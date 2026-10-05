<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * The committee's work on an application before a decision (docs/03):
 * status changes, internal notes, requests for more information (drafted,
 * then explicitly sent), the applicant's replies recorded as dated addenda,
 * and duplicate classification.
 *
 * Every action requires a "no conflict" declaration on the application
 * (ConflictService::require_clear()) plus the capability noted on each
 * method. Status changes use optimistic concurrency on row_version and are
 * written to the notes record with their reason.
 */
class WorkflowService {

    public const NOTE         = 'note';
    public const INFO_REQUEST = 'info_request';
    public const ADDENDUM     = 'addendum';
    public const STATUS       = 'status';
    public const DECISION_NOTICE = 'decision_notice';

    /** grants_review or grants_manage_organisations (reviewer / coordinator). */
    public static function can_progress(): bool {
        return current_user_can( 'grants_review' ) || current_user_can( 'grants_manage_organisations' );
    }

    // =========================================================================
    // Status
    // =========================================================================

    /**
     * Manual status change. Withdrawal needs grants_manage_organisations and
     * a reason; other moves need can_progress().
     *
     * @return true|\WP_Error
     */
    public function transition( int $application_id, string $to, int $expected_row_version, string $reason ): true|\WP_Error {
        $reason = trim( sanitize_textarea_field( $reason ) );
        if ( $to === ApplicationStatus::WITHDRAWN ) {
            if ( ! current_user_can( 'grants_manage_organisations' ) ) {
                return self::forbidden();
            }
            if ( $reason === '' ) {
                return new \WP_Error( 'reason', __( 'Say why the application is being withdrawn (e.g. "applicant emailed to withdraw, 3 March").', 'rotary-grants' ) );
            }
        } elseif ( ! self::can_progress() ) {
            return self::forbidden();
        }
        $gate = ( new ConflictService() )->require_clear( $application_id );
        if ( $gate ) {
            return $gate;
        }
        $app = ( new ApplicationService() )->find( $application_id );
        if ( ! $app ) {
            return new \WP_Error( 'not_found', __( 'Application not found.', 'rotary-grants' ) );
        }
        if ( $app->row_version !== $expected_row_version ) {
            return OrganisationService::conflict();
        }
        if ( ! ApplicationStatus::can_transition( $app->status, $to ) ) {
            return new \WP_Error( 'bad_transition', sprintf(
                /* translators: 1: current status, 2: requested status */
                __( 'An application cannot move from "%1$s" to "%2$s".', 'rotary-grants' ),
                ApplicationStatus::label( $app->status ),
                ApplicationStatus::label( $to )
            ) );
        }
        return $this->apply_transition( $app, $to, $reason );
    }

    /**
     * Status change made by the system as a side effect of another permitted
     * action (first review, info request sent, addendum recorded). The
     * caller has already checked capability and conflicts.
     */
    public function system_transition( object $app, string $to, string $reason ): void {
        $fresh = ( new ApplicationService() )->find( (int) $app->id );
        if ( $fresh && ApplicationStatus::can_transition( $fresh->status, $to ) ) {
            $this->apply_transition( $fresh, $to, $reason );
        }
    }

    /**
     * Status change outside the manual transition map — used only by
     * DecisionService (into/out of "decided"), which has already checked
     * grants_decide and conflicts. Returns false on a stale row.
     */
    public function force_status( object $app, string $to, string $reason ): bool {
        return $this->apply_transition( $app, $to, $reason ) === true;
    }

    private function apply_transition( object $app, string $to, string $reason ): true|\WP_Error {
        global $wpdb;
        $rows = $wpdb->update(
            $wpdb->prefix . 'grants_applications',
            [ 'status' => $to, 'row_version' => $app->row_version + 1, 'updated_at' => SiteTime::now_utc() ],
            [ 'id' => (int) $app->id, 'row_version' => $app->row_version ],
            [ '%s', '%d', '%s' ],
            [ '%d', '%d' ]
        );
        if ( $rows !== 1 ) {
            return OrganisationService::conflict();
        }
        $this->insert_note( (int) $app->id, self::STATUS, sprintf(
            /* translators: 1: old status, 2: new status, 3: reason */
            __( '%1$s → %2$s. %3$s', 'rotary-grants' ),
            ApplicationStatus::label( $app->status ),
            ApplicationStatus::label( $to ),
            $reason
        ) );
        AuditLogger::record( 'application_status_changed', 'application', (int) $app->id, [ 'from' => $app->status, 'to' => $to ] );
        return true;
    }

