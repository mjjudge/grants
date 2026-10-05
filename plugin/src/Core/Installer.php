<?php

namespace Rotary\Grants\Core;

defined( 'ABSPATH' ) || exit;

class Installer {

    public static function activate(): void {
        Roles::register();
        ( new \Rotary\Grants\Database\Migrator() )->run();
        \Rotary\Grants\Audit\AuditLogger::record( 'plugin_activated', 'plugin', null, [ 'version' => GRANTS_VERSION ] );
    }

    public static function deactivate(): void {
        // Capabilities, tables and settings are all kept on deactivate so a
        // deactivate/reactivate cycle (needed to run migrations) loses nothing.
        // There is no uninstall routine yet — see backlog/DECISIONS.md DEC-008.
        // Queued emails stay queued; only the cron trigger is removed (it is
        // re-created on the next page load after reactivation).
        \Rotary\Grants\Services\NotificationService::unschedule_cron();
        \Rotary\Grants\Services\RetentionService::unschedule_cron();
        \Rotary\Grants\Audit\AuditLogger::record( 'plugin_deactivated', 'plugin', null, [ 'version' => GRANTS_VERSION ] );
    }
}
