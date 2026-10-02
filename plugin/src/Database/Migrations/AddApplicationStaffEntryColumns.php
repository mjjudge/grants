<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.6.0 — staff-entered application columns on grants_applications.
 *
 *   entered_by_user_id — staff member who keyed in a paper/email/phone
 *                        application (NULL for the public form)
 *   entry_reason       — why it was entered by staff (required if late)
 *   is_late            — received outside the round's opening window
 */
class AddApplicationStaffEntryColumns implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_applications';
        $columns = [
            'entered_by_user_id' => "ALTER TABLE {$table} ADD COLUMN entered_by_user_id BIGINT UNSIGNED DEFAULT NULL AFTER source",
            'entry_reason'       => "ALTER TABLE {$table} ADD COLUMN entry_reason TEXT DEFAULT NULL AFTER entered_by_user_id",
            'is_late'            => "ALTER TABLE {$table} ADD COLUMN is_late TINYINT(1) NOT NULL DEFAULT 0 AFTER entry_reason",
        ];

        foreach ( $columns as $col => $sql ) {
            $exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '` LIKE %s', $col ) );
            if ( empty( $exists ) ) {
                $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — no user input
            }
        }
    }
}
