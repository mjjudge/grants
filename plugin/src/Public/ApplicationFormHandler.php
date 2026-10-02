<?php

namespace Rotary\Grants\Public;

use Rotary\Grants\Services\ApplicationForm;
use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\RoundService;
use Rotary\Grants\Services\SettingsService;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * [rotary_grant_application] — the public application form.
 *
 * Which round: fund="Tree of Light" uses that fund's open round (at most one
 * — DEC-009), so a page keeps working year after year; round="12" pins one
 * round; with neither, the single open round is used if there is exactly one.
 *
 * Request flow (POST goes to the page itself and is handled on `init`):
 *   1. body-size cap, same-origin check, WordPress nonce
 *   2. session-bound token: HMAC(session cookie | round id | submission key)
 *      — the round id is therefore server-signed, never trusted from the
 *      client, and a token copied to another browser is useless
 *   3. honeypot (silently dropped), rate limit per hashed IP
 *   4. allowlisted fields → ApplicationForm::validate()
 *   5. ApplicationService::submit() — server-time closure check + idempotent
 *      transaction
 * Errors re-render the form in the same request with the answers intact, so
 * no personal data is parked in transients or URLs. Success redirects to the
 * page with ?grants_submitted=1 and the receipt is read from a short-lived
 * transient keyed by the session cookie, so a receipt is never retrievable
 * by reference or by URL alone.
 *
 * Pages containing the shortcode are sent no-cache headers and
 * DONOTCACHEPAGE — see deployment-notes/CACHE_EXCLUSIONS.md.
 */
class ApplicationFormHandler {

    public const SHORTCODE = 'rotary_grant_application';

    private const COOKIE         = 'grants_form_session';
    private const NONCE_ACTION   = 'grants_apply';
    private const NONCE_FIELD    = 'grants_apply_nonce';
    private const HONEYPOT_FIELD = 'grants_contact_fax';
    private const MAX_BODY_BYTES = 65536;
    private const RATE_MAX       = 20;
    private const RECEIPT_TTL    = 1800;
    private const SUBMITTED_ARG  = 'grants_submitted';

    /**
     * Outcome of a POST handled earlier in this request, for the shortcode.
     *
     * @var array{round_id:int, values:array<string,string>, errors:array<string,string>, message:string}|null
     */
    private static ?array $outcome = null;

    private static ?string $session = null;

