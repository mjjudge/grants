<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Conflict-of-interest declarations (DEC-013).
 *
 * Basis: Rotary International's Conflict of Interest Policy for Grants
 * (TRF Code of Policies §30.040 — declare to the chair before selection
 * begins; no participation with a conflict) and the Charity Commission's
 * CC29 (identify, declare, manage, record).
 *
 * Rules enforced here, server-side:
 *   - A committee member must declare on an application ('none',
 *     'financial' or 'loyalty') before reviewing, noting, requesting
 *     information, changing status or deciding — require_clear().
 *   - Any declared conflict blocks all of those for that member on that
 *     application, and hides the committee's reviews and notes from them.
 *   - No overrides in this release. A declared conflict can't later be
 *     changed to 'none'; declarations are append-only.
 */
class ConflictService {

    public const NONE      = 'none';
    public const FINANCIAL = 'financial';
    public const LOYALTY   = 'loyalty';

    public const UNDECLARED = 'undeclared';

    /** Holding any of these makes someone part of the committee for this purpose. */
    public const COMMITTEE_CAPS = [ 'grants_review', 'grants_decide', 'grants_manage_organisations' ];

    public static function is_committee( ?int $user_id = null ): bool {
        $user_id = $user_id ?? get_current_user_id();
        foreach ( self::COMMITTEE_CAPS as $cap ) {
            if ( user_can( $user_id, $cap ) ) {
                return true;
            }
        }
        return false;
    }

    public function current( int $application_id, ?int $user_id = null ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE application_id = %d AND user_id = %d ORDER BY id DESC LIMIT 1",
            $application_id,
            $user_id ?? get_current_user_id()
        ) );
        return $row ?: null;
    }

    /** 'undeclared' | 'none' | 'financial' | 'loyalty' */
    public function status_for( int $application_id, ?int $user_id = null ): string {
        $row = $this->current( $application_id, $user_id );
        return $row ? $row->declaration : self::UNDECLARED;
    }

    public function is_clear( int $application_id, ?int $user_id = null ): bool {
        return $this->status_for( $application_id, $user_id ) === self::NONE;
    }

    /**
     * The gate every committee action passes through.
     *
     * @return \WP_Error|null null when the current user may act.
     */
    public function require_clear( int $application_id ): ?\WP_Error {
        return match ( $this->status_for( $application_id ) ) {
            self::NONE       => null,
            self::UNDECLARED => new \WP_Error( 'undeclared', __( 'Declare whether you have a conflict of interest with this application before taking part.', 'rotary-grants' ) ),
            default          => new \WP_Error( 'conflicted', __( 'You have declared a conflict of interest with this application, so you cannot take part in reviewing or deciding it.', 'rotary-grants' ) ),
        };
    }

    /**
     * Record the current user's declaration on one application.
     *
     * @return true|\WP_Error
     */
    public function declare( int $application_id, string $declaration, string $description = '' ): true|\WP_Error {
        if ( ! self::is_committee() ) {
            return new \WP_Error( 'forbidden', __( 'Only committee members declare conflicts of interest.', 'rotary-grants' ) );
        }
        if ( ! in_array( $declaration, [ self::NONE, self::FINANCIAL, self::LOYALTY ], true ) ) {
            return new \WP_Error( 'declaration', __( 'Choose whether you have a conflict of interest.', 'rotary-grants' ) );
        }
        if ( ! ( new ApplicationService() )->find( $application_id ) ) {
            return new \WP_Error( 'not_found', __( 'Application not found.', 'rotary-grants' ) );
        }
        $description = trim( sanitize_textarea_field( $description ) );
        if ( $declaration !== self::NONE && $description === '' ) {
            return new \WP_Error( 'description', __( 'Briefly describe the conflict (e.g. "I am a trustee of this organisation").', 'rotary-grants' ) );
        }
        if ( mb_strlen( $description ) > 1000 ) {
            return new \WP_Error( 'description', __( 'Please keep the description to 1000 characters or fewer.', 'rotary-grants' ) );
        }

        $current = $this->current( $application_id );
        if ( $current && $current->declaration !== self::NONE && $declaration === self::NONE ) {
            return new \WP_Error( 'cannot_withdraw', __( 'A declared conflict cannot be withdrawn. If it was declared in error, ask the committee chair to note this in the minutes.', 'rotary-grants' ) );
        }
        if ( $current && $current->declaration === $declaration && (string) $current->description === $description ) {
            return true;
        }

        global $wpdb;
        $wpdb->insert(
            $this->table(),
            [
                'application_id' => $application_id,
                'user_id'        => get_current_user_id(),
                'declaration'    => $declaration,
                'description'    => $description !== '' ? $description : null,
                'declared_at'    => SiteTime::now_utc(),
            ],
            [ '%d', '%d', '%s', '%s', '%s' ]
        );
        AuditLogger::record( 'conflict_declared', 'application', $application_id, [
            'declaration' => $declaration,
            'previous'    => $current->declaration ?? null,
        ] );
        return true;
    }

    /**
     * Declarations for one member across a round: application id => current row.
     *
     * @return array<int, object>
     */
    public function for_user_in_round( int $round_id, ?int $user_id = null ): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT c.* FROM {$this->table()} c
             JOIN {$wpdb->prefix}grants_applications a ON a.id = c.application_id
             WHERE a.round_id = %d AND c.user_id = %d
             ORDER BY c.id",
            $round_id,
            $user_id ?? get_current_user_id()
        ) );
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row->application_id ] = $row; // later rows overwrite earlier: newest wins
        }
        return $out;
    }

    /**
     * The round's conflicts register (every declaration, oldest first), for
     * the chair: grants_decide or grants_manage_rounds.
     *
     * @return object[]|\WP_Error
     */
    public function register( int $round_id ): array|\WP_Error {
        if ( ! current_user_can( 'grants_decide' ) && ! current_user_can( 'grants_manage_rounds' ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to view the conflicts register.', 'rotary-grants' ) );
        }
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, a.public_reference, a.organisation_name, u.display_name
             FROM {$this->table()} c
             JOIN {$wpdb->prefix}grants_applications a ON a.id = c.application_id
             LEFT JOIN {$wpdb->users} u ON u.ID = c.user_id
             WHERE a.round_id = %d
             ORDER BY c.declared_at, c.id",
            $round_id
        ) );
    }

    public static function label( string $declaration ): string {
        return match ( $declaration ) {
            self::NONE       => __( 'No conflict', 'rotary-grants' ),
            self::FINANCIAL  => __( 'Financial conflict', 'rotary-grants' ),
            self::LOYALTY    => __( 'Loyalty conflict', 'rotary-grants' ),
            default          => __( 'Not yet declared', 'rotary-grants' ),
        };
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'grants_conflicts';
    }
}
