<?php
/**
 * Product updater — the only component allowed to write WooCommerce prices.
 *
 * Responsibilities:
 *  - find managed products (meta flag) in DB-size-aware batches;
 *  - resolve weight (product weight unit → grams) and making charge
 *    (product override → default setting) per product / variation;
 *  - compute the price via GPU_Pricing_Engine and write it only when it
 *    actually changed (update_only_if_changed);
 *  - respect the sale-price behavior setting;
 *  - per-product safety: missing/bad data is skipped, never zeroed.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_Products {

	const META_MANAGED        = '_gpu_managed';
	const META_MAKING_CHARGE  = '_gpu_making_charge_pct';

	/** Weight units → grams (WooCommerce shop weight unit is normally one of these). */
	const WEIGHT_TO_GRAM = array(
		'g'     => 1.0,
		'kg'    => 1000.0,
		'lbs'   => 453.592,
		'oz'    => 28.3495,
		'pcs'   => 1.0, // Shop default; treated as grams when the shop unit is unknown.
	);

	/**
	 * Update a single product/variation and return the outcome.
	 *
	 * @param int   $product_id Simple product or variation ID.
	 * @param array $context    effective_price, decimals, round_mode, round_step,
	 *                          profit_pct, vat_pct, divisor, sale_mode.
	 *
	 * @return array{status: string, old?: float, new?: float} status: updated|skipped|error.
	 */
	public static function update_product( $product_id, array $context ) {
		$effective = (float) ( $context['effective_price'] ?? 0 );
		if ( $effective <= 0 ) {
			return array( 'status' => 'skipped', 'reason' => 'no_effective_price' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array( 'status' => 'error', 'reason' => 'not_found' );
		}

		if ( $product->is_type( 'variable' ) ) {
			return array( 'status' => 'skipped', 'reason' => 'variable_parent' );
		}

		// Managed flag: the product itself, or its parent for variations.
		$managed_id = $product->get_parent_id() ? $product->get_parent_id() : $product_id;
		if ( 'yes' !== get_post_meta( $managed_id, self::META_MANAGED, true ) ) {
			return array( 'status' => 'skipped', 'reason' => 'not_managed' );
		}

		$weight = self::weight_in_grams( $product, $managed_id );
		if ( null === $weight || $weight <= 0 ) {
			return array( 'status' => 'skipped', 'reason' => 'missing_weight' );
		}

		$mk_pct = self::making_charge_pct( $product_id, $managed_id );
		if ( null === $mk_pct ) {
			return array( 'status' => 'skippe' . 'd', 'reason' => 'invalid_making_charge' );
		}

		// Sale price behavior.
		if ( 'skip_on_sale' === ( $context['sale_mode'] ?? 'regular_only' )
			&& '' !== (string) $product->get_sale_price()
		) {
			return array( 'status' => 'skipped', 'reason' => 'on_sale' );
		}

		$result = GPU_Pricing_Engine::price(
			$weight,
			$effective, // Engine adds buffer again? No — effective already includes it; pass 0 buffer.
			0.0,
			$mk_pct,
			(float) ( $context['profit_pct'] ?? 0 ),
			(float) ( $context['vat_pct'] ?? 0 ),
			array(
				'decimals'          => (int) ( $context['decimals'] ?? 2 ),
				'round_mode'        => (string) ( $context['round_mode'] ?? 'none' ),
				'round_step'        => (float) ( $context['round_step'] ?? 0 ),
				'currency_divisor'  => (float) ( $context['divisor'] ?? 1 ),
			)
		);

		if ( null === $result ) {
			return array( 'status' => 'error', 'reason' => 'engine_rejected_input' );
		}

		$new_price = $result['final_price'];
		if ( $new_price <= 0 ) {
			return array( 'status' => 'error', 'reason' => 'non_positive_price' );
		}

		$old_price = (float) $product->get_regular_price( 'edit' );
		if ( abs( $old_price - $new_price ) < 0.01 ) {
			return array( 'status' => 'skipped', 'reason' => 'unchanged', 'old' => $old_price, 'new' => $new_price );
		}

		// Guard against sale-price drift: never write regular <= sale.
		$sale = (float) $product->get_sale_price( 'edit' );
		if ( $sale > 0 && $new_price <= $sale ) {
			return array( 'status' => 'skipped', 'reason' => 'price_below_sale', 'old' => $old_price, 'new' => $new_price );
		}

		// Write the regular price only (sale stays under manual control).
		// CRUD save keeps _price, lookup tables and transients consistent.
		$product->set_regular_price( (string) $new_price );
		$product->save();

		return array(
			'status' => 'updated',
			'old'    => $old_price,
			'new'    => $new_price,
			'making' => $result['making_charge'],
			'profit' => $result['seller_profit'],
			'vat'    => $result['vat'],
			'raw'    => $result['raw_gold_value'],
		);
	}

	/**
	 * Weight in grams for a product or variation, with parent fallback.
	 *
	 * @param WC_Product $product
	 * @param int        $managed_id Parent product ID for variations.
	 *
	 * @return float|null
	 */
	public static function weight_in_grams( $product, $managed_id = 0 ) {
		$weight = (float) $product->get_weight( 'edit' );
		if ( $weight <= 0 && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$weight = $parent ? (float) $parent->get_weight( 'edit' ) : 0.0;
		}
		if ( $weight <= 0 && $managed_id && $managed_id !== $product->get_id() ) {
			$parent = wc_get_product( $managed_id );
			$weight = $parent ? (float) $parent->get_weight( 'edit' ) : 0.0;
		}
		if ( $weight <= 0 ) {
			return null;
		}

		$shop_unit = get_option( 'woocommerce_weight_unit', 'g' );
		$factor    = self::WEIGHT_TO_GRAM[ $shop_unit ] ?? 1.0;

		return $weight * $factor;
	}

	/**
	 * Making charge percent: product override → managed default → null.
	 *
	 * @param int $product_id
	 * @param int $managed_id
	 *
	 * @return float|null
	 */
	public static function making_charge_pct( $product_id, $managed_id = 0 ) {
		$raw = get_post_meta( $product_id, self::META_MAKING_CHARGE, true );
		if ( '' === $raw || null === $raw ) {
			$raw = $managed_id && $managed_id !== $product_id
				? get_post_meta( $managed_id, self::META_MAKING_CHARGE, true )
				: '';
		}

		if ( '' === $raw || null === $raw ) {
			$raw = (string) GPU_Settings::get_setting( 'default_making_charge_pct', 0.0 );
		}

		if ( ! is_numeric( $raw ) ) {
			return null;
		}

		$value = (float) $raw;
		if ( $value < 0 || $value > 100 ) {
			return null;
		}

		return $value;
	}

	/**
	 * Managed product IDs (parents) in a DB-size-aware batch.
	 *
	 * @param int      $offset
	 * @param int      $limit
	 *
	 * @return list<int>
	 */
	public static function managed_product_ids( $offset = 0, $limit = 50 ) {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'yes'
				 WHERE p.post_type = 'product' AND p.post_status = 'publish'
				 ORDER BY p.ID ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::META_MANAGED,
				$limit,
				$offset
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Variation IDs for a set of parents, batched.
	 *
	 * @param list<int> $parent_ids
	 *
	 * @return list<int>
	 */
	public static function variation_ids( array $parent_ids ) {
		global $wpdb;

		if ( $parent_ids === array() ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $parent_ids ), '%d' ) );
		$rows         = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_parent IN ( $placeholders ) AND post_type = 'product_variation' AND post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$parent_ids
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Expand parents into the full work list (parents + variations).
	 *
	 * @param int $offset
	 * @param int $limit
	 *
	 * @return list<int>
	 */
	public static function work_list( $offset = 0, $limit = 50 ) {
		$parents = self::managed_product_ids( $offset, $limit );
		if ( $parents === array() ) {
			return array();
		}

		$ids = $parents;
		$variations = self::variation_ids( $parents );
		foreach ( $variations as $vid ) {
			$ids[] = $vid;
		}

		return $ids;
	}

	/**
	 * Total number of managed parents (for progress display).
	 *
	 * @return int
	 */
	public static function managed_count() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'yes'
				 WHERE p.post_type = 'product' AND p.post_status = 'publish'",
				self::META_MANAGED
			)
		);
	}
}
