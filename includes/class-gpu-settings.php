<?php
/**
 * Settings store, grouped per the spec taxonomy (general, gold_price, pricing,
 * sync, api, notifications, advanced).
 *
 * One autoloaded option (gpu_settings) holds configuration. Runtime values
 * that change on every sync (current price, last attempt, API status, sync
 * counters) live in dedicated, non-autoloaded options so they never rewrite
 * the autoloaded blob on every cron tick.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_Settings {

	const OPTION          = 'gpu_settings';
	const OPT_STATE       = 'gpu_state';       // runtime state (non-autoloaded).
	const OPT_API_STATUS  = 'gpu_api_status';  // last test-connection result.
	const OPT_NOTICES     = 'gpu_admin_notice';// sticky admin warning (max-change etc).

	/**
	 * All defaults, grouped by settings-page section.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			// ---- General -------------------------------------------------
			'enabled'                => 1,
			'sale_mode'              => 'regular_only', // regular_only | skip_on_sale.

			// ---- Gold price ----------------------------------------------
			'price_mode'             => 'fallback',     // api | manual | fallback.
			'manual_price'           => 0.0,
			'buffer'                 => 0.0,
			'karat'                  => '18k',
			'target_currency'        => 'IRR',
			'max_price_change'       => 20.0,           // percent; 0 = disabled.

			// ---- Pricing -------------------------------------------------
			'default_making_charge_pct' => 0.0, // fallback when a product leaves it empty.
			'seller_profit_pct'      => 7.0,
			'vat_pct'                => 10.0,
			'profit_base'            => 'raw_plus_making', // future-proofing (§20).
			'round_mode'             => 'none',         // none | nearest | up | down.
			'round_step'             => 0.0,
			'decimals'               => 2,

			// ---- Sync ----------------------------------------------------
			'update_interval'        => 900,            // seconds.
			'batch_size'             => 50,
			'retry_count'            => 2,
			'retry_delay'            => 60,             // seconds between sync retries.
			'lock_timeout'           => 300,            // seconds.

			// ---- API -----------------------------------------------------
			'provider'               => 'custom',
			'api_url'                => '',
			'api_key'                => '',
			'api_secret'             => '',
			'auth_method'            => 'none',         // none | header | bearer | query | basic.
			'request_method'         => 'GET',
			'request_body'           => '',
			'json_path'              => '',
			'price_unit'             => 'gram_18k',
			'api_currency'           => 'IRR',
			'timeout'                => 15,

			// ---- Notifications -------------------------------------------
			'email_on_error'         => 0,
			'error_email'            => '',

			// ---- Advanced ------------------------------------------------
			'debug'                  => 0,
			'logging_enabled'        => 1,
			'log_retention_days'     => 30,
			'github_repo'            => '',             // owner/name for the updater.
		);
	}

	/**
	 * Full settings array (stored + defaults).
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public static function get_setting( $key, $default = null ) {
		$settings = self::get();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Merge values into the stored settings.
	 *
	 * @param array<string, mixed> $values
	 *
	 * @return void
	 */
	public static function save( array $values ) {
		update_option( self::OPTION, wp_parse_args( $values, self::get() ), true );
	}

	// ------------------------------------------------------------- runtime state

	/**
	 * Runtime state (current price, attempts, counters). Non-autoloaded.
	 *
	 * @return array<string, mixed>
	 */
	public static function state() {
		$state = get_option( self::OPT_STATE, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public static function state_get( $key, $default = null ) {
		$state = self::state();

		return array_key_exists( $key, $state ) ? $state[ $key ] : $default;
	}

	/**
	 * Merge values into runtime state.
	 *
	 * @param array<string, mixed> $values
	 *
	 * @return void
	 */
	public static function state_save( array $values ) {
		$state = array_merge( self::state(), $values );
		update_option( self::OPT_STATE, $state, false );
	}

	// ------------------------------------------------------------- api status

	/**
	 * Persist the last Test Connection / fetch outcome.
	 *
	 * @param bool        $ok
	 * @param string      $message
	 * @param float|null  $price
	 * @param int|null    $http_code
	 *
	 * @return void
	 */
	public static function set_api_status( $ok, $message, $price = null, $http_code = null ) {
		update_option(
			self::OPT_API_STATUS,
			array(
				'status'      => $ok ? 'connected' : 'error',
				'message'     => (string) $message,
				'price'       => $price,
				'http_code'   => $http_code,
				'checked_at'  => time(),
			),
			false
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function api_status() {
		$status = get_option( self::OPT_API_STATUS, array() );

		return is_array( $status ) ? $status : array();
	}

	// ------------------------------------------------------------- sticky notice

	/**
	 * Show a persistent admin warning until dismissed.
	 *
	 * @param string $key     Unique notice key (repeated sets don't duplicate).
	 * @param string $message Escaped-on-output message.
	 *
	 * @return void
	 */
	public static function push_notice( $key, $message ) {
		$notices          = get_option( self::OPT_NOTICES, array() );
		$notices          = is_array( $notices ) ? $notices : array();
		$notices[ $key ]  = array(
			'message' => (string) $message,
			'time'    => time(),
		);
		update_option( self::OPT_NOTICES, $notices, false );
	}

	/**
	 * Remove a sticky notice.
	 *
	 * @param string $key
	 *
	 * @return void
	 */
	public static function dismiss_notice( $key ) {
		$notices = get_option( self::OPT_NOTICES, array() );
		if ( is_array( $notices ) && isset( $notices[ $key ] ) ) {
			unset( $notices[ $key ] );
			update_option( self::OPT_NOTICES, $notices, false );
		}
	}

	/**
	 * @return array<string, array{message: string, time: int}>
	 */
	public static function notices() {
		$notices = get_option( self::OPT_NOTICES, array() );

		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * Masks a credential for admin display (first 4 + *** + last 2).
	 *
	 * @param string $secret
	 *
	 * @return string
	 */
	public static function mask( $secret ) {
		$secret = trim( (string) $secret );
		if ( strlen( $secret ) <= 6 ) {
			return $secret === '' ? '' : '****';
		}

		return substr( $secret, 0, 4 ) . '***' . substr( $secret, - 2 );
	}
}
