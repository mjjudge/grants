<?php
/**
 * Admin template — one application, as submitted (read-only snapshot).
 *
 * Available variables:
 *   $application object               Hydrated grants_applications row.
 *   $snapshot    array<string,mixed>  Decoded answer_snapshot_json (form_version, policy_version,
 *                                     submitted_at, round{}, answers{}, wording{}, privacy_notice{}).
 *   $labels      array<string,string> Field name => label.
 *   $list_url    string               Application list URL.
 *   $org_url     string               Organisations screen URL.
 *   $can_link    bool                 User may link/re-link (grants_manage_organisations).
 *   $organisation object|null         Linked organisation row, if linked.
 *   $contact     object|null          Linked contact row, if linked.
 *   $siblings    object[]             Other applications in the same round that look like the same organisation.
 *   $candidates  list<array{organisation:object, score:int, reasons:string[]}>  Suggested organisations (unlinked only).
 *   $org_query   string               Organisation search text ('' if none).
 *   $search      object[]             Organisation search results.
 *   $entered_by  WP_User|null|false   Staff member who entered it (staff entries).
 *   $notice      string               'linked', 'linked_new', 'entered', 'already_entered', 'error' or ''.
 *   $error       string               Error message from the last action, or ''.
 *   $nonce_action string              Nonce action for link forms.
 *   $nonce_field  string              Nonce field name.
 *   $history      object[]            The linked organisation's other applications (any round).
 *   $duplicate_of object|null         Application this one is marked a duplicate of.
 *   …plus the committee-panel variables documented in partials/committee-panel.php.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

$answers = (array) ( $snapshot['answers'] ?? [] );
$show    = static function ( string $field ) use ( $answers ): string {
    $value = $answers[ $field ] ?? null;
    return match ( true ) {
        $value === true                => __( 'Yes (ticked)', 'rotary-grants' ),
        $value === false               => __( 'No (not ticked)', 'rotary-grants' ),
        $value === 'yes'               => __( 'Yes', 'rotary-grants' ),
        $value === 'no'                => __( 'No', 'rotary-grants' ),
        $value === 'not_sure'          => __( 'Not sure', 'rotary-grants' ),
        $value === null || $value === '' => '—',
        default                        => (string) $value,
    };
};
$groups = [
    __( 'Organisation and contact', 'rotary-grants' ) => [ 'organisation_name', 'charity_number', 'organisation_town', 'organisation_postcode', 'organisation_overview', 'website_url', 'facebook_url', 'other_social_label_1', 'other_social_url_1', 'other_social_label_2', 'other_social_url_2', 'other_social_label_3', 'other_social_url_3', 'contact_name', 'contact_role', 'contact_phone', 'contact_email' ],
    __( 'Initiative', 'rotary-grants' )               => [ 'proposed_use', 'expected_difference', 'local_benefit', 'one_off_initiative', 'one_off_explanation' ],
    __( 'About the organisation', 'rotary-grants' )   => [ 'has_organisation_bank_account', 'has_governing_document', 'locally_led_and_run', 'locally_led_explanation', 'has_committee_or_support_group', 'is_local_branch', 'branch_explanation' ],
    __( 'Acknowledgements', 'rotary-grants' )         => [ 'exclusions_acknowledged', 'publicity_acknowledged', 'presentation_acknowledged', 'privacy_notice_acknowledged', 'future_round_email_opt_in', 'declaration_name', 'declaration_confirmed' ],
];
?>
<div class="wrap grants-admin">
    <h1><?php echo esc_html( sprintf( /* translators: %s: reference */ __( 'Application %s', 'rotary-grants' ), $application->public_reference ) ); ?></h1>
    <p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'All applications', 'rotary-grants' ); ?></a></p>

    <?php
    $notices = [
        'linked'          => __( 'Application linked to the organisation.', 'rotary-grants' ),
        'linked_new'      => __( 'New organisation created from this application and linked.', 'rotary-grants' ),
        'entered'         => __( 'Application entered.', 'rotary-grants' ),
        'declared'        => __( 'Declaration recorded.', 'rotary-grants' ),
        'review_saved'    => __( 'Review saved.', 'rotary-grants' ),
        'note_added'      => __( 'Note added.', 'rotary-grants' ),
        'info_drafted'    => __( 'Draft request saved — it has not been sent.', 'rotary-grants' ),
        'info_sent'       => __( 'Request emailed to the applicant.', 'rotary-grants' ),
        'info_discarded'  => __( 'Draft discarded.', 'rotary-grants' ),
        'addendum_added'  => __( 'Information recorded.', 'rotary-grants' ),
        'status_changed'  => __( 'Status changed.', 'rotary-grants' ),
        'duplicate_marked'   => __( 'Marked as a duplicate.', 'rotary-grants' ),
        'duplicate_unmarked' => __( 'Duplicate mark removed.', 'rotary-grants' ),
        'already_entered' => __( 'This form had already been saved — showing the application it created.', 'rotary-grants' ),
    ];
    ?>
    <?php if ( isset( $notices[ $notice ] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
    <?php endif; ?>
    <?php if ( $error !== '' ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
    <?php endif; ?>

    <?php if ( $application->source !== ApplicationService::SOURCE_PUBLIC ) : ?>
        <div class="notice notice-info inline grants-source-note"><p>
            <strong><?php echo esc_html( ApplicationService::source_label( $application->source ) ); ?></strong>
            <?php if ( $entered_by ) : ?>
                — <?php echo esc_html( sprintf( /* translators: 1: staff name, 2: date */ __( 'keyed in by %1$s on %2$s', 'rotary-grants' ), $entered_by->display_name, SiteTime::display( $application->created_at ) ) ); ?>
            <?php endif; ?>
            <?php if ( $application->is_late ) : ?>
                <br><span class="grants-badge grants-badge--late"><?php esc_html_e( 'Late', 'rotary-grants' ); ?></span> <?php esc_html_e( 'Received outside the round\'s opening and closing dates.', 'rotary-grants' ); ?>
            <?php endif; ?>
            <?php if ( (string) $application->entry_reason !== '' ) : ?>
                <br><?php esc_html_e( 'Note:', 'rotary-grants' ); ?> <?php echo esc_html( $application->entry_reason ); ?>
            <?php endif; ?>
        </p></div>
    <?php endif; ?>

    <?php if ( $duplicate_of ) : ?>
        <div class="notice notice-warning inline"><p>
            <?php esc_html_e( 'Marked as a duplicate of', 'rotary-grants' ); ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $duplicate_of->id ], $list_url ) ); ?>"><?php echo esc_html( $duplicate_of->public_reference ); ?></a>.
            <?php esc_html_e( 'See the committee record below for the reason.', 'rotary-grants' ); ?>
        </p></div>
    <?php endif; ?>

    <?php if ( $siblings ) : ?>
        <div class="notice notice-warning inline"><p>
            <?php esc_html_e( 'Possible repeat application — this round also has:', 'rotary-grants' ); ?>
            <?php foreach ( $siblings as $i => $sib ) : ?>
                <?php echo $i ? ', ' : ' '; ?><a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $sib->id ], $list_url ) ); ?>"><?php echo esc_html( $sib->public_reference ); ?></a>
            <?php endforeach; ?>
            <?php esc_html_e( '(repeat applications are allowed; this is for the committee to note).', 'rotary-grants' ); ?>
        </p></div>
    <?php endif; ?>

    <table class="widefat striped grants-kv">
        <tbody>
            <tr><th scope="row"><?php esc_html_e( 'Status', 'rotary-grants' ); ?></th><td><?php echo esc_html( ApplicationStatus::label( $application->status ) ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Round', 'rotary-grants' ); ?></th><td><?php echo esc_html( ( $snapshot['round']['fund_name'] ?? '' ) . ' — ' . ( $snapshot['round']['label'] ?? '' ) ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Amount requested', 'rotary-grants' ); ?></th><td><?php echo esc_html( $application->requested_pence === null ? '—' : Money::format_gbp( $application->requested_pence ) ); ?></td></tr>
            <tr><th scope="row"><?php echo $application->source === ApplicationService::SOURCE_PUBLIC ? esc_html__( 'Submitted', 'rotary-grants' ) : esc_html__( 'Received', 'rotary-grants' ); ?></th><td><?php echo esc_html( SiteTime::display( $application->submitted_at ) ?: '—' ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Source', 'rotary-grants' ); ?></th><td><?php echo esc_html( ApplicationService::source_label( $application->source ) ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Versions', 'rotary-grants' ); ?></th><td><?php echo esc_html( sprintf( /* translators: 1: form version, 2: wording version, 3: privacy notice version */ __( 'Form %1$s · round wording %2$s · privacy notice %3$s', 'rotary-grants' ), $snapshot['form_version'] ?? '?', $snapshot['policy_version'] ?? '?', ( $snapshot['privacy_notice']['version'] ?? '' ) ?: '—' ) ); ?></td></tr>
        </tbody>
    </table>

    <h2><?php esc_html_e( 'Organisation', 'rotary-grants' ); ?></h2>
    <?php if ( $organisation ) : ?>
        <p>
            <?php esc_html_e( 'Linked to', 'rotary-grants' ); ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $organisation->id ], $org_url ) ); ?>"><strong><?php echo esc_html( $organisation->name ); ?></strong></a>
            <?php if ( $contact ) : ?>
                · <?php esc_html_e( 'contact:', 'rotary-grants' ); ?> <?php echo esc_html( $contact->name ); ?>
            <?php endif; ?>
        </p>
        <p class="description"><?php esc_html_e( 'The answers below are exactly as submitted. Later changes to the organisation or contact record do not alter them.', 'rotary-grants' ); ?></p>
    <?php else : ?>
        <p><?php esc_html_e( 'Not linked to an organisation record yet. Nothing is linked automatically — choose a match below or create a new record.', 'rotary-grants' ); ?></p>
    <?php endif; ?>

    <?php if ( $can_link ) : ?>
        <?php
        $link_button = static function ( int $org_id, string $label, bool $needs_reason ) use ( $application, $nonce_action, $nonce_field ): void {
            ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="grants-inline-form">
                <input type="hidden" name="action" value="grants_application_link">
                <input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $application->id ); ?>">
                <input type="hidden" name="organisation_id" value="<?php echo esc_attr( (string) $org_id ); ?>">
                <input type="hidden" name="row_version" value="<?php echo esc_attr( (string) $application->row_version ); ?>">
                <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
                <?php if ( $needs_reason ) : ?>
                    <label class="screen-reader-text" for="grants-relink-reason-<?php echo esc_attr( (string) $org_id ); ?>"><?php esc_html_e( 'Reason for changing the link', 'rotary-grants' ); ?></label>
                    <input type="text" id="grants-relink-reason-<?php echo esc_attr( (string) $org_id ); ?>" name="reason" required placeholder="<?php esc_attr_e( 'Reason for changing the link', 'rotary-grants' ); ?>">
                <?php endif; ?>
                <button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
            </form>
            <?php
        };
        $relink = (bool) $organisation;
        ?>
        <?php if ( $candidates ) : ?>
            <h3><?php esc_html_e( 'Possible matches', 'rotary-grants' ); ?></h3>
            <table class="widefat striped">
                <thead><tr>
                    <th scope="col"><?php esc_html_e( 'Organisation', 'rotary-grants' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Charity no.', 'rotary-grants' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Town / postcode', 'rotary-grants' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Why suggested', 'rotary-grants' ); ?></th>
                    <th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Action', 'rotary-grants' ); ?></span></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $candidates as $c ) : $o = $c['organisation']; ?>
                    <tr>
                        <td><a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $o->id ], $org_url ) ); ?>"><?php echo esc_html( $o->name ); ?></a> <span class="description">(<?php echo esc_html( (string) $o->application_count ); ?> <?php esc_html_e( 'applications', 'rotary-grants' ); ?>)</span></td>
                        <td><?php echo esc_html( $o->charity_number ?: '—' ); ?></td>
                        <td><?php echo esc_html( trim( $o->town . ' ' . $o->postcode ) ?: '—' ); ?></td>
                        <td><?php echo esc_html( implode( ', ', $c['reasons'] ) ); ?></td>
                        <td><?php $link_button( $o->id, __( 'Link to this organisation', 'rotary-grants' ), false ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ( ! $organisation ) : ?>
            <p class="description"><?php esc_html_e( 'No likely matches found among existing organisations.', 'rotary-grants' ); ?></p>
        <?php endif; ?>

        <h3><?php echo $relink ? esc_html__( 'Link to a different organisation', 'rotary-grants' ) : esc_html__( 'Find another organisation', 'rotary-grants' ); ?></h3>
        <form method="get" class="grants-filters">
            <input type="hidden" name="page" value="grants-applications">
            <input type="hidden" name="action" value="view">
            <input type="hidden" name="id" value="<?php echo esc_attr( (string) $application->id ); ?>">
            <label for="grants-org-q" class="screen-reader-text"><?php esc_html_e( 'Search organisations', 'rotary-grants' ); ?></label>
            <input type="search" id="grants-org-q" name="org_q" value="<?php echo esc_attr( $org_query ); ?>" placeholder="<?php esc_attr_e( 'Name, charity number or town', 'rotary-grants' ); ?>">
            <button type="submit" class="button"><?php esc_html_e( 'Search', 'rotary-grants' ); ?></button>
        </form>
        <?php if ( $org_query !== '' ) : ?>
            <?php if ( ! $search ) : ?>
                <p><?php esc_html_e( 'No organisations found.', 'rotary-grants' ); ?></p>
            <?php else : ?>
                <ul class="grants-search-results">
                    <?php foreach ( $search as $o ) : if ( $organisation && $o->id === $organisation->id ) { continue; } ?>
                        <li><?php echo esc_html( $o->name . ( $o->town ? ' — ' . $o->town : '' ) ); ?> <?php $link_button( $o->id, __( 'Link', 'rotary-grants' ), $relink ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( ! $organisation ) : ?>
            <p><?php $link_button( 0, __( 'None of these — create a new organisation from this application', 'rotary-grants' ), false ); ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ( $organisation ) : ?>
        <h3><?php esc_html_e( 'Previous applications from this organisation', 'rotary-grants' ); ?></h3>
        <?php if ( ! $history ) : ?>
            <p><?php esc_html_e( 'None — this is its first application on record.', 'rotary-grants' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th scope="col"><?php esc_html_e( 'Reference', 'rotary-grants' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Round', 'rotary-grants' ); ?></th>
                    <th scope="col" class="num"><?php esc_html_e( 'Requested', 'rotary-grants' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Status', 'rotary-grants' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $history as $h ) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $h->id ], $list_url ) ); ?>"><?php echo esc_html( $h->public_reference ); ?></a></td>
                        <td><?php echo esc_html( $h->fund_name . ' — ' . $h->round_label ); ?></td>
                        <td class="num"><?php echo esc_html( $h->requested_pence === null ? '—' : Money::format_gbp( $h->requested_pence ) ); ?></td>
                        <td><?php echo esc_html( ApplicationStatus::label( $h->status ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e( 'Award and payment history will appear here once decisions and payments are recorded.', 'rotary-grants' ); ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php include __DIR__ . '/partials/committee-panel.php'; ?>

    <h2><?php esc_html_e( 'As submitted', 'rotary-grants' ); ?></h2>
    <?php foreach ( $groups as $heading => $fields ) : ?>
        <h3><?php echo esc_html( $heading ); ?></h3>
        <table class="widefat striped grants-kv">
            <tbody>
                <?php foreach ( $fields as $f ) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html( $labels[ $f ] ?? $f ); ?></th>
                        <td><?php echo nl2br( esc_html( $show( $f ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped before nl2br ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
</div>
