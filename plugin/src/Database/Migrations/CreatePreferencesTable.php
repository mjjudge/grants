<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.6.0 — grants_preferences table.
 *
 * Purpose-specific contact permission history (currently only
 * 'future_rounds'). Each opt-in is a row recording the exact wording
 * version, when, and the evidence; withdrawal fills withdrawn_at /
 * withdrawal_source on that row and is never undone — a later opt-in is a
 * new row. The current state is the newest row for (contact, purpose).
 */
class CreatePreferencesTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_preferences';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id           BIGINT UNSIGNED NOT NULL,
            purpose              VARCHAR(40)     NOT NULL,
            status               VARCHAR(20)     NOT NULL DEFAULT 'opted_in',
            wording_version      VARCHAR(60)     NOT NULL DEFAULT '',
            wording_text         TEXT                     DEFAULT NULL,
            recorded_at          DATETIME        NOT NULL,
            evidence_source      VARCHAR(255)    NOT NULL DEFAULT '',
            recorded_by_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            withdrawn_at         DATETIME                 DEFAULT NULL,
            withdrawal_source    VARCHAR(255)    NOT NULL DEFAULT '',
            withdrawn_by_user_id BIGINT UNSIGNED          DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_contact_purpose (contact_id, purpose),
            KEY idx_purpose_status (purpose, status)
        ) {$charset};";

        dbDelta( $sql );
    }
}
