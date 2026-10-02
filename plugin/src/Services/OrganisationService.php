<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\NameMatcher;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Organisations: create/correct, staff-confirmed linking of applications,
 * and merging duplicates.
 *
 * Nothing here links or merges automatically. candidates_for_application()
 * only suggests; a staff member (grants_manage_organisations) decides via
 * link_application() or merge(). Application snapshots are never changed.
 */
class OrganisationService {

    public const CAPABILITY = 'grants_manage_organisations';

    public const ACTIVE = 'active';
    public const MERGED = 'merged';

    /** Editable fields: field => max length. */
    private const FIELDS = [ 'name' => 200, 'charity_number' => 50, 'town' => 100, 'postcode' => 10, 'website_url' => 500 ];

    // =========================================================================
    // Reads
    // =========================================================================

    public function find( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
        return $row ? self::hydrate( $row ) : null;
    }

    /**
     * Active organisations, optionally filtered by a name search, with a
     * count of linked applications.
     *
     * @return object[]
     */
    public function search( string $query = '', int $limit = 200 ): array {
        global $wpdb;
        $apps = $wpdb->prefix . 'grants_applications';
        $sql  = "SELECT o.*, (SELECT COUNT(*) FROM {$apps} a WHERE a.organisation_id = o.id) AS application_count
                 FROM {$this->table()} o WHERE o.status = %s";
        $args = [ self::ACTIVE ];
        $q    = trim( $query );
        if ( $q !== '' ) {
            $sql   .= ' AND (o.name LIKE %s OR o.name_key LIKE %s OR o.charity_key = %s OR o.town LIKE %s)';
            $like   = '%' . $wpdb->esc_like( $q ) . '%';
            $args[] = $like;
            $args[] = '%' . $wpdb->esc_like( NameMatcher::name_key( $q ) ) . '%';
            $args[] = NameMatcher::charity_key( $q );
            $args[] = $like;
        }
        $sql   .= ' ORDER BY o.name LIMIT %d';
        $args[] = $limit;
        return array_map( [ self::class, 'hydrate' ], (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — placeholders above
    }

    /**
     * Suggested organisations for an unlinked application, best first.
     * Scores every active organisation in PHP — fine at a club's scale
     * (hundreds of organisations); revisit if that ever grows a lot.
     *
     * @return list<array{organisation: object, score: int, reasons: string[]}>
     */
    public function candidates_for_application( object $application, int $limit = 5 ): array {
        $answers   = ( json_decode( (string) $application->answer_snapshot_json, true ) ?: [] )['answers'] ?? [];
        $applicant = [
            'name'           => (string) ( $answers['organisation_name'] ?? $application->organisation_name ),
            'charity_number' => (string) ( $answers['charity_number'] ?? '' ),
            'postcode'       => (string) ( $answers['organisation_postcode'] ?? '' ),
            'town'           => (string) ( $answers['organisation_town'] ?? '' ),
        ];
        $out = [];
        foreach ( $this->search( '', 5000 ) as $org ) {
            $m = NameMatcher::score( $applicant, [
                'name'           => $org->name,
                'charity_number' => $org->charity_number,
                'postcode'       => $org->postcode,
                'town'           => $org->town,
            ] );
            if ( $m['score'] >= NameMatcher::THRESHOLD ) {
                $out[] = [ 'organisation' => $org, 'score' => $m['score'], 'reasons' => $m['reasons'] ];
            }
        }
        usort( $out, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
        return array_slice( $out, 0, $limit );
    }

    // =========================================================================
    // Create / correct
    // =========================================================================

    /**
     * @param array<string,string> $input name, charity_number, town, postcode, website_url
     * @return int|\WP_Error
     */
    public function create( array $input ): int|\WP_Error {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return self::forbidden();
        }
        [ $clean, $errors ] = $this->validate( $input );
        if ( $errors->has_errors() ) {
            return $errors;
        }
        return $this->insert( $clean );
    }

    /**
     * Correct an organisation's current details; old values go to
     * grants_amendments with the reason.
     *
     * @param array<string,string> $input
     * @return true|\WP_Error
     */
    public function update( int $id, array $input, int $expected_row_version, string $reason ): true|\WP_Error {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return self::forbidden();
        }
        $org = $this->find( $id );
        if ( ! $org ) {
            return new \WP_Error( 'not_found', __( 'Organisation not found.', 'rotary-grants' ) );
        }
        if ( $org->status === self::MERGED ) {
            return new \WP_Error( 'merged', __( 'This organisation was merged into another; edit that one instead.', 'rotary-grants' ) );
        }
        [ $clean, $errors ] = $this->validate( $input );
        if ( $errors->has_errors() ) {
            return $errors;
        }
        $changes = [];
        foreach ( self::FIELDS as $field => $max ) {
            if ( (string) $org->$field !== $clean[ $field ] ) {
                $changes[ $field ] = [ $org->$field, $clean[ $field ] ];
            }
        }
        if ( ! $changes ) {
            return true;
        }
        global $wpdb;
        $rows = $wpdb->update(
            $this->table(),
            $clean + [
                'name_key'    => NameMatcher::name_key( $clean['name'] ),
                'charity_key' => NameMatcher::charity_key( $clean['charity_number'] ),
                'row_version' => $expected_row_version + 1,
                'updated_at'  => SiteTime::now_utc(),
            ],
            [ 'id' => $id, 'row_version' => $expected_row_version ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ],
            [ '%d', '%d' ]
        );
        if ( $rows !== 1 ) {
            return self::conflict();
        }
        AmendmentLog::record( 'organisation', $id, $changes, trim( sanitize_textarea_field( $reason ) ) );
        AuditLogger::record( 'organisation_updated', 'organisation', $id, [ 'changed' => array_keys( $changes ) ] );
        return true;
    }

    // =========================================================================
    // Linking applications
    // =========================================================================

    /**
     * Link an application to an organisation chosen by staff — an existing
     * one ($organisation_id > 0) or a new one created from the application's
     * own answers ($organisation_id === 0). Also records the applicant as a
     * contact of that organisation (reusing a current contact with the same
     * name and email) and, if they ticked it, their future-rounds opt-in.
     *
     * Re-linking an already-linked application needs a reason.
     *
     * @return array{organisation_id:int, contact_id:int, created:bool}|\WP_Error
     */
    public function link_application( int $application_id, int $organisation_id, int $expected_row_version, string $reason = '' ): array|\WP_Error {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return self::forbidden();
        }
        $apps = new ApplicationService();
        $app  = $apps->find( $application_id );
        if ( ! $app ) {
            return new \WP_Error( 'not_found', __( 'Application not found.', 'rotary-grants' ) );
        }
        if ( $app->row_version !== $expected_row_version ) {
            return self::conflict();
        }
        $reason = trim( sanitize_textarea_field( $reason ) );
        if ( $app->organisation_id && $reason === '' ) {
            return new \WP_Error( 'reason', __( 'This application is already linked. Give a reason for changing the link.', 'rotary-grants' ) );
        }
        if ( $app->organisation_id && $app->organisation_id === $organisation_id ) {
            return new \WP_Error( 'same', __( 'The application is already linked to that organisation.', 'rotary-grants' ) );
        }

        $answers = ( json_decode( (string) $app->answer_snapshot_json, true ) ?: [] )['answers'] ?? [];

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );

