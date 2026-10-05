<?php
/**
 * Admin partial — committee section of the application view (DEC-013).
 *
 * Available variables (from Admin\ApplicationListPage::render_view()):
 *   $application    object      Hydrated application row.
 *   $is_committee   bool        User holds grants_review / grants_decide / grants_manage_organisations.
 *   $declaration    object|null User's current conflict declaration row.
 *   $decl_status    string      'undeclared' | 'none' | 'financial' | 'loyalty'.
 *   $is_clear       bool        Declared no conflict — may see and take part.
 *   $reviews        object[]    Current reviews (->findings decoded, ->display_name, ->versions); [] unless clear.
 *   $my_review      object|null User's current review; null unless clear.
 *   $notes          object[]    Notes/requests/addenda/status rows, oldest first; [] unless clear.
 *   $can_review     bool        May add/update a review (capability + application still open).
 *   $can_progress   bool        May request info / record addenda / move status.
 *   $can_withdraw   bool        May withdraw / classify duplicates (grants_manage_organisations).
 *   $transitions    string[]    Manual status targets from the current status.
 *   $checks         array<string,string>  Eligibility check => label.
 *   $c_nonce_action string      Nonce action for committee forms.
 *   $c_nonce_field  string      Nonce field name.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\ApplicationStatus;
use Rotary\Grants\Services\ConflictService;
use Rotary\Grants\Services\ReviewService;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

$form_open = static function ( string $action, string $class = '' ) use ( $application, $c_nonce_action, $c_nonce_field ): void {
    printf( '<form method="post" action="%s" class="%s">', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( $class ) );
    printf( '<input type="hidden" name="action" value="%s">', esc_attr( 'grants_' . $action ) );
    printf( '<input type="hidden" name="application_id" value="%s">', esc_attr( (string) $application->id ) );
    wp_nonce_field( $c_nonce_action, $c_nonce_field );
};
$kind_labels = [
    'note'         => __( 'Internal note', 'rotary-grants' ),
    'info_request' => __( 'Request for more information', 'rotary-grants' ),
    'addendum'     => __( 'Information received from applicant', 'rotary-grants' ),
    'status'       => __( 'Status', 'rotary-grants' ),
];
?>
<h2 id="grants-committee"><?php esc_html_e( 'Committee', 'rotary-grants' ); ?></h2>

<?php if ( ! $is_committee ) : ?>
    <p class="description"><?php esc_html_e( 'Committee reviews and internal notes are visible to committee members only.', 'rotary-grants' ); ?></p>
    <?php return; ?>
<?php endif; ?>

<?php if ( $decl_status === ConflictService::UNDECLARED || $decl_status === ConflictService::NONE ) : ?>
    <div class="grants-declare-box<?php echo $is_clear ? ' grants-declare-box--clear' : ''; ?>">
        <?php if ( $is_clear ) : ?>
            <p>
                <strong><?php esc_html_e( 'You declared no conflict of interest', 'rotary-grants' ); ?></strong>
                (<?php echo esc_html( SiteTime::display( $declaration->declared_at ) ); ?>).
                <?php esc_html_e( 'If that changes, declare it now:', 'rotary-grants' ); ?>
            </p>
        <?php else : ?>
            <p><strong><?php esc_html_e( 'Before you can see the committee\'s reviews and notes or take part, declare whether you have a conflict of interest with this application.', 'rotary-grants' ); ?></strong></p>
            <p class="description"><?php esc_html_e( 'A conflict exists if a decision could benefit you, your family or your business, or an organisation where you hold a paid or voluntary leadership or advisory role (e.g. trustee, committee member). If unsure, declare it.', 'rotary-grants' ); ?></p>
        <?php endif; ?>
        <?php $form_open( 'declare' ); ?>
            <fieldset>
                <legend class="screen-reader-text"><?php esc_html_e( 'Conflict of interest', 'rotary-grants' ); ?></legend>
                <?php if ( ! $is_clear ) : ?>
                    <label class="grants-choice-inline"><input type="radio" name="declaration" value="none"> <?php esc_html_e( 'I have no conflict of interest', 'rotary-grants' ); ?></label>
                <?php endif; ?>
                <label class="grants-choice-inline"><input type="radio" name="declaration" value="financial"> <?php esc_html_e( 'Financial conflict', 'rotary-grants' ); ?></label>
                <label class="grants-choice-inline"><input type="radio" name="declaration" value="loyalty"> <?php esc_html_e( 'Loyalty conflict (e.g. I\'m involved with this organisation)', 'rotary-grants' ); ?></label>
            </fieldset>
            <p><label><?php esc_html_e( 'Description (required for a conflict)', 'rotary-grants' ); ?><br><input type="text" name="description" class="large-text"></label></p>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Record declaration', 'rotary-grants' ); ?></button>
        </form>
        <?php if ( ! $is_clear ) : ?>
            <p class="description"><?php esc_html_e( 'A declared conflict cannot be withdrawn later, and means you will not see or take part in the committee\'s discussion of this application.', 'rotary-grants' ); ?></p>
        <?php endif; ?>
    </div>
<?php else : ?>
    <div class="notice notice-warning inline"><p>
        <strong><?php echo esc_html( ConflictService::label( $decl_status ) ); ?></strong>
        <?php echo esc_html( sprintf( /* translators: 1: date, 2: description */ __( 'declared on %1$s: %2$s', 'rotary-grants' ), SiteTime::display( $declaration->declared_at ), (string) $declaration->description ) ); ?>
        <br><?php esc_html_e( 'You cannot see the committee\'s reviews or notes on this application, or take part in reviewing or deciding it.', 'rotary-grants' ); ?>
    </p></div>
