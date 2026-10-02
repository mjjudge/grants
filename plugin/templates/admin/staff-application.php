<?php
/**
 * Admin template — staff entry of a paper/email/phone application.
 *
 * Available variables:
 *   $rounds       object[]              Open and closed rounds (entries can't go to draft/archived).
 *   $values       array<string,string>  Field values (ApplicationForm names plus round_id,
 *                                       staff_source, staff_received [Y-m-d], staff_reason, staff_send_ack).
 *   $errors       array<string,string>  Field/meta name => message.
 *   $fields       string[]              ApplicationForm field names, in order.
 *   $labels       array<string,string>  Field name => label.
 *   $sources      array<string,string>  Staff source => label.
 *   $key          string                Submission key (keeps a retried save idempotent).
 *   $nonce_action string                Nonce action.
 *   $nonce_field  string                Nonce field name.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\ApplicationForm;

$v   = static fn( string $f ): string => (string) ( $values[ $f ] ?? '' );
$err = static function ( string $f ) use ( $errors ): void {
    if ( isset( $errors[ $f ] ) ) {
        printf( '<p class="grants-field-error" id="%s">%s</p>', esc_attr( 'grants-' . $f . '-error' ), esc_html( $errors[ $f ] ) );
    }
};
$aria = static fn( string $f ): string => isset( $errors[ $f ] ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( 'grants-' . $f . '-error' ) . '"' : '';
$meta_labels = [
    'round_id'       => __( 'Funding round', 'rotary-grants' ),
    'staff_round'    => __( 'Funding round', 'rotary-grants' ),
    'staff_source'   => __( 'How it was received', 'rotary-grants' ),
    'staff_received' => __( 'Date received', 'rotary-grants' ),
    'staff_reason'   => __( 'Note / reason', 'rotary-grants' ),
];
?>
<div class="wrap grants-admin">
    <h1><?php esc_html_e( 'Enter an application received on paper, by email or by phone', 'rotary-grants' ); ?></h1>
    <p><?php esc_html_e( 'Record what the applicant actually provided. Only the organisation, contact name, an email or phone number, the amount and its use are required; leave anything they did not answer blank. The application will be labelled as entered by staff.', 'rotary-grants' ); ?></p>

    <?php if ( $errors ) : ?>
        <div class="notice notice-error" id="grants-error-summary" tabindex="-1">
            <p><strong><?php esc_html_e( 'The application was not saved:', 'rotary-grants' ); ?></strong></p>
            <ul>
                <?php foreach ( $errors as $f => $m ) : ?>
                    <li><a href="#grants-<?php echo esc_attr( $f === 'staff_round' ? 'round_id' : $f ); ?>"><?php echo esc_html( ( $labels[ $f ] ?? $meta_labels[ $f ] ?? $f ) . ': ' . $m ); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <script>document.getElementById('grants-error-summary').focus();</script>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
        <input type="hidden" name="action" value="grants_staff_application_save">
        <input type="hidden" name="grants_submission_key" value="<?php echo esc_attr( $key ); ?>">
        <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>

        <h2><?php esc_html_e( 'How it arrived', 'rotary-grants' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="grants-round_id"><?php echo esc_html( $meta_labels['round_id'] ); ?></label></th>
                <td>
                    <select id="grants-round_id" name="round_id"<?php echo $aria( 'staff_round' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
                        <option value=""><?php esc_html_e( '— choose —', 'rotary-grants' ); ?></option>
                        <?php foreach ( $rounds as $r ) : ?>
                            <option value="<?php echo esc_attr( (string) $r->id ); ?>" <?php selected( $v( 'round_id' ), (string) $r->id ); ?>><?php echo esc_html( $r->fund_name . ' — ' . $r->label . ' (' . $r->status . ')' ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php $err( 'staff_round' ); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="grants-staff_source"><?php echo esc_html( $meta_labels['staff_source'] ); ?></label></th>
                <td>
                    <select id="grants-staff_source" name="staff_source"<?php echo $aria( 'staff_source' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
                        <option value=""><?php esc_html_e( '— choose —', 'rotary-grants' ); ?></option>
                        <?php foreach ( $sources as $key_s => $label_s ) : ?>
                            <option value="<?php echo esc_attr( $key_s ); ?>" <?php selected( $v( 'staff_source' ), $key_s ); ?>><?php echo esc_html( $label_s ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php $err( 'staff_source' ); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="grants-staff_received"><?php echo esc_html( $meta_labels['staff_received'] ); ?></label></th>
                <td>
                    <input type="date" id="grants-staff_received" name="staff_received" value="<?php echo esc_attr( $v( 'staff_received' ) ); ?>"<?php echo $aria( 'staff_received' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
                    <?php $err( 'staff_received' ); ?>
                    <p class="description"><?php esc_html_e( 'When the club received it (not today\'s date if it arrived earlier). A date outside the round\'s opening and closing dates marks it as late and needs a reason below.', 'rotary-grants' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="grants-staff_reason"><?php echo esc_html( $meta_labels['staff_reason'] ); ?></label></th>
                <td>
                    <textarea id="grants-staff_reason" name="staff_reason" rows="2" class="large-text"<?php echo $aria( 'staff_reason' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_textarea( $v( 'staff_reason' ) ); ?></textarea>
                    <?php $err( 'staff_reason' ); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Acknowledgement', 'rotary-grants' ); ?></th>
                <td><label><input type="checkbox" name="staff_send_ack" value="1" <?php checked( $v( 'staff_send_ack' ), '1' ); ?>> <?php esc_html_e( 'Email the applicant the standard acknowledgement (needs their email address)', 'rotary-grants' ); ?></label></td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Application', 'rotary-grants' ); ?></h2>
        <table class="form-table" role="presentation">
            <?php foreach ( $fields as $f ) : $type = ApplicationForm::field_type( $f ); ?>
                <tr>
                    <th scope="row">
                        <?php if ( $type === 'choice' || $type === 'checkbox' ) : ?>
                            <?php echo esc_html( $labels[ $f ] ); ?>
                        <?php else : ?>
                            <label for="grants-<?php echo esc_attr( $f ); ?>"><?php echo esc_html( $labels[ $f ] ); ?></label>
                        <?php endif; ?>
                    </th>
                    <td id="<?php echo in_array( $type, [ 'choice', 'checkbox' ], true ) ? esc_attr( 'grants-' . $f ) : ''; ?>">
                        <?php if ( $type === 'textarea' ) : ?>
                            <textarea id="grants-<?php echo esc_attr( $f ); ?>" name="<?php echo esc_attr( $f ); ?>" rows="3" class="large-text"<?php echo $aria( $f ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_textarea( $v( $f ) ); ?></textarea>
                        <?php elseif ( $type === 'choice' ) : ?>
                            <?php foreach ( [ '' => __( 'Not answered', 'rotary-grants' ) ] + ApplicationForm::choices( $f ) as $val => $lab ) : ?>
                                <label class="grants-choice-inline"><input type="radio" name="<?php echo esc_attr( $f ); ?>" value="<?php echo esc_attr( $val ); ?>" <?php checked( $v( $f ), $val ); ?>> <?php echo esc_html( $lab ); ?></label>
                            <?php endforeach; ?>
                        <?php elseif ( $type === 'checkbox' ) : ?>
                            <label><input type="checkbox" name="<?php echo esc_attr( $f ); ?>" value="1" <?php checked( $v( $f ), '1' ); ?>> <?php esc_html_e( 'Yes — the application confirms this', 'rotary-grants' ); ?></label>
                        <?php else : ?>
                            <input type="<?php echo $type === 'url' ? 'url' : 'text'; ?>" id="grants-<?php echo esc_attr( $f ); ?>" name="<?php echo esc_attr( $f ); ?>" class="regular-text" value="<?php echo esc_attr( $v( $f ) ); ?>"<?php echo $aria( $f ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
                        <?php endif; ?>
                        <?php $err( $f ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php submit_button( __( 'Save application', 'rotary-grants' ) ); ?>
    </form>
</div>
