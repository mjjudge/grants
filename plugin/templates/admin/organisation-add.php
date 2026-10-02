<?php
/**
 * Admin template — add an organisation.
 *
 * Available variables:
 *   $organisation null                  (always null here; shared field partial expects it).
 *   $values       array<string,string>  Re-displayed values after an error.
 *   $errors       array<string,string>  Error code => message.
 *   $nonce_action string                Nonce action.
 *   $nonce_field  string                Nonce field name.
 *   $list_url     string                Organisation list URL.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap grants-admin">
    <h1><?php esc_html_e( 'Add Organisation', 'rotary-grants' ); ?></h1>
    <p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'All organisations', 'rotary-grants' ); ?></a></p>
    <p class="description"><?php esc_html_e( 'Usually you won\'t need this: linking an application creates the organisation from what the applicant entered. Check the list first so you don\'t create a duplicate.', 'rotary-grants' ); ?></p>
    <?php include __DIR__ . '/partials/organisation-fields.php'; ?>
</div>
