<?php

namespace Rotary\Grants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the Rotary Grants capabilities and grants them to Administrator.
 *
 * Deliberately NOT granted to the WordPress `editor` role (DEC-003) and no
 * custom roles are created (DEC-008). Every other account receives individual
 * capabilities through Services\AccessService, ticked by an administrator on
 * the user-edit screen.
 *
 * Capabilities (see docs/03-workflows-and-permissions.md)
 * ─────────────────────────────────────────────────────────────────────────────
 * grants_access               — see the menu; read applications and history
 * grants_manage_organisations — edit organisation/contact data; link/merge
 * grants_review               — add reviews and recommendations
 * grants_decide               — approve/decline/defer and revise decisions
 * grants_pay                  — record and reverse payments
 * grants_export_committee     — export committee information
 * grants_export_financial     — export financial reports
 * grants_export_contacts      — export the future-round contact list
 * grants_manage_rounds        — manage funding rounds and budgets
 * grants_manage_settings      — change settings, incl. notification recipients
 * grants_privacy              — execute retention and anonymisation
 */
class Roles {

    public const ACCESS = 'grants_access';

    /**
     * Every grants_* capability, in display order.
     *
     * @var string[]
     */
    public const ALL_CAPS = [
        'grants_access',
        'grants_manage_organisations',
        'grants_review',
        'grants_decide',
        'grants_pay',
        'grants_export_committee',
        'grants_export_financial',
        'grants_export_contacts',
        'grants_manage_rounds',
        'grants_manage_settings',
        'grants_privacy',
    ];

    /**
     * Grant every grants_* capability to the administrator role.
     * Called on activation. Safe to call repeatedly — add_cap() is idempotent.
     */
    public static function register(): void {
        $role = get_role( 'administrator' );
        if ( $role ) {
            foreach ( self::ALL_CAPS as $cap ) {
                $role->add_cap( $cap );
            }
        }
    }

    /**
     * Human-readable label for each capability, for the access screens.
     *
     * @return array<string, string>
     */
    public static function labels(): array {
        return [
            'grants_access'               => __( 'Access: see the menu and read applications and organisation history', 'rotary-grants' ),
            'grants_manage_organisations' => __( 'Edit organisation/contact data; link and merge organisations', 'rotary-grants' ),
            'grants_review'               => __( 'Add reviews and recommendations', 'rotary-grants' ),
            'grants_decide'               => __( 'Approve, decline, defer and revise decisions', 'rotary-grants' ),
            'grants_pay'                  => __( 'Record and reverse payments', 'rotary-grants' ),
            'grants_export_committee'     => __( 'Export committee information', 'rotary-grants' ),
            'grants_export_financial'     => __( 'Export financial reports', 'rotary-grants' ),
            'grants_export_contacts'      => __( 'Export the future-round contact list', 'rotary-grants' ),
            'grants_manage_rounds'        => __( 'Manage funding rounds and budgets', 'rotary-grants' ),
            'grants_manage_settings'      => __( 'Manage settings, including notification recipients', 'rotary-grants' ),
            'grants_privacy'              => __( 'Execute retention and anonymisation', 'rotary-grants' ),
        ];
    }
}
