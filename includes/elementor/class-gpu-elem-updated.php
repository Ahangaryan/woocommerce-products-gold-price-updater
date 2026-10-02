<?php
/**
 * Elementor dynamic tag: last gold price update time.
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
class GPU_Elem_Updated extends GPU_Elem_Base {

	public function get_name() {
		return 'gpu_gold_updated';
	}

	public function get_title() {
		return GPU_Translations::t( 'Gold Price Last Update' );
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
			'format',
			array(
				'label'   => GPU_Translations::e( 'Date format (empty = site default)' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '',
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
		$format = trim( (string) $this->get_settings( 'format' ) );

		if ( '' === $format && isset( $options['format'] ) ) {
			$format = trim( (string) $options['format'] );
		}

		$last = (int) GPU_Settings::state_get( 'last_sync_ok', 0 );

		if ( $last <= 0 ) {
			return $this->wrap( '' );
		}

		$value = '' === $format
			? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last )
			: wp_date( $format, $last );

		return $this->wrap( $value );
	}
}
