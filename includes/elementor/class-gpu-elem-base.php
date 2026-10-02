<?php
/**
 * Shared base for all Gold Price Updater Elementor dynamic tags.
 *
 * Provides the common wrapper controls required on EVERY tag of this plugin:
 *   - Before Text / After Text  (prepended/appended verbatim to the value)
 *   - Fallback                  (shown instead of an empty value)
 *
 * It also hosts the product-context helpers used by the per-product tags:
 * the current product is resolved from Elementor's global state or the
 * current post (works on the Elementor editor preview, products, and any
 * template that resolves to a product).
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @noinspection PhpUndefinedClassInspection
 */
abstract class GPU_Elem_Base extends \Elementor\Core\DynamicTags\Data_Tag {

	/**
	 * Add the shared wrapper controls (before/after/fallback).
	 *
	 * Call this at the END of a tag's register_controls().
	 *
	 * @return void
	 */
	protected function register_wrapper_controls() {
		$this->add_control(
			'gpu_before',
			array(
				'label'       => GPU_Translations::e( 'Before Text' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => GPU_Translations::e( 'Text before the value, e.g. «' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'gpu_after',
			array(
				'label'       => GPU_Translations::e( 'After Text' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => GPU_Translations::e( 'Text after the value, e.g. » or تومان' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'gpu_fallback',
			array(
				'label'       => GPU_Translations::e( 'Fallback Text' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => GPU_Translations::e( 'Shown when this field has no value yet (e.g. before the first sync).' ),
				'label_block' => true,
			)
		);
	}

	/**
	 * Apply the shared wrapper to a raw value: fallback on empty,
	 * before/after on every non-empty result.
	 *
	 * @param string $value Raw computed value ('' = no data).
	 *
	 * @return string
	 */
	public function wrap( $value ) {
		$value   = (string) $value;
		$before  = (string) $this->get_settings( 'gpu_before' );
		$after   = (string) $this->get_settings( 'gpu_after' );
		$fallback = (string) $this->get_settings( 'gpu_fallback' );

		if ( '' === $value ) {
			// Elementor's own "Fallback" control (set on the tag connection)
			// takes precedence; ours fills when that one is empty.
			$elementor_fallback = (string) $this->get_settings( 'fallback' );
			if ( '' !== $elementor_fallback ) {
				$value = $elementor_fallback;
			} elseif ( '' !== $fallback ) {
				$value = $fallback;
			} else {
				return '';
			}

			return $value; // No before/after around a fallback — it is a complete sentence already.
		}

		return $before . $value . $after;
	}

	/**
	 * Resolve the product shown on the current page.
	 *
	 * Tries, in order: Elementor's editor preview document data
	 * (`main_product_id`), Elementor's own product context, and finally the
	 * current post (including the `post` global inside loop items).
	 *
	 * @return WC_Product|null
	 */
	protected function current_product() {
		$product_id = 0;

		// 1) Elementor editor / preview document.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
			try {
				$document = \Elementor\Plugin::$instance->documents->get_current();
				if ( $document && method_exists( $document, 'get_main_post_id' ) ) {
					$main_id = (int) $document->get_main_post_id();
					if ( $main_id > 0 && 'product' === get_post_type( $main_id ) ) {
						$product_id = $main_id;
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore
				// Ignore and continue to the next strategy.
			}
		}

		// 2) Current post / post global (loop items, templates).
		if ( $product_id <= 0 ) {
			$candidate = (int) get_the_ID();
			if ( 0 === $candidate && isset( $GLOBALS['post'] ) ) {
				$candidate = (int) $GLOBALS['post']->ID;
			}
			if ( $candidate > 0 && 'product' === get_post_type( $candidate ) ) {
				$product_id = $candidate;
			}
		}

		if ( $product_id <= 0 ) {
			return null;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		// Variable parents have no weight/price of their own — the tags work
		// with whatever the parent defines (children inherit via the meta
		// chain), so the parent itself is still a valid context.
		return $product;
	}
}
