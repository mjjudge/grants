<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\ApplicationForm;
use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Services\ContactService;
use Rotary\Grants\Services\OrganisationService;
use Rotary\Grants\Services\RoundService;

defined( 'ABSPATH' ) || exit;

/**
 * Application list and detail view (grants_access), plus linking an
 * application to an organisation (grants_manage_organisations).
 *
 * Routing:
 *   /wp-admin/admin.php?page=grants-applications[&round_id=N][&status=S][&organisation_id=N]
 *   /wp-admin/admin.php?page=grants-applications&action=view&id=N[&org_q=search]
 *   POST admin-post.php action=grants_application_link
 */
class ApplicationListPage {

    private const NONCE_ACTION = 'grants_application_link';
    private const NONCE_FIELD  = 'grants_link_nonce';

    public function register(): void {
        add_action( 'admin_post_grants_application_link', [ $this, 'handle_link' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'grants_access' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }

        $service = new ApplicationService();

        if ( sanitize_key( $_GET['action'] ?? '' ) === 'view' ) {
            $this->render_view( $service );
            return;
        }

        $round_id        = absint( $_GET['round_id'] ?? 0 );
        $organisation_id = absint( $_GET['organisation_id'] ?? 0 );
        $status          = sanitize_key( $_GET['status'] ?? '' );
        $status          = in_array( $status, ApplicationStatus::all(), true ) ? $status : '';
        $applications    = $service->list( $round_id ?: null, $status ?: null, $organisation_id ?: null );
        $rounds          = ( new RoundService() )->all();
        $base_url        = add_query_arg( 'page', 'grants-applications', admin_url( 'admin.php' ) );
        $add_url         = current_user_can( OrganisationService::CAPABILITY ) ? add_query_arg( 'page', 'grants-applications-add', admin_url( 'admin.php' ) ) : '';

        include GRANTS_PLUGIN_DIR . 'templates/admin/application-list.php';
    }

    private function render_view( ApplicationService $service ): void {
        $application = $service->find( absint( $_GET['id'] ?? 0 ) );
        if ( ! $application ) {
            wp_die( esc_html__( 'Application not found.', 'rotary-grants' ), 404 );
        }
        $orgs         = new OrganisationService();
        $snapshot     = json_decode( (string) $application->answer_snapshot_json, true ) ?: [];
        $labels       = ApplicationForm::labels();
        $list_url     = add_query_arg( 'page', 'grants-applications', admin_url( 'admin.php' ) );
        $org_url      = add_query_arg( 'page', 'grants-organisations', admin_url( 'admin.php' ) );
        $can_link     = current_user_can( OrganisationService::CAPABILITY );
        $organisation = $application->organisation_id ? $orgs->find( $application->organisation_id ) : null;
        $contact      = $application->contact_id ? ( new ContactService() )->find( $application->contact_id ) : null;
        $siblings     = $service->same_round_siblings( $application );
        $org_query    = sanitize_text_field( wp_unslash( $_GET['org_q'] ?? '' ) );
        $candidates   = $can_link && ! $organisation ? $orgs->candidates_for_application( $application ) : [];
        $search       = $can_link && $org_query !== '' ? $orgs->search( $org_query, 20 ) : [];
        $entered_by   = $application->entered_by_user_id ? get_userdata( (int) $application->entered_by_user_id ) : null;
        $notice       = sanitize_key( $_GET['grants_notice'] ?? '' );
        $error        = $this->take_error();
        $nonce_action = self::NONCE_ACTION;
        $nonce_field  = self::NONCE_FIELD;

        include GRANTS_PLUGIN_DIR . 'templates/admin/application-view.php';
    }

    public function handle_link(): void {
        if ( ! current_user_can( OrganisationService::CAPABILITY ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

        $app_id = absint( $_POST['application_id'] ?? 0 );
        $result = ( new OrganisationService() )->link_application(
            $app_id,
            absint( $_POST['organisation_id'] ?? 0 ),
            absint( $_POST['row_version'] ?? 0 ),
            sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) )
        );

        $args = [ 'page' => 'grants-applications', 'action' => 'view', 'id' => $app_id ];
        if ( is_wp_error( $result ) ) {
            set_transient( self::error_key(), implode( ' ', $result->get_error_messages() ), 300 );
            $args['grants_notice'] = 'error';
        } else {
            $args['grants_notice'] = $result['created'] ? 'linked_new' : 'linked';
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private function take_error(): string {
        $msg = (string) get_transient( self::error_key() );
        delete_transient( self::error_key() );
        return $msg;
    }

    private static function error_key(): string {
        return 'grants_app_error_' . get_current_user_id();
    }
}
