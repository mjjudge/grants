<?php

namespace Rotary\Grants\Database\Migrations;

use Rotary\Grants\Database\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * 0.9.0 — grants_payments table: the payment ledger.
 *
 * Append-only record of payments the treasurer has ALREADY made outside
 * WordPress. This plugin never initiates a transfer and stores no bank
 * details (CLAUDE.md).
 *
 *   entry_type          payment | reversal
 *   amount_pence        always positive; a reversal subtracts
 *   paid_on             actual date of the payment / correction (local date)
 *   method              bank_transfer | cheque | other (+ method_note)
 *   reversed_payment_id the payment a reversal corrects
 *   refund_received     a reversal is a ledger correction; this records
 *                       separately whether money actually came back
 *   command_key         SHA-256 of the form's one-time key: UNIQUE, so a
 *                       repeated submission is recorded once
 * created_at is the real entry time even when paid_on is in the past.
 */
class CreatePaymentsTable implements MigrationInterface {

    public function up(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'grants_payments';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            award_id             BIGINT UNSIGNED NOT NULL,
            entry_type           VARCHAR(10)     NOT NULL,
            amount_pence         BIGINT          NOT NULL,
            paid_on              DATE            NOT NULL,
            method               VARCHAR(20)     NOT NULL DEFAULT '',
            method_note          VARCHAR(255)    NOT NULL DEFAULT '',
            reference            VARCHAR(100)    NOT NULL DEFAULT '',
            note                 TEXT                     DEFAULT NULL,
            reversed_payment_id  BIGINT UNSIGNED          DEFAULT NULL,
            refund_received      TINYINT(1)      NOT NULL DEFAULT 0,
            command_key          CHAR(64)        NOT NULL,
            actor_user_id        BIGINT UNSIGNED NOT NULL,
            created_at           DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_command_key (command_key),
            KEY idx_award (award_id),
            KEY idx_paid_on (paid_on),
            KEY idx_reversed (reversed_payment_id)
        ) {$charset};";

        dbDelta( $sql );
    }
}
