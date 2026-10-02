<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.4.0 — grants_submissions table.
 *
 * One row per accepted public submission key, created in the same
 * transaction as the application. The UNIQUE key on key_hash is what makes
 * a retried or double-clicked submission return the original application
 * instead of creating another; payload_hash detects reuse of a key with
 * different answers. Only hashes are stored, never the key itself.
 */
class CreateSubmissionsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_submissions';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            key_hash        CHAR(64)        NOT NULL,
            payload_hash    CHAR(64)        NOT NULL,
            application_id  BIGINT UNSIGNED          DEFAULT NULL,
            created_at      DATETIME        NOT NULL,
            expires_at      DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_key_hash (key_hash),
            KEY idx_expires_at (expires_at)
        ) {$charset};";

        dbDelta( $sql );
    }
}
