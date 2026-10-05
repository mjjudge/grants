<?php
/**
 * Admin template — Rotary Grants Settings.
 *
 * Available variables:
 *   $values         array<string,string>  Field values keyed by setting key
 *                                         ('help_email', 'notification_recipients',
 *                                         'privacy_notice_url', 'privacy_notice_version',
 *                                         'mail_from_name', 'mail_from_address',
 *                                         'notifications_paused' ['1'|'0'],
 *                                         'reporting_year_start_month' ['1'..'12']).
 *   $site_name      string                The site's name (shown as the From-name fallback).
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
$privacy_error    = $errors['privacy_notice_url'] ?? '';
$version_error    = $errors['privacy_notice_version'] ?? '';
$field_ids        = [
    'help_email'              => 'grants-help-email',
    'notification_recipients' => 'grants-notification-recipients',
    'privacy_notice_url'      => 'grants-privacy-notice-url',
    'privacy_notice_version'  => 'grants-privacy-notice-version',
    'mail_from_name'          => 'grants-mail-from-name',
    'mail_from_address'       => 'grants-mail-from-address',
];
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
                        <?php if ( isset( $field_ids[ $field ] ) ) : ?>
                            <a href="#<?php echo esc_attr( $field_ids[ $field ] ); ?>"><?php echo esc_html( $message ); ?></a>
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

        <h2><?php esc_html_e( 'Privacy notice', 'rotary-grants' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="grants-privacy-notice-url"><?php esc_html_e( 'Privacy notice link', 'rotary-grants' ); ?></label></th>
                <td>
                    <input type="url" id="grants-privacy-notice-url" name="privacy_notice_url" class="regular-text"
                           value="<?php echo esc_attr( $values['privacy_notice_url'] ?? '' ); ?>"
                           aria-describedby="grants-privacy-notice-url-desc<?php echo $privacy_error ? ' grants-privacy-notice-url-error' : ''; ?>"
                           <?php echo $privacy_error ? 'aria-invalid="true"' : ''; ?>>
                    <?php if ( $privacy_error ) : ?>
                        <p class="grants-field-error" id="grants-privacy-notice-url-error"><?php echo esc_html( $privacy_error ); ?></p>
                    <?php endif; ?>
                    <p class="description" id="grants-privacy-notice-url-desc"><?php esc_html_e( 'Full address of the approved privacy notice applicants are asked to read. A funding round cannot be opened until this is set.', 'rotary-grants' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="grants-privacy-notice-version"><?php esc_html_e( 'Privacy notice version', 'rotary-grants' ); ?></label></th>
                <td>
                    <input type="text" id="grants-privacy-notice-version" name="privacy_notice_version" class="regular-text"
                           value="<?php echo esc_attr( $values['privacy_notice_version'] ?? '' ); ?>"
                           aria-describedby="grants-privacy-notice-version-desc<?php echo $version_error ? ' grants-privacy-notice-version-error' : ''; ?>"
                           <?php echo $version_error ? 'aria-invalid="true"' : ''; ?>>
                    <?php if ( $version_error ) : ?>
                        <p class="grants-field-error" id="grants-privacy-notice-version-error"><?php echo esc_html( $version_error ); ?></p>
                    <?php endif; ?>
                    <p class="description" id="grants-privacy-notice-version-desc"><?php esc_html_e( 'A short label such as "2026-1". Change it whenever the notice wording changes — each application records the version that was shown.', 'rotary-grants' ); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Email sending', 'rotary-grants' ); ?></h2>
        <table class="form-table" role="presentation">
            <?php
            foreach ( [
                'mail_from_name'    => [ __( 'From name', 'rotary-grants' ), 'text', sprintf( /* translators: %s: site name */ __( 'Shown as the sender of all Rotary Grants emails. Leave empty to use the site name ("%s").', 'rotary-grants' ), $site_name ) ],
                'mail_from_address' => [ __( 'From address', 'rotary-grants' ), 'email', __( 'An address on the site\'s own domain, so the domain\'s mail authentication (SPF/DKIM) covers it. Leave empty to use WordPress\'s normal sender. Replies go to the help/contact email above.', 'rotary-grants' ) ],
            ] as $key => [ $label, $type, $desc ] ) :
                $err = $errors[ $key ] ?? '';
                $id  = $field_ids[ $key ];
                ?>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
                    <td>
                        <input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" class="regular-text"
                               value="<?php echo esc_attr( $values[ $key ] ?? '' ); ?>"
                               aria-describedby="<?php echo esc_attr( $id . '-desc' . ( $err ? ' ' . $id . '-error' : '' ) ); ?>"
                               <?php echo $err ? 'aria-invalid="true"' : ''; ?>>
                        <?php if ( $err ) : ?>
                            <p class="grants-field-error" id="<?php echo esc_attr( $id . '-error' ); ?>"><?php echo esc_html( $err ); ?></p>
                        <?php endif; ?>
                        <p class="description" id="<?php echo esc_attr( $id . '-desc' ); ?>"><?php echo esc_html( $desc ); ?></p>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Pause sending', 'rotary-grants' ); ?></th>
                <td>
                    <label for="grants-notifications-paused">
                        <input type="checkbox" id="grants-notifications-paused" name="notifications_paused" value="1" <?php checked( ( $values['notifications_paused'] ?? '0' ) === '1' ); ?>>
                        <?php esc_html_e( 'Hold all Rotary Grants emails in the queue instead of sending them', 'rotary-grants' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Applications are still accepted and saved while paused. Untick to send everything that has been held (within a few minutes, or straight away from the Notifications screen).', 'rotary-grants' ); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Reports', 'rotary-grants' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="grants-reporting-year"><?php esc_html_e( 'Reporting year starts in', 'rotary-grants' ); ?></label></th>
                <td>
                    <select id="grants-reporting-year" name="reporting_year_start_month">
                        <?php for ( $m = 1; $m <= 12; $m++ ) : ?>
                            <option value="<?php echo esc_attr( (string) $m ); ?>" <?php selected( (string) ( $values['reporting_year_start_month'] ?? '1' ), (string) $m ); ?>><?php echo esc_html( wp_date( 'F', mktime( 12, 0, 0, $m, 1, 2000 ) ) ); ?></option>
                        <?php endfor; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Used to group payments by the date they were made. January = calendar year; July = the Rotary year; or your charity\'s accounting year. Round-based reports are not affected.', 'rotary-grants' ); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button( __( 'Save settings', 'rotary-grants' ) ); ?>
    </form>
</div>
