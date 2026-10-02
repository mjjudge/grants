<?php
/**
 * Admin template — add / edit funding round.
 *
 * Available variables:
 *   $round        object|null           Hydrated grants_rounds row, or null when adding.
 *   $values       array<string,string>  Form values keyed by field name (label, fund_name,
 *                                       campaign_year, accounting_period_label, opens_at,
 *                                       closes_at [local Y-m-d\TH:i], budget, cap [pounds],
 *                                       intro_text, eligibility_text, exclusions_text,
 *                                       publicity_text, presentation_text).
 *   $errors       array<string,string>  Errors keyed by field name, or 'status' / 'conflict' /
 *                                       'open_round' / 'forbidden' / 'db_error' etc.
 *   $copied_from  object|null           Round whose fund name/wording prefilled an add form.
 *   $notice       string                'created', 'saved', 'status_<to>', 'error', or ''.
 *   $blockers     string[]              Reasons the round cannot be opened (draft/closed only).
 *   $phase        string                RoundService::phase() for an existing round, else ''.
 *   $transitions  string[]              Statuses this round may move to.
 *   $read_only    bool                  True for archived rounds.
 *   $timezone     string                Site timezone name, for field descriptions.
 *   $fixed_offset bool                  True if the site timezone is a fixed UTC offset
 *                                       (no summer time) rather than a named city.
 *   $general_url  string                Settings → General URL (where the timezone is set).
 *   $list_url     string                Round list URL.
 *   $save_nonce   array{0:string,1:string}       Nonce action and field for the save form.
 *   $status_nonce array{0:string,1:string}|null  Nonce action and field for status forms.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\RoundStatus;
use Rotary\Grants\Support\SiteTime;

$field_labels = [
    'label'                   => __( 'Round label', 'rotary-grants' ),
    'fund_name'               => __( 'Fund name', 'rotary-grants' ),
    'campaign_year'           => __( 'Campaign year', 'rotary-grants' ),
    'accounting_period_label' => __( 'Accounting period', 'rotary-grants' ),
    'opens_at'                => __( 'Opens', 'rotary-grants' ),
    'closes_at'               => __( 'Closes', 'rotary-grants' ),
    'budget'                  => __( 'Budget (£)', 'rotary-grants' ),
    'cap'                     => __( 'Maximum award (£)', 'rotary-grants' ),
    'intro_text'              => __( 'Introduction', 'rotary-grants' ),
    'eligibility_text'        => __( 'Eligibility', 'rotary-grants' ),
    'exclusions_text'         => __( 'Exclusions', 'rotary-grants' ),
    'publicity_text'          => __( 'Publicity undertaking', 'rotary-grants' ),
    'presentation_text'       => __( 'Presentation on collection', 'rotary-grants' ),
];
$descriptions = [
    'label'                   => __( 'How the round is shown to staff and applicants, e.g. "Tree of Light 2026".', 'rotary-grants' ),
    'fund_name'               => __( 'Which fund this round distributes, e.g. "Tree of Light". Only one round per fund can be open at a time.', 'rotary-grants' ),
    'accounting_period_label' => __( 'Optional, e.g. "2026–27". Payments are still reported by their actual date.', 'rotary-grants' ),
    /* translators: %s: timezone name */
    'opens_at'                => sprintf( __( 'Site time (%s). Applications are accepted from this moment.', 'rotary-grants' ), $timezone ),
    /* translators: %s: timezone name */
    'closes_at'               => sprintf( __( 'Site time (%s). Applications are refused from this moment — a form left open in a browser cannot submit after it.', 'rotary-grants' ), $timezone ),
    'budget'                  => __( 'Total available to award in this round. Whole pounds or pounds and pence, e.g. 10000 or 10,000.00.', 'rotary-grants' ),
    'cap'                     => __( 'Optional. Leave empty unless the committee has agreed a maximum single award.', 'rotary-grants' ),
    'intro_text'              => __( 'Optional opening paragraph shown on the application page.', 'rotary-grants' ),
    'eligibility_text'        => __( 'Who can apply. Required before the round can open.', 'rotary-grants' ),
    'exclusions_text'         => __( 'What will not be funded. Required before the round can open.', 'rotary-grants' ),
    'publicity_text'          => __( 'Optional — leave empty if this fund asks no publicity undertaking. If set, applicants must tick to acknowledge it.', 'rotary-grants' ),
    'presentation_text'       => __( 'Optional — e.g. that a representative may be asked to say a few words when collecting the donation. If set, applicants must tick to acknowledge it.', 'rotary-grants' ),
];
$required = [ 'label', 'fund_name', 'campaign_year' ];

