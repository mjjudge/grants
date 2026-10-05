<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.7.0 — grants_reviews table.
 *
 * Internal committee reviews. Each save is a new row; superseded_by points
 * from an older version to its replacement, so a reviewer's current review
 * is the row with superseded_by IS NULL and the history is kept.
 * eligibility_json: {check: {"finding": met|not_met|unsure|not_checked, "note": "..."}}.
 * A recommendation never creates an award (docs/03).
 */
class CreateReviewsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_reviews';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id     BIGINT UNSIGNED NOT NULL,
            reviewer_user_id   BIGINT UNSIGNED NOT NULL,
            eligibility_json   LONGTEXT        NOT NULL,
            recommendation     VARCHAR(30)     NOT NULL DEFAULT 'none',
            recommended_pence  BIGINT                   DEFAULT NULL,
            notes              TEXT                     DEFAULT NULL,
            reviewed_at        DATETIME        NOT NULL,
            superseded_by      BIGINT UNSIGNED          DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_application_current (application_id, superseded_by),
            KEY idx_reviewer (reviewer_user_id)
        ) {$charset};";

        dbDelta( $sql );
    }
}
