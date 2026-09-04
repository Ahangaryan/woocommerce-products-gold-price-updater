<?php
/**
 * Admin UI — dashboard (status cards + progress), settings page, log viewer,
 * sync history. One submenu under WooCommerce: "Gold Price Updater".
 *
 * Every action is nonce-checked and capability-checked (manage_woocommerce).
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_Admin {

	/**
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_gpu_sync_now', array( __CLASS__, 'handle_sync_now' ) );
		add_action( 'admin_post_gpu_test_api', array( __CLASS__, 'handle_test_api' ) );
		add_action( 'admin_post_gpu_force_sync', array( __CLASS__, 'handle_force_sync' ) );
		add_action( 'wp_ajax_gpu_progress', array( __CLASS__, 'ajax_progress' ) );
		add_action( 'admin_notices', array( __CLASS__, 'sticky_notices' ) );
	}

	/**
	 * @return void
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Gold Price Updater', 'gold-price-updater' ),
			__( 'Gold Price Updater', 'gold-price-updater' ),
			'manage_woocommerce',
			'gpu-dashboard',
			array( __CLASS__, 'render_dashboard' )
		);
	}

	/**
	 * Assets for the dashboard + product-edit preview.
	 *
	 * @param string $hook
	 *
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, 'gpu-dashboard' ) ) {
			wp_enqueue_script(
				'gpu-dashboard',
				GPU_PLUGIN_URL . 'assets/dashboard.js',
				array(),
				GPU_VERSION,
				true
			);
			wp_localize_script(
				'gpu-dashboard',
				'gpuDash',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'gpu_progress' ),
				)
			);
			wp_localize_script(
				'gpu-dashboard',
				'gpuProviders',
				GPU_Api_Providers::all()
			);
		}

		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_script(
				'gpu-preview',
				GPU_PLUGIN_URL . 'assets/preview.js',
				array(),
				GPU_VERSION,
				true
			);
			wp_localize_script(
				'gpu-preview',
				'gpuPreview',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'gpu_preview' ),
				)
			);
		}
	}

	// ------------------------------------------------------------ actions

	/**
	 * Sync Now (admin-post): validates, queues, redirects back with a message.
	 *
	 * @return void
	 */
	public static function handle_sync_now() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'gold-price-updater' ) );
		}
		check_admin_referer( 'gpu_sync_now' );

		$result = GPU_Sync::run( 'manual' );
		$type   = $result['ok'] ? 'updated' : 'error';

		self::redirect( array( 'gpu_msg' => rawurlencode( $result['message'] ), 'gpu_type' => $type ) );
	}

	/**
	 * Force sync (ignores the max-change guard) — separate capability-checked action.
	 *
	 * @return void
	 */
	public static function handle_force_sync() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'gold-price-updater' ) );
		}
		check_admin_referer( 'gpu_force_sync' );

		$result = GPU_Sync::run( 'manual', true );
		$type   = $result['ok'] ? 'updated' : 'error';

		self::redirect( array( 'gpu_msg' => rawurlencode( $result['message'] ), 'gpu_type' => $type ) );
	}

	/**
	 * Test API Connection (admin-post): shows the outcome as an admin notice.
	 *
	 * @return void
	 */
	public static function handle_test_api() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'gold-price-updater' ) );
		}
		check_admin_referer( 'gpu_test_api' );

		$client = new GPU_Api_Client( GPU_Settings::get() );
		$result = $client->test();

		if ( $result['ok'] ) {
			GPU_Settings::set_api_status( true, 'Connected', $result['price'], $result['http_code'] ?? null );
			$message = sprintf(
				/* translators: %s: price */
				__( 'Connected. Current price: %s', 'gold-price-updater' ),
				number_format_i18n( (float) $result['price'] )
			);
		} else {
			GPU_Settings::set_api_status( false, $result['error'] ?? 'Unknown error.', null, $result['http_code'] ?? null );
			$message = sprintf(
				/* translators: %1$s: error, %2$d: http code */
				__( 'Connection failed: %1$s (HTTP %2$d)', 'gold-price-updater' ),
				(string) ( $result['error'] ?? 'unknown' ),
				(int) ( $result['http_code'] ?? 0 )
			);
		}

		self::redirect( array( 'gpu_msg' => rawurlencode( $message ), 'gpu_type' => $result['ok'] ? 'updated' : 'error' ) );
	}

	/**
	 * AJAX progress for the dashboard queue bar.
	 *
	 * @return void
	 */
	public static function ajax_progress() {
		check_ajax_referer( 'gpu_progress', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		$counts = GPU_Queue::counts();
		$done   = $counts['updated'] + $counts['skipped'] + $counts['error'];
		$total  = max( $counts['total'], 1 );

		wp_send_json_success(
			array(
				'counts'  => $counts,
				'percent' => round( ( $done / $total ) * 100, 1 ),
				'running' => $counts['pending'] > 0 || $counts['processing'] > 0,
			)
		);
	}

	/**
	 * Sticky warnings (max-change guard etc).
	 *
	 * @return void
	 */
	public static function sticky_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		foreach ( GPU_Settings::notices() as $key => $notice ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' .
				esc_html( $notice['message'] ) . '</p></div>';
		}
	}

	// ------------------------------------------------------------ pages

	/**
	 * Dashboard + settings combined (status cards on top, settings below).
	 *
	 * @return void
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$message = isset( $_GET['gpu_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['gpu_msg'] ) ) : '';
		$msgtype = isset( $_GET['gpu_type'] ) ? sanitize_text_field( wp_unslash( $_GET['gpu_type'] ) ) : 'updated';

		// Handle settings save.
		if ( isset( $_POST['gpu_save'] ) ) {
			check_admin_referer( 'gpu_settings' );
			self::save_settings();
			$message = __( 'Settings saved.', 'gold-price-updater' );
			$msgtype = 'updated';
		}

		$settings  = GPU_Settings::get();
		$state     = GPU_Settings::state();
		$api       = GPU_Settings::api_status();
		$effective = GPU_Gold_Price_Service::effective_price();
		$source    = GPU_Gold_Price_Service::current_with_source();
		$counts    = GPU_Queue::counts();
		$last_hist = GPU_DB::history_last();
		$history   = GPU_DB::history_recent( 10 );
		$logs      = GPU_Logger::recent( 30 );
		$next_sync = wp_next_scheduled( GPU_Plugin::CRON_HOOK );

		include GPU_PLUGIN_DIR . 'views/dashboard.php';
	}

	/**
	 * Persist posted settings (sanitized per field).
	 *
	 * @return void
	 */
	private static function save_settings() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- each field sanitized explicitly below.
		$post = $_POST;
		// phpcs:enable

		$values = array(
			// General.
			'enabled'          => empty( $post['gpu_enabled'] ) ? 0 : 1,
			'sale_mode'        => in_array( $post['gpu_sale_mode'] ?? '', array( 'regular_only', 'skip_on_sale' ), true )
				? sanitize_key( $post['gpu_sale_mode'] )
				: 'regular_only',

			// Gold price.
			'price_mode'       => in_array( $post['gpu_price_mode'] ?? '', array( 'api', 'manual', 'fallback' ), true )
				? sanitize_key( $post['gpu_price_mode'] )
				: 'fallback',
			'manual_price'     => max( 0.0, (float) ( $post['gpu_manual_price'] ?? 0 ) ),
			'buffer'           => max( 0.0, (float) ( $post['gpu_buffer'] ?? 0 ) ),
			'karat'            => sanitize_key( $post['gpu_karat'] ?? '18k' ),
			'target_currency'  => strtoupper( sanitize_key( $post['gpu_target_currency'] ?? 'IRR' ) ),
			'max_price_change' => max( 0.0, (float) ( $post['gpu_max_change'] ?? 20 ) ),

			// Pricing.
			'default_making_charge_pct' => min( 100.0, max( 0.0, (float) ( $post['gpu_default_mk'] ?? 0 ) ) ),
			'seller_profit_pct' => min( 100.0, max( 0.0, (float) ( $post['gpu_profit'] ?? 7 ) ) ),
			'vat_pct'          => min( 100.0, max( 0.0, (float) ( $post['gpu_vat'] ?? 10 ) ) ),
			'round_mode'       => in_array( $post['gpu_round_mode'] ?? '', array( 'none', 'nearest', 'up', 'down' ), true )
				? sanitize_key( $post['gpu_round_mode'] )
				: 'none',
			'round_step'       => max( 0.0, (float) ( $post['gpu_round_step'] ?? 0 ) ),
			'decimals'         => min( 6, max( 0, (int) ( $post['gpu_decimals'] ?? 2 ) ) ),

			// Sync.
			'update_interval'  => max( 60, (int) ( $post['gpu_interval'] ?? 900 ) ),
			'batch_size'       => max( 1, min( 500, (int) ( $post['gpu_batch'] ?? 50 ) ) ),
			'retry_count'      => max( 0, min( 10, (int) ( $post['gpu_retries'] ?? 2 ) ) ),
			'retry_delay'      => max( 30, (int) ( $post['gpu_retry_delay'] ?? 60 ) ),
			'lock_timeout'     => max( 60, (int) ( $post['gpu_lock_timeout'] ?? 300 ) ),

			// API.
			'provider'         => sanitize_key( $post['gpu_provider'] ?? 'custom' ),
			'api_url'          => untrailingslashit( esc_url_raw( (string) ( $post['gpu_api_url'] ?? '' ) ) ),
			'api_key'          => sanitize_text_field( (string) ( $post['gpu_api_key'] ?? '' ) ),
			'api_secret'       => sanitize_text_field( (string) ( $post['gpu_api_secret'] ?? '' ) ),
			'auth_method'      => in_array( $post['gpu_auth'] ?? '', array( 'none', 'header', 'bearer', 'query', 'basic' ), true )
				? sanitize_key( $post['gpu_auth'] )
				: 'none',
			'request_method'   => 'POST' === strtoupper( (string) ( $post['gpu_method'] ?? 'GET' ) ) ? 'POST' : 'GET',
			'json_path'        => sanitize_text_field( (string) ( $post['gpu_json_path'] ?? '' ) ),
			'price_unit'       => sanitize_key( $post['gpu_price_unit'] ?? 'gram_18k' ),
			'api_currency'     => strtoupper( sanitize_key( $post['gpu_api_currency'] ?? 'IRR' ) ),
			'timeout'          => max( 2, min( 120, (int) ( $post['gpu_timeout'] ?? 15 ) ) ),

			// Notifications.
			'email_on_error'   => empty( $post['gpu_email_on_error'] ) ? 0 : 1,
			'error_email'      => sanitize_email( (string) ( $post['gpu_error_email'] ?? '' ) ),

			// Advanced.
			'debug'            => empty( $post['gpu_debug'] ) ? 0 : 1,
			'logging_enabled'  => empty( $post['gpu_logging'] ) ? 0 : 1,
			'log_retention_days' => max( 1, (int) ( $post['gpu_log_days'] ?? 30 ) ),
			'github_repo'      => preg_match( '#^[\w.-]+/[\w.-]+$#', (string) ( $post['gpu_github_repo'] ?? '' ) )
				? sanitize_text_field( $post['gpu_github_repo'] )
				: '',
		);

		GPU_Settings::save( $values );
		GPU_Plugin::reschedule();
	}

	/**
	 * Redirect back to the dashboard with a message.
	 *
	 * @param array<string, string> $args
	 *
	 * @return void
	 */
	private static function redirect( array $args ) {
		wp_safe_redirect(
			add_query_arg(
				$args,
				admin_url( 'admin.php?page=gpu-dashboard' )
			)
		);
		exit;
	}
}