        $created = false;
        if ( $organisation_id === 0 ) {
            [ $clean, $errors ] = $this->validate( [
                'name'           => (string) ( $answers['organisation_name'] ?? $app->organisation_name ),
                'charity_number' => (string) ( $answers['charity_number'] ?? '' ),
                'town'           => (string) ( $answers['organisation_town'] ?? '' ),
                'postcode'       => (string) ( $answers['organisation_postcode'] ?? '' ),
                'website_url'    => (string) ( $answers['website_url'] ?? '' ),
            ] );
            if ( $errors->has_errors() ) {
                $wpdb->query( 'ROLLBACK' );
                return $errors;
            }
            $organisation_id = $this->insert( $clean );
            if ( is_wp_error( $organisation_id ) ) {
                $wpdb->query( 'ROLLBACK' );
                return $organisation_id;
            }
            $created = true;
        } else {
            // Lock the target so a concurrent merge can't move it under us.
            $target = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$this->table()} WHERE id = %d FOR UPDATE", $organisation_id ) );
            if ( ! $target || $target->status !== self::ACTIVE ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'not_active', __( 'That organisation does not exist or has been merged.', 'rotary-grants' ) );
            }
        }

        // The applicant as a contact of the organisation.
        $contacts = new ContactService();
        $name     = (string) ( $answers['contact_name'] ?? '' );
        $email    = (string) ( $answers['contact_email'] ?? '' );
        $existing = $name !== '' ? $contacts->find_current_match( $organisation_id, $name, $email ) : null;
        if ( $existing ) {
            $contact_id = (int) $existing->id;
        } elseif ( $name !== '' ) {
            $contact_id = $contacts->create( $organisation_id, [
                'name'  => $name,
                'role'  => (string) ( $answers['contact_role'] ?? '' ),
                'email' => $email,
                'phone' => (string) ( $answers['contact_phone'] ?? '' ),
            ], 'application', $application_id, false );
            if ( is_wp_error( $contact_id ) ) {
                $wpdb->query( 'ROLLBACK' );
                return $contact_id;
            }
        } else {
            $contact_id = 0;
        }

        $rows = $wpdb->update(
            $wpdb->prefix . 'grants_applications',
            [
                'organisation_id' => $organisation_id,
                'contact_id'      => $contact_id ?: null,
                'row_version'     => $expected_row_version + 1,
                'updated_at'      => SiteTime::now_utc(),
            ],
            [ 'id' => $application_id, 'row_version' => $expected_row_version ],
            [ '%d', '%d', '%d', '%s' ],
            [ '%d', '%d' ]
        );
        if ( $rows !== 1 ) {
            $wpdb->query( 'ROLLBACK' );
            return self::conflict();
        }

        if ( $contact_id ) {
            ( new PreferenceService() )->record_from_application( $contact_id, $app );
        }

        $wpdb->query( 'COMMIT' );

        AuditLogger::record( $app->organisation_id ? 'application_relinked' : 'application_linked', 'application', $application_id, array_filter( [
            'organisation_id'          => $organisation_id,
            'contact_id'               => $contact_id,
            'created_organisation'     => $created,
            'previous_organisation_id' => $app->organisation_id ?: null,
            'reason_given'             => $reason !== '' ? true : null,
        ], static fn( $v ) => $v !== null ) );
        if ( $reason !== '' ) {
            AmendmentLog::record( 'application_link', $application_id, [ 'organisation_id' => [ $app->organisation_id, $organisation_id ] ], $reason );
        }

        return [ 'organisation_id' => $organisation_id, 'contact_id' => $contact_id, 'created' => $created ];
    }

    // =========================================================================
    // Merging
    // =========================================================================

    /**
     * Merge organisation $from into $into (the one kept). Applications and
     * contacts move to $into in one transaction; $from is kept as a 'merged'
     * record pointing at $into, with the reason. Snapshots are untouched.
     *
     * @return array{applications:int, contacts:int}|\WP_Error
     */
    public function merge( int $from, int $into, int $expected_from_row_version, string $reason ): array|\WP_Error {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return self::forbidden();
        }
        $reason = trim( sanitize_textarea_field( $reason ) );
        if ( $reason === '' ) {
            return new \WP_Error( 'reason', __( 'Give a reason for the merge (e.g. "same group — renamed in 2024").', 'rotary-grants' ) );
        }
        if ( $from === $into ) {
            return new \WP_Error( 'same', __( 'Choose a different organisation to merge into.', 'rotary-grants' ) );
        }

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        // Lock both rows in id order so two opposite merges can't deadlock into a mess.
        $locked = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, status, row_version FROM {$this->table()} WHERE id IN (%d, %d) ORDER BY id FOR UPDATE",
            min( $from, $into ),
            max( $from, $into )
        ), OBJECT_K );
        $a = $locked[ $from ] ?? null;
        $b = $locked[ $into ] ?? null;
        if ( ! $a || ! $b || $a->status !== self::ACTIVE || $b->status !== self::ACTIVE ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'not_active', __( 'Both organisations must exist and not already be merged.', 'rotary-grants' ) );
        }
        if ( (int) $a->row_version !== $expected_from_row_version ) {
            $wpdb->query( 'ROLLBACK' );
            return self::conflict();
        }

        $now      = SiteTime::now_utc();
        $apps     = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}grants_applications SET organisation_id = %d, row_version = row_version + 1, updated_at = %s WHERE organisation_id = %d", $into, $now, $from ) );
        $contacts = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}grants_contacts SET organisation_id = %d, row_version = row_version + 1, updated_at = %s WHERE organisation_id = %d", $into, $now, $from ) );
        // Anything previously merged into $from now points straight at $into.
        $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET merged_into_id = %d WHERE merged_into_id = %d", $into, $from ) );
        $done = $wpdb->update(
            $this->table(),
            [
                'status'            => self::MERGED,
                'merged_into_id'    => $into,
                'merge_reason'      => $reason,
                'merged_at'         => $now,
                'merged_by_user_id' => get_current_user_id(),
                'row_version'       => $expected_from_row_version + 1,
                'updated_at'        => $now,
            ],
            [ 'id' => $from ],
            [ '%s', '%d', '%s', '%s', '%d', '%d', '%s' ],
            [ '%d' ]
        );
        if ( $apps === false || $contacts === false || $done !== 1 ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'db_error', __( 'The merge could not be completed. Nothing was changed.', 'rotary-grants' ) );
        }
        $wpdb->query( 'COMMIT' );

        AuditLogger::record( 'organisation_merged', 'organisation', $from, [
            'into'         => $into,
            'applications' => (int) $apps,
            'contacts'     => (int) $contacts,
        ] );
        return [ 'applications' => (int) $apps, 'contacts' => (int) $contacts ];
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** @param array<string,string> $clean */
    private function insert( array $clean ): int|\WP_Error {
        global $wpdb;
        $now = SiteTime::now_utc();
        $ok  = $wpdb->insert(
            $this->table(),
            $clean + [
                'name_key'           => NameMatcher::name_key( $clean['name'] ),
                'charity_key'        => NameMatcher::charity_key( $clean['charity_number'] ),
                'status'             => self::ACTIVE,
                'row_version'        => 1,
                'created_at'         => $now,
                'created_by_user_id' => get_current_user_id(),
                'updated_at'         => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ]
        );
        if ( $ok === false ) {
            return new \WP_Error( 'db_error', __( 'The organisation could not be saved.', 'rotary-grants' ) );
        }
        $id = (int) $wpdb->insert_id;
        AuditLogger::record( 'organisation_created', 'organisation', $id, [] );
        return $id;
    }

    /**
     * @param array<string,string> $input
     * @return array{0: array<string,string>, 1: \WP_Error}
     */
    private function validate( array $input ): array {
        $errors = new \WP_Error();
        $clean  = [];
        foreach ( self::FIELDS as $field => $max ) {
            $clean[ $field ] = trim( (string) preg_replace( '/\s+/u', ' ', wp_check_invalid_utf8( (string) ( $input[ $field ] ?? '' ) ) ) );
            if ( preg_match( '/<\s*[a-z!\/?]/i', $clean[ $field ] ) ) {
                $errors->add( 'org_' . $field, __( 'Please remove HTML from this field.', 'rotary-grants' ) );
            } elseif ( mb_strlen( $clean[ $field ] ) > $max ) {
                /* translators: %d: max characters */
                $errors->add( 'org_' . $field, sprintf( __( 'Please shorten this to %d characters or fewer.', 'rotary-grants' ), $max ) );
            }
        }
        if ( $clean['name'] === '' ) {
            $errors->add( 'org_name', __( 'Enter the organisation\'s name.', 'rotary-grants' ) );
        }
        if ( $clean['website_url'] !== '' ) {
            $scheme = strtolower( (string) wp_parse_url( $clean['website_url'], PHP_URL_SCHEME ) );
            if ( ! filter_var( $clean['website_url'], FILTER_VALIDATE_URL ) || ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
                $errors->add( 'org_website_url', __( 'Enter a full web address starting with https://, or leave it empty.', 'rotary-grants' ) );
            }
        }
        if ( $clean['postcode'] !== '' ) {
            $pc = NameMatcher::postcode_key( $clean['postcode'] );
            $clean['postcode'] = strlen( $pc ) > 3 ? substr( $pc, 0, -3 ) . ' ' . substr( $pc, -3 ) : $pc;
        }
        return [ $clean, $errors ];
    }

    private static function hydrate( object $row ): object {
        $row->id             = (int) $row->id;
        $row->row_version    = (int) $row->row_version;
        $row->merged_into_id = $row->merged_into_id === null ? null : (int) $row->merged_into_id;
        if ( isset( $row->application_count ) ) {
            $row->application_count = (int) $row->application_count;
        }
        return $row;
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'grants_organisations';
    }

    public static function forbidden(): \WP_Error {
        return new \WP_Error( 'forbidden', __( 'You do not have permission to manage organisations.', 'rotary-grants' ) );
    }

    public static function conflict(): \WP_Error {
        return new \WP_Error( 'conflict', __( 'Someone else changed this record while you were working on it. Reload the page and try again.', 'rotary-grants' ) );
    }
}
