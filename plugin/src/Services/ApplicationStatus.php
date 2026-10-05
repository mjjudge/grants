<?php

namespace Rotary\Grants\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Application states. Do not add a state without the project owner's
 * agreement (CLAUDE.md). docs/03: Received → Under review → More
 * information requested → Under review → Decided; withdrawal from any
 * open state. Entering 'decided' (and reopening) belongs to the decision
 * workflow (G07), not to manual status changes.
 */
final class ApplicationStatus {

    public const RECEIVED            = 'received';
    public const UNDER_REVIEW        = 'under_review';
    public const MORE_INFO_REQUESTED = 'more_info_requested';
    public const DECIDED             = 'decided';
    public const WITHDRAWN           = 'withdrawn';

    /** @var array<string, string[]> manual transitions: from => allowed targets */
    private const TRANSITIONS = [
        self::RECEIVED            => [ self::UNDER_REVIEW, self::MORE_INFO_REQUESTED, self::WITHDRAWN ],
        self::UNDER_REVIEW        => [ self::MORE_INFO_REQUESTED, self::WITHDRAWN ],
        self::MORE_INFO_REQUESTED => [ self::UNDER_REVIEW, self::WITHDRAWN ],
        self::DECIDED             => [],
        self::WITHDRAWN           => [],
    ];

    public static function can_transition( string $from, string $to ): bool {
        return in_array( $to, self::TRANSITIONS[ $from ] ?? [], true );
    }

    /** @return string[] */
    public static function targets( string $from ): array {
        return self::TRANSITIONS[ $from ] ?? [];
    }

    /** Statuses in which the committee is still working on an application. */
    public static function is_open( string $status ): bool {
        return in_array( $status, [ self::RECEIVED, self::UNDER_REVIEW, self::MORE_INFO_REQUESTED ], true );
    }

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
