<?php
/**
 * Main plugin class: lifecycle, cron scheduling, module registration.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_Plugin {

	const CRON_HOOK = 'gpu_sync_cron';

	/**
	 * Hooked to plugins_loaded.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woo_missing_notice' ) );

			return;
		}

		// Migrate the DB when the code version is newer than the stored one.
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );

		GPU_Settings::get(); // Warm (and validate) the option.
		GPU_Meta::register();
		GPU_Admin::register();

		add_action( self::CRON_HOOK, array( 'GPU_Sync', 'cron_tick' ) );
		add_action( GPU_Queue::CRON_HOOK, array( 'GPU_Queue', 'tick' ) );

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );

		// Flush buffered log lines at the end of every request.
		add_action( 'shutdown', array( 'GPU_Logger', 'flush' ) );

		// Keep the schedule in step with the configured interval.
		add_action( 'update_option_' . GPU_Settings::OPTION, array( __CLASS__, 'reschedule' ), 10, 0 );

		// Process queue on every admin page load (WP-Cron safety net).
		add_action( 'admin_init', array( __CLASS__, 'maybe_process_queue' ) );

		// GitHub updater (opt-in via the github_repo setting).
		GPU_Updater::register();

		// Elementor integration — silently skipped when Elementor is absent.
		GPU_Elementor::register();
	}

	/**
	 * @return void
	 */
	public static function woo_missing_notice() {
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'Gold Price Updater requires WooCommerce to be active.', 'gold-price-updater' ) .
			'</p></div>';
	}

	/**
	 * Activation: schema + defaults + schedule.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( GPU_PLUGIN_FILE ) );
			wp_die( esc_html__( 'Gold Price Updater requires WooCommerce to be active.', 'gold-price-updater' ) );
		}

		GPU_DB::migrate();
		add_option( GPU_Settings::OPTION, GPU_Settings::defaults() );
		add_option( GPU_Settings::OPT_STATE, array( 'queue_total' => 0 ), '', false );
		self::reschedule();
	}

	/**
	 * Deactivation: clear cron + release the lock.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( GPU_Queue::CRON_HOOK );
		GPU_DB::release_lock( true );
	}

	/**
	 * Run migrations only when the DB version lags the code version.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( ! GPU_DB::is_current() ) {
			GPU_DB::migrate();
		}
	}

	/**
	 * Register the "gpu_interval" cron schedule from settings.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules
	 *
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function cron_schedules( $schedules ) {
		$interval = max( 60, (int) GPU_Settings::get_setting( 'update_interval', 900 ) );

		$schedules['gpu_interval'] = array(
			'interval' => $interval,
			/* translators: %d seconds */
			'display'  => sprintf( __( 'Every %d seconds (Gold Price Updater)', 'gold-price-updater' ), $interval ),
		);

		return $schedules;
	}

	/**
	 * Process the queue when there's pending work (admin-init safety net).
     *
     * WP-Cron is unreliable on localhost and low-traffic sites.  This hook
     * ensures queued product updates actually run whenever an admin loads
     * any backend page.
     *
     * @return void
     */
	public static function maybe_process_queue() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$counts = GPU_Queue::counts();
		if ( $counts['pending'] > 0 || $counts['processing'] > 0 ) {
			GPU_Queue::tick( true );
		}
	}

	/**
	 * (Re)schedule the sync cron at the configured interval.
	 *
	 * @return void
     */
	public static function reschedule() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$interval = max( 60, (int) GPU_Settings::get_setting( 'update_interval', 900 ) );

		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}

		wp_schedule_event( time() + $interval, 'gpu_interval', self::CRON_HOOK );
	}
}
