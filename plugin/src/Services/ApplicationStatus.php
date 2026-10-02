<?php

namespace Rotary\Grants\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Application states. Do not add a state without the project owner's
 * agreement (CLAUDE.md). Transitions arrive with review/decision work (G06/G07).
 */
final class ApplicationStatus {

    public const RECEIVED            = 'received';
    public const UNDER_REVIEW        = 'under_review';
    public const MORE_INFO_REQUESTED = 'more_info_requested';
    public const DECIDED             = 'decided';
    public const WITHDRAWN           = 'withdrawn';

    /** @return string[] */
    public static function all(): array {
        return [ self::RECEIVED, self::UNDER_REVIEW, self::MORE_INFO_REQUESTED, self::DECIDED, self::WITHDRAWN ];
    }

    public static function label( string $status ): string {
        return match ( $status ) {
            self::RECEIVED            => __( 'Received', 'rotary-grants' ),
            self::UNDER_REVIEW        => __( 'Under review', 'rotary-grants' ),
            self::MORE_INFO_REQUESTED => __( 'More information requested', 'rotary-grants' ),
            self::DECIDED             => __( 'Decided', 'rotary-grants' ),
            self::WITHDRAWN           => __( 'Withdrawn', 'rotary-grants' ),
            default                   => $status,
        };
    }
}
