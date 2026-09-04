<?php
/**
 * Uninstall cleanup — removes options and custom tables.
 *
 * Only runs when the user deletes the plugin from the Plugins screen;
 * deactivation keeps all data.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'gpu_settings' );
delete_option( 'gpu_state' );
delete_option( 'gpu_api_status' );
delete_option( 'gpu_admin_notice' );
delete_option( 'gpu_db_version' );
delete_transient( 'gpu_lock_taken_at' );
delete_transient( 'gpu_gh_release' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gpu_queue" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gpu_logs" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gpu_sync_history" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
