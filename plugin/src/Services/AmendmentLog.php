<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Writes dated before/after corrections to grants_amendments. Callers have
 * already checked capability. Old personal values belong here (where G11
 * retention can anonymise them), never in the audit log.
 */
final class AmendmentLog {

    /**
     * @param array<string, array{0:mixed, 1:mixed}> $changes field => [old, new]
     */
    public static function record( string $entity_type, int $entity_id, array $changes, string $reason ): void {
        if ( ! $changes ) {
            return;
        }
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'grants_amendments',
            [
                'entity_type'   => $entity_type,
                'entity_id'     => $entity_id,
                'changes_json'  => wp_json_encode( $changes ),
                'reason'        => $reason,
                'actor_user_id' => get_current_user_id(),
                'created_at'    => SiteTime::now_utc(),
            ],
            [ '%s', '%d', '%s', '%s', '%d', '%s' ]
        );
    }

    /**
     * @return object[] Newest first, changes_json decoded into ->changes.
     */
    public static function for_entity( string $entity_type, int $entity_id ): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}grants_amendments WHERE entity_type = %s AND entity_id = %d ORDER BY id DESC",
            $entity_type,
            $entity_id
        ) );
        foreach ( $rows as $row ) {
            $row->changes = json_decode( (string) $row->changes_json, true ) ?: [];
        }
        return $rows;
    }
}
