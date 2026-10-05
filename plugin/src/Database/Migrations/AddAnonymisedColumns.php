<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.11.0 — anonymised_at on grants_applications and grants_contacts.
 *
 * Set when personal details and free text are removed under the retention
 * policy or an erasure request (G11). The rows themselves — and every award
 * and payment — are kept as the club's financial record.
 */
class AddAnonymisedColumns implements MigrationInterface {

    public function up(): void {
        global $wpdb;
        foreach ( [ 'grants_applications', 'grants_contacts' ] as $t ) {
            $table  = $wpdb->prefix . $t;
            $exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '` LIKE %s', 'anonymised_at' ) );
            if ( empty( $exists ) ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN anonymised_at DATETIME DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — no user input
            }
        }
    }
}
