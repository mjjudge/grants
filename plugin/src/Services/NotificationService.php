<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Mail\Mailer;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Email queue for application notifications.
 *
 * queue_for_application() runs only after the application has committed and
 * never throws back into the submission: a mail problem can't lose or
 * duplicate an application. Messages are sent straight after the
 * applicant's receipt redirect (see Public\ApplicationFormHandler) and
 * retried by WP-Cron with backoff; after MAX_ATTEMPTS a row is 'failed' and
 * shown on the Notifications screen for a staff retry.
 *
 * Kinds:
 *   applicant_ack          — to the applicant's contact email
 *   staff_new_application  — one row per Settings recipient at submission time
 *   info_request           — a committee request for more information, built
 *                            from the grants_application_notes row in related_id
 */
class NotificationService {

    public const KIND_ACK   = 'applicant_ack';
    public const KIND_STAFF = 'staff_new_application';
    public const KIND_INFO  = 'info_request';
    public const KIND_DECISION = 'decision_notice';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';

    public const CRON_HOOK     = 'grants_process_notifications';
    public const CRON_SCHEDULE = 'grants_every_five_minutes';

    public const MAX_ATTEMPTS = 5;

    /** Delay before retry n+1, in seconds, after n failed attempts. */
    private const BACKOFF = [ 1 => 300, 2 => 900, 3 => 3600, 4 => 21600 ];

    /** How long a claimed row is reserved for the sender that claimed it. */
    private const CLAIM_SECONDS = 300;

    private const BATCH = 20;

    // =========================================================================
    // Cron wiring
    // =========================================================================

