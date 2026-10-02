<?php

namespace Rotary\Grants\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Scores how likely an organisation record is to be the same body as an
 * applicant, to SUGGEST candidates to staff. It never decides anything.
 *
 * Signals: normalised name (exact / similar / shared words), charity number,
 * postcode, town. Email address is deliberately not a signal — two
 * organisations can share one contact email (docs/02, docs/06).
 */
final class NameMatcher {

    /** Words that don't distinguish one organisation from another. */
    private const NOISE = [ 'the', 'ltd', 'limited', 'inc', 'cio', 'cic', 'plc', 'llp', 'of', 'and' ];

    /** Minimum score for a record to be suggested at all. */
    public const THRESHOLD = 40;

    /** Lower-case, accent-free, punctuation-free, noise-word-free key. */
    public static function name_key( string $name ): string {
        $s = strtolower( remove_accents( $name ) );
        $s = str_replace( [ '&', '+' ], ' and ', $s );
        $s = str_replace( [ "'", '’' ], '', $s ); // "St Mary's" → "st marys"
        $s = (string) preg_replace( '/[^a-z0-9]+/', ' ', $s );
        $s = (string) preg_replace( '/\bst\b/', 'saint', $s );
        $words = array_values( array_filter( explode( ' ', $s ), static fn( $w ) => $w !== '' && ! in_array( $w, self::NOISE, true ) ) );
        // Light stemming so "Scouts" matches "Scout": drop a plural s (not "ss").
        $words = array_map( static fn( $w ) => strlen( $w ) > 3 && str_ends_with( $w, 's' ) && ! str_ends_with( $w, 'ss' ) ? substr( $w, 0, -1 ) : $w, $words );
        return implode( ' ', $words );
    }

    /** Charity numbers compared without spaces, dashes or case. */
    public static function charity_key( string $number ): string {
        return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $number ) );
    }

    public static function postcode_key( string $postcode ): string {
        return strtoupper( (string) preg_replace( '/\s+/', '', $postcode ) );
    }

    /**
     * @param array{name:string, charity_number?:string, postcode?:string, town?:string} $applicant
     * @param array{name:string, charity_number?:string, postcode?:string, town?:string} $record
     * @return array{score:int, reasons:string[]}
     */
    public static function score( array $applicant, array $record ): array {
        $score   = 0;
        $reasons = [];

        $a = self::name_key( $applicant['name'] );
        $b = self::name_key( $record['name'] );
        if ( $a !== '' && $a === $b ) {
            $score    += 60;
            $reasons[] = __( 'same name', 'rotary-grants' );
        } elseif ( $a !== '' && $b !== '' ) {
            similar_text( $a, $b, $pct );
            $wa      = array_unique( explode( ' ', $a ) );
            $wb      = array_unique( explode( ' ', $b ) );
            $shared  = count( array_intersect( $wa, $wb ) );
            $jaccard = $shared / max( 1, count( array_unique( array_merge( $wa, $wb ) ) ) );
            $name    = (int) round( max( $pct, $jaccard * 100 ) * 0.6 );
            if ( $name >= 25 ) {
                $score    += $name;
                /* translators: %d: similarity percentage */
                $reasons[] = sprintf( __( 'similar name (%d%%)', 'rotary-grants' ), (int) round( max( $pct, $jaccard * 100 ) ) );
            }
        }

        $ca = self::charity_key( (string) ( $applicant['charity_number'] ?? '' ) );
        $cb = self::charity_key( (string) ( $record['charity_number'] ?? '' ) );
        if ( $ca !== '' && $ca === $cb ) {
            $score    += 50;
            $reasons[] = __( 'same charity number', 'rotary-grants' );
        } elseif ( $ca !== '' && $cb !== '' ) {
            $score    -= 20;
            $reasons[] = __( 'different charity number', 'rotary-grants' );
        }

        $pa = self::postcode_key( (string) ( $applicant['postcode'] ?? '' ) );
        $pb = self::postcode_key( (string) ( $record['postcode'] ?? '' ) );
        if ( $pa !== '' && $pa === $pb ) {
            $score    += 15;
            $reasons[] = __( 'same postcode', 'rotary-grants' );
        } elseif ( strcasecmp( trim( (string) ( $applicant['town'] ?? '' ) ), trim( (string) ( $record['town'] ?? '' ) ) ) === 0 && trim( (string) ( $applicant['town'] ?? '' ) ) !== '' ) {
            $score    += 5;
            $reasons[] = __( 'same town', 'rotary-grants' );
        }

        return [ 'score' => max( 0, min( 100, $score ) ), 'reasons' => $reasons ];
    }
}
