<?php
/**
 * Admin template — funding round list.
 *
 * Available variables:
 *   $rounds     object[]           Hydrated grants_rounds rows (see RoundService::find()).
 *   $phases     array<int,string>  Round id => RoundService::phase() result.
 *   $status     string             Active status filter ('' for all).
 *   $can_manage bool               Whether the user may add/edit rounds.
 *   $base_url   string             List URL without a status filter.
 *   $add_url    string             Add-round URL.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\RoundStatus;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

$phase_labels = [
    'scheduled'       => __( 'Open — not yet accepting (scheduled)', 'rotary-grants' ),
    'accepting'       => __( 'Open — accepting applications', 'rotary-grants' ),
    'deadline_passed' => __( 'Open — deadline passed', 'rotary-grants' ),
];
?>
<div class="wrap grants-admin">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Funding Rounds', 'rotary-grants' ); ?></h1>
    <?php if ( $can_manage ) : ?>
        <a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add Round', 'rotary-grants' ); ?></a>
    <?php endif; ?>
    <hr class="wp-header-end">

    <ul class="subsubsub">
        <li><a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo $status === '' ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'rotary-grants' ); ?></a></li>
        <?php foreach ( RoundStatus::all() as $s ) : ?>
            <li> | <a href="<?php echo esc_url( add_query_arg( 'status', $s, $base_url ) ); ?>" class="<?php echo $status === $s ? 'current' : ''; ?>"><?php echo esc_html( RoundStatus::label( $s ) ); ?></a></li>
        <?php endforeach; ?>
    </ul>

    <table class="widefat striped grants-round-list">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e( 'Round', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Fund', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Year', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Status', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Opens', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Closes', 'rotary-grants' ); ?></th>
                <th scope="col" class="num"><?php esc_html_e( 'Budget', 'rotary-grants' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! $rounds ) : ?>
                <tr><td colspan="7"><?php esc_html_e( 'No funding rounds yet.', 'rotary-grants' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $rounds as $round ) : ?>
                <tr>
                    <td>
                        <?php if ( $can_manage ) : ?>
                            <strong><a href="<?php echo esc_url( add_query_arg( [ 'action' => 'edit', 'id' => $round->id ], $base_url ) ); ?>"><?php echo esc_html( $round->label ); ?></a></strong>
                        <?php else : ?>
                            <strong><?php echo esc_html( $round->label ); ?></strong>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $round->fund_name ); ?></td>
                    <td><?php echo esc_html( (string) $round->campaign_year ); ?></td>
                    <td><span class="grants-round-status grants-round-status--<?php echo esc_attr( $phases[ $round->id ] ); ?>"><?php echo esc_html( $phase_labels[ $phases[ $round->id ] ] ?? RoundStatus::label( $round->status ) ); ?></span></td>
                    <td><?php echo esc_html( SiteTime::display( $round->opens_at ) ?: '—' ); ?></td>
                    <td><?php echo esc_html( SiteTime::display( $round->closes_at ) ?: '—' ); ?></td>
                    <td class="num"><?php echo esc_html( $round->budget_pence === null ? '—' : Money::format_gbp( $round->budget_pence ) ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
