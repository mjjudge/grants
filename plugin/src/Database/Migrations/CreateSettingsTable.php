<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.2.0 — grants_settings table.
 *
 * Plugin-scoped key/value settings (help email, notification recipients, …),
 * read and written only through Services\SettingsService, which whitelists
 * the keys. updated_at is UTC.
 */
class CreateSettingsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_settings';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            setting_key         VARCHAR(64)     NOT NULL,
            setting_value       LONGTEXT        NOT NULL,
            updated_at          DATETIME        NOT NULL,
            updated_by_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (setting_key)
        ) {$charset};";

        dbDelta( $sql );
    }
}
