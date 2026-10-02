<?php
/**
 * Public template — grant application form.
 *
 * Available variables:
 *   $round          object                Hydrated grants_rounds row that is accepting applications.
 *   $values         array<string,string>  Previously submitted raw values (empty on first view).
 *   $errors         array<string,string>  Field name => error message.
 *   $message        string                Form-level message (e.g. expired form), or ''.
 *   $labels         array<string,string>  Field name => label (ApplicationForm::labels()).
 *   $help_email     string                Help/contact email from Settings.
 *   $privacy_url    string                Privacy notice URL from Settings.
 *   $cap_display    string                Formatted maximum award, or '' if none.
 *   $closes_display string                Closing date/time in site time.
 *   $action_url     string                URL to POST to (this page).
 *   $hidden         array<string,string>  Hidden token fields (round id, submission key, form token).
 *   $nonce_action   string                Nonce action.
 *   $nonce_field    string                Nonce field name.
 *   $honeypot       string                Honeypot field name.
 *   $max_lengths    array<string,int>     Field name => maximum characters (0 = n/a).
 *   $text           array<string,string>  Fixed wording: declaration, exclusions_ack, publicity_ack,
 *                                         presentation_ack, privacy_ack, opt_in.
 */
defined( 'ABSPATH' ) || exit;

$v = static fn( string $f ): string => (string) ( $values[ $f ] ?? '' );

$described = static function ( string $f, string $hint = '' ) use ( $errors ): string {
    $ids = [];
    if ( $hint !== '' ) {
        $ids[] = 'grants-' . $f . '-hint';
    }
    if ( isset( $errors[ $f ] ) ) {
        $ids[] = 'grants-' . $f . '-error';
    }
    return $ids ? ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"' : '';
};

$feedback = static function ( string $f, string $hint = '' ) use ( $errors ): void {
    if ( $hint !== '' ) {
        printf( '<p class="grants-hint" id="%s">%s</p>', esc_attr( 'grants-' . $f . '-hint' ), esc_html( $hint ) );
    }
    if ( isset( $errors[ $f ] ) ) {
        printf( '<p class="grants-error" id="%s"><span class="screen-reader-text">%s </span>%s</p>', esc_attr( 'grants-' . $f . '-error' ), esc_html__( 'Error:', 'rotary-grants' ), esc_html( $errors[ $f ] ) );
    }
};

/**
 * Text, email, tel, url or textarea field.
 */
