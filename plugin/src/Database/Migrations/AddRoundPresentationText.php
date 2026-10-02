<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.4.0 — add grants_rounds.presentation_text.
 *
 * The per-round wording for the "possible presentation on collection"
 * undertaking, kept separate from publicity_text so a fund can use either,
 * both or neither.
 */
class AddRoundPresentationText implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table  = $wpdb->prefix . 'grants_rounds';
        $exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '` LIKE %s', 'presentation_text' ) );
        if ( empty( $exists ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN presentation_text TEXT DEFAULT NULL AFTER publicity_text" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — no user input
        }
    }
}
