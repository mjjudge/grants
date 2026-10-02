<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\AccessService;

defined( 'ABSPATH' ) || exit;

/**
 * "Rotary Grants access" section on the core user-edit / profile screens
 * (DEC-008). Visible to, and saved by, administrators only.
 */
class UserAccessSection {

    private const NONCE_ACTION = 'grants_user_access';
    private const NONCE_FIELD  = 'grants_user_access_nonce';

    public function register(): void {
        add_action( 'show_user_profile',        [ $this, 'render' ] );
        add_action( 'edit_user_profile',        [ $this, 'render' ] );
        add_action( 'personal_options_update',  [ $this, 'handle_save' ] );
        add_action( 'edit_user_profile_update', [ $this, 'handle_save' ] );
    }

    public function render( \WP_User $user ): void {
        $service = new AccessService();
        if ( ! $service->can_manage() ) {
            return;
        }

        $labels       = \Rotary\Grants\Core\Roles::labels();
        $direct       = $service->direct_caps( $user );
        $via_role     = $service->role_caps( $user );
        $nonce_action = self::NONCE_ACTION;
        $nonce_field  = self::NONCE_FIELD;

        include GRANTS_PLUGIN_DIR . 'templates/admin/user-access.php';
    }

    public function handle_save( int $user_id ): void {
        $service = new AccessService();
        // The section is only rendered for administrators; anyone else's
        // profile save simply doesn't include it.
        if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! $service->can_manage() ) {
            return;
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

        $requested = array_map( 'sanitize_key', (array) wp_unslash( $_POST['grants_caps'] ?? [] ) );

        $result = $service->set_user_caps( $user_id, $requested );
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ), 403 );
        }
    }
}
