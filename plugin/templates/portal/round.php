<?php
/**
 * Portal template — a round's applications.
 *
 * Available variables: common portal vars plus
 *   $round  object
 *   $rows   list<array{app: object, declaration: string, my_review: bool, reviews: int, decision: string}>
 *   $budget array  BudgetService::summary()
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Services\ConflictService;
use Rotary\Grants\Support\Money;

$crumbs = [ $round->fund_name . ' — ' . $round->label => '' ];
include __DIR__ . '/_nav.php';
?>
<h2><?php echo esc_html( $round->fund_name . ' — ' . $round->label ); ?></h2>
<p class="grants-portal__stats">
    <?php esc_html_e( 'Budget', 'rotary-grants' ); ?> <strong><?php echo esc_html( $budget['budget'] === null ? '—' : Money::format_gbp( $budget['budget'] ) ); ?></strong> ·
    <?php esc_html_e( 'approved', 'rotary-grants' ); ?> <strong><?php echo esc_html( Money::format_gbp( $budget['committed'] ) ); ?></strong> ·
    <?php if ( $budget['over_committed'] ) : ?>
        <strong class="grants-portal__warn"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'over budget by %s', 'rotary-grants' ), Money::format_gbp( $budget['over_committed'] ) ) ); ?></strong>
    <?php else : ?>
        <?php esc_html_e( 'available', 'rotary-grants' ); ?> <strong><?php echo esc_html( $budget['available'] === null ? '—' : Money::format_gbp( $budget['available'] ) ); ?></strong>
    <?php endif; ?>
</p>
<p><a class="grants-portal__button" href="<?php echo esc_url( add_query_arg( [ 'view' => 'declare', 'round' => $round->id ], $base ) ); ?>"><?php esc_html_e( 'Declare conflicts for this round', 'rotary-grants' ); ?></a></p>

<div class="grants-portal__table-wrap">
<table class="grants-portal__table">
    <thead><tr>
        <th scope="col"><?php esc_html_e( 'Organisation', 'rotary-grants' ); ?></th>
        <th scope="col" class="num"><?php esc_html_e( 'Requested', 'rotary-grants' ); ?></th>
        <th scope="col"><?php esc_html_e( 'Status', 'rotary-grants' ); ?></th>
        <th scope="col"><?php esc_html_e( 'You', 'rotary-grants' ); ?></th>
        <th scope="col" class="num"><?php esc_html_e( 'Reviews', 'rotary-grants' ); ?></th>
    </tr></thead>
    <tbody>
    <?php if ( ! $rows ) : ?><tr><td colspan="5"><?php esc_html_e( 'No applications yet.', 'rotary-grants' ); ?></td></tr><?php endif; ?>
    <?php foreach ( $rows as $r ) : $a = $r['app']; $conflicted = in_array( $r['declaration'], [ ConflictService::FINANCIAL, ConflictService::LOYALTY ], true ); ?>
        <tr class="<?php echo $a->status === ApplicationStatus::WITHDRAWN ? 'grants-portal__dim' : ''; ?>">
            <td><a href="<?php echo esc_url( add_query_arg( [ 'view' => 'application', 'id' => $a->id ], $base ) ); ?>"><strong><?php echo esc_html( $a->organisation_name ); ?></strong></a>
                <br><span class="grants-portal__muted"><?php echo esc_html( $a->public_reference . ( $a->organisation_town ? ' · ' . $a->organisation_town : '' ) ); ?></span></td>
            <td class="num"><?php echo esc_html( $a->requested_pence === null ? '—' : Money::format_gbp( $a->requested_pence ) ); ?></td>
            <td><?php echo esc_html( $r['decision'] ?: ApplicationStatus::label( $a->status ) ); ?></td>
            <td>
                <?php if ( $conflicted ) : ?><span class="grants-portal__tag grants-portal__tag--conflict"><?php esc_html_e( 'Conflict', 'rotary-grants' ); ?></span>
                <?php elseif ( $r['declaration'] === ConflictService::UNDECLARED ) : ?><span class="grants-portal__tag grants-portal__tag--todo"><?php esc_html_e( 'Declare', 'rotary-grants' ); ?></span>
                <?php elseif ( $r['my_review'] ) : ?><span class="grants-portal__tag grants-portal__tag--done"><?php esc_html_e( 'Reviewed', 'rotary-grants' ); ?></span>
                <?php elseif ( ApplicationStatus::is_open( $a->status ) ) : ?><span class="grants-portal__tag grants-portal__tag--todo"><?php esc_html_e( 'To review', 'rotary-grants' ); ?></span>
                <?php else : ?>—<?php endif; ?>
            </td>
            <td class="num"><?php echo esc_html( (string) $r['reviews'] ); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
