<?php

namespace Rotary\Grants\Admin;

use Rotary\Grants\Services\ConflictService;
use Rotary\Grants\Services\ExportService;
use Rotary\Grants\Services\ReportService;
use Rotary\Grants\Services\RoundService;
use Rotary\Grants\Services\SettingsService;
use Rotary\Grants\Support\ReportingYear;

defined( 'ABSPATH' ) || exit;

/**
 * Reports: round overview, committee list, round financial report, payments
 * by reporting year, and the future-round contact list — on screen, with a
 * CSV download for each where the user holds that export capability.
 *
 * Routing:
 *   /wp-admin/admin.php?page=grants-reports&tab=overview|committee|financial|payments|contacts[&round_id=N][&year=Y]
 *   POST admin-post.php action=grants_export (type, round_id|year)
 */
class ReportsPage {

    private const NONCE_ACTION = 'grants_export';
    private const NONCE_FIELD  = 'grants_export_nonce';

    public function register(): void {
        add_action( 'admin_post_grants_export', [ $this, 'handle_export' ] );
    }

    /** Who may see each tab on screen. */
    public static function can_view( string $tab ): bool {
        return match ( $tab ) {
            'overview'  => current_user_can( 'grants_access' ),
            'committee' => ConflictService::is_committee() || current_user_can( 'grants_export_committee' ),
            'financial', 'payments' => current_user_can( 'grants_export_financial' ) || current_user_can( 'grants_pay' ) || current_user_can( 'grants_decide' ),
            'contacts'  => current_user_can( 'grants_export_contacts' ),
            default     => false,
        };
    }

    public function render(): void {
        if ( ! current_user_can( 'grants_access' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        $tabs = array_filter( [
            'overview'  => __( 'Round overview', 'rotary-grants' ),
            'committee' => __( 'Committee list', 'rotary-grants' ),
            'financial' => __( 'Awards & payments by round', 'rotary-grants' ),
            'payments'  => __( 'Payments by date', 'rotary-grants' ),
            'contacts'  => __( 'Future-round contacts', 'rotary-grants' ),
        ], static fn( $label, $tab ) => self::can_view( $tab ), ARRAY_FILTER_USE_BOTH );
        $tab = sanitize_key( $_GET['tab'] ?? 'overview' );
        if ( ! isset( $tabs[ $tab ] ) ) {
            $tab = 'overview';
        }

        $reports  = new ReportService();
        $rounds   = ( new RoundService() )->all();
        $round_id = absint( $_GET['round_id'] ?? 0 ) ?: (int) ( $rounds[0]->id ?? 0 );
        $round    = $round_id ? ( new RoundService() )->find( $round_id ) : null;
        $start    = ( new SettingsService() )->reporting_year_start_month();
        $years    = $reports->payment_years();
        $year     = absint( $_GET['year'] ?? 0 ) ?: ReportingYear::current( $start );
        $data     = match ( $tab ) {
            'committee' => $round ? $reports->shortlist( $round->id ) : [],
            'financial' => $round ? $reports->financial( $round->id ) : null,
            'payments'  => $reports->payments_in_year( $year ),
            'contacts'  => $reports->future_round_contacts(),
            default     => $round ? $reports->round_overview( $round ) : null,
        };
        $export_type  = [ 'committee' => 'shortlist', 'financial' => 'financial', 'payments' => 'payments', 'contacts' => 'contacts' ][ $tab ] ?? '';
        $can_export   = $export_type !== '' && current_user_can( ExportService::CAPABILITIES[ $export_type ] );
        $error        = sanitize_key( $_GET['grants_notice'] ?? '' ) === 'export_error';
        $base_url     = add_query_arg( 'page', 'grants-reports', admin_url( 'admin.php' ) );
        $year_label   = static fn( int $y ): string => ReportingYear::label( $y, $start );
        $nonce_action = self::NONCE_ACTION;
        $nonce_field  = self::NONCE_FIELD;

        include GRANTS_PLUGIN_DIR . 'templates/admin/reports.php';
    }

    public function handle_export(): void {
        if ( ! current_user_can( 'grants_access' ) ) {
            wp_die( esc_html__( 'Access denied.', 'rotary-grants' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
        $type   = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
        $result = ( new ExportService() )->build( $type, [
            'round_id' => absint( $_POST['round_id'] ?? 0 ),
            'year'     => absint( $_POST['year'] ?? 0 ),
        ] );
        if ( is_wp_error( $result ) ) {
            if ( $result->get_error_code() === 'forbidden' ) {
                wp_die( esc_html( $result->get_error_message() ), 403 );
            }
            wp_safe_redirect( add_query_arg( [ 'page' => 'grants-reports', 'grants_notice' => 'export_error' ], admin_url( 'admin.php' ) ) );
            exit;
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $result['filename'] ) . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        echo $result['csv']; // phpcs:ignore WordPress.Security.EscapeOutput -- CSV body, cells made formula-safe by Support\Csv
        exit;
    }
}
