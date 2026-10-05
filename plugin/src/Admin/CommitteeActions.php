<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\AwardService;
use Rotary\Grants\Services\ConflictService;
use Rotary\Grants\Services\DecisionService;
use Rotary\Grants\Services\NotificationService;
use Rotary\Grants\Services\ReviewService;
use Rotary\Grants\Services\WorkflowService;

defined( 'ABSPATH' ) || exit;

/**
 * POST handlers for committee work on an application: conflict
 * declaration, review, notes, information requests, addenda, status and
 * duplicates. Each handler: logged-in committee member, nonce, delegate to
 * the service (which enforces the specific capability and the conflict
 * gate), redirect back to the application.
 */
class CommitteeActions {

    public const NONCE_ACTION = 'grants_committee';
    public const NONCE_FIELD  = 'grants_committee_nonce';

    private const ACTIONS = [
        'declare', 'declare_round', 'review_save', 'note_add', 'info_draft', 'info_send', 'info_discard',
        'addendum_add', 'status_change', 'duplicate_mark', 'duplicate_unmark',
        'decide', 'reopen', 'condition_fulfil', 'notice_draft',
    ];

    public function register(): void {
        foreach ( self::ACTIONS as $action ) {
            add_action( 'admin_post_grants_' . $action, [ $this, 'handle_' . $action ] );
        }
    }

    public function handle_declare(): void {
        $app = $this->guard();
        $this->finish( $app, ( new ConflictService() )->declare( $app, $this->text( 'declaration', true ), $this->text( 'description' ) ), 'declared' );
    }

