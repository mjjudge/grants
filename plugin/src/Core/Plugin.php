<?php

namespace Rotary\Grants\Core;

defined( 'ABSPATH' ) || exit;

class Plugin {

    public function boot(): void {
        load_plugin_textdomain(
            'rotary-grants',
            false,
            dirname( plugin_basename( GRANTS_PLUGIN_FILE ) ) . '/languages'
        );

        ( new \Rotary\Grants\Public\ApplicationFormHandler() )->register();
        \Rotary\Grants\Services\NotificationService::register_cron();
        \Rotary\Grants\Services\RetentionService::register_cron();
        ( new \Rotary\Grants\Services\PrivacyService() )->register();

        if ( is_admin() ) {
            ( new \Rotary\Grants\Admin\Menu() )->register();
            ( new \Rotary\Grants\Admin\SettingsPage() )->register();
            ( new \Rotary\Grants\Admin\RoundEditPage() )->register();
            ( new \Rotary\Grants\Admin\NotificationsPage() )->register();
            ( new \Rotary\Grants\Admin\ApplicationListPage() )->register();
            ( new \Rotary\Grants\Admin\StaffApplicationPage() )->register();
            ( new \Rotary\Grants\Admin\OrganisationPage() )->register();
            ( new \Rotary\Grants\Admin\CommitteeActions() )->register();
            ( new \Rotary\Grants\Admin\PaymentsPage() )->register();
            ( new \Rotary\Grants\Admin\ReportsPage() )->register();
            ( new \Rotary\Grants\Admin\PrivacyPage() )->register();
            ( new \Rotary\Grants\Admin\UserAccessSection() )->register();
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        }
    }

    public function enqueue_admin_assets( string $hook_suffix ): void {
        // Load only on Rotary Grants admin pages — every page slug starts "grants-".
        if ( ! str_contains( $hook_suffix, 'grants-' ) ) {
            return;
        }
        wp_enqueue_style(
            'grants-admin',
            GRANTS_PLUGIN_URL . 'assets/css/grants-admin.css',
            [],
            GRANTS_VERSION
        );
    }
}
