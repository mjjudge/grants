<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.8.0 — grants_decisions table.
 *
 * Append-only committee decisions. A revision is a new row with
 * supersedes_id; the effective decision for an application is the newest
 * row not superseded. Recorded decisions, not committee voting (docs/03).
 *
 *   decision_type      approve | decline | defer
 *   approved_pence     the approved amount (approve only)
 *   decided_on         the date the committee decided (local date)
 *   over_budget_pence  how far this approval took the round beyond its
 *                      budget (0 if within), with funding_note saying where
 *                      the extra money comes from (DEC-014)
 */
class CreateDecisionsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_decisions';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id     BIGINT UNSIGNED NOT NULL,
            decision_type      VARCHAR(20)     NOT NULL,
            approved_pence     BIGINT                   DEFAULT NULL,
            reason             TEXT            NOT NULL,
            meeting_reference  VARCHAR(150)    NOT NULL DEFAULT '',
            decided_on         DATE            NOT NULL,
            over_budget_pence  BIGINT          NOT NULL DEFAULT 0,
            funding_note       TEXT                     DEFAULT NULL,
            actor_user_id      BIGINT UNSIGNED NOT NULL,
            supersedes_id      BIGINT UNSIGNED          DEFAULT NULL,
            superseded_by      BIGINT UNSIGNED          DEFAULT NULL,
            created_at         DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_application_current (application_id, superseded_by)
        ) {$charset};";

        dbDelta( $sql );
    }
}
