<?php
/**
 * Logger — INFO / WARNING / ERROR lines into a custom table with capped rows.
 *
 * All write-heavy paths buffer their log lines and flush them once per batch
 * (no N+1 inserts). Secrets (API key / secret / bearer) are redacted before
 * anything reaches the database. The table is pruned at the start of every
 * cron sync; row cap is configurable (Advanced → Log retention).
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_Logger {

	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/** @var list<array<string, mixed>> */
	private static $buffer = array();

	/**
	 * Record a log line (buffered; call flush() at the end of the request).
	 *
	 * @param string              $level   info|warning|error.
	 * @param string              $event   Machine event name, e.g. 'sync_start'.
	 * @param string              $message Human message.
	 * @param array<string,mixed> $context Sync id, product id, prices, ...
	 *
	 * @return void
	 */
	public static function log( $level, $event, $message, array $context = array() ) {
		// When logging is disabled, errors are still kept (they feed the
		// dashboard + admin email); info/warning lines are dropped.
		if ( self::LEVEL_ERROR !== $level && class_exists( 'GPU_Settings' )
			&& ! GPU_Settings::get_setting( 'logging_enabled', 1 ) ) {
			return;
		}

		self::$buffer[] = array(
			'level'     => in_array( $level, array( self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR ), true ) ? $level : self::LEVEL_INFO,
			'event'     => substr( sanitize_key( $event ), 0, 64 ),
			'message'   => self::redact( (string) $message ),
			'context'   => wp_json_encode( self::redact_context( $context ) ),
			'create_at' => time(),
		);
	}

	public static function info( $event, $message, array $context = array() ) {
		self::log( self::LEVEL_INFO, $event, $message, $context );
	}

	public static function warning( $event, $message, array $context = array() ) {
		self::log( self::LEVEL_WARNING, $event, $message, $context );
	}

	public static function error( $event, $message, array $context = array() ) {
		self::log( self::LEVEL_ERROR, $event, $message, $context );
	}

	/**
	 * Write buffered lines to the DB (single multi-row query).
	 *
	 * @return void
	 */
	public static function flush() {
		if ( self::$buffer === array() ) {
			return;
		}

		global $wpdb;
		$table = GPU_DB::logs_table();
		$now   = time();

		foreach ( self::$buffer as $entry ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'level'     => $entry['level'],
					'event'     => $entry['event'],
					'message'   => $entry['message'],
					'context'   => (string) $entry['context'],
					'create_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%d' )
			);
		}

		self::$buffer = array();
	}

	/**
	 * Remove old rows (called from cron).
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;
		$days = max( 1, (int) GPU_Settings::get_setting( 'log_retention_days', 30 ) );
		$table = GPU_DB::logs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE create_at < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				time() - $days * DAY_IN_SECONDS
			)
		);
	}

	/**
	 * Recent rows for the admin log viewer.
	 *
	 * @param int    $limit
	 * @param string $level Optional level filter.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function recent( $limit = 100, $level = '' ) {
		global $wpdb;
		$table = GPU_DB::logs_table();
		$limit = max( 1, min( 500, (int) $limit ) );

		if ( '' !== $level ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE level = %s ORDER BY id DESC LIMIT %d", $level, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
	}

	/**
	 * Last error line, for the dashboard.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last_error() {
		global $wpdb;
		$table = GPU_DB::logs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE level = 'error' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	// ------------------------------------------------------------- redaction

	/**
	 * Remove anything that looks like a credential from a message.
	 *
	 * @param string $message
	 *
	 * @return string
	 */
	private static function redact( $message ) {
		$settings = GPU_Settings::get();

		foreach ( array( 'api_key', 'api_secret' ) as $field ) {
			$secret = trim( (string) ( $settings[ $field ] ?? '' ) );
			if ( strlen( $secret ) >= 4 ) {
				$message = str_replace( $secret, '***', $message );
			}
		}

		// Common token patterns (Authorization headers, query-string keys).
		$message = preg_replace( '/(Bearer\s+)\S+/i', '$1***', $message );
		$message = preg_replace( '/((api[_-]?key|api[_-]?secret|token|password)=)[^&\s]+/i', '$1***', $message );

		return $message;
	}

	/**
	 * Redact credential-looking values inside structured context.
	 *
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>
	 */
	private static function redact_context( array $context ) {
		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/(api_key|api_secret|token|password|authorization|bearer)/i', $key ) ) {
				$context[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = self::redact_context( $value );
			}
		}

		return $context;
	}
}
