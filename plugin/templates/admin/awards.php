<?php
/**
 * Admin template — awards for a round.
 *
 * Available variables:
 *   $rounds   object[]    All rounds.
 *   $round    object|null Selected round.
 *   $status   string      'approved' (default), 'cancelled' or 'all'.
 *   $awards   object[]    AwardService::list() rows (organisation_name, public_reference, open_conditions…).
 *   $budget   array|null  BudgetService::summary() for the round.
 *   $apps_url string      Applications screen URL.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\AwardService;
use Rotary\Grants\Support\Money;

$fmt = static fn( ?int $p ): string => $p === null ? '—' : Money::format_gbp( $p );
?>
<div class="wrap grants-admin">
    <h1><?php esc_html_e( 'Awards', 'rotary-grants' ); ?></h1>
    <form method="get" class="grants-filters">
        <input type="hidden" name="page" value="grants-awards">
        <select name="round_id" aria-label="<?php esc_attr_e( 'Round', 'rotary-grants' ); ?>">
            <?php foreach ( $rounds as $r ) : ?>
                <option value="<?php echo esc_attr( (string) $r->id ); ?>" <?php selected( $round->id ?? 0, $r->id ); ?>><?php echo esc_html( $r->fund_name . ' — ' . $r->label ); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" aria-label="<?php esc_attr_e( 'Status', 'rotary-grants' ); ?>">
            <option value="approved" <?php selected( $status, 'approved' ); ?>><?php esc_html_e( 'Approved', 'rotary-grants' ); ?></option>
            <option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'rotary-grants' ); ?></option>
            <option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( 'All', 'rotary-grants' ); ?></option>
        </select>
        <button type="submit" class="button"><?php esc_html_e( 'Show', 'rotary-grants' ); ?></button>
    </form>

    <?php if ( $budget ) : ?>
        <table class="widefat grants-budget-box"><tbody><tr>
            <td><span class="description"><?php esc_html_e( 'Round budget', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $budget['budget'] ) ); ?></strong></td>
            <td><span class="description"><?php esc_html_e( 'Approved commitments', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $budget['committed'] ) ); ?></strong></td>
            <td><span class="description"><?php esc_html_e( 'Available to award', 'rotary-grants' ); ?></span><br>
                <strong class="<?php echo $budget['over_committed'] ? 'grants-over' : ''; ?>"><?php echo esc_html( $budget['over_committed'] ? sprintf( /* translators: %s: amount */ __( 'Over budget by %s', 'rotary-grants' ), Money::format_gbp( $budget['over_committed'] ) ) : $fmt( $budget['available'] ) ); ?></strong></td>
        </tr></tbody></table>
        <?php if ( $budget['incomplete'] ) : ?>
            <p class="description"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d award has an unknown amount and is not included in the total.', '%d awards have unknown amounts and are not included in the total.', $budget['incomplete'], 'rotary-grants' ), $budget['incomplete'] ) ); ?></p>
        <?php endif; ?>
        <?php if ( $budget['over_budget_awards'] ) : ?>
            <div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Approved beyond the round budget, funded from elsewhere:', 'rotary-grants' ); ?></strong></p><ul>
                <?php foreach ( $budget['over_budget_awards'] as $o ) : ?>
                    <li><?php echo esc_html( sprintf( '%s (%s): %s — %s', (string) $o->organisation_name, (string) $o->public_reference, Money::format_gbp( (int) $o->over_budget_pence ), (string) $o->funding_note ) ); ?></li>
                <?php endforeach; ?>
            </ul></div>
        <?php endif; ?>
    <?php endif; ?>

    <table class="widefat striped">
        <thead><tr>
            <th scope="col"><?php esc_html_e( 'Organisation', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Application', 'rotary-grants' ); ?></th>
            <th scope="col" class="num"><?php esc_html_e( 'Approved', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Status', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Approved on', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Conditions to meet before payment', 'rotary-grants' ); ?></th>
        </tr></thead>
        <tbody>
        <?php if ( ! $awards ) : ?>
            <tr><td colspan="6"><?php esc_html_e( 'No awards.', 'rotary-grants' ); ?></td></tr>
        <?php endif; ?>
        <?php foreach ( $awards as $aw ) : ?>
            <tr>
                <td><?php echo esc_html( (string) $aw->organisation_name ); ?><?php if ( $aw->over_budget_pence > 0 ) : ?> <span class="grants-badge grants-badge--late"><?php esc_html_e( 'beyond budget', 'rotary-grants' ); ?></span><?php endif; ?></td>
                <td><?php if ( $aw->application_id ) : ?><a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $aw->application_id ], $apps_url ) . '#grants-decision' ); ?>"><?php echo esc_html( (string) $aw->public_reference ); ?></a><?php else : ?>—<?php endif; ?></td>
                <td class="num"><?php echo esc_html( $fmt( $aw->approved_pence ) ); ?></td>
                <td><?php echo esc_html( AwardService::status_label( $aw->status ) ); ?></td>
                <td><?php echo esc_html( $aw->approved_on ? wp_date( get_option( 'date_format' ), strtotime( $aw->approved_on . ' 12:00:00' ) ) : '—' ); ?></td>
                <td><?php echo $aw->open_conditions ? '<span class="grants-badge grants-badge--late">' . esc_html( sprintf( /* translators: %d: count */ _n( '%d outstanding', '%d outstanding', $aw->open_conditions, 'rotary-grants' ), $aw->open_conditions ) ) . '</span>' : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
