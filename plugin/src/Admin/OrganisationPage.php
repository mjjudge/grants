<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\AmendmentLog;
use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\ContactService;
use Rotary\Grants\Services\OrganisationService;
use Rotary\Grants\Services\PreferenceService;

defined( 'ABSPATH' ) || exit;

/**
 * Organisations: list/search (grants_access), view with contacts,
 * permissions, application history and correction history (grants_access);
 * add, correct, merge, manage contacts and permissions
 * (grants_manage_organisations).
 *
 * Routing:
 *   /wp-admin/admin.php?page=grants-organisations[&q=search]
 *   /wp-admin/admin.php?page=grants-organisations&action=view&id=N
 *   /wp-admin/admin.php?page=grants-organisations-add
 *   POST admin-post.php action=grants_org_save | grants_org_merge |
 *        grants_contact_save | grants_contact_end |
 *        grants_pref_record | grants_pref_withdraw
 */
class OrganisationPage {

    private const NONCE_ACTION = 'grants_organisation';
    private const NONCE_FIELD  = 'grants_org_nonce';

    public function register(): void {
        foreach ( [ 'org_save', 'org_merge', 'contact_save', 'contact_end', 'pref_record', 'pref_withdraw' ] as $action ) {
            add_action( 'admin_post_grants_' . $action, [ $this, 'handle_' . $action ] );
        }
    }

    // -------------------------------------------------------------------------
    // Screens
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! current_user_can( 'grants_access' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        if ( sanitize_key( $_GET['action'] ?? '' ) === 'view' ) {
            $this->render_view();
            return;
        }
        $query         = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
        $organisations = ( new OrganisationService() )->search( $query );
        $can_manage    = current_user_can( OrganisationService::CAPABILITY );
        $base_url      = add_query_arg( 'page', 'grants-organisations', admin_url( 'admin.php' ) );
        $add_url       = add_query_arg( 'page', 'grants-organisations-add', admin_url( 'admin.php' ) );
        include GRANTS_PLUGIN_DIR . 'templates/admin/organisation-list.php';
    }

