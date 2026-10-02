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

	const CRON_HOOK      = 'gpu_sync_cron';
	const REALTIME_HOOK = 'gpu_realtime_cron';

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
		add_action( self::REALTIME_HOOK, array( 'GPU_Sync', 'realtime_tick' ) );
		add_action( GPU_Queue::CRON_HOOK, array( 'GPU_Queue', 'tick' ) );

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );

		// Flush buffered log lines at the end of every request.
		add_action( 'shutdown', array( 'GPU_Logger', 'flush' ) );

		// Keep the schedule in step with the configured interval.
		add_action( 'update_option_' . GPU_Settings::OPTION, array( __CLASS__, 'reschedule' ), 10, 0 );

		// Process queue on every admin page load (WP-Cron safety net).
		add_action( 'admin_init', array( __CLASS__, 'maybe_process_queue' ) );

		// Front-load pending queue ticks into every request (frontend included):
		// keeps gold-price changes spreading to the catalog even when WP-Cron
		// is starved or the site has no traffic, with a per-request guard so
		// each PHP request drains at most one tick (~batch_size products).
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_frontload_queue' ) );

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
		wp_clear_scheduled_hook( self::REALTIME_HOOK );
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

		$rt_interval = max( 15, (int) GPU_Settings::get_setting( 'realtime_interval', 60 ) );

		$schedules['gpu_realtime'] = array(
			'interval' => $rt_interval,
			/* translators: %d seconds */
			'display'  => sprintf( __( 'Every %d seconds (Gold Price Updater realtime)', 'gold-price-updater' ), $rt_interval ),
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
			GPU_Queue::run_ticks( 10 );
		}
	}

	/**
	 * Drain one queue tick on ANY request (frontend included) when cron
	 * hasn't picked the work up within ~10 seconds. This is the substitute
	 * for a real cron driver (curl, real crontab) on hosts where WP-Cron
	 * only fires on traffic — it makes price changes spread in seconds.
	 *
	 * @return void
	 */
	public static function maybe_frontload_queue() {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() ) {
			return;
		}

		$state   = GPU_Settings::state();
		$pending = (int) ( $state['queue_pending'] ?? 0 );

		if ( $pending <= 0 && time() - (int) ( $state['queue_frontload_at'] ?? 0 ) > 10 ) {
			// Cheap probe (only when no recent signal): any pending rows?
			$counts   = GPU_Queue::counts();
			$pending  = $counts['pending'] + $counts['processing'];
			GPU_Settings::state_save(
				array(
					'queue_pending'      => $pending,
					'queue_frontload_at' => time(),
				)
			);
		}

		if ( $pending > 0 ) {
			GPU_Settings::state_save(
				array(
					'queue_pending'      => 0,
					'queue_frontload_at' => time(),
				)
			);
			GPU_Queue::tick();
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

		// Realtime poller — independent high-frequency event.
		$rt_interval = max( 15, (int) GPU_Settings::get_setting( 'realtime_interval', 60 ) );
		$rt_next     = wp_next_scheduled( self::REALTIME_HOOK );
		if ( $rt_next ) {
			wp_unschedule_event( $rt_next, self::REALTIME_HOOK );
		}

		if ( ! empty( GPU_Settings::get_setting( 'realtime_enabled', 1 ) ) ) {
			wp_schedule_event( time() + $rt_interval, 'gpu_realtime', self::REALTIME_HOOK );
		}
	}
}
