<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\AwardService;
use Rotary\Grants\Services\BudgetService;
use Rotary\Grants\Services\PaymentService;
use Rotary\Grants\Services\RoundService;

defined( 'ABSPATH' ) || exit;

/**
 * Treasurer screens: approved awards with payment progress for a round, and
 * one award's ledger with "record payment" and "reverse" forms.
 *
 * Viewing needs grants_access; recording needs grants_pay (checked again in
 * PaymentService). Payments are made outside WordPress first — these
 * screens only record them. No bank details are ever asked for.
 *
 * Routing:
 *   /wp-admin/admin.php?page=grants-payments[&round_id=N]
 *   /wp-admin/admin.php?page=grants-payments&action=award&id=N
 *   POST admin-post.php action=grants_payment_record | grants_payment_reverse
 */
class PaymentsPage {

    private const NONCE_ACTION = 'grants_payment';
    private const NONCE_FIELD  = 'grants_payment_nonce';

    public function register(): void {
        add_action( 'admin_post_grants_payment_record', [ $this, 'handle_record' ] );
        add_action( 'admin_post_grants_payment_reverse', [ $this, 'handle_reverse' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'grants_access' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        if ( sanitize_key( $_GET['action'] ?? '' ) === 'award' ) {
            $this->render_award();
            return;
        }
        $rounds   = ( new RoundService() )->all();
        $round_id = absint( $_GET['round_id'] ?? 0 ) ?: (int) ( $rounds[0]->id ?? 0 );
        $round    = $round_id ? ( new RoundService() )->find( $round_id ) : null;
        $payments = new PaymentService();
        $awards   = [];
        foreach ( $round ? ( new AwardService() )->list( $round->id, AwardService::APPROVED ) : [] as $aw ) {
            $aw->totals = $payments->totals( $aw );
            $awards[]   = $aw;
        }
        $budget   = $round ? ( new BudgetService() )->summary( $round ) : null;
        $base_url = add_query_arg( 'page', 'grants-payments', admin_url( 'admin.php' ) );
        include GRANTS_PLUGIN_DIR . 'templates/admin/payments.php';
    }

    private function render_award(): void {
        $awards = new AwardService();
        $award  = $awards->find( absint( $_GET['id'] ?? 0 ) );
        if ( ! $award ) {
            wp_die( esc_html__( 'Award not found.', 'rotary-grants' ), 404 );
        }
        $payments     = new PaymentService();
        $totals       = $payments->totals( $award );
        $ledger       = $payments->ledger( $award->id );
        $conditions   = $awards->conditions( $award->id );
        $open_conds   = $awards->open_pre_payment_conditions( $award->id );
        $application  = $award->application_id ? ( new \Rotary\Grants\Services\ApplicationService() )->find( $award->application_id ) : null;
        $organisation = ( new \Rotary\Grants\Services\OrganisationService() )->find( $award->organisation_id );
        $round        = ( new RoundService() )->find( $award->round_id );
        $can_pay      = current_user_can( 'grants_pay' );
        $methods      = PaymentService::methods();
        $notice       = sanitize_key( $_GET['grants_notice'] ?? '' );
        [ $error, $values ] = $this->take_state( $award->id );
        $key          = (string) ( $values['command_key'] ?? '' ) ?: bin2hex( random_bytes( 32 ) );
        $nonce_action = self::NONCE_ACTION;
        $nonce_field  = self::NONCE_FIELD;
        $list_url     = add_query_arg( [ 'page' => 'grants-payments', 'round_id' => $award->round_id ], admin_url( 'admin.php' ) );
        $apps_url     = add_query_arg( 'page', 'grants-applications', admin_url( 'admin.php' ) );
        include GRANTS_PLUGIN_DIR . 'templates/admin/award.php';
    }

    public function handle_record(): void {
        $this->guard();
        $award_id = absint( $_POST['award_id'] ?? 0 );
        $input    = [
            'amount'      => $this->text( 'amount' ),
            'paid_on'     => $this->text( 'paid_on' ),
            'method'      => sanitize_key( wp_unslash( $_POST['method'] ?? '' ) ),
            'method_note' => $this->text( 'method_note' ),
            'reference'   => $this->text( 'reference' ),
            'note'        => $this->text( 'note' ),
        ];
        $key    = $this->text( 'command_key' );
        $result = ( new PaymentService() )->record_payment( $award_id, $input, $key );
        $this->finish( $award_id, $result, $input + [ 'command_key' => $key ], is_array( $result ) && $result['replayed'] ? 'already_recorded' : 'paid' );
    }

    public function handle_reverse(): void {
        $this->guard();
        $award_id = absint( $_POST['award_id'] ?? 0 );
        $payment  = ( new PaymentService() )->find( absint( $_POST['payment_id'] ?? 0 ) );
        if ( ! $payment || (int) $payment->award_id !== $award_id ) {
            $this->finish( $award_id, new \WP_Error( 'mismatch', __( 'That payment does not belong to this award.', 'rotary-grants' ) ), [], '' );
        }
        $result = ( new PaymentService() )->record_reversal( (int) $payment->id, [
            'amount'          => $this->text( 'amount' ),
            'paid_on'         => $this->text( 'paid_on' ),
            'reason'          => $this->text( 'reason' ),
            'refund_received' => ( $_POST['refund_received'] ?? '' ) === '1',
        ], $this->text( 'command_key' ) );
        $this->finish( $award_id, $result, [], is_array( $result ) && $result['replayed'] ? 'already_recorded' : 'reversed' );
    }

    // -------------------------------------------------------------------------

    private function guard(): void {
        if ( ! current_user_can( 'grants_pay' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
    }

    private function text( string $key ): string {
        $v = wp_unslash( $_POST[ $key ] ?? '' );
        return is_string( $v ) ? sanitize_textarea_field( $v ) : '';
    }

    /** @param array<string,string> $values */
    private function finish( int $award_id, mixed $result, array $values, string $notice ): never {
        $args = [ 'page' => 'grants-payments', 'action' => 'award', 'id' => $award_id ];
        if ( is_wp_error( $result ) ) {
            set_transient( self::state_key( $award_id ), [ 'error' => implode( ' ', $result->get_error_messages() ), 'values' => $values ], 600 );
            $args['grants_notice'] = 'error';
        } else {
            $args['grants_notice'] = $notice;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /** @return array{0: string, 1: array<string,string>} */
    private function take_state( int $award_id ): array {
        $state = get_transient( self::state_key( $award_id ) );
        delete_transient( self::state_key( $award_id ) );
        return is_array( $state ) ? [ (string) $state['error'], (array) $state['values'] ] : [ '', [] ];
    }

    private static function state_key( int $award_id ): string {
        return 'grants_payment_form_' . get_current_user_id() . '_' . $award_id;
    }
}
