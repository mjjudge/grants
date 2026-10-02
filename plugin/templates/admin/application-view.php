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
 */
defined( 'ABSPATH' ) || exit;

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

    <table class="widefat striped grants-kv">
        <tbody>
            <tr><th scope="row"><?php esc_html_e( 'Status', 'rotary-grants' ); ?></th><td><?php echo esc_html( ApplicationStatus::label( $application->status ) ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Round', 'rotary-grants' ); ?></th><td><?php echo esc_html( ( $snapshot['round']['fund_name'] ?? '' ) . ' — ' . ( $snapshot['round']['label'] ?? '' ) ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Amount requested', 'rotary-grants' ); ?></th><td><?php echo esc_html( $application->requested_pence === null ? '—' : Money::format_gbp( $application->requested_pence ) ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Submitted', 'rotary-grants' ); ?></th><td><?php echo esc_html( SiteTime::display( $application->submitted_at ) ?: '—' ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Source', 'rotary-grants' ); ?></th><td><?php echo esc_html( $application->source ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Versions', 'rotary-grants' ); ?></th><td><?php echo esc_html( sprintf( /* translators: 1: form version, 2: wording version, 3: privacy notice version */ __( 'Form %1$s · round wording %2$s · privacy notice %3$s', 'rotary-grants' ), $snapshot['form_version'] ?? '?', $snapshot['policy_version'] ?? '?', ( $snapshot['privacy_notice']['version'] ?? '' ) ?: '—' ) ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Organisation record', 'rotary-grants' ); ?></th><td><?php esc_html_e( 'Not yet linked — organisation matching arrives in a later release.', 'rotary-grants' ); ?></td></tr>
        </tbody>
    </table>

    <?php foreach ( $groups as $heading => $fields ) : ?>
        <h2><?php echo esc_html( $heading ); ?></h2>
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
