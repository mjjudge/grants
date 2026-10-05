<?php
/**
 * Admin partial — decision, award and decision notice (inside the committee
 * panel; only rendered for members who declared no conflict).
 *
 * Available variables:
 *   $application      object       Hydrated application.
 *   $can_decide       bool         grants_decide.
 *   $award            object|null  The application's award (any status).
 *   $award_conditions object[]     Award conditions (->fulfilled_by_name).
 *   $award_paid       int          Net paid against the award (0 until G08).
 *   $effective        object|null  Effective decision row.
 *   $decision_history object[]     All decisions, newest first (->display_name).
 *   $budget           array|null   BudgetService::summary() for the round.
 *   $decision_state   array|null   {input, code, data} after a failed decide (over_budget etc.).
 *   $notice_template  string       Suggested decision notice text.
 *   $notice_sent      bool         A decision notice has been sent.
 *   $can_send_notice  bool         May draft/send the decision notice.
 *   $can_conditions   bool         May mark conditions met.
 *   $form_open        callable     (action, class) → opens a committee POST form.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Services\AwardService;
use Rotary\Grants\Services\DecisionService;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

$in       = (array) ( $decision_state['input'] ?? [] );
$over     = ( $decision_state['code'] ?? '' ) === 'over_budget';
$fmt      = static fn( ?int $p ): string => $p === null ? '—' : Money::format_gbp( $p );
$date_fmt = static fn( ?string $d ): string => $d ? wp_date( get_option( 'date_format' ), strtotime( $d . ' 12:00:00' ) ) : '—';
?>
<h3 id="grants-decision"><?php esc_html_e( 'Decision', 'rotary-grants' ); ?></h3>

<?php if ( $budget ) : ?>
    <table class="widefat grants-budget-box"><tbody><tr>
        <td><span class="description"><?php esc_html_e( 'Round budget', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $budget['budget'] ) ); ?></strong></td>
        <td><span class="description"><?php esc_html_e( 'Approved so far', 'rotary-grants' ); ?></span><br><strong><?php echo esc_html( $fmt( $budget['committed'] ) ); ?></strong> <span class="description">(<?php echo esc_html( (string) $budget['award_count'] ); ?>)</span></td>
        <td><span class="description"><?php esc_html_e( 'Available to award', 'rotary-grants' ); ?></span><br>
            <?php if ( $budget['over_committed'] > 0 ) : ?>
                <strong class="grants-over"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'Over budget by %s', 'rotary-grants' ), Money::format_gbp( $budget['over_committed'] ) ) ); ?></strong>
            <?php else : ?>
                <strong><?php echo esc_html( $fmt( $budget['available'] ) ); ?></strong>
            <?php endif; ?>
        </td>
    </tr></tbody></table>
<?php endif; ?>

<?php if ( $award ) : ?>
    <div class="grants-award-box grants-award-box--<?php echo esc_attr( $award->status ); ?>">
        <p>
            <strong><?php esc_html_e( 'Award:', 'rotary-grants' ); ?> <?php echo esc_html( $fmt( $award->approved_pence ) ); ?></strong>
            — <?php echo esc_html( AwardService::status_label( $award->status ) ); ?>
            <?php if ( $award->approved_on ) : ?> · <?php echo esc_html( sprintf( /* translators: %s: date */ __( 'approved %s', 'rotary-grants' ), $date_fmt( $award->approved_on ) ) ); ?><?php endif; ?>
            <?php if ( $award_paid ) : ?> · <?php echo esc_html( sprintf( /* translators: %s: amount */ __( '%s paid', 'rotary-grants' ), Money::format_gbp( $award_paid ) ) ); ?><?php endif; ?>
            · <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'grants-payments', 'action' => 'award', 'id' => $award->id ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Payments', 'rotary-grants' ); ?></a>
        </p>
        <?php if ( $award->over_budget_pence > 0 ) : ?>
            <p class="grants-over-note"><strong><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'Approved %s beyond the round budget.', 'rotary-grants' ), Money::format_gbp( $award->over_budget_pence ) ) ); ?></strong>
                <?php esc_html_e( 'Funding source:', 'rotary-grants' ); ?> <?php echo esc_html( (string) $award->funding_note ); ?></p>
        <?php endif; ?>
        <?php if ( $award_conditions ) : ?>
            <p><strong><?php esc_html_e( 'Conditions', 'rotary-grants' ); ?></strong></p>
            <ul class="grants-conditions">
                <?php foreach ( $award_conditions as $c ) : ?>
                    <li>
                        <?php echo esc_html( $c->condition_text ); ?>
                        <?php if ( (int) $c->before_payment ) : ?><span class="grants-badge"><?php esc_html_e( 'before payment', 'rotary-grants' ); ?></span><?php endif; ?>
                        <?php if ( $c->fulfilled_at ) : ?>
                            <br><span class="grants-status--ok"><?php echo esc_html( sprintf( /* translators: 1: date, 2: name, 3: evidence */ __( 'Met %1$s (%2$s): %3$s', 'rotary-grants' ), SiteTime::display( $c->fulfilled_at ), (string) $c->fulfilled_by_name, (string) $c->evidence_note ) ); ?></span>
                        <?php elseif ( $can_conditions && $award->status === AwardService::APPROVED ) : ?>
                            <?php $form_open( 'condition_fulfil', 'grants-inline-form' ); ?>
                                <input type="hidden" name="condition_id" value="<?php echo esc_attr( (string) $c->id ); ?>">
                                <input type="text" name="evidence" required placeholder="<?php esc_attr_e( 'Evidence it has been met', 'rotary-grants' ); ?>">
                                <button type="submit" class="button button-small"><?php esc_html_e( 'Mark as met', 'rotary-grants' ); ?></button>
                            </form>
                        <?php else : ?>
                            <br><span class="grants-badge grants-badge--late"><?php esc_html_e( 'Not yet met', 'rotary-grants' ); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ( $decision_history ) : ?>
    <table class="widefat striped">
        <thead><tr>
            <th scope="col"><?php esc_html_e( 'Decided', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Decision', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Reason', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Recorded by', 'rotary-grants' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $decision_history as $d ) : ?>
            <tr class="<?php echo $d->superseded_by ? 'grants-row-former' : ''; ?>">
                <td><?php echo esc_html( $date_fmt( $d->decided_on ) ); ?><?php echo $d->meeting_reference !== '' ? '<br><span class="description">' . esc_html( $d->meeting_reference ) . '</span>' : ''; ?></td>
                <td><strong><?php echo esc_html( DecisionService::label( $d->decision_type ) ); ?></strong>
                    <?php echo $d->approved_pence !== null ? ' ' . esc_html( Money::format_gbp( (int) $d->approved_pence ) ) : ''; ?>
                    <?php if ( $d->superseded_by ) : ?><br><span class="description"><?php esc_html_e( '(superseded)', 'rotary-grants' ); ?></span><?php endif; ?>
                    <?php if ( (int) $d->over_budget_pence > 0 ) : ?><br><span class="grants-over"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( '%s over budget', 'rotary-grants' ), Money::format_gbp( (int) $d->over_budget_pence ) ) ); ?></span><?php endif; ?></td>
                <td><?php echo esc_html( $d->reason ); ?><?php echo $d->funding_note ? '<br><em>' . esc_html( __( 'Funding:', 'rotary-grants' ) . ' ' . $d->funding_note ) . '</em>' : ''; ?></td>
                <td><?php echo esc_html( (string) $d->display_name ); ?><br><span class="description"><?php echo esc_html( SiteTime::display( $d->created_at ) ); ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ( $can_decide && $application->status === ApplicationStatus::DECIDED ) : ?>
    <details><summary><?php esc_html_e( 'Reopen this decision', 'rotary-grants' ); ?></summary>
        <?php $form_open( 'reopen' ); ?>
            <input type="hidden" name="row_version" value="<?php echo esc_attr( (string) $application->row_version ); ?>">
            <p><label><?php esc_html_e( 'Reason', 'rotary-grants' ); ?> <input type="text" name="reason" class="large-text" required></label></p>
            <p class="description"><?php esc_html_e( 'The application goes back to review so a revised decision can be recorded. The current decision stays on record and any award stays in place until the new decision is made.', 'rotary-grants' ); ?></p>
            <button type="submit" class="button"><?php esc_html_e( 'Reopen', 'rotary-grants' ); ?></button>
        </form>
    </details>
