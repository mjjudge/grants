<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\ApplicationForm;
use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Services\AwardService;
use Rotary\Grants\Services\BudgetService;
use Rotary\Grants\Services\DecisionService;
use Rotary\Grants\Services\ConflictService;
use Rotary\Grants\Services\ContactService;
use Rotary\Grants\Services\ReviewService;
use Rotary\Grants\Services\WorkflowService;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Services\OrganisationService;
use Rotary\Grants\Services\RoundService;

defined( 'ABSPATH' ) || exit;

/**
 * Application list and detail view (grants_access), plus linking an
 * application to an organisation (grants_manage_organisations).
 *
 * Routing:
 *   /wp-admin/admin.php?page=grants-applications[&round_id=N][&status=S][&organisation_id=N]
 *   /wp-admin/admin.php?page=grants-applications&action=view&id=N[&org_q=search]
 *   POST admin-post.php action=grants_application_link
 */
class ApplicationListPage {

    private const NONCE_ACTION = 'grants_application_link';
    private const NONCE_FIELD  = 'grants_link_nonce';

    public function register(): void {
        add_action( 'admin_post_grants_application_link', [ $this, 'handle_link' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'grants_access' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }

        $service = new ApplicationService();

        if ( sanitize_key( $_GET['action'] ?? '' ) === 'view' ) {
            $this->render_view( $service );
            return;
        }

        $round_id        = absint( $_GET['round_id'] ?? 0 );
        $organisation_id = absint( $_GET['organisation_id'] ?? 0 );
        $status          = sanitize_key( $_GET['status'] ?? '' );
        $status          = in_array( $status, ApplicationStatus::all(), true ) ? $status : '';
        $min_raw         = sanitize_text_field( wp_unslash( $_GET['min'] ?? '' ) );
        $max_raw         = sanitize_text_field( wp_unslash( $_GET['max'] ?? '' ) );
        $town            = sanitize_text_field( wp_unslash( $_GET['town'] ?? '' ) );
        $show_duplicates = ! empty( $_GET['duplicates'] );
        $min_pence       = $min_raw !== '' && ! is_wp_error( Money::parse_gbp( $min_raw ) ) ? Money::parse_gbp( $min_raw ) : null;
        $max_pence       = $max_raw !== '' && ! is_wp_error( Money::parse_gbp( $max_raw ) ) ? Money::parse_gbp( $max_raw ) : null;
        $applications    = $service->list( $round_id ?: null, $status ?: null, $organisation_id ?: null, [
            'min_pence'       => $min_pence,
            'max_pence'       => $max_pence,
            'town'            => $town,
            'hide_duplicates' => ! $show_duplicates,
        ] );
        $review_counts   = ( new ReviewService() )->counts( array_map( static fn( $a ) => $a->id, $applications ) );
        $is_committee    = ConflictService::is_committee();
        $my_declarations = [];
        if ( $is_committee ) {
            $conflicts = new ConflictService();
            foreach ( $applications as $a ) {
                $my_declarations[ $a->id ] = $conflicts->status_for( $a->id );
            }
        }
        $declarations_url = add_query_arg( [ 'page' => 'grants-declarations', 'round_id' => $round_id ], admin_url( 'admin.php' ) );
        $rounds          = ( new RoundService() )->all();
        $base_url        = add_query_arg( 'page', 'grants-applications', admin_url( 'admin.php' ) );
        $add_url         = current_user_can( OrganisationService::CAPABILITY ) ? add_query_arg( 'page', 'grants-applications-add', admin_url( 'admin.php' ) ) : '';

        include GRANTS_PLUGIN_DIR . 'templates/admin/application-list.php';
    }

    private function render_view( ApplicationService $service ): void {
        $application = $service->find( absint( $_GET['id'] ?? 0 ) );
        if ( ! $application ) {
            wp_die( esc_html__( 'Application not found.', 'rotary-grants' ), 404 );
        }
        $orgs         = new OrganisationService();
        $snapshot     = json_decode( (string) $application->answer_snapshot_json, true ) ?: [];
        $labels       = ApplicationForm::labels();
        $list_url     = add_query_arg( 'page', 'grants-applications', admin_url( 'admin.php' ) );
        $org_url      = add_query_arg( 'page', 'grants-organisations', admin_url( 'admin.php' ) );
        $can_link     = current_user_can( OrganisationService::CAPABILITY );
        $organisation = $application->organisation_id ? $orgs->find( $application->organisation_id ) : null;
        $contact      = $application->contact_id ? ( new ContactService() )->find( $application->contact_id ) : null;
        $siblings     = $service->same_round_siblings( $application );
        $org_query    = sanitize_text_field( wp_unslash( $_GET['org_q'] ?? '' ) );
        $candidates   = $can_link && ! $organisation ? $orgs->candidates_for_application( $application ) : [];
        $search       = $can_link && $org_query !== '' ? $orgs->search( $org_query, 20 ) : [];
        $entered_by   = $application->entered_by_user_id ? get_userdata( (int) $application->entered_by_user_id ) : null;
        $notice       = sanitize_key( $_GET['grants_notice'] ?? '' );
        $error        = $this->take_error();
        $committee_error = CommitteeActions::take_error();
        $error        = $error !== '' ? $error : ( is_string( $committee_error ) ? $committee_error : '' );

        // Committee panel (DEC-013): discussion only after a "no conflict" declaration.
        $conflicts        = new ConflictService();
        $is_committee     = ConflictService::is_committee();
        $declaration      = $is_committee ? $conflicts->current( $application->id ) : null;
        $decl_status      = $declaration ? $declaration->declaration : ConflictService::UNDECLARED;
        $is_clear         = $decl_status === ConflictService::NONE;
        $reviews          = $is_clear ? ( new ReviewService() )->for_application( $application->id ) : [];
        $reviews          = is_wp_error( $reviews ) ? [] : $reviews;
        $my_review        = $is_clear ? ( new ReviewService() )->current_for( $application->id, get_current_user_id() ) : null;
        $notes            = $is_clear ? ( new WorkflowService() )->notes_for( $application->id ) : [];
        $notes            = is_wp_error( $notes ) ? [] : $notes;
        $can_review       = ReviewService::can_review() && ApplicationStatus::is_open( $application->status );
        $can_progress     = WorkflowService::can_progress();
        $can_withdraw     = current_user_can( 'grants_manage_organisations' );
        $transitions      = ApplicationStatus::targets( $application->status );
        $checks           = ReviewService::checks();
        $history          = $application->organisation_id
            ? array_values( array_filter( $service->list( null, null, $application->organisation_id ), static fn( $a ) => $a->id !== $application->id ) )
            : [];
        $duplicate_of     = $application->duplicate_of_id ? $service->find( $application->duplicate_of_id ) : null;
        // Decision panel (G07).
        $decisions        = new DecisionService();
        $can_decide       = current_user_can( 'grants_decide' );
        $award            = ( new AwardService() )->for_application( $application->id );
        $award_conditions = $award ? ( new AwardService() )->conditions( $award->id ) : [];
        $award_paid       = $award ? ( new AwardService() )->net_paid( $award->id ) : 0;
        $effective        = $decisions->effective( $application->id );
        $decision_history = $is_clear ? $decisions->history( $application->id ) : [];
        $round_row        = ( new RoundService() )->find( $application->round_id );
        $budget           = $round_row ? ( new BudgetService() )->summary( $round_row ) : null;
        $decision_state   = CommitteeActions::take_decision_state( $application->id );
        $notice_template  = $effective ? $decisions->notice_template( $application, $effective ) : '';
        $notice_sent      = (bool) array_filter( $notes, static fn( $n ) => $n->kind === 'decision_notice' && $n->sent_at !== null );
        $can_send_notice  = WorkflowService::can_send_decision();
        $can_conditions   = current_user_can( 'grants_manage_organisations' ) || $can_decide;
        $c_nonce_action   = CommitteeActions::NONCE_ACTION;
        $c_nonce_field    = CommitteeActions::NONCE_FIELD;
        $nonce_action = self::NONCE_ACTION;
        $nonce_field  = self::NONCE_FIELD;

        include GRANTS_PLUGIN_DIR . 'templates/admin/application-view.php';
    }

    public function handle_link(): void {
        if ( ! current_user_can( OrganisationService::CAPABILITY ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

        $app_id = absint( $_POST['application_id'] ?? 0 );
        $result = ( new OrganisationService() )->link_application(
            $app_id,
            absint( $_POST['organisation_id'] ?? 0 ),
            absint( $_POST['row_version'] ?? 0 ),
            sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) )
        );

        $args = [ 'page' => 'grants-applications', 'action' => 'view', 'id' => $app_id ];
        if ( is_wp_error( $result ) ) {
            set_transient( self::error_key(), implode( ' ', $result->get_error_messages() ), 300 );
            $args['grants_notice'] = 'error';
        } else {
            $args['grants_notice'] = $result['created'] ? 'linked_new' : 'linked';
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private function take_error(): string {
        $msg = (string) get_transient( self::error_key() );
        delete_transient( self::error_key() );
        return $msg;
    }

    private static function error_key(): string {
        return 'grants_app_error_' . get_current_user_id();
    }
}