    // =========================================================================
    // Notes, information requests, addenda
    // =========================================================================

    /**
     * Internal note — committee only, never shown to applicants.
     *
     * @return int|\WP_Error
     */
    public function add_note( int $application_id, string $body ): int|\WP_Error {
        if ( ! ConflictService::is_committee() ) {
            return self::forbidden();
        }
        return $this->guarded_insert( $application_id, self::NOTE, $body, 5000 );
    }

    /**
     * Draft a request for more information. Nothing is sent until
     * send_info_request() is called by an explicit staff action.
     *
     * @return int|\WP_Error
     */
    public function draft_info_request( int $application_id, string $body ): int|\WP_Error {
        if ( ! self::can_progress() ) {
            return self::forbidden();
        }
        $app = ( new ApplicationService() )->find( $application_id );
        if ( $app && ! ApplicationStatus::is_open( $app->status ) ) {
            return new \WP_Error( 'closed', __( 'This application is no longer open.', 'rotary-grants' ) );
        }
        return $this->guarded_insert( $application_id, self::INFO_REQUEST, $body, 3000 );
    }

    /**
     * Send a drafted information request: queue the email to the applicant's
     * contact address and move the application to "more information
     * requested". Sending twice is refused.
     *
     * @return true|\WP_Error
     */
    public function send_info_request( int $note_id ): true|\WP_Error {
        return $this->send_message( $note_id );
    }

