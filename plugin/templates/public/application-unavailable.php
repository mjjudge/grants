<?php
/**
 * Public template — no round is accepting applications for this page.
 *
 * Available variables:
 *   $round          object|null  The round this page points at, if any (scheduled/closed).
 *   $phase          string       RoundService::phase() for $round, or ''.
 *   $help_email     string       Help/contact email from Settings.
 *   $config_problem string       Shortcode set-up problem — shown only to editors, else ''.
 *   $message        string       Message from a submission that just failed (e.g. round closed), or ''.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Support\SiteTime;
?>
<div class="grants-apply grants-apply--closed">
    <?php if ( $message !== '' ) : ?>
        <div class="grants-error-summary" role="alert"><p><?php echo esc_html( $message ); ?></p></div>
    <?php endif; ?>

    <?php if ( $round && $phase === 'scheduled' ) : ?>
        <h2>
            <?php
            /* translators: %s: fund name */
            echo esc_html( sprintf( __( 'Applications for %s funding are not open yet', 'rotary-grants' ), $round->fund_name ) );
            ?>
        </h2>
        <p>
            <?php
            /* translators: 1: round label, 2: opening date/time */
            echo esc_html( sprintf( __( '%1$s opens for applications on %2$s.', 'rotary-grants' ), $round->label, SiteTime::display( $round->opens_at ) ) );
            ?>
        </p>
    <?php elseif ( $round ) : ?>
        <h2>
            <?php
            /* translators: %s: fund name */
            echo esc_html( sprintf( __( 'Applications for %s funding are closed', 'rotary-grants' ), $round->fund_name ) );
            ?>
        </h2>
        <p><?php esc_html_e( 'This funding round is no longer accepting applications.', 'rotary-grants' ); ?></p>
    <?php else : ?>
        <h2><?php esc_html_e( 'Applications are not currently open', 'rotary-grants' ); ?></h2>
        <p><?php esc_html_e( 'Please check back later.', 'rotary-grants' ); ?></p>
    <?php endif; ?>

    <?php if ( $help_email !== '' ) : ?>
        <p>
            <?php esc_html_e( 'Questions?', 'rotary-grants' ); ?>
            <a href="<?php echo esc_url( 'mailto:' . $help_email ); ?>"><?php echo esc_html( $help_email ); ?></a>
        </p>
    <?php endif; ?>

    <?php if ( $config_problem !== '' ) : ?>
        <p class="grants-config-problem"><strong><?php esc_html_e( 'Note for site editors:', 'rotary-grants' ); ?></strong> <?php echo esc_html( $config_problem ); ?></p>
    <?php endif; ?>
</div>
