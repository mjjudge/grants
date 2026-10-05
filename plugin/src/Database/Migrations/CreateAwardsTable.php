<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.8.0 — grants_awards table.
 *
 * At most one award per application (UNIQUE application_id; NULL allowed
 * for historical award-only imports in G10). A revised approval changes the
 * same award row (amount, effective_decision_id) so payments stay attached;
 * the history is in grants_decisions. Approved commitments for a round =
 * SUM(approved_pence) of status 'approved'.
 *
 *   status        approved | cancelled | superseded
 *   approved_pence NULL only for an imported award with an unknown amount
 *                 (incomplete_history = 1)
 *   over_budget_pence / funding_note copied from the effective decision
 */
class CreateAwardsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_awards';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                     BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            organisation_id        BIGINT UNSIGNED  NOT NULL,
            round_id               BIGINT UNSIGNED  NOT NULL,
            application_id         BIGINT UNSIGNED           DEFAULT NULL,
            effective_decision_id  BIGINT UNSIGNED           DEFAULT NULL,
            approved_pence         BIGINT                    DEFAULT NULL,
            status                 VARCHAR(20)      NOT NULL DEFAULT 'approved',
            approved_on            DATE                      DEFAULT NULL,
            over_budget_pence      BIGINT           NOT NULL DEFAULT 0,
            funding_note           TEXT                      DEFAULT NULL,
            source                 VARCHAR(20)      NOT NULL DEFAULT 'decision',
            provenance             TEXT                      DEFAULT NULL,
            incomplete_history     TINYINT(1)       NOT NULL DEFAULT 0,
            row_version            INT UNSIGNED     NOT NULL DEFAULT 1,
            created_at             DATETIME         NOT NULL,
            updated_at             DATETIME         NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_application (application_id),
            KEY idx_round_status (round_id, status),
            KEY idx_organisation (organisation_id)
        ) {$charset};";

        dbDelta( $sql );
    }
}
