<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Durable, idempotent creation of applications, and reads for admin screens.
 *
 * submit() runs one InnoDB transaction: insert the grants_submissions row
 * (UNIQUE key_hash), insert the application, link them, commit. A second
 * request with the same submission key blocks on the unique key until the
 * first commits, then fails the insert and is answered from the committed
 * row — so double-clicks and retries yield one application and the same
 * reference. A key reused with different answers is refused.
 */
class ApplicationService {

    /** How long a submission key is remembered (cleanup arrives with G11). */
    private const SUBMISSION_TTL_DAYS = 30;

    /** Unambiguous characters for public references (no I, L, O, U). */
    private const REF_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const REF_ATTEMPTS = 5;

    /**
     * @param array<string,mixed> $clean Output of ApplicationForm::validate() with no errors.
     * @return array{application_id:int, reference:string, replayed:bool}|\WP_Error
     *         Error codes: round_not_accepting, key_reused, db_error.
     */
    public function submit( object $round, array $clean, string $submission_key ): array|\WP_Error {
        // Server time is the only clock that matters — a page loaded before
        // the deadline cannot submit after it.
        if ( ! ( new RoundService() )->is_accepting( $round ) ) {
            return new \WP_Error( 'round_not_accepting', __( 'Sorry — this funding round is not accepting applications at the moment.', 'rotary-grants' ) );
        }

        global $wpdb;
        $key_hash     = hash( 'sha256', $submission_key );
        $payload_hash = ApplicationForm::payload_hash( $clean, (int) $round->id );
        $now          = SiteTime::now_utc();
        $subs         = $wpdb->prefix . 'grants_submissions';
        $apps         = $wpdb->prefix . 'grants_applications';

        $wpdb->query( 'START TRANSACTION' );
        // Duplicate-key failures below are expected (retries, reference
        // collisions) and handled; don't let wpdb print them on a debug site.
        $suppressed = $wpdb->suppress_errors( true );

        $claimed = $wpdb->insert(
            $subs,
            [
                'key_hash'     => $key_hash,
                'payload_hash' => $payload_hash,
                'created_at'   => $now,
                'expires_at'   => gmdate( SiteTime::DB_FORMAT, time() + self::SUBMISSION_TTL_DAYS * DAY_IN_SECONDS ),
            ],
            [ '%s', '%s', '%s', '%s' ]
        );

        if ( $claimed === false ) {
            $wpdb->query( 'ROLLBACK' );
            $wpdb->suppress_errors( $suppressed );
            return $this->replay( $key_hash, $payload_hash );
        }
        $submission_id = (int) $wpdb->insert_id;

        $snapshot = wp_json_encode( ApplicationForm::snapshot( $clean, $round, $now ) );
        $app_id   = 0;
        $ref      = '';
        for ( $i = 0; $i < self::REF_ATTEMPTS && ! $app_id; $i++ ) {
            $ref = self::new_reference();
            $ok  = $wpdb->insert(
                $apps,
                [
                    'round_id'             => (int) $round->id,
                    'public_reference'     => $ref,
                    'status'               => ApplicationStatus::RECEIVED,
                    'organisation_name'    => $clean['organisation_name'],
                    'organisation_town'    => $clean['organisation_town'],
                    'requested_pence'      => $clean['requested_pence'],
                    'answer_snapshot_json' => $snapshot,
                    'form_version'         => ApplicationForm::FORM_VERSION,
                    'policy_version'       => (string) $round->policy_version,
                    'source'               => 'public_form',
                    'submitted_at'         => $now,
                    'row_version'          => 1,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
            );
            if ( $ok !== false ) {
                $app_id = (int) $wpdb->insert_id;
            } elseif ( ! str_contains( (string) $wpdb->last_error, 'Duplicate' ) ) {
                break; // A real failure, not a reference collision.
            }
        }

        $wpdb->suppress_errors( $suppressed );

        if ( ! $app_id
            || $wpdb->update( $subs, [ 'application_id' => $app_id ], [ 'id' => $submission_id ], [ '%d' ], [ '%d' ] ) === false
            || $wpdb->query( 'COMMIT' ) === false
        ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'db_error', __( 'Sorry — your application could not be saved. Nothing has been submitted; please try again.', 'rotary-grants' ) );
        }

        AuditLogger::record( 'application_submitted', 'application', $app_id, [
            'reference' => $ref,
            'round_id'  => (int) $round->id,
            'source'    => 'public_form',
        ] );

        return [ 'application_id' => $app_id, 'reference' => $ref, 'replayed' => false ];
    }

