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
 *   $min_raw, $max_raw string  Amount filter inputs as typed.
 *   $town         string    Town filter.
 *   $show_duplicates bool   Include applications marked as duplicates.
 *   $review_counts array<int,int>  Application id => current review count.
 *   $is_committee bool      User is a committee member.
 *   $my_declarations array<int,string>  Application id => user's declaration status.
 *   $declarations_url string  Conflicts-of-interest screen for the selected round.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Services\ConflictService;
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
        <label><?php esc_html_e( '£ from', 'rotary-grants' ); ?> <input type="text" name="min" class="small-text" value="<?php echo esc_attr( $min_raw ); ?>"></label>
        <label><?php esc_html_e( 'to', 'rotary-grants' ); ?> <input type="text" name="max" class="small-text" value="<?php echo esc_attr( $max_raw ); ?>"></label>
        <label><?php esc_html_e( 'Town', 'rotary-grants' ); ?> <input type="text" name="town" class="regular-text" style="width:10em" value="<?php echo esc_attr( $town ); ?>"></label>
        <label><input type="checkbox" name="duplicates" value="1" <?php checked( $show_duplicates ); ?>> <?php esc_html_e( 'Show duplicates', 'rotary-grants' ); ?></label>
        <button type="submit" class="button"><?php esc_html_e( 'Filter', 'rotary-grants' ); ?></button>
    </form>
    <?php if ( $is_committee && $round_id ) : ?>
        <p><a href="<?php echo esc_url( $declarations_url ); ?>"><?php esc_html_e( 'Declare conflicts of interest for this round', 'rotary-grants' ); ?></a></p>
    <?php endif; ?>

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
                <th scope="col" class="num"><?php esc_html_e( 'Reviews', 'rotary-grants' ); ?></th>
                <?php if ( $is_committee ) : ?><th scope="col"><?php esc_html_e( 'Your declaration', 'rotary-grants' ); ?></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! $applications ) : ?>
                <tr><td colspan="9"><?php esc_html_e( 'No applications match.', 'rotary-grants' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $applications as $a ) : ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $a->id ], $base_url ) ); ?>"><strong><?php echo esc_html( $a->public_reference ); ?></strong></a>
                        <?php if ( $a->source !== ApplicationService::SOURCE_PUBLIC ) : ?><br><span class="grants-badge"><?php echo esc_html( ApplicationService::source_label( $a->source ) ); ?></span><?php endif; ?>
                        <?php if ( $a->is_late ) : ?> <span class="grants-badge grants-badge--late"><?php esc_html_e( 'Late', 'rotary-grants' ); ?></span><?php endif; ?>
                        <?php if ( $a->duplicate_of_id ) : ?> <span class="grants-badge"><?php esc_html_e( 'Duplicate', 'rotary-grants' ); ?></span><?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $a->organisation_name ); ?><?php if ( ! $a->organisation_id ) : ?> <span class="grants-badge"><?php esc_html_e( 'not linked', 'rotary-grants' ); ?></span><?php endif; ?></td>
                    <td><?php echo esc_html( $a->organisation_town ); ?></td>
                    <td class="num"><?php echo esc_html( $a->requested_pence === null ? '—' : Money::format_gbp( $a->requested_pence ) ); ?></td>
                    <td><?php echo esc_html( trim( $a->fund_name . ' — ' . $a->round_label, ' —' ) ); ?></td>
                    <td><?php echo esc_html( ApplicationStatus::label( $a->status ) ); ?></td>
                    <td><?php echo esc_html( SiteTime::display( $a->submitted_at ) ?: '—' ); ?></td>
                    <td class="num"><?php echo esc_html( (string) ( $review_counts[ $a->id ] ?? 0 ) ); ?></td>
                    <?php if ( $is_committee ) : $d = $my_declarations[ $a->id ] ?? 'undeclared'; ?>
                        <td><span class="grants-decl grants-decl--<?php echo esc_attr( $d ); ?>"><?php echo esc_html( ConflictService::label( $d ) ); ?></span></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
