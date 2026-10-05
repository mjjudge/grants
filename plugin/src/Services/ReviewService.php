<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Committee reviews: eligibility findings (separate from application
 * status), a recommendation, and private notes.
 *
 * Public declarations are evidence, not verification — reviewers record
 * what they checked and their uncertainty; decision makers decide (docs/03).
 * A recommendation never creates an award. Saving again creates a new
 * version and marks the old one superseded; history is kept.
 *
 * Requires grants_review or grants_decide, and a "no conflict" declaration
 * on the application (ConflictService::require_clear()).
 */
class ReviewService {

    public const FINDINGS = [ 'met', 'not_met', 'unsure', 'not_checked' ];

    public const RECOMMENDATIONS = [ 'fund', 'fund_partial', 'decline', 'defer', 'none' ];

    /** @return array<string,string> eligibility check => label */
    public static function checks(): array {
        return [
            'local_benefit'      => __( 'Local connection and benefit, as this round\'s eligibility wording requires', 'rotary-grants' ),
            'one_off'            => __( 'One-off initiative rather than recurring running costs', 'rotary-grants' ),
            'no_exclusion'       => __( 'None of the exclusions applies', 'rotary-grants' ),
            'bank_account'       => __( 'Bank account in the organisation\'s own name', 'rotary-grants' ),
            'governing_document' => __( 'Governing document setting out its objectives', 'rotary-grants' ),
            'locally_led'        => __( 'Led and run locally', 'rotary-grants' ),
            'committee'          => __( 'Has a committee or support group', 'rotary-grants' ),
            'national_branch'    => __( 'If part of a national organisation: own local management and funds stay local', 'rotary-grants' ),
        ];
    }

    public static function finding_label( string $finding ): string {
        return match ( $finding ) {
            'met'         => __( 'Met', 'rotary-grants' ),
            'not_met'     => __( 'Not met', 'rotary-grants' ),
            'unsure'      => __( 'Unsure', 'rotary-grants' ),
            default       => __( 'Not checked', 'rotary-grants' ),
        };
    }

    public static function recommendation_label( string $rec ): string {
        return match ( $rec ) {
            'fund'         => __( 'Fund in full', 'rotary-grants' ),
            'fund_partial' => __( 'Fund in part', 'rotary-grants' ),
            'decline'      => __( 'Decline', 'rotary-grants' ),
            'defer'        => __( 'Defer', 'rotary-grants' ),
            default        => __( 'No recommendation yet', 'rotary-grants' ),
        };
    }

    public static function can_review(): bool {
        return current_user_can( 'grants_review' ) || current_user_can( 'grants_decide' );
    }

    /**
     * Save (a new version of) the current user's review.
     *
     * @param array{findings?: array<string,string>, finding_notes?: array<string,string>, recommendation?: string, recommended_amount?: string, notes?: string} $input
     * @return int|\WP_Error New review id.
     */
    public function save( int $application_id, array $input ): int|\WP_Error {
        if ( ! self::can_review() ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to review applications.', 'rotary-grants' ) );
        }
        $gate = ( new ConflictService() )->require_clear( $application_id );
        if ( $gate ) {
            return $gate;
        }
        $app = ( new ApplicationService() )->find( $application_id );
        if ( ! $app ) {
            return new \WP_Error( 'not_found', __( 'Application not found.', 'rotary-grants' ) );
        }
        if ( ! ApplicationStatus::is_open( $app->status ) ) {
            return new \WP_Error( 'closed', __( 'This application is no longer open for review.', 'rotary-grants' ) );
        }

        $errors   = new \WP_Error();
        $findings = [];
        foreach ( array_keys( self::checks() ) as $check ) {
            $finding = (string) ( $input['findings'][ $check ] ?? 'not_checked' );
            $note    = trim( sanitize_textarea_field( (string) ( $input['finding_notes'][ $check ] ?? '' ) ) );
            if ( ! in_array( $finding, self::FINDINGS, true ) ) {
                $finding = 'not_checked';
            }
            if ( mb_strlen( $note ) > 1000 ) {
                $errors->add( 'finding_' . $check, __( 'Please keep each note to 1000 characters or fewer.', 'rotary-grants' ) );
            }
            $findings[ $check ] = [ 'finding' => $finding, 'note' => $note ];
        }

        $rec = (string) ( $input['recommendation'] ?? 'none' );
        if ( ! in_array( $rec, self::RECOMMENDATIONS, true ) ) {
            $errors->add( 'recommendation', __( 'Choose a recommendation.', 'rotary-grants' ) );
        }
        $amount = null;
        if ( $rec === 'fund' ) {
            $amount = $app->requested_pence;
        } elseif ( $rec === 'fund_partial' ) {
            $parsed = Money::parse_gbp( (string) ( $input['recommended_amount'] ?? '' ) );
            if ( is_wp_error( $parsed ) ) {
                $errors->add( 'recommended_amount', $parsed->get_error_message() );
            } elseif ( $parsed <= 0 || ( $app->requested_pence !== null && $parsed >= $app->requested_pence ) ) {
                $errors->add( 'recommended_amount', sprintf(
                    /* translators: %s: amount requested */
                    __( 'A part award must be more than £0 and less than the %s requested.', 'rotary-grants' ),
                    $app->requested_pence === null ? '?' : Money::format_gbp( $app->requested_pence )
                ) );
            } else {
                $amount = $parsed;
            }
        }
        $notes = trim( sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ) );
        if ( mb_strlen( $notes ) > 5000 ) {
            $errors->add( 'notes', __( 'Please keep the notes to 5000 characters or fewer.', 'rotary-grants' ) );
        }
        if ( $errors->has_errors() ) {
            return $errors;
        }

