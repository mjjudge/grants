<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.8.0 — grants_award_conditions table.
 *
 * Conditions attached to an award. before_payment = must be marked
 * fulfilled (with an evidence note) before the treasurer can record a
 * payment (enforced in G08). Fulfilment is recorded, never deleted.
 */
class CreateAwardConditionsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_award_conditions';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            award_id             BIGINT UNSIGNED NOT NULL,
            condition_text       TEXT            NOT NULL,
            before_payment       TINYINT(1)      NOT NULL DEFAULT 1,
            fulfilled_at         DATETIME                 DEFAULT NULL,
            fulfilled_by_user_id BIGINT UNSIGNED          DEFAULT NULL,
            evidence_note        TEXT                     DEFAULT NULL,
            decision_id          BIGINT UNSIGNED          DEFAULT NULL,
            created_at           DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_award (award_id)
        ) {$charset};";

        dbDelta( $sql );
    }
}
