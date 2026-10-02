<?php
/**
 * Elementor dynamic tag: Product Price Breakdown — the FINAL price and every
 * component of the pricing engine for the current product, computed with the
 * live effective gold price (read-only; never writes anything).
 *
 * Every output passes through the shared Before / After / Fallback wrapper.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @noinspection PhpUndefinedClassInspection
 */
class GPU_Elem_Product_Price extends GPU_Elem_Base {

	public function get_name() {
		return 'gpu_product_price';
	}

	public function get_title() {
		return GPU_Translations::t( 'Product Price Breakdown (Gold)' );
	}

	public function get_group() {
		return 'site';
	}

	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY );
	}

	/**
	 * @return void
	 */
	protected function register_controls() {
		$this->add_control(
			'component',
			array(
			'label'   => GPU_Translations::e( 'Component' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'final_price',
			'options' => array(
				'final_price'          => GPU_Translations::t( 'Final price (what the customer pays)' ),
				'raw_gold_value'       => GPU_Translations::t( 'Raw gold value' ),
				'accessory_gold_value' => GPU_Translations::t( 'Accessory gold value' ),
				'making_charge'        => GPU_Translations::t( 'Making charge' ),
				'stone_fee'            => GPU_Translations::t( 'Stone / accessory fee' ),
				'seller_profit'        => GPU_Translations::t( 'Seller profit' ),
				'vat'                  => GPU_Translations::t( 'VAT' ),
				'effective_gold_price' => GPU_Translations::t( 'Effective gold price used' ),
			),
			)
		);

		$this->add_control(
			'decimals',
			array(
				'label'   => GPU_Translations::e( 'Decimals' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 0,
				'min'     => 0,
				'max'     => 4,
			)
		);

		$this->register_wrapper_controls();
	}

	/**
	 * @param array<string, mixed> $options
	 *
	 * @return string
	 */
	public function get_value( array $options = array() ) {
		$component = (string) $this->get_settings( 'component' );
		if ( '' === $component ) {
			$component = 'final_price';
		}

		$decimals = max( 0, min( 4, (int) $this->get_settings( 'decimals' ) ) );

		// The effective price works without a product context.
		if ( 'effective_gold_price' === $component ) {
			$price = GPU_Gold_Price_Service::effective_price();

			return $this->wrap( null === $price ? '' : number_format_i18n( $price, $decimals ) );
		}

		$product = $this->current_product();
		if ( ! $product ) {
			return $this->wrap( '' );
		}

		$product_id = $product->get_id();
		$managed_id = $product->get_parent_id() ? $product->get_parent_id() : $product_id;

		$weight = GPU_Products::weight_in_grams( $product, $managed_id );
		if ( null === $weight || $weight <= 0 ) {
			return $this->wrap( '' );
		}

		$mk_pct = GPU_Products::making_charge_pct( $product_id, $managed_id );
		if ( null === $mk_pct ) {
			return $this->wrap( '' );
		}

		$profit_pct = GPU_Products::seller_profit_pct( $product_id, $managed_id );
		if ( null === $profit_pct ) {
			return $this->wrap( '' );
		}

		$vat_pct = GPU_Products::vat_pct( $product_id, $managed_id );
		if ( null === $vat_pct ) {
			return $this->wrap( '' );
		}

		$effective = GPU_Gold_Price_Service::effective_price();
		if ( null === $effective ) {
			return $this->wrap( '' );
		}

		$breakdown = GPU_Pricing_Engine::price(
			$weight,
			$effective,
			0.0,
			$mk_pct,
			$profit_pct,
			$vat_pct,
			array(
				'decimals'                    => 6,
				'accessory_weight'            => GPU_Products::accessory_weight( $product_id, $managed_id ),
				'stone_fee'                   => GPU_Products::stone_fee( $product_id, $managed_id ),
				'include_accessory_in_making' => GPU_Products::accessory_in_making( $product_id, $managed_id ),
			)
		);

		if ( null === $breakdown || ! isset( $breakdown[ $component ] ) ) {
			return $this->wrap( '' );
		}

		return $this->wrap( number_format_i18n( $breakdown[ $component ], $decimals ) );
	}
}
