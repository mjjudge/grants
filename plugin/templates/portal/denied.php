<?php
/**
 * Portal template — signed in but not a committee member.
 *
 * Available variables: $logout_url string.
 */
defined( 'ABSPATH' ) || exit;
?>
<h2><?php esc_html_e( 'Grants committee', 'rotary-grants' ); ?></h2>
<p><?php esc_html_e( 'Your account does not have access to the grants committee. If you think it should, ask the site administrator.', 'rotary-grants' ); ?></p>
<p><a href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Sign out', 'rotary-grants' ); ?></a></p>
