<?php

namespace Rotary\Grants\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only list of every account holding a grants_* capability — the
 * system's own answer to "document who holds them" (DEC-008). Changes are
 * made on each user's profile (UserAccessSection).
 */
class AccessPage {

    public function render(): void {
        $service = new \Rotary\Grants\Services\AccessService();
        if ( ! $service->can_manage() ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }

        $holders = $service->holders();
        $labels  = \Rotary\Grants\Core\Roles::labels();

        include GRANTS_PLUGIN_DIR . 'templates/admin/access.php';
    }
}
