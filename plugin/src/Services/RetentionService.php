<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Retention policy (docs/04, DEC-018): a dry-run plan per category, applied
 * only when someone with grants_privacy presses Apply. Periods come from
 * Settings (suggested defaults until the club confirms its own).
 *
 * Categories:
 *   unsuccessful  — applications with no award (declined, withdrawn,
 *                   duplicate, or a cancelled award never paid), N months
 *                   after the decision / last change
 *   award         — applications with an award, N months after the later of
 *                   approval and last payment; award and payments kept
 *   contact       — former contacts (ended), N months after they ended
 *   notification  — email log recipients, N months after queueing
 * Applications still being considered are never included.
 *
 * Daily housekeeping (automatic, no personal data): expired submission keys.
 */
class RetentionService {

    public const CRON_HOOK = 'grants_daily_housekeeping';

    private const LIMIT = 500;

    public static function register_cron(): void {
        add_action( self::CRON_HOOK, static fn() => ( new self() )->housekeeping() );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 3600, 'daily', self::CRON_HOOK );
        }
    }

    public static function unschedule_cron(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /** Remove expired submission keys (hashes only — security identifiers). */
    public function housekeeping(): int {
        global $wpdb;
        return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}grants_submissions WHERE expires_at < %s", SiteTime::now_utc() ) );
    }

    /**
     * What would be anonymised now, per category.
     *
     * @return array<string, array{label:string, months:int, cutoff:string, items:list<array{id:int, label:string, since:string}>, keeps:string}>
     */
    public function plan( ?string $now_utc = null ): array {
        global $wpdb;
        $p        = $wpdb->prefix;
        $settings = new SettingsService();
        $now      = $now_utc ?? SiteTime::now_utc();
        $cut      = static fn( int $months ): string => gmdate( 'Y-m-d H:i:s', strtotime( $now . " UTC -{$months} months" ) );
        $open     = "'" . implode( "','", [ ApplicationStatus::RECEIVED, ApplicationStatus::UNDER_REVIEW, ApplicationStatus::MORE_INFO_REQUESTED ] ) . "'";

        $m_uns  = $settings->retention_months( 'retention_unsuccessful_months' );
        $m_aw   = $settings->retention_months( 'retention_award_months' );
        $m_con  = $settings->retention_months( 'retention_contact_months' );
        $m_note = $settings->retention_months( 'retention_notification_months' );

        // An application "has an award" if it has an approved award, or a cancelled one with payments.
        $award_sql = "SELECT aw.application_id FROM {$p}grants_awards aw WHERE aw.application_id IS NOT NULL AND (aw.status = 'approved'
                        OR EXISTS (SELECT 1 FROM {$p}grants_payments pm WHERE pm.award_id = aw.id))";

        $uns = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.public_reference, a.organisation_name,
                    COALESCE((SELECT CONCAT(d.decided_on, ' 12:00:00') FROM {$p}grants_decisions d WHERE d.application_id = a.id AND d.superseded_by IS NULL ORDER BY d.id DESC LIMIT 1), a.updated_at) AS since
             FROM {$p}grants_applications a
             WHERE a.anonymised_at IS NULL AND a.status NOT IN ({$open}) AND a.id NOT IN ({$award_sql})
             HAVING since < %s ORDER BY since LIMIT %d",
            $cut( $m_uns ),
            self::LIMIT
        ) );
        $aw = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.public_reference, a.organisation_name,
                    GREATEST(COALESCE(CONCAT(aw.approved_on, ' 12:00:00'), a.updated_at),
                             COALESCE((SELECT CONCAT(MAX(pm.paid_on), ' 12:00:00') FROM {$p}grants_payments pm WHERE pm.award_id = aw.id), '1970-01-01')) AS since
             FROM {$p}grants_applications a JOIN {$p}grants_awards aw ON aw.application_id = a.id
             WHERE a.anonymised_at IS NULL AND a.status NOT IN ({$open}) AND a.id IN ({$award_sql})
             HAVING since < %s ORDER BY since LIMIT %d",
            $cut( $m_aw ),
            self::LIMIT
        ) );
        $con = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id, c.name, o.name AS organisation_name, c.active_until AS since FROM {$p}grants_contacts c
             LEFT JOIN {$p}grants_organisations o ON o.id = c.organisation_id
             WHERE c.anonymised_at IS NULL AND c.active_until IS NOT NULL AND c.active_until < %s ORDER BY c.active_until LIMIT %d",
            $cut( $m_con ),
            self::LIMIT
        ) );
        $note = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT n.id, n.kind, n.created_at AS since, a.public_reference FROM {$p}grants_notifications n
             LEFT JOIN {$p}grants_applications a ON a.id = n.application_id
             WHERE n.recipient <> %s AND n.status <> 'pending' AND n.created_at < %s ORDER BY n.created_at LIMIT %d",
            PrivacyService::REMOVED,
            $cut( $m_note ),
            self::LIMIT
        ) );

        $item = static fn( $id, $label, $since ) => [ 'id' => (int) $id, 'label' => (string) $label, 'since' => (string) $since ];
        return [
            'unsuccessful' => [
                'label'  => __( 'Applications without an award', 'rotary-grants' ),
                'months' => $m_uns,
                'cutoff' => $cut( $m_uns ),
                'items'  => array_map( static fn( $r ) => $item( $r->id, $r->public_reference . ' — ' . $r->organisation_name, $r->since ), $uns ),
                'keeps'  => __( 'Kept: reference, round, organisation name and town, amount requested, decision and reason, dates. Removed: contact name, role, email, phone, declaration name, free-text answers, committee notes and review notes.', 'rotary-grants' ),
            ],
            'award' => [
                'label'  => __( 'Applications with an award', 'rotary-grants' ),
                'months' => $m_aw,
                'cutoff' => $cut( $m_aw ),
                'items'  => array_map( static fn( $r ) => $item( $r->id, $r->public_reference . ' — ' . $r->organisation_name, $r->since ), $aw ),
                'keeps'  => __( 'Kept as the club\'s financial record: the award, every payment and reversal, the decision, organisation name and amounts. Removed: the applicant\'s contact details, free-text answers and committee notes.', 'rotary-grants' ),
            ],
            'contact' => [
                'label'  => __( 'Former contacts', 'rotary-grants' ),
                'months' => $m_con,
                'cutoff' => $cut( $m_con ),
                'items'  => array_map( static fn( $r ) => $item( $r->id, $r->name . ' — ' . $r->organisation_name, $r->since ), $con ),
                'keeps'  => __( 'Kept: that the organisation had a contact for that period, and the history of their email permission (dates and evidence). Removed: name, role, email, phone, and those values in the correction history.', 'rotary-grants' ),
            ],
            'notification' => [
                'label'  => __( 'Email log recipients', 'rotary-grants' ),
                'months' => $m_note,
                'cutoff' => $cut( $m_note ),
                'items'  => array_map( static fn( $r ) => $item( $r->id, NotificationService::kind_label( $r->kind ) . ' — ' . $r->public_reference, $r->since ), $note ),
                'keeps'  => __( 'Kept: which email was sent for which application, when, and whether it was accepted. Removed: the recipient\'s email address.', 'rotary-grants' ),
            ],
        ];
    }

    /**
     * Apply the current plan. grants_privacy only. Returns counts per category.
     *
     * @return array<string,int>|\WP_Error
     */
    public function apply(): array|\WP_Error {
        if ( ! current_user_can( 'grants_privacy' ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to apply the retention policy.', 'rotary-grants' ) );
        }
        global $wpdb;
        $plan    = $this->plan();
        $privacy = new PrivacyService();
        $done    = [ 'unsuccessful' => 0, 'award' => 0, 'contact' => 0, 'notification' => 0 ];
        // Email log first: anonymising an application also clears its log
        // recipients, which would otherwise make this count understate the plan.
        $ids = array_column( $plan['notification']['items'], 'id' );
        if ( $ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $done['notification'] = (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}grants_notifications SET recipient = %s WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — placeholders built above
                PrivacyService::REMOVED,
                ...$ids
            ) );
        }
        foreach ( [ 'unsuccessful', 'award' ] as $cat ) {
            foreach ( $plan[ $cat ]['items'] as $it ) {
                $done[ $cat ] += $privacy->anonymise_application( $it['id'], 'retention: ' . $cat ) ? 1 : 0;
            }
        }
        foreach ( $plan['contact']['items'] as $it ) {
            $done['contact'] += $privacy->anonymise_contact( $it['id'], 'retention: former contact' ) ? 1 : 0;
        }
        AuditLogger::record( 'retention_applied', 'privacy', null, $done );
        return $done;
    }
}
