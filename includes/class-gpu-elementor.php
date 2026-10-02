<?php
/**
 * Elementor integration — dynamic tags backed by the Gold Price Service.
 *
 * Registers four tags when Elementor is active (silently skipped otherwise —
 * the core plugin never depends on Elementor). Every tag exposes the shared
 * Before / After / Fallback wrapper (see GPU_Elem_Base):
 *   GPU_Elem_Price          — gold price (effective/api/buffer/manual/karat/
 *                             currency/source).
 *   GPU_Elem_Updated        — last successful sync time (WP locale date).
 *   GPU_Elem_Product_Fields — per-product fields: weight, accessory weight,
 *                             stone fee, the three percents (raw number or
 *                             money value), raw gold value.
 *   GPU_Elem_Product_Price  — per-product pricing-engine breakdown incl.
 *                             the final price.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_Elementor {

	/**
	 * @return void
	 */
	public static function register() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		// Elementor ≥ 3.5 registers tags via a manager service; older builds
		// used the hook directly.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance )
			&& isset( \Elementor\Plugin::$instance->dynamic_tags ) ) {
			add_action( 'elementor/dynamic_tags/register', array( __CLASS__, 'register_tags' ) );
		} else {
			add_action( 'elementor/init', array( __CLASS__, 'register_tags_legacy' ) );
		}
	}

	/**
	 * @param object $dynamic_tags Elementor dynamic tags manager.
	 *
	 * @return void
	 */
	public static function register_tags( $dynamic_tags ) {
		if ( ! class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) {
			return;
		}

		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-base.php';
		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-price.php';
		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-updated.php';
		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-product-fields.php';
		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-product-price.php';

		$dynamic_tags->register( new GPU_Elem_Price() );
		$dynamic_tags->register( new GPU_Elem_Updated() );
		$dynamic_tags->register( new GPU_Elem_Product_Fields() );
		$dynamic_tags->register( new GPU_Elem_Product_Price() );
	}

	/**
	 * Legacy path for old Elementor versions.
	 *
	 * @return void
	 */
	public static function register_tags_legacy() {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->dynamic_tags ) ) {
			return;
		}

		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-base.php';
		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-price.php';
		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-updated.php';
		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-product-fields.php';
		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-product-price.php';

		$manager = \Elementor\Plugin::$instance->dynamic_tags;
		if ( method_exists( $manager, 'register_tag' ) ) {
			$manager->register_tag( new GPU_Elem_Price() );
			$manager->register_tag( new GPU_Elem_Updated() );
			$manager->register_tag( new GPU_Elem_Product_Fields() );
			$manager->register_tag( new GPU_Elem_Product_Price() );
		}
	}
}
