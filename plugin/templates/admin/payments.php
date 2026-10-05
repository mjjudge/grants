<?php
/**
 * Admin template — payments overview for a round (treasurer).
 *
 * Available variables:
 *   $rounds   object[]    All rounds.
 *   $round    object|null Selected round.
 *   $awards   object[]    Approved awards with ->totals {approved, net_paid, outstanding, progress},
 *                         ->organisation_name, ->public_reference, ->open_conditions.
 *   $budget   array|null  BudgetService::summary() (incl. net_paid, outstanding).
 *   $base_url string      This screen's URL.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\PaymentService;
use Rotary\Grants\Support\Money;

$fmt = static fn( ?int $p ): string => $p === null ? '—' : Money::format_gbp( $p );
?>
<div class="wrap grants-admin">
    <h1><?php esc_html_e( 'Payments', 'rotary-grants' ); ?></h1>
    <p class="description"><?php esc_html_e( 'Make payments through the club\'s usual banking process first, after confirming the recipient\'s bank details independently. Then record them here. This site never sends money and never stores bank details.', 'rotary-grants' ); ?></p>

    <form method="get" class="grants-filters">
        <input type="hidden" name="page" value="grants-payments">
        <select name="round_id" aria-label="<?php esc_attr_e( 'Round', 'rotary-grants' ); ?>" onchange="this.form.submit()">
            <?php foreach ( $rounds as $r ) : ?>
                <option value="<?php echo esc_attr( (string) $r->id ); ?>" <?php selected( $round->id ?? 0, $r->id ); ?>><?php echo esc_html( $r->fund_name . ' — ' . $r->label ); ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="button"><?php esc_html_e( 'Show', 'rotary-grants' ); ?></button></noscript>
    </form>

    <?php if ( $budget ) : ?>
        <table class="widefat grants-budget-box"><tbody><tr>
            <td><span class="description"><?php esc_html_e( 'Approved commitments', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $budget['committed'] ) ); ?></strong></td>
            <td><span class="description"><?php esc_html_e( 'Net paid', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $budget['net_paid'] ) ); ?></strong></td>
            <td><span class="description"><?php esc_html_e( 'Outstanding', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $budget['outstanding'] ) ); ?></strong></td>
        </tr></tbody></table>
    <?php endif; ?>

    <table class="widefat striped">
        <thead><tr>
            <th scope="col"><?php esc_html_e( 'Organisation', 'rotary-grants' ); ?></th>
            <th scope="col" class="num"><?php esc_html_e( 'Approved', 'rotary-grants' ); ?></th>
            <th scope="col" class="num"><?php esc_html_e( 'Paid', 'rotary-grants' ); ?></th>
            <th scope="col" class="num"><?php esc_html_e( 'Outstanding', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Progress', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Ready to pay?', 'rotary-grants' ); ?></th>
        </tr></thead>
        <tbody>
        <?php if ( ! $awards ) : ?>
            <tr><td colspan="6"><?php esc_html_e( 'No approved awards in this round.', 'rotary-grants' ); ?></td></tr>
        <?php endif; ?>
        <?php foreach ( $awards as $aw ) : $t = $aw->totals; ?>
            <tr>
                <td><a href="<?php echo esc_url( add_query_arg( [ 'action' => 'award', 'id' => $aw->id ], $base_url ) ); ?>"><strong><?php echo esc_html( (string) $aw->organisation_name ); ?></strong></a><br><span class="description"><?php echo esc_html( (string) $aw->public_reference ); ?></span></td>
                <td class="num"><?php echo esc_html( $fmt( $t['approved'] ) ); ?></td>
                <td class="num"><?php echo esc_html( $fmt( $t['net_paid'] ) ); ?></td>
                <td class="num"><?php echo esc_html( $fmt( $t['outstanding'] ) ); ?></td>
                <td><span class="grants-progress grants-progress--<?php echo esc_attr( $t['progress'] ); ?>"><?php echo esc_html( PaymentService::progress_label( $t['progress'] ) ); ?></span></td>
                <td>
                    <?php if ( $t['progress'] === PaymentService::PAID ) : ?>—
                    <?php elseif ( $aw->open_conditions ) : ?><span class="grants-badge grants-badge--late"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d condition to meet first', '%d conditions to meet first', $aw->open_conditions, 'rotary-grants' ), $aw->open_conditions ) ); ?></span>
                    <?php elseif ( $aw->approved_pence === null ) : ?><span class="grants-badge grants-badge--late"><?php esc_html_e( 'Amount unknown — reconcile', 'rotary-grants' ); ?></span>
                    <?php else : ?><span class="grants-status--ok"><?php esc_html_e( 'Yes', 'rotary-grants' ); ?></span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