<?php endif; ?>

<?php if ( ! $is_clear ) { return; } ?>

<?php if ( $can_progress || $can_withdraw ) : ?>
    <h3><?php esc_html_e( 'Status', 'rotary-grants' ); ?></h3>
    <p><?php esc_html_e( 'Currently:', 'rotary-grants' ); ?> <strong><?php echo esc_html( ApplicationStatus::label( $application->status ) ); ?></strong></p>
    <?php foreach ( $transitions as $to ) :
        if ( $to === ApplicationStatus::WITHDRAWN ? ! $can_withdraw : ! $can_progress ) { continue; }
        if ( $to === ApplicationStatus::MORE_INFO_REQUESTED ) { continue; } // set by sending a request
        ?>
        <?php $form_open( 'status_change', 'grants-inline-form' ); ?>
            <input type="hidden" name="to" value="<?php echo esc_attr( $to ); ?>">
            <input type="hidden" name="row_version" value="<?php echo esc_attr( (string) $application->row_version ); ?>">
            <input type="text" name="reason" placeholder="<?php echo esc_attr( $to === ApplicationStatus::WITHDRAWN ? __( 'Reason (required)', 'rotary-grants' ) : __( 'Reason (optional)', 'rotary-grants' ) ); ?>"<?php echo $to === ApplicationStatus::WITHDRAWN ? ' required' : ''; ?>>
            <button type="submit" class="button"><?php echo esc_html( $to === ApplicationStatus::WITHDRAWN ? __( 'Withdraw application', 'rotary-grants' ) : sprintf( /* translators: %s: status */ __( 'Move to "%s"', 'rotary-grants' ), ApplicationStatus::label( $to ) ) ); ?></button>
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<h3><?php esc_html_e( 'Reviews', 'rotary-grants' ); ?></h3>
<p class="description"><?php esc_html_e( 'Reviews record what was checked and how sure the reviewer is. They are recommendations only — the decision is taken separately.', 'rotary-grants' ); ?></p>
<?php if ( ! $reviews ) : ?>
    <p><?php esc_html_e( 'No reviews yet.', 'rotary-grants' ); ?></p>
