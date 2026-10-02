<?php
/**
 * Elementor dynamic tag: Product Fields — every per-product field of the
 * plugin, resolvable on any page that shows a product.
 *
 * Percent fields (making charge / seller profit / VAT) have TWO display
 * modes, exposed via a "Display" select on each:
 *   - raw_number  → exactly the number stored in the field (e.g. `15`)
 *   - money_value → the percent's monetary value in the shop currency,
 *                   computed with the current effective gold price and the
 *                   product weight (e.g. `3,825,000`).
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
class GPU_Elem_Product_Fields extends GPU_Elem_Base {

	public function get_name() {
		return 'gpu_product_fields';
	}

	public function get_title() {
		return GPU_Translations::t( 'Product Fields (Gold Pricing)' );
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
			'field',
			array(
			'label'   => GPU_Translations::e( 'Field' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'weight',
			'options' => array(
				'weight'          => GPU_Translations::t( 'Product Weight (grams)' ),
				'making_charge'   => GPU_Translations::t( 'Making Charge %' ),
				'profit'          => GPU_Translations::t( 'Seller Profit %' ),
				'vat'             => GPU_Translations::t( 'VAT %' ),
				'accessory'       => GPU_Translations::t( 'Accessory Weight (grams)' ),
				'stone_fee'       => GPU_Translations::t( 'Stone / Accessory Fee' ),
				'raw_gold_value'  => GPU_Translations::t( 'Raw Gold Value (computed)' ),
			),
			)
		);

		// Only meaningful for the three percent fields: raw number vs. the
		// percent's money value.
		$this->add_control(
			'pct_display',
			array(
			'label'     => GPU_Translations::e( 'Percent Display' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => 'raw_number',
			'options'   => array(
				'raw_number'  => GPU_Translations::t( '1) Raw number (as written in the field)' ),
				'money_value' => GPU_Translations::t( '2) Money value of the percent (computed)' ),
			),
				'condition' => array(
					'field' => array( 'making_charge', 'profit', 'vat' ),
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
		$field = (string) $this->get_settings( 'field' );
		if ( '' === $field ) {
			$field = 'weight';
		}

		$product = $this->current_product();
		if ( ! $product ) {
			return $this->wrap( '' );
		}

		$product_id = $product->get_id();
		$managed_id = $product->get_parent_id() ? $product->get_parent_id() : $product_id;

		$decimals = max( 0, min( 4, (int) $this->get_settings( 'decimals' ) ) );

		switch ( $field ) {
			case 'making_charge':
			case 'profit':
			case 'vat':
				return $this->wrap( $this->percent_output( $field, $product_id, $managed_id, $decimals ) );

			case 'accessory':
				$value = GPU_Products::accessory_weight( $product_id, $managed_id );

				return $this->wrap( number_format_i18n( $value, $decimals ) );

			case 'stone_fee':
				$value = GPU_Products::stone_fee( $product_id, $managed_id );

				return $this->wrap( number_format_i18n( $value, $decimals ) );

			case 'raw_gold_value':
				$breakdown = $this->product_breakdown( $product, $product_id, $managed_id );

				return $this->wrap(
					null === $breakdown
						? ''
						: number_format_i18n( $breakdown['raw_gold_value'], $decimals )
				);

			case 'weight':
			default:
				$weight = GPU_Products::weight_in_grams( $product, $managed_id );

				return $this->wrap( null === $weight ? '' : number_format_i18n( $weight, $decimals ) );
		}
	}

	/**
	 * Percent output: raw stored number, or the percent's money value.
	 *
	 * The money value of every percent follows the same definitions the
	 * pricing engine uses, so the displayed numbers always add up:
	 *   making_charge → percent of the raw gold value (respecting the
	 *                   accessory-in-making flag)
	 *   profit        → percent of (raw gold value + making charge)
	 *   vat           → percent of the making charge
	 *
	 * @param string $field      making_charge|profit|vat.
	 * @param int    $product_id
	 * @param int    $managed_id
	 * @param int    $decimals   Money-value precision only — the raw percent is
	 *                           always shown exactly as stored (never rounded).
	 *
	 * @return string '' when a money value cannot be computed.
	 */
	private function percent_output( $field, $product_id, $managed_id, $decimals ) {
		$display = (string) $this->get_settings( 'pct_display' );
		if ( '' === $display ) {
			$display = 'raw_number';
		}

		switch ( $field ) {
			case 'making_charge':
				$raw = GPU_Products::making_charge_pct( $product_id, $managed_id );
				break;
			case 'profit':
				$raw = GPU_Products::seller_profit_pct( $product_id, $managed_id );
				break;
			case 'vat':
			default:
				$raw = GPU_Products::vat_pct( $product_id, $managed_id );
				break;
		}

		// Mode 1: the raw number exactly as written in the field — the widget's
		// money "Decimals" control must never round it (7.7 stays 7.7, 8.25
		// stays 8.25 even when Decimals is 0).
		if ( 'money_value' !== $display ) {
			return null === $raw ? '' : self::format_percent( $raw );
		}

		// Mode 2: money value of the percent.
		if ( null === $raw ) {
			return '';
		}

		$breakdown = $this->product_breakdown( wc_get_product( $product_id ), $product_id, $managed_id );
		if ( null === $breakdown ) {
			return '';
		}

		switch ( $field ) {
			case 'making_charge':
				$value = $breakdown['making_charge'];
				break;
			case 'profit':
				$value = $breakdown['seller_profit'];
				break;
			case 'vat':
			default:
				$value = $breakdown['vat'];
				break;
		}

		return number_format_i18n( $value, $decimals );
	}

	/**
	 * Exact display of a stored percent: locale digits, at most 4 decimals,
	 * trailing zeros trimmed, never rounded (7.7 → "7.7", 8.25 → "8.25").
	 *
	 * @param float $value
	 *
	 * @return string
	 */
	private static function format_percent( $value ) {
		$plain = rtrim( rtrim( number_format( (float) $value, 4, '.', '' ), '0' ), '.' );
		if ( '' === $plain || '-' === $plain ) {
			$plain = '0';
		}

		$decimals = ( false !== strpos( $plain, '.' ) )
			? strlen( substr( $plain, strpos( $plain, '.' ) + 1 ) )
			: 0;

		return number_format_i18n( (float) $plain, $decimals );
	}

	/**
	 * Full read-only breakdown for the product with the current gold price.
	 *
	 * @param WC_Product $product
	 * @param int        $product_id
	 * @param int        $managed_id
	 *
	 * @return array<string, float>|null
	 */
	private function product_breakdown( $product, $product_id, $managed_id ) {
		if ( ! $product ) {
			return null;
		}

		$weight = GPU_Products::weight_in_grams( $product, $managed_id );
		if ( null === $weight || $weight <= 0 ) {
			return null;
		}

		$mk_pct = GPU_Products::making_charge_pct( $product_id, $managed_id );
		if ( null === $mk_pct ) {
			return null;
		}

		$effective = GPU_Gold_Price_Service::effective_price();
		if ( null === $effective ) {
			return null;
		}

		return GPU_Pricing_Engine::price(
			$weight,
			$effective,
			0.0,
			$mk_pct,
			(float) GPU_Products::seller_profit_pct( $product_id, $managed_id ),
			(float) GPU_Products::vat_pct( $product_id, $managed_id ),
			array(
				'decimals'                    => 6,
				'accessory_weight'            => GPU_Products::accessory_weight( $product_id, $managed_id ),
				'stone_fee'                   => GPU_Products::stone_fee( $product_id, $managed_id ),
				'include_accessory_in_making' => GPU_Products::accessory_in_making( $product_id, $managed_id ),
			)
		);
	}
}
