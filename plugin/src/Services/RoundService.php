<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Audit\AuditLogger;
use Rotary\Grants\Support\Money;
use Rotary\Grants\Support\SiteTime;

defined( 'ABSPATH' ) || exit;

/**
 * Funding rounds: validation, create/update, status transitions, and the
 * "is this round accepting applications right now" rule.
 *
 * Every mutation requires grants_manage_rounds (checked here, not only in
 * the controller), is audited, and uses optimistic concurrency on
 * row_version so two staff editing the same round cannot silently
 * overwrite each other. Opening a round is additionally serialised with a
 * named database lock so two rounds of the same fund cannot both open.
 *
 * Times are UTC in the database; opening is inclusive, closing exclusive.
 */
class RoundService {

    public const CAPABILITY = 'grants_manage_rounds';

    /** Public wording fields, configured per round. */
    public const TEXT_FIELDS = [ 'intro_text', 'eligibility_text', 'exclusions_text', 'publicity_text', 'presentation_text' ];

    private const MAX_TEXT_LENGTH  = 10000;
    private const MIN_YEAR         = 2000;
    private const MAX_YEAR         = 2100;
    private const OPEN_LOCK_SECS   = 5;

    /** Columns compared to decide what an update changed. */
    private const EDITABLE_COLUMNS = [
        'label', 'fund_name', 'campaign_year', 'accounting_period_label', 'opens_at', 'closes_at',
        'budget_pence', 'cap_pence', 'intro_text', 'eligibility_text', 'exclusions_text', 'publicity_text',
        'presentation_text',
    ];

    // =========================================================================
    // Reads
    // =========================================================================

    public function find( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
        return $row ? self::hydrate( $row ) : null;
    }

