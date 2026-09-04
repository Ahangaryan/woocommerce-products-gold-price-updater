<?php
/**
 * Elementor integration — dynamic tags backed by the Gold Price Service.
 *
 * Registers two tags when Elementor is active (silently skipped otherwise —
 * the core plugin never depends on Elementor):
 *   GPU_Elem_Price  — gold price with a "what to show" option
 *                     (effective / api / buffer / manual / source label).
 *   GPU_Elem_Updated — last successful sync time (WP locale date format).
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

		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-price.php';
		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-updated.php';

		$dynamic_tags->register( new GPU_Elem_Price() );
		$dynamic_tags->register( new GPU_Elem_Updated() );
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

		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-price.php';
		require_once GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-updated.php';

		$manager = \Elementor\Plugin::$instance->dynamic_tags;
		if ( method_exists( $manager, 'register_tag' ) ) {
			$manager->register_tag( new GPU_Elem_Price() );
			$manager->register_tag( new GPU_Elem_Updated() );
		}
	}
}
