<?php

namespace Rotary\Grants\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Conversion between the site's configured timezone (what staff and
 * applicants see and type) and UTC (what is stored).
 */
final class SiteTime {

    /** Format used by <input type="datetime-local">. */
    public const INPUT_FORMAT = 'Y-m-d\TH:i';

    /** MySQL DATETIME format, always UTC in this plugin's tables. */
    public const DB_FORMAT = 'Y-m-d H:i:s';

    /**
     * Convert a local "YYYY-MM-DDTHH:MM" (site timezone) to a UTC DB string.
     *
     * Rejects malformed input, wall-clock times that do not exist in the
     * site timezone (the hour skipped when clocks go forward), and times that
     * occur twice (the hour repeated when clocks go back) — rather than
     * letting PHP silently pick one, which would move a deadline by an hour.
     */
    public static function local_input_to_utc( string $local ): string|\WP_Error {
        $local = trim( $local );
        $tz    = wp_timezone();
        $dt    = \DateTimeImmutable::createFromFormat( '!' . self::INPUT_FORMAT, $local, $tz );
        $errs  = \DateTimeImmutable::getLastErrors();

        if ( ! $dt || ( $errs && ( $errs['warning_count'] || $errs['error_count'] ) ) ) {
            return new \WP_Error( 'date_invalid', __( 'Enter a valid date and time.', 'rotary-grants' ) );
        }
        if ( $dt->format( self::INPUT_FORMAT ) !== $local ) {
            return new \WP_Error( 'date_nonexistent', __( 'That time does not exist in the site timezone (the clocks change then). Choose a different time.', 'rotary-grants' ) );
        }

        // The same wall-clock time an hour either side in UTC → it occurs twice.
        foreach ( [ -3600, 3600 ] as $shift ) {
            if ( $dt->setTimestamp( $dt->getTimestamp() + $shift )->format( self::INPUT_FORMAT ) === $local ) {
                return new \WP_Error( 'date_ambiguous', __( 'That time happens twice in the site timezone (the clocks go back then). Choose a different time.', 'rotary-grants' ) );
            }
        }

        return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::DB_FORMAT );
    }

    /** UTC DB string → local "YYYY-MM-DDTHH:MM" for a form field ('' for null). */
    public static function utc_to_local_input( ?string $utc ): string {
        $dt = self::from_utc( $utc );
        return $dt ? $dt->setTimezone( wp_timezone() )->format( self::INPUT_FORMAT ) : '';
    }

    /** UTC DB string → human-readable local date and time ('' for null). */
    public static function display( ?string $utc ): string {
        $dt = self::from_utc( $utc );
        if ( ! $dt ) {
            return '';
        }
        return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dt->getTimestamp() );
    }

    /** Current server time as a UTC DB string. */
    public static function now_utc(): string {
        return gmdate( self::DB_FORMAT );
    }

    private static function from_utc( ?string $utc ): ?\DateTimeImmutable {
        if ( $utc === null || $utc === '' ) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat( self::DB_FORMAT, $utc, new \DateTimeZone( 'UTC' ) );
        return $dt ?: null;
    }
}
