<?php
/**
 * Admin template — one award: payment ledger and treasurer forms.
 *
 * Available variables:
 *   $award        object        Award row.
 *   $totals       array         PaymentService::totals(): approved, net_paid, outstanding, progress.
 *   $ledger       object[]      Ledger entries oldest first (->actor_name, ->reversed_pence, ->unreversed).
 *   $conditions   object[]      Award conditions.
 *   $open_conds   int           Unmet "before payment" conditions.
 *   $application  object|null   The application.
 *   $organisation object|null   The organisation.
 *   $round        object|null   The round.
 *   $can_pay      bool          grants_pay.
 *   $methods      array<string,string>  Payment method => label.
 *   $notice       string        'paid', 'reversed', 'already_recorded', 'error' or ''.
 *   $error        string        Error message from the last action.
 *   $values       array<string,string>  Payment form values after an error.
 *   $key          string        One-time command key for the payment form.
 *   $nonce_action string        Nonce action.
 *   $nonce_field  string        Nonce field name.
 *   $list_url     string        Payments list for the round.
 *   $apps_url     string        Applications screen URL.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\AwardService;
use Rotary\Grants\Services\PaymentService;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

$fmt  = static fn( ?int $p ): string => $p === null ? '—' : Money::format_gbp( $p );
$day  = static fn( ?string $d ): string => $d ? wp_date( get_option( 'date_format' ), strtotime( $d . ' 12:00:00' ) ) : '—';
$v    = static fn( string $k, string $default = '' ): string => (string) ( $values[ $k ] ?? $default );
$ready = $can_pay && $award->status === AwardService::APPROVED && $award->approved_pence !== null && $open_conds === 0 && (int) $totals['outstanding'] > 0;
$notices = [
    'paid'             => __( 'Payment recorded.', 'rotary-grants' ),
    'reversed'         => __( 'Reversal recorded.', 'rotary-grants' ),
    'already_recorded' => __( 'That form had already been saved — nothing new was recorded.', 'rotary-grants' ),
];
?>
<div class="wrap grants-admin">
    <h1><?php echo esc_html( sprintf( /* translators: %s: organisation */ __( 'Award to %s', 'rotary-grants' ), $organisation->name ?? '?' ) ); ?></h1>
    <p>
        <a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Payments', 'rotary-grants' ); ?></a>
        <?php if ( $application ) : ?> · <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $application->id ], $apps_url ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: reference */ __( 'Application %s', 'rotary-grants' ), $application->public_reference ) ); ?></a><?php endif; ?>
    </p>

    <?php if ( isset( $notices[ $notice ] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
    <?php endif; ?>
    <?php if ( $error !== '' ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
    <?php endif; ?>

    <table class="widefat grants-budget-box"><tbody><tr>
        <td><span class="description"><?php esc_html_e( 'Approved', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $totals['approved'] ) ); ?></strong></td>
        <td><span class="description"><?php esc_html_e( 'Net paid', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $totals['net_paid'] ) ); ?></strong></td>
        <td><span class="description"><?php esc_html_e( 'Outstanding', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $totals['outstanding'] ) ); ?></strong></td>
        <td><span class="description"><?php esc_html_e( 'Progress', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( PaymentService::progress_label( $totals['progress'] ) ); ?></strong></td>
    </tr></tbody></table>
    <p class="description"><?php echo esc_html( sprintf(
        /* translators: 1: round, 2: status, 3: approved date */
        __( '%1$s · award %2$s · approved %3$s', 'rotary-grants' ),
        $round ? $round->fund_name . ' — ' . $round->label : '?',
        AwardService::status_label( $award->status ),
        $day( $award->approved_on )
    ) ); ?></p>

    <?php if ( $conditions ) : ?>
        <h2><?php esc_html_e( 'Conditions', 'rotary-grants' ); ?></h2>
        <ul class="grants-conditions">
            <?php foreach ( $conditions as $c ) : ?>
                <li><?php echo esc_html( $c->condition_text ); ?>
                    <?php if ( (int) $c->before_payment ) : ?><span class="grants-badge"><?php esc_html_e( 'before payment', 'rotary-grants' ); ?></span><?php endif; ?>
                    — <?php echo $c->fulfilled_at ? '<span class="grants-status--ok">' . esc_html( sprintf( /* translators: %s: evidence */ __( 'met: %s', 'rotary-grants' ), (string) $c->evidence_note ) ) . '</span>' : '<span class="grants-badge grants-badge--late">' . esc_html__( 'not yet met', 'rotary-grants' ) . '</span>'; ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if ( $open_conds ) : ?>
            <p><?php esc_html_e( 'Payments can be recorded once the "before payment" conditions are marked as met on the application page.', 'rotary-grants' ); ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Ledger', 'rotary-grants' ); ?></h2>
    <table class="widefat striped grants-ledger">
        <thead><tr>
            <th scope="col"><?php esc_html_e( 'Date', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Entry', 'rotary-grants' ); ?></th>
            <th scope="col" class="num"><?php esc_html_e( 'Amount', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Details', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Recorded', 'rotary-grants' ); ?></th>
            <?php if ( $can_pay ) : ?><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'rotary-grants' ); ?></span></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if ( ! $ledger ) : ?>
            <tr><td colspan="6"><?php esc_html_e( 'Nothing paid yet.', 'rotary-grants' ); ?></td></tr>
        <?php endif; ?>
        <?php foreach ( $ledger as $e ) : $is_pay = $e->entry_type === PaymentService::PAYMENT; ?>
            <tr class="<?php echo $is_pay ? '' : 'grants-ledger__reversal'; ?>">
                <td><?php echo esc_html( $day( $e->paid_on ) ); ?></td>
                <td><?php echo $is_pay ? esc_html__( 'Payment', 'rotary-grants' ) : esc_html( sprintf( /* translators: %d: payment id */ __( 'Reversal of #%d', 'rotary-grants' ), (int) $e->reversed_payment_id ) ); ?> <span class="description">#<?php echo esc_html( (string) $e->id ); ?></span></td>
                <td class="num"><?php echo esc_html( ( $is_pay ? '' : '−' ) . Money::format_gbp( $e->amount_pence ) ); ?></td>
                <td>
                    <?php if ( $is_pay ) : ?>
                        <?php echo esc_html( ( $methods[ $e->method ] ?? $e->method ) . ( $e->method_note !== '' ? ' (' . $e->method_note . ')' : '' ) . ( $e->reference !== '' ? ' · ' . __( 'ref', 'rotary-grants' ) . ' ' . $e->reference : '' ) ); ?>
                        <?php if ( $e->reversed_pence ) : ?><br><span class="description"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( '%s reversed', 'rotary-grants' ), Money::format_gbp( $e->reversed_pence ) ) ); ?></span><?php endif; ?>
                    <?php else : ?>
                        <?php echo esc_html( (string) $e->note ); ?>
                        <br><span class="description"><?php echo (int) $e->refund_received ? esc_html__( 'Money was returned to the club.', 'rotary-grants' ) : esc_html__( 'Ledger correction only — no refund recorded.', 'rotary-grants' ); ?></span>
                    <?php endif; ?>
                    <?php if ( $is_pay && $e->note ) : ?><br><span class="description"><?php echo esc_html( (string) $e->note ); ?></span><?php endif; ?>
                </td>
                <td><?php echo esc_html( (string) $e->actor_name ); ?><br><span class="description"><?php echo esc_html( SiteTime::display( $e->created_at ) ); ?></span></td>
                <?php if ( $can_pay ) : ?>
                    <td>
                        <?php if ( $is_pay && $e->unreversed > 0 ) : ?>
                            <details><summary><?php esc_html_e( 'Reverse / correct', 'rotary-grants' ); ?></summary>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="grants_payment_reverse">
                                    <input type="hidden" name="award_id" value="<?php echo esc_attr( (string) $award->id ); ?>">
                                    <input type="hidden" name="payment_id" value="<?php echo esc_attr( (string) $e->id ); ?>">
                                    <input type="hidden" name="command_key" value="<?php echo esc_attr( bin2hex( random_bytes( 32 ) ) ); ?>">
                                    <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
                                    <p><label><?php esc_html_e( 'Amount to reverse (£)', 'rotary-grants' ); ?><br><input type="text" inputmode="decimal" name="amount" value="<?php echo esc_attr( Money::to_input( $e->unreversed ) ); ?>" required></label>
                                        <span class="description"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'up to %s', 'rotary-grants' ), Money::format_gbp( $e->unreversed ) ) ); ?></span></p>
                                    <p><label><?php esc_html_e( 'Date of correction', 'rotary-grants' ); ?><br><input type="date" name="paid_on" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required></label></p>
                                    <p><label><?php esc_html_e( 'Reason', 'rotary-grants' ); ?><br><input type="text" name="reason" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. entered twice', 'rotary-grants' ); ?>"></label></p>
                                    <p><label><input type="checkbox" name="refund_received" value="1"> <?php esc_html_e( 'Money was actually returned to the club', 'rotary-grants' ); ?></label></p>
                                    <button type="submit" class="button"><?php esc_html_e( 'Record reversal', 'rotary-grants' ); ?></button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $ready ) : ?>
        <h2><?php esc_html_e( 'Record a payment', 'rotary-grants' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Only record a payment that has already been made through the club\'s banking process. Do not enter bank account details here.', 'rotary-grants' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="grants-payment-form">
            <input type="hidden" name="action" value="grants_payment_record">
            <input type="hidden" name="award_id" value="<?php echo esc_attr( (string) $award->id ); ?>">
            <input type="hidden" name="command_key" value="<?php echo esc_attr( $key ); ?>">
            <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="grants-pay-amount"><?php esc_html_e( 'Amount paid (£)', 'rotary-grants' ); ?></label></th>
                    <td><input type="text" inputmode="decimal" id="grants-pay-amount" name="amount" class="regular-text" style="width:10em" value="<?php echo esc_attr( $v( 'amount', Money::to_input( (int) $totals['outstanding'] ) ) ); ?>" required>
                        <span class="description"><?php echo esc_html( sprintf( /* translators: %s: outstanding */ __( 'Outstanding: %s', 'rotary-grants' ), $fmt( $totals['outstanding'] ) ) ); ?></span></td></tr>
                <tr><th scope="row"><label for="grants-pay-date"><?php esc_html_e( 'Date paid', 'rotary-grants' ); ?></label></th>
                    <td><input type="date" id="grants-pay-date" name="paid_on" value="<?php echo esc_attr( $v( 'paid_on', wp_date( 'Y-m-d' ) ) ); ?>" required></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Method', 'rotary-grants' ); ?></th>
                    <td><?php foreach ( $methods as $m => $label ) : ?>
                        <label class="grants-choice-inline"><input type="radio" name="method" value="<?php echo esc_attr( $m ); ?>" <?php checked( $v( 'method', 'bank_transfer' ), $m ); ?>> <?php echo esc_html( $label ); ?></label>
                    <?php endforeach; ?>
                        <br><label><?php esc_html_e( 'If other, how:', 'rotary-grants' ); ?> <input type="text" name="method_note" value="<?php echo esc_attr( $v( 'method_note' ) ); ?>"></label></td></tr>
                <tr><th scope="row"><label for="grants-pay-ref"><?php esc_html_e( 'Reference', 'rotary-grants' ); ?></label></th>
                    <td><input type="text" id="grants-pay-ref" name="reference" class="regular-text" value="<?php echo esc_attr( $v( 'reference' ) ); ?>">
                        <p class="description"><?php esc_html_e( 'e.g. the bank transaction reference or cheque number — not an account number.', 'rotary-grants' ); ?></p></td></tr>
                <tr><th scope="row"><label for="grants-pay-note"><?php esc_html_e( 'Note (optional)', 'rotary-grants' ); ?></label></th>
                    <td><textarea id="grants-pay-note" name="note" rows="2" class="large-text"><?php echo esc_textarea( $v( 'note' ) ); ?></textarea></td></tr>
            </table>
            <?php submit_button( __( 'Record payment', 'rotary-grants' ) ); ?>
        </form>
    <?php elseif ( $can_pay && $totals['progress'] === PaymentService::PAID ) : ?>
        <p><strong><?php esc_html_e( 'This award has been paid in full.', 'rotary-grants' ); ?></strong></p>
    <?php endif; ?>
</div>
