<?php
/**
 * Portal template — the member's rounds.
 *
 * Available variables: common portal vars (see _nav.php) plus
 *   $rounds list<array{round: object, total: int, undeclared: int, reviewed: int, open: int}>
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\RoundStatus;
use Rotary\Grants\Support\SiteTime;

$crumbs = [];
include __DIR__ . '/_nav.php';
?>
<h2><?php esc_html_e( 'Funding rounds', 'rotary-grants' ); ?></h2>
<?php if ( ! $rounds ) : ?>
    <p><?php esc_html_e( 'There are no funding rounds to review at the moment.', 'rotary-grants' ); ?></p>
<?php endif; ?>
<div class="grants-portal__cards">
    <?php foreach ( $rounds as $r ) : $round = $r['round']; ?>
        <section class="grants-portal__card">
            <h3><a href="<?php echo esc_url( add_query_arg( [ 'view' => 'round', 'round' => $round->id ], $base ) ); ?>"><?php echo esc_html( $round->fund_name . ' — ' . $round->label ); ?></a></h3>
            <p class="grants-portal__muted"><?php echo esc_html( RoundStatus::label( $round->status ) . ' · ' . sprintf( /* translators: %s: date */ __( 'closes %s', 'rotary-grants' ), SiteTime::display( $round->closes_at ) ) ); ?></p>
            <ul>
                <li><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d application', '%d applications', $r['total'], 'rotary-grants' ), $r['total'] ) ); ?></li>
                <li><?php echo esc_html( sprintf( /* translators: %d: count */ __( '%d reviewed by you', 'rotary-grants' ), $r['reviewed'] ) ); ?></li>
                <?php if ( $r['undeclared'] ) : ?>
                    <li class="grants-portal__attention"><a href="<?php echo esc_url( add_query_arg( [ 'view' => 'declare', 'round' => $round->id ], $base ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d needs your conflict-of-interest declaration', '%d need your conflict-of-interest declaration', $r['undeclared'], 'rotary-grants' ), $r['undeclared'] ) ); ?></a></li>
                <?php endif; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</div>
