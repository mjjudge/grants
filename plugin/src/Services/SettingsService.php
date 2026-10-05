<?php

namespace Rotary\Grants\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes grants_settings. The only code that touches that table.
 *
 * Keys are whitelisted. Saving requires grants_manage_settings (checked here,
 * not only in the admin controller), validates every value before writing
 * any of them, and records an audit event naming the changed keys — never
 * the email addresses themselves.
 */
class SettingsService {

    public const HELP_EMAIL              = 'help_email';
    public const NOTIFICATION_RECIPIENTS = 'notification_recipients';
    public const PRIVACY_NOTICE_URL      = 'privacy_notice_url';
    public const PRIVACY_NOTICE_VERSION  = 'privacy_notice_version';
    public const MAIL_FROM_NAME          = 'mail_from_name';
    public const MAIL_FROM_ADDRESS       = 'mail_from_address';
    public const NOTIFICATIONS_PAUSED    = 'notifications_paused';
    public const REPORTING_YEAR_START    = 'reporting_year_start_month';

    /** Upper bound on staff notification recipients — a typo guard, not a policy. */
    public const MAX_RECIPIENTS = 20;

    /** @var array<string, string> */
    private const DEFAULTS = [
        self::HELP_EMAIL              => '',
        self::NOTIFICATION_RECIPIENTS => '',
        self::PRIVACY_NOTICE_URL      => '',
        self::PRIVACY_NOTICE_VERSION  => '',
        // Blank = fall back to the site's name / WordPress's own sender, so a
        // fresh install for any club works. Each club sets its own (Rotary in
        // the Vale: funds@rotaryinthevale.org — DEC-011).
        self::MAIL_FROM_NAME          => '',
        self::MAIL_FROM_ADDRESS       => '',
        self::NOTIFICATIONS_PAUSED    => '0',
        // 1 = calendar year; 7 = Rotary year (July–June); 4 = April–March…
        self::REPORTING_YEAR_START    => '1',
    ];

    /** @var array<string, string>|null Per-request cache of stored values. */
    private static ?array $cache = null;

    public function get( string $key ): string {
        if ( ! array_key_exists( $key, self::DEFAULTS ) ) {
            return '';
        }
        return $this->load()[ $key ] ?? self::DEFAULTS[ $key ];
    }

    /** Sender name actually used: the setting, or the site's name. */
    public function mail_from_name(): string {
        return $this->get( self::MAIL_FROM_NAME ) ?: wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
    }

    public function reporting_year_start_month(): int {
        return max( 1, min( 12, (int) $this->get( self::REPORTING_YEAR_START ) ) );
    }

    public function notifications_paused(): bool {
        return $this->get( self::NOTIFICATIONS_PAUSED ) === '1';
    }

    public function help_email(): string {
        return $this->get( self::HELP_EMAIL );
    }

    /**
     * Configured staff notification recipients, as a list of addresses.
     *
     * @return string[]
     */
    public function notification_recipients(): array {
        return self::parse_email_list( $this->get( self::NOTIFICATION_RECIPIENTS ) )['valid'];
    }

    /**
     * Split a comma/semicolon/whitespace-separated list of addresses.
     * Duplicates (case-insensitive) are dropped, keeping the first spelling.
     *
     * @return array{valid: string[], invalid: string[]}
     */
    public static function parse_email_list( string $raw ): array {
        $valid   = [];
        $invalid = [];
        $seen    = [];

        foreach ( preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $candidate ) {
            if ( ! is_email( $candidate ) ) {
                $invalid[] = $candidate;
                continue;
            }
            $folded = strtolower( $candidate );
            if ( isset( $seen[ $folded ] ) ) {
                continue;
            }
            $seen[ $folded ] = true;
            $valid[]         = $candidate;
        }

        return [ 'valid' => $valid, 'invalid' => $invalid ];
    }

