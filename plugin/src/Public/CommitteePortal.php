<?php

namespace Rotary\Grants\Public;

use Rotary\Grants\Admin\CommitteeActions;
use Rotary\Grants\Services\ApplicationForm;
use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Services\AwardService;
use Rotary\Grants\Services\BudgetService;
use Rotary\Grants\Services\ConflictService;
use Rotary\Grants\Services\DecisionService;
use Rotary\Grants\Services\PaymentService;
use Rotary\Grants\Services\ReviewService;
use Rotary\Grants\Services\RoundService;
use Rotary\Grants\Services\RoundStatus;
use Rotary\Grants\Services\SettingsService;
use Rotary\Grants\Services\WorkflowService;

defined( 'ABSPATH' ) || exit;

/**
 * [rotary_grants_committee] — the committee's page on the website (DEC-019).
 *
 * Reviewers and decision makers sign in here and work without WordPress
 * admin: their rounds, applications, conflict declarations, reviews, notes
 * and decisions. Same WordPress accounts; every form posts to the same
 * admin-post handlers as WordPress admin (Admin\CommitteeActions), so the
 * services enforce exactly the same capabilities, nonces and conflict
 * gate. This class only gathers data and renders templates/portal/*.
 *
 * Review-only users (grants capabilities limited to access / review /
 * decide, and no WordPress editing rights) are sent here after login and
 * from WordPress admin (profile excepted), and don't see the admin bar.
 *
 * Views (query args on the page): home | round&round=N | declare&round=N |
 * application&id=N. The page is never cached.
 */
class CommitteePortal {

    public const SHORTCODE = 'rotary_grants_committee';

    /** Grants capabilities a "portal-only" user may hold. */
    private const PORTAL_CAPS = [ 'grants_access', 'grants_review', 'grants_decide' ];

    private static ?string $url = null;

    public function register(): void {
        add_shortcode( self::SHORTCODE, [ $this, 'render' ] );
        add_action( 'template_redirect', [ $this, 'prepare_page' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
        add_action( 'admin_init', [ $this, 'keep_out_of_admin' ] );
        add_filter( 'login_redirect', [ $this, 'login_redirect' ], 10, 3 );
        add_filter( 'show_admin_bar', [ $this, 'admin_bar' ] );
    }

    /** The committee page URL ('' if none is set up). */
    public static function url(): string {
        if ( self::$url !== null ) {
            return self::$url;
        }
        $id = (int) ( new SettingsService() )->get( SettingsService::COMMITTEE_PAGE_ID );
        if ( ! $id ) {
            global $wpdb;
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('publish','private') AND post_content LIKE %s ORDER BY ID LIMIT 1",
                '%[' . $wpdb->esc_like( self::SHORTCODE ) . '%'
            ) );
        }
        return self::$url = $id ? (string) get_permalink( $id ) : '';
    }

    /** True for a user whose only grants roles are access/review/decide and who can't edit content. */
    public static function is_portal_only( ?\WP_User $user = null ): bool {
        $user = $user ?? wp_get_current_user();
        if ( ! $user || ! $user->exists() || $user->has_cap( 'edit_posts' ) || $user->has_cap( 'manage_options' ) ) {
            return false;
        }
        if ( ! $user->has_cap( 'grants_review' ) && ! $user->has_cap( 'grants_decide' ) ) {
            return false;
        }
        foreach ( \Rotary\Grants\Core\Roles::ALL_CAPS as $cap ) {
            if ( $user->has_cap( $cap ) && ! in_array( $cap, self::PORTAL_CAPS, true ) ) {
                return false;
            }
        }
        return true;
    }

    // =========================================================================
    // Hooks
    // =========================================================================

    public function register_assets(): void {
        wp_register_style( 'grants-portal', GRANTS_PLUGIN_URL . 'assets/css/grants-portal.css', [], GRANTS_VERSION );
    }

    public function prepare_page(): void {
        $post = get_queried_object();
        if ( ! ( $post instanceof \WP_Post ) || ! has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
            return;
        }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow' );
    }

    /** Send portal-only users from WordPress admin to the committee page (profile and form handlers excepted). */
    public function keep_out_of_admin(): void {
        if ( wp_doing_ajax() || ! self::is_portal_only() || self::url() === '' ) {
            return;
        }
        global $pagenow;
        if ( in_array( $pagenow, [ 'admin-post.php', 'profile.php', 'admin-ajax.php' ], true ) ) {
            return;
        }
        wp_safe_redirect( self::url() );
        exit;
    }

