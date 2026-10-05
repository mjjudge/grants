<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Personal data handling (docs/04 "Retention and rights", DEC-018).
 *
 * Anonymise, never delete the record: an application keeps its reference,
 * round, organisation, amounts, versions, decisions, award and payments (the
 * club's financial and governance record); the applicant's contact details
 * and free-text answers — and the committee's free-text notes about it —
 * are replaced. Contacts lose name, role, email and phone.
 *
 * Also provides WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data), which run only after WordPress's own
 * verified-request workflow and an administrator's action.
 */
class PrivacyService {

    public const REMOVED = '[removed]';

    /** Snapshot answers that identify a person. */
    private const PERSONAL_FIELDS = [ 'contact_name', 'contact_role', 'contact_phone', 'contact_email', 'declaration_name' ];

    /** Free-text answers that may contain personal details about anyone. */
    private const FREE_TEXT_FIELDS = [
        'organisation_overview', 'proposed_use', 'expected_difference', 'local_benefit',
        'locally_led_explanation', 'branch_explanation', 'one_off_explanation',
    ];

    public function register(): void {
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
        add_action( 'admin_init', [ $this, 'privacy_policy_text' ] );
    }

    // =========================================================================
    // Anonymisation
    // =========================================================================

    /**
     * Anonymise one application (never one still under consideration).
     * Idempotent. Caller has checked permission.
     */
    public function anonymise_application( int $application_id, string $why ): bool {
        global $wpdb;
        $p   = $wpdb->prefix;
        $app = ( new ApplicationService() )->find( $application_id );
        if ( ! $app || $app->anonymised_at !== null || ApplicationStatus::is_open( $app->status ) ) {
            return false;
        }
        $snap = json_decode( (string) $app->answer_snapshot_json, true ) ?: [];
        foreach ( array_merge( self::PERSONAL_FIELDS, self::FREE_TEXT_FIELDS ) as $f ) {
            if ( isset( $snap['answers'][ $f ] ) && $snap['answers'][ $f ] !== '' ) {
                $snap['answers'][ $f ] = self::REMOVED;
            }
        }
        if ( isset( $snap['staff_entry']['reason'] ) && $snap['staff_entry']['reason'] !== '' ) {
            $snap['staff_entry']['reason'] = self::REMOVED;
        }
        $snap['anonymised'] = [ 'at' => SiteTime::now_utc(), 'why' => $why ];

        $wpdb->query( 'START TRANSACTION' );
        $wpdb->update(
            "{$p}grants_applications",
            [ 'answer_snapshot_json' => wp_json_encode( $snap ), 'entry_reason' => $app->entry_reason ? self::REMOVED : $app->entry_reason, 'anonymised_at' => SiteTime::now_utc() ],
            [ 'id' => $application_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
        // Committee free text about this application (notes, requests, replies, decision notices).
        $wpdb->query( $wpdb->prepare( "UPDATE {$p}grants_application_notes SET body = %s WHERE application_id = %d AND kind <> 'status'", self::REMOVED, $application_id ) );
        $wpdb->query( $wpdb->prepare( "UPDATE {$p}grants_reviews SET notes = %s, eligibility_json = %s WHERE application_id = %d", self::REMOVED, '{}', $application_id ) );
        $wpdb->query( $wpdb->prepare( "UPDATE {$p}grants_notifications SET recipient = %s WHERE application_id = %d", self::REMOVED, $application_id ) );
        $wpdb->query( 'COMMIT' );

        AuditLogger::record( 'application_anonymised', 'application', $application_id, [ 'why' => $why ] );
        return true;
    }

    /** Anonymise one contact and the old values in its correction history. Idempotent. */
    public function anonymise_contact( int $contact_id, string $why ): bool {
        global $wpdb;
        $p       = $wpdb->prefix;
        $contact = $wpdb->get_row( $wpdb->prepare( "SELECT id, anonymised_at FROM {$p}grants_contacts WHERE id = %d", $contact_id ) );
        if ( ! $contact || $contact->anonymised_at !== null ) {
            return false;
        }
        $wpdb->update(
            "{$p}grants_contacts",
            [ 'name' => self::REMOVED, 'role' => '', 'email' => '', 'phone' => '', 'anonymised_at' => SiteTime::now_utc() ],
            [ 'id' => $contact_id ],
            [ '%s', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, changes_json FROM {$p}grants_amendments WHERE entity_type = 'contact' AND entity_id = %d", $contact_id ) ) as $am ) {
            $changes = json_decode( (string) $am->changes_json, true ) ?: [];
            foreach ( $changes as $field => $pair ) {
                if ( in_array( $field, [ 'name', 'role', 'email', 'phone' ], true ) ) {
                    $changes[ $field ] = [ self::REMOVED, self::REMOVED ];
                }
            }
            $wpdb->update( "{$p}grants_amendments", [ 'changes_json' => wp_json_encode( $changes ) ], [ 'id' => (int) $am->id ], [ '%s' ], [ '%d' ] );
        }
        AuditLogger::record( 'contact_anonymised', 'contact', $contact_id, [ 'why' => $why ] );
        return true;
    }

    // =========================================================================
    // WordPress exporter / eraser
    // =========================================================================

    /** @param array<string,mixed> $exporters */
    public function register_exporter( array $exporters ): array {
        $exporters['rotary-grants'] = [
            'exporter_friendly_name' => __( 'Grant applications', 'rotary-grants' ),
            'callback'               => [ $this, 'export' ],
        ];
        return $exporters;
    }

    /** @param array<string,mixed> $erasers */
    public function register_eraser( array $erasers ): array {
        $erasers['rotary-grants'] = [
            'eraser_friendly_name' => __( 'Grant applications', 'rotary-grants' ),
            'callback'             => [ $this, 'erase' ],
        ];
        return $erasers;
    }

    /**
     * @return array{data: list<array<string,mixed>>, done: bool}
     */
    public function export( string $email, int $page = 1 ): array {
        $email = trim( $email );
        $items = [];
        $labels = ApplicationForm::labels();

        foreach ( $this->applications_for_email( $email ) as $app ) {
            $snap = json_decode( (string) $app->answer_snapshot_json, true ) ?: [];
            $data = [
                [ 'name' => __( 'Reference', 'rotary-grants' ), 'value' => $app->public_reference ],
                [ 'name' => __( 'Funding round', 'rotary-grants' ), 'value' => ( $snap['round']['fund_name'] ?? '' ) . ' — ' . ( $snap['round']['label'] ?? '' ) ],
                [ 'name' => __( 'Submitted', 'rotary-grants' ), 'value' => (string) $app->submitted_at ],
                [ 'name' => __( 'Status', 'rotary-grants' ), 'value' => ApplicationStatus::label( $app->status ) ],
            ];
            foreach ( (array) ( $snap['answers'] ?? [] ) as $field => $value ) {
                $data[] = [ 'name' => $labels[ $field ] ?? $field, 'value' => is_bool( $value ) ? ( $value ? __( 'Yes', 'rotary-grants' ) : __( 'No', 'rotary-grants' ) ) : (string) $value ];
            }
            $items[] = [ 'group_id' => 'rotary-grants-applications', 'group_label' => __( 'Grant applications', 'rotary-grants' ), 'item_id' => 'grant-application-' . $app->id, 'data' => $data ];
        }

        foreach ( $this->contacts_for_email( $email ) as $c ) {
            $data = [
                [ 'name' => __( 'Organisation', 'rotary-grants' ), 'value' => (string) $c->organisation_name ],
                [ 'name' => __( 'Name', 'rotary-grants' ), 'value' => $c->name ],
                [ 'name' => __( 'Role', 'rotary-grants' ), 'value' => $c->role ],
                [ 'name' => __( 'Email', 'rotary-grants' ), 'value' => $c->email ],
                [ 'name' => __( 'Phone', 'rotary-grants' ), 'value' => $c->phone ],
                [ 'name' => __( 'Contact from', 'rotary-grants' ), 'value' => (string) $c->active_from ],
                [ 'name' => __( 'Contact until', 'rotary-grants' ), 'value' => (string) ( $c->active_until ?? '' ) ],
            ];
            foreach ( ( new PreferenceService() )->history( (int) $c->id ) as $pr ) {
                $data[] = [
                    'name'  => __( 'Future-round email permission', 'rotary-grants' ),
                    'value' => sprintf( '%s · %s · %s%s', $pr->status, $pr->recorded_at, $pr->evidence_source, $pr->withdrawn_at ? ' · withdrawn ' . $pr->withdrawn_at : '' ),
                ];
            }
            $items[] = [ 'group_id' => 'rotary-grants-contacts', 'group_label' => __( 'Grant contacts', 'rotary-grants' ), 'item_id' => 'grant-contact-' . $c->id, 'data' => $data ];
        }

        global $wpdb;
        foreach ( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, kind, status, created_at, sent_at FROM {$wpdb->prefix}grants_notifications WHERE recipient = %s ORDER BY id",
            $email
        ) ) as $n ) {
            $items[] = [
                'group_id'    => 'rotary-grants-emails',
                'group_label' => __( 'Grant emails sent', 'rotary-grants' ),
                'item_id'     => 'grant-email-' . $n->id,
                'data'        => [
                    [ 'name' => __( 'Email', 'rotary-grants' ), 'value' => NotificationService::kind_label( $n->kind ) ],
                    [ 'name' => __( 'Status', 'rotary-grants' ), 'value' => $n->status ],
                    [ 'name' => __( 'Queued', 'rotary-grants' ), 'value' => (string) $n->created_at ],
                    [ 'name' => __( 'Sent', 'rotary-grants' ), 'value' => (string) ( $n->sent_at ?? '' ) ],
                ],
            ];
        }

        return [ 'data' => $items, 'done' => true ];
    }

    /**
     * Erase what can be erased for an email address; report what is kept and why.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
     */
    public function erase( string $email, int $page = 1 ): array {
        $email    = trim( $email );
        $removed  = false;
        $retained = false;
        $messages = [];

        foreach ( $this->applications_for_email( $email ) as $app ) {
            if ( ApplicationStatus::is_open( $app->status ) ) {
                $retained   = true;
                $messages[] = sprintf(
                    /* translators: %s: reference */
                    __( 'Application %s is still being considered, so its contact details were kept. Erase again once it has been decided or withdrawn.', 'rotary-grants' ),
                    $app->public_reference
                );
                continue;
            }
            if ( $app->anonymised_at === null && $this->anonymise_application( (int) $app->id, 'erasure request' ) ) {
                $removed = true;
            }
            $retained   = true;
            $messages[] = sprintf(
                /* translators: %s: reference */
                __( 'Application %s: contact details and free-text answers removed. The organisation name, amounts, decision, award and payment records are kept as the club\'s financial record.', 'rotary-grants' ),
                $app->public_reference
            );
        }

        $orgs = [];
        foreach ( $this->contacts_for_email( $email ) as $c ) {
            if ( $this->anonymise_contact( (int) $c->id, 'erasure request' ) ) {
                $removed = true;
            }
            $orgs[] = (string) $c->organisation_name;
        }
        if ( count( array_unique( $orgs ) ) > 1 ) {
            $messages[] = sprintf(
                /* translators: %s: organisation names */
                __( 'This email address was a contact for more than one organisation (%s). Check with those organisations that the right person asked for erasure.', 'rotary-grants' ),
                implode( ', ', array_unique( $orgs ) )
            );
        }

        global $wpdb;
        $n = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}grants_notifications SET recipient = %s WHERE recipient = %s", self::REMOVED, $email ) );
        $removed = $removed || $n > 0;

        return [ 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => true ];
    }

