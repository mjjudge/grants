<?php
/**
 * Portal template — declare conflicts for a whole round.
 *
 * Available variables: common portal vars plus
 *   $round object, $applications object[], $mine array<int,object> (current declaration per application),
 *   $errors array<int,string> per-application errors from the last save.
 */
defined( 'ABSPATH' ) || exit;

use Rotary\Grants\Services\ConflictService;

$crumbs = [ $round->fund_name . ' — ' . $round->label => add_query_arg( [ 'view' => 'round', 'round' => $round->id ], $base ), __( 'Conflicts of interest', 'rotary-grants' ) => '' ];
include __DIR__ . '/_nav.php';
?>
<h2><?php esc_html_e( 'Conflicts of interest', 'rotary-grants' ); ?></h2>
<?php if ( $notice === 'declared' ) : ?><p class="grants-portal__notice" role="status"><?php esc_html_e( 'Declarations recorded.', 'rotary-grants' ); ?></p><?php endif; ?>
<?php if ( $errors ) : ?><p class="grants-portal__error" role="alert"><?php esc_html_e( 'Some declarations were not recorded — see below.', 'rotary-grants' ); ?></p><?php endif; ?>
<p><?php esc_html_e( 'A conflict exists if a decision could benefit you, your family or your business, or an organisation where you hold a paid or voluntary leadership or advisory role (for example trustee or committee member). If you are unsure, declare it. A declared conflict means you won\'t see or take part in the committee\'s discussion of that application, and it can\'t be withdrawn later.', 'rotary-grants' ); ?></p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <input type="hidden" name="action" value="grants_declare_round">
    <input type="hidden" name="round_id" value="<?php echo esc_attr( (string) $round->id ); ?>">
    <input type="hidden" name="grants_return" value="portal">
    <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
    <?php foreach ( $applications as $a ) :
        $cur    = $mine[ $a->id ] ?? null;
        $status = $cur ? $cur->declaration : ConflictService::UNDECLARED;
        $locked = $cur && $status !== ConflictService::NONE;
        ?>
        <fieldset class="grants-portal__declare">
            <legend><strong><?php echo esc_html( $a->organisation_name ); ?></strong> <span class="grants-portal__muted"><?php echo esc_html( $a->public_reference . ( $a->organisation_town ? ' · ' . $a->organisation_town : '' ) ); ?></span></legend>
            <?php if ( isset( $errors[ $a->id ] ) ) : ?><p class="grants-portal__error"><?php echo esc_html( $errors[ $a->id ] ); ?></p><?php endif; ?>
            <?php if ( $locked ) : ?>
                <p><?php echo esc_html( ConflictService::label( $status ) . ( $cur->description ? ': ' . $cur->description : '' ) ); ?> — <?php esc_html_e( 'recorded', 'rotary-grants' ); ?></p>
            <?php else : ?>
                <?php if ( $cur ) : ?><p class="grants-portal__muted"><?php esc_html_e( 'Currently: no conflict', 'rotary-grants' ); ?></p><?php endif; ?>
                <?php foreach ( ( $cur ? [] : [ 'none' => __( 'No conflict', 'rotary-grants' ) ] ) + [ 'financial' => __( 'Financial conflict', 'rotary-grants' ), 'loyalty' => __( 'Loyalty conflict', 'rotary-grants' ) ] as $val => $lab ) : ?>
                    <label class="grants-portal__choice"><input type="radio" name="declaration[<?php echo esc_attr( (string) $a->id ); ?>]" value="<?php echo esc_attr( $val ); ?>"> <?php echo esc_html( $lab ); ?></label>
                <?php endforeach; ?>
                <label class="grants-portal__field"><?php esc_html_e( 'Description (required for a conflict)', 'rotary-grants' ); ?>
                    <input type="text" name="description[<?php echo esc_attr( (string) $a->id ); ?>]"></label>
            <?php endif; ?>
        </fieldset>
    <?php endforeach; ?>
    <?php if ( $applications ) : ?><p><button type="submit" class="grants-portal__button grants-portal__button--primary"><?php esc_html_e( 'Record my declarations', 'rotary-grants' ); ?></button></p><?php endif; ?>
</form>
