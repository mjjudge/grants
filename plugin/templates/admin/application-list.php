<?php
/**
 * Admin template — application list (read-only).
 *
 * Available variables:
 *   $applications object[]  Rows from ApplicationService::list() (id, public_reference, status,
 *                           organisation_name, organisation_town, requested_pence, source,
 *                           submitted_at, round_label, fund_name).
 *   $rounds       object[]  All rounds, for the filter.
 *   $round_id     int       Selected round filter (0 = all).
 *   $status       string    Selected status filter ('' = all).
 *   $base_url     string    List URL without filters.
 *   $add_url      string    Staff "Add application" URL, or '' if not permitted.
 *   $organisation_id int    Organisation filter (0 = all).
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;
?>
<div class="wrap grants-admin">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Applications', 'rotary-grants' ); ?></h1>
    <?php if ( $add_url !== '' ) : ?>
        <a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Enter a paper/email application', 'rotary-grants' ); ?></a>
    <?php endif; ?>
    <hr class="wp-header-end">

    <form method="get" class="grants-filters">
        <input type="hidden" name="page" value="grants-applications">
        <?php if ( $organisation_id ) : ?><input type="hidden" name="organisation_id" value="<?php echo esc_attr( (string) $organisation_id ); ?>"><?php endif; ?>
        <label for="grants-filter-round" class="screen-reader-text"><?php esc_html_e( 'Funding round', 'rotary-grants' ); ?></label>
        <select id="grants-filter-round" name="round_id">
            <option value="0"><?php esc_html_e( 'All rounds', 'rotary-grants' ); ?></option>
            <?php foreach ( $rounds as $r ) : ?>
                <option value="<?php echo esc_attr( (string) $r->id ); ?>" <?php selected( $round_id, $r->id ); ?>><?php echo esc_html( $r->fund_name . ' — ' . $r->label ); ?></option>
            <?php endforeach; ?>
        </select>
        <label for="grants-filter-status" class="screen-reader-text"><?php esc_html_e( 'Status', 'rotary-grants' ); ?></label>
        <select id="grants-filter-status" name="status">
            <option value=""><?php esc_html_e( 'All statuses', 'rotary-grants' ); ?></option>
            <?php foreach ( ApplicationStatus::all() as $s ) : ?>
                <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( ApplicationStatus::label( $s ) ); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button"><?php esc_html_e( 'Filter', 'rotary-grants' ); ?></button>
    </form>

    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e( 'Reference', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Organisation', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Town', 'rotary-grants' ); ?></th>
                <th scope="col" class="num"><?php esc_html_e( 'Requested', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Round', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Status', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Submitted', 'rotary-grants' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! $applications ) : ?>
                <tr><td colspan="7"><?php esc_html_e( 'No applications match.', 'rotary-grants' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $applications as $a ) : ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $a->id ], $base_url ) ); ?>"><strong><?php echo esc_html( $a->public_reference ); ?></strong></a>
                        <?php if ( $a->source !== ApplicationService::SOURCE_PUBLIC ) : ?><br><span class="grants-badge"><?php echo esc_html( ApplicationService::source_label( $a->source ) ); ?></span><?php endif; ?>
                        <?php if ( $a->is_late ) : ?> <span class="grants-badge grants-badge--late"><?php esc_html_e( 'Late', 'rotary-grants' ); ?></span><?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $a->organisation_name ); ?><?php if ( ! $a->organisation_id ) : ?> <span class="grants-badge"><?php esc_html_e( 'not linked', 'rotary-grants' ); ?></span><?php endif; ?></td>
                    <td><?php echo esc_html( $a->organisation_town ); ?></td>
                    <td class="num"><?php echo esc_html( $a->requested_pence === null ? '—' : Money::format_gbp( $a->requested_pence ) ); ?></td>
                    <td><?php echo esc_html( trim( $a->fund_name . ' — ' . $a->round_label, ' —' ) ); ?></td>
                    <td><?php echo esc_html( ApplicationStatus::label( $a->status ) ); ?></td>
                    <td><?php echo esc_html( SiteTime::display( $a->submitted_at ) ?: '—' ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