    /**
     * @return object[] Newest campaign year first.
     */
    public function all( ?string $status = null ): array {
        global $wpdb;
        if ( $status !== null && in_array( $status, RoundStatus::all(), true ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE status = %s ORDER BY campaign_year DESC, id DESC",
                $status
            ) );
        } else {
            $rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY campaign_year DESC, id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — no user input
        }
        return array_map( [ self::class, 'hydrate' ], (array) $rows );
    }

    /**
     * The open round for a fund (at most one exists — DEC-009), or null.
     * fund_name comparison follows the table collation (case-insensitive).
     */
    public function find_open_for_fund( string $fund_name ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE fund_name = %s AND status = %s ORDER BY id DESC LIMIT 1",
            trim( $fund_name ),
            RoundStatus::OPEN
        ) );
        return $row ? self::hydrate( $row ) : null;
    }

    /**
     * Whether the round accepts public applications at $now_utc (default: now).
     * Server time only — never the client's clock.
     */
    public function is_accepting( object $round, ?string $now_utc = null ): bool {
        return $this->phase( $round, $now_utc ) === 'accepting';
    }

    /**
     * Finer-grained state of an open round: 'scheduled' (before opens_at),
     * 'accepting', or 'deadline_passed'. Non-open rounds return their status.
     */
    public function phase( object $round, ?string $now_utc = null ): string {
        if ( $round->status !== RoundStatus::OPEN || ! $round->opens_at || ! $round->closes_at ) {
            return $round->status;
        }
        $now = $now_utc ?? SiteTime::now_utc();
        // Fixed-width 'Y-m-d H:i:s' UTC strings compare correctly as strings.
        if ( $now < $round->opens_at ) {
            return 'scheduled';
        }
        return $now < $round->closes_at ? 'accepting' : 'deadline_passed';
    }

    /**
     * Reasons the round cannot be (or stay) open. Empty array = ready.
     *
     * @param object|array<string,mixed> $round Hydrated row or validated clean data (+ id).
     * @return string[]
     */
    public function open_blockers( object|array $round, bool $for_opening ): array {
        $r        = (object) $round;
        $blockers = [];

        if ( empty( $r->opens_at ) ) {
            $blockers[] = __( 'Set the opening date and time.', 'rotary-grants' );
        }
        if ( empty( $r->closes_at ) ) {
            $blockers[] = __( 'Set the closing date and time.', 'rotary-grants' );
        } elseif ( $for_opening && $r->closes_at <= SiteTime::now_utc() ) {
            $blockers[] = __( 'The closing date and time has already passed — move it later before opening.', 'rotary-grants' );
        }
        if ( $r->budget_pence === null || (int) $r->budget_pence <= 0 ) {
            $blockers[] = __( 'Set the round budget.', 'rotary-grants' );
        }
        if ( trim( (string) $r->eligibility_text ) === '' ) {
            $blockers[] = __( 'Add the eligibility wording.', 'rotary-grants' );
        }
        if ( trim( (string) $r->exclusions_text ) === '' ) {
            $blockers[] = __( 'Add the exclusions wording.', 'rotary-grants' );
        }

        $settings = new SettingsService();
        if ( $settings->help_email() === '' ) {
            $blockers[] = __( 'Set the help/contact email in Settings.', 'rotary-grants' );
        }
        if ( ! $settings->notification_recipients() ) {
            $blockers[] = __( 'Add at least one staff notification recipient in Settings.', 'rotary-grants' );
        }
        if ( $settings->get( SettingsService::PRIVACY_NOTICE_URL ) === '' ) {
            $blockers[] = __( 'Set the privacy notice link in Settings.', 'rotary-grants' );
        }

        $other = $this->other_open_round_for_fund( (string) $r->fund_name, (int) ( $r->id ?? 0 ) );
        if ( $other ) {
            $blockers[] = sprintf(
                /* translators: 1: fund name, 2: other round label */
                __( 'Another %1$s round is already open ("%2$s"). Close it first — only one round per fund can be open at a time.', 'rotary-grants' ),
                $r->fund_name,
                $other->label
            );
        }

        return $blockers;
    }

    /**
     * Things worth fixing before opening that do NOT block it.
     *
     * @return string[]
     */
    public function open_warnings(): array {
        $warnings = [];
        if ( ! ( new SettingsService() )->retention_confirmed() ) {
            $warnings[] = __( 'Retention periods have not been confirmed in Settings (suggested periods are in use).', 'rotary-grants' );
        }
        return $warnings;
    }

    // =========================================================================
    // Mutations
    // =========================================================================

    /**
     * @param array<string,string> $input Raw-but-unslashed form strings.
     * @return int|\WP_Error New round id, or errors keyed by field name.
     */
    public function create( array $input ): int|\WP_Error {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return self::forbidden();
        }

        [ $clean, $errors ] = $this->validate( $input );
        if ( $errors->has_errors() ) {
            return $errors;
        }

        global $wpdb;
        $now  = SiteTime::now_utc();
        $data = $clean + [
            'status'             => RoundStatus::DRAFT,
            'policy_version'     => '1',
            'row_version'        => 1,
            'created_at'         => $now,
            'created_by_user_id' => get_current_user_id(),
            'updated_at'         => $now,
        ];
        $ok = $wpdb->insert( $this->table(), $data, self::formats( array_keys( $data ) ) );
        if ( $ok === false ) {
            return self::db_error();
        }
        $id = (int) $wpdb->insert_id;

        AuditLogger::record( 'round_created', 'round', $id, [
            'fund_name'     => $clean['fund_name'],
            'label'         => $clean['label'],
            'campaign_year' => $clean['campaign_year'],
            'budget_pence'  => $clean['budget_pence'],
        ] );

        return $id;
    }

    /**
     * @param array<string,string> $input
     * @return true|\WP_Error
     */
    public function update( int $id, array $input, int $expected_row_version ): true|\WP_Error {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return self::forbidden();
        }
        $round = $this->find( $id );
        if ( ! $round ) {
            return new \WP_Error( 'not_found', __( 'Funding round not found.', 'rotary-grants' ) );
        }
        if ( $round->status === RoundStatus::ARCHIVED ) {
            return new \WP_Error( 'archived', __( 'An archived round cannot be edited.', 'rotary-grants' ) );
        }
        if ( $round->row_version !== $expected_row_version ) {
            return self::conflict();
        }

        [ $clean, $errors ] = $this->validate( $input );
        if ( $errors->has_errors() ) {
            return $errors;
        }

        // An open round must remain openable after the edit (e.g. wording
        // cannot be blanked while applications are being taken). A closing
        // time in the past is allowed — it simply ends submissions.
        if ( $round->status === RoundStatus::OPEN ) {
            $blockers = $this->open_blockers( $clean + [ 'id' => $id ], false );
            if ( $blockers ) {
                return new \WP_Error( 'open_round', implode( ' ', $blockers ) );
            }
        }

        // Approvals reserve budget; the budget can't be lowered below them
        // (going over budget is only ever a recorded decision with a funding
        // note — DEC-014). Raising it, or leaving it alone while already
        // knowingly over-committed, is fine.
        $committed = ( new BudgetService() )->commitments( $id );
        $lowered   = $clean['budget_pence'] === null || ( $round->budget_pence !== null && $clean['budget_pence'] < $round->budget_pence );
        if ( $committed > 0 && $lowered && ( $clean['budget_pence'] === null || $clean['budget_pence'] < $committed ) ) {
            return new \WP_Error( 'budget', sprintf(
                /* translators: %s: amount already approved */
                __( 'The budget cannot be less than the %s already approved in this round.', 'rotary-grants' ),
                \Rotary\Grants\Support\Money::format_gbp( $committed )
            ) );
        }

        $changed = [];
        foreach ( self::EDITABLE_COLUMNS as $col ) {
            if ( (string) $clean[ $col ] !== (string) $round->$col || ( $clean[ $col ] === null ) !== ( $round->$col === null ) ) {
                $changed[] = $col;
            }
        }
        if ( ! $changed ) {
            return true;
        }

        $data = $clean;
        if ( array_intersect( $changed, self::TEXT_FIELDS ) ) {
            $data['policy_version'] = (string) ( (int) $round->policy_version + 1 );
        }
        $data['row_version'] = $expected_row_version + 1;
        $data['updated_at']  = SiteTime::now_utc();

        global $wpdb;
        $rows = $wpdb->update(
            $this->table(),
            $data,
            [ 'id' => $id, 'row_version' => $expected_row_version ],
            self::formats( array_keys( $data ) ),
            [ '%d', '%d' ]
        );
        if ( $rows === false ) {
            return self::db_error();
        }
        if ( $rows === 0 ) {
            return self::conflict();
        }

        $summary = [ 'changed' => $changed ];
        foreach ( [ 'budget_pence', 'cap_pence', 'opens_at', 'closes_at', 'fund_name', 'label' ] as $col ) {
            if ( in_array( $col, $changed, true ) ) {
                $summary[ $col ] = [ $round->$col, $clean[ $col ] ];
            }
        }
        if ( isset( $data['policy_version'] ) ) {
            $summary['policy_version'] = $data['policy_version'];
        }
        AuditLogger::record( 'round_updated', 'round', $id, $summary );

        return true;
    }

    /**
     * Move a round to another status. Opening validates readiness and is
     * serialised so two rounds of one fund cannot open concurrently.
     *
     * @return true|\WP_Error
     */
    public function transition( int $id, string $to, int $expected_row_version ): true|\WP_Error {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return self::forbidden();
        }

        global $wpdb;
        $lock = $wpdb->prefix . 'grants_round_status';
        if ( $to === RoundStatus::OPEN ) {
            if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::OPEN_LOCK_SECS ) ) !== '1' ) {
                return new \WP_Error( 'busy', __( 'Another change to funding rounds is in progress. Please try again.', 'rotary-grants' ) );
            }
        }

        try {
            $round = $this->find( $id );
            if ( ! $round ) {
                return new \WP_Error( 'not_found', __( 'Funding round not found.', 'rotary-grants' ) );
            }
            if ( $round->row_version !== $expected_row_version ) {
                return self::conflict();
            }
            if ( ! RoundStatus::can_transition( $round->status, $to ) ) {
                return new \WP_Error( 'bad_transition', sprintf(
                    /* translators: 1: current status, 2: requested status */
                    __( 'A round cannot move from %1$s to %2$s.', 'rotary-grants' ),
                    RoundStatus::label( $round->status ),
                    RoundStatus::label( $to )
                ) );
            }
            if ( $to === RoundStatus::OPEN ) {
                $blockers = $this->open_blockers( $round, true );
                if ( $blockers ) {
                    return new \WP_Error( 'not_ready', implode( ' ', $blockers ) );
                }
            }

            $rows = $wpdb->update(
                $this->table(),
                [ 'status' => $to, 'row_version' => $expected_row_version + 1, 'updated_at' => SiteTime::now_utc() ],
                [ 'id' => $id, 'row_version' => $expected_row_version ],
                [ '%s', '%d', '%s' ],
                [ '%d', '%d' ]
            );
            if ( $rows === false ) {
                return self::db_error();
            }
            if ( $rows === 0 ) {
                return self::conflict();
            }

            AuditLogger::record( 'round_status_changed', 'round', $id, [ 'from' => $round->status, 'to' => $to ] );
            return true;
        } finally {
            if ( $to === RoundStatus::OPEN ) {
                $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
            }
        }
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * Validate form input into column values. Errors are keyed by input name.
     *
     * Input keys: label, fund_name, campaign_year, accounting_period_label,
     * opens_at, closes_at (local "YYYY-MM-DDTHH:MM"), budget, cap (GBP),
     * and each of TEXT_FIELDS.
     *
     * @param array<string,string> $input
     * @return array{0: array<string,mixed>, 1: \WP_Error}
     */
    public function validate( array $input ): array {
        $errors = new \WP_Error();
        $clean  = [];

        $clean['label'] = self::single_line( $input['label'] ?? '' );
        if ( $clean['label'] === '' ) {
            $errors->add( 'label', __( 'Enter a round label, for example "Tree of Light 2026".', 'rotary-grants' ) );
        } elseif ( mb_strlen( $clean['label'] ) > 150 ) {
            $errors->add( 'label', __( 'The round label must be 150 characters or fewer.', 'rotary-grants' ) );
        }

        $clean['fund_name'] = self::single_line( $input['fund_name'] ?? '' );
        if ( $clean['fund_name'] === '' ) {
            $errors->add( 'fund_name', __( 'Enter the fund name, for example "Tree of Light".', 'rotary-grants' ) );
        } elseif ( mb_strlen( $clean['fund_name'] ) > 150 ) {
            $errors->add( 'fund_name', __( 'The fund name must be 150 characters or fewer.', 'rotary-grants' ) );
        }

        $year = trim( (string) ( $input['campaign_year'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}$/', $year ) || (int) $year < self::MIN_YEAR || (int) $year > self::MAX_YEAR ) {
            $errors->add( 'campaign_year', __( 'Enter a four-digit campaign year.', 'rotary-grants' ) );
            $clean['campaign_year'] = 0;
        } else {
            $clean['campaign_year'] = (int) $year;
        }

        $clean['accounting_period_label'] = self::single_line( $input['accounting_period_label'] ?? '' );
        if ( mb_strlen( $clean['accounting_period_label'] ) > 100 ) {
            $errors->add( 'accounting_period_label', __( 'The accounting period label must be 100 characters or fewer.', 'rotary-grants' ) );
        }

        foreach ( [ 'opens_at', 'closes_at' ] as $field ) {
            $raw = trim( (string) ( $input[ $field ] ?? '' ) );
            $clean[ $field ] = null;
            if ( $raw === '' ) {
                continue;
            }
            $utc = SiteTime::local_input_to_utc( $raw );
            if ( is_wp_error( $utc ) ) {
                $errors->add( $field, $utc->get_error_message() );
            } else {
                $clean[ $field ] = $utc;
            }
        }
        if ( $clean['opens_at'] && $clean['closes_at'] && $clean['closes_at'] <= $clean['opens_at'] ) {
            $errors->add( 'closes_at', __( 'The closing time must be after the opening time.', 'rotary-grants' ) );
        }

        foreach ( [ 'budget' => 'budget_pence', 'cap' => 'cap_pence' ] as $field => $col ) {
            $raw           = trim( (string) ( $input[ $field ] ?? '' ) );
            $clean[ $col ] = null;
            if ( $raw === '' ) {
                continue;
            }
            $pence = Money::parse_gbp( $raw );
            if ( is_wp_error( $pence ) ) {
                $errors->add( $field, $pence->get_error_message() );
            } elseif ( $pence <= 0 ) {
                $errors->add( $field, __( 'Enter an amount greater than £0.', 'rotary-grants' ) );
            } else {
                $clean[ $col ] = $pence;
            }
        }
        if ( $clean['budget_pence'] !== null && $clean['cap_pence'] !== null && $clean['cap_pence'] > $clean['budget_pence'] ) {
            $errors->add( 'cap', __( 'The maximum award cannot be more than the round budget.', 'rotary-grants' ) );
        }

        foreach ( self::TEXT_FIELDS as $field ) {
            $clean[ $field ] = trim( wp_kses_post( (string) ( $input[ $field ] ?? '' ) ) );
            if ( mb_strlen( $clean[ $field ] ) > self::MAX_TEXT_LENGTH ) {
                $errors->add( $field, sprintf(
                    /* translators: %d: maximum characters */
                    __( 'This wording must be %d characters or fewer.', 'rotary-grants' ),
                    self::MAX_TEXT_LENGTH
                ) );
            }
        }

        return [ $clean, $errors ];
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'grants_rounds';
    }

    private function other_open_round_for_fund( string $fund_name, int $exclude_id ): ?object {
        global $wpdb;
        if ( $fund_name === '' ) {
            return null;
        }
        // fund_name comparison follows the table collation (case-insensitive).
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, label FROM {$this->table()} WHERE fund_name = %s AND status = %s AND id <> %d LIMIT 1",
            $fund_name,
            RoundStatus::OPEN,
            $exclude_id
        ) );
        return $row ?: null;
    }

    /** Cast numeric columns so callers can compare strictly. */
    private static function hydrate( object $row ): object {
        $row->id            = (int) $row->id;
        $row->campaign_year = (int) $row->campaign_year;
        $row->row_version   = (int) $row->row_version;
        $row->budget_pence  = $row->budget_pence === null ? null : (int) $row->budget_pence;
        $row->cap_pence     = $row->cap_pence === null ? null : (int) $row->cap_pence;
        return $row;
    }

    private static function single_line( mixed $value ): string {
        return trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $value ) ) );
    }

    /**
     * $wpdb format for each column name.
     *
     * @param string[] $columns
     * @return string[]
     */
    private static function formats( array $columns ): array {
        $ints = [ 'campaign_year', 'budget_pence', 'cap_pence', 'row_version', 'created_by_user_id' ];
        return array_map( static fn( $c ) => in_array( $c, $ints, true ) ? '%d' : '%s', $columns );
    }

    private static function forbidden(): \WP_Error {
        return new \WP_Error( 'forbidden', __( 'You do not have permission to manage funding rounds.', 'rotary-grants' ) );
    }

    private static function conflict(): \WP_Error {
        return new \WP_Error( 'conflict', __( 'Someone else changed this round while you were editing it. Reload the page to see their changes, then try again.', 'rotary-grants' ) );
    }

    private static function db_error(): \WP_Error {
        return new \WP_Error( 'db_error', __( 'The funding round could not be saved. Please try again.', 'rotary-grants' ) );
    }
}
