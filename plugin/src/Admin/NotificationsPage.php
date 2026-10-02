<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\NotificationService;
use Rotary\Grants\Services\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Notification queue: what was sent, what is waiting, what failed.
 *
 * Routing:
 *   /wp-admin/admin.php?page=grants-notifications[&status=failed]
 *   POST admin-post.php action=grants_notification_retry   (one row)
 *   POST admin-post.php action=grants_notifications_send   (send due now)
 *
 * Viewing needs grants_access; retrying/sending needs grants_manage_settings.
 */
class NotificationsPage {

    private const NONCE_ACTION = 'grants_notifications';
    private const NONCE_FIELD  = 'grants_notifications_nonce';

    public function register(): void {
        add_action( 'admin_post_grants_notification_retry', [ $this, 'handle_retry' ] );
        add_action( 'admin_post_grants_notifications_send', [ $this, 'handle_send' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'grants_access' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        $service       = new NotificationService();
        $status        = sanitize_key( $_GET['status'] ?? '' );
        $rows          = $service->list( $status ?: null );
        $counts        = $service->counts();
        $paused        = ( new SettingsService() )->notifications_paused();
        $can_act       = current_user_can( 'grants_manage_settings' );
        $notice        = sanitize_key( $_GET['grants_notice'] ?? '' );
        $sent_count    = absint( $_GET['sent'] ?? 0 );
        $base_url      = add_query_arg( 'page', 'grants-notifications', admin_url( 'admin.php' ) );
        $apps_url      = add_query_arg( 'page', 'grants-applications', admin_url( 'admin.php' ) );
        $nonce_action  = self::NONCE_ACTION;
        $nonce_field   = self::NONCE_FIELD;
        $max_attempts  = NotificationService::MAX_ATTEMPTS;

        include GRANTS_PLUGIN_DIR . 'templates/admin/notifications.php';
    }

    public function handle_retry(): void {
        if ( ! current_user_can( 'grants_manage_settings' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

        $service = new NotificationService();
        $result  = $service->retry( absint( $_POST['notification_id'] ?? 0 ) );
        if ( is_wp_error( $result ) ) {
            $this->redirect( [ 'grants_notice' => 'retry_error' ] );
        }
        $tally = $service->process_due();
        $this->redirect( [ 'grants_notice' => 'retried', 'sent' => $tally['sent'] ] );
    }

    public function handle_send(): void {
        if ( ! current_user_can( 'grants_manage_settings' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

        $tally = ( new NotificationService() )->process_due();
        $this->redirect( [ 'grants_notice' => 'sent_now', 'sent' => $tally['sent'] ] );
    }

    /** @param array<string,string|int> $args */
    private function redirect( array $args ): never {
        wp_safe_redirect( add_query_arg( [ 'page' => 'grants-notifications' ] + $args, admin_url( 'admin.php' ) ) );
        exit;
    }
}
