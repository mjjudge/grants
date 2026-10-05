<?php
/**
 * Admin template — Reports.
 *
 * Available variables:
 *   $tabs        array<string,string>  Visible tab => label (filtered by capability).
 *   $tab         string                Active tab.
 *   $rounds      object[]              All rounds.
 *   $round       object|null           Selected round (round-based tabs).
 *   $years       int[]                 Reporting years with payments (newest first).
 *   $year        int                   Selected reporting year.
 *   $year_label  callable(int):string  Label for a reporting year ("2027" / "2026–27").
 *   $data        mixed                 overview: ReportService::round_overview(); committee: shortlist rows;
 *                                      financial: {rows, totals}; payments: payments_in_year(); contacts: rows.
 *   $can_export  bool                  User may download this tab's CSV.
 *   $export_type string                Export type for this tab ('' for overview).
 *   $error       bool                  The last export failed.
 *   $base_url    string                Reports URL.
 *   $nonce_action, $nonce_field string Export form nonce.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Services\AwardService;
use Rotary\Grants\Services\DecisionService;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

$fmt = static fn( ?int $p ): string => $p === null ? '—' : Money::format_gbp( $p );
$day = static fn( ?string $d ): string => $d ? wp_date( get_option( 'date_format' ), strtotime( substr( $d, 0, 10 ) . ' 12:00:00' ) ) : '—';
$round_picker = static function () use ( $rounds, $round, $tab ): void {
    ?>
    <form method="get" class="grants-filters">
        <input type="hidden" name="page" value="grants-reports"><input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
        <select name="round_id" aria-label="<?php esc_attr_e( 'Round', 'rotary-grants' ); ?>" onchange="this.form.submit()">
            <?php foreach ( $rounds as $r ) : ?>
                <option value="<?php echo esc_attr( (string) $r->id ); ?>" <?php selected( $round->id ?? 0, $r->id ); ?>><?php echo esc_html( $r->fund_name . ' — ' . $r->label ); ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="button"><?php esc_html_e( 'Show', 'rotary-grants' ); ?></button></noscript>
    </form>
    <?php
};
$download = static function () use ( $can_export, $export_type, $round, $year, $nonce_action, $nonce_field ): void {
    if ( ! $can_export ) {
        return;
    }
    ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="grants-inline-form grants-download">
        <input type="hidden" name="action" value="grants_export">
        <input type="hidden" name="type" value="<?php echo esc_attr( $export_type ); ?>">
        <input type="hidden" name="round_id" value="<?php echo esc_attr( (string) ( $round->id ?? 0 ) ); ?>">
        <input type="hidden" name="year" value="<?php echo esc_attr( (string) $year ); ?>">
        <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
        <button type="submit" class="button"><?php esc_html_e( 'Download CSV', 'rotary-grants' ); ?></button>
    </form>
    <?php
};
?>
<div class="wrap grants-admin">
    <h1><?php esc_html_e( 'Reports', 'rotary-grants' ); ?></h1>
    <nav class="nav-tab-wrapper">
        <?php foreach ( $tabs as $key => $label ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'tab' => $key, 'round_id' => $round->id ?? 0 ], $base_url ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ( $error ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'The export could not be produced.', 'rotary-grants' ); ?></p></div><?php endif; ?>

    <?php if ( $tab === 'overview' ) : ?>
        <?php $round_picker(); ?>
        <?php if ( $data ) : $b = $data['budget']; ?>
            <h2><?php echo esc_html( $data['round']->fund_name . ' — ' . $data['round']->label ); ?></h2>
            <table class="widefat striped grants-kv"><tbody>
                <tr><th scope="row"><?php esc_html_e( 'Applications', 'rotary-grants' ); ?></th><td><?php echo esc_html( (string) $data['applications'] ); ?>
                    <?php if ( $data['duplicates'] ) : ?><span class="description"><?php echo esc_html( sprintf( /* translators: %d: count */ __( '(+ %d marked duplicate, not counted)', 'rotary-grants' ), $data['duplicates'] ) ); ?></span><?php endif; ?></td></tr>
                <?php foreach ( $data['by_status'] as $status => $n ) : if ( ! $n ) { continue; } ?>
                    <tr><th scope="row">&nbsp;&nbsp;<?php echo esc_html( ApplicationStatus::label( $status ) ); ?></th><td><?php echo esc_html( (string) $n ); ?></td></tr>
                <?php endforeach; ?>
                <tr><th scope="row"><?php esc_html_e( 'Not yet linked to an organisation', 'rotary-grants' ); ?></th><td><?php echo esc_html( (string) $data['unlinked'] ); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Total requested (excl. withdrawn)', 'rotary-grants' ); ?></th><td><?php echo esc_html( $fmt( $data['requested'] ) ); ?>
                    <?php if ( $data['requested_unknown'] ) : ?><span class="description"><?php echo esc_html( sprintf( /* translators: %d: count */ __( '+ %d with unknown amount', 'rotary-grants' ), $data['requested_unknown'] ) ); ?></span><?php endif; ?></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Decisions', 'rotary-grants' ); ?></th><td><?php echo esc_html( sprintf( /* translators: 1: approved, 2: declined, 3: deferred */ __( '%1$d approved · %2$d declined · %3$d deferred', 'rotary-grants' ), $data['decisions']['approve'], $data['decisions']['decline'], $data['decisions']['defer'] ) ); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Budget', 'rotary-grants' ); ?></th><td><?php echo esc_html( $fmt( $b['budget'] ) ); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Approved commitments', 'rotary-grants' ); ?></th><td><?php echo esc_html( $fmt( $b['committed'] ) ); ?>
                    <?php if ( $b['incomplete'] ) : ?><span class="description"><?php echo esc_html( sprintf( /* translators: %d: count */ __( '+ %d award(s) with unknown amount, not included', 'rotary-grants' ), $b['incomplete'] ) ); ?></span><?php endif; ?></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Available to award', 'rotary-grants' ); ?></th><td class="<?php echo $b['over_committed'] ? 'grants-over' : ''; ?>"><?php echo esc_html( $b['over_committed'] ? sprintf( /* translators: %s: amount */ __( 'Over budget by %s', 'rotary-grants' ), Money::format_gbp( $b['over_committed'] ) ) : $fmt( $b['available'] ) ); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Net paid', 'rotary-grants' ); ?></th><td><?php echo esc_html( $fmt( $b['net_paid'] ) ); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Outstanding', 'rotary-grants' ); ?></th><td><?php echo esc_html( $fmt( $b['outstanding'] ) ); ?></td></tr>
            </tbody></table>
            <?php if ( $b['over_budget_awards'] ) : ?>
                <h3><?php esc_html_e( 'Approved beyond the round budget', 'rotary-grants' ); ?></h3>
                <ul><?php foreach ( $b['over_budget_awards'] as $o ) : ?><li><?php echo esc_html( sprintf( '%s (%s): %s — %s', (string) $o->organisation_name, (string) $o->public_reference, Money::format_gbp( (int) $o->over_budget_pence ), (string) $o->funding_note ) ); ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ( $tab === 'committee' ) : ?>
        <?php $round_picker(); $download(); ?>
        <p class="description"><?php esc_html_e( 'Applications still before the committee or decided — duplicates and withdrawn applications are left out. Recommendation counts are hidden on applications where you declared a conflict.', 'rotary-grants' ); ?></p>
        <table class="widefat striped">
            <thead><tr>
                <th scope="col"><?php esc_html_e( 'Reference', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Organisation', 'rotary-grants' ); ?></th>
                <th scope="col" class="num"><?php esc_html_e( 'Requested', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Reviews (fund / part / decline / defer)', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Decision', 'rotary-grants' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( ! $data ) : ?><tr><td colspan="6"><?php esc_html_e( 'No applications.', 'rotary-grants' ); ?></td></tr><?php endif; ?>
            <?php foreach ( $data as $r ) : ?>
                <tr>
                    <td><?php echo esc_html( $r['public_reference'] ); ?><?php echo $r['source'] !== ApplicationService::SOURCE_PUBLIC ? ' <span class="grants-badge">' . esc_html__( 'staff entry', 'rotary-grants' ) . '</span>' : ''; ?><?php echo (int) $r['is_late'] ? ' <span class="grants-badge grants-badge--late">' . esc_html__( 'Late', 'rotary-grants' ) . '</span>' : ''; ?></td>
                    <td><?php echo esc_html( $r['organisation_name'] . ( $r['organisation_town'] ? ' — ' . $r['organisation_town'] : '' ) ); ?></td>
                    <td class="num"><?php echo esc_html( $fmt( $r['requested_pence'] === null ? null : (int) $r['requested_pence'] ) ); ?></td>
                    <td><?php echo esc_html( ApplicationStatus::label( $r['status'] ) ); ?></td>
                    <td><?php echo $r['conflicted'] ? '<em>' . esc_html__( 'hidden — you declared a conflict', 'rotary-grants' ) . '</em>' : esc_html( sprintf( '%d  (%d / %d / %d / %d)', $r['reviews'], $r['rec_fund'], $r['rec_part'], $r['rec_decline'], $r['rec_defer'] ) ); ?></td>
                    <td><?php echo esc_html( $r['decision'] ? DecisionService::label( $r['decision'] ) : '—' ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php elseif ( $tab === 'financial' ) : ?>
        <?php $round_picker(); $download(); ?>
        <?php if ( $data ) : $t = $data['totals']; ?>
            <table class="widefat grants-budget-box"><tbody><tr>
                <td><span class="description"><?php esc_html_e( 'Approved', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $t['approved'] ) ); ?></strong><?php echo $t['unknown_amount'] ? '<br><span class="description">' . esc_html( sprintf( /* translators: %d: count */ __( '+ %d unknown', 'rotary-grants' ), $t['unknown_amount'] ) ) . '</span>' : ''; ?></td>
                <td><span class="description"><?php esc_html_e( 'Net paid', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $t['net_paid'] ) ); ?></strong></td>
                <td><span class="description"><?php esc_html_e( 'Outstanding', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $t['outstanding'] ) ); ?></strong></td>
                <td><span class="description"><?php esc_html_e( 'Beyond budget', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $t['over_budget'] ) ); ?></strong></td>
            </tr></tbody></table>
            <table class="widefat striped">
                <thead><tr>
                    <th scope="col"><?php esc_html_e( 'Organisation', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'rotary-grants' ); ?></th>
                    <th scope="col" class="num"><?php esc_html_e( 'Approved', 'rotary-grants' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Net paid', 'rotary-grants' ); ?></th>
                    <th scope="col" class="num"><?php esc_html_e( 'Outstanding', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Progress', 'rotary-grants' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Beyond budget / funding note', 'rotary-grants' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( ! $data['rows'] ) : ?><tr><td colspan="7"><?php esc_html_e( 'No awards.', 'rotary-grants' ); ?></td></tr><?php endif; ?>
                <?php foreach ( $data['rows'] as $r ) : ?>
                    <tr class="<?php echo $r['status'] !== 'approved' ? 'grants-row-former' : ''; ?>">
                        <td><?php echo esc_html( (string) $r['organisation_name'] ); ?><br><span class="description"><?php echo esc_html( (string) $r['public_reference'] ); ?></span></td>
                        <td><?php echo esc_html( AwardService::status_label( $r['status'] ) ); ?></td>
                        <td class="num"><?php echo esc_html( $fmt( $r['approved_pence'] ) ); ?></td>
                        <td class="num"><?php echo esc_html( $fmt( $r['net_paid'] ) ); ?></td>
                        <td class="num"><?php echo esc_html( $fmt( $r['outstanding'] ) ); ?></td>
                        <td><?php echo esc_html( $r['progress'] ); ?></td>
                        <td><?php echo (int) $r['over_budget_pence'] ? esc_html( Money::format_gbp( (int) $r['over_budget_pence'] ) . ' — ' . (string) $r['funding_note'] ) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php elseif ( $tab === 'payments' ) : ?>
        <form method="get" class="grants-filters">
            <input type="hidden" name="page" value="grants-reports"><input type="hidden" name="tab" value="payments">
            <select name="year" aria-label="<?php esc_attr_e( 'Reporting year', 'rotary-grants' ); ?>" onchange="this.form.submit()">
                <?php foreach ( $years as $y ) : ?><option value="<?php echo esc_attr( (string) $y ); ?>" <?php selected( $year, $y ); ?>><?php echo esc_html( $year_label( $y ) ); ?></option><?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="button"><?php esc_html_e( 'Show', 'rotary-grants' ); ?></button></noscript>
        </form>
        <?php $download(); ?>
        <p class="description"><?php echo esc_html( sprintf(
            /* translators: 1: year label, 2: from date, 3: to date */
            __( 'Payments and reversals dated in %1$s (%2$s to %3$s), whichever round the award belongs to. The reporting year can be changed in Settings.', 'rotary-grants' ),
            $data['label'], $day( $data['from'] ), $day( gmdate( 'Y-m-d', strtotime( $data['to'] . ' -1 day' ) ) )
        ) ); ?></p>
        <table class="widefat grants-budget-box"><tbody><tr>
            <td><span class="description"><?php esc_html_e( 'Payments', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $data['totals']['payments'] ) ); ?></strong></td>
            <td><span class="description"><?php esc_html_e( 'Reversals', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $data['totals']['reversals'] ? '−' . Money::format_gbp( $data['totals']['reversals'] ) : '—' ); ?></strong></td>
            <td><span class="description"><?php esc_html_e( 'Net paid out', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $data['totals']['net'] ) ); ?></strong></td>
        </tr></tbody></table>
        <?php if ( $data['by_round'] ) : ?>
            <p><?php esc_html_e( 'By round:', 'rotary-grants' ); ?> <?php echo esc_html( implode( ' · ', array_map( static fn( $k, $v ) => $k . ': ' . Money::format_gbp( $v ), array_keys( $data['by_round'] ), $data['by_round'] ) ) ); ?></p>
        <?php endif; ?>
        <table class="widefat striped">
            <thead><tr><th scope="col"><?php esc_html_e( 'Date', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Organisation', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Round', 'rotary-grants' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Amount', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Method / reference', 'rotary-grants' ); ?></th></tr></thead>
            <tbody>
            <?php if ( ! $data['rows'] ) : ?><tr><td colspan="5"><?php esc_html_e( 'No payments dated in this year.', 'rotary-grants' ); ?></td></tr><?php endif; ?>
            <?php foreach ( $data['rows'] as $r ) : ?>
                <tr class="<?php echo $r['signed_pence'] < 0 ? 'grants-ledger__reversal' : ''; ?>">
                    <td><?php echo esc_html( $day( $r['paid_on'] ) ); ?></td>
                    <td><?php echo esc_html( (string) $r['organisation_name'] ); ?></td>
                    <td><?php echo esc_html( $r['round_name'] ); ?></td>
                    <td class="num"><?php echo esc_html( ( $r['signed_pence'] < 0 ? '−' : '' ) . Money::format_gbp( abs( $r['signed_pence'] ) ) ); ?></td>
                    <td><?php echo esc_html( $r['method_label'] . ( $r['reference'] !== '' ? ' · ' . $r['reference'] : '' ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php else : ?>
        <?php $download(); ?>
        <p class="description"><?php esc_html_e( 'Current contacts who asked to be emailed about future funding rounds and have not withdrawn that permission. Former contacts, withdrawn permissions and contacts without an email address are excluded. Use this list only to tell people about future rounds.', 'rotary-grants' ); ?></p>
        <table class="widefat striped">
            <thead><tr><th scope="col"><?php esc_html_e( 'Name', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Email', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Organisation', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Permission given', 'rotary-grants' ); ?></th></tr></thead>
            <tbody>
            <?php if ( ! $data ) : ?><tr><td colspan="4"><?php esc_html_e( 'No contacts have given permission.', 'rotary-grants' ); ?></td></tr><?php endif; ?>
            <?php foreach ( $data as $r ) : ?>
                <tr><td><?php echo esc_html( $r['name'] ); ?></td><td><?php echo esc_html( $r['email'] ); ?></td><td><?php echo esc_html( $r['organisation_name'] ); ?></td><td><?php echo esc_html( SiteTime::display( $r['recorded_at'] ) . ' · ' . $r['evidence_source'] ); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
