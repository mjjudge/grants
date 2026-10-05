<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\Csv;
use Rotary\Grants\Support\ReportingYear;

defined( 'ABSPATH' ) || exit;

/**
 * CSV exports. Each needs its own capability (docs/03), is audited (type,
 * scope, row count — never contents) and is streamed straight to the
 * browser with no-cache headers: nothing is written to the Media Library or
 * any public folder (docs/04).
 */
class ExportService {

    /** @var array<string,string> export type => capability */
    public const CAPABILITIES = [
        'shortlist' => 'grants_export_committee',
        'financial' => 'grants_export_financial',
        'payments'  => 'grants_export_financial',
        'contacts'  => 'grants_export_contacts',
    ];

    /**
     * @param array{round_id?:int, year?:int} $params
     * @return array{filename:string, csv:string, rows:int}|\WP_Error
     */
    public function build( string $type, array $params ): array|\WP_Error {
        $cap = self::CAPABILITIES[ $type ] ?? null;
        if ( ! $cap ) {
            return new \WP_Error( 'unknown', __( 'Unknown export.', 'rotary-grants' ) );
        }
        if ( ! current_user_can( $cap ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to download this export.', 'rotary-grants' ) );
        }
        $reports = new ReportService();
        $round   = null;
        if ( in_array( $type, [ 'shortlist', 'financial' ], true ) ) {
            $round = ( new RoundService() )->find( (int) ( $params['round_id'] ?? 0 ) );
            if ( ! $round ) {
                return new \WP_Error( 'round', __( 'Choose a funding round.', 'rotary-grants' ) );
            }
        }
        $slug = static fn( string $s ): string => trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $s ) ) ), '-' );
        $date = wp_date( 'Y-m-d' );

        switch ( $type ) {
            case 'shortlist':
                $rows = $reports->shortlist( $round->id );
                $csv  = Csv::build( [
                    'public_reference'  => [ __( 'Reference', 'rotary-grants' ), Csv::TEXT ],
                    'organisation_name' => [ __( 'Organisation', 'rotary-grants' ), Csv::TEXT ],
                    'organisation_town' => [ __( 'Town', 'rotary-grants' ), Csv::TEXT ],
                    'requested_pence'   => [ __( 'Requested (GBP)', 'rotary-grants' ), Csv::MONEY ],
                    'status'            => [ __( 'Status', 'rotary-grants' ), Csv::TEXT ],
                    'source'            => [ __( 'Source', 'rotary-grants' ), Csv::TEXT ],
                    'submitted_at'      => [ __( 'Received', 'rotary-grants' ), Csv::DATE ],
                    'reviews'           => [ __( 'Reviews', 'rotary-grants' ), Csv::INT ],
                    'rec_fund'          => [ __( 'Recommend fund', 'rotary-grants' ), Csv::INT ],
                    'rec_part'          => [ __( 'Recommend part', 'rotary-grants' ), Csv::INT ],
                    'rec_decline'       => [ __( 'Recommend decline', 'rotary-grants' ), Csv::INT ],
                    'rec_defer'         => [ __( 'Recommend defer', 'rotary-grants' ), Csv::INT ],
                    'decision'          => [ __( 'Decision', 'rotary-grants' ), Csv::TEXT ],
                    'note'              => [ __( 'Note', 'rotary-grants' ), Csv::TEXT ],
                ], array_map( static fn( $r ) => $r + [ 'note' => $r['conflicted'] ? __( 'You declared a conflict on this application', 'rotary-grants' ) : '' ], $rows ) );
                $name = 'committee-list-' . $slug( $round->fund_name . '-' . $round->label ) . '-' . $date . '.csv';
                break;

            case 'financial':
                $data = $reports->financial( $round->id );
                $rows = $data['rows'];
                $csv  = Csv::build( [
                    'organisation_name' => [ __( 'Organisation', 'rotary-grants' ), Csv::TEXT ],
                    'public_reference'  => [ __( 'Application', 'rotary-grants' ), Csv::TEXT ],
                    'status'            => [ __( 'Award status', 'rotary-grants' ), Csv::TEXT ],
                    'approved_on'       => [ __( 'Approved on', 'rotary-grants' ), Csv::DATE ],
                    'approved_pence'    => [ __( 'Approved (GBP)', 'rotary-grants' ), Csv::MONEY ],
                    'net_paid'          => [ __( 'Net paid (GBP)', 'rotary-grants' ), Csv::MONEY ],
                    'outstanding'       => [ __( 'Outstanding (GBP)', 'rotary-grants' ), Csv::MONEY ],
                    'progress'          => [ __( 'Progress', 'rotary-grants' ), Csv::TEXT ],
                    'last_paid_on'      => [ __( 'Last paid', 'rotary-grants' ), Csv::DATE ],
                    'over_budget_pence' => [ __( 'Beyond round budget (GBP)', 'rotary-grants' ), Csv::MONEY ],
                    'funding_note'      => [ __( 'Funding source for amount beyond budget', 'rotary-grants' ), Csv::TEXT ],
                    'incomplete'        => [ __( 'Incomplete history', 'rotary-grants' ), Csv::TEXT ],
                ], array_map( static fn( $r ) => $r + [ 'incomplete' => (int) $r['incomplete_history'] ? 'yes' : '' ], $rows ) );
                $name = 'financial-' . $slug( $round->fund_name . '-' . $round->label ) . '-' . $date . '.csv';
                break;

            case 'payments':
                $year = (int) ( $params['year'] ?? 0 );
                if ( $year < 1990 || $year > 2200 ) {
                    return new \WP_Error( 'year', __( 'Choose a year.', 'rotary-grants' ) );
                }
                $data = $reports->payments_in_year( $year );
                $rows = $data['rows'];
                $csv  = Csv::build( [
                    'paid_on'           => [ __( 'Date', 'rotary-grants' ), Csv::DATE ],
                    'organisation_name' => [ __( 'Organisation', 'rotary-grants' ), Csv::TEXT ],
                    'public_reference'  => [ __( 'Application', 'rotary-grants' ), Csv::TEXT ],
                    'round_name'        => [ __( 'Round', 'rotary-grants' ), Csv::TEXT ],
                    'entry_type'        => [ __( 'Entry', 'rotary-grants' ), Csv::TEXT ],
                    'signed_pence'      => [ __( 'Amount (GBP)', 'rotary-grants' ), Csv::MONEY ],
                    'method_label'      => [ __( 'Method', 'rotary-grants' ), Csv::TEXT ],
                    'reference'         => [ __( 'Reference', 'rotary-grants' ), Csv::TEXT ],
                ], $rows );
                $name = 'payments-' . $slug( $data['label'] ) . '-' . $date . '.csv';
                break;

            default: // contacts
                $rows = $reports->future_round_contacts();
                $csv  = Csv::build( [
                    'name'              => [ __( 'Name', 'rotary-grants' ), Csv::TEXT ],
                    'email'             => [ __( 'Email', 'rotary-grants' ), Csv::TEXT ],
                    'role'              => [ __( 'Role', 'rotary-grants' ), Csv::TEXT ],
                    'organisation_name' => [ __( 'Organisation', 'rotary-grants' ), Csv::TEXT ],
                    'town'              => [ __( 'Town', 'rotary-grants' ), Csv::TEXT ],
                    'recorded_at'       => [ __( 'Permission given', 'rotary-grants' ), Csv::DATE ],
                    'wording_version'   => [ __( 'Wording version', 'rotary-grants' ), Csv::TEXT ],
                    'evidence_source'   => [ __( 'Evidence', 'rotary-grants' ), Csv::TEXT ],
                ], $rows );
                $name = 'future-round-contacts-' . $date . '.csv';
        }

        AuditLogger::record( 'export_downloaded', 'export', null, array_filter( [
            'type'     => $type,
            'round_id' => $round->id ?? null,
            'year'     => $type === 'payments' ? (int) $params['year'] : null,
            'rows'     => count( $rows ),
        ], static fn( $v ) => $v !== null ) );

        return [ 'filename' => $name, 'csv' => $csv, 'rows' => count( $rows ) ];
    }
}