    /** Bulk declarations for a round, from the My declarations screen. */
    public function handle_declare_round(): void {
        if ( ! ConflictService::is_committee() ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
        $round   = absint( $_POST['round_id'] ?? 0 );
        $service = new ConflictService();
        $errors  = [];
        $decls   = (array) wp_unslash( $_POST['declaration'] ?? [] );
        $descs   = (array) wp_unslash( $_POST['description'] ?? [] );
        foreach ( $decls as $app_id => $decl ) {
            $decl = sanitize_key( (string) $decl );
            if ( $decl === '' ) {
                continue; // left undeclared
            }
            $result = $service->declare( absint( $app_id ), $decl, sanitize_textarea_field( (string) ( $descs[ $app_id ] ?? '' ) ) );
            if ( is_wp_error( $result ) ) {
                $errors[ absint( $app_id ) ] = $result->get_error_message();
            }
        }
        if ( $errors ) {
            set_transient( self::error_key(), $errors, 300 );
        }
        $portal = self::portal_url();
        wp_safe_redirect( $portal
            ? add_query_arg( [ 'view' => 'declare', 'round' => $round, 'grants_notice' => $errors ? 'partial' : 'declared' ], $portal )
            : add_query_arg( [ 'page' => 'grants-declarations', 'round_id' => $round, 'grants_notice' => $errors ? 'partial' : 'declared' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_review_save(): void {
        $app = $this->guard();
        $this->finish( $app, ( new ReviewService() )->save( $app, [
            'findings'           => array_map( 'sanitize_key', (array) wp_unslash( $_POST['findings'] ?? [] ) ),
            'finding_notes'      => array_map( 'sanitize_textarea_field', (array) wp_unslash( $_POST['finding_notes'] ?? [] ) ),
            'recommendation'     => $this->text( 'recommendation', true ),
            'recommended_amount' => $this->text( 'recommended_amount' ),
            'notes'              => $this->text( 'notes' ),
        ] ), 'review_saved' );
    }

    public function handle_note_add(): void {
        $app = $this->guard();
        $this->finish( $app, ( new WorkflowService() )->add_note( $app, $this->text( 'body' ) ), 'note_added' );
    }

    public function handle_info_draft(): void {
        $app = $this->guard();
        $this->finish( $app, ( new WorkflowService() )->draft_info_request( $app, $this->text( 'body' ) ), 'info_drafted' );
    }

    public function handle_info_send(): void {
        $app    = $this->guard();
        $result = ( new WorkflowService() )->send_info_request( absint( $_POST['note_id'] ?? 0 ) );
        if ( $result === true ) {
            ( new NotificationService() )->process_due( $app );
        }
        $this->finish( $app, $result, 'info_sent' );
    }

    public function handle_info_discard(): void {
        $app = $this->guard();
        $this->finish( $app, ( new WorkflowService() )->discard_draft( absint( $_POST['note_id'] ?? 0 ) ), 'info_discarded' );
    }

    public function handle_addendum_add(): void {
        $app = $this->guard();
        $this->finish( $app, ( new WorkflowService() )->record_addendum( $app, $this->text( 'body' ), $this->text( 'received' ) ), 'addendum_added' );
    }

    public function handle_status_change(): void {
        $app = $this->guard();
        $this->finish( $app, ( new WorkflowService() )->transition( $app, $this->text( 'to', true ), absint( $_POST['row_version'] ?? 0 ), $this->text( 'reason' ) ), 'status_changed' );
    }

    public function handle_duplicate_mark(): void {
        $app = $this->guard();
        $this->finish( $app, ( new WorkflowService() )->mark_duplicate( $app, $this->text( 'kept_reference' ), $this->text( 'reason' ) ), 'duplicate_marked' );
    }

    public function handle_duplicate_unmark(): void {
        $app = $this->guard();
        $this->finish( $app, ( new WorkflowService() )->unmark_duplicate( $app, $this->text( 'reason' ) ), 'duplicate_unmarked' );
    }

    public function handle_decide(): void {
        $app   = $this->guard();
        $conds = [];
        foreach ( (array) wp_unslash( $_POST['conditions'] ?? [] ) as $row ) {
            $conds[] = [
                'text'           => sanitize_textarea_field( (string) ( $row['text'] ?? '' ) ),
                'before_payment' => ! empty( $row['before_payment'] ),
            ];
        }
        $input = [
            'type'                => $this->text( 'decision_type', true ),
            'amount'              => $this->text( 'amount' ),
            'reason'              => $this->text( 'reason' ),
            'meeting_reference'   => $this->text( 'meeting_reference' ),
            'decided_on'          => $this->text( 'decided_on' ),
            'conditions'          => $conds,
            'confirm_over_budget' => ( $_POST['confirm_over_budget'] ?? '' ) === '1',
            'funding_note'        => $this->text( 'funding_note' ),
        ];
        $result = ( new DecisionService() )->decide( $app, $input );
        if ( is_wp_error( $result ) ) {
            // Keep what was typed (admin-only data) so an over-budget warning
            // or a typo doesn't lose the form.
            set_transient( self::decision_key( $app ), [
                'input' => $input,
                'code'  => $result->get_error_code(),
                'data'  => $result->get_error_data(),
            ], 600 );
        }
        $this->finish( $app, $result, is_array( $result ) && $result['over_budget_pence'] > 0 ? 'decided_over_budget' : 'decided' );
    }

    public function handle_reopen(): void {
        $app = $this->guard();
        $this->finish( $app, ( new DecisionService() )->reopen( $app, absint( $_POST['row_version'] ?? 0 ), $this->text( 'reason' ) ), 'reopened' );
    }

    public function handle_condition_fulfil(): void {
        $app = $this->guard();
        $this->finish( $app, ( new AwardService() )->fulfil_condition( absint( $_POST['condition_id'] ?? 0 ), $this->text( 'evidence' ) ), 'condition_met' );
    }

    public function handle_notice_draft(): void {
        $app = $this->guard();
        $this->finish( $app, ( new WorkflowService() )->draft_decision_notice( $app, $this->text( 'body' ) ), 'notice_drafted' );
    }

    /** Decision form state saved after a failed decide (read once). */
    public static function take_decision_state( int $application_id ): ?array {
        $state = get_transient( self::decision_key( $application_id ) );
        delete_transient( self::decision_key( $application_id ) );
        return is_array( $state ) ? $state : null;
    }

    private static function decision_key( int $application_id ): string {
        return 'grants_decision_form_' . get_current_user_id() . '_' . $application_id;
    }

    // -------------------------------------------------------------------------

    /** Committee member + nonce; returns the application id. */
    private function guard(): int {
        if ( ! ConflictService::is_committee() ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
        return absint( $_POST['application_id'] ?? 0 );
    }

    private function text( string $key, bool $as_key = false ): string {
        $raw = wp_unslash( $_POST[ $key ] ?? '' );
        $raw = is_string( $raw ) ? $raw : '';
        return $as_key ? sanitize_key( $raw ) : sanitize_textarea_field( $raw );
    }

    private function finish( int $application_id, mixed $result, string $notice ): never {
        if ( is_wp_error( $result ) ) {
            set_transient( self::error_key(), implode( ' ', $result->get_error_messages() ), 300 );
            $notice = 'error';
        }
        $portal = self::portal_url();
        $url    = $portal
            ? add_query_arg( [ 'view' => 'application', 'id' => $application_id, 'grants_notice' => $notice ], $portal )
            : add_query_arg( [ 'page' => 'grants-applications', 'action' => 'view', 'id' => $application_id, 'grants_notice' => $notice ], admin_url( 'admin.php' ) );
        wp_safe_redirect( $url . '#grants-committee' );
        exit;
    }

    /**
     * The committee page URL when the form was posted from it (hidden
     * grants_return=portal). The URL itself always comes from Settings,
     * never from the request, so this can't be used as an open redirect.
     */
    private static function portal_url(): string {
        if ( ( $_POST['grants_return'] ?? '' ) !== 'portal' ) {
            return '';
        }
        return \Rotary\Grants\Public\CommitteePortal::url();
    }

    /** Error message (string) or per-application errors (array) from the last action. */
    public static function take_error(): string|array {
        $value = get_transient( self::error_key() );
        delete_transient( self::error_key() );
        return $value === false ? '' : $value;
    }

    private static function error_key(): string {
        return 'grants_committee_error_' . get_current_user_id();
    }
}