    /** Suggested wording for the site's privacy policy (Settings → Privacy → Policy Guide). */
    public function privacy_policy_text(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }
        $text = '<p>' . esc_html__( 'When an organisation applies for a grant we collect the details on the application form: the organisation\'s name, address and website; the name, role, email address and phone number of the person applying; and their answers about the project. We use this to consider the application, to administer any award and payment, and to keep the financial records we are required to keep. Committee members record their reviews and decisions. We email the applicant an acknowledgement and any decision.', 'rotary-grants' ) . '</p>'
            . '<p>' . esc_html__( 'If the applicant ticks the box asking to hear about future funding rounds, we keep a record of that permission and use it only for that purpose. It can be withdrawn at any time.', 'rotary-grants' ) . '</p>'
            . '<p>' . esc_html__( 'We do not collect bank account details through this website. Personal details and free-text answers are removed after the periods set by the club (shown in the plugin\'s Settings); records of awards and payments are kept as financial records.', 'rotary-grants' ) . '</p>';
        wp_add_privacy_policy_content( __( 'Grant applications', 'rotary-grants' ), wp_kses_post( $text ) );
    }

    // =========================================================================

    /** @return object[] */
    private function applications_for_email( string $email ): array {
        global $wpdb;
        if ( ! is_email( $email ) ) {
            return [];
        }
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}grants_applications
             WHERE LOWER(JSON_UNQUOTE(JSON_EXTRACT(answer_snapshot_json, '$.answers.contact_email'))) = LOWER(%s)
             ORDER BY id",
            $email
        ) );
        $apps = new ApplicationService();
        return array_values( array_filter( array_map( static fn( $r ) => $apps->find( (int) $r->id ), $rows ) ) );
    }

    /** @return object[] */
    private function contacts_for_email( string $email ): array {
        global $wpdb;
        if ( ! is_email( $email ) ) {
            return [];
        }
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, o.name AS organisation_name FROM {$wpdb->prefix}grants_contacts c
             LEFT JOIN {$wpdb->prefix}grants_organisations o ON o.id = c.organisation_id
             WHERE LOWER(c.email) = LOWER(%s) ORDER BY c.id",
            $email
        ) );
    }
}
