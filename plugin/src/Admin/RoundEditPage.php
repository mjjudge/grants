<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\RoundService;
use Rotary\Grants\Services\RoundStatus;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Add / edit a funding round, and change its status.
 *
 * Routing:
 *   /wp-admin/admin.php?page=grants-rounds-add                 → add form
 *   /wp-admin/admin.php?page=grants-rounds&action=edit&id=N    → edit form
 *   POST admin-post.php action=grants_round_save
 *   POST admin-post.php action=grants_round_status
 *
 * A failed save keeps the submitted values and errors in a short-lived
 * per-user transient so the form redisplays what was typed.
 */
class RoundEditPage {

    private const SAVE_NONCE_ACTION   = 'grants_round_save';
    private const STATUS_NONCE_PREFIX = 'grants_round_status_';
    private const NONCE_FIELD         = 'grants_round_nonce';
    private const FORM_STATE_TTL      = 300;

    /** Form field names, in the order RoundService::validate() reads them. */
    private const FIELDS = [
        'label', 'fund_name', 'campaign_year', 'accounting_period_label', 'opens_at', 'closes_at', 'budget', 'cap',
        'intro_text', 'eligibility_text', 'exclusions_text', 'publicity_text',
    ];

    public function register(): void {
        add_action( 'admin_post_grants_round_save',   [ $this, 'handle_save' ] );
        add_action( 'admin_post_grants_round_status', [ $this, 'handle_status' ] );
    }

    public function render_add(): void {
        if ( ! current_user_can( RoundService::CAPABILITY ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }

        $service     = new RoundService();
        $round       = null;
        $copied_from = null;
        $values      = array_fill_keys( self::FIELDS, '' );
        $values['campaign_year'] = wp_date( 'Y' );

        // Wording usually carries over year to year: start from the most recent round.
        $latest = $service->all()[0] ?? null;
        if ( $latest ) {
            $copied_from         = $latest;
            $values['fund_name'] = $latest->fund_name;
            foreach ( RoundService::TEXT_FIELDS as $field ) {
                $values[ $field ] = (string) $latest->$field;
            }
        }

        $this->render_form( $round, $values, $copied_from );
    }

    public function render_edit(): void {
        if ( ! current_user_can( RoundService::CAPABILITY ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }

        $round = ( new RoundService() )->find( absint( $_GET['id'] ?? 0 ) );
        if ( ! $round ) {
            wp_die( esc_html__( 'Funding round not found.', 'rotary-grants' ), 404 );
        }

        $values = [
            'label'                   => $round->label,
            'fund_name'               => $round->fund_name,
            'campaign_year'           => (string) $round->campaign_year,
            'accounting_period_label' => $round->accounting_period_label,
            'opens_at'                => SiteTime::utc_to_local_input( $round->opens_at ),
            'closes_at'               => SiteTime::utc_to_local_input( $round->closes_at ),
            'budget'                  => Money::to_input( $round->budget_pence ),
            'cap'                     => Money::to_input( $round->cap_pence ),
        ];
        foreach ( RoundService::TEXT_FIELDS as $field ) {
            $values[ $field ] = (string) $round->$field;
        }

        $this->render_form( $round, $values, null );
    }

    public function handle_save(): void {
        if ( ! current_user_can( RoundService::CAPABILITY ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::SAVE_NONCE_ACTION, self::NONCE_FIELD );

        $id          = absint( $_POST['round_id'] ?? 0 );
        $row_version = absint( $_POST['row_version'] ?? 0 );

        $input = [];
        foreach ( self::FIELDS as $field ) {
            $raw = wp_unslash( $_POST[ $field ] ?? '' );
            // Wording fields are filtered with wp_kses_post() in the service;
            // everything else is plain text.
            $input[ $field ] = in_array( $field, RoundService::TEXT_FIELDS, true )
                ? (string) $raw
                : sanitize_text_field( (string) $raw );
        }

        $service = new RoundService();
        $result  = $id ? $service->update( $id, $input, $row_version ) : $service->create( $input );

        if ( is_wp_error( $result ) ) {
            $errors = [];
            foreach ( $result->get_error_codes() as $code ) {
                $errors[ $code ] = $result->get_error_message( $code );
            }
            set_transient( self::state_key(), [ 'id' => $id, 'input' => $input, 'errors' => $errors ], self::FORM_STATE_TTL );
            $this->redirect( $id, 'error', $id ? null : 'grants-rounds-add' );
        }

        $this->redirect( $id ?: (int) $result, $id ? 'saved' : 'created' );
    }

    public function handle_status(): void {
        if ( ! current_user_can( RoundService::CAPABILITY ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        $id = absint( $_POST['round_id'] ?? 0 );
        check_admin_referer( self::STATUS_NONCE_PREFIX . $id, self::NONCE_FIELD );

        $to     = sanitize_key( wp_unslash( $_POST['to'] ?? '' ) );
        $result = ( new RoundService() )->transition( $id, $to, absint( $_POST['row_version'] ?? 0 ) );

        if ( is_wp_error( $result ) ) {
            set_transient( self::state_key(), [ 'id' => $id, 'errors' => [ 'status' => $result->get_error_message() ] ], self::FORM_STATE_TTL );
            $this->redirect( $id, 'error' );
        }

        $this->redirect( $id, 'status_' . $to );
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string,string> $values
     */
    private function render_form( ?object $round, array $values, ?object $copied_from ): void {
        $service = new RoundService();
        $errors  = [];

        $state = get_transient( self::state_key() );
        if ( is_array( $state ) && (int) ( $state['id'] ?? 0 ) === (int) ( $round->id ?? 0 ) ) {
            delete_transient( self::state_key() );
            $values      = array_merge( $values, (array) ( $state['input'] ?? [] ) );
            $errors      = (array) ( $state['errors'] ?? [] );
            $copied_from = null;
        }

        $notice        = sanitize_key( $_GET['grants_notice'] ?? '' );
        $blockers      = $round && in_array( $round->status, [ RoundStatus::DRAFT, RoundStatus::CLOSED ], true )
            ? $service->open_blockers( $round, true )
            : [];
        $phase         = $round ? $service->phase( $round ) : '';
        $transitions   = $round ? RoundStatus::targets( $round->status ) : [];
        $read_only     = $round && $round->status === RoundStatus::ARCHIVED;
        $timezone      = wp_timezone_string();
        // A "+00:00"-style offset (rather than a city such as Europe/London)
        // never observes summer time, so deadlines would drift by an hour.
        $fixed_offset  = (bool) preg_match( '/^[+-]\d{2}:\d{2}$/', $timezone );
        $general_url   = admin_url( 'options-general.php' );
        $list_url      = add_query_arg( 'page', 'grants-rounds', admin_url( 'admin.php' ) );
        $save_nonce    = [ self::SAVE_NONCE_ACTION, self::NONCE_FIELD ];
        $status_nonce  = $round ? [ self::STATUS_NONCE_PREFIX . $round->id, self::NONCE_FIELD ] : null;

        include GRANTS_PLUGIN_DIR . 'templates/admin/round-edit.php';
    }

    private static function state_key(): string {
        return 'grants_round_form_' . get_current_user_id();
    }

    private function redirect( int $id, string $notice, ?string $page = null ): never {
        $args = $page
            ? [ 'page' => $page, 'grants_notice' => $notice ]
            : [ 'page' => 'grants-rounds', 'action' => 'edit', 'id' => $id, 'grants_notice' => $notice ];
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }
}
