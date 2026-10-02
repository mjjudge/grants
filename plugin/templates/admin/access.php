<?php
/**
 * Admin template — who holds Rotary Grants capabilities (read-only).
 *
 * Available variables:
 *   $holders list<array{user: WP_User, direct: string[], via_role: string[]}>
 *            Every account holding any grants_* capability.
 *   $labels  array<string,string>  Capability => human-readable label.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap grants-admin">
    <h1><?php esc_html_e( 'Rotary Grants Access', 'rotary-grants' ); ?></h1>

    <p><?php esc_html_e( 'Every account that holds a Rotary Grants capability. Administrators hold all of them through their role. To grant or remove access for anyone else, edit their user profile and use the "Rotary Grants access" section.', 'rotary-grants' ); ?></p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e( 'User', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'WordPress role(s)', 'rotary-grants' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Capabilities', 'rotary-grants' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! $holders ) : ?>
                <tr><td colspan="3"><?php esc_html_e( 'No accounts hold a Rotary Grants capability.', 'rotary-grants' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $holders as $row ) : ?>
                <?php $user = $row['user']; ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url( get_edit_user_link( $user->ID ) . '#grants-access' ); ?>"><?php echo esc_html( $user->display_name ); ?></a>
                        <br><span class="description"><?php echo esc_html( $user->user_login ); ?></span>
                    </td>
                    <td><?php echo esc_html( implode( ', ', array_map( 'translate_user_role', array_map( static fn( $r ) => wp_roles()->role_names[ $r ] ?? $r, (array) $user->roles ) ) ) ); ?></td>
                    <td>
                        <ul class="grants-cap-list">
                            <?php foreach ( $labels as $cap => $label ) : ?>
                                <?php
                                $by_role = in_array( $cap, $row['via_role'], true );
                                $direct  = in_array( $cap, $row['direct'], true );
                                if ( ! $by_role && ! $direct ) {
                                    continue;
                                }
                                ?>
                                <li>
                                    <code><?php echo esc_html( $cap ); ?></code>
                                    <?php if ( $by_role ) : ?>
                                        <span class="description"><?php esc_html_e( '(via role)', 'rotary-grants' ); ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
