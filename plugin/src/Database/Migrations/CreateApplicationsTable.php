<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.4.0 — grants_applications table.
 *
 * answer_snapshot_json is the immutable record of what was submitted: every
 * answer plus the exact round wording, declaration, privacy-notice and
 * form versions shown. organisation_name / organisation_town /
 * requested_pence are copied out of it for listing and filtering only.
 *
 * organisation_id / contact_id stay NULL until staff link the application
 * to an organisation (G05) — public submissions are never matched
 * automatically.
 *
 * submitted_at is UTC and server-set; NULL only for historical imports that
 * lack a submission date (G10).
 */
class CreateApplicationsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_applications';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            round_id              BIGINT UNSIGNED NOT NULL,
            organisation_id       BIGINT UNSIGNED          DEFAULT NULL,
            contact_id            BIGINT UNSIGNED          DEFAULT NULL,
            public_reference      VARCHAR(20)     NOT NULL,
            status                VARCHAR(30)     NOT NULL DEFAULT 'received',
            organisation_name     VARCHAR(200)    NOT NULL DEFAULT '',
            organisation_town     VARCHAR(100)    NOT NULL DEFAULT '',
            requested_pence       BIGINT                   DEFAULT NULL,
            answer_snapshot_json  LONGTEXT        NOT NULL,
            form_version          VARCHAR(50)     NOT NULL DEFAULT '',
            policy_version        VARCHAR(50)     NOT NULL DEFAULT '',
            source                VARCHAR(30)     NOT NULL DEFAULT 'public_form',
            submitted_at          DATETIME                 DEFAULT NULL,
            row_version           INT UNSIGNED    NOT NULL DEFAULT 1,
            created_at            DATETIME        NOT NULL,
            updated_at            DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_public_reference (public_reference),
            KEY idx_round_status_submitted (round_id, status, submitted_at),
            KEY idx_organisation_round (organisation_id, round_id)
        ) {$charset};";

        dbDelta( $sql );
    }
}
