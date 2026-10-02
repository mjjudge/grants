<?php
/**
 * Admin partial — organisation details form (add or correct).
 *
 * Available variables:
 *   $organisation object|null           Organisation being corrected, or null to add.
 *   $values       array<string,string>  Re-displayed values after an error (else from $organisation).
 *   $errors       array<string,string>  Error code (org_<field>, reason, conflict…) => message.
 *   $nonce_action string                Nonce action.
 *   $nonce_field  string                Nonce field name.
 */
defined( 'ABSPATH' ) || exit;

$org_fields = [
    'name'           => __( 'Name', 'rotary-grants' ),
    'charity_number' => __( 'Charity number', 'rotary-grants' ),
    'town'           => __( 'Town or village', 'rotary-grants' ),
    'postcode'       => __( 'Postcode', 'rotary-grants' ),
    'website_url'    => __( 'Website', 'rotary-grants' ),
];
?>
<?php if ( $errors ) : ?>
    <div class="notice notice-error"><ul>
        <?php foreach ( $errors as $m ) : ?><li><?php echo esc_html( $m ); ?></li><?php endforeach; ?>
    </ul></div>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <input type="hidden" name="action" value="grants_org_save">
    <input type="hidden" name="organisation_id" value="<?php echo esc_attr( (string) ( $organisation->id ?? 0 ) ); ?>">
    <input type="hidden" name="row_version" value="<?php echo esc_attr( (string) ( $organisation->row_version ?? 0 ) ); ?>">
    <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
    <table class="form-table" role="presentation">
        <?php foreach ( $org_fields as $f => $label ) : ?>
            <tr>
                <th scope="row"><label for="grants-org-<?php echo esc_attr( $f ); ?>"><?php echo esc_html( $label ); ?></label></th>
                <td>
                    <input type="<?php echo $f === 'website_url' ? 'url' : 'text'; ?>" id="grants-org-<?php echo esc_attr( $f ); ?>" name="<?php echo esc_attr( $f ); ?>" class="regular-text"
                           value="<?php echo esc_attr( (string) ( $values[ $f ] ?? $organisation->$f ?? '' ) ); ?>"
                           <?php echo isset( $errors[ 'org_' . $f ] ) ? 'aria-invalid="true"' : ''; ?>>
                    <?php if ( isset( $errors[ 'org_' . $f ] ) ) : ?><p class="grants-field-error"><?php echo esc_html( $errors[ 'org_' . $f ] ); ?></p><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ( $organisation ) : ?>
            <tr>
                <th scope="row"><label for="grants-org-reason"><?php esc_html_e( 'Reason for the change', 'rotary-grants' ); ?></label></th>
                <td>
                    <input type="text" id="grants-org-reason" name="reason" class="large-text" placeholder="<?php esc_attr_e( 'e.g. renamed in 2025; typo in charity number', 'rotary-grants' ); ?>">
                    <p class="description"><?php esc_html_e( 'The previous values are kept in the correction history below. Past applications are not changed.', 'rotary-grants' ); ?></p>
                </td>
            </tr>
        <?php endif; ?>
    </table>
    <?php submit_button( $organisation ? __( 'Save corrections', 'rotary-grants' ) : __( 'Add organisation', 'rotary-grants' ) ); ?>
</form>
