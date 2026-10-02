<?php
/**
 * Elementor dynamic tag: Gold Price (effective / api / buffer / manual /
 * source / karat / currency).
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
class GPU_Elem_Price extends GPU_Elem_Base {

	public function get_name() {
		return 'gpu_gold_price';
	}

	public function get_title() {
		return GPU_Translations::t( 'Gold Price' );
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
			'label'   => GPU_Translations::e( 'Show' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'effective',
			'options' => array(
				'effective' => GPU_Translations::t( 'Effective price (gold + buffer)' ),
				'api'       => GPU_Translations::t( 'API price (base)' ),
				'buffer'    => GPU_Translations::t( 'Buffer amount' ),
				'manual'    => GPU_Translations::t( 'Manual price' ),
				'karat'     => GPU_Translations::t( 'Target karat label' ),
				'currency'  => GPU_Translations::t( 'Target currency label' ),
				'source'    => GPU_Translations::t( 'Price source (api/manual/none)' ),
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
		/*
		 * Elementor renders tags via get_content() with no arguments, so
		 * $options is always empty. Control values live in the tag settings.
		 */
		$variant  = (string) $this->get_settings( 'variant' );
		$decimals = (int) $this->get_settings( 'decimals' );

		if ( isset( $options['variant'] ) ) {
			$variant = (string) $options['variant'];
		}

		if ( isset( $options['decimals'] ) ) {
			$decimals = (int) $options['decimals'];
		}

		if ( '' === $variant ) {
			$variant = 'effective';
		}

		$decimals = max( 0, min( 4, $decimals ) );

		switch ( $variant ) {
			case 'api':
				$price = GPU_Settings::state_get( 'api_price', null );

				return $this->wrap( null === $price ? '' : GPU_Formatter::number( (float) $price, $decimals ) );

			case 'buffer':
				return $this->wrap( GPU_Formatter::number( (float) GPU_Settings::get_setting( 'buffer', 0 ), $decimals ) );

			case 'manual':
				return $this->wrap( GPU_Formatter::number( (float) GPU_Settings::get_setting( 'manual_price', 0 ), $decimals ) );

			case 'karat':
				return $this->wrap( (string) GPU_Settings::get_setting( 'karat', '18k' ) );

			case 'currency':
				return $this->wrap( (string) GPU_Settings::get_setting( 'target_currency', 'IRR' ) );

			case 'source':
				$source = GPU_Gold_Price_Service::current_with_source();

				return $this->wrap( (string) $source['source'] );

			case 'effective':
			default:
				$price = GPU_Gold_Price_Service::effective_price();

				return $this->wrap( null === $price ? '' : GPU_Formatter::number( $price, $decimals ) );
		}
	}
}
