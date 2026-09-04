<?php
/**
 * Elementor dynamic tag: Gold Price (effective / api / buffer / manual / source).
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @noinspection PhpUndefinedClassInspection
 */
class GPU_Elem_Price extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name() {
		return 'gpu_gold_price';
	}

	public function get_title() {
		return __( 'Gold Price', 'gold-price-updater' );
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
			'variant',
			array(
				'label'   => __( 'Show', 'gold-price-updater' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'effective',
				'options' => array(
					'effective' => __( 'Effective price (gold + buffer)', 'gold-price-updater' ),
					'api'       => __( 'API price (base)', 'gold-price-updater' ),
					'buffer'    => __( 'Buffer amount', 'gold-price-updater' ),
					'manual'    => __( 'Manual price', 'gold-price-updater' ),
					'source'    => __( 'Price source (api/manual/none)', 'gold-price-updater' ),
				),
			)
		);

		$this->add_control(
			'decimals',
			array(
				'label'   => __( 'Decimals', 'gold-price-updater' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 0,
				'min'     => 0,
				'max'     => 4,
			)
		);
	}

	/**
	 * @param array<string, mixed> $options
	 *
	 * @return string
	 */
	public function get_value( array $options = array() ) {
		$variant  = isset( $options['variant'] ) ? (string) $options['variant'] : 'effective';
		$decimals = isset( $options['decimals'] ) ? (int) $options['decimals'] : 0;

		switch ( $variant ) {
			case 'api':
				$price = GPU_Settings::state_get( 'api_price', null );

				return null === $price ? '' : number_format_i18n( (float) $price, $decimals );

			case 'buffer':
				return number_format_i18n( (float) GPU_Settings::get_setting( 'buffer', 0 ), $decimals );

			case 'manual':
				return number_format_i18n( (float) GPU_Settings::get_setting( 'manual_price', 0 ), $decimals );

			case 'source':
				$source = GPU_Gold_Price_Service::current_with_source();

				return (string) $source['source'];

			case 'effective':
			default:
				$price = GPU_Gold_Price_Service::effective_price();

				return null === $price ? '' : number_format_i18n( $price, $decimals );
		}
	}
}