        global $wpdb;
        $user     = get_current_user_id();
        $previous = $this->current_for( $application_id, $user );

        $wpdb->query( 'START TRANSACTION' );
        $wpdb->insert(
            $this->table(),
            [
                'application_id'    => $application_id,
                'reviewer_user_id'  => $user,
                'eligibility_json'  => wp_json_encode( $findings ),
                'recommendation'    => $rec,
                'recommended_pence' => $amount,
                'notes'             => $notes,
                'reviewed_at'       => SiteTime::now_utc(),
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
        );
        $id = (int) $wpdb->insert_id;
        if ( $previous ) {
            $wpdb->update( $this->table(), [ 'superseded_by' => $id ], [ 'id' => (int) $previous->id ], [ '%d' ], [ '%d' ] );
        }
        $wpdb->query( 'COMMIT' );

        // Reviewing starts the review.
        if ( $app->status === ApplicationStatus::RECEIVED ) {
            ( new WorkflowService() )->system_transition( $app, ApplicationStatus::UNDER_REVIEW, __( 'First review recorded.', 'rotary-grants' ) );
        }

        AuditLogger::record( 'review_saved', 'application', $application_id, [
            'review_id'      => $id,
            'recommendation' => $rec,
            'replaces'       => $previous ? (int) $previous->id : null,
        ] );
        return $id;
    }

    public function current_for( int $application_id, int $user_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE application_id = %d AND reviewer_user_id = %d AND superseded_by IS NULL ORDER BY id DESC LIMIT 1",
            $application_id,
            $user_id
        ) );
        return $row ? self::hydrate( $row ) : null;
    }

    /**
     * Current reviews on an application — only for a committee member who
     * has declared no conflict on it.
     *
     * @return object[]|\WP_Error
     */
    public function for_application( int $application_id ): array|\WP_Error {
        $gate = ( new ConflictService() )->require_clear( $application_id );
        if ( $gate ) {
            return $gate;
        }
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, u.display_name,
                    (SELECT COUNT(*) FROM {$this->table()} h WHERE h.application_id = r.application_id AND h.reviewer_user_id = r.reviewer_user_id) AS versions
             FROM {$this->table()} r LEFT JOIN {$wpdb->users} u ON u.ID = r.reviewer_user_id
             WHERE r.application_id = %d AND r.superseded_by IS NULL
             ORDER BY r.reviewed_at",
            $application_id
        ) );
        return array_map( [ self::class, 'hydrate' ], $rows );
    }

    /**
     * Number of current reviews per application (counts only — safe for lists).
     *
     * @param int[] $application_ids
     * @return array<int,int>
     */
    public function counts( array $application_ids ): array {
        $ids = array_values( array_filter( array_map( 'intval', $application_ids ) ) );
        if ( ! $ids ) {
            return [];
        }
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT application_id, COUNT(*) AS n FROM {$this->table()} WHERE superseded_by IS NULL AND application_id IN ({$placeholders}) GROUP BY application_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — placeholders built above
            ...$ids
        ) );
        $out = [];
        foreach ( $rows as $r ) {
            $out[ (int) $r->application_id ] = (int) $r->n;
        }
        return $out;
    }

    private static function hydrate( object $row ): object {
        $row->id                = (int) $row->id;
        $row->reviewer_user_id  = (int) $row->reviewer_user_id;
        $row->recommended_pence = $row->recommended_pence === null ? null : (int) $row->recommended_pence;
        $row->findings          = json_decode( (string) $row->eligibility_json, true ) ?: [];
        return $row;
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'grants_reviews';
    }
}