<?php elseif ( $can_decide && ApplicationStatus::is_open( $application->status ) ) : ?>
    <div class="grants-decision-form">
        <?php if ( $over ) : ?>
            <div class="notice notice-warning inline grants-over-warning"><p>
                <strong><?php esc_html_e( 'Over budget', 'rotary-grants' ); ?></strong> —
                <?php echo esc_html( sprintf(
                    /* translators: 1: amount over, 2: amount available */
                    __( 'approving this amount would take the round %1$s over its budget (%2$s is available). If the committee has agreed to fund the difference from elsewhere, tick the box below and say where the money is coming from.', 'rotary-grants' ),
                    Money::format_gbp( (int) ( $decision_state['data']['over'] ?? 0 ) ),
                    Money::format_gbp( max( 0, (int) ( $decision_state['data']['available'] ?? 0 ) ) )
                ) ); ?>
            </p></div>
        <?php endif; ?>
        <?php $form_open( 'decide' ); ?>
            <fieldset>
                <legend><strong><?php esc_html_e( 'Record the committee\'s decision', 'rotary-grants' ); ?></strong></legend>
                <?php foreach ( [ 'approve' => __( 'Approve', 'rotary-grants' ), 'decline' => __( 'Decline', 'rotary-grants' ), 'defer' => __( 'Defer (back to review)', 'rotary-grants' ) ] as $val => $lab ) : ?>
                    <label class="grants-choice-inline"><input type="radio" name="decision_type" value="<?php echo esc_attr( $val ); ?>" <?php checked( (string) ( $in['type'] ?? '' ), $val ); ?>> <?php echo esc_html( $lab ); ?></label>
                <?php endforeach; ?>
            </fieldset>
            <p>
                <label><?php esc_html_e( 'Amount approved (£)', 'rotary-grants' ); ?>
                    <input type="text" inputmode="decimal" name="amount" class="regular-text" style="width:10em" value="<?php echo esc_attr( (string) ( $in['amount'] ?? Money::to_input( $application->requested_pence ) ) ); ?>"></label>
                <span class="description"><?php echo esc_html( sprintf(
                    /* translators: 1: requested, 2: available */
                    __( 'Requested %1$s · available in this round %2$s · for approvals only (a part award is fine)', 'rotary-grants' ),
                    $fmt( $application->requested_pence ),
                    $budget ? $fmt( max( 0, (int) $budget['available'] ) ) : '—'
                ) ); ?></span>
            </p>
            <p><label><?php esc_html_e( 'Reason (recorded; not sent to the applicant)', 'rotary-grants' ); ?><br><textarea name="reason" rows="2" class="large-text" required><?php echo esc_textarea( (string) ( $in['reason'] ?? '' ) ); ?></textarea></label></p>
            <p>
                <label><?php esc_html_e( 'Date of decision', 'rotary-grants' ); ?> <input type="date" name="decided_on" value="<?php echo esc_attr( (string) ( $in['decided_on'] ?? wp_date( 'Y-m-d' ) ) ); ?>" required></label>
                <label><?php esc_html_e( 'Meeting reference (optional)', 'rotary-grants' ); ?> <input type="text" name="meeting_reference" value="<?php echo esc_attr( (string) ( $in['meeting_reference'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Committee 12 March 2026, minute 4', 'rotary-grants' ); ?>"></label>
            </p>
            <details<?php echo ! empty( $in['conditions'][0]['text'] ) ? ' open' : ''; ?>><summary><?php esc_html_e( 'Conditions (approvals only, optional)', 'rotary-grants' ); ?></summary>
                <?php for ( $i = 0; $i < 3; $i++ ) : $c = $in['conditions'][ $i ] ?? []; ?>
                    <p>
                        <input type="text" name="conditions[<?php echo esc_attr( (string) $i ); ?>][text]" class="large-text" value="<?php echo esc_attr( (string) ( $c['text'] ?? '' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: number */ __( 'Condition %d', 'rotary-grants' ), $i + 1 ) ); ?>">
                        <label><input type="checkbox" name="conditions[<?php echo esc_attr( (string) $i ); ?>][before_payment]" value="1" <?php checked( ! isset( $c['before_payment'] ) || ! empty( $c['before_payment'] ) ); ?>> <?php esc_html_e( 'Must be met before payment', 'rotary-grants' ); ?></label>
                    </p>
                <?php endfor; ?>
            </details>
            <fieldset class="grants-over-budget<?php echo $over ? ' grants-over-budget--active' : ''; ?>">
                <legend><?php esc_html_e( 'Approving beyond the round budget', 'rotary-grants' ); ?></legend>
                <p class="description"><?php esc_html_e( 'Only needed if this approval exceeds what is available. The amount over and your note are recorded with the award and shown in the round totals.', 'rotary-grants' ); ?></p>
                <p><label><input type="checkbox" name="confirm_over_budget" value="1" <?php checked( ! empty( $in['confirm_over_budget'] ) ); ?>> <?php esc_html_e( 'The committee has agreed to approve this beyond the round budget', 'rotary-grants' ); ?></label></p>
                <p><label><?php esc_html_e( 'Where is the extra money coming from?', 'rotary-grants' ); ?><br>
                    <textarea name="funding_note" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'e.g. £1,500 from the club charity account, agreed at the committee meeting of 12 March 2026', 'rotary-grants' ); ?>"><?php echo esc_textarea( (string) ( $in['funding_note'] ?? '' ) ); ?></textarea></label></p>
            </fieldset>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Record decision', 'rotary-grants' ); ?></button>
        </form>
    </div>
<?php elseif ( ! $can_decide && ! $decision_history ) : ?>
    <p class="description"><?php esc_html_e( 'Decisions are recorded by members with the decision-maker role.', 'rotary-grants' ); ?></p>
<?php endif; ?>

<?php if ( $effective && $can_send_notice && ! $notice_sent ) : ?>
    <details class="grants-notice-draft"><summary><?php esc_html_e( 'Prepare the decision notice to the applicant', 'rotary-grants' ); ?></summary>
        <?php $form_open( 'notice_draft' ); ?>
            <p class="description"><?php esc_html_e( 'Suggested wording — edit as needed. It is saved as a draft and only emailed when someone presses "Send to applicant" in the committee record. The internal reason is not included.', 'rotary-grants' ); ?></p>
            <textarea name="body" rows="8" class="large-text"><?php echo esc_textarea( $notice_template ); ?></textarea>
            <p><button type="submit" class="button"><?php esc_html_e( 'Save draft notice', 'rotary-grants' ); ?></button></p>
        </form>
    </details>
<?php endif; ?>
