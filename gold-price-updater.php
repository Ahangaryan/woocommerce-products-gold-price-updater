<?php
/**
 * Plugin Name: Gold Price Updater for WooCommerce
 * Description: Fetches the daily gold price from a configurable provider (presets included, incl. Price Intelligence Engine), validates it, applies a buffer, and recalculates every managed product with a dedicated pricing engine (making charge, seller profit, VAT on making charge only). Batch/queue processing, locking, retries, manual override, logs, sync history, Elementor dynamic tags and GitHub release updates.
 * Version:     1.2.0
 * Author:      Hamid Ahangaryan
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gold-price-updater
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 12.0
 *
 * @package GoldPriceUpdater
 */

defined( 'ABSPATH' ) || exit;

define( 'GPU_VERSION', '1.2.0' );
define( 'GPU_DB_VERSION', 1 );
define( 'GPU_PLUGIN_FILE', __FILE__ );
define( 'GPU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GPU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once GPU_PLUGIN_DIR . 'includes/class-gpu-logger.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-pricing-engine.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-price-normalizer.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-api-providers.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-api-client.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-gold-price-service.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-settings.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-db.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-sync.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-queue.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-products.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-meta.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-admin.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-elementor.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-updater.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-plugin.php';
require_once GPU_PLUGIN_DIR . 'includes/class-gpu-translations.php';

register_activation_hook( __FILE__, array( 'GPU_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GPU_Plugin', 'deactivate' ) );

/*
 * Declare compatibility with WooCommerce features.
 *
 * MUST run inside 'before_woocommerce_init' and MUST live in the MAIN plugin
 * file (WordPress never loads auxiliary files before plugins_loaded, so a
 * standalone declaration file is silently ignored). Without these
 * declarations WooCommerce treats the plugin as "uncertain" and, for
 * features whose default is 'incompatible' (e.g. custom_order_tables), shows
 * the red compatibility warning on every admin page.
 */
add_action( 'before_woocommerce_init', function () {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		return;
	}

	$features = array(
		'custom_order_tables',
		'blueprint',
		'order_attribution',
		'point_of_sale',
		'remote_logging',
		'site_visibility_badge',
		'analytics',
		'marketplace',
	);

	foreach ( $features as $feature_id ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( $feature_id, __FILE__ );
	}
} );

add_action( 'plugins_loaded', array( 'GPU_Plugin', 'init' ) );
