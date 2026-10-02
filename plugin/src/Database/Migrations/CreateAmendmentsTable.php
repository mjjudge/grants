<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.6.0 — grants_amendments table.
 *
 * Dated corrections to organisation and contact records, with the previous
 * and new values ({"field": [old, new]}) and the reason. This, not the
 * audit log, is where old personal values live, so retention processing
 * (G11) has one place to anonymise them. Application snapshots are never
 * amended.
 */
class CreateAmendmentsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_amendments';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type    VARCHAR(30)     NOT NULL,
            entity_id      BIGINT UNSIGNED NOT NULL,
            changes_json   LONGTEXT        NOT NULL,
            reason         TEXT                     DEFAULT NULL,
            actor_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at     DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_entity (entity_type, entity_id)
        ) {$charset};";

        dbDelta( $sql );
    }
}
