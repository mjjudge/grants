<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.6.0 — grants_organisations table.
 *
 * Community groups requesting money. Deliberately NOT Tree of Light's
 * tol_companies (businesses giving money) — different purpose and lawful
 * basis; see docs/02 "Do not reuse Tree of Light's tables".
 *
 * name_key / charity_key are normalised copies used only to suggest match
 * candidates to staff; nothing is ever linked or merged automatically.
 * A merged organisation keeps its row (status 'merged', merged_into_id) so
 * old references still resolve and the merge reason is preserved.
 */
class CreateOrganisationsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_organisations';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name                VARCHAR(200)    NOT NULL DEFAULT '',
            name_key            VARCHAR(200)    NOT NULL DEFAULT '',
            charity_number      VARCHAR(50)     NOT NULL DEFAULT '',
            charity_key         VARCHAR(50)     NOT NULL DEFAULT '',
            town                VARCHAR(100)    NOT NULL DEFAULT '',
            postcode            VARCHAR(10)     NOT NULL DEFAULT '',
            website_url         VARCHAR(500)    NOT NULL DEFAULT '',
            status              VARCHAR(20)     NOT NULL DEFAULT 'active',
            merged_into_id      BIGINT UNSIGNED          DEFAULT NULL,
            merge_reason        TEXT                     DEFAULT NULL,
            merged_at           DATETIME                 DEFAULT NULL,
            merged_by_user_id   BIGINT UNSIGNED          DEFAULT NULL,
            row_version         INT UNSIGNED    NOT NULL DEFAULT 1,
            created_at          DATETIME        NOT NULL,
            created_by_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at          DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_name_key (name_key(100)),
            KEY idx_charity_key (charity_key),
            KEY idx_status (status),
            KEY idx_merged_into (merged_into_id)
        ) {$charset};";

        dbDelta( $sql );
    }
}
