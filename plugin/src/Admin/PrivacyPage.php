<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\RetentionService;
use Rotary\Grants\Services\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy & retention (grants_privacy): the dry-run plan per category and
 * an Apply button. Access/erasure requests go through WordPress's own
 * Tools → Export / Erase Personal Data, which include this plugin.
 *
 * Routing: /wp-admin/admin.php?page=grants-privacy ; POST action=grants_retention_apply
 */
class PrivacyPage {

    private const NONCE_ACTION = 'grants_retention';
    private const NONCE_FIELD  = 'grants_retention_nonce';

    public function register(): void {
        add_action( 'admin_post_grants_retention_apply', [ $this, 'handle_apply' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'grants_privacy' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        $plan         = ( new RetentionService() )->plan();
        $confirmed    = ( new SettingsService() )->retention_confirmed();
        $result       = get_transient( 'grants_retention_result_' . get_current_user_id() );
        delete_transient( 'grants_retention_result_' . get_current_user_id() );
        $settings_url = add_query_arg( 'page', 'grants-settings', admin_url( 'admin.php' ) ) . '#grants-retention';
        $export_url   = admin_url( 'export-personal-data.php' );
        $erase_url    = admin_url( 'erase-personal-data.php' );
        $nonce_action = self::NONCE_ACTION;
        $nonce_field  = self::NONCE_FIELD;
        include GRANTS_PLUGIN_DIR . 'templates/admin/privacy.php';
    }

    public function handle_apply(): void {
        if ( ! current_user_can( 'grants_privacy' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
        $result = ( new RetentionService() )->apply();
        set_transient( 'grants_retention_result_' . get_current_user_id(), is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result, 300 );
        wp_safe_redirect( add_query_arg( 'page', 'grants-privacy', admin_url( 'admin.php' ) ) );
        exit;
    }
}
