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

    public const SOURCE_PUBLIC = 'public_form';

    /** @return array<string,string> staff entry source => label */
    public static function staff_sources(): array {
        return [
            'staff_paper' => __( 'Paper form', 'rotary-grants' ),
            'staff_email' => __( 'Email', 'rotary-grants' ),
            'staff_phone' => __( 'Phone', 'rotary-grants' ),
            'staff_other' => __( 'Other (explain in the note)', 'rotary-grants' ),
        ];
    }

    public static function source_label( string $source ): string {
        return $source === self::SOURCE_PUBLIC
            ? __( 'Online form', 'rotary-grants' )
            /* translators: %s: how received, e.g. Paper form */
            : sprintf( __( 'Entered by staff — %s', 'rotary-grants' ), self::staff_sources()[ $source ] ?? $source );
    }

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

        return $this->persist( $round, $clean, $submission_key, [
            'source'       => self::SOURCE_PUBLIC,
            'submitted_at' => SiteTime::now_utc(),
        ] );
    }

    /**
     * Staff key in an application received on paper, by email or by phone
     * (grants_manage_organisations). The source is recorded and shown
     * everywhere; "date received" may be in the past but not the future; an
     * application received outside the round's window is flagged late and
     * needs a reason (docs/03). The applicant is emailed an acknowledgement
     * only if staff ask; staff recipients are not notified (staff entered it).
     *
     * @param array<string,string> $raw  Form values (ApplicationForm field names).
     * @param array{source:string, received:string, reason:string, send_ack:bool} $meta
     *        received = local "YYYY-MM-DD" in the site timezone.
     * @return array{application_id:int, reference:string, replayed:bool, late:bool}|\WP_Error
     *         WP_Error codes are field names (incl. staff_source, staff_received, staff_reason).
     */
    public function create_staff_entry( object $round, array $raw, array $meta, string $submission_key ): array|\WP_Error {
        if ( ! current_user_can( OrganisationService::CAPABILITY ) ) {
            return OrganisationService::forbidden();
        }
        [ $clean, $errors ] = ApplicationForm::validate( $raw, $round, ApplicationForm::MODE_STAFF );

        $source = (string) ( $meta['source'] ?? '' );
        if ( ! isset( self::staff_sources()[ $source ] ) ) {
            $errors->add( 'staff_source', __( 'Choose how the application was received.', 'rotary-grants' ) );
        }

        $received = trim( (string) ( $meta['received'] ?? '' ) );
        $received_utc = null;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $received ) ) {
            $errors->add( 'staff_received', __( 'Enter the date the application was received.', 'rotary-grants' ) );
        } else {
            // Midday local time on the received date: unambiguous, and the
            // whole day is compared against the round window below.
            $utc = SiteTime::local_input_to_utc( $received . 'T12:00' );
            if ( is_wp_error( $utc ) ) {
                $errors->add( 'staff_received', __( 'Enter a valid date.', 'rotary-grants' ) );
            } elseif ( $received > wp_date( 'Y-m-d' ) ) {
                $errors->add( 'staff_received', __( 'The date received cannot be in the future.', 'rotary-grants' ) );
            } else {
                $received_utc = $utc;
            }
        }

        // Late = received on a day wholly outside the round's window. A paper
        // form dated the closing day itself gets the benefit of the doubt
        // (no time of day is known), as does one dated the opening day.
        $late = false;
        if ( $received_utc !== null ) {
            $day_start = SiteTime::local_input_to_utc( $received . 'T00:00' );
            $day_start = is_wp_error( $day_start ) ? $received_utc : $day_start;
            $opens_on  = $round->opens_at ? wp_date( 'Y-m-d', strtotime( $round->opens_at . ' UTC' ) ) : null;
            $late      = ( $round->closes_at && $day_start >= $round->closes_at ) || ( $opens_on && $received < $opens_on );
        }

        $reason = trim( sanitize_textarea_field( (string) ( $meta['reason'] ?? '' ) ) );
        if ( $late && $reason === '' ) {
            $errors->add( 'staff_reason', __( 'This application was received outside the round\'s opening and closing dates. Give the reason it is being accepted.', 'rotary-grants' ) );
        }
        if ( mb_strlen( $reason ) > 2000 ) {
            $errors->add( 'staff_reason', __( 'Please shorten the reason to 2000 characters or fewer.', 'rotary-grants' ) );
        }
        if ( $round->status === RoundStatus::ARCHIVED || $round->status === RoundStatus::DRAFT ) {
            $errors->add( 'staff_round', __( 'Applications can only be entered for open or closed rounds.', 'rotary-grants' ) );
        }

        if ( $errors->has_errors() ) {
            return $errors;
        }

        $result = $this->persist( $round, $clean, $submission_key, [
            'source'             => $source,
            'submitted_at'       => $received_utc,
            'entered_by_user_id' => get_current_user_id(),
            'entry_reason'       => $reason,
            'is_late'            => $late,
            'notify'             => ! empty( $meta['send_ack'] ) ? [ NotificationService::KIND_ACK ] : [],
        ] );
        return is_wp_error( $result ) ? $result : $result + [ 'late' => $late ];
    }

    /**
     * Shared idempotent insert for public and staff applications.
     *
     * @param array{source:string, submitted_at:string, entered_by_user_id?:int, entry_reason?:string, is_late?:bool, notify?:string[]} $entry
     * @return array{application_id:int, reference:string, replayed:bool}|\WP_Error
     */
    private function persist( object $round, array $clean, string $submission_key, array $entry ): array|\WP_Error {
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

        $snap = ApplicationForm::snapshot( $clean, $round, $entry['submitted_at'] );
        $snap['source'] = $entry['source'];
        if ( $entry['source'] !== self::SOURCE_PUBLIC ) {
            $snap['staff_entry'] = [
                'entered_by_user_id' => (int) ( $entry['entered_by_user_id'] ?? 0 ),
                'entered_at'         => $now,
                'reason'             => (string) ( $entry['entry_reason'] ?? '' ),
                'late'               => ! empty( $entry['is_late'] ),
            ];
        }
        $snapshot = wp_json_encode( $snap );
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
                    'source'               => $entry['source'],
                    'entered_by_user_id'   => $entry['entered_by_user_id'] ?? null,
                    'entry_reason'         => $entry['entry_reason'] ?? null,
                    'is_late'              => ! empty( $entry['is_late'] ) ? 1 : 0,
                    'submitted_at'         => $entry['submitted_at'],
                    'row_version'          => 1,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s' ]
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

        AuditLogger::record( $entry['source'] === self::SOURCE_PUBLIC ? 'application_submitted' : 'application_entered_by_staff', 'application', $app_id, array_filter( [
            'reference' => $ref,
            'round_id'  => (int) $round->id,
            'source'    => $entry['source'],
            'late'      => ! empty( $entry['is_late'] ) ? true : null,
        ], static fn( $v ) => $v !== null ) );

        // Only now, after COMMIT, queue the emails. A queueing problem must
        // never undo or duplicate the saved application.
        try {
            ( new NotificationService() )->queue_for_application( $app_id, $entry['notify'] ?? null );
        } catch ( \Throwable $e ) {
            error_log( 'Rotary Grants: could not queue notifications for application ' . $app_id . ': ' . get_class( $e ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        }

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
    /**
     * @param array{min_pence?: int|null, max_pence?: int|null, town?: string, hide_duplicates?: bool} $filters
     */
    public function list( ?int $round_id = null, ?string $status = null, ?int $organisation_id = null, array $filters = [] ): array {
        global $wpdb;
        $where  = [];
        $params = [];
        if ( isset( $filters['min_pence'] ) && $filters['min_pence'] !== null ) {
            $where[]  = 'a.requested_pence >= %d';
            $params[] = (int) $filters['min_pence'];
        }
        if ( isset( $filters['max_pence'] ) && $filters['max_pence'] !== null ) {
            $where[]  = 'a.requested_pence <= %d';
            $params[] = (int) $filters['max_pence'];
        }
        if ( trim( (string) ( $filters['town'] ?? '' ) ) !== '' ) {
            $where[]  = 'a.organisation_town LIKE %s';
            $params[] = '%' . $wpdb->esc_like( trim( (string) $filters['town'] ) ) . '%';
        }
        if ( ! empty( $filters['hide_duplicates'] ) ) {
            $where[] = 'a.duplicate_of_id IS NULL';
        }
        if ( $organisation_id ) {
            $where[]  = 'a.organisation_id = %d';
            $params[] = $organisation_id;
        }
        if ( $round_id ) {
            $where[]  = 'a.round_id = %d';
            $params[] = $round_id;
        }
        if ( $status !== null && in_array( $status, ApplicationStatus::all(), true ) ) {
            $where[]  = 'a.status = %s';
            $params[] = $status;
        }
        $sql = "SELECT a.id, a.round_id, a.organisation_id, a.public_reference, a.status, a.organisation_name, a.organisation_town,
                       a.requested_pence, a.source, a.is_late, a.duplicate_of_id, a.submitted_at, r.label AS round_label, r.fund_name
                FROM {$wpdb->prefix}grants_applications a
                LEFT JOIN {$wpdb->prefix}grants_rounds r ON r.id = a.round_id"
            . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' )
            . ' ORDER BY a.submitted_at DESC, a.id DESC';
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — placeholders built above
        return array_map( [ self::class, 'hydrate' ], (array) $rows );
    }

    /**
     * Other applications in the same round that look like the same
     * organisation: same linked organisation, or (while unlinked) the same
     * normalised name. Repeat applications are allowed but flagged (docs/02).
     *
     * @return object[]
     */
    public function same_round_siblings( object $application ): array {
        $key = \Rotary\Grants\Support\NameMatcher::name_key( $application->organisation_name );
        return array_values( array_filter(
            $this->list( $application->round_id ),
            static fn( $a ) => $a->id !== $application->id && (
                ( $application->organisation_id && $a->organisation_id === $application->organisation_id )
                || ( $key !== '' && \Rotary\Grants\Support\NameMatcher::name_key( $a->organisation_name ) === $key )
            )
        ) );
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
        $row->organisation_id = empty( $row->organisation_id ) ? null : (int) $row->organisation_id;
        if ( property_exists( $row, 'contact_id' ) ) {
            $row->contact_id = empty( $row->contact_id ) ? null : (int) $row->contact_id;
        }
        if ( property_exists( $row, 'duplicate_of_id' ) ) {
            $row->duplicate_of_id = empty( $row->duplicate_of_id ) ? null : (int) $row->duplicate_of_id;
        }
        if ( isset( $row->is_late ) ) {
            $row->is_late = (bool) (int) $row->is_late;
        }
        $row->requested_pence = $row->requested_pence === null ? null : (int) $row->requested_pence;
        if ( isset( $row->row_version ) ) {
            $row->row_version = (int) $row->row_version;
        }
        return $row;
    }
}
