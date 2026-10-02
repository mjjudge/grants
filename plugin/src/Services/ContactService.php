<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Organisation contacts over time.
 *
 * A different person is a new contact; the previous one is ended with
 * active_until rather than overwritten, so history survives. Corrections to
 * the same person (typo, new phone) are amendments recorded with old and new
 * values. Contact edits never touch application snapshots.
 *
 * Mutations require grants_manage_organisations.
 */
class ContactService {

    /** Editable fields: field => max length. */
    private const FIELDS = [ 'name' => 150, 'role' => 150, 'email' => 254, 'phone' => 50 ];

    public function find( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
        if ( $row ) {
            $row->id              = (int) $row->id;
            $row->organisation_id = (int) $row->organisation_id;
            $row->row_version     = (int) $row->row_version;
        }
        return $row ?: null;
    }

    /**
     * @return object[] Current contacts first, then former ones, newest first.
     */
    public function for_organisation( int $organisation_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE organisation_id = %d
             ORDER BY (active_until IS NULL) DESC, id DESC",
            $organisation_id
        ) );
    }

    /**
     * @param array<string,string> $input name, role, email, phone
     * @return int|\WP_Error
     */
    public function create( int $organisation_id, array $input, string $source = 'staff', ?int $application_id = null, bool $check_cap = true ): int|\WP_Error {
        if ( $check_cap && ! current_user_can( OrganisationService::CAPABILITY ) ) {
            return OrganisationService::forbidden();
        }
        [ $clean, $errors ] = $this->validate( $input );
        if ( $errors->has_errors() ) {
            return $errors;
        }
        global $wpdb;
        $now = SiteTime::now_utc();
        $ok  = $wpdb->insert(
            $this->table(),
            $clean + [
                'organisation_id'       => $organisation_id,
                'active_from'           => $now,
                'source'                => $source,
                'source_application_id' => $application_id,
                'row_version'           => 1,
                'created_at'            => $now,
                'created_by_user_id'    => get_current_user_id(),
                'updated_at'            => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s' ]
        );
        if ( $ok === false ) {
            return new \WP_Error( 'db_error', __( 'The contact could not be saved.', 'rotary-grants' ) );
        }
        $id = (int) $wpdb->insert_id;
        AuditLogger::record( 'contact_created', 'contact', $id, [ 'organisation_id' => $organisation_id, 'source' => $source, 'application_id' => $application_id ] );
        return $id;
    }

    /**
     * Correct the same person's details. Records an amendment.
     *
     * @param array<string,string> $input
     * @return true|\WP_Error
     */
    public function update( int $id, array $input, int $expected_row_version, string $reason ): true|\WP_Error {
        if ( ! current_user_can( OrganisationService::CAPABILITY ) ) {
            return OrganisationService::forbidden();
        }
        $contact = $this->find( $id );
        if ( ! $contact ) {
            return new \WP_Error( 'not_found', __( 'Contact not found.', 'rotary-grants' ) );
        }
        if ( $contact->active_until !== null ) {
            return new \WP_Error( 'inactive', __( 'A former contact cannot be edited. Add a new contact instead.', 'rotary-grants' ) );
        }
        [ $clean, $errors ] = $this->validate( $input );
        if ( $errors->has_errors() ) {
            return $errors;
        }
        $changes = [];
        foreach ( $clean as $field => $value ) {
            if ( (string) $contact->$field !== $value ) {
                $changes[ $field ] = [ $contact->$field, $value ];
            }
        }
        if ( ! $changes ) {
            return true;
        }

        global $wpdb;
        $rows = $wpdb->update(
            $this->table(),
            $clean + [ 'row_version' => $expected_row_version + 1, 'updated_at' => SiteTime::now_utc() ],
            [ 'id' => $id, 'row_version' => $expected_row_version ],
            [ '%s', '%s', '%s', '%s', '%d', '%s' ],
            [ '%d', '%d' ]
        );
        if ( $rows !== 1 ) {
            return OrganisationService::conflict();
        }
        AmendmentLog::record( 'contact', $id, $changes, trim( sanitize_textarea_field( $reason ) ) );
        AuditLogger::record( 'contact_updated', 'contact', $id, [ 'changed' => array_keys( $changes ) ] );
        return true;
    }

    /**
     * Mark a contact as no longer acting for the organisation.
     *
     * @return true|\WP_Error
     */
    public function end( int $id, string $reason ): true|\WP_Error {
        if ( ! current_user_can( OrganisationService::CAPABILITY ) ) {
            return OrganisationService::forbidden();
        }
        $contact = $this->find( $id );
        if ( ! $contact || $contact->active_until !== null ) {
            return new \WP_Error( 'not_active', __( 'That contact is not current.', 'rotary-grants' ) );
        }
        global $wpdb;
        $now = SiteTime::now_utc();
        $wpdb->update(
            $this->table(),
            [ 'active_until' => $now, 'row_version' => $contact->row_version + 1, 'updated_at' => $now ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );
        AmendmentLog::record( 'contact', $id, [ 'active_until' => [ null, $now ] ], trim( sanitize_textarea_field( $reason ) ) );
        AuditLogger::record( 'contact_ended', 'contact', $id, [ 'organisation_id' => $contact->organisation_id ] );
        return true;
    }

    /**
     * Current contact at the organisation for the same person (same name
     * AND same email, case-insensitive), if any — used so that linking a
     * second application from the same person doesn't duplicate them.
     */
    public function find_current_match( int $organisation_id, string $name, string $email ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE organisation_id = %d AND active_until IS NULL AND LOWER(name) = LOWER(%s) AND LOWER(email) = LOWER(%s)
             ORDER BY id DESC LIMIT 1",
            $organisation_id,
            trim( $name ),
            trim( $email )
        ) );
        return $row ?: null;
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
                $errors->add( 'contact_' . $field, __( 'Please remove HTML from this field.', 'rotary-grants' ) );
            } elseif ( mb_strlen( $clean[ $field ] ) > $max ) {
                /* translators: %d: max characters */
                $errors->add( 'contact_' . $field, sprintf( __( 'Please shorten this to %d characters or fewer.', 'rotary-grants' ), $max ) );
            }
        }
        if ( $clean['name'] === '' ) {
            $errors->add( 'contact_name', __( 'Enter the contact\'s name.', 'rotary-grants' ) );
        }
        if ( $clean['email'] !== '' && ! is_email( $clean['email'] ) ) {
            $errors->add( 'contact_email', __( 'Enter a valid email address, or leave it empty.', 'rotary-grants' ) );
        }
        return [ $clean, $errors ];
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'grants_contacts';
    }
}
