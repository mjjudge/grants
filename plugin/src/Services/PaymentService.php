<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * The payment ledger (docs/02 "Financial semantics", docs/03 "Payment
 * recording").
 *
 * The treasurer pays outside WordPress, through the club's independent
 * banking process, and then records it here. Nothing in this plugin
 * initiates a transfer or holds bank details.
 *
 * Rules (all server-side, requires grants_pay):
 *   - only an approved award with a known amount, and only once every
 *     "before payment" condition is met;
 *   - a payment can't exceed what is outstanding (approved − net paid);
 *   - a correction is a reversal entry against the original payment (never
 *     an edit), up to that payment's unreversed amount, with a reason; it
 *     records separately whether money actually came back;
 *   - each form submission carries a one-time key, so repeating it records
 *     nothing new;
 *   - entries on one award are serialised by locking the award row, so
 *     concurrent payments can't overpay.
 *
 * Payments never change "available to award": approval already reserved it.
 */
class PaymentService {

    public const PAYMENT  = 'payment';
    public const REVERSAL = 'reversal';

    public const UNPAID    = 'unpaid';
    public const PART_PAID = 'part_paid';
    public const PAID      = 'paid';

    /** @return array<string,string> method => label */
    public static function methods(): array {
        return [
            'bank_transfer' => __( 'Bank transfer', 'rotary-grants' ),
            'cheque'        => __( 'Cheque', 'rotary-grants' ),
            'other'         => __( 'Other (explain)', 'rotary-grants' ),
        ];
    }

    // =========================================================================
    // Commands
    // =========================================================================

    /**
     * Record a payment already made.
     *
     * @param array{amount?:string, paid_on?:string, method?:string, method_note?:string, reference?:string, note?:string} $input
     * @return array{payment_id:int, replayed:bool}|\WP_Error
     */
    public function record_payment( int $award_id, array $input, string $command_key ): array|\WP_Error {
        if ( ! current_user_can( 'grants_pay' ) ) {
            return self::forbidden();
        }
        $errors = new \WP_Error();
        $amount = $this->amount( (string) ( $input['amount'] ?? '' ), $errors );
        $date   = $this->date( (string) ( $input['paid_on'] ?? '' ), $errors, 'paid_on', __( 'Enter the date the payment was made (not in the future).', 'rotary-grants' ) );
        $method = (string) ( $input['method'] ?? '' );
        if ( ! isset( self::methods()[ $method ] ) ) {
            $errors->add( 'method', __( 'Choose how it was paid.', 'rotary-grants' ) );
        }
        $method_note = trim( sanitize_text_field( (string) ( $input['method_note'] ?? '' ) ) );
        if ( $method === 'other' && $method_note === '' ) {
            $errors->add( 'method_note', __( 'Say how it was paid.', 'rotary-grants' ) );
        }
        $reference = trim( sanitize_text_field( (string) ( $input['reference'] ?? '' ) ) );
        $note      = trim( sanitize_textarea_field( (string) ( $input['note'] ?? '' ) ) );
        $this->lengths( [ 'method_note' => [ $method_note, 255 ], 'reference' => [ $reference, 100 ], 'note' => [ $note, 2000 ] ], $errors );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $command_key ) ) {
            $errors->add( 'command_key', __( 'This form has expired. Reload the page and try again.', 'rotary-grants' ) );
        }
        if ( $errors->has_errors() ) {
            return $errors;
        }

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        $award = $this->lock_award( $award_id );
        if ( ! $award ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'not_found', __( 'Award not found.', 'rotary-grants' ) );
        }

        $hash   = hash( 'sha256', $command_key );
        $replay = $this->replay( $hash, $award_id, self::PAYMENT, $amount, $date );
        if ( $replay !== null ) {
            $wpdb->query( 'ROLLBACK' );
            return $replay;
        }

        if ( $award->status !== AwardService::APPROVED ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'not_approved', __( 'Payments can only be recorded against an approved award.', 'rotary-grants' ) );
        }
        if ( $award->approved_pence === null ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'unknown_amount', __( 'This award\'s approved amount is unknown (historical record). It must be reconciled before payments can be recorded.', 'rotary-grants' ) );
        }
        $open = ( new AwardService() )->open_pre_payment_conditions( $award_id );
        if ( $open > 0 ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'conditions', sprintf(
                /* translators: %d: number of conditions */
                _n( '%d condition must be marked as met before a payment can be recorded.', '%d conditions must be marked as met before a payment can be recorded.', $open, 'rotary-grants' ),
                $open
            ) );
        }
        if ( $award->approved_on && $date < $award->approved_on && $note === '' ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'note', __( 'This payment date is before the award was approved. Add a note explaining it (e.g. a historical record).', 'rotary-grants' ) );
        }
        $outstanding = (int) $award->approved_pence - $this->net_paid( $award_id );
        if ( $amount > $outstanding ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'over_outstanding', sprintf(
                /* translators: %s: outstanding amount */
                __( 'That is more than the %s still outstanding on this award.', 'rotary-grants' ),
                Money::format_gbp( max( 0, $outstanding ) )
            ) );
        }

        $id = $this->insert( [
            'award_id'     => $award_id,
            'entry_type'   => self::PAYMENT,
            'amount_pence' => $amount,
            'paid_on'      => $date,
            'method'       => $method,
            'method_note'  => $method === 'other' ? $method_note : '',
            'reference'    => $reference,
            'note'         => $note !== '' ? $note : null,
            'command_key'  => $hash,
        ] );
        if ( ! $id ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'db_error', __( 'The payment could not be saved. Nothing was recorded.', 'rotary-grants' ) );
        }
        $wpdb->query( 'COMMIT' );

        AuditLogger::record( 'payment_recorded', 'award', $award_id, [ 'payment_id' => $id, 'amount_pence' => $amount, 'method' => $method, 'paid_on' => $date ] );
        return [ 'payment_id' => $id, 'replayed' => false ];
    }

    /**
     * Reverse (part of) a payment — a correction to the website ledger.
     *
     * @param array{amount?:string, paid_on?:string, reason?:string, refund_received?:bool} $input
     * @return array{payment_id:int, replayed:bool}|\WP_Error
     */
    public function record_reversal( int $payment_id, array $input, string $command_key ): array|\WP_Error {
        if ( ! current_user_can( 'grants_pay' ) ) {
            return self::forbidden();
        }
        $errors = new \WP_Error();
        $amount = $this->amount( (string) ( $input['amount'] ?? '' ), $errors );
        $date   = $this->date( (string) ( $input['paid_on'] ?? '' ), $errors, 'paid_on', __( 'Enter the date of the correction (not in the future).', 'rotary-grants' ) );
        $reason = trim( sanitize_textarea_field( (string) ( $input['reason'] ?? '' ) ) );
        if ( $reason === '' ) {
            $errors->add( 'reason', __( 'Say why the payment is being reversed (e.g. "entered twice", "wrong amount — should have been £800").', 'rotary-grants' ) );
        }
        $this->lengths( [ 'reason' => [ $reason, 2000 ] ], $errors );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $command_key ) ) {
            $errors->add( 'command_key', __( 'This form has expired. Reload the page and try again.', 'rotary-grants' ) );
        }
        if ( $errors->has_errors() ) {
            return $errors;
        }

        global $wpdb;
        $original = $this->find( $payment_id );
        if ( ! $original || $original->entry_type !== self::PAYMENT ) {
            return new \WP_Error( 'not_found', __( 'Only a payment entry can be reversed.', 'rotary-grants' ) );
        }

        $wpdb->query( 'START TRANSACTION' );
        $this->lock_award( (int) $original->award_id );
        $hash   = hash( 'sha256', $command_key );
        $replay = $this->replay( $hash, (int) $original->award_id, self::REVERSAL, $amount, $date );
        if ( $replay !== null ) {
            $wpdb->query( 'ROLLBACK' );
            return $replay;
        }
        $unreversed = $this->unreversed( $payment_id );
        if ( $amount > $unreversed ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'over_reversal', sprintf(
                /* translators: %s: amount */
                __( 'At most %s of this payment can still be reversed.', 'rotary-grants' ),
                Money::format_gbp( $unreversed )
            ) );
        }

        $refund = ! empty( $input['refund_received'] );
        $id     = $this->insert( [
            'award_id'            => (int) $original->award_id,
            'entry_type'          => self::REVERSAL,
            'amount_pence'        => $amount,
            'paid_on'             => $date,
            'method'              => '',
            'method_note'         => '',
            'reference'           => '',
            'note'                => $reason,
            'reversed_payment_id' => $payment_id,
            'refund_received'     => $refund ? 1 : 0,
            'command_key'         => $hash,
        ] );
        if ( ! $id ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'db_error', __( 'The reversal could not be saved. Nothing was recorded.', 'rotary-grants' ) );
        }
        $wpdb->query( 'COMMIT' );

        AuditLogger::record( 'payment_reversed', 'award', (int) $original->award_id, [
            'payment_id'      => $id,
            'reverses'        => $payment_id,
            'amount_pence'    => $amount,
            'refund_received' => $refund,
        ] );
        return [ 'payment_id' => $id, 'replayed' => false ];
    }

    // =========================================================================
    // Reads
    // =========================================================================

    public function find( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
        return $row ?: null;
    }

    /**
     * Ledger entries for an award, oldest first, with who recorded each and
     * (for payments) how much is still reversible.
     *
     * @return object[]
     */
    public function ledger( int $award_id ): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, u.display_name AS actor_name,
                    (SELECT COALESCE(SUM(r.amount_pence), 0) FROM {$this->table()} r WHERE r.reversed_payment_id = p.id) AS reversed_pence
             FROM {$this->table()} p LEFT JOIN {$wpdb->users} u ON u.ID = p.actor_user_id
             WHERE p.award_id = %d ORDER BY p.paid_on, p.id",
            $award_id
        ) );
        foreach ( $rows as $r ) {
            $r->amount_pence   = (int) $r->amount_pence;
            $r->reversed_pence = (int) $r->reversed_pence;
            $r->unreversed     = $r->entry_type === self::PAYMENT ? $r->amount_pence - $r->reversed_pence : 0;
        }
        return $rows;
    }

    public function net_paid( int $award_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN entry_type = 'payment' THEN amount_pence ELSE -amount_pence END), 0) FROM {$this->table()} WHERE award_id = %d",
            $award_id
        ) );
    }

    public function unreversed( int $payment_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT p.amount_pence - COALESCE((SELECT SUM(r.amount_pence) FROM {$this->table()} r WHERE r.reversed_payment_id = p.id), 0)
             FROM {$this->table()} p WHERE p.id = %d AND p.entry_type = 'payment'",
            $payment_id
        ) );
    }

    /**
     * @return array{approved:int|null, net_paid:int, outstanding:int|null, progress:string}
     */
    public function totals( object $award ): array {
        $net = $this->net_paid( (int) $award->id );
        $app = $award->approved_pence;
        return [
            'approved'    => $app,
            'net_paid'    => $net,
            'outstanding' => $app === null ? null : ( $award->status === AwardService::APPROVED ? $app - $net : 0 ),
            'progress'    => self::progress( $app, $net ),
        ];
    }

    /** Derived, never stored: unpaid / part_paid / paid. */
    public static function progress( ?int $approved, int $net_paid ): string {
        if ( $net_paid <= 0 ) {
            return self::UNPAID;
        }
        return ( $approved !== null && $net_paid >= $approved ) ? self::PAID : self::PART_PAID;
    }

    public static function progress_label( string $progress ): string {
        return match ( $progress ) {
            self::PAID      => __( 'Paid', 'rotary-grants' ),
            self::PART_PAID => __( 'Part paid', 'rotary-grants' ),
            default         => __( 'Unpaid', 'rotary-grants' ),
        };
    }

    // =========================================================================

    private function lock_award( int $award_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}grants_awards WHERE id = %d FOR UPDATE", $award_id ) );
        if ( ! $row ) {
            return null;
        }
        $row->approved_pence = $row->approved_pence === null ? null : (int) $row->approved_pence;
        return $row;
    }

    /**
     * Same key seen before (inside the award lock): same command → replay;
     * different command → refuse. Null when the key is new.
     *
     * @return array{payment_id:int, replayed:bool}|\WP_Error|null
     */
    private function replay( string $hash, int $award_id, string $type, int $amount, string $date ): array|\WP_Error|null {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, award_id, entry_type, amount_pence, paid_on FROM {$this->table()} WHERE command_key = %s", $hash ) );
        if ( ! $row ) {
            return null;
        }
        if ( (int) $row->award_id === $award_id && $row->entry_type === $type && (int) $row->amount_pence === $amount && $row->paid_on === $date ) {
            return [ 'payment_id' => (int) $row->id, 'replayed' => true ];
        }
        return new \WP_Error( 'key_reused', __( 'This form has already been used to record a different entry. Reload the page to record another.', 'rotary-grants' ) );
    }

    /** Column => format, in the order every row is written. */
    private const COLUMNS = [
        'award_id' => '%d', 'entry_type' => '%s', 'amount_pence' => '%d', 'paid_on' => '%s', 'method' => '%s',
        'method_note' => '%s', 'reference' => '%s', 'note' => '%s', 'reversed_payment_id' => '%d',
        'refund_received' => '%d', 'command_key' => '%s', 'actor_user_id' => '%d', 'created_at' => '%s',
    ];

    /** @param array<string,mixed> $data */
    private function insert( array $data ): int {
        global $wpdb;
        $data += [ 'reversed_payment_id' => null, 'refund_received' => 0, 'actor_user_id' => get_current_user_id(), 'created_at' => SiteTime::now_utc() ];
        $row = [];
        foreach ( array_keys( self::COLUMNS ) as $col ) {
            $row[ $col ] = $data[ $col ] ?? null;
        }
        $ok = $wpdb->insert( $this->table(), $row, array_values( self::COLUMNS ) );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    private function amount( string $raw, \WP_Error $errors ): int {
        $pence = Money::parse_gbp( $raw );
        if ( is_wp_error( $pence ) ) {
            $errors->add( 'amount', $pence->get_error_message() );
            return 0;
        }
        if ( $pence <= 0 ) {
            $errors->add( 'amount', __( 'Enter an amount greater than £0.', 'rotary-grants' ) );
        }
        return $pence;
    }

    private function date( string $raw, \WP_Error $errors, string $code, string $message ): string {
        $raw = trim( $raw );
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || $raw > wp_date( 'Y-m-d' ) ) {
            $errors->add( $code, $message );
        }
        return $raw;
    }

    /** @param array<string, array{0:string, 1:int}> $fields */
    private function lengths( array $fields, \WP_Error $errors ): void {
        foreach ( $fields as $code => [ $value, $max ] ) {
            if ( mb_strlen( $value ) > $max ) {
                /* translators: %d: max characters */
                $errors->add( $code, sprintf( __( 'Please keep this to %d characters or fewer.', 'rotary-grants' ), $max ) );
            }
        }
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'grants_payments';
    }

    private static function forbidden(): \WP_Error {
        return new \WP_Error( 'forbidden', __( 'Only the treasurer (grants_pay) can record payments.', 'rotary-grants' ) );
    }
}
