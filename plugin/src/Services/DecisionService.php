<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Committee decisions and the awards they create (docs/02, docs/03).
 *
 * decide() runs in one InnoDB transaction that first locks the round row
 * (SELECT … FOR UPDATE), so concurrent approvals in one round are
 * serialised and the budget check sees every committed award.
 *
 * Over budget (DEC-014): an approval that would take the round past its
 * budget is refused with code 'over_budget' (data: available, over) UNLESS
 * the decision maker confirms and writes a funding note saying where the
 * extra money comes from. The over-budget amount and note are stored on the
 * decision and award, and shown in round totals.
 *
 * Revisions: a later decision supersedes the earlier one (both kept). The
 * single award per application is revised in place so payments stay
 * attached; it can never be reduced below what has been paid, and decline /
 * defer can cancel it only if nothing has been paid.
 *
 * Requires grants_decide and a "no conflict" declaration.
 */
class DecisionService {

    public const APPROVE = 'approve';
    public const DECLINE = 'decline';
    public const DEFER   = 'defer';

    private const MAX_CONDITIONS = 5;

    /**
     * @param array{type?:string, amount?:string, reason?:string, meeting_reference?:string, decided_on?:string,
     *              conditions?: list<array{text?:string, before_payment?:bool}>, confirm_over_budget?:bool,
     *              funding_note?:string} $input
     * @return array{decision_id:int, award_id:int|null, over_budget_pence:int}|\WP_Error
     */
    public function decide( int $application_id, array $input ): array|\WP_Error {
        if ( ! current_user_can( 'grants_decide' ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to record decisions.', 'rotary-grants' ) );
        }
        $gate = ( new ConflictService() )->require_clear( $application_id );
        if ( $gate ) {
            return $gate;
        }
        $apps = new ApplicationService();
        $app  = $apps->find( $application_id );
        if ( ! $app ) {
            return new \WP_Error( 'not_found', __( 'Application not found.', 'rotary-grants' ) );
        }
        if ( ! ApplicationStatus::is_open( $app->status ) && $app->status !== ApplicationStatus::DECIDED ) {
            return new \WP_Error( 'closed', __( 'A withdrawn application cannot be decided.', 'rotary-grants' ) );
        }
        if ( $app->status === ApplicationStatus::DECIDED ) {
            return new \WP_Error( 'decided', __( 'This application has already been decided. Reopen it (with a reason) to record a revised decision.', 'rotary-grants' ) );
        }

        [ $clean, $errors ] = $this->validate( $app, $input );
        if ( $errors->has_errors() ) {
            return $errors;
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( 'START TRANSACTION' );

        // Serialise every decision in this round on the round row.
        $round = $wpdb->get_row( $wpdb->prepare( "SELECT id, budget_pence FROM {$p}grants_rounds WHERE id = %d FOR UPDATE", $app->round_id ) );
        $award = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}grants_awards WHERE application_id = %d FOR UPDATE", $application_id ) );
        if ( ! $round ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'round', __( 'The application\'s funding round no longer exists.', 'rotary-grants' ) );
        }
        $awards   = new AwardService();
        $net_paid = $award ? $awards->net_paid( (int) $award->id ) : 0;
        $over     = 0;

        if ( $clean['type'] === self::APPROVE ) {
            if ( $round->budget_pence === null ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'no_budget', __( 'The round has no budget set.', 'rotary-grants' ) );
            }
            $exclude   = ( $award && $award->status === AwardService::APPROVED ) ? (int) $award->id : null;
            $available = (int) $round->budget_pence - ( new BudgetService() )->commitments( (int) $round->id, $exclude );
            $over      = max( 0, $clean['approved_pence'] - max( 0, $available ) );
            if ( $over > 0 && ( ! $clean['confirm_over_budget'] || $clean['funding_note'] === '' ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error(
                    'over_budget',
                    sprintf(
                        /* translators: 1: amount over, 2: amount available */
                        __( 'This approval would take the round %1$s over its budget (%2$s is still available). To approve it anyway, tick the confirmation and explain where the extra money is coming from.', 'rotary-grants' ),
                        Money::format_gbp( $over ),
                        Money::format_gbp( max( 0, $available ) )
                    ),
                    [ 'available' => $available, 'over' => $over ]
                );
            }
            if ( $clean['approved_pence'] < $net_paid ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'below_paid', sprintf(
                    /* translators: %s: amount already paid */
                    __( 'The award cannot be less than the %s already paid. Record a reversal first if a payment was wrong.', 'rotary-grants' ),
                    Money::format_gbp( $net_paid )
                ) );
            }
        } elseif ( $award && $award->status === AwardService::APPROVED && $net_paid > 0 ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'paid', sprintf(
                /* translators: %s: amount paid */
                __( '%s has already been paid against this award, so it cannot be declined or deferred. Record reversals first if the payments were wrong.', 'rotary-grants' ),
                Money::format_gbp( $net_paid )
            ) );
        }

        // Record the decision, superseding the previous effective one.
        $now      = SiteTime::now_utc();
        $previous = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}grants_decisions WHERE application_id = %d AND superseded_by IS NULL ORDER BY id DESC LIMIT 1", $application_id ) );
        $wpdb->insert(
            "{$p}grants_decisions",
            [
                'application_id'    => $application_id,
                'decision_type'     => $clean['type'],
                'approved_pence'    => $clean['type'] === self::APPROVE ? $clean['approved_pence'] : null,
                'reason'            => $clean['reason'],
                'meeting_reference' => $clean['meeting_reference'],
                'decided_on'        => $clean['decided_on'],
                'over_budget_pence' => $over,
                'funding_note'      => $over > 0 ? $clean['funding_note'] : null,
                'actor_user_id'     => get_current_user_id(),
                'supersedes_id'     => $previous ? (int) $previous : null,
                'created_at'        => $now,
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s' ]
        );
        $decision_id = (int) $wpdb->insert_id;
        if ( $previous ) {
            $wpdb->update( "{$p}grants_decisions", [ 'superseded_by' => $decision_id ], [ 'id' => (int) $previous ], [ '%d' ], [ '%d' ] );
        }

        // Create, revise or cancel the award.
        $award_id = $award ? (int) $award->id : null;
        if ( $clean['type'] === self::APPROVE ) {
            $data = [
                'approved_pence'        => $clean['approved_pence'],
                'status'                => AwardService::APPROVED,
                'effective_decision_id' => $decision_id,
                'approved_on'           => $clean['decided_on'],
                'over_budget_pence'     => $over,
                'funding_note'          => $over > 0 ? $clean['funding_note'] : null,
                'updated_at'            => $now,
            ];
            if ( $award ) {
                $wpdb->update( "{$p}grants_awards", $data + [ 'row_version' => (int) $award->row_version + 1 ], [ 'id' => $award_id ], [ '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%d' ], [ '%d' ] );
            } else {
                $wpdb->insert(
                    "{$p}grants_awards",
                    $data + [
                        'organisation_id' => (int) $app->organisation_id,
                        'round_id'        => (int) $app->round_id,
                        'application_id'  => $application_id,
                        'source'          => 'decision',
                        'row_version'     => 1,
                        'created_at'      => $now,
                    ],
                    [ '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s' ]
                );
                $award_id = (int) $wpdb->insert_id;
            }
            foreach ( $clean['conditions'] as $c ) {
                $wpdb->insert(
                    "{$p}grants_award_conditions",
                    [ 'award_id' => $award_id, 'condition_text' => $c['text'], 'before_payment' => $c['before_payment'] ? 1 : 0, 'decision_id' => $decision_id, 'created_at' => $now ],
                    [ '%d', '%s', '%d', '%d', '%s' ]
                );
            }
        } elseif ( $award && $award->status === AwardService::APPROVED ) {
            $wpdb->update(
                "{$p}grants_awards",
                [ 'status' => AwardService::CANCELLED, 'effective_decision_id' => $decision_id, 'row_version' => (int) $award->row_version + 1, 'updated_at' => $now ],
                [ 'id' => $award_id ],
                [ '%s', '%d', '%d', '%s' ],
                [ '%d' ]
            );
        }

        // Application status: approve/decline → decided; defer → back to review.
        $to = $clean['type'] === self::DEFER ? ApplicationStatus::UNDER_REVIEW : ApplicationStatus::DECIDED;
        $ok = ( new WorkflowService() )->force_status( $app, $to, sprintf(
            /* translators: 1: decision, 2: reason */
            __( 'Decision: %1$s. %2$s', 'rotary-grants' ),
            self::label( $clean['type'] ),
            $clean['reason']
        ) );
        if ( ! $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return OrganisationService::conflict();
        }

        $wpdb->query( 'COMMIT' );

        AuditLogger::record( 'decision_recorded', 'application', $application_id, array_filter( [
            'decision_id'       => $decision_id,
            'type'              => $clean['type'],
            'approved_pence'    => $clean['type'] === self::APPROVE ? $clean['approved_pence'] : null,
            'over_budget_pence' => $over ?: null,
            'award_id'          => $award_id,
            'supersedes'        => $previous ? (int) $previous : null,
        ], static fn( $v ) => $v !== null ) );

        return [ 'decision_id' => $decision_id, 'award_id' => $award_id, 'over_budget_pence' => $over ];
    }

    /**
     * Reopen a decided application for a revised decision (reason
     * required). The previous decision stays recorded; an existing award is
     * NOT released or cancelled by reopening (docs/03).
     *
     * @return true|\WP_Error
     */
    public function reopen( int $application_id, int $expected_row_version, string $reason ): true|\WP_Error {
        if ( ! current_user_can( 'grants_decide' ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to reopen decisions.', 'rotary-grants' ) );
        }
        $gate = ( new ConflictService() )->require_clear( $application_id );
        if ( $gate ) {
            return $gate;
        }
        $reason = trim( sanitize_textarea_field( $reason ) );
        if ( $reason === '' ) {
            return new \WP_Error( 'reason', __( 'Give a reason for reopening the decision.', 'rotary-grants' ) );
        }
        $app = ( new ApplicationService() )->find( $application_id );
        if ( ! $app || $app->status !== ApplicationStatus::DECIDED ) {
            return new \WP_Error( 'not_decided', __( 'Only a decided application can be reopened.', 'rotary-grants' ) );
        }
        if ( $app->row_version !== $expected_row_version ) {
            return OrganisationService::conflict();
        }
        if ( ! ( new WorkflowService() )->force_status( $app, ApplicationStatus::UNDER_REVIEW, sprintf( /* translators: %s: reason */ __( 'Decision reopened. %s', 'rotary-grants' ), $reason ) ) ) {
            return OrganisationService::conflict();
        }
        AuditLogger::record( 'decision_reopened', 'application', $application_id, [] );
        return true;
    }

    /** @return object[] All decisions for the application, newest first. */
    public function history( int $application_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT d.*, u.display_name FROM {$wpdb->prefix}grants_decisions d LEFT JOIN {$wpdb->users} u ON u.ID = d.actor_user_id
             WHERE d.application_id = %d ORDER BY d.id DESC",
            $application_id
        ) );
    }

    public function effective( int $application_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}grants_decisions WHERE application_id = %d AND superseded_by IS NULL ORDER BY id DESC LIMIT 1",
            $application_id
        ) );
        return $row ?: null;
    }

    /**
     * Suggested wording for the decision notice, for staff to review and edit
     * before sending (nothing is sent automatically).
     */
    public function notice_template( object $application, object $decision ): string {
        $snapshot = json_decode( (string) $application->answer_snapshot_json, true ) ?: [];
        $fund     = (string) ( $snapshot['round']['fund_name'] ?? '' );
        $lines    = [];
        if ( $decision->decision_type === self::APPROVE ) {
            /* translators: 1: fund name, 2: amount */
            $lines[] = sprintf( __( 'We are pleased to tell you that the committee has agreed to award %2$s from the %1$s fund.', 'rotary-grants' ), $fund, Money::format_gbp( (int) $decision->approved_pence ) );
            $award   = ( new AwardService() )->for_application( (int) $application->id );
            $conds   = $award ? ( new AwardService() )->conditions( $award->id ) : [];
            if ( $conds ) {
                $lines[] = '';
                $lines[] = __( 'The award is subject to the following:', 'rotary-grants' );
                foreach ( $conds as $c ) {
                    $lines[] = '- ' . $c->condition_text;
                }
            }
            $lines[] = '';
            $lines[] = __( 'Our treasurer will contact you to confirm your organisation\'s bank details through our usual secure process. Please do not send bank details by email.', 'rotary-grants' );
        } elseif ( $decision->decision_type === self::DECLINE ) {
            /* translators: %s: fund name */
            $lines[] = sprintf( __( 'Thank you for applying to the %s fund. We are sorry to tell you that on this occasion the committee was not able to support your application.', 'rotary-grants' ), $fund );
        } else {
            /* translators: %s: fund name */
            $lines[] = sprintf( __( 'Thank you for applying to the %s fund. The committee has not yet reached a decision on your application and will consider it further.', 'rotary-grants' ), $fund );
        }
        return implode( "\n", $lines );
    }

    public static function label( string $type ): string {
        return match ( $type ) {
            self::APPROVE => __( 'Approved', 'rotary-grants' ),
            self::DECLINE => __( 'Declined', 'rotary-grants' ),
            self::DEFER   => __( 'Deferred', 'rotary-grants' ),
            default       => $type,
        };
    }

    // =========================================================================

    /**
     * @return array{0: array<string,mixed>, 1: \WP_Error}
     */
    private function validate( object $app, array $input ): array {
        $errors = new \WP_Error();
        $clean  = [
            'type'                => (string) ( $input['type'] ?? '' ),
            'approved_pence'      => 0,
            'reason'              => trim( sanitize_textarea_field( (string) ( $input['reason'] ?? '' ) ) ),
            'meeting_reference'   => trim( sanitize_text_field( (string) ( $input['meeting_reference'] ?? '' ) ) ),
            'decided_on'          => trim( (string) ( $input['decided_on'] ?? '' ) ),
            'conditions'          => [],
            'confirm_over_budget' => ! empty( $input['confirm_over_budget'] ),
            'funding_note'        => trim( sanitize_textarea_field( (string) ( $input['funding_note'] ?? '' ) ) ),
        ];

        if ( ! in_array( $clean['type'], [ self::APPROVE, self::DECLINE, self::DEFER ], true ) ) {
            $errors->add( 'decision_type', __( 'Choose approve, decline or defer.', 'rotary-grants' ) );
        }
        if ( $clean['type'] === self::APPROVE ) {
            if ( ! $app->organisation_id ) {
                $errors->add( 'organisation', __( 'Link the application to an organisation before approving it — the award is made to the organisation.', 'rotary-grants' ) );
            }
            $pence = Money::parse_gbp( (string) ( $input['amount'] ?? '' ) );
            if ( is_wp_error( $pence ) ) {
                $errors->add( 'amount', $pence->get_error_message() );
            } elseif ( $pence <= 0 ) {
                $errors->add( 'amount', __( 'Enter an approved amount greater than £0.', 'rotary-grants' ) );
            } else {
                $clean['approved_pence'] = $pence;
            }
            $rows = array_slice( (array) ( $input['conditions'] ?? [] ), 0, self::MAX_CONDITIONS );
            foreach ( $rows as $row ) {
                $text = trim( sanitize_textarea_field( (string) ( $row['text'] ?? '' ) ) );
                if ( $text === '' ) {
                    continue;
                }
                if ( mb_strlen( $text ) > 1000 ) {
                    $errors->add( 'conditions', __( 'Please keep each condition to 1000 characters or fewer.', 'rotary-grants' ) );
                }
                $clean['conditions'][] = [ 'text' => $text, 'before_payment' => ! empty( $row['before_payment'] ) ];
            }
        }
        if ( $clean['reason'] === '' ) {
            $errors->add( 'reason', __( 'Record the reason for the decision.', 'rotary-grants' ) );
        } elseif ( mb_strlen( $clean['reason'] ) > 2000 ) {
            $errors->add( 'reason', __( 'Please keep the reason to 2000 characters or fewer.', 'rotary-grants' ) );
        }
        if ( mb_strlen( $clean['meeting_reference'] ) > 150 ) {
            $errors->add( 'meeting_reference', __( 'Please keep the meeting reference to 150 characters or fewer.', 'rotary-grants' ) );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $clean['decided_on'] ) || ! checkdate( (int) substr( $clean['decided_on'], 5, 2 ), (int) substr( $clean['decided_on'], 8, 2 ), (int) substr( $clean['decided_on'], 0, 4 ) ) ) {
            $errors->add( 'decided_on', __( 'Enter the date of the decision.', 'rotary-grants' ) );
        } elseif ( $clean['decided_on'] > wp_date( 'Y-m-d' ) ) {
            $errors->add( 'decided_on', __( 'The decision date cannot be in the future.', 'rotary-grants' ) );
        }
        if ( mb_strlen( $clean['funding_note'] ) > 2000 ) {
            $errors->add( 'funding_note', __( 'Please keep the funding note to 2000 characters or fewer.', 'rotary-grants' ) );
        }
        return [ $clean, $errors ];
    }
}
