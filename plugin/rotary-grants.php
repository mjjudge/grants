<?php
/**
 * Plugin Name: Rotary Grants
 * Description: Grant application, decision, and payment record-keeping for Rotary in the Vale funding rounds — not tied to any single campaign (Tree of Light or otherwise).
 * Version:     0.7.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author:      Rotary in the Vale
 * License:     GPL-2.0-or-later
 * Text Domain: rotary-grants
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'GRANTS_VERSION',     '0.7.0' );
define( 'GRANTS_PLUGIN_FILE', __FILE__ );
define( 'GRANTS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'GRANTS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Autoloader — maps Rotary\Grants\Foo\Bar to plugin/src/Foo/Bar.php
spl_autoload_register( static function ( string $class ): void {
    if ( ! str_starts_with( $class, 'Rotary\\Grants\\' ) ) {
        return;
    }
    $file = GRANTS_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', substr( $class, 14 ) ) . '.php';
    if ( is_readable( $file ) ) {
        require $file;
    }
} );

register_activation_hook( __FILE__,   [ Rotary\Grants\Core\Installer::class, 'activate'   ] );
register_deactivation_hook( __FILE__, [ Rotary\Grants\Core\Installer::class, 'deactivate' ] );

add_action( 'plugins_loaded', static function (): void {
    ( new Rotary\Grants\Core\Plugin() )->boot();
} );
