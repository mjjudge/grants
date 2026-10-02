<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.6.0 — grants_contacts table.
 *
 * People who act for an organisation, over time. A change of person is a
 * new contact (the old one gets active_until), not an overwrite. There is
 * deliberately no unique email: one address can belong to contacts at
 * several organisations, and email is never used to match organisations.
 * Corrections to a contact are recorded in grants_amendments.
 */
class CreateContactsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_contacts';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            organisation_id        BIGINT UNSIGNED NOT NULL,
            name                   VARCHAR(150)    NOT NULL DEFAULT '',
            role                   VARCHAR(150)    NOT NULL DEFAULT '',
            email                  VARCHAR(254)    NOT NULL DEFAULT '',
            phone                  VARCHAR(50)     NOT NULL DEFAULT '',
            active_from            DATETIME        NOT NULL,
            active_until           DATETIME                 DEFAULT NULL,
            source                 VARCHAR(30)     NOT NULL DEFAULT 'staff',
            source_application_id  BIGINT UNSIGNED          DEFAULT NULL,
            row_version            INT UNSIGNED    NOT NULL DEFAULT 1,
            created_at             DATETIME        NOT NULL,
            created_by_user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at             DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_organisation (organisation_id, active_until),
            KEY idx_email (email(100))
        ) {$charset};";

        dbDelta( $sql );
    }
}
