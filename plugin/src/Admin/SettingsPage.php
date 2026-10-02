<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen: help/contact email, staff notification recipients,
 * privacy notice link/version, and email sender identity / pause switch.
 *
 * Routing:
 *   /wp-admin/admin.php?page=grants-settings   → form
 *   POST admin-post.php action=grants_settings_save
 *
 * On a validation failure the submitted values and field errors are kept in a
 * short-lived per-user transient so the form redisplays what was typed.
 */
class SettingsPage {

    private const NONCE_ACTION  = 'grants_settings_save';
    private const NONCE_FIELD   = 'grants_settings_nonce';
    private const FORM_STATE_TTL = 300;

    public function register(): void {
        add_action( 'admin_post_grants_settings_save', [ $this, 'handle_save' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'grants_manage_settings' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }

        $service = new SettingsService();
        $values  = [
            SettingsService::HELP_EMAIL              => $service->get( SettingsService::HELP_EMAIL ),
            SettingsService::NOTIFICATION_RECIPIENTS => $service->get( SettingsService::NOTIFICATION_RECIPIENTS ),
            SettingsService::PRIVACY_NOTICE_URL      => $service->get( SettingsService::PRIVACY_NOTICE_URL ),
            SettingsService::PRIVACY_NOTICE_VERSION  => $service->get( SettingsService::PRIVACY_NOTICE_VERSION ),
            SettingsService::MAIL_FROM_NAME          => $service->get( SettingsService::MAIL_FROM_NAME ),
            SettingsService::MAIL_FROM_ADDRESS       => $service->get( SettingsService::MAIL_FROM_ADDRESS ),
            SettingsService::NOTIFICATIONS_PAUSED    => $service->get( SettingsService::NOTIFICATIONS_PAUSED ),
        ];
        $errors = [];

        $state_key = self::state_key();
        $state     = get_transient( $state_key );
        if ( is_array( $state ) ) {
            delete_transient( $state_key );
            $values = array_merge( $values, (array) ( $state['input'] ?? [] ) );
            $errors = (array) ( $state['errors'] ?? [] );
        }

        $notice         = sanitize_key( $_GET['grants_notice'] ?? '' );
        $max_recipients = SettingsService::MAX_RECIPIENTS;
        $nonce_action   = self::NONCE_ACTION;
        $nonce_field    = self::NONCE_FIELD;

        include GRANTS_PLUGIN_DIR . 'templates/admin/settings.php';
    }

    public function handle_save(): void {
        if ( ! current_user_can( 'grants_manage_settings' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

        $input = [
            SettingsService::HELP_EMAIL              => sanitize_text_field( wp_unslash( $_POST['help_email'] ?? '' ) ),
            SettingsService::NOTIFICATION_RECIPIENTS => sanitize_textarea_field( wp_unslash( $_POST['notification_recipients'] ?? '' ) ),
            SettingsService::PRIVACY_NOTICE_URL      => sanitize_text_field( wp_unslash( $_POST['privacy_notice_url'] ?? '' ) ), // validated as an http(s) URL by the service
            SettingsService::PRIVACY_NOTICE_VERSION  => sanitize_text_field( wp_unslash( $_POST['privacy_notice_version'] ?? '' ) ),
            SettingsService::MAIL_FROM_NAME          => sanitize_text_field( wp_unslash( $_POST['mail_from_name'] ?? '' ) ),
            SettingsService::MAIL_FROM_ADDRESS       => sanitize_text_field( wp_unslash( $_POST['mail_from_address'] ?? '' ) ),
            SettingsService::NOTIFICATIONS_PAUSED    => ( $_POST['notifications_paused'] ?? '' ) === '1' ? '1' : '',
        ];

        $result = ( new SettingsService() )->save( $input );

        if ( is_wp_error( $result ) ) {
            $errors = [];
            foreach ( $result->get_error_codes() as $code ) {
                $errors[ $code ] = $result->get_error_message( $code );
            }
            set_transient( self::state_key(), [ 'input' => $input, 'errors' => $errors ], self::FORM_STATE_TTL );
            $this->redirect( 'error' );
        }

        $this->redirect( 'saved' );
    }

    private static function state_key(): string {
        return 'grants_settings_form_' . get_current_user_id();
    }

    private function redirect( string $notice ): never {
        wp_safe_redirect( add_query_arg(
            [ 'page' => 'grants-settings', 'grants_notice' => $notice ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }
}
