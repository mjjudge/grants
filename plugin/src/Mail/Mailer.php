<?php

namespace Rotary\Grants\Mail;

use Rotary\Grants\Services\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one plain-text email through wp_mail() (SiteGround mail on live,
 * Mailpit on LocalWP) with this plugin's sender identity.
 *
 * From name/address are applied with filters added just for this call and
 * removed straight after — the same scoping Tree of Light, Duck Race and
 * Battle Shield Sponsorship use — so other plugins' mail is unaffected.
 * Reply-To is the help email, so replies reach a person.
 */
class Mailer {

    /**
     * @return true|\WP_Error Error code is short and contains no personal data.
     */
    public function send( string $to, string $subject, string $body ): true|\WP_Error {
        $settings = new SettingsService();
        $to       = trim( $to );
        $subject  = trim( (string) preg_replace( '/[\r\n]+/', ' ', $subject ) );

        if ( ! is_email( $to ) ) {
            return new \WP_Error( 'invalid_recipient' );
        }
        if ( $subject === '' || trim( $body ) === '' ) {
            return new \WP_Error( 'empty_message' );
        }

        $from_address = $settings->get( SettingsService::MAIL_FROM_ADDRESS );
        $from_name    = $settings->get( SettingsService::MAIL_FROM_NAME );
        $reply_to     = $settings->help_email();

        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
        if ( $reply_to !== '' && is_email( $reply_to ) ) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        $from_filter = static fn() => $from_address;
        $name_filter = static fn() => $from_name;
        $error_code  = '';
        $on_failure  = static function ( \WP_Error $e ) use ( &$error_code ): void {
            // PHPMailer messages can contain addresses — keep only a code.
            $error_code = (string) ( $e->get_error_code() ?: 'wp_mail_failed' );
        };

        add_filter( 'wp_mail_from', $from_filter, 99 );
        add_filter( 'wp_mail_from_name', $name_filter, 99 );
        add_action( 'wp_mail_failed', $on_failure );
        try {
            $ok = wp_mail( $to, $subject, $body, $headers );
        } finally {
            remove_filter( 'wp_mail_from', $from_filter, 99 );
            remove_filter( 'wp_mail_from_name', $name_filter, 99 );
            remove_action( 'wp_mail_failed', $on_failure );
        }

        return $ok ? true : new \WP_Error( substr( $error_code ?: 'wp_mail_failed', 0, 64 ) );
    }
}