$field_error = static function ( string $field ) use ( $errors ): string {
    return (string) ( $errors[ $field ] ?? '' );
};
$describedby = static function ( string $field ) use ( $errors, $descriptions ): string {
    $ids = [];
    if ( isset( $descriptions[ $field ] ) ) {
        $ids[] = 'grants-' . $field . '-desc';
    }
    if ( isset( $errors[ $field ] ) ) {
        $ids[] = 'grants-' . $field . '-error';
    }
    return implode( ' ', $ids );
};
$help = static function ( string $field ) use ( $errors, $descriptions ): void {
    if ( isset( $errors[ $field ] ) ) {
        printf( '<p class="grants-field-error" id="%s">%s</p>', esc_attr( 'grants-' . $field . '-error' ), esc_html( $errors[ $field ] ) );
    }
    if ( isset( $descriptions[ $field ] ) ) {
        printf( '<p class="description" id="%s">%s</p>', esc_attr( 'grants-' . $field . '-desc' ), esc_html( $descriptions[ $field ] ) );
    }
};
$attrs = static function ( string $field ) use ( $errors, $describedby, $read_only, $required ): string {
    $out = ' id="' . esc_attr( 'grants-' . $field ) . '" name="' . esc_attr( $field ) . '"';
    if ( $describedby( $field ) !== '' ) {
        $out .= ' aria-describedby="' . esc_attr( $describedby( $field ) ) . '"';
    }
    if ( isset( $errors[ $field ] ) ) {
        $out .= ' aria-invalid="true"';
    }
    if ( in_array( $field, $required, true ) ) {
        $out .= ' aria-required="true"';
    }
    if ( $read_only ) {
        $out .= ' readonly';
    }
    return $out;
};

