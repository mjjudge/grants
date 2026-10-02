<?php
/**
 * Public template — receipt after a successful submission.
 *
 * Available variables:
 *   $receipt    array|null  [ reference, round_label, fund_name, amount_pence (int),
 *                             organisation, submitted_at (UTC), email ] for this browser's
 *                             submission, or null if none is held for this session.
 *   $help_email string      Help/contact email from Settings.
 *   $form_url   string      URL of the form without the receipt flag.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;
?>
<div class="grants-apply grants-apply--receipt" role="status">
    <?php if ( $receipt ) : ?>
        <h2><?php esc_html_e( 'Application received', 'rotary-grants' ); ?></h2>
        <p><?php esc_html_e( 'Thank you. Your application has been saved. Please keep your reference — quote it if you contact us.', 'rotary-grants' ); ?></p>
        <dl class="grants-apply__facts">
            <dt><?php esc_html_e( 'Reference', 'rotary-grants' ); ?></dt>
            <dd><strong class="grants-reference"><?php echo esc_html( $receipt['reference'] ); ?></strong></dd>
            <dt><?php esc_html_e( 'Organisation', 'rotary-grants' ); ?></dt>
            <dd><?php echo esc_html( $receipt['organisation'] ); ?></dd>
            <dt><?php esc_html_e( 'Funding round', 'rotary-grants' ); ?></dt>
            <dd><?php echo esc_html( $receipt['fund_name'] . ' — ' . $receipt['round_label'] ); ?></dd>
            <dt><?php esc_html_e( 'Amount requested', 'rotary-grants' ); ?></dt>
            <dd><?php echo esc_html( Money::format_gbp( (int) $receipt['amount_pence'] ) ); ?></dd>
            <dt><?php esc_html_e( 'Received', 'rotary-grants' ); ?></dt>
            <dd><?php echo esc_html( SiteTime::display( $receipt['submitted_at'] ) ); ?></dd>
        </dl>
        <?php if ( ! empty( $receipt['email'] ) ) : ?>
            <p>
                <?php
                /* translators: %s: applicant email address */
                echo esc_html( sprintf( __( 'We will also email a copy of this confirmation to %s. If it does not arrive, please check your spam folder — your application has been received either way.', 'rotary-grants' ), $receipt['email'] ) );
                ?>
            </p>
        <?php endif; ?>
        <p><?php esc_html_e( 'Submitting an application does not guarantee funding. The committee will be in touch once applications have been considered.', 'rotary-grants' ); ?></p>
    <?php else : ?>
        <h2><?php esc_html_e( 'Thank you', 'rotary-grants' ); ?></h2>
        <p><?php esc_html_e( 'If you have just submitted an application, it has been received.', 'rotary-grants' ); ?></p>
    <?php endif; ?>
    <?php if ( $help_email !== '' ) : ?>
        <p>
            <?php esc_html_e( 'Questions?', 'rotary-grants' ); ?>
            <a href="<?php echo esc_url( 'mailto:' . $help_email ); ?>"><?php echo esc_html( $help_email ); ?></a>
        </p>
    <?php endif; ?>
</div>
