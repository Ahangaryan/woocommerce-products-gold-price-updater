<?php
/**
 * Provider presets + pluggable parsing per provider.
 *
 * Presets fill the API settings form; parsing is per-provider so each API's
 * response shape is handled explicitly. Adding a provider = one array entry
 * here (or via the gpu_api_providers filter) — the client, sync and UI layers
 * never switch on provider names.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'GPU_TESTING' ) ) {
	exit;
}

final class GPU_Api_Providers {

	/**
	 * All known providers (filterable via gpu_api_providers).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all() {
		$providers = array(
			'custom' => array(
				'label'     => 'Custom JSON API',
				'auth'      => 'none',
				'url'       => '',
				'json_path' => '',
				'unit'      => 'gram_18k',
				'currency'  => 'IRR',
				'note'      => 'Generic JSON API — set URL, auth, JSON path, unit and currency yourself.',
			),
			'pie'    => array(
				'label'     => 'Price Intelligence Engine',
				'auth'      => 'header',
				'url'       => 'https://aradev.ir/price-engine/public/api/v1/prices/gold_18k',
				'json_path' => 'data.price',
				'unit'      => 'gram_18k',
				'currency'  => 'TOMAN',
				'note'      => 'Your Price Engine at aradev.ir. Uses X-API-Key header for auth.',
			),
			'tgju'   => array(
				'label'     => 'TGJU (Rial per gram 18k)',
				'auth'      => 'none',
				'url'       => 'https://api.tgju.org/v1/market/indicator/summary-table-data/geram18',
				'json_path' => 'data.0.0',
				'unit'      => 'gram_18k',
				'currency'  => 'IRR',
				'note'      => 'Public TGJU endpoint; Rial per gram of 18k gold. No auth needed.',
			),
		);

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Register more providers.
			 *
			 * @param array<string, array<string, mixed>> $providers
			 */
			$providers = apply_filters( 'gpu_api_providers', $providers );
		}

		return $providers;
	}

	/**
	 * Parse a provider's response into a raw quote.
	 *
	 * @param string               $provider Provider key.
	 * @param string               $body     Raw response body.
	 * @param array<string, mixed> $settings
	 *
	 * @return array{ok: bool, price?: float, error?: string}
	 */
	public static function parse( $provider, $body, array $settings = array() ) {
		switch ( $provider ) {
			case 'pie':
				return self::parse_pie( $body );
			case 'tgju':
				return self::parse_tgju( $body );
		}

		return array( 'ok' => false, 'error' => 'Unknown provider: ' . $provider );
	}

	// ------------------------------------------------------------- parsers

	/**
	 * Price Intelligence Engine: /api/v1/prices/{entity}
	 * Response: { "status": "ok", "data": { "price": 12345678, "currency": "IRR", ... } }
	 *
	 * @param string $body
	 *
	 * @return array{ok: bool, price?: float, error?: string}
	 */
	private static function parse_pie( $body ) {
		$data = json_decode( (string) $body, true );
		if ( ! is_array( $data ) ) {
			return array( 'ok' => false, 'error' => 'Engine response is not valid JSON.' );
		}

		// Check for envelope: { "status": "ok", "data": { "price": ... } }
		if ( isset( $data['data']['price'] ) && is_numeric( $data['data']['price'] ) ) {
			return array( 'ok' => true, 'price' => (float) $data['data']['price'] );
		}

		// Flat format: { "price": ... }
		$price = $data['price'] ?? null;
		if ( is_numeric( $price ) ) {
			return array( 'ok' => true, 'price' => (float) $price );
		}

		// Check for error status.
		if ( isset( $data['status'] ) && 'ok' !== $data['status'] ) {
			return array( 'ok' => false, 'error' => 'Engine returned status: ' . (string) $data['status'] );
		}

		return array( 'ok' => false, 'error' => 'Engine response has no numeric price.' );
	}

	/**
	 * TGJU summary-table API.
	 *
	 * Response shape:
	 *   { "data": [ ["221,970,000", "221,970,000", ...], ... ] }
	 *
	 * data[0][0] = last price (Rial per gram 18k).
	 * data[0][1] = close price.
	 * data[0][2] = high.
	 * data[0][3] = low.
	 *
	 * @param string $body
	 *
	 * @return array{ok: bool, price?: float, error?: string}
	 */
	private static function parse_tgju( $body ) {
		$data = json_decode( (string) $body, true );
		$raw  = null;

		if ( is_array( $data ) && isset( $data['data'][0][0] ) ) {
			$raw = $data['data'][0][0];
		}

		if ( null === $raw ) {
			return array( 'ok' => false, 'error' => 'TGJU response shape not recognized. Expected data.0.0.' );
		}

		// TGJU prices are comma-separated strings like "221,970,000".
		$price = str_replace( array( ',', ' ' ), '', (string) $raw );
		if ( ! is_numeric( $price ) ) {
			return array( 'ok' => false, 'error' => 'TGJU price is not numeric: ' . (string) $raw );
		}

		return array( 'ok' => true, 'price' => (float) $price );
	}
}
