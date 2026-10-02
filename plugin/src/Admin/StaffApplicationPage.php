<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\ApplicationForm;
use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\OrganisationService;
use Rotary\Grants\Services\RoundService;
use Rotary\Grants\Services\RoundStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Staff entry of an application received on paper, by email or by phone
 * (grants_manage_organisations).
 *
 * Routing:
 *   /wp-admin/admin.php?page=grants-applications-add
 *   POST admin-post.php action=grants_staff_application_save
 *
 * A failed save keeps what was typed in a 10-minute transient keyed to the
 * staff member (admin-only data), so the form can be corrected.
 */
class StaffApplicationPage {

    private const NONCE_ACTION = 'grants_staff_application';
    private const NONCE_FIELD  = 'grants_staff_application_nonce';
    private const STATE_TTL    = 600;

    public function register(): void {
        add_action( 'admin_post_grants_staff_application_save', [ $this, 'handle_save' ] );
    }

    public function render(): void {
        if ( ! current_user_can( OrganisationService::CAPABILITY ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        $rounds = array_values( array_filter(
            ( new RoundService() )->all(),
            static fn( $r ) => in_array( $r->status, [ RoundStatus::OPEN, RoundStatus::CLOSED ], true )
        ) );

        $values = [ 'staff_received' => wp_date( 'Y-m-d' ), 'staff_send_ack' => '' ];
        $errors = [];
        $state  = get_transient( self::state_key() );
        if ( is_array( $state ) ) {
            delete_transient( self::state_key() );
            $values = array_merge( $values, (array) $state['values'] );
            $errors = (array) $state['errors'];
        }

        $fields       = ApplicationForm::field_names();
        $labels       = ApplicationForm::labels();
        $sources      = ApplicationService::staff_sources();
        $nonce_action = self::NONCE_ACTION;
        $nonce_field  = self::NONCE_FIELD;
        $key          = (string) ( $values['grants_submission_key'] ?? '' ) ?: bin2hex( random_bytes( 32 ) );

        include GRANTS_PLUGIN_DIR . 'templates/admin/staff-application.php';
    }

    public function handle_save(): void {
        if ( ! current_user_can( OrganisationService::CAPABILITY ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

        $raw = [];
        foreach ( ApplicationForm::field_names() as $field ) {
            $v = $_POST[ $field ] ?? '';
            $raw[ $field ] = is_string( $v ) ? wp_unslash( $v ) : '';
            if ( ApplicationForm::field_type( $field ) === 'checkbox' ) {
                $raw[ $field ] = $raw[ $field ] === '1' ? '1' : '';
            }
        }
        $meta = [
            'source'   => sanitize_key( wp_unslash( $_POST['staff_source'] ?? '' ) ),
            'received' => sanitize_text_field( wp_unslash( $_POST['staff_received'] ?? '' ) ),
            'reason'   => sanitize_textarea_field( wp_unslash( $_POST['staff_reason'] ?? '' ) ),
            'send_ack' => ( $_POST['staff_send_ack'] ?? '' ) === '1',
        ];
        $key = (string) wp_unslash( $_POST['grants_submission_key'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
            $key = bin2hex( random_bytes( 32 ) );
        }

        $round  = ( new RoundService() )->find( absint( $_POST['round_id'] ?? 0 ) );
        $result = $round
            ? ( new ApplicationService() )->create_staff_entry( $round, $raw, $meta, $key )
            : new \WP_Error( 'staff_round', __( 'Choose the funding round.', 'rotary-grants' ) );

        if ( is_wp_error( $result ) ) {
            $errors = [];
            foreach ( $result->get_error_codes() as $code ) {
                $errors[ $code ] = $result->get_error_message( $code );
            }
            set_transient( self::state_key(), [
                'values' => $raw + [
                    'round_id'              => (string) absint( $_POST['round_id'] ?? 0 ),
                    'staff_source'          => $meta['source'],
                    'staff_received'        => $meta['received'],
                    'staff_reason'          => $meta['reason'],
                    'staff_send_ack'        => $meta['send_ack'] ? '1' : '',
                    'grants_submission_key' => $key,
                ],
                'errors' => $errors,
            ], self::STATE_TTL );
            wp_safe_redirect( add_query_arg( [ 'page' => 'grants-applications-add', 'grants_notice' => 'error' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'grants-applications', 'action' => 'view', 'id' => $result['application_id'], 'grants_notice' => $result['replayed'] ? 'already_entered' : 'entered' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    private static function state_key(): string {
        return 'grants_staff_app_' . get_current_user_id();
    }
}