    public function render_add(): void {
        if ( ! current_user_can( OrganisationService::CAPABILITY ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        [ $values, $errors ] = $this->take_state( 0 );
        $organisation = null;
        $nonce_action = self::NONCE_ACTION;
        $nonce_field  = self::NONCE_FIELD;
        $list_url     = add_query_arg( 'page', 'grants-organisations', admin_url( 'admin.php' ) );
        include GRANTS_PLUGIN_DIR . 'templates/admin/organisation-add.php';
    }

    private function render_view(): void {
        $orgs         = new OrganisationService();
        $organisation = $orgs->find( absint( $_GET['id'] ?? 0 ) );
        if ( ! $organisation ) {
            wp_die( esc_html__( 'Organisation not found.', 'rotary-grants' ), 404 );
        }
        $merged_into  = $organisation->merged_into_id ? $orgs->find( $organisation->merged_into_id ) : null;
        $contacts     = ( new ContactService() )->for_organisation( $organisation->id );
        $prefs        = new PreferenceService();
        $preferences  = [];
        foreach ( $contacts as $c ) {
            $preferences[ (int) $c->id ] = [ 'current' => $prefs->current( (int) $c->id ), 'history' => $prefs->history( (int) $c->id ) ];
        }
        $applications = ( new ApplicationService() )->list( null, null, $organisation->id );
        $amendments   = AmendmentLog::for_entity( 'organisation', $organisation->id );
        $merge_q      = sanitize_text_field( wp_unslash( $_GET['merge_q'] ?? '' ) );
        $merge_hits   = $merge_q !== '' ? array_filter( $orgs->search( $merge_q, 20 ), static fn( $o ) => $o->id !== $organisation->id ) : [];
        $can_manage   = current_user_can( OrganisationService::CAPABILITY );
        [ $values, $errors ] = $this->take_state( $organisation->id );
        $notice       = sanitize_key( $_GET['grants_notice'] ?? '' );
        $nonce_action = self::NONCE_ACTION;
        $nonce_field  = self::NONCE_FIELD;
        $list_url     = add_query_arg( 'page', 'grants-organisations', admin_url( 'admin.php' ) );
        $view_url     = add_query_arg( [ 'page' => 'grants-organisations', 'action' => 'view', 'id' => $organisation->id ], admin_url( 'admin.php' ) );
        $apps_url     = add_query_arg( 'page', 'grants-applications', admin_url( 'admin.php' ) );
        include GRANTS_PLUGIN_DIR . 'templates/admin/organisation-view.php';
    }

    // -------------------------------------------------------------------------
    // Handlers — capability, nonce, delegate, redirect
    // -------------------------------------------------------------------------

    public function handle_org_save(): void {
        $this->guard();
        $id    = absint( $_POST['organisation_id'] ?? 0 );
        $input = [];
        foreach ( [ 'name', 'charity_number', 'town', 'postcode', 'website_url' ] as $f ) {
            $input[ $f ] = sanitize_text_field( wp_unslash( $_POST[ $f ] ?? '' ) );
        }
        $service = new OrganisationService();
        $result  = $id
            ? $service->update( $id, $input, absint( $_POST['row_version'] ?? 0 ), sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) )
            : $service->create( $input );
        if ( is_wp_error( $result ) ) {
            $this->fail( $id, $result, $input );
        }
        $this->done( $id ?: (int) $result, $id ? 'saved' : 'created' );
    }

    public function handle_org_merge(): void {
        $this->guard();
        $from   = absint( $_POST['organisation_id'] ?? 0 );
        $result = ( new OrganisationService() )->merge(
            $from,
            absint( $_POST['into_id'] ?? 0 ),
            absint( $_POST['row_version'] ?? 0 ),
            sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) )
        );
        if ( is_wp_error( $result ) ) {
            $this->fail( $from, $result );
        }
        $this->done( absint( $_POST['into_id'] ?? 0 ), 'merged' );
    }

    public function handle_contact_save(): void {
        $this->guard();
        $org_id     = absint( $_POST['organisation_id'] ?? 0 );
        $contact_id = absint( $_POST['contact_id'] ?? 0 );
        $input      = [];
        foreach ( [ 'name', 'role', 'email', 'phone' ] as $f ) {
            $input[ $f ] = sanitize_text_field( wp_unslash( $_POST[ 'contact_' . $f ] ?? '' ) );
        }
        $service = new ContactService();
        if ( $contact_id ) {
            $contact = $service->find( $contact_id );
            if ( ! $contact || $contact->organisation_id !== $org_id ) {
                $this->fail( $org_id, new \WP_Error( 'mismatch', __( 'That contact does not belong to this organisation.', 'rotary-grants' ) ) );
            }
            $result = $service->update( $contact_id, $input, absint( $_POST['row_version'] ?? 0 ), sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
        } else {
            $org    = ( new OrganisationService() )->find( $org_id );
            $result = ( $org && $org->status === OrganisationService::ACTIVE )
                ? $service->create( $org_id, $input )
                : new \WP_Error( 'not_active', __( 'Contacts can only be added to an active organisation.', 'rotary-grants' ) );
        }
        if ( is_wp_error( $result ) ) {
            $this->fail( $org_id, $result, array_combine( array_map( static fn( $k ) => 'contact_' . $k, array_keys( $input ) ), $input ) + [ 'contact_id' => (string) $contact_id ] );
        }
        $this->done( $org_id, 'contact_saved' );
    }

    public function handle_contact_end(): void {
        $this->guard();
        $org_id = absint( $_POST['organisation_id'] ?? 0 );
        $result = $this->for_own_contact( $org_id, static fn( int $cid ) => ( new ContactService() )->end( $cid, sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) ) );
        is_wp_error( $result ) ? $this->fail( $org_id, $result ) : $this->done( $org_id, 'contact_ended' );
    }

    public function handle_pref_record(): void {
        $this->guard();
        $org_id = absint( $_POST['organisation_id'] ?? 0 );
        $result = $this->for_own_contact( $org_id, static fn( int $cid ) => ( new PreferenceService() )->record_by_staff( $cid, sanitize_text_field( wp_unslash( $_POST['evidence'] ?? '' ) ) ) );
        is_wp_error( $result ) ? $this->fail( $org_id, $result ) : $this->done( $org_id, 'pref_recorded' );
    }

    public function handle_pref_withdraw(): void {
        $this->guard();
        $org_id = absint( $_POST['organisation_id'] ?? 0 );
        $result = $this->for_own_contact( $org_id, static fn( int $cid ) => ( new PreferenceService() )->withdraw( $cid, sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) ) ) );
        is_wp_error( $result ) ? $this->fail( $org_id, $result ) : $this->done( $org_id, 'pref_withdrawn' );
    }

    // -------------------------------------------------------------------------

    private function guard(): void {
        if ( ! current_user_can( OrganisationService::CAPABILITY ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
    }

    /** Run $fn for the posted contact only if it belongs to $org_id (object-level check). */
    private function for_own_contact( int $org_id, callable $fn ): mixed {
        $contact = ( new ContactService() )->find( absint( $_POST['contact_id'] ?? 0 ) );
        if ( ! $contact || $contact->organisation_id !== $org_id ) {
            return new \WP_Error( 'mismatch', __( 'That contact does not belong to this organisation.', 'rotary-grants' ) );
        }
        return $fn( $contact->id );
    }

    /** @param array<string,string> $values */
    private function fail( int $org_id, \WP_Error $error, array $values = [] ): never {
        $errors = [];
        foreach ( $error->get_error_codes() as $code ) {
            $errors[ $code ] = $error->get_error_message( $code );
        }
        set_transient( self::state_key(), [ 'org_id' => $org_id, 'values' => $values, 'errors' => $errors ], 300 );
        $args = $org_id
            ? [ 'page' => 'grants-organisations', 'action' => 'view', 'id' => $org_id, 'grants_notice' => 'error' ]
            : [ 'page' => 'grants-organisations-add', 'grants_notice' => 'error' ];
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private function done( int $org_id, string $notice ): never {
        wp_safe_redirect( add_query_arg( [ 'page' => 'grants-organisations', 'action' => 'view', 'id' => $org_id, 'grants_notice' => $notice ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /** @return array{0: array<string,string>, 1: array<string,string>} */
    private function take_state( int $org_id ): array {
        $state = get_transient( self::state_key() );
        if ( is_array( $state ) && (int) $state['org_id'] === $org_id ) {
            delete_transient( self::state_key() );
            return [ (array) $state['values'], (array) $state['errors'] ];
        }
        return [ [], [] ];
    }

    private static function state_key(): string {
        return 'grants_org_form_' . get_current_user_id();
    }
}
