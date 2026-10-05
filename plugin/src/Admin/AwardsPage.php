<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\AwardService;
use Rotary\Grants\Services\BudgetService;
use Rotary\Grants\Services\RoundService;

defined( 'ABSPATH' ) || exit;

/**
 * Awards by round with the round's budget position, including any
 * approvals made beyond the budget and their funding notes (DEC-014).
 * Read-only (grants_access). Full reports arrive in G09.
 *
 * Routing: /wp-admin/admin.php?page=grants-awards[&round_id=N][&status=S]
 */
class AwardsPage {

    public function render(): void {
        if ( ! current_user_can( 'grants_access' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        $rounds   = ( new RoundService() )->all();
        $round_id = absint( $_GET['round_id'] ?? 0 ) ?: (int) ( $rounds[0]->id ?? 0 );
        $round    = $round_id ? ( new RoundService() )->find( $round_id ) : null;
        $status   = sanitize_key( $_GET['status'] ?? 'approved' );
        $awards   = $round ? ( new AwardService() )->list( $round->id, $status === 'all' ? null : $status ) : [];
        $budget   = $round ? ( new BudgetService() )->summary( $round ) : null;
        $apps_url = add_query_arg( 'page', 'grants-applications', admin_url( 'admin.php' ) );
        include GRANTS_PLUGIN_DIR . 'templates/admin/awards.php';
    }
}
