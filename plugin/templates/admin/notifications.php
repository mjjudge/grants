<?php
/**
 * Admin template — notification queue.
 *
 * Available variables:
 *   $rows         object[]           grants_notifications rows (+ public_reference), newest first.
 *   $counts       array<string,int>  pending / sent / failed counts.
 *   $status       string             Active status filter ('' = all).
 *   $paused       bool               Sending paused in Settings.
 *   $can_act      bool               User may retry / send now.
 *   $notice       string             'retried', 'sent_now', 'retry_error' or ''.
 *   $sent_count   int                Messages sent by the action just taken.
 *   $base_url     string             This screen's URL.
 *   $apps_url     string             Applications screen URL.
 *   $nonce_action string             Nonce action for the POST forms.
 *   $nonce_field  string             Nonce field name.
 *   $max_attempts int                Attempts before a message is marked failed.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\NotificationService;
use Rotary\Grants\Support\SiteTime;

$status_labels = [
    'pending' => __( 'Waiting', 'rotary-grants' ),
    'sent'    => __( 'Sent', 'rotary-grants' ),
    'failed'  => __( 'Failed', 'rotary-grants' ),
];
?>
<div class="wrap grants-admin">
    <h1><?php esc_html_e( 'Notifications', 'rotary-grants' ); ?></h1>

    <?php if ( $notice === 'retried' || $notice === 'sent_now' ) : ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php
            /* translators: %d: number of emails sent */
            echo esc_html( sprintf( _n( '%d email sent.', '%d emails sent.', $sent_count, 'rotary-grants' ), $sent_count ) );
            ?>
        </p></div>
    <?php elseif ( $notice === 'retry_error' ) : ?>
        <div class="notice notice-error"><p><?php esc_html_e( 'That notification could not be retried.', 'rotary-grants' ); ?></p></div>
    <?php endif; ?>

    <?php if ( $paused ) : ?>
        <div class="notice notice-warning inline"><p><?php esc_html_e( 'Sending is paused in Settings — emails are being held, not sent.', 'rotary-grants' ); ?></p></div>
    <?php endif; ?>

    <p class="description">
        <?php
        echo esc_html( sprintf(
            /* translators: %d: maximum attempts */
            __( '"Sent" means the site\'s mail service accepted the email; it cannot confirm it reached the inbox. Waiting emails are retried automatically every few minutes; after %d failed attempts an email is marked Failed and needs a retry from here.', 'rotary-grants' ),
            $max_attempts
        ) );
        ?>
    </p>

    <ul class="subsubsub">
        <li><a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo $status === '' ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'rotary-grants' ); ?></a></li>
        <?php foreach ( $status_labels as $s => $label ) : ?>
            <li> | <a href="<?php echo esc_url( add_query_arg( 'status', $s, $base_url ) ); ?>" class="<?php echo $status === $s ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( (string) $counts[ $s ] ); ?>)</span></a></li>
        <?php endforeach; ?>
    </ul>

    <?php if ( $can_act && $counts['pending'] > 0 && ! $paused ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="grants-inline-form" style="float:right">
            <input type="hidden" name="action" value="grants_notifications_send">
            <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
            <button type="submit" class="button"><?php esc_html_e( 'Send waiting emails now', 'rotary-grants' ); ?></button>
        </form>
    <?php endif; ?>

    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e( 'Application', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Type', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'To', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Status', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Attempts', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Last attempt / next', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Problem', 'rotary-grants' ); ?></th>
                <?php if ( $can_act ) : ?><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'rotary-grants' ); ?></span></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! $rows ) : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No notifications.', 'rotary-grants' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $rows as $n ) : ?>
                <tr>
                    <td><a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => (int) $n->application_id ], $apps_url ) ); ?>"><?php echo esc_html( $n->public_reference ?: '#' . $n->application_id ); ?></a></td>
                    <td><?php echo esc_html( NotificationService::kind_label( $n->kind ) ); ?></td>
                    <td><?php echo esc_html( $n->recipient ); ?></td>
                    <td><span class="grants-mail-status grants-mail-status--<?php echo esc_attr( $n->status ); ?>"><?php echo esc_html( $status_labels[ $n->status ] ?? $n->status ); ?></span></td>
                    <td><?php echo esc_html( (string) $n->attempts ); ?></td>
                    <td>
                        <?php echo esc_html( SiteTime::display( $n->status === 'sent' ? $n->sent_at : $n->last_attempt_at ) ?: '—' ); ?>
                        <?php if ( $n->status === 'pending' ) : ?>
                            <br><span class="description"><?php echo esc_html( sprintf( /* translators: %s: date/time */ __( 'next: %s', 'rotary-grants' ), SiteTime::display( $n->next_attempt_at ) ) ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html( $n->last_error_code ?: '' ); ?></code></td>
                    <?php if ( $can_act ) : ?>
                        <td>
                            <?php if ( $n->status !== 'sent' ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="grants_notification_retry">
                                    <input type="hidden" name="notification_id" value="<?php echo esc_attr( (string) $n->id ); ?>">
                                    <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
                                    <button type="submit" class="button button-small"><?php esc_html_e( 'Retry now', 'rotary-grants' ); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
