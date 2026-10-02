<?php
/**
 * Admin template — "Rotary Grants access" section on the user-edit screen.
 * Rendered for administrators only (see Admin\UserAccessSection).
 *
 * Available variables:
 *   $user         WP_User               The user being edited.
 *   $labels       array<string,string>  Capability => human-readable label.
 *   $direct       string[]              grants_* capabilities granted to this user directly.
 *   $via_role     string[]              grants_* capabilities held through the user's role(s).
 *   $nonce_action string                Nonce action.
 *   $nonce_field  string                Nonce field name.
 */
defined( 'ABSPATH' ) || exit;
?>
<h2 id="grants-access"><?php esc_html_e( 'Rotary Grants access', 'rotary-grants' ); ?></h2>
<?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e( 'Capabilities', 'rotary-grants' ); ?></th>
        <td>
            <fieldset>
                <legend class="screen-reader-text"><?php esc_html_e( 'Rotary Grants capabilities', 'rotary-grants' ); ?></legend>
                <?php foreach ( $labels as $cap => $label ) : ?>
                    <?php $by_role = in_array( $cap, $via_role, true ); ?>
                    <label style="display:block;margin-bottom:4px;">
                        <input type="checkbox" name="grants_caps[]" value="<?php echo esc_attr( $cap ); ?>"
                            <?php checked( $by_role || in_array( $cap, $direct, true ) ); ?>
                            <?php disabled( $by_role ); ?>>
                        <?php echo esc_html( $label ); ?>
                        <?php if ( $by_role ) : ?>
                            <span class="description"><?php esc_html_e( '(held through this user\'s role)', 'rotary-grants' ); ?></span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
                <p class="description"><?php esc_html_e( 'Grant only what the committee has agreed for this person. Any capability also grants basic access to the Rotary Grants menu. Every change is recorded in the audit log.', 'rotary-grants' ); ?></p>
            </fieldset>
        </td>
    </tr>
</table>
