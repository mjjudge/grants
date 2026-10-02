<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.5.0 — grants_notifications table.
 *
 * One row per message to one recipient (applicant acknowledgement, or one
 * staff "new application" notice per configured recipient). command_key is
 * UNIQUE so the same message can never be queued twice. No message body is
 * stored — it is rebuilt from the immutable application snapshot at send
 * time. "sent" means wp_mail() accepted it, not that it was delivered.
 *
 * claimed_until stops cron and the post-submission send from both sending
 * the same row; a crashed send becomes claimable again once it passes.
 * All times UTC.
 */
class CreateNotificationsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_notifications';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            application_id   BIGINT UNSIGNED  NOT NULL,
            kind             VARCHAR(40)      NOT NULL,
            recipient        VARCHAR(254)     NOT NULL,
            status           VARCHAR(20)      NOT NULL DEFAULT 'pending',
            attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at  DATETIME         NOT NULL,
            claimed_until    DATETIME                  DEFAULT NULL,
            last_attempt_at  DATETIME                  DEFAULT NULL,
            last_error_code  VARCHAR(64)      NOT NULL DEFAULT '',
            command_key      VARCHAR(100)     NOT NULL,
            created_at       DATETIME         NOT NULL,
            sent_at          DATETIME                  DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_command_key (command_key),
            KEY idx_status_next (status, next_attempt_at),
            KEY idx_application (application_id)
        ) {$charset};";

        dbDelta( $sql );
    }
}
