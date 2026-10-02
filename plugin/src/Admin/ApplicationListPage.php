<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\ApplicationForm;
use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Services\RoundService;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only application list and detail view (grants_access). Review,
 * filtering by amount/locality, notes and status changes arrive in G06.
 *
 * Routing:
 *   /wp-admin/admin.php?page=grants-applications[&round_id=N][&status=S]
 *   /wp-admin/admin.php?page=grants-applications&action=view&id=N
 */
class ApplicationListPage {

    public function render(): void {
        if ( ! current_user_can( 'grants_access' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }

        $service = new ApplicationService();

        if ( sanitize_key( $_GET['action'] ?? '' ) === 'view' ) {
            $application = $service->find( absint( $_GET['id'] ?? 0 ) );
            if ( ! $application ) {
                wp_die( esc_html__( 'Application not found.', 'rotary-grants' ), 404 );
            }
            $snapshot = json_decode( (string) $application->answer_snapshot_json, true ) ?: [];
            $labels   = ApplicationForm::labels();
            $list_url = add_query_arg( 'page', 'grants-applications', admin_url( 'admin.php' ) );
            include GRANTS_PLUGIN_DIR . 'templates/admin/application-view.php';
            return;
        }

        $round_id     = absint( $_GET['round_id'] ?? 0 );
        $status       = sanitize_key( $_GET['status'] ?? '' );
        $status       = in_array( $status, ApplicationStatus::all(), true ) ? $status : '';
        $applications = $service->list( $round_id ?: null, $status ?: null );
        $rounds       = ( new RoundService() )->all();
        $base_url     = add_query_arg( 'page', 'grants-applications', admin_url( 'admin.php' ) );

        include GRANTS_PLUGIN_DIR . 'templates/admin/application-list.php';
    }
}