<?php else : ?>
    <table class="widefat striped grants-reviews">
        <thead><tr>
            <th scope="col"><?php esc_html_e( 'Reviewer', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Recommendation', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Eligibility findings', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Notes', 'rotary-grants' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $reviews as $r ) : ?>
            <tr>
                <td><?php echo esc_html( (string) $r->display_name ); ?><br><span class="description"><?php echo esc_html( SiteTime::display( $r->reviewed_at ) ); ?><?php echo (int) $r->versions > 1 ? esc_html( ' · ' . sprintf( /* translators: %d: version count */ __( 'version %d', 'rotary-grants' ), (int) $r->versions ) ) : ''; ?></span></td>
                <td><strong><?php echo esc_html( ReviewService::recommendation_label( $r->recommendation ) ); ?></strong><?php echo $r->recommended_pence !== null ? '<br>' . esc_html( Money::format_gbp( $r->recommended_pence ) ) : ''; ?></td>
                <td><ul class="grants-findings">
                    <?php foreach ( $checks as $key => $label ) : $f = $r->findings[ $key ] ?? [ 'finding' => 'not_checked', 'note' => '' ]; if ( $f['finding'] === 'not_checked' && $f['note'] === '' ) { continue; } ?>
                        <li class="grants-finding--<?php echo esc_attr( $f['finding'] ); ?>"><strong><?php echo esc_html( ReviewService::finding_label( $f['finding'] ) ); ?>:</strong> <?php echo esc_html( $label ); ?><?php echo $f['note'] !== '' ? ' — ' . esc_html( $f['note'] ) : ''; ?></li>
                    <?php endforeach; ?>
                </ul></td>
                <td><?php echo nl2br( esc_html( (string) $r->notes ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ( $can_review ) : ?>
    <details class="grants-review-form"<?php echo $my_review ? '' : ' open'; ?>>
        <summary><strong><?php echo $my_review ? esc_html__( 'Update my review', 'rotary-grants' ) : esc_html__( 'Add my review', 'rotary-grants' ); ?></strong></summary>
        <?php $form_open( 'review_save' ); ?>
            <table class="widefat grants-findings-form">
                <thead><tr><th scope="col"><?php esc_html_e( 'Check', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Finding', 'rotary-grants' ); ?></th><th scope="col"><?php esc_html_e( 'Note (what you checked, any doubt)', 'rotary-grants' ); ?></th></tr></thead>
                <tbody>
                <?php foreach ( $checks as $key => $label ) : $cur = $my_review->findings[ $key ] ?? [ 'finding' => 'not_checked', 'note' => '' ]; ?>
                    <tr>
                        <td><?php echo esc_html( $label ); ?></td>
                        <td><select name="findings[<?php echo esc_attr( $key ); ?>]" aria-label="<?php echo esc_attr( $label ); ?>">
                            <?php foreach ( ReviewService::FINDINGS as $f ) : ?>
                                <option value="<?php echo esc_attr( $f ); ?>" <?php selected( $cur['finding'], $f ); ?>><?php echo esc_html( ReviewService::finding_label( $f ) ); ?></option>
                            <?php endforeach; ?>
                        </select></td>
                        <td><input type="text" class="large-text" name="finding_notes[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $cur['note'] ); ?>" aria-label="<?php echo esc_attr( $label . ' — ' . __( 'note', 'rotary-grants' ) ); ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <label for="grants-recommendation"><strong><?php esc_html_e( 'Recommendation', 'rotary-grants' ); ?></strong></label><br>
                <select id="grants-recommendation" name="recommendation">
                    <?php foreach ( ReviewService::RECOMMENDATIONS as $rec ) : ?>
                        <option value="<?php echo esc_attr( $rec ); ?>" <?php selected( $my_review->recommendation ?? 'none', $rec ); ?>><?php echo esc_html( ReviewService::recommendation_label( $rec ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <label><?php esc_html_e( 'Part amount (£)', 'rotary-grants' ); ?> <input type="text" inputmode="decimal" name="recommended_amount" class="small-text" value="<?php echo esc_attr( ( $my_review && $my_review->recommendation === 'fund_partial' ) ? Money::to_input( $my_review->recommended_pence ) : '' ); ?>"></label>
                <span class="description"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'Requested: %s', 'rotary-grants' ), $application->requested_pence === null ? '—' : Money::format_gbp( $application->requested_pence ) ) ); ?></span>
            </p>
            <p><label for="grants-review-notes"><strong><?php esc_html_e( 'Private notes for the committee', 'rotary-grants' ); ?></strong></label><br>
                <textarea id="grants-review-notes" name="notes" rows="4" class="large-text"><?php echo esc_textarea( (string) ( $my_review->notes ?? '' ) ); ?></textarea></p>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save review', 'rotary-grants' ); ?></button>
        </form>
    </details>
<?php endif; ?>

<h3><?php esc_html_e( 'Committee record', 'rotary-grants' ); ?></h3>
<?php if ( ! $notes ) : ?>
    <p><?php esc_html_e( 'Nothing recorded yet.', 'rotary-grants' ); ?></p>
<?php else : ?>
    <ol class="grants-timeline">
        <?php foreach ( $notes as $n ) : ?>
            <li class="grants-timeline__item grants-timeline__item--<?php echo esc_attr( $n->kind ); ?>">
                <div class="grants-timeline__meta">
                    <strong><?php echo esc_html( $kind_labels[ $n->kind ] ?? $n->kind ); ?></strong>
                    · <?php echo esc_html( (string) $n->author_name ); ?> · <?php echo esc_html( SiteTime::display( $n->created_at ) ); ?>
                    <?php if ( $n->kind === 'addendum' && $n->received_at ) : ?>
                        · <?php echo esc_html( sprintf( /* translators: %s: date */ __( 'received %s', 'rotary-grants' ), wp_date( get_option( 'date_format' ), strtotime( $n->received_at . ' UTC' ) ) ) ); ?>
                    <?php endif; ?>
                    <?php if ( $n->kind === 'info_request' ) : ?>
                        · <?php echo $n->sent_at
                            ? esc_html( sprintf( /* translators: 1: date, 2: name */ __( 'sent %1$s by %2$s', 'rotary-grants' ), SiteTime::display( $n->sent_at ), (string) $n->sender_name ) )
                            : '<span class="grants-badge grants-badge--late">' . esc_html__( 'Draft — not sent', 'rotary-grants' ) . '</span>'; ?>
                    <?php endif; ?>
                </div>
                <div class="grants-timeline__body"><?php echo nl2br( esc_html( (string) $n->body ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                <?php if ( $n->kind === 'info_request' && ! $n->sent_at && $can_progress ) : ?>
                    <?php $form_open( 'info_send', 'grants-inline-form' ); ?>
                        <input type="hidden" name="note_id" value="<?php echo esc_attr( (string) $n->id ); ?>">
                        <button type="submit" class="button button-primary button-small" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Email this request to the applicant now?', 'rotary-grants' ) ) ); ?>);"><?php esc_html_e( 'Send to applicant', 'rotary-grants' ); ?></button>
                    </form>
                    <?php $form_open( 'info_discard', 'grants-inline-form' ); ?>
                        <input type="hidden" name="note_id" value="<?php echo esc_attr( (string) $n->id ); ?>">
                        <button type="submit" class="button button-small"><?php esc_html_e( 'Discard draft', 'rotary-grants' ); ?></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<div class="grants-committee-forms">
    <details><summary><?php esc_html_e( 'Add an internal note', 'rotary-grants' ); ?></summary>
        <?php $form_open( 'note_add' ); ?>
            <p><label class="screen-reader-text" for="grants-note-body"><?php esc_html_e( 'Note', 'rotary-grants' ); ?></label>
            <textarea id="grants-note-body" name="body" rows="3" class="large-text" required></textarea></p>
            <p class="description"><?php esc_html_e( 'Visible only to committee members who have declared no conflict. Never shown to the applicant.', 'rotary-grants' ); ?></p>
            <button type="submit" class="button"><?php esc_html_e( 'Add note', 'rotary-grants' ); ?></button>
        </form>
    </details>

    <?php if ( $can_progress && ApplicationStatus::is_open( $application->status ) ) : ?>
        <details><summary><?php esc_html_e( 'Ask the applicant for more information', 'rotary-grants' ); ?></summary>
            <?php $form_open( 'info_draft' ); ?>
                <p><label class="screen-reader-text" for="grants-info-body"><?php esc_html_e( 'Request', 'rotary-grants' ); ?></label>
                <textarea id="grants-info-body" name="body" rows="4" class="large-text" required></textarea></p>
                <p class="description"><?php esc_html_e( 'This is saved as a draft. It is only emailed when someone presses "Send to applicant".', 'rotary-grants' ); ?></p>
                <button type="submit" class="button"><?php esc_html_e( 'Save draft request', 'rotary-grants' ); ?></button>
            </form>
        </details>
        <details><summary><?php esc_html_e( 'Record information received from the applicant', 'rotary-grants' ); ?></summary>
            <?php $form_open( 'addendum_add' ); ?>
                <p><label><?php esc_html_e( 'Date received', 'rotary-grants' ); ?> <input type="date" name="received" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required></label></p>
                <p><label class="screen-reader-text" for="grants-addendum-body"><?php esc_html_e( 'Information received', 'rotary-grants' ); ?></label>
                <textarea id="grants-addendum-body" name="body" rows="4" class="large-text" required placeholder="<?php esc_attr_e( 'Paste or summarise what they sent. The original application is not changed.', 'rotary-grants' ); ?>"></textarea></p>
                <button type="submit" class="button"><?php esc_html_e( 'Record', 'rotary-grants' ); ?></button>
            </form>
        </details>
    <?php endif; ?>

    <?php if ( $can_withdraw ) : ?>
        <details><summary><?php echo $application->duplicate_of_id ? esc_html__( 'Not a duplicate after all', 'rotary-grants' ) : esc_html__( 'Mark as a duplicate', 'rotary-grants' ); ?></summary>
            <?php if ( $application->duplicate_of_id ) : ?>
                <?php $form_open( 'duplicate_unmark' ); ?>
                    <p><label><?php esc_html_e( 'Reason', 'rotary-grants' ); ?> <input type="text" name="reason" class="regular-text" required></label></p>
                    <button type="submit" class="button"><?php esc_html_e( 'Remove duplicate mark', 'rotary-grants' ); ?></button>
                </form>
            <?php else : ?>
                <?php $form_open( 'duplicate_mark' ); ?>
                    <p><label><?php esc_html_e( 'Reference of the application to keep', 'rotary-grants' ); ?> <input type="text" name="kept_reference" class="regular-text" placeholder="RG-XXXX-XXXX" required></label></p>
                    <p><label><?php esc_html_e( 'Reason', 'rotary-grants' ); ?> <input type="text" name="reason" class="regular-text" required></label></p>
                    <p class="description"><?php esc_html_e( 'Nothing is deleted. The application is hidden from the committee list by default and points to the one kept.', 'rotary-grants' ); ?></p>
                    <button type="submit" class="button"><?php esc_html_e( 'Mark as duplicate', 'rotary-grants' ); ?></button>
                </form>
            <?php endif; ?>
        </details>
    <?php endif; ?>
</div>
