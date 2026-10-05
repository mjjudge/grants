<?php
/**
 * Admin template — one organisation.
 *
 * Available variables:
 *   $organisation object               Organisation row.
 *   $merged_into  object|null          Organisation this one was merged into.
 *   $contacts     object[]             Contacts, current first.
 *   $preferences  array<int, array{current: object|null, history: object[]}>  Per contact id.
 *   $applications object[]             Linked applications (ApplicationService::list rows).
 *   $award_info   array<int, array{award: object, paid: int}>  Application id => its award and net paid.
 *   $amendments   object[]             Corrections to this organisation (->changes decoded).
 *   $merge_q      string               Merge search text.
 *   $merge_hits   object[]             Organisations matching the merge search.
 *   $can_manage   bool                 User has grants_manage_organisations.
 *   $values       array<string,string> Re-displayed values after a failed save.
 *   $errors       array<string,string> Error code => message.
 *   $notice       string               Notice key from the last action.
 *   $nonce_action string               Nonce action for every form here.
 *   $nonce_field  string               Nonce field name.
 *   $list_url     string               Organisation list URL.
 *   $view_url     string               This page's URL.
 *   $apps_url     string               Applications screen URL.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Services\PreferenceService;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

$active  = $organisation->status === 'active';
$hidden  = static function ( array $fields ) use ( $organisation, $nonce_action, $nonce_field ): void {
    printf( '<input type="hidden" name="organisation_id" value="%s">', esc_attr( (string) $organisation->id ) );
    foreach ( $fields as $k => $val ) {
        printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( (string) $val ) );
    }
    wp_nonce_field( $nonce_action, $nonce_field );
};
$notices = [
    'created'        => __( 'Organisation added.', 'rotary-grants' ),
    'saved'          => __( 'Corrections saved.', 'rotary-grants' ),
    'merged'         => __( 'Organisations merged. This is the organisation that was kept.', 'rotary-grants' ),
    'contact_saved'  => __( 'Contact saved.', 'rotary-grants' ),
    'contact_ended'  => __( 'Contact marked as no longer acting for the organisation.', 'rotary-grants' ),
    'pref_recorded'  => __( 'Permission recorded.', 'rotary-grants' ),
    'pref_withdrawn' => __( 'Permission withdrawn.', 'rotary-grants' ),
];
?>
<div class="wrap grants-admin">
    <h1><?php echo esc_html( $organisation->name ); ?></h1>
    <p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'All organisations', 'rotary-grants' ); ?></a></p>

    <?php if ( isset( $notices[ $notice ] ) && ! $errors ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
    <?php endif; ?>

    <?php if ( ! $active ) : ?>
        <div class="notice notice-warning inline"><p>
            <?php esc_html_e( 'This organisation was merged into', 'rotary-grants' ); ?>
            <?php if ( $merged_into ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $merged_into->id ], $list_url ) ); ?>"><?php echo esc_html( $merged_into->name ); ?></a>
            <?php endif; ?>
            <?php echo esc_html( sprintf( /* translators: 1: date, 2: reason */ __( 'on %1$s. Reason: %2$s', 'rotary-grants' ), SiteTime::display( $organisation->merged_at ), (string) $organisation->merge_reason ) ); ?>
        </p></div>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Details', 'rotary-grants' ); ?></h2>
    <?php if ( $can_manage && $active ) : ?>
        <?php include __DIR__ . '/partials/organisation-fields.php'; ?>
    <?php else : ?>
        <table class="widefat striped grants-kv"><tbody>
            <tr><th scope="row"><?php esc_html_e( 'Charity number', 'rotary-grants' ); ?></th><td><?php echo esc_html( $organisation->charity_number ?: '—' ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Town / postcode', 'rotary-grants' ); ?></th><td><?php echo esc_html( trim( $organisation->town . ' ' . $organisation->postcode ) ?: '—' ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Website', 'rotary-grants' ); ?></th><td><?php echo esc_html( $organisation->website_url ?: '—' ); ?></td></tr>
        </tbody></table>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Contacts', 'rotary-grants' ); ?></h2>
    <p class="description"><?php esc_html_e( 'If a different person now acts for the organisation, add them as a new contact and mark the previous one as no longer acting — don\'t overwrite them. "Correct" is for fixing the same person\'s details.', 'rotary-grants' ); ?></p>
    <table class="widefat striped">
        <thead><tr>
            <th scope="col"><?php esc_html_e( 'Name / role', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Email / phone', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Period', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Future-round emails', 'rotary-grants' ); ?></th>
            <?php if ( $can_manage ) : ?><th scope="col"><?php esc_html_e( 'Actions', 'rotary-grants' ); ?></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if ( ! $contacts ) : ?>
            <tr><td colspan="5"><?php esc_html_e( 'No contacts yet.', 'rotary-grants' ); ?></td></tr>
        <?php endif; ?>
        <?php foreach ( $contacts as $c ) :
            $cid     = (int) $c->id;
            $current = $c->active_until === null;
            $pref    = $preferences[ $cid ]['current'] ?? null;
            $opted   = $pref && $pref->status === PreferenceService::OPTED_IN;
            ?>
            <tr class="<?php echo $current ? '' : 'grants-row-former'; ?>">
                <td><strong><?php echo esc_html( $c->name ); ?></strong><br><?php echo esc_html( $c->role ); ?></td>
                <td><?php echo esc_html( $c->email ?: '—' ); ?><br><?php echo esc_html( $c->phone ); ?></td>
                <td>
                    <?php echo esc_html( sprintf( /* translators: %s: date */ __( 'from %s', 'rotary-grants' ), SiteTime::display( $c->active_from ) ) ); ?>
                    <?php if ( ! $current ) : ?><br><strong><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'until %s (former contact)', 'rotary-grants' ), SiteTime::display( $c->active_until ) ) ); ?></strong><?php endif; ?>
                    <br><span class="description"><?php echo esc_html( $c->source === 'application' ? __( 'from an application', 'rotary-grants' ) : __( 'added by staff', 'rotary-grants' ) ); ?></span>
                </td>
                <td>
                    <?php if ( $opted ) : ?>
                        <span class="grants-status--ok"><?php esc_html_e( 'Yes', 'rotary-grants' ); ?></span>
                        <br><span class="description"><?php echo esc_html( $pref->evidence_source . ' · ' . SiteTime::display( $pref->recorded_at ) ); ?></span>
                    <?php elseif ( $pref ) : ?>
                        <?php esc_html_e( 'Withdrawn', 'rotary-grants' ); ?>
                        <br><span class="description"><?php echo esc_html( $pref->withdrawal_source . ' · ' . SiteTime::display( $pref->withdrawn_at ) ); ?></span>
                    <?php else : ?>
                        <?php esc_html_e( 'No', 'rotary-grants' ); ?>
                    <?php endif; ?>
                    <?php if ( count( $preferences[ $cid ]['history'] ?? [] ) > 1 ) : ?>
                        <details><summary><?php esc_html_e( 'History', 'rotary-grants' ); ?></summary><ul>
                            <?php foreach ( $preferences[ $cid ]['history'] as $h ) : ?>
                                <li><?php echo esc_html( SiteTime::display( $h->recorded_at ) . ': ' . $h->evidence_source . ( $h->withdrawn_at ? ' — ' . __( 'withdrawn', 'rotary-grants' ) . ' ' . SiteTime::display( $h->withdrawn_at ) . ' (' . $h->withdrawal_source . ')' : '' ) ); ?></li>
                            <?php endforeach; ?>
                        </ul></details>
                    <?php endif; ?>
                </td>
                <?php if ( $can_manage ) : ?>
                    <td>
                        <?php if ( $current && $active ) : ?>
                            <details><summary><?php esc_html_e( 'Correct', 'rotary-grants' ); ?></summary>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="grants_contact_save">
                                    <?php $hidden( [ 'contact_id' => $cid, 'row_version' => $c->row_version ] ); ?>
                                    <?php foreach ( [ 'name' => __( 'Name', 'rotary-grants' ), 'role' => __( 'Role', 'rotary-grants' ), 'email' => __( 'Email', 'rotary-grants' ), 'phone' => __( 'Phone', 'rotary-grants' ) ] as $f => $lab ) : ?>
                                        <p><label><?php echo esc_html( $lab ); ?><br><input type="text" name="contact_<?php echo esc_attr( $f ); ?>" value="<?php echo esc_attr( (string) $c->$f ); ?>"></label></p>
                                    <?php endforeach; ?>
                                    <p><label><?php esc_html_e( 'Reason', 'rotary-grants' ); ?><br><input type="text" name="reason"></label></p>
                                    <button type="submit" class="button button-small"><?php esc_html_e( 'Save correction', 'rotary-grants' ); ?></button>
                                </form>
                            </details>
                            <details><summary><?php esc_html_e( 'No longer the contact', 'rotary-grants' ); ?></summary>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="grants_contact_end">
                                    <?php $hidden( [ 'contact_id' => $cid ] ); ?>
                                    <p><label><?php esc_html_e( 'Reason', 'rotary-grants' ); ?><br><input type="text" name="reason" placeholder="<?php esc_attr_e( 'e.g. stepped down as secretary', 'rotary-grants' ); ?>"></label></p>
                                    <button type="submit" class="button button-small"><?php esc_html_e( 'Mark as former contact', 'rotary-grants' ); ?></button>
                                </form>
                            </details>
                        <?php endif; ?>
                        <?php if ( $opted ) : ?>
                            <details><summary><?php esc_html_e( 'Withdraw permission', 'rotary-grants' ); ?></summary>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="grants_pref_withdraw">
                                    <?php $hidden( [ 'contact_id' => $cid ] ); ?>
                                    <p><label><?php esc_html_e( 'How was it withdrawn?', 'rotary-grants' ); ?><br><input type="text" name="source" required placeholder="<?php esc_attr_e( 'e.g. email of 4 March 2026', 'rotary-grants' ); ?>"></label></p>
                                    <button type="submit" class="button button-small"><?php esc_html_e( 'Withdraw', 'rotary-grants' ); ?></button>
                                </form>
                            </details>
                        <?php elseif ( $current ) : ?>
                            <details><summary><?php esc_html_e( 'Record permission', 'rotary-grants' ); ?></summary>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="grants_pref_record">
                                    <?php $hidden( [ 'contact_id' => $cid ] ); ?>
                                    <p><label><?php esc_html_e( 'Evidence (how and when they asked)', 'rotary-grants' ); ?><br><input type="text" name="evidence" required></label></p>
                                    <button type="submit" class="button button-small"><?php esc_html_e( 'Record', 'rotary-grants' ); ?></button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $can_manage && $active ) : ?>
        <details class="grants-add-contact"<?php echo isset( $values['contact_name'] ) && empty( $values['contact_id'] ) ? ' open' : ''; ?>>
            <summary><?php esc_html_e( 'Add a contact', 'rotary-grants' ); ?></summary>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="grants_contact_save">
                <?php $hidden( [ 'contact_id' => 0 ] ); ?>
                <?php foreach ( [ 'name' => __( 'Name', 'rotary-grants' ), 'role' => __( 'Role', 'rotary-grants' ), 'email' => __( 'Email', 'rotary-grants' ), 'phone' => __( 'Phone', 'rotary-grants' ) ] as $f => $lab ) : ?>
                    <p><label><?php echo esc_html( $lab ); ?><br><input type="text" name="contact_<?php echo esc_attr( $f ); ?>" value="<?php echo esc_attr( empty( $values['contact_id'] ) ? (string) ( $values[ 'contact_' . $f ] ?? '' ) : '' ); ?>"></label></p>
                <?php endforeach; ?>
                <button type="submit" class="button"><?php esc_html_e( 'Add contact', 'rotary-grants' ); ?></button>
            </form>
        </details>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Applications', 'rotary-grants' ); ?></h2>
    <table class="widefat striped">
        <thead><tr>
            <th scope="col"><?php esc_html_e( 'Reference', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Round', 'rotary-grants' ); ?></th>
            <th scope="col" class="num"><?php esc_html_e( 'Requested', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Status', 'rotary-grants' ); ?></th>
            <th scope="col" class="num"><?php esc_html_e( 'Awarded', 'rotary-grants' ); ?></th>
            <th scope="col" class="num"><?php esc_html_e( 'Paid', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Received', 'rotary-grants' ); ?></th>
        </tr></thead>
        <tbody>
            <?php if ( ! $applications ) : ?>
                <tr><td colspan="7"><?php esc_html_e( 'No applications linked.', 'rotary-grants' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $applications as $a ) : ?>
                <tr>
                    <td><a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $a->id ], $apps_url ) ); ?>"><?php echo esc_html( $a->public_reference ); ?></a>
                        <?php if ( $a->source !== ApplicationService::SOURCE_PUBLIC ) : ?><span class="grants-badge"><?php echo esc_html( ApplicationService::source_label( $a->source ) ); ?></span><?php endif; ?></td>
                    <td><?php echo esc_html( $a->fund_name . ' — ' . $a->round_label ); ?></td>
                    <td class="num"><?php echo esc_html( $a->requested_pence === null ? '—' : Money::format_gbp( $a->requested_pence ) ); ?></td>
                    <td><?php echo esc_html( ApplicationStatus::label( $a->status ) ); ?></td>
                    <?php $ai = $award_info[ $a->id ] ?? null; ?>
                    <td class="num"><?php echo esc_html( $ai ? ( $ai['award']->status === 'approved' ? ( $ai['award']->approved_pence === null ? __( 'unknown', 'rotary-grants' ) : Money::format_gbp( $ai['award']->approved_pence ) ) : __( 'cancelled', 'rotary-grants' ) ) : '—' ); ?></td>
                    <td class="num"><?php echo esc_html( $ai && $ai['paid'] ? Money::format_gbp( $ai['paid'] ) : '—' ); ?></td>
                    <td><?php echo esc_html( SiteTime::display( $a->submitted_at ) ?: '—' ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $can_manage && $active ) : ?>
        <h2><?php esc_html_e( 'Merge a duplicate', 'rotary-grants' ); ?></h2>
        <p class="description"><?php esc_html_e( 'If this organisation is the same body as another record, merge this one into the other. Its applications and contacts move across, this record is kept as "merged" with your reason, and submitted applications are not changed.', 'rotary-grants' ); ?></p>
        <form method="get" class="grants-filters">
            <input type="hidden" name="page" value="grants-organisations">
            <input type="hidden" name="action" value="view">
            <input type="hidden" name="id" value="<?php echo esc_attr( (string) $organisation->id ); ?>">
            <label for="grants-merge-q" class="screen-reader-text"><?php esc_html_e( 'Find the organisation to keep', 'rotary-grants' ); ?></label>
            <input type="search" id="grants-merge-q" name="merge_q" value="<?php echo esc_attr( $merge_q ); ?>" placeholder="<?php esc_attr_e( 'Find the organisation to keep', 'rotary-grants' ); ?>">
            <button type="submit" class="button"><?php esc_html_e( 'Search', 'rotary-grants' ); ?></button>
        </form>
        <?php foreach ( $merge_hits as $hit ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="grants-merge-row"
                  onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( sprintf( /* translators: 1: this org, 2: kept org */ __( 'Merge "%1$s" into "%2$s"? This cannot be undone from this screen.', 'rotary-grants' ), $organisation->name, $hit->name ) ) ); ?>);">
                <input type="hidden" name="action" value="grants_org_merge">
                <?php $hidden( [ 'into_id' => $hit->id, 'row_version' => $organisation->row_version ] ); ?>
                <strong><?php echo esc_html( $hit->name ); ?></strong> <?php echo esc_html( trim( $hit->town . ' ' . $hit->charity_number ) ); ?>
                <label class="screen-reader-text" for="grants-merge-reason-<?php echo esc_attr( (string) $hit->id ); ?>"><?php esc_html_e( 'Reason', 'rotary-grants' ); ?></label>
                <input type="text" id="grants-merge-reason-<?php echo esc_attr( (string) $hit->id ); ?>" name="reason" required placeholder="<?php esc_attr_e( 'Reason for the merge', 'rotary-grants' ); ?>">
                <button type="submit" class="button"><?php esc_html_e( 'Merge into this one', 'rotary-grants' ); ?></button>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ( $amendments ) : ?>
        <h2><?php esc_html_e( 'Correction history', 'rotary-grants' ); ?></h2>
        <table class="widefat striped">
            <thead><tr>
                <th scope="col"><?php esc_html_e( 'When', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Changes', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Reason', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'By', 'rotary-grants' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $amendments as $am ) : $who = get_userdata( (int) $am->actor_user_id ); ?>
                <tr>
                    <td><?php echo esc_html( SiteTime::display( $am->created_at ) ); ?></td>
                    <td><?php foreach ( $am->changes as $field => $pair ) : ?><div><code><?php echo esc_html( $field ); ?></code>: <?php echo esc_html( (string) ( $pair[0] ?? '' ) ); ?> → <?php echo esc_html( (string) ( $pair[1] ?? '' ) ); ?></div><?php endforeach; ?></td>
                    <td><?php echo esc_html( (string) $am->reason ?: '—' ); ?></td>
                    <td><?php echo esc_html( $who ? $who->display_name : '—' ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
