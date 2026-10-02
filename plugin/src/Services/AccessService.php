<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Core\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Grants and revokes per-user grants_* capabilities (DEC-008).
 *
 * The only code that changes who holds a grants_* capability, other than
 * Core\Roles::register() granting them to the administrator role on
 * activation. Only administrators (manage_options + promote_users) may use
 * it. Every change is audited.
 */
class AccessService {

    public function can_manage(): bool {
        return current_user_can( 'manage_options' ) && current_user_can( 'promote_users' );
    }

    /**
     * grants_* capabilities held directly by the user (per-user grants).
     *
     * @return string[]
     */
    public function direct_caps( \WP_User $user ): array {
        $held = array_keys( array_filter( (array) $user->caps ) );
        return array_values( array_intersect( Roles::ALL_CAPS, $held ) );
    }

    /**
     * grants_* capabilities the user holds through one of their roles.
     *
     * @return string[]
     */
    public function role_caps( \WP_User $user ): array {
        $held = [];
        foreach ( (array) $user->roles as $role_name ) {
            $role = get_role( $role_name );
            if ( $role ) {
                $held = array_merge( $held, array_keys( array_filter( $role->capabilities ) ) );
            }
        }
        return array_values( array_intersect( Roles::ALL_CAPS, $held ) );
    }

    /**
     * Replace the user's per-user grants_* capabilities with $requested.
     * Unknown capability names are ignored. Any grant implies grants_access.
     *
     * @param string[] $requested
     * @return true|\WP_Error
     */
    public function set_user_caps( int $user_id, array $requested ): true|\WP_Error {
        if ( ! $this->can_manage() || ! current_user_can( 'edit_user', $user_id ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to change Rotary Grants access.', 'rotary-grants' ) );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return new \WP_Error( 'not_found', __( 'User not found.', 'rotary-grants' ) );
        }

        $wanted = array_values( array_intersect( Roles::ALL_CAPS, $requested ) );
        if ( $wanted && ! in_array( Roles::ACCESS, $wanted, true ) ) {
            $wanted[] = Roles::ACCESS;
        }

        $current = $this->direct_caps( $user );
        $grant   = array_values( array_diff( $wanted, $current ) );
        $revoke  = array_values( array_diff( $current, $wanted ) );

        if ( ! $grant && ! $revoke ) {
            return true;
        }

        foreach ( $grant as $cap ) {
            $user->add_cap( $cap );
        }
        foreach ( $revoke as $cap ) {
            $user->remove_cap( $cap );
        }

        \Rotary\Grants\Audit\AuditLogger::record(
            'access_changed',
            'user',
            $user_id,
            [ 'granted' => $grant, 'revoked' => $revoke ]
        );

        return true;
    }

    /**
     * Every account holding any grants_* capability, directly or by role.
     *
     * @return list<array{user: \WP_User, direct: string[], via_role: string[]}>
     */
    public function holders(): array {
        $users = get_users( [
            'capability__in' => Roles::ALL_CAPS,
            'orderby'        => 'display_name',
        ] );

        $rows = [];
        foreach ( $users as $user ) {
            $rows[] = [
                'user'     => $user,
                'direct'   => $this->direct_caps( $user ),
                'via_role' => $this->role_caps( $user ),
            ];
        }
        return $rows;
    }
}
