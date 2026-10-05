<?php
/**
 * Portal partial — top bar.
 *
 * Available variables: $user WP_User, $base string (page URL), $logout_url, $profile_url,
 * $admin_url string ('' for portal-only users), $crumbs array<string,string> label => url ('' = current).
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="grants-portal__bar">
    <nav class="grants-portal__crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'rotary-grants' ); ?>">
        <a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Grants committee', 'rotary-grants' ); ?></a>
        <?php foreach ( $crumbs ?? [] as $label => $url ) : ?>
            › <?php echo $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : '<span aria-current="page">' . esc_html( $label ) . '</span>'; ?>
        <?php endforeach; ?>
    </nav>
    <div class="grants-portal__me">
        <?php echo esc_html( $user->display_name ); ?> ·
        <a href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Password', 'rotary-grants' ); ?></a> ·
        <?php if ( $admin_url !== '' ) : ?><a href="<?php echo esc_url( $admin_url ); ?>"><?php esc_html_e( 'Back office', 'rotary-grants' ); ?></a> · <?php endif; ?>
        <a href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Sign out', 'rotary-grants' ); ?></a>
    </div>
</div>
