<?php

namespace Rotary\Grants\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Funding round states and the allowed transitions between them.
 * Do not add a state without the project owner's agreement (CLAUDE.md).
 */
final class RoundStatus {

    public const DRAFT    = 'draft';
    public const OPEN     = 'open';
    public const CLOSED   = 'closed';
    public const ARCHIVED = 'archived';

    /** @var array<string, string[]> from => allowed targets */
    private const TRANSITIONS = [
        self::DRAFT    => [ self::OPEN, self::ARCHIVED ],
        self::OPEN     => [ self::CLOSED ],
        self::CLOSED   => [ self::OPEN, self::ARCHIVED ],
        self::ARCHIVED => [],
    ];

    /** @return string[] */
    public static function all(): array {
        return array_keys( self::TRANSITIONS );
    }

    public static function can_transition( string $from, string $to ): bool {
        return in_array( $to, self::TRANSITIONS[ $from ] ?? [], true );
    }

    /** @return string[] */
    public static function targets( string $from ): array {
        return self::TRANSITIONS[ $from ] ?? [];
    }

    public static function label( string $status ): string {
        return match ( $status ) {
            self::DRAFT    => __( 'Draft', 'rotary-grants' ),
            self::OPEN     => __( 'Open', 'rotary-grants' ),
            self::CLOSED   => __( 'Closed', 'rotary-grants' ),
            self::ARCHIVED => __( 'Archived', 'rotary-grants' ),
            default        => $status,
        };
    }

    /** Verb used on the button that moves a round to $to. */
    public static function action_label( string $from, string $to ): string {
        return match ( $to ) {
            self::OPEN     => $from === self::CLOSED ? __( 'Reopen round', 'rotary-grants' ) : __( 'Open round', 'rotary-grants' ),
            self::CLOSED   => __( 'Close round', 'rotary-grants' ),
            self::ARCHIVED => __( 'Archive round', 'rotary-grants' ),
            default        => $to,
        };
    }
}
