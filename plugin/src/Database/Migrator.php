<?php

namespace Rotary\Grants\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Runs schema migrations. Mirrors Tree of Light's Migrator.
 *
 * Only ever called from the activation hook (Core\Installer::activate) — it
 * does NOT run on an ordinary file update, so every release that adds a
 * migration needs a deactivate/reactivate cycle on the live site.
 */
class Migrator {

    private const DB_VERSION_OPTION = 'grants_db_version';

    /**
     * Ordered list of migrations. Each key is the plugin version that
     * introduced them; each class is exactly one schema change. A list per
     * version (rather than one class per key) lets a single release add more
     * than one table — see backlog/DECISIONS.md DEC-007.
     *
     * @var array<string, list<class-string<MigrationInterface>>>
     */
    private const MIGRATIONS = [
        '0.2.0' => [
            Migrations\CreateAuditEventsTable::class,
            Migrations\CreateSettingsTable::class,
        ],
        '0.3.0' => [
            Migrations\CreateRoundsTable::class,
        ],
        '0.4.0' => [
            Migrations\AddRoundPresentationText::class,
            Migrations\CreateApplicationsTable::class,
            Migrations\CreateSubmissionsTable::class,
        ],
    ];

    public function run(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $installed = self::installed_version();

        foreach ( self::MIGRATIONS as $version => $classes ) {
            if ( version_compare( $installed, $version, '<' ) ) {
                foreach ( $classes as $class ) {
                    ( new $class() )->up();
                }
                update_option( self::DB_VERSION_OPTION, $version, true );
                \Rotary\Grants\Audit\AuditLogger::record(
                    'migration_applied',
                    'schema',
                    null,
                    [ 'version' => $version, 'classes' => array_map( static fn( $c ) => substr( strrchr( $c, '\\' ), 1 ), $classes ) ]
                );
            }
        }

        // Always stamp the current plugin version so we know what's installed.
        update_option( self::DB_VERSION_OPTION, GRANTS_VERSION, true );
    }

    public static function installed_version(): string {
        return (string) get_option( self::DB_VERSION_OPTION, '0.0.0' );
    }
}
