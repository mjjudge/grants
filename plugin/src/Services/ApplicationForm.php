<?php

namespace Rotary\Grants\Services;

use Rotary\Grants\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The public application form: its fields (an allowlist — nothing else in a
 * POST is ever read), fixed declaration wording, server-side validation, and
 * the immutable snapshot stored with each application.
 *
 * Field set and rules: docs/01-requirements-and-form.md, "Field mapping".
 * Bump FORM_VERSION whenever a field or a piece of fixed wording changes, so
 * each application records which version of the form it was submitted on.
 */
final class ApplicationForm {

    public const FORM_VERSION = '1';

    public const YES      = 'yes';
    public const NO       = 'no';
    public const NOT_SURE = 'not_sure';

    /** Single-line text: field => [max length, required]. */
    private const TEXT = [
        'organisation_name'     => [ 200, true ],
        'contact_name'          => [ 150, true ],
        'contact_role'          => [ 150, true ],
        'contact_phone'         => [ 50, true ],
        'contact_email'         => [ 254, true ],
        'charity_number'        => [ 50, false ],
        'organisation_town'     => [ 100, true ],
        'organisation_postcode' => [ 10, true ],
        'other_social_label_1'  => [ 60, false ],
        'other_social_label_2'  => [ 60, false ],
        'other_social_label_3'  => [ 60, false ],
        'declaration_name'      => [ 150, true ],
    ];

    /** Optional http(s) URLs: field => max length. */
    private const URLS = [
        'website_url'        => 500,
        'facebook_url'       => 500,
        'other_social_url_1' => 500,
        'other_social_url_2' => 500,
        'other_social_url_3' => 500,
    ];

    /** Multi-line text: field => [max length, required]. Conditional ones are required by rule below. */
    private const TEXTAREAS = [
        'organisation_overview'   => [ 2000, true ],
        'proposed_use'            => [ 4000, true ],
        'expected_difference'     => [ 3000, true ],
        'local_benefit'           => [ 2000, true ],
        'locally_led_explanation' => [ 2000, false ],
        'branch_explanation'      => [ 2000, false ],
        'one_off_explanation'     => [ 2000, false ],
    ];

    /** Required radio questions: field => allowed values. */
    private const CHOICES = [
        'has_organisation_bank_account'  => [ self::YES, self::NO ],
        'has_governing_document'         => [ self::YES, self::NO ],
        'locally_led_and_run'            => [ self::YES, self::NO ],
        'has_committee_or_support_group' => [ self::YES, self::NO ],
        'is_local_branch'                => [ self::YES, self::NO ],
        'one_off_initiative'             => [ self::YES, self::NOT_SURE ],
    ];

    /** Checkboxes. Publicity/presentation are required only when the round has that wording. */
    private const CHECKBOXES = [
        'exclusions_acknowledged',
        'publicity_acknowledged',
        'presentation_acknowledged',
        'declaration_confirmed',
        'privacy_notice_acknowledged',
        'future_round_email_opt_in',
    ];

    private const MONEY = 'requested_amount_gbp';

    /**
     * Every field name the form accepts.
     *
     * @return string[]
     */
    public static function field_names(): array {
        return array_merge(
            array_keys( self::TEXT ),
            array_keys( self::URLS ),
            array_keys( self::TEXTAREAS ),
            array_keys( self::CHOICES ),
            self::CHECKBOXES,
            [ self::MONEY ]
        );
    }

    /** @return string[] */
    public static function checkbox_names(): array {
        return self::CHECKBOXES;
    }

    public static function max_length( string $field ): int {
        return self::TEXT[ $field ][0] ?? self::URLS[ $field ] ?? self::TEXTAREAS[ $field ][0] ?? 0;
    }

    // =========================================================================
    // Fixed wording (snapshotted with every application)
    // =========================================================================

    public static function declaration_text(): string {
        return __( 'I am authorised to apply on behalf of this organisation. To the best of my knowledge, the information provided is accurate. I understand that submitting an application does not guarantee funding.', 'rotary-grants' );
    }

