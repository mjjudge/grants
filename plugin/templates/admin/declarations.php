<?php
/**
 * Admin template — conflicts of interest for a round.
 *
 * Available variables:
 *   $rounds       object[]            All rounds (for the selector).
 *   $round        object|null         Selected round.
 *   $is_committee bool                User is a committee member (may declare).
 *   $applications object[]            Round's applications (duplicates hidden).
 *   $mine         array<int, object>  Application id => user's current declaration row.
 *   $register     object[]|null       Every declaration in the round (chair only), else null.
 *   $notice       string              'declared', 'partial' or ''.
 *   $errors       array<int,string>   Application id => error from the last bulk save.
 *   $nonce_action string              Nonce action.
 *   $nonce_field  string              Nonce field name.
 *   $apps_url     string              Applications screen URL.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\ConflictService;
use Rotary\Grants\Support\SiteTime;
?>
<div class="wrap grants-admin">
    <h1><?php esc_html_e( 'Conflicts of interest', 'rotary-grants' ); ?></h1>

    <form method="get" class="grants-filters">
        <input type="hidden" name="page" value="grants-declarations">
        <label for="grants-decl-round"><?php esc_html_e( 'Round', 'rotary-grants' ); ?></label>
        <select id="grants-decl-round" name="round_id" onchange="this.form.submit()">
            <?php foreach ( $rounds as $r ) : ?>
                <option value="<?php echo esc_attr( (string) $r->id ); ?>" <?php selected( $round->id ?? 0, $r->id ); ?>><?php echo esc_html( $r->fund_name . ' — ' . $r->label ); ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="button"><?php esc_html_e( 'Show', 'rotary-grants' ); ?></button></noscript>
    </form>

    <?php if ( $notice === 'declared' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Declarations recorded.', 'rotary-grants' ); ?></p></div>
    <?php elseif ( $errors ) : ?>
        <div class="notice notice-error"><p><?php esc_html_e( 'Some declarations were not recorded — see the messages below.', 'rotary-grants' ); ?></p></div>
    <?php endif; ?>

    <?php if ( ! $round ) : ?>
        <p><?php esc_html_e( 'No funding rounds yet.', 'rotary-grants' ); ?></p>
    <?php else : ?>

        <?php if ( $is_committee ) : ?>
            <h2><?php esc_html_e( 'My declarations', 'rotary-grants' ); ?></h2>
            <p><?php esc_html_e( 'Declare any conflict before the committee starts reviewing (Rotary International\'s grants policy asks for this before selection begins). A conflict exists if a decision could benefit you, your family or your business, or an organisation where you hold a paid or voluntary leadership or advisory role. If you are unsure, declare it. A declared conflict cannot be withdrawn later.', 'rotary-grants' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="grants_declare_round">
                <input type="hidden" name="round_id" value="<?php echo esc_attr( (string) $round->id ); ?>">
                <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th scope="col"><?php esc_html_e( 'Application', 'rotary-grants' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Current', 'rotary-grants' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Declare', 'rotary-grants' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Description (required for a conflict)', 'rotary-grants' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if ( ! $applications ) : ?>
                        <tr><td colspan="4"><?php esc_html_e( 'No applications in this round yet.', 'rotary-grants' ); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ( $applications as $a ) :
                        $cur    = $mine[ $a->id ] ?? null;
                        $status = $cur ? $cur->declaration : ConflictService::UNDECLARED;
                        $locked = $cur && $status !== ConflictService::NONE;
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $a->id ], $apps_url ) ); ?>"><?php echo esc_html( $a->public_reference ); ?></a><br><?php echo esc_html( $a->organisation_name . ( $a->organisation_town ? ' — ' . $a->organisation_town : '' ) ); ?>
                                <?php if ( isset( $errors[ $a->id ] ) ) : ?><p class="grants-field-error"><?php echo esc_html( $errors[ $a->id ] ); ?></p><?php endif; ?></td>
                            <td><span class="grants-decl grants-decl--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ConflictService::label( $status ) ); ?></span>
                                <?php if ( $cur && $cur->description ) : ?><br><span class="description"><?php echo esc_html( $cur->description ); ?></span><?php endif; ?></td>
                            <td>
                                <?php if ( $locked ) : ?>
                                    <span class="description"><?php esc_html_e( 'Recorded — cannot be changed', 'rotary-grants' ); ?></span>
                                <?php else : ?>
                                    <select name="declaration[<?php echo esc_attr( (string) $a->id ); ?>]" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: reference */ __( 'Declaration for %s', 'rotary-grants' ), $a->public_reference ) ); ?>">
                                        <option value=""><?php echo $cur ? esc_html__( '— keep —', 'rotary-grants' ) : esc_html__( '— not yet —', 'rotary-grants' ); ?></option>
                                        <?php if ( ! $cur ) : ?><option value="none"><?php esc_html_e( 'No conflict', 'rotary-grants' ); ?></option><?php endif; ?>
                                        <option value="financial"><?php esc_html_e( 'Financial conflict', 'rotary-grants' ); ?></option>
                                        <option value="loyalty"><?php esc_html_e( 'Loyalty conflict', 'rotary-grants' ); ?></option>
                                    </select>
                                <?php endif; ?>
                            </td>
                            <td><?php if ( ! $locked ) : ?><input type="text" class="large-text" name="description[<?php echo esc_attr( (string) $a->id ); ?>]" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: reference */ __( 'Description for %s', 'rotary-grants' ), $a->public_reference ) ); ?>"><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button( __( 'Record my declarations', 'rotary-grants' ) ); ?>
            </form>
        <?php endif; ?>

        <?php if ( $register !== null ) : ?>
            <h2><?php esc_html_e( 'Conflicts register (all declarations this round)', 'rotary-grants' ); ?></h2>
            <p class="description"><?php esc_html_e( 'The record of who declared what and when, for the minutes. Conflicted members are automatically excluded from reviewing and deciding those applications.', 'rotary-grants' ); ?></p>
            <table class="widefat striped">
                <thead><tr>
                    <th scope="col"><?php esc_html_e( 'When', 'rotary-grants' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Member', 'rotary-grants' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Application', 'rotary-grants' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Declaration', 'rotary-grants' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Description', 'rotary-grants' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( ! $register ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No declarations yet.', 'rotary-grants' ); ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $register as $row ) : ?>
                    <tr class="<?php echo $row->declaration !== 'none' ? 'grants-row-conflict' : ''; ?>">
                        <td><?php echo esc_html( SiteTime::display( $row->declared_at ) ); ?></td>
                        <td><?php echo esc_html( (string) $row->display_name ); ?></td>
                        <td><?php echo esc_html( $row->public_reference . ' — ' . $row->organisation_name ); ?></td>
                        <td><?php echo esc_html( ConflictService::label( $row->declaration ) ); ?></td>
                        <td><?php echo esc_html( (string) $row->description ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>
