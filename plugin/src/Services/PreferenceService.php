<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Contact permissions by purpose (docs/04 "Data purpose and future
 * contact"). Only purpose so far: future_rounds — "email me when future
 * funding rounds open".
 *
 * Rules: an opt-in records its wording version, date and evidence; a
 * withdrawal is stamped onto that row and never reversed — opting in again
 * is a new row. The current position is the newest row. Creating an opt-in
 * from an application happens at most once per application, so linking or
 * re-linking can never resurrect a withdrawn permission.
 */
class PreferenceService {

    public const PURPOSE_FUTURE_ROUNDS = 'future_rounds';

    public const OPTED_IN  = 'opted_in';
    public const WITHDRAWN = 'withdrawn';

    public function current( int $contact_id, string $purpose = self::PURPOSE_FUTURE_ROUNDS ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE contact_id = %d AND purpose = %s ORDER BY id DESC LIMIT 1",
            $contact_id,
            $purpose
        ) );
        return $row ?: null;
    }

    public function is_opted_in( int $contact_id, string $purpose = self::PURPOSE_FUTURE_ROUNDS ): bool {
        $row = $this->current( $contact_id, $purpose );
        return $row !== null && $row->status === self::OPTED_IN && $row->withdrawn_at === null;
    }

    /** @return object[] Newest first. */
    public function history( int $contact_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE contact_id = %d ORDER BY id DESC",
            $contact_id
        ) );
    }

    /**
     * Record the opt-in ticked on an application, once. Called from
     * OrganisationService::link_application() (capability already checked).
     */
    public function record_from_application( int $contact_id, object $application ): ?int {
        $snapshot = json_decode( (string) $application->answer_snapshot_json, true ) ?: [];
        if ( empty( $snapshot['answers']['future_round_email_opt_in'] ) ) {
            return null;
        }

        global $wpdb;
        $evidence = 'application ' . $application->public_reference;
        $exists   = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE purpose = %s AND evidence_source = %s LIMIT 1",
            self::PURPOSE_FUTURE_ROUNDS,
            $evidence
        ) );
        if ( $exists ) {
            return null; // Already recorded (possibly since withdrawn) — never recreate.
        }
        if ( $this->is_opted_in( $contact_id ) ) {
            return null; // Already opted in through earlier evidence.
        }

        $text = (string) ( $snapshot['wording']['opt_in'] ?? '' );
        return $this->insert(
            $contact_id,
            'form' . ( $snapshot['form_version'] ?? '?' ) . ':' . substr( md5( $text ), 0, 8 ),
            $text,
            $evidence,
            (string) ( $application->submitted_at ?: SiteTime::now_utc() )
        );
    }

    /**
     * Staff record an opt-in given another way (e.g. by email). Evidence is
     * required. Requires grants_manage_organisations.
     *
     * @return int|\WP_Error
     */
    public function record_by_staff( int $contact_id, string $evidence ): int|\WP_Error {
        if ( ! current_user_can( OrganisationService::CAPABILITY ) ) {
            return OrganisationService::forbidden();
        }
        $evidence = trim( sanitize_text_field( $evidence ) );
        if ( $evidence === '' ) {
            return new \WP_Error( 'evidence', __( 'Say how and when the contact gave permission (e.g. "email of 3 March 2026").', 'rotary-grants' ) );
        }
        if ( ! ( new ContactService() )->find( $contact_id ) ) {
            return new \WP_Error( 'not_found', __( 'Contact not found.', 'rotary-grants' ) );
        }
        if ( $this->is_opted_in( $contact_id ) ) {
            return new \WP_Error( 'already', __( 'This contact is already opted in.', 'rotary-grants' ) );
        }
        $id = $this->insert( $contact_id, 'staff', __( 'Recorded by staff from the evidence noted.', 'rotary-grants' ), 'staff: ' . mb_substr( $evidence, 0, 240 ), SiteTime::now_utc() );
        AuditLogger::record( 'preference_recorded', 'contact', $contact_id, [ 'purpose' => self::PURPOSE_FUTURE_ROUNDS, 'preference_id' => $id ] );
        return $id;
    }

    /**
     * Withdraw the current opt-in. Requires grants_manage_organisations.
     *
     * @return true|\WP_Error
     */
    public function withdraw( int $contact_id, string $source ): true|\WP_Error {
        if ( ! current_user_can( OrganisationService::CAPABILITY ) ) {
            return OrganisationService::forbidden();
        }
        $source = trim( sanitize_text_field( $source ) );
        if ( $source === '' ) {
            return new \WP_Error( 'source', __( 'Say how the withdrawal was received (e.g. "phone call 4 March").', 'rotary-grants' ) );
        }
        $row = $this->current( $contact_id );
        if ( ! $row || $row->status !== self::OPTED_IN ) {
            return new \WP_Error( 'not_opted_in', __( 'This contact has no current permission to withdraw.', 'rotary-grants' ) );
        }
        global $wpdb;
        $wpdb->update(
            $this->table(),
            [
                'status'               => self::WITHDRAWN,
                'withdrawn_at'         => SiteTime::now_utc(),
                'withdrawal_source'    => mb_substr( $source, 0, 255 ),
                'withdrawn_by_user_id' => get_current_user_id(),
            ],
            [ 'id' => (int) $row->id, 'status' => self::OPTED_IN ],
            [ '%s', '%s', '%s', '%d' ],
            [ '%d', '%s' ]
        );
        AuditLogger::record( 'preference_withdrawn', 'contact', $contact_id, [ 'purpose' => self::PURPOSE_FUTURE_ROUNDS, 'preference_id' => (int) $row->id ] );
        return true;
    }

    private function insert( int $contact_id, string $wording_version, string $wording_text, string $evidence, string $recorded_at ): int {
        global $wpdb;
        $wpdb->insert(
            $this->table(),
            [
                'contact_id'          => $contact_id,
                'purpose'             => self::PURPOSE_FUTURE_ROUNDS,
                'status'              => self::OPTED_IN,
                'wording_version'     => mb_substr( $wording_version, 0, 60 ),
                'wording_text'        => $wording_text,
                'recorded_at'         => $recorded_at,
                'evidence_source'     => mb_substr( $evidence, 0, 255 ),
                'recorded_by_user_id' => get_current_user_id(),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
        );
        return (int) $wpdb->insert_id;
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'grants_preferences';
    }
}