    /** @param string $redirect @param string $requested @param \WP_User|\WP_Error $user */
    public function login_redirect( $redirect, $requested, $user ): string {
        if ( $user instanceof \WP_User && self::is_portal_only( $user ) && self::url() !== '' ) {
            return self::url();
        }
        return (string) $redirect;
    }

    public function admin_bar( bool $show ): bool {
        return self::is_portal_only() ? false : $show;
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    public function render(): string {
        wp_enqueue_style( 'grants-portal' );
        $base = self::url() ?: (string) get_permalink();

        if ( ! is_user_logged_in() ) {
            return $this->template( 'login', [ 'login_form' => wp_login_form( [ 'echo' => false, 'redirect' => $base ] ), 'lost_url' => wp_lostpassword_url( $base ) ] );
        }
        if ( ! ConflictService::is_committee() || ! current_user_can( 'grants_access' ) ) {
            return $this->template( 'denied', [ 'logout_url' => wp_logout_url( $base ) ] );
        }

        $common = [
            'base'         => $base,
            'user'         => wp_get_current_user(),
            'logout_url'   => wp_logout_url( $base ),
            'profile_url'  => admin_url( 'profile.php' ),
            'admin_url'    => self::is_portal_only() ? '' : admin_url( 'admin.php?page=grants-dashboard' ),
            'notice'       => sanitize_key( $_GET['grants_notice'] ?? '' ),
            'nonce_action' => CommitteeActions::NONCE_ACTION,
            'nonce_field'  => CommitteeActions::NONCE_FIELD,
        ];
        $view = sanitize_key( $_GET['view'] ?? 'home' );

        return match ( $view ) {
            'round'       => $this->round_view( $common ),
            'declare'     => $this->declare_view( $common ),
            'application' => $this->application_view( $common ),
            default       => $this->home_view( $common ),
        };
    }

    /** @param array<string,mixed> $c */
    private function home_view( array $c ): string {
        $conflicts = new ConflictService();
        $rows      = [];
        foreach ( ( new RoundService() )->all() as $round ) {
            if ( ! in_array( $round->status, [ RoundStatus::OPEN, RoundStatus::CLOSED ], true ) ) {
                continue;
            }
            $apps     = ( new ApplicationService() )->list( $round->id, null, null, [ 'hide_duplicates' => true ] );
            $apps     = array_filter( $apps, static fn( $a ) => $a->status !== ApplicationStatus::WITHDRAWN );
            $mine     = $conflicts->for_user_in_round( $round->id );
            $reviewed = 0;
            $reviews  = new ReviewService();
            foreach ( $apps as $a ) {
                $reviewed += $reviews->current_for( $a->id, get_current_user_id() ) ? 1 : 0;
            }
            $rows[] = [
                'round'      => $round,
                'total'      => count( $apps ),
                'undeclared' => count( array_filter( $apps, static fn( $a ) => ! isset( $mine[ $a->id ] ) ) ),
                'reviewed'   => $reviewed,
                'open'       => count( array_filter( $apps, static fn( $a ) => ApplicationStatus::is_open( $a->status ) ) ),
            ];
        }
        return $this->template( 'home', $c + [ 'rounds' => $rows ] );
    }

    /** @param array<string,mixed> $c */
    private function round_view( array $c ): string {
        $round = ( new RoundService() )->find( absint( $_GET['round'] ?? 0 ) );
        if ( ! $round ) {
            return $this->home_view( $c );
        }
        $conflicts = new ConflictService();
        $reviews   = new ReviewService();
        $decisions = new DecisionService();
        $apps      = ( new ApplicationService() )->list( $round->id, null, null, [ 'hide_duplicates' => true ] );
        $counts    = $reviews->counts( array_map( static fn( $a ) => $a->id, $apps ) );
        $rows      = [];
        foreach ( $apps as $a ) {
            $eff    = $decisions->effective( $a->id );
            $rows[] = [
                'app'         => $a,
                'declaration' => $conflicts->status_for( $a->id ),
                'my_review'   => (bool) $reviews->current_for( $a->id, get_current_user_id() ),
                'reviews'     => $counts[ $a->id ] ?? 0,
                'decision'    => $eff ? DecisionService::label( $eff->decision_type ) : '',
            ];
        }
        return $this->template( 'round', $c + [ 'round' => $round, 'rows' => $rows, 'budget' => ( new BudgetService() )->summary( $round ) ] );
    }

    /** @param array<string,mixed> $c */
    private function declare_view( array $c ): string {
        $round = ( new RoundService() )->find( absint( $_GET['round'] ?? 0 ) );
        if ( ! $round ) {
            return $this->home_view( $c );
        }
        $errors = CommitteeActions::take_error();
        return $this->template( 'declare', $c + [
            'round'        => $round,
            'applications' => array_values( array_filter(
                ( new ApplicationService() )->list( $round->id, null, null, [ 'hide_duplicates' => true ] ),
                static fn( $a ) => $a->status !== ApplicationStatus::WITHDRAWN
            ) ),
            'mine'         => ( new ConflictService() )->for_user_in_round( $round->id ),
            'errors'       => is_array( $errors ) ? $errors : [],
        ] );
    }

    /** @param array<string,mixed> $c */
    private function application_view( array $c ): string {
        $apps = new ApplicationService();
        $app  = $apps->find( absint( $_GET['id'] ?? 0 ) );
        if ( ! $app ) {
            return $this->home_view( $c );
        }
        $conflicts   = new ConflictService();
        $declaration = $conflicts->current( $app->id );
        $is_clear    = $conflicts->is_clear( $app->id );
        $reviews     = $is_clear ? ( new ReviewService() )->for_application( $app->id ) : [];
        $notes       = $is_clear ? ( new WorkflowService() )->notes_for( $app->id ) : [];
        $decisions   = new DecisionService();
        $award       = ( new AwardService() )->for_application( $app->id );
        $round       = ( new RoundService() )->find( $app->round_id );
        $history     = [];
        if ( $app->organisation_id ) {
            foreach ( $apps->list( null, null, $app->organisation_id ) as $h ) {
                if ( $h->id === $app->id ) {
                    continue;
                }
                $aw        = ( new AwardService() )->for_application( $h->id );
                $history[] = [ 'app' => $h, 'award' => $aw, 'paid' => $aw ? ( new PaymentService() )->net_paid( $aw->id ) : 0 ];
            }
        }
        $error = CommitteeActions::take_error();

        return $this->template( 'application', $c + [
            'app'              => $app,
            'snapshot'         => json_decode( (string) $app->answer_snapshot_json, true ) ?: [],
            'labels'           => ApplicationForm::labels(),
            'round'            => $round,
            'history'          => $history,
            'declaration'      => $declaration,
            'decl_status'      => $declaration ? $declaration->declaration : ConflictService::UNDECLARED,
            'is_clear'         => $is_clear,
            'reviews'          => is_wp_error( $reviews ) ? [] : $reviews,
            'my_review'        => $is_clear ? ( new ReviewService() )->current_for( $app->id, get_current_user_id() ) : null,
            'notes'            => is_wp_error( $notes ) ? [] : array_values( array_filter( $notes, static fn( $n ) => $n->kind !== 'info_request' || $n->sent_at !== null ) ),
            'checks'           => ReviewService::checks(),
            'can_review'       => ReviewService::can_review() && ApplicationStatus::is_open( $app->status ),
            'can_decide'       => current_user_can( 'grants_decide' ),
            'award'            => $award,
            'award_conditions' => $award ? ( new AwardService() )->conditions( $award->id ) : [],
            'award_paid'       => $award ? ( new PaymentService() )->net_paid( $award->id ) : 0,
            'decision_history' => $is_clear ? $decisions->history( $app->id ) : [],
            'budget'           => $round ? ( new BudgetService() )->summary( $round ) : null,
            'decision_state'   => CommitteeActions::take_decision_state( $app->id ),
            'error'            => is_string( $error ) ? $error : '',
        ] );
    }

    /** @param array<string,mixed> $vars */
    private function template( string $name, array $vars ): string {
        extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract — variables documented in each template
        ob_start();
        echo '<div class="grants-portal">';
        include GRANTS_PLUGIN_DIR . 'templates/portal/' . $name . '.php';
        echo '</div>';
        return (string) ob_get_clean();
    }
}