    public function find( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}grants_applications WHERE id = %d", $id ) );
        return $row ? self::hydrate( $row ) : null;
    }

    /**
     * @return object[] Newest first. Snapshot JSON is not decoded here.
     */
    public function list( ?int $round_id = null, ?string $status = null ): array {
        global $wpdb;
        $where  = [];
        $params = [];
        if ( $round_id ) {
            $where[]  = 'a.round_id = %d';
            $params[] = $round_id;
        }
        if ( $status !== null && in_array( $status, ApplicationStatus::all(), true ) ) {
            $where[]  = 'a.status = %s';
            $params[] = $status;
        }
        $sql = "SELECT a.id, a.round_id, a.public_reference, a.status, a.organisation_name, a.organisation_town,
                       a.requested_pence, a.source, a.submitted_at, r.label AS round_label, r.fund_name
                FROM {$wpdb->prefix}grants_applications a
                LEFT JOIN {$wpdb->prefix}grants_rounds r ON r.id = a.round_id"
            . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' )
            . ' ORDER BY a.submitted_at DESC, a.id DESC';
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — placeholders built above
        return array_map( [ self::class, 'hydrate' ], (array) $rows );
    }

    // =========================================================================

    /**
     * Answer a request whose submission key was already used.
     *
     * @return array{application_id:int, reference:string, replayed:bool}|\WP_Error
     */
    private function replay( string $key_hash, string $payload_hash ): array|\WP_Error {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.payload_hash, s.application_id, a.public_reference
             FROM {$wpdb->prefix}grants_submissions s
             LEFT JOIN {$wpdb->prefix}grants_applications a ON a.id = s.application_id
             WHERE s.key_hash = %s",
            $key_hash
        ) );

        if ( ! $row || ! $row->application_id ) {
            // Not a duplicate after all (the insert failed for another reason).
            return new \WP_Error( 'db_error', __( 'Sorry — your application could not be saved. Nothing has been submitted; please try again.', 'rotary-grants' ) );
        }
        if ( ! hash_equals( $row->payload_hash, $payload_hash ) ) {
            return new \WP_Error(
                'key_reused',
                sprintf(
                    /* translators: %s: application reference */
                    __( 'This form has already been used to submit application %s. To send a different application, reload the page to get a fresh form.', 'rotary-grants' ),
                    $row->public_reference
                ),
                [ 'reference' => $row->public_reference ]
            );
        }

        return [ 'application_id' => (int) $row->application_id, 'reference' => (string) $row->public_reference, 'replayed' => true ];
    }

    /** Non-sequential public reference, e.g. "RG-7KQ2-M9XD". */
    private static function new_reference(): string {
        $chars = '';
        for ( $i = 0; $i < 8; $i++ ) {
            $chars .= self::REF_ALPHABET[ random_int( 0, 31 ) ];
        }
        return 'RG-' . substr( $chars, 0, 4 ) . '-' . substr( $chars, 4 );
    }

    private static function hydrate( object $row ): object {
        $row->id              = (int) $row->id;
        $row->round_id        = (int) $row->round_id;
        $row->requested_pence = $row->requested_pence === null ? null : (int) $row->requested_pence;
        if ( isset( $row->row_version ) ) {
            $row->row_version = (int) $row->row_version;
        }
        return $row;
    }
}
