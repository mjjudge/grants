<?php

namespace Rotary\Grants\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Writes rows to grants_audit_events.
 *
 * Callers pass a *limited* summary: which fields changed, counts, ids,
 * versions. Never bank details, tokens, full application answers, or
 * personal contact details such as email addresses.
 */
class AuditLogger {

    /** One id per HTTP request, so related events can be grouped. */
    private static ?string $request_id = null;

    /**
     * @param array<string, mixed> $summary
     */
    public static function record( string $action, string $entity_type, ?int $entity_id, array $summary = [] ): bool {
        global $wpdb;

        if ( self::$request_id === null ) {
            self::$request_id = wp_generate_uuid4();
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'grants_audit_events',
            [
                'actor_user_id' => get_current_user_id(),
                'action'        => substr( $action, 0, 64 ),
                'entity_type'   => substr( $entity_type, 0, 64 ),
                'entity_id'     => $entity_id,
                'summary'       => $summary ? wp_json_encode( $summary ) : null,
                'request_id'    => self::$request_id,
                'created_at'    => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s', '%s' ] // wpdb writes a null value as NULL whatever its format
        );

        return $result !== false;
    }
}