$phase_text = [
    'scheduled'       => __( 'Open — not yet accepting applications (before the opening time).', 'rotary-grants' ),
    'accepting'       => __( 'Open — accepting applications now.', 'rotary-grants' ),
    'deadline_passed' => __( 'Open — the closing time has passed, so applications are refused. Close the round to tidy up.', 'rotary-grants' ),
];
$notice_text = [
    'created'         => __( 'Funding round created as a draft.', 'rotary-grants' ),
    'saved'           => __( 'Funding round saved.', 'rotary-grants' ),
    'status_open'     => __( 'Round opened.', 'rotary-grants' ),
    'status_closed'   => __( 'Round closed.', 'rotary-grants' ),
    'status_archived' => __( 'Round archived.', 'rotary-grants' ),
];
?>
<div class="wrap grants-admin">
    <h1><?php echo $round ? esc_html( $round->label ) : esc_html__( 'Add Funding Round', 'rotary-grants' ); ?></h1>
    <p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'All funding rounds', 'rotary-grants' ); ?></a></p>

    <?php if ( isset( $notice_text[ $notice ] ) && ! $errors ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice_text[ $notice ] ); ?></p></div>
    <?php endif; ?>

    <?php if ( $errors ) : ?>
        <div class="notice notice-error" id="grants-error-summary" tabindex="-1">
            <p><strong><?php echo isset( $errors['status'] ) ? esc_html__( 'The status was not changed:', 'rotary-grants' ) : esc_html__( 'The round was not saved. Please correct the following:', 'rotary-grants' ); ?></strong></p>
            <ul>
                <?php foreach ( $errors as $field => $message ) : ?>
                    <li>
                        <?php if ( isset( $field_labels[ $field ] ) ) : ?>
                            <a href="#grants-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $field_labels[ $field ] . ': ' . $message ); ?></a>
                        <?php else : ?>
                            <?php echo esc_html( $message ); ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <script>document.getElementById('grants-error-summary').focus();</script>
    <?php endif; ?>

    <?php if ( $fixed_offset ) : ?>
        <div class="notice notice-warning inline"><p>
            <?php
            echo esc_html( sprintf(
                /* translators: %s: timezone offset such as +00:00 */
                __( 'The site timezone is a fixed offset (%s), which does not change for British Summer Time. Opening and closing times entered here would be an hour out for part of the year. Choose a city such as "London" under Settings → General → Timezone.', 'rotary-grants' ),
                $timezone
            ) );
            ?>
            <a href="<?php echo esc_url( $general_url ); ?>"><?php esc_html_e( 'Open General Settings', 'rotary-grants' ); ?></a>
        </p></div>
    <?php endif; ?>

    <?php if ( $copied_from ) : ?>
        <div class="notice notice-info inline"><p>
            <?php
            echo esc_html( sprintf(
                /* translators: %s: label of the round the wording was copied from */
                __( 'The fund name and wording below were copied from "%s". Review them before saving.', 'rotary-grants' ),
                $copied_from->label
            ) );
            ?>
        </p></div>
    <?php endif; ?>

    <?php if ( $round ) : ?>
        <div class="grants-round-status-box">
            <h2><?php esc_html_e( 'Status', 'rotary-grants' ); ?></h2>
            <p>
                <strong><?php echo esc_html( RoundStatus::label( $round->status ) ); ?></strong>
                <?php if ( isset( $phase_text[ $phase ] ) ) : ?>
                    — <?php echo esc_html( $phase_text[ $phase ] ); ?>
                <?php endif; ?>
            </p>
            <p class="description">
                <?php
                echo esc_html( sprintf(
                    /* translators: 1: policy version number, 2: last updated date */
                    __( 'Wording version %1$s · last updated %2$s', 'rotary-grants' ),
                    $round->policy_version,
                    SiteTime::display( $round->updated_at )
                ) );
                ?>
            </p>

            <?php if ( $blockers && in_array( RoundStatus::OPEN, $transitions, true ) ) : ?>
                <p><?php esc_html_e( 'Before this round can open:', 'rotary-grants' ); ?></p>
                <ul class="grants-blockers">
                    <?php foreach ( $blockers as $blocker ) : ?>
                        <li><?php echo esc_html( $blocker ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php foreach ( $transitions as $to ) : ?>
                <?php
                if ( $to === RoundStatus::OPEN && $blockers ) {
                    continue;
                }
                ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="grants-inline-form"
                      onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( sprintf( /* translators: %s: action, e.g. "Open round" */ __( '%s — are you sure?', 'rotary-grants' ), RoundStatus::action_label( $round->status, $to ) ) ) ); ?>);">
                    <input type="hidden" name="action" value="grants_round_status">
                    <input type="hidden" name="round_id" value="<?php echo esc_attr( (string) $round->id ); ?>">
                    <input type="hidden" name="row_version" value="<?php echo esc_attr( (string) $round->row_version ); ?>">
                    <input type="hidden" name="to" value="<?php echo esc_attr( $to ); ?>">
                    <?php wp_nonce_field( $status_nonce[0], $status_nonce[1] ); ?>
                    <button type="submit" class="button<?php echo $to === RoundStatus::OPEN ? ' button-primary' : ''; ?>"><?php echo esc_html( RoundStatus::action_label( $round->status, $to ) ); ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ( $read_only ) : ?>
        <div class="notice notice-info inline"><p><?php esc_html_e( 'This round is archived and can no longer be edited.', 'rotary-grants' ); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
        <input type="hidden" name="action" value="grants_round_save">
        <input type="hidden" name="round_id" value="<?php echo esc_attr( (string) ( $round->id ?? 0 ) ); ?>">
        <input type="hidden" name="row_version" value="<?php echo esc_attr( (string) ( $round->row_version ?? 0 ) ); ?>">
        <?php wp_nonce_field( $save_nonce[0], $save_nonce[1] ); ?>

        <h2><?php esc_html_e( 'Round details', 'rotary-grants' ); ?></h2>
        <table class="form-table" role="presentation">
            <?php foreach ( [ 'label', 'fund_name', 'campaign_year', 'accounting_period_label' ] as $field ) : ?>
                <tr>
                    <th scope="row"><label for="grants-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $field_labels[ $field ] ); ?><?php echo in_array( $field, $required, true ) ? ' <span class="grants-required" aria-hidden="true">*</span>' : ''; ?></label></th>
                    <td>
                        <input type="<?php echo $field === 'campaign_year' ? 'number' : 'text'; ?>" class="<?php echo $field === 'campaign_year' ? 'small-text' : 'regular-text'; ?>"
                               value="<?php echo esc_attr( $values[ $field ] ?? '' ); ?>"<?php echo $attrs( $field ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts ?>>
                        <?php $help( $field ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2><?php esc_html_e( 'Dates', 'rotary-grants' ); ?></h2>
        <table class="form-table" role="presentation">
            <?php foreach ( [ 'opens_at', 'closes_at' ] as $field ) : ?>
                <tr>
                    <th scope="row"><label for="grants-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $field_labels[ $field ] ); ?></label></th>
                    <td>
                        <input type="datetime-local" value="<?php echo esc_attr( $values[ $field ] ?? '' ); ?>"<?php echo $attrs( $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
                        <?php $help( $field ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2><?php esc_html_e( 'Money', 'rotary-grants' ); ?></h2>
        <table class="form-table" role="presentation">
            <?php foreach ( [ 'budget', 'cap' ] as $field ) : ?>
                <tr>
                    <th scope="row"><label for="grants-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $field_labels[ $field ] ); ?></label></th>
                    <td>
                        £ <input type="text" inputmode="decimal" class="regular-text grants-money" value="<?php echo esc_attr( $values[ $field ] ?? '' ); ?>"<?php echo $attrs( $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
                        <?php $help( $field ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2><?php esc_html_e( 'Public wording', 'rotary-grants' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Shown on this round\'s application form. Paragraphs, lists, bold and links are allowed. Changing any of these increases the wording version; each application records the version it was submitted under.', 'rotary-grants' ); ?></p>
        <table class="form-table" role="presentation">
            <?php foreach ( [ 'intro_text', 'eligibility_text', 'exclusions_text', 'publicity_text', 'presentation_text' ] as $field ) : ?>
                <tr>
                    <th scope="row"><label for="grants-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $field_labels[ $field ] ); ?></label></th>
                    <td>
                        <textarea rows="6" class="large-text"<?php echo $attrs( $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_textarea( $values[ $field ] ?? '' ); ?></textarea>
                        <?php $help( $field ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php if ( ! $read_only ) : ?>
            <?php submit_button( $round ? __( 'Save round', 'rotary-grants' ) : __( 'Create draft round', 'rotary-grants' ) ); ?>
        <?php endif; ?>
    </form>
</div>
