<?php
/**
 * Elementor dynamic tag: last gold price update time.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @noinspection PhpUndefinedClassInspection
 */
class GPU_Elem_Updated extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name() {
		return 'gpu_gold_updated';
	}

	public function get_title() {
		return __( 'Gold Price Last Update', 'gold-price-updater' );
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
				'label'   => __( 'Date format (empty = site default)', 'gold-price-updater' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '',
			)
		);
	}

	/**
	 * @param array<string, mixed> $options
	 *
	 * @return string
	 */
	public function get_value( array $options = array() ) {
		$format = isset( $options['format'] ) ? (string) $options['format'] : '';
		$last   = (int) GPU_Settings::state_get( 'last_sync_ok', 0 );

		if ( $last <= 0 ) {
			return '';
		}

		return '' === $format
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last )
			: date_i18n( $format, $last );
	}
}
