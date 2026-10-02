<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\RoundService;
use Rotary\Grants\Services\RoundStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Funding round list. Readable by anyone with grants_access; editing links
 * appear only for grants_manage_rounds.
 */
class RoundListPage {

    public function render(): void {
        if ( ! current_user_can( 'grants_access' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }

        $service    = new RoundService();
        $status     = sanitize_key( $_GET['status'] ?? '' );
        $status     = in_array( $status, RoundStatus::all(), true ) ? $status : '';
        $rounds     = $service->all( $status ?: null );
        $phases     = [];
        foreach ( $rounds as $round ) {
            $phases[ $round->id ] = $service->phase( $round );
        }
        $can_manage = current_user_can( RoundService::CAPABILITY );
        $base_url   = add_query_arg( 'page', 'grants-rounds', admin_url( 'admin.php' ) );
        $add_url    = add_query_arg( 'page', 'grants-rounds-add', admin_url( 'admin.php' ) );

        include GRANTS_PLUGIN_DIR . 'templates/admin/round-list.php';
    }
}