$input = static function ( string $f, string $type = 'text', bool $required = true, string $hint = '', string $autocomplete = '' ) use ( $v, $labels, $errors, $described, $feedback, $max_lengths ): void {
    $id    = 'grants-' . $f;
    $max   = (int) ( $max_lengths[ $f ] ?? 0 );
    $attrs = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $f ) . '"' . $described( $f, $hint )
        . ( $max ? ' maxlength="' . $max . '"' : '' )
        . ( $required ? ' aria-required="true"' : '' )
        . ( isset( $errors[ $f ] ) ? ' aria-invalid="true"' : '' )
        . ( $autocomplete ? ' autocomplete="' . esc_attr( $autocomplete ) . '"' : '' );
    echo '<div class="grants-field' . ( isset( $errors[ $f ] ) ? ' grants-field--error' : '' ) . '">';
    printf(
        '<label for="%s">%s%s</label>',
        esc_attr( $id ),
        esc_html( $labels[ $f ] ),
        $required ? '' : ' <span class="grants-optional">' . esc_html__( '(optional)', 'rotary-grants' ) . '</span>'
    );
    $feedback( $f, $hint );
    if ( $type === 'textarea' ) {
        echo '<textarea rows="5"' . $attrs . '>' . esc_textarea( $v( $f ) ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput -- attributes escaped above
    } else {
        echo '<input type="' . esc_attr( $type ) . '" value="' . esc_attr( $v( $f ) ) . '"' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    echo '</div>';
};

/**
 * Required radio question.
 *
 * @param array<string,string> $options value => label
 */
$radios = static function ( string $f, array $options, string $hint = '' ) use ( $v, $labels, $errors, $described, $feedback ): void {
    echo '<div class="grants-field' . ( isset( $errors[ $f ] ) ? ' grants-field--error' : '' ) . '" id="grants-' . esc_attr( $f ) . '">';
    echo '<fieldset' . $described( $f, $hint ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
    echo '<legend>' . esc_html( $labels[ $f ] ) . '</legend>';
    $feedback( $f, $hint );
    $first = true;
    foreach ( $options as $value => $label ) {
        $id = 'grants-' . $f . '-' . $value;
        printf(
            '<label class="grants-choice" for="%1$s"><input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s> %6$s</label>',
            esc_attr( $id ),
            esc_attr( $f ),
            esc_attr( $value ),
            checked( $v( $f ), $value, false ),
            $first && isset( $errors[ $f ] ) ? ' aria-invalid="true"' : '',
            esc_html( $label )
        );
        $first = false;
    }
    echo '</fieldset></div>';
};

$checkbox = static function ( string $f, string $label ) use ( $v, $errors, $described, $feedback ): void {
    $id = 'grants-' . $f;
    echo '<div class="grants-field grants-field--checkbox' . ( isset( $errors[ $f ] ) ? ' grants-field--error' : '' ) . '">';
    $feedback( $f );
    printf(
        '<label class="grants-choice" for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s%4$s%5$s> %6$s</label>',
        esc_attr( $id ),
        esc_attr( $f ),
        checked( $v( $f ), '1', false ),
        isset( $errors[ $f ] ) ? ' aria-invalid="true"' : '',
        $described( $f ), // phpcs:ignore WordPress.Security.EscapeOutput
        esc_html( $label )
    );
    echo '</div>';
};

$yes_no = [ 'yes' => __( 'Yes', 'rotary-grants' ), 'no' => __( 'No', 'rotary-grants' ) ];
$wording = static fn( ?string $html ): string => wpautop( wp_kses_post( (string) $html ) );
?>
<div class="grants-apply">
    <h2 class="grants-apply__title">
        <?php
        /* translators: %s: fund name */
        echo esc_html( sprintf( __( 'Apply for %s funding', 'rotary-grants' ), $round->fund_name ) );
        ?>
    </h2>

    <dl class="grants-apply__facts">
        <dt><?php esc_html_e( 'Funding round', 'rotary-grants' ); ?></dt>
        <dd><?php echo esc_html( $round->label ); ?></dd>
        <dt><?php esc_html_e( 'Closing date', 'rotary-grants' ); ?></dt>
        <dd><?php echo esc_html( $closes_display ); ?></dd>
        <?php if ( $help_email !== '' ) : ?>
            <dt><?php esc_html_e( 'Questions', 'rotary-grants' ); ?></dt>
            <dd><a href="<?php echo esc_url( 'mailto:' . $help_email ); ?>"><?php echo esc_html( $help_email ); ?></a></dd>
        <?php endif; ?>
    </dl>

    <?php if ( trim( (string) $round->intro_text ) !== '' ) : ?>
        <div class="grants-apply__intro"><?php echo $wording( $round->intro_text ); // phpcs:ignore WordPress.Security.EscapeOutput -- wp_kses_post ?></div>
    <?php endif; ?>

    <?php if ( $errors || $message !== '' ) : ?>
        <div class="grants-error-summary" id="grants-error-summary" role="alert" tabindex="-1">
            <h3><?php echo $errors ? esc_html__( 'There is a problem with your application', 'rotary-grants' ) : esc_html__( 'Your application has not been sent yet', 'rotary-grants' ); ?></h3>
            <?php if ( $message !== '' ) : ?>
                <p><?php echo esc_html( $message ); ?></p>
            <?php endif; ?>
            <?php if ( $errors ) : ?>
                <ul>
                    <?php foreach ( $errors as $f => $msg ) : ?>
                        <li><a href="#grants-<?php echo esc_attr( $f ); ?>"><?php echo esc_html( ( $labels[ $f ] ?? $f ) . ': ' . $msg ); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <script>(function(){var s=document.getElementById('grants-error-summary');if(s){s.focus();}})();</script>
    <?php endif; ?>

    <section class="grants-apply__rules" aria-labelledby="grants-rules-heading">
        <h3 id="grants-rules-heading"><?php esc_html_e( 'Who can apply', 'rotary-grants' ); ?></h3>
        <?php echo $wording( $round->eligibility_text ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <h3><?php esc_html_e( 'What we cannot fund', 'rotary-grants' ); ?></h3>
        <?php echo $wording( $round->exclusions_text ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    </section>

    <form method="post" action="<?php echo esc_url( $action_url ); ?>" class="grants-apply__form" novalidate>
        <?php wp_nonce_field( $nonce_action, $nonce_field, false ); ?>
        <?php foreach ( $hidden as $name => $value ) : ?>
            <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
        <?php endforeach; ?>

        <div class="grants-hp" aria-hidden="true">
            <label for="grants-hp"><?php esc_html_e( 'Leave this field empty', 'rotary-grants' ); ?></label>
            <input type="text" id="grants-hp" name="<?php echo esc_attr( $honeypot ); ?>" value="" tabindex="-1" autocomplete="off">
        </div>

        <fieldset class="grants-section">
            <legend><h3><?php esc_html_e( 'Organisation and contact', 'rotary-grants' ); ?></h3></legend>
            <p><?php esc_html_e( 'Tell us about your organisation and who we should contact about this application.', 'rotary-grants' ); ?></p>
            <?php
            $input( 'organisation_name', 'text', true, '', 'organization' );
            $input( 'charity_number', 'text', false, __( 'Community groups without a charity number are welcome to apply.', 'rotary-grants' ) );
            $input( 'organisation_town', 'text', true, '', 'address-level2' );
            $input( 'organisation_postcode', 'text', true, '', 'postal-code' );
            $input( 'organisation_overview', 'textarea', true, __( 'What your organisation does and who it serves.', 'rotary-grants' ) );
            $input( 'website_url', 'url', false, __( 'Starting with https://', 'rotary-grants' ), 'url' );
            $input( 'facebook_url', 'url', false );
            ?>
            <details class="grants-more"<?php echo ( $v( 'other_social_url_1' ) . $v( 'other_social_label_1' ) . $v( 'other_social_url_2' ) . $v( 'other_social_url_3' ) !== '' || array_intersect_key( $errors, array_flip( [ 'other_social_url_1', 'other_social_url_2', 'other_social_url_3', 'other_social_label_1', 'other_social_label_2', 'other_social_label_3' ] ) ) ) ? ' open' : ''; ?>>
                <summary><?php esc_html_e( 'Other social media (optional, up to three)', 'rotary-grants' ); ?></summary>
                <?php
                for ( $i = 1; $i <= 3; $i++ ) {
                    $input( "other_social_label_$i", 'text', false, $i === 1 ? __( 'For example Instagram or X.', 'rotary-grants' ) : '' );
                    $input( "other_social_url_$i", 'url', false );
                }
                ?>
            </details>
            <?php
            $input( 'contact_name', 'text', true, '', 'name' );
            $input( 'contact_role', 'text', true, '', 'organization-title' );
            $input( 'contact_phone', 'tel', true, '', 'tel' );
            $input( 'contact_email', 'email', true, __( 'We will only use this address about this application.', 'rotary-grants' ), 'email' );
            ?>
        </fieldset>

        <fieldset class="grants-section">
            <legend><h3><?php esc_html_e( 'Your initiative', 'rotary-grants' ); ?></h3></legend>
            <?php
            $input(
                'requested_amount_gbp',
                'text',
                true,
                $cap_display !== ''
                    /* translators: %s: maximum award */
                    ? sprintf( __( 'In pounds, for example 750 or 1,250.50. The most this round can award is %s.', 'rotary-grants' ), $cap_display )
                    : __( 'In pounds, for example 750 or 1,250.50.', 'rotary-grants' )
            );
            $input( 'proposed_use', 'textarea' );
            $input( 'expected_difference', 'textarea' );
            $input( 'local_benefit', 'textarea', true, __( 'Please describe groups of people rather than naming individuals, and do not include medical or other sensitive personal details.', 'rotary-grants' ) );
            $radios( 'one_off_initiative', [ 'yes' => __( 'Yes', 'rotary-grants' ), 'not_sure' => __( 'Not sure', 'rotary-grants' ) ], __( 'This fund supports one-off initiatives rather than recurring running costs.', 'rotary-grants' ) );
            $input( 'one_off_explanation', 'textarea', false, __( 'Only needed if you answered "Not sure".', 'rotary-grants' ) );
            ?>
        </fieldset>

        <fieldset class="grants-section">
            <legend><h3><?php esc_html_e( 'About your organisation', 'rotary-grants' ); ?></h3></legend>
            <p><?php esc_html_e( 'Please answer honestly — a "No" does not automatically rule you out. The committee may ask for evidence later.', 'rotary-grants' ); ?></p>
            <?php
            $radios( 'has_organisation_bank_account', $yes_no, __( 'Do not enter any bank details here. If funding is agreed, our treasurer will confirm them with you separately.', 'rotary-grants' ) );
            $radios( 'has_governing_document', $yes_no );
            $radios( 'locally_led_and_run', $yes_no );
            $input( 'locally_led_explanation', 'textarea', false, __( 'Only needed if you answered "No".', 'rotary-grants' ) );
            $radios( 'has_committee_or_support_group', $yes_no );
            $radios( 'is_local_branch', $yes_no, __( 'National organisations can be supported only through a local group with its own management committee, where the funds definitely go to the local community.', 'rotary-grants' ) );
            $input( 'branch_explanation', 'textarea', false, __( 'Only needed if you answered "Yes".', 'rotary-grants' ) );
            ?>
        </fieldset>

        <fieldset class="grants-section">
            <legend><h3><?php esc_html_e( 'Eligibility and exclusions', 'rotary-grants' ); ?></h3></legend>
            <?php $checkbox( 'exclusions_acknowledged', $text['exclusions_ack'] ); ?>
        </fieldset>

        <?php if ( trim( (string) $round->publicity_text ) !== '' || trim( (string) ( $round->presentation_text ?? '' ) ) !== '' ) : ?>
            <fieldset class="grants-section">
                <legend><h3><?php esc_html_e( 'Publicity', 'rotary-grants' ); ?></h3></legend>
                <?php if ( trim( (string) $round->publicity_text ) !== '' ) : ?>
                    <?php echo $wording( $round->publicity_text ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php $checkbox( 'publicity_acknowledged', $text['publicity_ack'] ); ?>
                <?php endif; ?>
                <?php if ( trim( (string) ( $round->presentation_text ?? '' ) ) !== '' ) : ?>
                    <?php echo $wording( $round->presentation_text ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php $checkbox( 'presentation_acknowledged', $text['presentation_ack'] ); ?>
                <?php endif; ?>
            </fieldset>
        <?php endif; ?>

        <fieldset class="grants-section">
            <legend><h3><?php esc_html_e( 'Future funding rounds', 'rotary-grants' ); ?></h3></legend>
            <?php $checkbox( 'future_round_email_opt_in', $text['opt_in'] ); ?>
        </fieldset>

        <fieldset class="grants-section">
            <legend><h3><?php esc_html_e( 'Privacy', 'rotary-grants' ); ?></h3></legend>
            <p>
                <a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e( 'Read the privacy notice (opens in a new tab)', 'rotary-grants' ); ?>
                </a>
            </p>
            <?php $checkbox( 'privacy_notice_acknowledged', $text['privacy_ack'] ); ?>
        </fieldset>

        <fieldset class="grants-section">
            <legend><h3><?php esc_html_e( 'Applicant declaration', 'rotary-grants' ); ?></h3></legend>
            <p><?php echo esc_html( $text['declaration'] ); ?></p>
            <?php
            $input( 'declaration_name', 'text', true, '', 'name' );
            $checkbox( 'declaration_confirmed', __( 'I confirm the declaration above.', 'rotary-grants' ) );
            ?>
        </fieldset>

        <p><button type="submit" class="grants-submit"><?php esc_html_e( 'Submit application', 'rotary-grants' ); ?></button></p>
    </form>
</div>