    /**
     * Send a drafted message to the applicant — an information request or a
     * decision notice. Queues the email; an information request also moves
     * the application to "more information requested". Sending twice is
     * refused.
     *
     * @return true|\WP_Error
     */
    public function send_message( int $note_id ): true|\WP_Error {
        $note = $this->find_note( $note_id );
        if ( ! $note || ! in_array( $note->kind, [ self::INFO_REQUEST, self::DECISION_NOTICE ], true ) ) {
            return new \WP_Error( 'not_found', __( 'Message not found.', 'rotary-grants' ) );
        }
        if ( $note->kind === self::INFO_REQUEST ? ! self::can_progress() : ! self::can_send_decision() ) {
            return self::forbidden();
        }
        $gate = ( new ConflictService() )->require_clear( (int) $note->application_id );
        if ( $gate ) {
            return $gate;
        }
        if ( $note->sent_at !== null ) {
            return new \WP_Error( 'already_sent', __( 'This request has already been sent.', 'rotary-grants' ) );
        }
        $app      = ( new ApplicationService() )->find( (int) $note->application_id );
        $snapshot = json_decode( (string) $app->answer_snapshot_json, true ) ?: [];
        $email    = (string) ( $snapshot['answers']['contact_email'] ?? '' );
        if ( ! is_email( $email ) ) {
            return new \WP_Error( 'no_email', __( 'This application has no contact email address, so the request cannot be emailed. Contact the applicant another way and record their reply as an addendum.', 'rotary-grants' ) );
        }

        global $wpdb;
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table()} SET sent_at = %s, sent_by_user_id = %d WHERE id = %d AND sent_at IS NULL",
            SiteTime::now_utc(),
            get_current_user_id(),
            $note_id
        ) );
        if ( $claimed !== 1 ) {
            return new \WP_Error( 'already_sent', __( 'This request has already been sent.', 'rotary-grants' ) );
        }
        ( new NotificationService() )->queue_message( (int) $app->id, $note_id, $email, $note->kind === self::INFO_REQUEST ? NotificationService::KIND_INFO : NotificationService::KIND_DECISION );
        if ( $note->kind === self::INFO_REQUEST ) {
            $this->system_transition( $app, ApplicationStatus::MORE_INFO_REQUESTED, __( 'More information requested from the applicant.', 'rotary-grants' ) );
        }
        AuditLogger::record( $note->kind . '_sent', 'application', (int) $app->id, [ 'note_id' => $note_id ] );
        return true;
    }

    /** grants_decide, or a coordinator (grants_manage_organisations). */
    public static function can_send_decision(): bool {
        return current_user_can( 'grants_decide' ) || current_user_can( 'grants_manage_organisations' );
    }

    /**
     * Draft the decision notice for an application that has an effective
     * decision. Nothing is sent until send_message() — editing a note never
     * sends another email (docs/03).
     *
     * @return int|\WP_Error
     */
    public function draft_decision_notice( int $application_id, string $body ): int|\WP_Error {
        if ( ! self::can_send_decision() ) {
            return self::forbidden();
        }
        if ( ! ( new DecisionService() )->effective( $application_id ) ) {
            return new \WP_Error( 'no_decision', __( 'Record a decision before preparing the notice.', 'rotary-grants' ) );
        }
        return $this->guarded_insert( $application_id, self::DECISION_NOTICE, $body, 5000 );
    }

    /**
     * Discard an unsent draft request.
     *
     * @return true|\WP_Error
     */
    public function discard_draft( int $note_id ): true|\WP_Error {
        $note = $this->find_note( $note_id );
        if ( ! $note || ! in_array( $note->kind, [ self::INFO_REQUEST, self::DECISION_NOTICE ], true ) || $note->sent_at !== null ) {
            return new \WP_Error( 'not_draft', __( 'Only an unsent draft can be discarded.', 'rotary-grants' ) );
        }
        if ( $note->kind === self::INFO_REQUEST ? ! self::can_progress() : ! self::can_send_decision() ) {
            return self::forbidden();
        }
        $gate = ( new ConflictService() )->require_clear( (int) $note->application_id );
        if ( $gate ) {
            return $gate;
        }
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE id = %d AND sent_at IS NULL", $note_id ) );
        return true;
    }

    /**
     * Record the applicant's reply / extra information, dated when it was
     * received. Moves "more information requested" back to "under review".
     *
     * @param string $received_date Local "YYYY-MM-DD", not in the future.
     * @return int|\WP_Error
     */
    public function record_addendum( int $application_id, string $body, string $received_date ): int|\WP_Error {
        if ( ! self::can_progress() ) {
            return self::forbidden();
        }
        $received_date = trim( $received_date );
        $utc           = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $received_date ) ? SiteTime::local_input_to_utc( $received_date . 'T12:00' ) : null;
        if ( ! is_string( $utc ) || $received_date > wp_date( 'Y-m-d' ) ) {
            return new \WP_Error( 'received', __( 'Enter the date the information was received (not in the future).', 'rotary-grants' ) );
        }
        $id = $this->guarded_insert( $application_id, self::ADDENDUM, $body, 10000, $utc );
        if ( is_int( $id ) ) {
            $app = ( new ApplicationService() )->find( $application_id );
            if ( $app && $app->status === ApplicationStatus::MORE_INFO_REQUESTED ) {
                $this->system_transition( $app, ApplicationStatus::UNDER_REVIEW, __( 'Applicant\'s reply recorded.', 'rotary-grants' ) );
            }
        }
        return $id;
    }

    /**
     * The committee record for an application — only for a member who has
     * declared no conflict on it.
     *
     * @return object[]|\WP_Error Oldest first.
     */
    public function notes_for( int $application_id ): array|\WP_Error {
        $gate = ( new ConflictService() )->require_clear( $application_id );
        if ( $gate ) {
            return $gate;
        }
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT n.*, u.display_name AS author_name, s.display_name AS sender_name
             FROM {$this->table()} n
             LEFT JOIN {$wpdb->users} u ON u.ID = n.author_user_id
             LEFT JOIN {$wpdb->users} s ON s.ID = n.sent_by_user_id
             WHERE n.application_id = %d ORDER BY n.created_at, n.id",
            $application_id
        ) );
    }

    public function find_note( int $note_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $note_id ) );
        return $row ?: null;
    }

    // =========================================================================
    // Duplicates
    // =========================================================================

    /**
     * Classify an application as a duplicate of another (kept) one — by its
     * public reference. Nothing is deleted. grants_manage_organisations.
     *
     * @return true|\WP_Error
     */
    public function mark_duplicate( int $application_id, string $kept_reference, string $reason ): true|\WP_Error {
        if ( ! current_user_can( 'grants_manage_organisations' ) ) {
            return self::forbidden();
        }
        $gate = ( new ConflictService() )->require_clear( $application_id );
        if ( $gate ) {
            return $gate;
        }
        $reason = trim( sanitize_textarea_field( $reason ) );
        if ( $reason === '' ) {
            return new \WP_Error( 'reason', __( 'Say why this is a duplicate.', 'rotary-grants' ) );
        }
        global $wpdb;
        $apps = $wpdb->prefix . 'grants_applications';
        $kept = $wpdb->get_row( $wpdb->prepare( "SELECT id, duplicate_of_id FROM {$apps} WHERE public_reference = %s", strtoupper( trim( $kept_reference ) ) ) );
        if ( ! $kept || (int) $kept->id === $application_id ) {
            return new \WP_Error( 'kept', __( 'Enter the reference of the application to keep (not this one).', 'rotary-grants' ) );
        }
        if ( $kept->duplicate_of_id ) {
            return new \WP_Error( 'kept', __( 'That application is itself marked as a duplicate. Point to the one that is kept.', 'rotary-grants' ) );
        }
        $wpdb->update( $apps, [ 'duplicate_of_id' => (int) $kept->id, 'updated_at' => SiteTime::now_utc() ], [ 'id' => $application_id ], [ '%d', '%s' ], [ '%d' ] );
        $this->insert_note( $application_id, self::STATUS, sprintf( /* translators: 1: kept reference, 2: reason */ __( 'Marked as a duplicate of %1$s. %2$s', 'rotary-grants' ), strtoupper( trim( $kept_reference ) ), $reason ) );
        AuditLogger::record( 'application_marked_duplicate', 'application', $application_id, [ 'duplicate_of' => (int) $kept->id ] );
        return true;
    }

    /** @return true|\WP_Error */
    public function unmark_duplicate( int $application_id, string $reason ): true|\WP_Error {
        if ( ! current_user_can( 'grants_manage_organisations' ) ) {
            return self::forbidden();
        }
        $gate = ( new ConflictService() )->require_clear( $application_id );
        if ( $gate ) {
            return $gate;
        }
        $reason = trim( sanitize_textarea_field( $reason ) );
        if ( $reason === '' ) {
            return new \WP_Error( 'reason', __( 'Say why this is not a duplicate after all.', 'rotary-grants' ) );
        }
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}grants_applications SET duplicate_of_id = NULL, updated_at = %s WHERE id = %d", SiteTime::now_utc(), $application_id ) );
        $this->insert_note( $application_id, self::STATUS, sprintf( /* translators: %s: reason */ __( 'No longer marked as a duplicate. %s', 'rotary-grants' ), $reason ) );
        AuditLogger::record( 'application_unmarked_duplicate', 'application', $application_id, [] );
        return true;
    }

    // =========================================================================

    private function guarded_insert( int $application_id, string $kind, string $body, int $max, ?string $received_at = null ): int|\WP_Error {
        $gate = ( new ConflictService() )->require_clear( $application_id );
        if ( $gate ) {
            return $gate;
        }
        if ( ! ( new ApplicationService() )->find( $application_id ) ) {
            return new \WP_Error( 'not_found', __( 'Application not found.', 'rotary-grants' ) );
        }
        $body = trim( sanitize_textarea_field( $body ) );
        if ( $body === '' ) {
            return new \WP_Error( 'body', __( 'Enter some text.', 'rotary-grants' ) );
        }
        if ( mb_strlen( $body ) > $max ) {
            /* translators: %d: max characters */
            return new \WP_Error( 'body', sprintf( __( 'Please keep this to %d characters or fewer.', 'rotary-grants' ), $max ) );
        }
        $id = $this->insert_note( $application_id, $kind, $body, $received_at );
        AuditLogger::record( 'application_' . $kind . '_added', 'application', $application_id, [ 'note_id' => $id ] );
        return $id;
    }

    private function insert_note( int $application_id, string $kind, string $body, ?string $received_at = null ): int {
        global $wpdb;
        $wpdb->insert(
            $this->table(),
            [
                'application_id' => $application_id,
                'kind'           => $kind,
                'body'           => $body,
                'author_user_id' => get_current_user_id(),
                'created_at'     => SiteTime::now_utc(),
                'received_at'    => $received_at,
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'grants_application_notes';
    }

    private static function forbidden(): \WP_Error {
        return new \WP_Error( 'forbidden', __( 'You do not have permission to do that.', 'rotary-grants' ) );
    }
}
