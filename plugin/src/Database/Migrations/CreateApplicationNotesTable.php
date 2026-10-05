<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.7.0 — grants_application_notes table.
 *
 * The committee's running record on an application:
 *   note          — internal note (never shown to applicants)
 *   info_request  — request for more information; drafted, then sent by an
 *                   explicit staff action (sent_at/sent_by set, email queued)
 *   addendum      — the applicant's reply or extra information, entered by
 *                   staff with the date it was received
 *   status        — a status change with its reason (system-written)
 * Rows are never edited after sending/creation except a draft's body.
 */
class CreateApplicationNotesTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_application_notes';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id   BIGINT UNSIGNED NOT NULL,
            kind             VARCHAR(20)     NOT NULL,
            body             TEXT            NOT NULL,
            author_user_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at       DATETIME        NOT NULL,
            received_at      DATETIME                 DEFAULT NULL,
            sent_at          DATETIME                 DEFAULT NULL,
            sent_by_user_id  BIGINT UNSIGNED          DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_application_kind (application_id, kind)
        ) {$charset};";

        dbDelta( $sql );
    }
}
