<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Awards and their conditions. Awards are created and revised only by
 * DecisionService; this class reads them and manages condition fulfilment.
 *
 * Payment progress (unpaid / part paid / paid) is always derived from the
 * payment ledger (G08), never stored.
 */
class AwardService {

    public const APPROVED   = 'approved';
    public const CANCELLED  = 'cancelled';
    public const SUPERSEDED = 'superseded';

    public function find( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
        return $row ? self::hydrate( $row ) : null;
    }

    public function for_application( int $application_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE application_id = %d", $application_id ) );
        return $row ? self::hydrate( $row ) : null;
    }

    /**
     * @return object[] With organisation name, application reference and outstanding pre-payment condition count.
     */
    public function list( ?int $round_id = null, ?string $status = null ): array {
        global $wpdb;
        $where = [ '1=1' ];
        $args  = [];
        if ( $round_id ) {
            $where[] = 'aw.round_id = %d';
            $args[]  = $round_id;
        }
        if ( in_array( $status, [ self::APPROVED, self::CANCELLED, self::SUPERSEDED ], true ) ) {
            $where[] = 'aw.status = %s';
            $args[]  = $status;
        }
        $sql = "SELECT aw.*, o.name AS organisation_name, a.public_reference, r.label AS round_label, r.fund_name,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}grants_award_conditions c WHERE c.award_id = aw.id AND c.before_payment = 1 AND c.fulfilled_at IS NULL) AS open_conditions
                FROM {$this->table()} aw
                LEFT JOIN {$wpdb->prefix}grants_organisations o ON o.id = aw.organisation_id
                LEFT JOIN {$wpdb->prefix}grants_applications a ON a.id = aw.application_id
                LEFT JOIN {$wpdb->prefix}grants_rounds r ON r.id = aw.round_id
                WHERE " . implode( ' AND ', $where ) . ' ORDER BY aw.approved_on DESC, aw.id DESC';
        $rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — placeholders above
        return array_map( [ self::class, 'hydrate' ], (array) $rows );
    }

    /** @return object[] Oldest first. */
    public function conditions( int $award_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, u.display_name AS fulfilled_by_name FROM {$wpdb->prefix}grants_award_conditions c
             LEFT JOIN {$wpdb->users} u ON u.ID = c.fulfilled_by_user_id
             WHERE c.award_id = %d ORDER BY c.id",
            $award_id
        ) );
    }

    public function open_pre_payment_conditions( int $award_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}grants_award_conditions WHERE award_id = %d AND before_payment = 1 AND fulfilled_at IS NULL",
            $award_id
        ) );
    }

    /**
     * Mark a condition met, with evidence. grants_manage_organisations or
     * grants_decide, plus a "no conflict" declaration on the application.
     *
     * @return true|\WP_Error
     */
    public function fulfil_condition( int $condition_id, string $evidence ): true|\WP_Error {
        if ( ! current_user_can( 'grants_manage_organisations' ) && ! current_user_can( 'grants_decide' ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to manage award conditions.', 'rotary-grants' ) );
        }
        global $wpdb;
        $cond = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}grants_award_conditions WHERE id = %d", $condition_id ) );
        $award = $cond ? $this->find( (int) $cond->award_id ) : null;
        if ( ! $cond || ! $award ) {
            return new \WP_Error( 'not_found', __( 'Condition not found.', 'rotary-grants' ) );
        }
        if ( $award->application_id ) {
            $gate = ( new ConflictService() )->require_clear( $award->application_id );
            if ( $gate ) {
                return $gate;
            }
        }
        $evidence = trim( sanitize_textarea_field( $evidence ) );
        if ( $evidence === '' ) {
            return new \WP_Error( 'evidence', __( 'Note the evidence that the condition has been met (e.g. "safeguarding policy received 3 April").', 'rotary-grants' ) );
        }
        if ( $cond->fulfilled_at !== null ) {
            return new \WP_Error( 'already', __( 'This condition is already marked as met.', 'rotary-grants' ) );
        }
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}grants_award_conditions SET fulfilled_at = %s, fulfilled_by_user_id = %d, evidence_note = %s WHERE id = %d AND fulfilled_at IS NULL",
            SiteTime::now_utc(),
            get_current_user_id(),
            mb_substr( $evidence, 0, 2000 ),
            $condition_id
        ) );
        AuditLogger::record( 'award_condition_fulfilled', 'award', $award->id, [ 'condition_id' => $condition_id ] );
        return true;
    }

    /**
     * Net paid against an award (payments − reversals). Zero until the
     * payment ledger exists (G08).
     */
    public function net_paid( int $award_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'grants_payments';
        static $exists = null;
        if ( $exists === null ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        }
        if ( ! $exists ) {
            return 0;
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN entry_type = 'payment' THEN amount_pence ELSE -amount_pence END), 0) FROM {$table} WHERE award_id = %d",
            $award_id
        ) );
    }

    public static function status_label( string $status ): string {
        return match ( $status ) {
            self::APPROVED   => __( 'Approved', 'rotary-grants' ),
            self::CANCELLED  => __( 'Cancelled', 'rotary-grants' ),
            self::SUPERSEDED => __( 'Superseded', 'rotary-grants' ),
            default          => $status,
        };
    }

    private static function hydrate( object $row ): object {
        foreach ( [ 'id', 'organisation_id', 'round_id', 'row_version', 'over_budget_pence' ] as $f ) {
            if ( isset( $row->$f ) ) {
                $row->$f = (int) $row->$f;
            }
        }
        $row->application_id = empty( $row->application_id ) ? null : (int) $row->application_id;
        $row->approved_pence = $row->approved_pence === null ? null : (int) $row->approved_pence;
        if ( isset( $row->open_conditions ) ) {
            $row->open_conditions = (int) $row->open_conditions;
        }
        return $row;
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'grants_awards';
    }
}
