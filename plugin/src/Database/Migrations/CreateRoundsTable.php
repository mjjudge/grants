<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.3.0 — grants_rounds table.
 *
 * One row per funding round. fund_name identifies which fund (e.g. "Tree of
 * Light") the round belongs to, so one plugin serves several funds.
 *
 *   opens_at / closes_at — UTC; opening inclusive, closing exclusive.
 *   budget_pence / cap_pence — signed integer pence; NULL until set.
 *   *_text — the round's own public wording (HTML limited to post kses).
 *   policy_version — incremented by RoundService whenever wording changes.
 *   form_version — set by the application form (G03); '' until then.
 *   row_version — optimistic-concurrency counter for edits.
 */
class CreateRoundsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_rounds';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                       BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
            label                    VARCHAR(150)      NOT NULL DEFAULT '',
            fund_name                VARCHAR(150)      NOT NULL DEFAULT '',
            campaign_year            SMALLINT UNSIGNED NOT NULL,
            accounting_period_label  VARCHAR(100)      NOT NULL DEFAULT '',
            opens_at                 DATETIME                   DEFAULT NULL,
            closes_at                DATETIME                   DEFAULT NULL,
            status                   VARCHAR(20)       NOT NULL DEFAULT 'draft',
            budget_pence             BIGINT                     DEFAULT NULL,
            cap_pence                BIGINT                     DEFAULT NULL,
            intro_text               TEXT                       DEFAULT NULL,
            eligibility_text         TEXT                       DEFAULT NULL,
            exclusions_text          TEXT                       DEFAULT NULL,
            publicity_text           TEXT                       DEFAULT NULL,
            policy_version           VARCHAR(50)       NOT NULL DEFAULT '1',
            form_version             VARCHAR(50)       NOT NULL DEFAULT '',
            row_version              INT UNSIGNED      NOT NULL DEFAULT 1,
            created_at               DATETIME          NOT NULL,
            created_by_user_id       BIGINT UNSIGNED   NOT NULL DEFAULT 0,
            updated_at               DATETIME          NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_status           (status),
            KEY idx_fund_status      (fund_name, status),
            KEY idx_opens_closes     (opens_at, closes_at)
        ) {$charset};";

        dbDelta( $sql );
    }
}
