<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.7.0 — two small columns:
 *   grants_applications.duplicate_of_id — staff classification of a
 *     duplicate, pointing at the application that is kept (no deletion)
 *   grants_notifications.related_id — the note an info-request email is
 *     built from (the queue stores no message bodies itself)
 */
class AddApplicationDuplicateAndNotificationLink implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $columns = [
            [ $wpdb->prefix . 'grants_applications', 'duplicate_of_id', 'BIGINT UNSIGNED DEFAULT NULL AFTER is_late' ],
            [ $wpdb->prefix . 'grants_notifications', 'related_id', 'BIGINT UNSIGNED DEFAULT NULL AFTER kind' ],
        ];
        foreach ( $columns as [ $table, $col, $def ] ) {
            $exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '` LIKE %s', $col ) );
            if ( empty( $exists ) ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$def}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — no user input
            }
        }
    }
}
