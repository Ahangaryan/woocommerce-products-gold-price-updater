<?php
/**
 * WooCommerce compatibility declaration.
 *
 * DEPRECATED: WordPress only loads the MAIN plugin file before plugins_loaded,
 * so this standalone file was never executed and the declarations were never
 * registered. The real declarations now live in gold-price-updater.php inside
 * the 'before_woocommerce_init' action.
 *
 * Kept as an empty file to avoid "missing include" errors from old copies.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
