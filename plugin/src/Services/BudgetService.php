<?php

namespace Rotary\Grants\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Round money, per docs/02 "Financial semantics" — integer pence only.
 *
 *   approved commitments = SUM of approved_pence of the round's awards with
 *                          status 'approved' (unknown imported amounts are
 *                          counted separately, never as zero)
 *   available to award   = budget − approved commitments (negative when the
 *                          committee has knowingly gone over budget — DEC-014)
 *   net paid             = payments − reversals on the round's awards
 *   outstanding          = approved commitments − net paid on approved awards
 *
 * Payments never reduce "available" a second time (G08 adds net paid and
 * outstanding without touching these figures).
 */
class BudgetService {

    /**
     * @param int|null $exclude_award_id Leave one award out (when revising it).
     */
    public function commitments( int $round_id, ?int $exclude_award_id = null ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(approved_pence), 0) FROM {$wpdb->prefix}grants_awards
             WHERE round_id = %d AND status = 'approved' AND approved_pence IS NOT NULL AND id <> %d",
            $round_id,
            $exclude_award_id ?? 0
        ) );
    }

    /**
     * @return array{budget: int|null, committed: int, available: int|null, over_committed: int,
     *               award_count: int, incomplete: int, over_budget_awards: object[],
     *               net_paid: int, outstanding: int}
     */
    public function summary( object $round ): array {
        global $wpdb;
        $committed = $this->commitments( (int) $round->id );
        $budget    = $round->budget_pence;
        $available = $budget === null ? null : $budget - $committed;
        $counts    = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS n, SUM(approved_pence IS NULL) AS unknown FROM {$wpdb->prefix}grants_awards WHERE round_id = %d AND status = 'approved'",
            (int) $round->id
        ) );
        $over = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT aw.id, aw.over_budget_pence, aw.funding_note, aw.approved_pence, o.name AS organisation_name, a.public_reference
             FROM {$wpdb->prefix}grants_awards aw
             LEFT JOIN {$wpdb->prefix}grants_organisations o ON o.id = aw.organisation_id
             LEFT JOIN {$wpdb->prefix}grants_applications a ON a.id = aw.application_id
             WHERE aw.round_id = %d AND aw.status = 'approved' AND aw.over_budget_pence > 0
             ORDER BY aw.id",
            (int) $round->id
        ) );
        $paid = $wpdb->get_row( $wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN p.entry_type = 'payment' THEN p.amount_pence ELSE -p.amount_pence END), 0) AS net,
                    COALESCE(SUM(CASE WHEN aw.status = 'approved' THEN (CASE WHEN p.entry_type = 'payment' THEN p.amount_pence ELSE -p.amount_pence END) ELSE 0 END), 0) AS net_approved
             FROM {$wpdb->prefix}grants_payments p JOIN {$wpdb->prefix}grants_awards aw ON aw.id = p.award_id
             WHERE aw.round_id = %d",
            (int) $round->id
        ) );
        return [
            'net_paid'           => (int) ( $paid->net ?? 0 ),
            'outstanding'        => $committed - (int) ( $paid->net_approved ?? 0 ),
            'budget'             => $budget,
            'committed'          => $committed,
            'available'          => $available,
            'over_committed'     => $available !== null && $available < 0 ? -$available : 0,
            'award_count'        => (int) ( $counts->n ?? 0 ),
            'incomplete'         => (int) ( $counts->unknown ?? 0 ),
            'over_budget_awards' => $over,
        ];
    }
}