    /**
     * Validate and save settings. Nothing is written unless every value is valid.
     *
     * @param array<string, string> $input Already sanitised by the controller.
     * @return true|\WP_Error WP_Error codes are setting keys (field errors) or
     *                        'forbidden' / 'db_error'.
     */
    public function save( array $input ): true|\WP_Error {
        if ( ! current_user_can( 'grants_manage_settings' ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to change Rotary Grants settings.', 'rotary-grants' ) );
        }

        $errors = new \WP_Error();
        $clean  = [];

        // Help email — optional here; opening a round will require it (G02).
        $help = trim( (string) ( $input[ self::HELP_EMAIL ] ?? '' ) );
        if ( $help !== '' && ! is_email( $help ) ) {
            $errors->add( self::HELP_EMAIL, __( 'Enter a valid email address, or leave the field empty.', 'rotary-grants' ) );
        }
        $clean[ self::HELP_EMAIL ] = $help;

        // Notification recipients — stored one per line.
        $parsed = self::parse_email_list( (string) ( $input[ self::NOTIFICATION_RECIPIENTS ] ?? '' ) );
        if ( $parsed['invalid'] ) {
            $errors->add(
                self::NOTIFICATION_RECIPIENTS,
                sprintf(
                    /* translators: %s: comma-separated list of rejected entries */
                    __( 'These are not valid email addresses: %s', 'rotary-grants' ),
                    implode( ', ', $parsed['invalid'] )
                )
            );
        } elseif ( count( $parsed['valid'] ) > self::MAX_RECIPIENTS ) {
            $errors->add(
                self::NOTIFICATION_RECIPIENTS,
                sprintf(
                    /* translators: %d: maximum number of recipients */
                    __( 'Enter no more than %d notification recipients.', 'rotary-grants' ),
                    self::MAX_RECIPIENTS
                )
            );
        }
        $clean[ self::NOTIFICATION_RECIPIENTS ] = implode( "\n", $parsed['valid'] );

        // Privacy notice — optional here; opening a round will require the link.
        $url = trim( (string) ( $input[ self::PRIVACY_NOTICE_URL ] ?? '' ) );
        if ( $url !== '' && ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), [ 'http', 'https' ], true ) ) ) {
            $errors->add( self::PRIVACY_NOTICE_URL, __( 'Enter a full web address starting with https:// (or http://), or leave the field empty.', 'rotary-grants' ) );
        }
        $clean[ self::PRIVACY_NOTICE_URL ] = $url;

        $version = trim( (string) ( $input[ self::PRIVACY_NOTICE_VERSION ] ?? '' ) );
        if ( mb_strlen( $version ) > 50 ) {
            $errors->add( self::PRIVACY_NOTICE_VERSION, __( 'The privacy notice version must be 50 characters or fewer.', 'rotary-grants' ) );
        }
        $clean[ self::PRIVACY_NOTICE_VERSION ] = $version;

        // Sender identity — empty means "use the site's defaults".
        $from_address = trim( (string) ( $input[ self::MAIL_FROM_ADDRESS ] ?? '' ) );
        if ( $from_address !== '' && ! is_email( $from_address ) ) {
            $errors->add( self::MAIL_FROM_ADDRESS, __( 'Enter a valid email address, or leave the field empty to use WordPress\'s normal sender.', 'rotary-grants' ) );
        }
        $clean[ self::MAIL_FROM_ADDRESS ] = $from_address;

        $from_name = trim( (string) preg_replace( '/[\r\n\t]+/', ' ', (string) ( $input[ self::MAIL_FROM_NAME ] ?? '' ) ) );
        if ( mb_strlen( $from_name ) > 100 || preg_match( '/[<>"@]/', $from_name ) ) {
            $errors->add( self::MAIL_FROM_NAME, __( 'Enter a plain name of 100 characters or fewer, without < > " or @.', 'rotary-grants' ) );
        }
        $clean[ self::MAIL_FROM_NAME ] = $from_name;

        $month = (string) ( $input[ self::REPORTING_YEAR_START ] ?? '1' );
        if ( ! preg_match( '/^(?:[1-9]|1[0-2])$/', $month ) ) {
            $errors->add( self::REPORTING_YEAR_START, __( 'Choose the month the reporting year starts.', 'rotary-grants' ) );
            $month = '1';
        }
        $clean[ self::REPORTING_YEAR_START ] = $month;

        $clean[ self::NOTIFICATIONS_PAUSED ] = ( $input[ self::NOTIFICATIONS_PAUSED ] ?? '' ) === '1' ? '1' : '0';

        if ( $errors->has_errors() ) {
            return $errors;
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'grants_settings';
        $now     = current_time( 'mysql', true );
        $actor   = get_current_user_id();
        $current = $this->load();
        $changed = [];

        foreach ( $clean as $key => $value ) {
            if ( ( $current[ $key ] ?? self::DEFAULTS[ $key ] ) === $value ) {
                continue;
            }
            $ok = $wpdb->replace(
                $table,
                [
                    'setting_key'        => $key,
                    'setting_value'      => $value,
                    'updated_at'         => $now,
                    'updated_by_user_id' => $actor,
                ],
                [ '%s', '%s', '%s', '%d' ]
            );
            if ( $ok === false ) {
                self::$cache = null;
                return new \WP_Error( 'db_error', __( 'Settings could not be saved. Please try again.', 'rotary-grants' ) );
            }
            $changed[] = $key;
        }

        self::$cache = null;

        if ( $changed ) {
            \Rotary\Grants\Audit\AuditLogger::record(
                'settings_updated',
                'settings',
                null,
                [
                    'changed'         => $changed,
                    'help_email_set'  => $clean[ self::HELP_EMAIL ] !== '',
                    'recipient_count' => count( $parsed['valid'] ),
                    'privacy_version' => $clean[ self::PRIVACY_NOTICE_VERSION ],
                    'paused'          => $clean[ self::NOTIFICATIONS_PAUSED ] === '1',
                ]
            );
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function load(): array {
        if ( self::$cache !== null ) {
            return self::$cache;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'grants_settings';
        // No user input in this query.
        $rows = $wpdb->get_results( "SELECT setting_key, setting_value FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        self::$cache = [];
        foreach ( (array) $rows as $row ) {
            if ( array_key_exists( $row->setting_key, self::DEFAULTS ) ) {
                self::$cache[ $row->setting_key ] = (string) $row->setting_value;
            }
        }
        return self::$cache;
    }
}
