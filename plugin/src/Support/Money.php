<?php

namespace Rotary\Grants\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Exact GBP parsing and formatting. Money is always integer pence — no float
 * ever touches an amount, here or anywhere else.
 */
final class Money {

    /** Largest accepted amount: £9,999,999,999.99 — well inside signed BIGINT. */
    private const MAX_POUNDS_DIGITS = 10;

    /**
     * Parse a user-entered GBP amount into pence.
     *
     * Accepts "1000", "1,000", "1000.5", "£1,000.50", surrounding spaces.
     * Rejects empty input, negatives, more than two decimal places, exponents,
     * misplaced thousands separators, and anything else that is not a plain
     * positive-or-zero amount. Zero is returned as 0; callers decide whether
     * zero is acceptable for their field.
     */
    public static function parse_gbp( string $input ): int|\WP_Error {
        $s = trim( $input );
        $s = preg_replace( '/^£\s*/u', '', $s );

        if ( $s === '' ) {
            return new \WP_Error( 'money_empty', __( 'Enter an amount.', 'rotary-grants' ) );
        }

        // Thousands separators are optional but, if used, must be in the right places.
        if ( ! preg_match( '/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?$/', $s, $m ) ) {
            if ( preg_match( '/^[\d,]*\.\d{3,}$/', $s ) ) {
                return new \WP_Error( 'money_precision', __( 'Enter an amount with no more than two decimal places.', 'rotary-grants' ) );
            }
            return new \WP_Error( 'money_invalid', __( 'Enter an amount in pounds, for example 1500 or 1,500.00.', 'rotary-grants' ) );
        }

        $pounds = ltrim( str_replace( ',', '', $m[1] ), '0' );
        if ( strlen( $pounds ) > self::MAX_POUNDS_DIGITS ) {
            return new \WP_Error( 'money_too_large', __( 'That amount is too large.', 'rotary-grants' ) );
        }
        $pence = str_pad( $m[2] ?? '', 2, '0' );

        return (int) ( $pounds === '' ? '0' : $pounds ) * 100 + (int) $pence;
    }

    /**
     * Format pence as "£1,234.56" (or "-£1,234.56"). Integer arithmetic only.
     */
    public static function format_gbp( int $pence ): string {
        $sign   = $pence < 0 ? '-' : '';
        $abs    = abs( $pence );
        $pounds = intdiv( $abs, 100 );
        $rem    = $abs % 100;
        return $sign . '£' . number_format( $pounds ) . '.' . str_pad( (string) $rem, 2, '0', STR_PAD_LEFT );
    }

    /**
     * Pence as an unformatted input value, e.g. 150050 → "1500.50", for
     * re-populating a form field.
     */
    public static function to_input( ?int $pence ): string {
        if ( $pence === null ) {
            return '';
        }
        return intdiv( $pence, 100 ) . '.' . str_pad( (string) ( abs( $pence ) % 100 ), 2, '0', STR_PAD_LEFT );
    }
}
