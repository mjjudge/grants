<?php
/**
 * Admin template — Rotary Grants dashboard (placeholder until G02/G03).
 *
 * Available variables:
 *   $help_email_set  bool    Whether a help/contact email is configured.
 *   $recipient_count int     Number of configured staff notification recipients.
 *   $privacy_set     bool    Whether a privacy notice link is configured.
 *   $can_settings    bool    Whether the current user may open Settings.
 *   $settings_url    string  URL of the Settings screen.
 *   $mail_counts     array<string,int>  Notification counts by status (pending/sent/failed).
 *   $mail_paused     bool    Whether email sending is paused in Settings.
 *   $mail_url        string  URL of the Notifications screen.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap grants-admin">
    <h1><?php esc_html_e( 'Rotary Grants', 'rotary-grants' ); ?></h1>

    <p><?php esc_html_e( 'Applications, decisions and payments will appear here as they are built. Funding rounds can be set up under Funding Rounds; nothing can be submitted yet.', 'rotary-grants' ); ?></p>

    <?php if ( $mail_counts['failed'] > 0 ) : ?>
        <div class="notice notice-error inline"><p>
            <?php
            /* translators: %d: number of failed emails */
            echo esc_html( sprintf( _n( '%d email could not be sent.', '%d emails could not be sent.', $mail_counts['failed'], 'rotary-grants' ), $mail_counts['failed'] ) );
            ?>
            <a href="<?php echo esc_url( add_query_arg( 'status', 'failed', $mail_url ) ); ?>"><?php esc_html_e( 'Review failed emails', 'rotary-grants' ); ?></a>
        </p></div>
    <?php endif; ?>
    <?php if ( $mail_paused ) : ?>
        <div class="notice notice-warning inline"><p>
            <?php
            /* translators: %d: number of held emails */
            echo esc_html( sprintf( __( 'Email sending is paused in Settings. %d email(s) are being held.', 'rotary-grants' ), $mail_counts['pending'] ) );
            ?>
        </p></div>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Configuration', 'rotary-grants' ); ?></h2>
    <table class="widefat striped grants-config-status">
        <tbody>
            <tr>
                <th scope="row"><?php esc_html_e( 'Help/contact email', 'rotary-grants' ); ?></th>
                <td>
                    <?php if ( $help_email_set ) : ?>
                        <span class="grants-status grants-status--ok"><?php esc_html_e( 'Set', 'rotary-grants' ); ?></span>
                    <?php else : ?>
                        <span class="grants-status grants-status--missing"><?php esc_html_e( 'Not set — required before a round can open', 'rotary-grants' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Staff notification recipients', 'rotary-grants' ); ?></th>
                <td>
                    <?php if ( $recipient_count > 0 ) : ?>
                        <span class="grants-status grants-status--ok">
                            <?php
                            /* translators: %d: number of recipients */
                            echo esc_html( sprintf( _n( '%d recipient', '%d recipients', $recipient_count, 'rotary-grants' ), $recipient_count ) );
                            ?>
                        </span>
                    <?php else : ?>
                        <span class="grants-status grants-status--missing"><?php esc_html_e( 'None — required before a round can open', 'rotary-grants' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Privacy notice link', 'rotary-grants' ); ?></th>
                <td>
                    <?php if ( $privacy_set ) : ?>
                        <span class="grants-status grants-status--ok"><?php esc_html_e( 'Set', 'rotary-grants' ); ?></span>
                    <?php else : ?>
                        <span class="grants-status grants-status--missing"><?php esc_html_e( 'Not set — required before a round can open', 'rotary-grants' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <?php if ( $can_settings ) : ?>
        <p><a class="button" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open Settings', 'rotary-grants' ); ?></a></p>
    <?php endif; ?>
</div>
