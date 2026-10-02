<?php

namespace Rotary\Grants\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Rotary Grants landing page. Placeholder until funding rounds (G02) and
 * applications (G03) exist — shows configuration status only.
 */
class DashboardPage {

    public function render(): void {
        if ( ! current_user_can( 'grants_access' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }

        $settings        = new \Rotary\Grants\Services\SettingsService();
        $help_email_set  = $settings->help_email() !== '';
        $recipient_count = count( $settings->notification_recipients() );
        $can_settings    = current_user_can( 'grants_manage_settings' );
        $settings_url    = add_query_arg( 'page', 'grants-settings', admin_url( 'admin.php' ) );

        include GRANTS_PLUGIN_DIR . 'templates/admin/dashboard.php';
    }
}
