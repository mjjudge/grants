<?php

namespace Rotary\Grants\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Rotary Grants admin menu.
 *
 * Menu capabilities only control visibility — every page's render() and every
 * admin-post handler re-checks its own capability.
 */
class Menu {

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
    }

    public function register_menus(): void {
        add_menu_page(
            __( 'Rotary Grants', 'rotary-grants' ),
            __( 'Rotary Grants', 'rotary-grants' ),
            'grants_access',
            'grants-dashboard',
            [ new DashboardPage(), 'render' ],
            'dashicons-awards',
            31
        );

        add_submenu_page( 'grants-dashboard', __( 'Rotary Grants', 'rotary-grants' ),          __( 'Dashboard', 'rotary-grants' ), 'grants_access',          'grants-dashboard', [ new DashboardPage(), 'render' ] );
        add_submenu_page( 'grants-dashboard', __( 'Rotary Grants Settings', 'rotary-grants' ), __( 'Settings', 'rotary-grants' ),  'grants_manage_settings', 'grants-settings',  [ new SettingsPage(),  'render' ] );
        // Access management is administrator-only (DEC-008), not a grants_* capability.
        add_submenu_page( 'grants-dashboard', __( 'Rotary Grants Access', 'rotary-grants' ),   __( 'Access', 'rotary-grants' ),    'promote_users',          'grants-access',    [ new AccessPage(),    'render' ] );
    }
}