    public static function register_cron(): void {
        add_filter( 'cron_schedules', static function ( array $schedules ): array {
            $schedules[ self::CRON_SCHEDULE ] = [
                'interval' => 300,
                'display'  => __( 'Every five minutes (Rotary Grants)', 'rotary-grants' ),
            ];
            return $schedules;
        } );
        add_action( self::CRON_HOOK, static fn() => ( new self() )->process_due() );

        // Also schedules after a plain file update (activation hooks don't run then).
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    public static function unschedule_cron(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    // =========================================================================
    // Queueing
    // =========================================================================

    /**
     * Queue the acknowledgement and staff notices for a committed application.
     * Idempotent (UNIQUE command_key). Returns the number of rows newly queued.
     *
     * @param string[]|null $kinds Limit to these kinds (null = both).
     */
    public function queue_for_application( int $application_id, ?array $kinds = null ): int {
        $app = ( new ApplicationService() )->find( $application_id );
        if ( ! $app ) {
            return 0;
        }
        $snapshot = json_decode( (string) $app->answer_snapshot_json, true ) ?: [];
        $email    = (string) ( $snapshot['answers']['contact_email'] ?? '' );

        $kinds = $kinds ?? [ self::KIND_ACK, self::KIND_STAFF ];
        $rows  = [];
        if ( in_array( self::KIND_ACK, $kinds, true ) && is_email( $email ) ) {
            $rows[] = [ self::KIND_ACK, $email, self::KIND_ACK . ':' . $application_id ];
        }
        foreach ( in_array( self::KIND_STAFF, $kinds, true ) ? ( new SettingsService() )->notification_recipients() : [] as $staff ) {
            $rows[] = [ self::KIND_STAFF, $staff, self::KIND_STAFF . ':' . $application_id . ':' . md5( strtolower( $staff ) ) ];
        }

        global $wpdb;
        $now    = SiteTime::now_utc();
        $queued = 0;
        foreach ( $rows as [ $kind, $recipient, $key ] ) {
            $queued += (int) $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$this->table()}
                    (application_id, kind, recipient, status, attempts, next_attempt_at, command_key, created_at)
                 VALUES (%d, %s, %s, %s, 0, %s, %s, %s)",
                $application_id, $kind, $recipient, self::STATUS_PENDING, $now, $key, $now
            ) );
        }
        return $queued;
    }

    /**
     * Queue a sent information request (WorkflowService::send_info_request()).
     * Idempotent per note.
     */
    public function queue_info_request( int $application_id, int $note_id, string $recipient ): int {
        return $this->queue_message( $application_id, $note_id, $recipient, self::KIND_INFO );
    }

    /**
     * Queue a staff-written message (information request or decision notice)
     * built from a grants_application_notes row. Idempotent per note.
     */
    public function queue_message( int $application_id, int $note_id, string $recipient, string $kind ): int {
        global $wpdb;
        $now = SiteTime::now_utc();
        return (int) $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$this->table()}
                (application_id, kind, related_id, recipient, status, attempts, next_attempt_at, command_key, created_at)
             VALUES (%d, %s, %d, %s, %s, 0, %s, %s, %s)",
            $application_id, $kind, $note_id, $recipient, self::STATUS_PENDING, $now, $kind . ':' . $note_id, $now
        ) );
    }

    // =========================================================================
    // Sending
    // =========================================================================

    /**
     * Send due pending notifications (optionally only for one application).
     * Does nothing while sending is paused in Settings.
     *
     * @return array{sent:int, failed:int, retrying:int}
     */
    public function process_due( ?int $application_id = null, int $limit = self::BATCH ): array {
        $tally = [ 'sent' => 0, 'failed' => 0, 'retrying' => 0 ];
        if ( ( new SettingsService() )->notifications_paused() ) {
            return $tally;
        }

        global $wpdb;
        $now = SiteTime::now_utc();
        $sql = "SELECT id FROM {$this->table()}
                WHERE status = %s AND next_attempt_at <= %s AND (claimed_until IS NULL OR claimed_until < %s)";
        $params = [ self::STATUS_PENDING, $now, $now ];
        if ( $application_id ) {
            $sql     .= ' AND application_id = %d';
            $params[] = $application_id;
        }
        $sql     .= ' ORDER BY next_attempt_at, id LIMIT %d';
        $params[] = $limit;

        foreach ( $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — placeholders above
            $outcome = $this->send_one( (int) $id );
            if ( $outcome !== null ) {
                $tally[ $outcome ]++;
            }
        }
        return $tally;
    }

    /**
     * Claim and send one row. Returns 'sent' | 'retrying' | 'failed', or null
     * if another process holds it or it is no longer due.
     */
    private function send_one( int $id ): ?string {
        global $wpdb;
        $now     = SiteTime::now_utc();
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table()}
             SET claimed_until = %s, attempts = attempts + 1, last_attempt_at = %s
             WHERE id = %d AND status = %s AND next_attempt_at <= %s AND (claimed_until IS NULL OR claimed_until < %s)",
            gmdate( SiteTime::DB_FORMAT, time() + self::CLAIM_SECONDS ), $now, $id, self::STATUS_PENDING, $now, $now
        ) );
        if ( $claimed !== 1 ) {
            return null;
        }

        $row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
        $message = $this->build_message( $row );
        $result  = is_wp_error( $message )
            ? $message
            : ( new Mailer() )->send( $row->recipient, $message['subject'], $message['body'] );

        if ( $result === true ) {
            $wpdb->update(
                $this->table(),
                [ 'status' => self::STATUS_SENT, 'sent_at' => SiteTime::now_utc(), 'claimed_until' => null, 'last_error_code' => '' ],
                [ 'id' => $id ],
                [ '%s', '%s', null, '%s' ],
                [ '%d' ]
            );
            return 'sent';
        }

        $attempts = (int) $row->attempts;
        $final    = $attempts >= self::MAX_ATTEMPTS || $result->get_error_code() === 'invalid_recipient';
        $wpdb->update(
            $this->table(),
            [
                'status'          => $final ? self::STATUS_FAILED : self::STATUS_PENDING,
                'next_attempt_at' => gmdate( SiteTime::DB_FORMAT, time() + ( self::BACKOFF[ $attempts ] ?? 21600 ) ),
                'claimed_until'   => null,
                'last_error_code' => substr( (string) $result->get_error_code(), 0, 64 ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', null, '%s' ],
            [ '%d' ]
        );
        return $final ? 'failed' : 'retrying';
    }

    // =========================================================================
    // Staff actions and reads
    // =========================================================================

    /**
     * Put a failed (or pending) notification back in the queue, due now, with
     * a fresh set of attempts. Requires grants_manage_settings. Audited.
     *
     * @return true|\WP_Error
     */
    public function retry( int $id ): true|\WP_Error {
        if ( ! current_user_can( 'grants_manage_settings' ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to resend notifications.', 'rotary-grants' ) );
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, application_id, kind, status FROM {$this->table()} WHERE id = %d", $id ) );
        if ( ! $row || $row->status === self::STATUS_SENT ) {
            return new \WP_Error( 'not_retryable', __( 'That notification has already been sent or does not exist.', 'rotary-grants' ) );
        }
        $wpdb->update(
            $this->table(),
            [ 'status' => self::STATUS_PENDING, 'attempts' => 0, 'next_attempt_at' => SiteTime::now_utc(), 'claimed_until' => null ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s', null ],
            [ '%d' ]
        );
        AuditLogger::record( 'notification_retried', 'notification', $id, [
            'application_id' => (int) $row->application_id,
            'kind'           => $row->kind,
            'previous'       => $row->status,
        ] );
        return true;
    }

    /**
     * @return object[] Newest first.
     */
    public function list( ?string $status = null, int $limit = 200 ): array {
        global $wpdb;
        $where = '';
        $args  = [];
        if ( in_array( $status, [ self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_FAILED ], true ) ) {
            $where  = 'WHERE n.status = %s';
            $args[] = $status;
        }
        $args[] = $limit;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT n.*, a.public_reference FROM {$this->table()} n
             LEFT JOIN {$wpdb->prefix}grants_applications a ON a.id = n.application_id
             {$where} ORDER BY n.id DESC LIMIT %d",
            ...$args
        ) );
    }

    /** @return array<string,int> status => count */
    public function counts(): array {
        global $wpdb;
        $out = [ self::STATUS_PENDING => 0, self::STATUS_SENT => 0, self::STATUS_FAILED => 0 ];
        foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — no user input
            $out[ $r->status ] = (int) $r->n;
        }
        return $out;
    }

    public static function kind_label( string $kind ): string {
        return match ( $kind ) {
            self::KIND_ACK   => __( 'Applicant acknowledgement', 'rotary-grants' ),
            self::KIND_STAFF => __( 'Staff: new application', 'rotary-grants' ),
            self::KIND_INFO  => __( 'Request for more information', 'rotary-grants' ),
            self::KIND_DECISION => __( 'Decision notice', 'rotary-grants' ),
            default          => $kind,
        };
    }

    // =========================================================================
    // Message content
    // =========================================================================

    /**
     * Build subject and plain-text body from the application snapshot.
     *
     * @return array{subject:string, body:string}|\WP_Error
     */
    public function build_message( object $row ): array|\WP_Error {
        $app = ( new ApplicationService() )->find( (int) $row->application_id );
        if ( ! $app ) {
            return new \WP_Error( 'application_missing' );
        }
        $snapshot = json_decode( (string) $app->answer_snapshot_json, true ) ?: [];
        $settings = new SettingsService();

        $vars = [
            'reference'     => $app->public_reference,
            'fund_name'     => (string) ( $snapshot['round']['fund_name'] ?? '' ),
            'round_label'   => (string) ( $snapshot['round']['label'] ?? '' ),
            'organisation'  => $app->organisation_name,
            'amount'        => $app->requested_pence === null ? '' : Money::format_gbp( $app->requested_pence ),
            'received'      => SiteTime::display( $app->submitted_at ),
            'contact_name'  => (string) ( $snapshot['answers']['contact_name'] ?? '' ),
            'help_email'    => $settings->help_email(),
            'from_name'     => $settings->mail_from_name(),
            'admin_url'     => add_query_arg( [ 'page' => 'grants-applications', 'action' => 'view', 'id' => $app->id ], admin_url( 'admin.php' ) ),
        ];

        if ( $row->kind === self::KIND_ACK ) {
            /* translators: 1: fund name, 2: reference */
            $subject  = sprintf( __( 'Your %1$s funding application — %2$s', 'rotary-grants' ), $vars['fund_name'], $vars['reference'] );
            $template = 'applicant-acknowledgement';
        } elseif ( $row->kind === self::KIND_DECISION ) {
            $note = ( new WorkflowService() )->find_note( (int) $row->related_id );
            if ( ! $note || $note->sent_at === null ) {
                return new \WP_Error( 'notice_missing' );
            }
            $vars['request'] = (string) $note->body;
            /* translators: 1: fund name, 2: reference */
            $subject  = sprintf( __( 'Your %1$s funding application %2$s — decision', 'rotary-grants' ), $vars['fund_name'], $vars['reference'] );
            $template = 'decision-notice';
        } elseif ( $row->kind === self::KIND_INFO ) {
            $note = ( new WorkflowService() )->find_note( (int) $row->related_id );
            if ( ! $note || $note->sent_at === null ) {
                return new \WP_Error( 'request_missing' );
            }
            $vars['request'] = (string) $note->body;
            /* translators: 1: fund name, 2: reference */
            $subject  = sprintf( __( 'More information needed: your %1$s funding application %2$s', 'rotary-grants' ), $vars['fund_name'], $vars['reference'] );
            $template = 'info-request';
        } elseif ( $row->kind === self::KIND_STAFF ) {
            /* translators: 1: reference, 2: fund name, 3: organisation */
            $subject  = sprintf( __( 'New grant application %1$s (%2$s): %3$s', 'rotary-grants' ), $vars['reference'], $vars['fund_name'], $vars['organisation'] );
            $template = 'staff-new-application';
        } else {
            return new \WP_Error( 'unknown_kind' );
        }

        return [ 'subject' => $subject, 'body' => self::render( $template, $vars ) ];
    }

    /**
     * @param array<string,string> $vars
     */
    private static function render( string $template, array $vars ): string {
        extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract — variables documented in the template
        ob_start();
        include GRANTS_PLUGIN_DIR . 'templates/email/' . $template . '.php';
        return trim( (string) ob_get_clean() ) . "\n";
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'grants_notifications';
    }
}
