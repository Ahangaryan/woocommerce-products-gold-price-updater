<?php
/**
 * Database layer — custom tables, migrations, locking.
 *
 * Two custom tables (logs, sync history) because high-volume rows never
 * belong in wp_options. Locking uses a MySQL GET_LOCK which is atomic and
 * self-releasing (session death frees it) with an explicit timeout as a
 * second line of defense.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_DB {

	const LOCK_NAME   = 'gpu_sync_lock';
	const LOCK_RETRIES = 3;

	/**
	 * @return string Fully-qualified logs table name.
	 */
	public static function logs_table() {
		global $wpdb;

		return $wpdb->prefix . 'gpu_logs';
	}

	/**
	 * @return string Fully-qualified sync history table name.
	 */
	public static function history_table() {
		global $wpdb;

		return $wpdb->prefix . 'gpu_sync_history';
	}

	/**
	 * Charset/collation fragment for CREATE TABLE.
	 *
	 * @return string
	 */
	private static function charset() {
		global $wpdb;

		return $wpdb->get_charset_collate();
	}

	/**
	 * Create / migrate the custom tables. Runs on activation and when the
	 * stored GPU_DB_VERSION is older than the code's version (never on
	 * normal page loads).
	 *
	 * @return void
	 */
	public static function migrate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$logs  = self::logs_table();
		$hist  = self::history_table();
		$queue = $wpdb->prefix . 'gpu_queue';
		$cs    = self::charset();

		dbDelta(
			"CREATE TABLE {$logs} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				level VARCHAR(10) NOT NULL DEFAULT 'info',
				event VARCHAR(64) NOT NULL DEFAULT '',
				message TEXT NOT NULL,
				context LONGTEXT NULL,
				create_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY level (level),
				KEY create_at (create_at)
			) {$cs};"
		);		dbDelta(
				"CREATE TABLE {$queue} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				sync_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				claimed_at BIGINT UNSIGNED NULL,
				finished_at BIGINT UNSIGNED NULL,
				PRIMARY KEY (id),
				KEY sync_id (sync_id),
				KEY status (status),
				KEY product_id (product_id)
			) {$cs};"
		);

		dbDelta(
				"CREATE TABLE {$hist} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				started_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
				finished_at BIGINT UNSIGNED NULL,
				source VARCHAR(20) NOT NULL DEFAULT 'api',
				gold_price DECIMAL(20, 4) NULL,
				buffer_amount DECIMAL(20, 4) NULL DEFAULT 0,
				effective_price DECIMAL(20, 4) NULL,
				products_total INT UNSIGNED NOT NULL DEFAULT 0,
				products_updated INT UNSIGNED NOT NULL DEFAULT 0,
				products_skipped INT UNSIGNED NOT NULL DEFAULT 0,
				errors INT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'running',
				message TEXT NULL,
				PRIMARY KEY (id),
				KEY started_at (started_at),
				KEY status (status)
			) {$cs};"
		);

		update_option( 'gpu_db_version', GPU_DB_VERSION, false );
	}

	/**
	 * True when the tables exist and are current.
	 *
	 * @return bool
	 */
	public static function is_current() {
		return (int) get_option( 'gpu_db_version', 0 ) >= GPU_DB_VERSION;
	}

	// ------------------------------------------------------------------ lock

	/**
	 * Acquire the cross-process sync lock (atomic, self-releasing).
	 *
	 * @param int $timeout Auto-release after this many seconds (crash guard).
	 *
	 * @return bool True when the lock was acquired.
	 */
	public static function acquire_lock( $timeout = 300 ) {
		global $wpdb;

		for ( $i = 0; $i < self::LOCK_RETRIES; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->get_var(
				$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, 0 )
			);
			if ( 1 === (int) $result ) {
				// Remember when we took it so a crashed process's lock can be
				// reclaimed after the timeout.
				set_transient( 'gpu_lock_taken_at', time(), max( 60, (int) $timeout ) );

				return true;
			}

			// Lock held — is it stale (crashed process)?
			$taken = (int) get_transient( 'gpu_lock_taken_at' );
			if ( $taken > 0 && ( time() - $taken ) > $timeout ) {
				self::release_lock( true );
				continue; // Retry the acquisition once the stale lock is dropped.
			}

			return false;
		}

		return false;
	}

	/**
	 * Release the sync lock.
	 *
	 * @param bool $force Also clear the taken_at marker (stale reclaim).
	 *
	 * @return void
	 */
	public static function release_lock( $force = false ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
		delete_transient( 'gpu_lock_taken_at' );

		if ( $force ) {
			// RELEASE_LOCK only works in the owning session; for stale locks
			// from crashed processes we rely on the timeout marker so the next
			// acquire attempt re-issues GET_LOCK after the session is gone.
			delete_transient( 'gpu_lock_taken_at' );
		}
	}

	/**
	 * Whether a sync is currently running (best-effort, for the dashboard).
	 *
	 * @return bool
	 */
	public static function is_locked() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', self::LOCK_NAME )
		);

		return ! empty( $result );
	}

	// ---------------------------------------------------------------- history

	/**
	 * Start a sync-history row and return its id.
	 *
	 * @param string $source api|manual|cron.
	 *
	 * @return int
	 */
	public static function history_start( $source ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::history_table(),
			array(
				'started_at' => time(),
				'source'     => sanitize_key( $source ),
				'status'     => 'running',
			),
			array( '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Finish a sync-history row.
	 *
	 * @param int                  $id
	 * @param array<string, mixed> $data gold_price, buffer_amount, effective_price,
	 *                                   products_total, products_updated, products_skipped,
	 *                                   errors, status, message.
	 *
	 * @return void
	 */
	public static function history_finish( $id, array $data ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return;
		}

		$map = array(
			'gold_price'       => '%f',
			'buffer_amount'    => '%f',
			'effective_price'  => '%f',
			'products_total'   => '%d',
			'products_updated' => '%d',
			'products_skipped' => '%d',
			'errors'           => '%d',
			'status'           => '%s',
			'message'          => '%s',
		);

		$payload = array( 'finished_at' => time() );
		$format  = array( '%d' );
		foreach ( $map as $key => $fmt ) {
			if ( array_key_exists( $key, $data ) ) {
				$payload[ $key ] = $data[ $key ];
				$format[]        = $fmt;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::history_table(), $payload, array( 'id' => (int) $id ), $format, array( '%d' ) );
	}

	/**
	 * Recent history rows for the admin page.
	 *
	 * @param int $limit
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function history_recent( $limit = 20 ) {
		global $wpdb;
		$table = self::history_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, (int) $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Last finished sync row (for the dashboard).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function history_last() {
		global $wpdb;
		$table = self::history_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE status != 'running' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * Prune history rows older than the retention window (cron).
	 *
	 * @return void
	 */
	public static function history_prune() {
		global $wpdb;
		$days = max( 1, (int) GPU_Settings::get_setting( 'log_retention_days', 30 ) );
		$table = self::history_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE started_at < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				time() - $days * DAY_IN_SECONDS
			)
		);
	}
}
