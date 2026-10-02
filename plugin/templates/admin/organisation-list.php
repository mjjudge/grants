<?php
/**
 * Admin template — organisation list.
 *
 * Available variables:
 *   $organisations object[]  Active organisations (with application_count).
 *   $query         string    Current search text.
 *   $can_manage    bool      User may add organisations.
 *   $base_url      string    List URL.
 *   $add_url       string    Add-organisation URL.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap grants-admin">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Organisations', 'rotary-grants' ); ?></h1>
    <?php if ( $can_manage ) : ?>
        <a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add Organisation', 'rotary-grants' ); ?></a>
    <?php endif; ?>
    <hr class="wp-header-end">

    <form method="get" class="grants-filters">
        <input type="hidden" name="page" value="grants-organisations">
        <label for="grants-org-search" class="screen-reader-text"><?php esc_html_e( 'Search organisations', 'rotary-grants' ); ?></label>
        <input type="search" id="grants-org-search" name="q" value="<?php echo esc_attr( $query ); ?>" placeholder="<?php esc_attr_e( 'Name, charity number or town', 'rotary-grants' ); ?>">
        <button type="submit" class="button"><?php esc_html_e( 'Search', 'rotary-grants' ); ?></button>
    </form>

    <table class="widefat striped">
        <thead><tr>
            <th scope="col"><?php esc_html_e( 'Organisation', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Charity no.', 'rotary-grants' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Town', 'rotary-grants' ); ?></th>
            <th scope="col" class="num"><?php esc_html_e( 'Applications', 'rotary-grants' ); ?></th>
        </tr></thead>
        <tbody>
            <?php if ( ! $organisations ) : ?>
                <tr><td colspan="4"><?php esc_html_e( 'No organisations found. Organisations are created when staff link an application, or added here.', 'rotary-grants' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $organisations as $o ) : ?>
                <tr>
                    <td><a href="<?php echo esc_url( add_query_arg( [ 'action' => 'view', 'id' => $o->id ], $base_url ) ); ?>"><strong><?php echo esc_html( $o->name ); ?></strong></a></td>
                    <td><?php echo esc_html( $o->charity_number ?: '—' ); ?></td>
                    <td><?php echo esc_html( $o->town ?: '—' ); ?></td>
                    <td class="num"><?php echo esc_html( (string) $o->application_count ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