    public static function exclusions_ack_text(): string {
        return __( 'I have read the eligibility rules and exclusions above, and I understand what this fund will not support.', 'rotary-grants' );
    }

    public static function publicity_ack_text(): string {
        return __( 'I have read and understood the publicity undertaking above.', 'rotary-grants' );
    }

    public static function presentation_ack_text(): string {
        return __( 'I have read and understood the note above about presentations.', 'rotary-grants' );
    }

    public static function privacy_ack_text(): string {
        return __( 'I have read the privacy notice, which explains how the information in this application will be used.', 'rotary-grants' );
    }

    public static function opt_in_text(): string {
        return __( 'Optional: Please email me when future funding rounds open. I can withdraw this permission at any time. Leaving this unticked will not affect this application.', 'rotary-grants' );
    }

    /**
     * Human-readable label for every field (used in error messages and the
     * admin view of a snapshot).
     *
     * @return array<string,string>
     */
    public static function labels(): array {
        return [
            'organisation_name'              => __( 'Organisation name', 'rotary-grants' ),
            'contact_name'                   => __( 'Your name', 'rotary-grants' ),
            'contact_role'                   => __( 'Your position in the organisation', 'rotary-grants' ),
            'contact_phone'                  => __( 'Phone', 'rotary-grants' ),
            'contact_email'                  => __( 'Email', 'rotary-grants' ),
            'charity_number'                 => __( 'Charity number (if any)', 'rotary-grants' ),
            'website_url'                    => __( 'Website', 'rotary-grants' ),
            'facebook_url'                   => __( 'Facebook page', 'rotary-grants' ),
            'other_social_label_1'           => __( 'Other social media 1 — name', 'rotary-grants' ),
            'other_social_url_1'             => __( 'Other social media 1 — link', 'rotary-grants' ),
            'other_social_label_2'           => __( 'Other social media 2 — name', 'rotary-grants' ),
            'other_social_url_2'             => __( 'Other social media 2 — link', 'rotary-grants' ),
            'other_social_label_3'           => __( 'Other social media 3 — name', 'rotary-grants' ),
            'other_social_url_3'             => __( 'Other social media 3 — link', 'rotary-grants' ),
            'organisation_town'              => __( 'Town or village', 'rotary-grants' ),
            'organisation_postcode'          => __( 'Postcode', 'rotary-grants' ),
            'organisation_overview'          => __( 'Short overview of your organisation', 'rotary-grants' ),
            'requested_amount_gbp'           => __( 'Amount requested (£)', 'rotary-grants' ),
            'proposed_use'                   => __( 'What will you use it for?', 'rotary-grants' ),
            'expected_difference'            => __( 'How will it make a difference?', 'rotary-grants' ),
            'local_benefit'                  => __( 'Who in the local community will benefit?', 'rotary-grants' ),
            'has_organisation_bank_account'  => __( 'Does your organisation have a bank account in its own name?', 'rotary-grants' ),
            'has_governing_document'         => __( 'Does your organisation have a governing document (such as a constitution) setting out its objectives?', 'rotary-grants' ),
            'locally_led_and_run'            => __( 'Is your organisation led and run locally?', 'rotary-grants' ),
            'locally_led_explanation'        => __( 'Please explain how your organisation is led and run', 'rotary-grants' ),
            'has_committee_or_support_group' => __( 'Does your organisation have a committee or support group?', 'rotary-grants' ),
            'is_local_branch'                => __( 'Is your organisation a local group or branch of a national organisation?', 'rotary-grants' ),
            'branch_explanation'             => __( 'Please explain your local group\'s own management committee and how the funds will stay in the local community', 'rotary-grants' ),
            'one_off_initiative'             => __( 'Is this request for a one-off initiative (rather than ongoing running costs)?', 'rotary-grants' ),
            'one_off_explanation'            => __( 'Please explain what the funding would pay for and over what period', 'rotary-grants' ),
            'exclusions_acknowledged'        => __( 'Eligibility and exclusions', 'rotary-grants' ),
            'publicity_acknowledged'         => __( 'Publicity', 'rotary-grants' ),
            'presentation_acknowledged'      => __( 'Presentation', 'rotary-grants' ),
            'declaration_name'               => __( 'Your full name (as your declaration)', 'rotary-grants' ),
            'declaration_confirmed'          => __( 'Declaration', 'rotary-grants' ),
            'privacy_notice_acknowledged'    => __( 'Privacy notice', 'rotary-grants' ),
            'future_round_email_opt_in'      => __( 'Future funding rounds', 'rotary-grants' ),
        ];
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * Validate raw (unslashed, otherwise untouched) form strings.
     *
     * Returns clean answers keyed by field (text as text; choices as
     * 'yes'/'no'/'not_sure'; checkboxes as bool; requested_pence as int)
     * and a WP_Error whose codes are field names.
     *
     * @param array<string,string> $raw
     * @return array{0: array<string,mixed>, 1: \WP_Error}
     */
    public static function validate( array $raw, object $round ): array {
        $errors = new \WP_Error();
        $clean  = [];
        $labels = self::labels();

        $required_msg = static fn( string $f ) => sprintf(
            /* translators: %s: field label */
            __( 'Enter %s.', 'rotary-grants' ),
            self::lcfirst_label( $labels[ $f ] )
        );

        foreach ( self::TEXT as $field => [ $max, $required ] ) {
            $value = self::clean_line( (string) ( $raw[ $field ] ?? '' ) );
            $clean[ $field ] = $value;
            if ( self::has_markup( (string) ( $raw[ $field ] ?? '' ) ) ) {
                $errors->add( $field, __( 'Please remove the HTML or < > tags from this answer.', 'rotary-grants' ) );
            } elseif ( $value === '' ) {
                if ( $required ) {
                    $errors->add( $field, $required_msg( $field ) );
                }
            } elseif ( mb_strlen( $value ) > $max ) {
                $errors->add( $field, self::too_long( $max ) );
            }
        }

        foreach ( self::TEXTAREAS as $field => [ $max, $required ] ) {
            $value = self::clean_text( (string) ( $raw[ $field ] ?? '' ) );
            $clean[ $field ] = $value;
            if ( self::has_markup( (string) ( $raw[ $field ] ?? '' ) ) ) {
                $errors->add( $field, __( 'Please remove the HTML or < > tags from this answer.', 'rotary-grants' ) );
            } elseif ( $value === '' ) {
                if ( $required ) {
                    $errors->add( $field, $required_msg( $field ) );
                }
            } elseif ( mb_strlen( $value ) > $max ) {
                $errors->add( $field, self::too_long( $max ) );
            }
        }

        foreach ( self::URLS as $field => $max ) {
            $value           = trim( (string) ( $raw[ $field ] ?? '' ) );
            $clean[ $field ] = '';
            if ( $value === '' ) {
                continue;
            }
            if ( mb_strlen( $value ) > $max ) {
                $errors->add( $field, self::too_long( $max ) );
            } elseif ( ! self::is_http_url( $value ) ) {
                $errors->add( $field, __( 'Enter a full web address starting with https:// or http://.', 'rotary-grants' ) );
            } else {
                $clean[ $field ] = esc_url_raw( $value, [ 'http', 'https' ] );
            }
        }

        foreach ( self::CHOICES as $field => $allowed ) {
            $value           = (string) ( $raw[ $field ] ?? '' );
            $clean[ $field ] = in_array( $value, $allowed, true ) ? $value : '';
            if ( $clean[ $field ] === '' ) {
                $errors->add( $field, __( 'Choose an answer.', 'rotary-grants' ) );
            }
        }

        foreach ( self::CHECKBOXES as $field ) {
            $clean[ $field ] = ( $raw[ $field ] ?? '' ) === '1';
        }

        // --- Field-specific rules ---------------------------------------------

        if ( $clean['contact_email'] !== '' && ! $errors->get_error_message( 'contact_email' ) && ! is_email( $clean['contact_email'] ) ) {
            $errors->add( 'contact_email', __( 'Enter a valid email address, like name@example.org.', 'rotary-grants' ) );
        }

        if ( $clean['contact_phone'] !== '' && ! $errors->get_error_message( 'contact_phone' ) ) {
            $digits = preg_replace( '/\D/', '', $clean['contact_phone'] );
            if ( strlen( $digits ) < 6 || ! preg_match( '/^[0-9+()\-.\s\/]+(?:\s*(?:ext\.?|x)\s*\d+)?$/iu', $clean['contact_phone'] ) ) {
                $errors->add( 'contact_phone', __( 'Enter a phone number using digits, spaces and + ( ) - only.', 'rotary-grants' ) );
            }
        }

        if ( $clean['organisation_postcode'] !== '' && ! $errors->get_error_message( 'organisation_postcode' ) ) {
            $pc = strtoupper( preg_replace( '/\s+/', '', $clean['organisation_postcode'] ) );
            if ( ! preg_match( '/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $pc ) ) {
                $errors->add( 'organisation_postcode', __( 'Enter a UK postcode, like WR11 4AB.', 'rotary-grants' ) );
            } else {
                $clean['organisation_postcode'] = substr( $pc, 0, -3 ) . ' ' . substr( $pc, -3 );
            }
        }

        for ( $i = 1; $i <= 3; $i++ ) {
            if ( $clean[ "other_social_label_$i" ] !== '' && $clean[ "other_social_url_$i" ] === '' && ! $errors->get_error_message( "other_social_url_$i" ) ) {
                $errors->add( "other_social_url_$i", __( 'Add the link, or remove the name.', 'rotary-grants' ) );
            }
        }

        $amount = trim( (string) ( $raw[ self::MONEY ] ?? '' ) );
        $clean['requested_pence'] = null;
        $pence = Money::parse_gbp( $amount );
        if ( is_wp_error( $pence ) ) {
            $errors->add( self::MONEY, $amount === '' ? __( 'Enter the amount you are requesting.', 'rotary-grants' ) : $pence->get_error_message() );
        } elseif ( $pence <= 0 ) {
            $errors->add( self::MONEY, __( 'Enter an amount greater than £0.', 'rotary-grants' ) );
        } elseif ( $round->cap_pence !== null && $pence > $round->cap_pence ) {
            $errors->add( self::MONEY, sprintf(
                /* translators: %s: maximum award, e.g. £1,000.00 */
                __( 'The most this round can award is %s.', 'rotary-grants' ),
                Money::format_gbp( $round->cap_pence )
            ) );
        } else {
            $clean['requested_pence'] = $pence;
        }

        // Explanations that become required by an earlier answer.
        $conditional = [
            'locally_led_explanation' => $clean['locally_led_and_run'] === self::NO,
            'branch_explanation'      => $clean['is_local_branch'] === self::YES,
            'one_off_explanation'     => $clean['one_off_initiative'] === self::NOT_SURE,
        ];
        foreach ( $conditional as $field => $needed ) {
            if ( $needed && $clean[ $field ] === '' && ! $errors->get_error_message( $field ) ) {
                $errors->add( $field, __( 'Please explain — your answer above needs a short explanation.', 'rotary-grants' ) );
            }
        }

        // Required acknowledgements.
        $must_tick = [ 'exclusions_acknowledged', 'declaration_confirmed', 'privacy_notice_acknowledged' ];
        if ( trim( (string) $round->publicity_text ) !== '' ) {
            $must_tick[] = 'publicity_acknowledged';
        } else {
            $clean['publicity_acknowledged'] = false;
        }
        if ( trim( (string) ( $round->presentation_text ?? '' ) ) !== '' ) {
            $must_tick[] = 'presentation_acknowledged';
        } else {
            $clean['presentation_acknowledged'] = false;
        }
        foreach ( $must_tick as $field ) {
            if ( ! $clean[ $field ] ) {
                $errors->add( $field, __( 'Tick this box to confirm.', 'rotary-grants' ) );
            }
        }

        return [ $clean, $errors ];
    }

    // =========================================================================
    // Snapshot and fingerprint
    // =========================================================================

    /**
     * The immutable record stored with the application: answers plus every
     * piece of wording and every version the applicant was shown.
     *
     * @param array<string,mixed> $clean
     * @return array<string,mixed>
     */
    public static function snapshot( array $clean, object $round, string $submitted_at_utc ): array {
        $settings = new SettingsService();
        ksort( $clean );
        return [
            'form_version'   => self::FORM_VERSION,
            'policy_version' => (string) $round->policy_version,
            'submitted_at'   => $submitted_at_utc,
            'round'          => [
                'id'            => (int) $round->id,
                'label'         => $round->label,
                'fund_name'     => $round->fund_name,
                'campaign_year' => (int) $round->campaign_year,
            ],
            'answers'        => $clean,
            'wording'        => [
                'intro'          => (string) $round->intro_text,
                'eligibility'    => (string) $round->eligibility_text,
                'exclusions'     => (string) $round->exclusions_text,
                'publicity'      => (string) $round->publicity_text,
                'presentation'   => (string) ( $round->presentation_text ?? '' ),
                'exclusions_ack' => self::exclusions_ack_text(),
                'publicity_ack'  => self::publicity_ack_text(),
                'presentation_ack' => self::presentation_ack_text(),
                'declaration'    => self::declaration_text(),
                'privacy_ack'    => self::privacy_ack_text(),
                'opt_in'         => self::opt_in_text(),
            ],
            'privacy_notice' => [
                'url'     => $settings->get( SettingsService::PRIVACY_NOTICE_URL ),
                'version' => $settings->get( SettingsService::PRIVACY_NOTICE_VERSION ),
            ],
        ];
    }

    /**
     * Fingerprint of a submission's answers for a given round — two requests
     * with the same submission key must carry the same fingerprint.
     *
     * @param array<string,mixed> $clean
     */
    public static function payload_hash( array $clean, int $round_id ): string {
        ksort( $clean );
        return hash( 'sha256', $round_id . '|' . wp_json_encode( $clean ) );
    }

    // =========================================================================

    /*
     * Answers are stored as plain text and escaped on output. Markup is
     * rejected outright by has_markup(), so WordPress's sanitize_*_field()
     * (which would store a lone "<" as "&lt;") is not used here.
     */

    private static function clean_line( string $value ): string {
        $value = wp_check_invalid_utf8( $value );
        return trim( (string) preg_replace( '/[\s\x00-\x1F\x7F]+/u', ' ', $value ) );
    }

    private static function clean_text( string $value ): string {
        $value = str_replace( [ "\r\n", "\r" ], "\n", wp_check_invalid_utf8( $value ) );
        $value = (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/u', '', $value );
        return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $value ) );
    }

    /** Something that looks like an HTML tag or comment — "5 < 10" is fine. */
    private static function has_markup( string $value ): bool {
        return (bool) preg_match( '/<\s*[a-z!\/?]/i', $value );
    }

    private static function is_http_url( string $value ): bool {
        if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
            return false;
        }
        $scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
        $host   = (string) wp_parse_url( $value, PHP_URL_HOST );
        return in_array( $scheme, [ 'http', 'https' ], true ) && $host !== '';
    }

    private static function too_long( int $max ): string {
        /* translators: %d: maximum characters */
        return sprintf( __( 'Please shorten this to %d characters or fewer.', 'rotary-grants' ), $max );
    }

    private static function lcfirst_label( string $label ): string {
        // "Your name" → "your name"; leave acronyms/proper nouns that start a label alone.
        return preg_match( '/^[A-Z][a-z]/', $label ) ? lcfirst( $label ) : $label;
    }
}