    public function register(): void {
        add_shortcode( self::SHORTCODE, [ $this, 'render' ] );
        add_action( 'init', [ $this, 'maybe_handle_submission' ] );
        add_action( 'template_redirect', [ $this, 'prepare_page' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
    }

    public function register_assets(): void {
        wp_register_style( 'grants-public', GRANTS_PLUGIN_URL . 'assets/css/grants-public.css', [], GRANTS_VERSION );
    }

    /** Before output: no caching, and a session cookie for the token. */
    public function prepare_page(): void {
        $post = get_queried_object();
        if ( ! ( $post instanceof \WP_Post ) || ! has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
            return;
        }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();
        self::session();
    }

    // =========================================================================
    // Submission
    // =========================================================================

    public function maybe_handle_submission(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
            return;
        }
        nocache_headers();

        $round_id = absint( $_POST['grants_round_id'] ?? 0 );
        $values   = $this->posted_values();
        $fail     = static function ( string $message, array $errors = [] ) use ( $round_id, $values ): void {
            self::$outcome = [ 'round_id' => $round_id, 'values' => $values, 'errors' => $errors, 'message' => $message ];
        };
        $expired = __( 'For your security this form needed refreshing. Your answers are still below — please check them and submit again. If this keeps happening, check that cookies are allowed for this site.', 'rotary-grants' );

        if ( (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > self::MAX_BODY_BYTES ) {
            $fail( __( 'Your answers are too long to send. Please shorten them and try again.', 'rotary-grants' ) );
            return;
        }
        if ( ! self::same_origin() ) {
            $fail( $expired );
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            $fail( $expired );
            return;
        }

        $key   = (string) wp_unslash( $_POST['grants_submission_key'] ?? '' );
        $token = (string) wp_unslash( $_POST['grants_form_token'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $key ) || ! hash_equals( self::token( $round_id, $key ), $token ) ) {
            $fail( $expired );
            return;
        }

        // Honeypot: real people never see this field. Pretend all is well.
        if ( ! empty( $_POST[ self::HONEYPOT_FIELD ] ) ) {
            $this->redirect_to_receipt();
        }

        if ( ! self::within_rate_limit() ) {
            $fail( sprintf(
                /* translators: %s: help email */
                __( 'There have been too many attempts from your network in the last hour. Please try again later, or contact %s for help.', 'rotary-grants' ),
                ( new SettingsService() )->help_email()
            ) );
            return;
        }

        $round = ( new RoundService() )->find( $round_id );
        if ( ! $round ) {
            $fail( __( 'Sorry — this funding round is not accepting applications at the moment.', 'rotary-grants' ) );
            return;
        }

        [ $clean, $errors ] = ApplicationForm::validate( $values, $round );
        if ( $errors->has_errors() ) {
            $by_field = [];
            foreach ( $errors->get_error_codes() as $code ) {
                $by_field[ $code ] = $errors->get_error_message( $code );
            }
            $fail( '', $by_field );
            return;
        }

        $result = ( new ApplicationService() )->submit( $round, $clean, $key );
        if ( is_wp_error( $result ) ) {
            $fail( $result->get_error_message() );
            return;
        }

        set_transient( self::receipt_key(), [
            'reference'     => $result['reference'],
            'round_label'   => $round->label,
            'fund_name'     => $round->fund_name,
            'amount_pence'  => $clean['requested_pence'],
            'organisation'  => $clean['organisation_name'],
            'submitted_at'  => SiteTime::now_utc(),
        ], self::RECEIPT_TTL );

        $this->redirect_to_receipt();
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    /**
     * @param array<string,string>|string $atts
     */
    public function render( array|string $atts = [] ): string {
        $atts = shortcode_atts( [ 'fund' => '', 'round' => '' ], (array) $atts, self::SHORTCODE );
        wp_enqueue_style( 'grants-public' );

        $rounds   = new RoundService();
        $settings = new SettingsService();
        $help     = $settings->help_email();

        // Receipt after a successful submission (session-bound, never by URL alone).
        if ( isset( $_GET[ self::SUBMITTED_ARG ] ) && ! self::$outcome ) {
            $receipt = get_transient( self::receipt_key() );
            return $this->template( 'application-receipt', [
                'receipt'    => is_array( $receipt ) ? $receipt : null,
                'help_email' => $help,
                'form_url'   => remove_query_arg( self::SUBMITTED_ARG ),
            ] );
        }

        [ $round, $config_problem ] = $this->resolve_round( $atts, $rounds );

        if ( ! $round || ! $rounds->is_accepting( $round ) ) {
            return $this->template( 'application-unavailable', [
                'round'          => $round,
                'phase'          => $round ? $rounds->phase( $round ) : '',
                'help_email'     => $help,
                'config_problem' => current_user_can( 'edit_posts' ) ? $config_problem : '',
                'message'        => self::$outcome['message'] ?? '',
            ] );
        }

        $outcome = ( self::$outcome && self::$outcome['round_id'] === $round->id ) ? self::$outcome : null;

        // Keep the same submission key across a failed attempt so a retry of
        // the same answers stays idempotent; a fresh page gets a fresh key.
        $posted_key = (string) wp_unslash( $_POST['grants_submission_key'] ?? '' );
        $key        = ( $outcome && preg_match( '/^[a-f0-9]{64}$/', $posted_key ) ) ? $posted_key : bin2hex( random_bytes( 32 ) );

        return $this->template( 'application-form', [
            'round'          => $round,
            'values'         => $outcome['values'] ?? [],
            'errors'         => $outcome['errors'] ?? [],
            'message'        => $outcome['message'] ?? '',
            'labels'         => ApplicationForm::labels(),
            'help_email'     => $help,
            'privacy_url'    => $settings->get( SettingsService::PRIVACY_NOTICE_URL ),
            'cap_display'    => $round->cap_pence !== null ? Money::format_gbp( $round->cap_pence ) : '',
            'closes_display' => SiteTime::display( $round->closes_at ),
            'action_url'     => remove_query_arg( self::SUBMITTED_ARG ),
            'hidden'         => [
                'grants_round_id'       => (string) $round->id,
                'grants_submission_key' => $key,
                'grants_form_token'     => self::token( $round->id, $key ),
            ],
            'nonce_action'   => self::NONCE_ACTION,
            'nonce_field'    => self::NONCE_FIELD,
            'honeypot'       => self::HONEYPOT_FIELD,
            'max_lengths'    => array_combine( ApplicationForm::field_names(), array_map( [ ApplicationForm::class, 'max_length' ], ApplicationForm::field_names() ) ),
            'text'           => [
                'declaration'      => ApplicationForm::declaration_text(),
                'exclusions_ack'   => ApplicationForm::exclusions_ack_text(),
                'publicity_ack'    => ApplicationForm::publicity_ack_text(),
                'presentation_ack' => ApplicationForm::presentation_ack_text(),
                'privacy_ack'      => ApplicationForm::privacy_ack_text(),
                'opt_in'           => ApplicationForm::opt_in_text(),
            ],
        ] );
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * @param array{fund:string, round:string} $atts
     * @return array{0: ?object, 1: string} Round (or null) and an editor-facing configuration problem.
     */
    private function resolve_round( array $atts, RoundService $rounds ): array {
        // After a POST, show the round that was posted to (its id was HMAC-verified
        // or the outcome is an error message about an unknown round).
        if ( self::$outcome && self::$outcome['round_id'] ) {
            $posted = $rounds->find( self::$outcome['round_id'] );
            if ( $posted ) {
                return [ $posted, '' ];
            }
        }
        if ( $atts['round'] !== '' ) {
            $round = $rounds->find( absint( $atts['round'] ) );
            return [ $round, $round ? '' : __( 'The round="" number in this shortcode does not match any funding round.', 'rotary-grants' ) ];
        }
        if ( trim( $atts['fund'] ) !== '' ) {
            return [ $rounds->find_open_for_fund( $atts['fund'] ), '' ];
        }
        $open = $rounds->all( \Rotary\Grants\Services\RoundStatus::OPEN );
        if ( count( $open ) === 1 ) {
            return [ $open[0], '' ];
        }
        if ( count( $open ) > 1 ) {
            return [ null, __( 'More than one funding round is open. Add fund="…" to this shortcode, e.g. [rotary_grant_application fund="Tree of Light"], so the page knows which round to show.', 'rotary-grants' ) ];
        }
        return [ null, '' ];
    }

    /**
     * Allowlisted, unslashed raw values. Checkboxes become '1' or ''.
     *
     * @return array<string,string>
     */
    private function posted_values(): array {
        $values     = [];
        $checkboxes = ApplicationForm::checkbox_names();
        foreach ( ApplicationForm::field_names() as $field ) {
            $raw = $_POST[ $field ] ?? '';
            $raw = is_string( $raw ) ? wp_unslash( $raw ) : '';
            $values[ $field ] = in_array( $field, $checkboxes, true ) ? ( $raw === '1' ? '1' : '' ) : $raw;
        }
        return $values;
    }

    /** Random per-browser id in an HttpOnly, SameSite=Lax cookie. */
    private static function session(): string {
        if ( self::$session !== null ) {
            return self::$session;
        }
        $existing = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
        if ( preg_match( '/^[a-f0-9]{64}$/', $existing ) ) {
            return self::$session = $existing;
        }
        self::$session = bin2hex( random_bytes( 32 ) );
        if ( ! headers_sent() ) {
            setcookie( self::COOKIE, self::$session, [
                'expires'  => 0,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ] );
        }
        return self::$session;
    }

    private static function token( int $round_id, string $key ): string {
        return hash_hmac( 'sha256', self::session() . '|' . $round_id . '|' . $key, wp_salt( 'nonce' ) . 'rotary-grants-apply' );
    }

    private static function receipt_key(): string {
        return 'grants_receipt_' . substr( hash( 'sha256', self::session() ), 0, 40 );
    }

    /** Origin (or, failing that, Referer) must be this site when present. */
    private static function same_origin(): bool {
        $source = (string) ( $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '' );
        if ( $source === '' || $source === 'null' ) {
            return $source === ''; // Absent is tolerated (privacy tools strip it); literal "null" is not.
        }
        return strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    }

    /**
     * Allow RATE_MAX verified submissions per hour per network. The IP is
     * stored only as a salted hash inside a transient that expires in an
     * hour. REMOTE_ADDR only — forwarded headers are not trusted.
     */
    private static function within_rate_limit(): bool {
        $ip    = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        $key   = 'grants_rate_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
        $count = (int) get_transient( $key );
        if ( $count >= self::RATE_MAX ) {
            return false;
        }
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
        return true;
    }

    private function redirect_to_receipt(): never {
        // Back to the page that was posted to. wp_validate_redirect() falls
        // back to the home page if the Host header pointed anywhere else.
        $scheme = is_ssl() ? 'https://' : 'http://';
        $here   = $scheme . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
        $back   = wp_validate_redirect( $here, home_url( '/' ) );
        wp_safe_redirect( add_query_arg( self::SUBMITTED_ARG, '1', remove_query_arg( self::SUBMITTED_ARG, $back ) ), 303 );
        exit;
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function template( string $name, array $vars ): string {
        extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract — template variables are documented in each template
        ob_start();
        include GRANTS_PLUGIN_DIR . 'templates/public/' . $name . '.php';
        return (string) ob_get_clean();
    }
}
