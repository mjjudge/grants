<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\ConflictService;
use Rotary\Grants\Services\RoundService;

defined( 'ABSPATH' ) || exit;

/**
 * "Conflicts of interest" screen for a round:
 *   - every committee member: declare on all of the round's applications at
 *     once, before the review starts (RI policy: "before the selection
 *     process begins");
 *   - the chair (grants_decide or grants_manage_rounds): the round's full
 *     conflicts register — the CC29 record.
 *
 * Routing: /wp-admin/admin.php?page=grants-declarations[&round_id=N]
 * POST handled by CommitteeActions::handle_declare_round().
 */
class DeclarationsPage {

    public function render(): void {
        if ( ! ConflictService::is_committee() && ! current_user_can( 'grants_manage_rounds' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        $rounds   = ( new RoundService() )->all();
        $round_id = absint( $_GET['round_id'] ?? 0 ) ?: (int) ( $rounds[0]->id ?? 0 );
        $round    = $round_id ? ( new RoundService() )->find( $round_id ) : null;
        $service  = new ConflictService();

        $is_committee = ConflictService::is_committee();
        $applications = $round ? ( new ApplicationService() )->list( $round->id, null, null, [ 'hide_duplicates' => true ] ) : [];
        $mine         = $round && $is_committee ? $service->for_user_in_round( $round->id ) : [];
        $register     = $round ? $service->register( $round->id ) : [];
        $register     = is_wp_error( $register ) ? null : $register;
        $notice       = sanitize_key( $_GET['grants_notice'] ?? '' );
        $errors       = CommitteeActions::take_error();
        $errors       = is_array( $errors ) ? $errors : [];
        $nonce_action = CommitteeActions::NONCE_ACTION;
        $nonce_field  = CommitteeActions::NONCE_FIELD;
        $apps_url     = add_query_arg( 'page', 'grants-applications', admin_url( 'admin.php' ) );

        include GRANTS_PLUGIN_DIR . 'templates/admin/declarations.php';
    }
}
