<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.7.0 — grants_conflicts table.
 *
 * Append-only conflict-of-interest declarations: one row each time a
 * committee member declares on an application ('none', 'financial',
 * 'loyalty'). The newest row per (application, user) is current. A
 * declared conflict can never be replaced by 'none' (DEC-013). This table
 * is the CC29 record: who, which application, what, when.
 */
class CreateConflictsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_conflicts';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id  BIGINT UNSIGNED NOT NULL,
            user_id         BIGINT UNSIGNED NOT NULL,
            declaration     VARCHAR(20)     NOT NULL,
            description     TEXT                     DEFAULT NULL,
            declared_at     DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_application_user (application_id, user_id),
            KEY idx_user (user_id)
        ) {$charset};";

        dbDelta( $sql );
    }
}
