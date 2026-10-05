<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Support\ReportingYear;

defined( 'ABSPATH' ) || exit;

/**
 * Report data (docs/02 "Reporting", docs/06 money tests). Read-only.
 *
 * - Round-based by default: a 2026 round's award stays in the 2026 round
 *   whenever it is paid.
 * - Payment-date reporting is separate, by the reporting year in Settings.
 * - Aggregates use per-award / per-application subqueries so multiple
 *   payments or reviews never multiply award totals.
 * - Unknown amounts (historical imports) are counted beside totals, never
 *   treated as zero.
 *
 * Callers (the Reports screen / exports) check capabilities; the methods
 * that expose committee discussion respect conflict declarations.
 */
class ReportService {

    // =========================================================================
    // Round overview
    // =========================================================================

    /**
     * @return array{round: object, by_status: array<string,int>, applications: int, duplicates: int,
     *               requested: int, requested_unknown: int, unlinked: int,
     *               decisions: array<string,int>, budget: array}
     */
    public function round_overview( object $round ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT status, (duplicate_of_id IS NOT NULL) AS dup, COUNT(*) AS n,
                    COALESCE(SUM(requested_pence), 0) AS req, SUM(requested_pence IS NULL) AS req_unknown,
                    SUM(organisation_id IS NULL) AS unlinked
             FROM {$p}grants_applications WHERE round_id = %d GROUP BY status, dup",
            (int) $round->id
        ) );
        $by_status = array_fill_keys( ApplicationStatus::all(), 0 );
        $apps = $dups = $req = $req_unknown = $unlinked = 0;
        foreach ( $rows as $r ) {
            if ( (int) $r->dup ) {
                $dups += (int) $r->n;
                continue;
            }
            $by_status[ $r->status ] = ( $by_status[ $r->status ] ?? 0 ) + (int) $r->n;
            $apps                   += (int) $r->n;
            $unlinked               += (int) $r->unlinked;
            if ( $r->status !== ApplicationStatus::WITHDRAWN ) {
                $req         += (int) $r->req;
                $req_unknown += (int) $r->req_unknown;
            }
        }
        $decisions = array_fill_keys( [ DecisionService::APPROVE, DecisionService::DECLINE, DecisionService::DEFER ], 0 );
        foreach ( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT d.decision_type, COUNT(*) AS n FROM {$p}grants_decisions d
             JOIN {$p}grants_applications a ON a.id = d.application_id
             WHERE a.round_id = %d AND d.superseded_by IS NULL GROUP BY d.decision_type",
            (int) $round->id
        ) ) as $r ) {
            $decisions[ $r->decision_type ] = (int) $r->n;
        }
        return [
            'round'             => $round,
            'by_status'         => $by_status,
            'applications'      => $apps,
            'duplicates'        => $dups,
            'requested'         => $req,
            'requested_unknown' => $req_unknown,
            'unlinked'          => $unlinked,
            'decisions'         => $decisions,
            'budget'            => ( new BudgetService() )->summary( $round ),
        ];
    }

    // =========================================================================
    // Committee shortlist
    // =========================================================================

    /**
     * Open-for-committee applications (no duplicates, not withdrawn) with
     * review counts and recommendation tallies. Tallies are blanked for any
     * application on which the current user has declared a conflict.
     *
     * @return list<array<string,mixed>>
     */
    public function shortlist( int $round_id ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.public_reference, a.organisation_name, a.organisation_town, a.requested_pence, a.status, a.submitted_at, a.source, a.is_late,
                    (SELECT COUNT(*) FROM {$p}grants_reviews r WHERE r.application_id = a.id AND r.superseded_by IS NULL) AS reviews,
                    (SELECT COUNT(*) FROM {$p}grants_reviews r WHERE r.application_id = a.id AND r.superseded_by IS NULL AND r.recommendation = 'fund') AS rec_fund,
                    (SELECT COUNT(*) FROM {$p}grants_reviews r WHERE r.application_id = a.id AND r.superseded_by IS NULL AND r.recommendation = 'fund_partial') AS rec_part,
                    (SELECT COUNT(*) FROM {$p}grants_reviews r WHERE r.application_id = a.id AND r.superseded_by IS NULL AND r.recommendation = 'decline') AS rec_decline,
                    (SELECT COUNT(*) FROM {$p}grants_reviews r WHERE r.application_id = a.id AND r.superseded_by IS NULL AND r.recommendation = 'defer') AS rec_defer,
                    (SELECT d.decision_type FROM {$p}grants_decisions d WHERE d.application_id = a.id AND d.superseded_by IS NULL ORDER BY d.id DESC LIMIT 1) AS decision
             FROM {$p}grants_applications a
             WHERE a.round_id = %d AND a.duplicate_of_id IS NULL AND a.status <> %s
             ORDER BY a.organisation_name, a.id",
            $round_id,
            ApplicationStatus::WITHDRAWN
        ), ARRAY_A );

        $conflicts = new ConflictService();
        foreach ( $rows as &$r ) {
            $r['conflicted'] = in_array( $conflicts->status_for( (int) $r['id'] ), [ ConflictService::FINANCIAL, ConflictService::LOYALTY ], true );
            if ( $r['conflicted'] ) {
                foreach ( [ 'reviews', 'rec_fund', 'rec_part', 'rec_decline', 'rec_defer' ] as $k ) {
                    $r[ $k ] = null;
                }
            }
        }
        return $rows;
    }

    // =========================================================================
    // Financial (round-based)
    // =========================================================================

    /**
     * Awards in a round with net paid / outstanding, and reconciled totals.
     *
     * @return array{rows: list<array<string,mixed>>, totals: array<string,int>}
     */
    public function financial( int $round_id ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT aw.id, aw.status, aw.approved_pence, aw.approved_on, aw.over_budget_pence, aw.funding_note, aw.incomplete_history,
                    o.name AS organisation_name, a.public_reference,
                    (SELECT COALESCE(SUM(CASE WHEN pm.entry_type = 'payment' THEN pm.amount_pence ELSE -pm.amount_pence END), 0)
                       FROM {$p}grants_payments pm WHERE pm.award_id = aw.id) AS net_paid,
                    (SELECT MAX(pm.paid_on) FROM {$p}grants_payments pm WHERE pm.award_id = aw.id AND pm.entry_type = 'payment') AS last_paid_on
             FROM {$p}grants_awards aw
             LEFT JOIN {$p}grants_organisations o ON o.id = aw.organisation_id
             LEFT JOIN {$p}grants_applications a ON a.id = aw.application_id
             WHERE aw.round_id = %d
             ORDER BY aw.status, o.name, aw.id",
            $round_id
        ), ARRAY_A );

        $totals = [ 'approved' => 0, 'net_paid' => 0, 'outstanding' => 0, 'over_budget' => 0, 'unknown_amount' => 0, 'awards' => 0 ];
        foreach ( $rows as &$r ) {
            $r['approved_pence'] = $r['approved_pence'] === null ? null : (int) $r['approved_pence'];
            $r['net_paid']       = (int) $r['net_paid'];
            $active              = $r['status'] === AwardService::APPROVED;
            $r['outstanding']    = $active && $r['approved_pence'] !== null ? $r['approved_pence'] - $r['net_paid'] : null;
            $r['progress']       = PaymentService::progress_label( PaymentService::progress( $r['approved_pence'], $r['net_paid'] ) );
            $totals['net_paid'] += $r['net_paid'];
            if ( $active ) {
                $totals['awards']++;
                if ( $r['approved_pence'] === null ) {
                    $totals['unknown_amount']++;
                } else {
                    $totals['approved']    += $r['approved_pence'];
                    $totals['outstanding'] += $r['outstanding'];
                }
                $totals['over_budget'] += (int) $r['over_budget_pence'];
            }
        }
        return [ 'rows' => $rows, 'totals' => $totals ];
    }

    // =========================================================================
    // Payments by date
    // =========================================================================

    /**
     * Every ledger entry dated in a reporting year, with its round, so a
     * 2026-round award paid in 2027 appears here under 2027.
     *
     * @return array{label: string, from: string, to: string, rows: list<array<string,mixed>>,
     *               totals: array{payments:int, reversals:int, net:int}, by_round: array<string,int>}
     */
    public function payments_in_year( int $year ): array {
        global $wpdb;
        $p     = $wpdb->prefix;
        $start = ( new SettingsService() )->reporting_year_start_month();
        [ $from, $to ] = ReportingYear::bounds( $year, $start );
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.id, pm.paid_on, pm.entry_type, pm.amount_pence, pm.method, pm.method_note, pm.reference, pm.reversed_payment_id, pm.refund_received,
                    o.name AS organisation_name, a.public_reference, r.fund_name, r.label AS round_label, r.campaign_year
             FROM {$p}grants_payments pm
             JOIN {$p}grants_awards aw ON aw.id = pm.award_id
             LEFT JOIN {$p}grants_organisations o ON o.id = aw.organisation_id
             LEFT JOIN {$p}grants_applications a ON a.id = aw.application_id
             LEFT JOIN {$p}grants_rounds r ON r.id = aw.round_id
             WHERE pm.paid_on >= %s AND pm.paid_on < %s
             ORDER BY pm.paid_on, pm.id",
            $from,
            $to
        ), ARRAY_A );
        $totals   = [ 'payments' => 0, 'reversals' => 0, 'net' => 0 ];
        $by_round = [];
        $methods  = PaymentService::methods();
        foreach ( $rows as &$r ) {
            $amount             = (int) $r['amount_pence'];
            $signed             = $r['entry_type'] === PaymentService::PAYMENT ? $amount : -$amount;
            $r['signed_pence']  = $signed;
            $r['method_label']  = $r['entry_type'] === PaymentService::PAYMENT ? ( $methods[ $r['method'] ] ?? $r['method'] ) . ( $r['method_note'] !== '' ? ' (' . $r['method_note'] . ')' : '' ) : __( 'Reversal', 'rotary-grants' );
            $r['round_name']    = trim( (string) $r['fund_name'] . ' — ' . (string) $r['round_label'], ' —' );
            $totals[ $signed >= 0 ? 'payments' : 'reversals' ] += $amount;
            $totals['net']     += $signed;
            $by_round[ $r['round_name'] ] = ( $by_round[ $r['round_name'] ] ?? 0 ) + $signed;
        }
        return [
            'label'    => ReportingYear::label( $year, $start ),
            'from'     => $from,
            'to'       => $to,
            'rows'     => $rows,
            'totals'   => $totals,
            'by_round' => $by_round,
        ];
    }

    /** @return int[] Reporting years that have ledger entries, newest first (always includes the current year). */
    public function payment_years(): array {
        global $wpdb;
        $start = ( new SettingsService() )->reporting_year_start_month();
        $years = [ ReportingYear::current( $start ) ];
        foreach ( (array) $wpdb->get_col( "SELECT DISTINCT paid_on FROM {$wpdb->prefix}grants_payments" ) as $d ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — no user input
            $years[] = ReportingYear::for_date( (string) $d, $start );
        }
        $years = array_values( array_unique( $years ) );
        rsort( $years );
        return $years;
    }

    // =========================================================================
    // Future-round contacts
    // =========================================================================

    /**
     * Contacts who may be emailed about future rounds: current contacts
     * (not ended) of active organisations whose newest future-rounds
     * preference is an opt-in that hasn't been withdrawn. A historic opt-in
     * never overrides a later withdrawal (docs/04, docs/06).
     *
     * @return list<array<string,mixed>>
     */
    public function future_round_contacts(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT c.name, c.email, c.role, o.name AS organisation_name, o.town, pr.recorded_at, pr.wording_version, pr.evidence_source
             FROM {$p}grants_contacts c
             JOIN {$p}grants_organisations o ON o.id = c.organisation_id AND o.status = 'active'
             JOIN {$p}grants_preferences pr ON pr.id = (
                 SELECT MAX(p2.id) FROM {$p}grants_preferences p2 WHERE p2.contact_id = c.id AND p2.purpose = %s
             )
             WHERE c.active_until IS NULL AND c.email <> '' AND pr.status = %s AND pr.withdrawn_at IS NULL
             ORDER BY o.name, c.name",
            PreferenceService::PURPOSE_FUTURE_ROUNDS,
            PreferenceService::OPTED_IN
        ), ARRAY_A );
    }
}
