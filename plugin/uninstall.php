<?php
/**
 * Rotary Grants — uninstall.
 *
 * Deliberately keeps every table, setting and capability (docs/04:
 * "Uninstall retains records by default"). Grant applications, decisions,
 * awards and payments are financial and governance records; removing them
 * needs a backup and an authorised, documented procedure — see
 * deployment-notes/UNINSTALL_AND_ERASE.md in the plugin's repository.
 *
 * Only the scheduled jobs are removed so nothing runs without the plugin.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'grants_process_notifications' );
wp_clear_scheduled_hook( 'grants_daily_housekeeping' );
