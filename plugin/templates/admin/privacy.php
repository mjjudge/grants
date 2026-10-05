<?php
/**
 * Admin template — privacy & retention.
 *
 * Available variables:
 *   $plan         array   RetentionService::plan(): category => {label, months, cutoff, items[{id,label,since}], keeps}.
 *   $confirmed    bool    The club has confirmed its retention periods in Settings.
 *   $result       array|false  Counts from the last Apply (or ['error' => msg]), shown once.
 *   $settings_url string  Settings → Retention.
 *   $export_url   string  WordPress Tools → Export Personal Data.
 *   $erase_url    string  WordPress Tools → Erase Personal Data.
 *   $nonce_action, $nonce_field string
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Support\SiteTime;

$total = array_sum( array_map( static fn( $c ) => count( $c['items'] ), $plan ) );
?>
<div class="wrap grants-admin">
    <h1><?php esc_html_e( 'Privacy & retention', 'rotary-grants' ); ?></h1>

    <?php if ( is_array( $result ) && isset( $result['error'] ) ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $result['error'] ); ?></p></div>
    <?php elseif ( is_array( $result ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf(
            /* translators: 1-4: counts */
            __( 'Retention applied: %1$d applications without an award, %2$d with an award, %3$d former contacts and %4$d email log entries anonymised.', 'rotary-grants' ),
            $result['unsuccessful'], $result['award'], $result['contact'], $result['notification']
        ) ); ?></p></div>
    <?php endif; ?>

    <?php if ( ! $confirmed ) : ?>
        <div class="notice notice-warning inline"><p>
            <strong><?php esc_html_e( 'These are suggested retention periods.', 'rotary-grants' ); ?></strong>
            <?php esc_html_e( 'Agree them with your trustees, adjust if needed and tick "confirmed" in Settings.', 'rotary-grants' ); ?>
            <a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Retention settings', 'rotary-grants' ); ?></a>
        </p></div>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Requests from individuals', 'rotary-grants' ); ?></h2>
    <p><?php esc_html_e( 'Use WordPress\'s own tools for access and erasure requests — they include grant applications, contacts, email permissions and the email log. WordPress confirms the request by email before anything is exported or erased.', 'rotary-grants' ); ?>
        <a href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export Personal Data', 'rotary-grants' ); ?></a> ·
        <a href="<?php echo esc_url( $erase_url ); ?>"><?php esc_html_e( 'Erase Personal Data', 'rotary-grants' ); ?></a></p>

    <h2><?php esc_html_e( 'Retention — what would happen now (dry run)', 'rotary-grants' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Nothing changes until you press Apply. Records are anonymised, not deleted: awards and payments are always kept as financial records. Applications still being considered are never included.', 'rotary-grants' ); ?></p>
    <?php foreach ( $plan as $key => $cat ) : ?>
        <h3><?php echo esc_html( sprintf( /* translators: 1: category, 2: months, 3: count */ __( '%1$s — older than %2$d months: %3$d', 'rotary-grants' ), $cat['label'], $cat['months'], count( $cat['items'] ) ) ); ?></h3>
        <p class="description"><?php echo esc_html( $cat['keeps'] ); ?></p>
        <?php if ( $cat['items'] ) : ?>
            <details><summary><?php esc_html_e( 'Show which', 'rotary-grants' ); ?></summary><ul>
                <?php foreach ( $cat['items'] as $it ) : ?>
                    <li><?php echo esc_html( $it['label'] . ' (' . SiteTime::display( $it['since'] ) . ')' ); ?></li>
                <?php endforeach; ?>
            </ul></details>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ( $total > 0 ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( sprintf( /* translators: %d: count */ __( 'Anonymise %d records now? This cannot be undone (except by restoring a backup).', 'rotary-grants' ), $total ) ) ); ?>);">
            <input type="hidden" name="action" value="grants_retention_apply">
            <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
            <?php submit_button( __( 'Apply retention now', 'rotary-grants' ), 'primary', 'submit', false ); ?>
        </form>
    <?php else : ?>
        <p><strong><?php esc_html_e( 'Nothing is due.', 'rotary-grants' ); ?></strong></p>
    <?php endif; ?>
</div>
