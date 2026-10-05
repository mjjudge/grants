<?php

namespace Rotary\Grants\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The reporting year used to group payments by date. It starts in the month
 * set in Settings (1 = calendar year, 7 = Rotary year July–June, …) and is
 * identified by the calendar year it starts in.
 */
final class ReportingYear {

    /** Reporting year (by its starting calendar year) containing a Y-m-d date. */
    public static function for_date( string $date, int $start_month ): int {
        $y = (int) substr( $date, 0, 4 );
        $m = (int) substr( $date, 5, 2 );
        return ( $start_month === 1 || $m >= $start_month ) ? $y : $y - 1;
    }

    /** @return array{0:string, 1:string} [first day, first day of the next year) as Y-m-d */
    public static function bounds( int $year, int $start_month ): array {
        return [
            sprintf( '%04d-%02d-01', $year, $start_month ),
            sprintf( '%04d-%02d-01', $year + 1, $start_month ),
        ];
    }

    /** "2027" for a calendar year; "2026–27" otherwise. */
    public static function label( int $year, int $start_month ): string {
        return $start_month === 1 ? (string) $year : sprintf( '%d–%02d', $year, ( $year + 1 ) % 100 );
    }

    public static function current( int $start_month ): int {
        return self::for_date( wp_date( 'Y-m-d' ), $start_month );
    }
}
