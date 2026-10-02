<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.2.0 — grants_audit_events table.
 *
 * Append-only record of every admin action that creates, updates, reviews,
 * decides or pays. `summary` is a small JSON object describing what changed —
 * never bank details, raw tokens, full application answers, or personal
 * contact details. Not tamper-proof against database administrators.
 *
 * created_at is UTC.
 */
class CreateAuditEventsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_audit_events';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            action              VARCHAR(64)     NOT NULL DEFAULT '',
            entity_type         VARCHAR(64)     NOT NULL DEFAULT '',
            entity_id           BIGINT UNSIGNED          DEFAULT NULL,
            summary             TEXT                     DEFAULT NULL,
            request_id          CHAR(36)        NOT NULL DEFAULT '',
            created_at          DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_actor       (actor_user_id),
            KEY idx_action      (action),
            KEY idx_entity      (entity_type, entity_id),
            KEY idx_created_at  (created_at)
        ) {$charset};";

        dbDelta( $sql );
    }
}
