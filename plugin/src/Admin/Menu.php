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

        add_submenu_page( 'grants-dashboard', __( 'Rotary Grants', 'rotary-grants' ),          __( 'Dashboard', 'rotary-grants' ),      'grants_access',        'grants-dashboard',  [ new DashboardPage(), 'render' ] );
        add_submenu_page( 'grants-dashboard', __( 'Applications', 'rotary-grants' ),           __( 'Applications', 'rotary-grants' ),   'grants_access',        'grants-applications', [ new ApplicationListPage(), 'render' ] );
        add_submenu_page( 'grants-dashboard', __( 'Conflicts of Interest', 'rotary-grants' ),  __( 'Conflicts of Interest', 'rotary-grants' ), 'grants_access', 'grants-declarations', [ new DeclarationsPage(), 'render' ] );
        add_submenu_page( 'grants-dashboard', __( 'Enter Application', 'rotary-grants' ),      __( 'Enter Application', 'rotary-grants' ), 'grants_manage_organisations', 'grants-applications-add', [ new StaffApplicationPage(), 'render' ] );
        add_submenu_page( 'grants-dashboard', __( 'Organisations', 'rotary-grants' ),          __( 'Organisations', 'rotary-grants' ),  'grants_access',        'grants-organisations', [ new OrganisationPage(), 'render' ] );
        add_submenu_page( 'grants-dashboard', __( 'Add Organisation', 'rotary-grants' ),       __( 'Add Organisation', 'rotary-grants' ), 'grants_manage_organisations', 'grants-organisations-add', [ new OrganisationPage(), 'render_add' ] );
        add_submenu_page( 'grants-dashboard', __( 'Funding Rounds', 'rotary-grants' ),         __( 'Funding Rounds', 'rotary-grants' ), 'grants_access',        'grants-rounds',     [ $this, 'render_rounds' ] );
        add_submenu_page( 'grants-dashboard', __( 'Add Funding Round', 'rotary-grants' ),      __( 'Add Round', 'rotary-grants' ),      'grants_manage_rounds', 'grants-rounds-add', [ new RoundEditPage(), 'render_add' ] );
        add_submenu_page( 'grants-dashboard', __( 'Notifications', 'rotary-grants' ),          __( 'Notifications', 'rotary-grants' ),  'grants_access',        'grants-notifications', [ new NotificationsPage(), 'render' ] );
        add_submenu_page( 'grants-dashboard', __( 'Rotary Grants Settings', 'rotary-grants' ), __( 'Settings', 'rotary-grants' ),       'grants_manage_settings', 'grants-settings', [ new SettingsPage(), 'render' ] );
        // Access management is administrator-only (DEC-008), not a grants_* capability.
        add_submenu_page( 'grants-dashboard', __( 'Rotary Grants Access', 'rotary-grants' ),   __( 'Access', 'rotary-grants' ),         'promote_users',          'grants-access',   [ new AccessPage(), 'render' ] );
    }

    public function render_rounds(): void {
        if ( sanitize_key( $_GET['action'] ?? '' ) === 'edit' ) {
            ( new RoundEditPage() )->render_edit();
        } else {
            ( new RoundListPage() )->render();
        }
    }
}
