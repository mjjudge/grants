<?php
/**
 * Portal template — sign in.
 *
 * Available variables: $login_form string (wp_login_form() HTML, redirecting back here), $lost_url string.
 */
defined( 'ABSPATH' ) || exit;
?>
<h2><?php esc_html_e( 'Grants committee', 'rotary-grants' ); ?></h2>
<p><?php esc_html_e( 'Sign in to review grant applications.', 'rotary-grants' ); ?></p>
<?php echo $login_form; // phpcs:ignore WordPress.Security.EscapeOutput -- core wp_login_form() markup ?>
<p><a href="<?php echo esc_url( $lost_url ); ?>"><?php esc_html_e( 'Forgotten your password?', 'rotary-grants' ); ?></a></p>
