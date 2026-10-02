<?php
/**
 * Admin template — Rotary Grants Settings.
 *
 * Available variables:
 *   $values         array<string,string>  Field values keyed by setting key
 *                                         ('help_email', 'notification_recipients').
 *                                         After a failed save, these are what was submitted.
 *   $errors         array<string,string>  Error messages keyed by setting key
 *                                         (or 'forbidden' / 'db_error').
 *   $notice         string                'saved', 'error', or ''.
 *   $max_recipients int                   Maximum number of notification recipients.
 *   $nonce_action   string                Nonce action for the form.
 *   $nonce_field    string                Nonce field name for the form.
 */
defined( 'ABSPATH' ) || exit;

$help_error       = $errors['help_email'] ?? '';
$recipients_error = $errors['notification_recipients'] ?? '';
?>
<div class="wrap grants-admin">
    <h1><?php esc_html_e( 'Rotary Grants Settings', 'rotary-grants' ); ?></h1>

    <?php if ( $notice === 'saved' && ! $errors ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'rotary-grants' ); ?></p></div>
    <?php endif; ?>

    <?php if ( $errors ) : ?>
        <div class="notice notice-error" id="grants-error-summary" tabindex="-1">
            <p><strong><?php esc_html_e( 'Settings were not saved. Please correct the following:', 'rotary-grants' ); ?></strong></p>
            <ul>
                <?php foreach ( $errors as $field => $message ) : ?>
                    <li>
                        <?php if ( in_array( $field, [ 'help_email', 'notification_recipients' ], true ) ) : ?>
                            <a href="#grants-<?php echo esc_attr( str_replace( '_', '-', $field ) ); ?>"><?php echo esc_html( $message ); ?></a>
                        <?php else : ?>
                            <?php echo esc_html( $message ); ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <script>document.getElementById('grants-error-summary').focus();</script>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
        <input type="hidden" name="action" value="grants_settings_save">
        <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>

        <h2><?php esc_html_e( 'Applicant contact', 'rotary-grants' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="grants-help-email"><?php esc_html_e( 'Help/contact email', 'rotary-grants' ); ?></label></th>
                <td>
                    <input type="email" id="grants-help-email" name="help_email" class="regular-text"
                           value="<?php echo esc_attr( $values['help_email'] ?? '' ); ?>"
                           aria-describedby="grants-help-email-desc<?php echo $help_error ? ' grants-help-email-error' : ''; ?>"
                           <?php echo $help_error ? 'aria-invalid="true"' : ''; ?>>
                    <?php if ( $help_error ) : ?>
                        <p class="grants-field-error" id="grants-help-email-error"><?php echo esc_html( $help_error ); ?></p>
                    <?php endif; ?>
                    <p class="description" id="grants-help-email-desc"><?php esc_html_e( 'Shown to applicants on the application form and in their acknowledgement email. A funding round cannot be opened until this is set.', 'rotary-grants' ); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Staff notifications', 'rotary-grants' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="grants-notification-recipients"><?php esc_html_e( 'New-application recipients', 'rotary-grants' ); ?></label></th>
                <td>
                    <textarea id="grants-notification-recipients" name="notification_recipients" rows="5" class="large-text code"
                              aria-describedby="grants-notification-recipients-desc<?php echo $recipients_error ? ' grants-notification-recipients-error' : ''; ?>"
                              <?php echo $recipients_error ? 'aria-invalid="true"' : ''; ?>><?php echo esc_textarea( $values['notification_recipients'] ?? '' ); ?></textarea>
                    <?php if ( $recipients_error ) : ?>
                        <p class="grants-field-error" id="grants-notification-recipients-error"><?php echo esc_html( $recipients_error ); ?></p>
                    <?php endif; ?>
                    <p class="description" id="grants-notification-recipients-desc">
                        <?php
                        echo esc_html( sprintf(
                            /* translators: %d: maximum number of recipients */
                            __( 'Staff email addresses told whenever a new application is submitted — one per line, or separated by commas (up to %d). This is separate from the acknowledgement the applicant receives. A funding round cannot be opened until at least one is set.', 'rotary-grants' ),
                            $max_recipients
                        ) );
                        ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button( __( 'Save settings', 'rotary-grants' ) ); ?>
    </form>
</div>
