<?php
/**
 * Portal template — one application for the committee.
 *
 * Available variables: common portal vars (see _nav.php; also $notice, $nonce_action, $nonce_field) plus
 *   $app object, $snapshot array, $labels array<string,string>, $round object|null,
 *   $history list<array{app: object, award: object|null, paid: int}>  organisation's other applications,
 *   $declaration object|null, $decl_status string, $is_clear bool,
 *   $reviews object[] (current reviews; [] unless clear), $my_review object|null, $checks array<string,string>,
 *   $notes object[] (committee record, sent requests only; [] unless clear),
 *   $can_review bool, $can_decide bool,
 *   $award object|null, $award_conditions object[], $award_paid int,
 *   $decision_history object[] ([] unless clear), $budget array|null,
 *   $decision_state array|null  {input, code, data} after a refused decision,
 *   $error string  message from the last action.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\ApplicationService;
use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Services\AwardService;
use Rotary\Grants\Services\ConflictService;
use Rotary\Grants\Services\DecisionService;
use Rotary\Grants\Services\ReviewService;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

$crumbs = $round ? [ $round->fund_name . ' — ' . $round->label => add_query_arg( [ 'view' => 'round', 'round' => $round->id ], $base ), $app->organisation_name => '' ] : [ $app->organisation_name => '' ];
include __DIR__ . '/_nav.php';

$fmt  = static fn( ?int $p ): string => $p === null ? '—' : Money::format_gbp( $p );
$day  = static fn( ?string $d ): string => $d ? wp_date( get_option( 'date_format' ), strtotime( substr( $d, 0, 10 ) . ' 12:00:00' ) ) : '—';
$form = static function ( string $action ) use ( $app, $nonce_action, $nonce_field ): void {
    printf( '<form method="post" action="%s" class="grants-portal__form">', esc_url( admin_url( 'admin-post.php' ) ) );
    printf( '<input type="hidden" name="action" value="%s"><input type="hidden" name="application_id" value="%d"><input type="hidden" name="grants_return" value="portal">', esc_attr( 'grants_' . $action ), (int) $app->id );
    wp_nonce_field( $nonce_action, $nonce_field );
};
$answers = (array) ( $snapshot['answers'] ?? [] );
$show    = static function ( string $f ) use ( $answers ): string {
    $v = $answers[ $f ] ?? null;
    return match ( true ) {
        $v === true => __( 'Yes', 'rotary-grants' ), $v === false => __( 'No', 'rotary-grants' ),
        $v === 'yes' => __( 'Yes', 'rotary-grants' ), $v === 'no' => __( 'No', 'rotary-grants' ), $v === 'not_sure' => __( 'Not sure', 'rotary-grants' ),
        $v === null || $v === '' => '—', default => (string) $v,
    };
};
$notices = [
    'declared'            => __( 'Declaration recorded.', 'rotary-grants' ),
    'review_saved'        => __( 'Your review has been saved.', 'rotary-grants' ),
    'note_added'          => __( 'Note added.', 'rotary-grants' ),
    'decided'             => __( 'Decision recorded.', 'rotary-grants' ),
    'decided_over_budget' => __( 'Decision recorded — beyond the round budget, with your funding note.', 'rotary-grants' ),
    'reopened'            => __( 'Decision reopened — the application is back under review.', 'rotary-grants' ),
];
$in   = (array) ( $decision_state['input'] ?? [] );
$over = ( $decision_state['code'] ?? '' ) === 'over_budget';
?>
<header class="grants-portal__app-head">
    <h2><?php echo esc_html( $app->organisation_name ); ?></h2>
    <p class="grants-portal__muted">
        <?php echo esc_html( $app->public_reference ); ?> ·
        <?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'requesting %s', 'rotary-grants' ), $fmt( $app->requested_pence ) ) ); ?> ·
        <?php echo esc_html( ApplicationStatus::label( $app->status ) ); ?>
        <?php if ( $app->source !== ApplicationService::SOURCE_PUBLIC ) : ?> · <?php echo esc_html( ApplicationService::source_label( $app->source ) ); ?><?php endif; ?>
        <?php if ( $app->is_late ) : ?> · <strong class="grants-portal__warn"><?php esc_html_e( 'Late', 'rotary-grants' ); ?></strong><?php endif; ?>
    </p>
</header>

<?php if ( isset( $notices[ $notice ] ) && $error === '' ) : ?><p class="grants-portal__notice" role="status"><?php echo esc_html( $notices[ $notice ] ); ?></p><?php endif; ?>
<?php if ( $error !== '' ) : ?><p class="grants-portal__error" role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>

<section class="grants-portal__section grants-portal__declare-box<?php echo $is_clear ? ' is-clear' : ''; ?>" id="grants-committee">
    <?php if ( in_array( $decl_status, [ ConflictService::FINANCIAL, ConflictService::LOYALTY ], true ) ) : ?>
        <p><strong><?php echo esc_html( ConflictService::label( $decl_status ) ); ?></strong> — <?php echo esc_html( (string) $declaration->description ); ?></p>
        <p><?php esc_html_e( 'You have declared a conflict, so you can read the application but not the committee\'s reviews or notes, and you can\'t review or decide it.', 'rotary-grants' ); ?></p>
    <?php else : ?>
        <?php if ( $is_clear ) : ?>
            <p><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'You declared no conflict of interest on %s.', 'rotary-grants' ), SiteTime::display( $declaration->declared_at ) ) ); ?></p>
            <details><summary><?php esc_html_e( 'That has changed — declare a conflict', 'rotary-grants' ); ?></summary>
        <?php else : ?>
            <p><strong><?php esc_html_e( 'Before you see the committee\'s reviews and notes, or review this application, declare whether you have a conflict of interest.', 'rotary-grants' ); ?></strong></p>
        <?php endif; ?>
        <?php $form( 'declare' ); ?>
            <fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Conflict of interest', 'rotary-grants' ); ?></legend>
                <?php if ( ! $is_clear ) : ?><label class="grants-portal__choice"><input type="radio" name="declaration" value="none"> <?php esc_html_e( 'I have no conflict of interest', 'rotary-grants' ); ?></label><?php endif; ?>
                <label class="grants-portal__choice"><input type="radio" name="declaration" value="financial"> <?php esc_html_e( 'Financial conflict', 'rotary-grants' ); ?></label>
                <label class="grants-portal__choice"><input type="radio" name="declaration" value="loyalty"> <?php esc_html_e( 'Loyalty conflict (e.g. I\'m involved with this organisation)', 'rotary-grants' ); ?></label>
            </fieldset>
            <label class="grants-portal__field"><?php esc_html_e( 'Description (required for a conflict)', 'rotary-grants' ); ?> <input type="text" name="description"></label>
            <button type="submit" class="grants-portal__button grants-portal__button--primary"><?php esc_html_e( 'Record declaration', 'rotary-grants' ); ?></button>
        </form>
        <?php if ( $is_clear ) : ?></details><?php endif; ?>
    <?php endif; ?>
</section>

<?php if ( $is_clear ) : ?>
    <section class="grants-portal__section">
        <h3><?php esc_html_e( 'Reviews', 'rotary-grants' ); ?></h3>
        <?php if ( ! $reviews ) : ?><p><?php esc_html_e( 'No reviews yet.', 'rotary-grants' ); ?></p><?php endif; ?>
        <?php foreach ( $reviews as $r ) : ?>
            <article class="grants-portal__review">
                <p><strong><?php echo esc_html( (string) $r->display_name ); ?></strong>: <?php echo esc_html( ReviewService::recommendation_label( $r->recommendation ) . ( $r->recommended_pence !== null ? ' ' . Money::format_gbp( $r->recommended_pence ) : '' ) ); ?>
                    <span class="grants-portal__muted"><?php echo esc_html( SiteTime::display( $r->reviewed_at ) ); ?></span></p>
                <ul>
                    <?php foreach ( $checks as $k => $label ) : $f = $r->findings[ $k ] ?? [ 'finding' => 'not_checked', 'note' => '' ]; if ( $f['finding'] === 'not_checked' && $f['note'] === '' ) { continue; } ?>
                        <li class="grants-portal__finding--<?php echo esc_attr( $f['finding'] ); ?>"><?php echo esc_html( ReviewService::finding_label( $f['finding'] ) . ': ' . $label . ( $f['note'] !== '' ? ' — ' . $f['note'] : '' ) ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ( (string) $r->notes !== '' ) : ?><p><?php echo nl2br( esc_html( (string) $r->notes ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p><?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php if ( $can_review ) : ?>
            <details class="grants-portal__review-form"<?php echo $my_review ? '' : ' open'; ?>>
                <summary><strong><?php echo $my_review ? esc_html__( 'Update my review', 'rotary-grants' ) : esc_html__( 'Add my review', 'rotary-grants' ); ?></strong></summary>
                <?php $form( 'review_save' ); ?>
                    <?php foreach ( $checks as $k => $label ) : $cur = $my_review->findings[ $k ] ?? [ 'finding' => 'not_checked', 'note' => '' ]; ?>
                        <div class="grants-portal__check">
                            <label for="gp-f-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label>
                            <select id="gp-f-<?php echo esc_attr( $k ); ?>" name="findings[<?php echo esc_attr( $k ); ?>]">
                                <?php foreach ( ReviewService::FINDINGS as $f ) : ?><option value="<?php echo esc_attr( $f ); ?>" <?php selected( $cur['finding'], $f ); ?>><?php echo esc_html( ReviewService::finding_label( $f ) ); ?></option><?php endforeach; ?>
                            </select>
                            <input type="text" name="finding_notes[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $cur['note'] ); ?>" placeholder="<?php esc_attr_e( 'What you checked, any doubt', 'rotary-grants' ); ?>" aria-label="<?php echo esc_attr( $label . ' — ' . __( 'note', 'rotary-grants' ) ); ?>">
                        </div>
                    <?php endforeach; ?>
                    <label class="grants-portal__field"><?php esc_html_e( 'Recommendation', 'rotary-grants' ); ?>
                        <select name="recommendation"><?php foreach ( ReviewService::RECOMMENDATIONS as $rec ) : ?><option value="<?php echo esc_attr( $rec ); ?>" <?php selected( $my_review->recommendation ?? 'none', $rec ); ?>><?php echo esc_html( ReviewService::recommendation_label( $rec ) ); ?></option><?php endforeach; ?></select></label>
                    <label class="grants-portal__field"><?php esc_html_e( 'Part amount (£), if recommending part', 'rotary-grants' ); ?>
                        <input type="text" inputmode="decimal" name="recommended_amount" value="<?php echo esc_attr( ( $my_review && $my_review->recommendation === 'fund_partial' ) ? Money::to_input( $my_review->recommended_pence ) : '' ); ?>"></label>
                    <label class="grants-portal__field"><?php esc_html_e( 'Private notes for the committee', 'rotary-grants' ); ?>
                        <textarea name="notes" rows="4"><?php echo esc_textarea( (string) ( $my_review->notes ?? '' ) ); ?></textarea></label>
                    <button type="submit" class="grants-portal__button grants-portal__button--primary"><?php esc_html_e( 'Save review', 'rotary-grants' ); ?></button>
                </form>
            </details>
        <?php endif; ?>
    </section>

    <section class="grants-portal__section">
        <h3><?php esc_html_e( 'Committee notes', 'rotary-grants' ); ?></h3>
        <?php if ( ! $notes ) : ?><p><?php esc_html_e( 'Nothing recorded yet.', 'rotary-grants' ); ?></p><?php endif; ?>
        <ol class="grants-portal__timeline">
            <?php foreach ( $notes as $n ) : ?>
                <li class="grants-portal__note--<?php echo esc_attr( $n->kind ); ?>">
                    <span class="grants-portal__muted"><?php echo esc_html( SiteTime::display( $n->created_at ) . ' · ' . (string) $n->author_name ); ?></span>
                    <div><?php echo nl2br( esc_html( (string) $n->body ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php $form( 'note_add' ); ?>
            <label class="grants-portal__field"><?php esc_html_e( 'Add a note (committee only, never shown to the applicant)', 'rotary-grants' ); ?>
                <textarea name="body" rows="3" required></textarea></label>
            <button type="submit" class="grants-portal__button"><?php esc_html_e( 'Add note', 'rotary-grants' ); ?></button>
        </form>
    </section>

    <section class="grants-portal__section" id="grants-decision">
        <h3><?php esc_html_e( 'Decision', 'rotary-grants' ); ?></h3>
        <?php if ( $budget ) : ?>
            <p class="grants-portal__stats"><?php esc_html_e( 'Round budget', 'rotary-grants' ); ?> <strong><?php echo esc_html( $fmt( $budget['budget'] ) ); ?></strong> ·
                <?php esc_html_e( 'approved', 'rotary-grants' ); ?> <strong><?php echo esc_html( $fmt( $budget['committed'] ) ); ?></strong> ·
                <?php echo $budget['over_committed'] ? '<strong class="grants-portal__warn">' . esc_html( sprintf( /* translators: %s: amount */ __( 'over budget by %s', 'rotary-grants' ), Money::format_gbp( $budget['over_committed'] ) ) ) . '</strong>' : esc_html__( 'available', 'rotary-grants' ) . ' <strong>' . esc_html( $fmt( $budget['available'] ) ) . '</strong>'; ?></p>
        <?php endif; ?>
        <?php if ( $award ) : ?>
            <p class="grants-portal__award"><strong><?php echo esc_html( sprintf( /* translators: 1: amount, 2: status */ __( 'Award: %1$s (%2$s)', 'rotary-grants' ), $fmt( $award->approved_pence ), AwardService::status_label( $award->status ) ) ); ?></strong>
                <?php if ( $award_paid ) : ?> · <?php echo esc_html( sprintf( /* translators: %s: amount */ __( '%s paid', 'rotary-grants' ), Money::format_gbp( $award_paid ) ) ); ?><?php endif; ?>
                <?php if ( $award->over_budget_pence > 0 ) : ?><br><?php echo esc_html( sprintf( /* translators: 1: amount, 2: note */ __( '%1$s beyond the round budget — funded from: %2$s', 'rotary-grants' ), Money::format_gbp( $award->over_budget_pence ), (string) $award->funding_note ) ); ?><?php endif; ?></p>
            <?php if ( $award_conditions ) : ?><ul><?php foreach ( $award_conditions as $c ) : ?><li><?php echo esc_html( $c->condition_text . ( $c->fulfilled_at ? ' — ' . __( 'met', 'rotary-grants' ) : ( (int) $c->before_payment ? ' — ' . __( 'to be met before payment', 'rotary-grants' ) : '' ) ) ); ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php endif; ?>
        <?php foreach ( $decision_history as $d ) : ?>
            <p class="<?php echo $d->superseded_by ? 'grants-portal__dim' : ''; ?>"><?php echo esc_html( sprintf(
                /* translators: 1: decision, 2: amount, 3: date, 4: reason, 5: who */
                __( '%1$s%2$s on %3$s — %4$s (%5$s)', 'rotary-grants' ),
                DecisionService::label( $d->decision_type ), $d->approved_pence !== null ? ' ' . Money::format_gbp( (int) $d->approved_pence ) : '', $day( $d->decided_on ), $d->reason, (string) $d->display_name
            ) ); ?><?php echo $d->superseded_by ? ' ' . esc_html__( '(superseded)', 'rotary-grants' ) : ''; ?></p>
        <?php endforeach; ?>

        <?php if ( $can_decide && $app->status === ApplicationStatus::DECIDED ) : ?>
            <details><summary><?php esc_html_e( 'Reopen this decision', 'rotary-grants' ); ?></summary>
                <?php $form( 'reopen' ); ?>
                    <input type="hidden" name="row_version" value="<?php echo esc_attr( (string) $app->row_version ); ?>">
                    <label class="grants-portal__field"><?php esc_html_e( 'Reason', 'rotary-grants' ); ?> <input type="text" name="reason" required></label>
                    <p class="grants-portal__muted"><?php esc_html_e( 'The current decision stays on record and any award stays in place until the new decision is made.', 'rotary-grants' ); ?></p>
                    <button type="submit" class="grants-portal__button"><?php esc_html_e( 'Reopen', 'rotary-grants' ); ?></button>
                </form>
            </details>
        <?php elseif ( $can_decide && ApplicationStatus::is_open( $app->status ) ) : ?>
            <?php if ( $over ) : ?>
                <div class="grants-portal__warning" role="alert"><strong><?php esc_html_e( 'Over budget', 'rotary-grants' ); ?></strong> — <?php echo esc_html( sprintf(
                    /* translators: 1: over, 2: available */
                    __( 'approving this would take the round %1$s over its budget (%2$s is available). If the committee has agreed to fund the difference from elsewhere, tick the box and say where the money is coming from.', 'rotary-grants' ),
                    Money::format_gbp( (int) ( $decision_state['data']['over'] ?? 0 ) ), Money::format_gbp( max( 0, (int) ( $decision_state['data']['available'] ?? 0 ) ) )
                ) ); ?></div>
            <?php endif; ?>
            <?php $form( 'decide' ); ?>
                <fieldset><legend><strong><?php esc_html_e( 'Record the committee\'s decision', 'rotary-grants' ); ?></strong></legend>
                    <?php foreach ( [ 'approve' => __( 'Approve', 'rotary-grants' ), 'decline' => __( 'Decline', 'rotary-grants' ), 'defer' => __( 'Defer', 'rotary-grants' ) ] as $val => $lab ) : ?>
                        <label class="grants-portal__choice"><input type="radio" name="decision_type" value="<?php echo esc_attr( $val ); ?>" <?php checked( (string) ( $in['type'] ?? '' ), $val ); ?>> <?php echo esc_html( $lab ); ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <label class="grants-portal__field"><?php esc_html_e( 'Amount approved (£) — approvals only; a part award is fine', 'rotary-grants' ); ?>
                    <input type="text" inputmode="decimal" name="amount" value="<?php echo esc_attr( (string) ( $in['amount'] ?? Money::to_input( $app->requested_pence ) ) ); ?>"></label>
                <label class="grants-portal__field"><?php esc_html_e( 'Reason (recorded; not sent to the applicant)', 'rotary-grants' ); ?>
                    <textarea name="reason" rows="2" required><?php echo esc_textarea( (string) ( $in['reason'] ?? '' ) ); ?></textarea></label>
                <label class="grants-portal__field"><?php esc_html_e( 'Date of decision', 'rotary-grants' ); ?>
                    <input type="date" name="decided_on" value="<?php echo esc_attr( (string) ( $in['decided_on'] ?? wp_date( 'Y-m-d' ) ) ); ?>" required></label>
                <label class="grants-portal__field"><?php esc_html_e( 'Meeting reference (optional)', 'rotary-grants' ); ?>
                    <input type="text" name="meeting_reference" value="<?php echo esc_attr( (string) ( $in['meeting_reference'] ?? '' ) ); ?>"></label>
                <details<?php echo ! empty( $in['conditions'][0]['text'] ) ? ' open' : ''; ?>><summary><?php esc_html_e( 'Conditions (optional)', 'rotary-grants' ); ?></summary>
                    <?php for ( $i = 0; $i < 3; $i++ ) : $c = $in['conditions'][ $i ] ?? []; ?>
                        <div class="grants-portal__check">
                            <input type="text" name="conditions[<?php echo esc_attr( (string) $i ); ?>][text]" value="<?php echo esc_attr( (string) ( $c['text'] ?? '' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: number */ __( 'Condition %d', 'rotary-grants' ), $i + 1 ) ); ?>">
                            <label><input type="checkbox" name="conditions[<?php echo esc_attr( (string) $i ); ?>][before_payment]" value="1" <?php checked( ! isset( $c['before_payment'] ) || ! empty( $c['before_payment'] ) ); ?>> <?php esc_html_e( 'Before payment', 'rotary-grants' ); ?></label>
                        </div>
                    <?php endfor; ?>
                </details>
                <fieldset class="grants-portal__over<?php echo $over ? ' is-active' : ''; ?>">
                    <legend><?php esc_html_e( 'Only if approving beyond the round budget', 'rotary-grants' ); ?></legend>
                    <label class="grants-portal__choice"><input type="checkbox" name="confirm_over_budget" value="1" <?php checked( ! empty( $in['confirm_over_budget'] ) ); ?>> <?php esc_html_e( 'The committee has agreed to approve this beyond the round budget', 'rotary-grants' ); ?></label>
                    <label class="grants-portal__field"><?php esc_html_e( 'Where is the extra money coming from?', 'rotary-grants' ); ?>
                        <textarea name="funding_note" rows="2"><?php echo esc_textarea( (string) ( $in['funding_note'] ?? '' ) ); ?></textarea></label>
                </fieldset>
                <button type="submit" class="grants-portal__button grants-portal__button--primary"><?php esc_html_e( 'Record decision', 'rotary-grants' ); ?></button>
            </form>
        <?php elseif ( ! $decision_history ) : ?>
            <p class="grants-portal__muted"><?php esc_html_e( 'Not decided yet.', 'rotary-grants' ); ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="grants-portal__section">
    <h3><?php esc_html_e( 'The application, as submitted', 'rotary-grants' ); ?></h3>
    <p class="grants-portal__muted"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Received %s', 'rotary-grants' ), SiteTime::display( $app->submitted_at ) ) ); ?></p>
    <dl class="grants-portal__answers">
        <?php foreach ( [ 'organisation_name', 'charity_number', 'organisation_town', 'organisation_postcode', 'website_url', 'organisation_overview', 'proposed_use', 'expected_difference', 'local_benefit', 'one_off_initiative', 'one_off_explanation', 'has_organisation_bank_account', 'has_governing_document', 'locally_led_and_run', 'locally_led_explanation', 'has_committee_or_support_group', 'is_local_branch', 'branch_explanation', 'contact_name', 'contact_role', 'contact_email', 'contact_phone' ] as $f ) : ?>
            <dt><?php echo esc_html( $labels[ $f ] ?? $f ); ?></dt>
            <dd><?php echo nl2br( esc_html( $show( $f ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
        <?php endforeach; ?>
    </dl>
</section>

<?php if ( $history ) : ?>
    <section class="grants-portal__section">
        <h3><?php esc_html_e( 'Previous applications from this organisation', 'rotary-grants' ); ?></h3>
        <ul>
            <?php foreach ( $history as $h ) : $ha = $h['app']; ?>
                <li><?php echo esc_html( sprintf(
                    /* translators: 1: round, 2: requested, 3: status, 4: awarded/paid */
                    __( '%1$s — requested %2$s — %3$s%4$s', 'rotary-grants' ),
                    $ha->fund_name . ' ' . $ha->round_label, $fmt( $ha->requested_pence ), ApplicationStatus::label( $ha->status ),
                    $h['award'] && $h['award']->status === 'approved' ? ' — ' . sprintf( /* translators: 1: awarded, 2: paid */ __( 'awarded %1$s, paid %2$s', 'rotary-grants' ), $fmt( $h['award']->approved_pence ), Money::format_gbp( $h['paid'] ) ) : ''
                ) ); ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
