<?php
/**
 * Gold Price Service — the single source of truth for "the" current gold price.
 *
 * Resolution order (per the configured price_mode):
 *   api      → cached API price only.
 *   manual   → manual price only.
 *   fallback → API price, falling back to manual / last-valid when the API is down.
 *
 * Consumers (sync, elementor, product meta box preview) all read from here —
 * nobody else owns price state.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_Gold_Price_Service {

	/**
	 * The effective gold price (already normalized per gram + buffered) or null.
	 *
	 * @return float|null
	 */
	public static function effective_price() {
		$settings = GPU_Settings::get();
		$state    = GPU_Settings::state();

		$api_price    = isset( $state['api_price'] ) ? (float) $state['api_price'] : null;
		$manual_price = (float) ( $settings['manual_price'] ?? 0 );
		$mode         = (string) ( $settings['price_mode'] ?? 'fallback' );

		switch ( $mode ) {
			case 'manual':
				$base = $manual_price > 0 ? $manual_price : null;
				break;

			case 'api':
				$base = $api_price;
				break;

			case 'fallback':
			default:
				if ( null !== $api_price && $api_price > 0 ) {
					$base = $api_price;
				} elseif ( $manual_price > 0 ) {
					$base = $manual_price;
				} else {
					$base = null;
				}
				break;
		}

		if ( null === $base || $base <= 0 ) {
			return null;
		}

		$buffer = max( 0.0, (float) ( $settings['buffer'] ?? 0 ) );

		return $base + $buffer;
	}

	/**
	 * The base (pre-buffer) price actually in use, plus its source.
	 *
	 * @return array{price: float|null, source: string} source: api|manual|none.
	 */
	public static function current_with_source() {
		$settings = GPU_Settings::get();
		$state    = GPU_Settings::state();

		$api_price    = isset( $state['api_price'] ) ? (float) $state['api_price'] : null;
		$manual_price = (float) ( $settings['manual_price'] ?? 0 );
		$mode         = (string) ( $settings['price_mode'] ?? 'fallback' );

		if ( 'manual' === $mode ) {
			return array(
				'price'  => $manual_price > 0 ? $manual_price : null,
				'source' => 'manual',
			);
		}

		if ( 'api' === $mode ) {
			return array(
				'price'  => $api_price,
				'source' => null !== $api_price ? 'api' : 'none',
			);
		}

		// fallback.
		if ( null !== $api_price && $api_price > 0 ) {
			return array( 'price' => $api_price, 'source' => 'api' );
		}
		if ( $manual_price > 0 ) {
			return array( 'price' => $manual_price, 'source' => 'manual' );
		}

		return array( 'price' => null, 'source' => 'none' );
	}

	/**
	 * Persist a freshly fetched API price (post-validation). Only valid prices
	 * ever reach this method — the current price never changes on failure.
	 *
	 * @param float $price Per-gram base price (pre-buffer), site currency.
	 *
	 * @return void
	 */
	public static function store_api_price( $price ) {
		$state = array(
			'api_price'        => (float) $price,
			'last_sync_ok'     => time(),
			'last_sync_source' => 'api',
		);

		// Keep last_valid_price as an explicit rollback point.
		$previous = GPU_Settings::state_get( 'api_price', null );
		if ( null !== $previous && (float) $previous > 0 ) {
			$state['last_valid_price'] = (float) $previous;
		}

		GPU_Settings::state_save( $state );
	}

	/**
	 * Record a failed sync attempt (timestamps only — price untouched).
	 *
	 * @param string $error
	 *
	 * @return void
	 */
	public static function record_failure( $error ) {
		GPU_Settings::state_save(
			array(
				'last_sync_attempt' => time(),
				'last_error'        => (string) $error,
				'failure_count'     => (int) GPU_Settings::state_get( 'failure_count', 0 ) + 1,
			)
		);
	}

	/**
	 * Reset consecutive failure counter after a success.
	 *
	 * @return void
	 */
	public static function record_success() {
		GPU_Settings::state_save(
			array(
				'last_sync_attempt' => time(),
				'last_error'        => '',
				'failure_count'     => 0,
			)
		);
	}

	/**
	 * Last error message (empty string when none).
	 *
	 * @return string
	 */
	public static function last_error() {
		return (string) GPU_Settings::state_get( 'last_error', '' );
	}

	/**
	 * Failure streak length.
	 *
	 * @return int
	 */
	public static function failure_count() {
		return (int) GPU_Settings::state_get( 'failure_count', 0 );
	}
}
