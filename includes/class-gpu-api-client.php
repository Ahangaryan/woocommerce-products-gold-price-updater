<?php
/**
 * API client — provider-agnostic HTTP layer with pluggable response parsing.
 *
 * Auth methods: none, header (X-API-Key + X-API-Secret), bearer, query (?api_key=),
 * basic. Custom headers can be injected per provider via the
 * gpu_api_request_headers filter. The raw response body is NEVER stored or
 * logged — only a sanitized summary (first 300 chars, keys only when debug is
 * on) to keep credentials out of the logs.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'GPU_TESTING' ) ) {
	exit;
}

final class GPU_Api_Client {

	/** @var array<string, mixed> */
	private $settings;

	/**
	 * @param array<string, mixed> $settings Settings subset (api_*, auth_*, timeout...).
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Fetch and parse a price quote.
	 *
	 * @return array{ok: bool, price?: float, http_code?: int, error?: string, summary?: string}
	 */
	public function fetch() {
		$url = trim( (string) ( $this->settings['api_url'] ?? '' ) );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return array( 'ok' => false, 'error' => 'Invalid or empty API URL.' );
		}

		$started = microtime( true );
		$result  = $this->request( $url );
		$elapsed = round( ( microtime( true ) - $started ) * 1000 );

		if ( ! $result['ok'] ) {
			return $result;
		}

		$parsed = $this->parse_body( $result['body'] );
		if ( ! $parsed['ok'] ) {
			GPU_Logger::error( 'api_parse_error', $parsed['error'], array( 'http_code' => $result['http_code'] ) );

			return array(
				'ok'       => false,
				'http_code' => $result['http_code'],
				'error'    => $parsed['error'],
				'summary'  => $this->summary( $result['body'] ),
			);
		}

		return array(
			'ok'       => true,
			'price'    => (float) $parsed['price'],
			'http_code' => $result['http_code'],
			'summary'  => $this->summary( $result['body'] ),
		);
	}

	/**
	 * Lightweight connectivity probe used by Test Connection.
	 *
	 * @return array{ok: bool, price?: float, http_code?: int, error?: string, summary?: string}
	 */
	public function test() {
		return $this->fetch();
	}

	// -------------------------------------------------------------- internals

	/**
	 * Perform the HTTP request via wp_remote_request.
	 *
	 * @param string $url
	 *
	 * @return array{ok: bool, body?: string, http_code?: int, error?: string}
	 */
	private function request( $url ) {
		$method = strtoupper( (string) ( $this->settings['request_method'] ?? 'GET' ) );
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
			$method = 'GET';
		}

		$headers = $this->build_headers();
		$args    = array(
			'method'     => $method,
			'timeout'    => max( 2, (int) ( $this->settings['timeout'] ?? 15 ) ),
			'redirection'=> 3,
			'headers'    => $headers,
			'body'       => 'POST' === $method ? (string) ( $this->settings['request_body'] ?? '' ) : null,
			'sslverify'  => true,
			'user-agent' => 'Gold-Price-Updater/' . GPU_VERSION,
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			GPU_Logger::error( 'api_transport_error', $response->get_error_message(), array( 'url_host' => wp_parse_url( $url, PHP_URL_HOST ) ) );

			return array( 'ok' => false, 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			GPU_Logger::error( 'api_http_error', 'HTTP ' . $code, array( 'http_code' => $code ) );

			// Provide a more helpful error message for common status codes.
			$hint = '';
			if ( 401 === $code ) {
				$hint = ' — Invalid or missing API credentials. Check your API key and auth method.';
			} elseif ( 403 === $code ) {
				$hint = ' — Access denied. Your API key may not have permission for this endpoint.';
			} elseif ( 404 === $code ) {
				$hint = ' — Endpoint not found. Verify the API URL is correct.';
			} elseif ( 429 === $code ) {
				$hint = ' — Rate limit exceeded. Try again later.';
			}

			return array(
				'ok'       => false,
				'http_code' => $code,
				'error'    => 'HTTP ' . $code . $hint,
				'body'     => $body,
			);
		}

		return array( 'ok' => true, 'body' => $body, 'http_code' => $code );
	}

	/**
	 * Build request headers, applying the auth method.
	 *
	 * @return array<string, string>
	 */
	private function build_headers() {
		$headers = array(
			'Accept' => 'application/json',
		);

		$auth   = (string) ( $this->settings['auth_method'] ?? 'none' );
		$key    = (string) ( $this->settings['api_key'] ?? '' );
		$secret = (string) ( $this->settings['api_secret'] ?? '' );

		switch ( $auth ) {
			case 'header':
				if ( '' !== $key ) {
					$headers['X-API-Key'] = $key;
				}
				if ( '' !== $secret ) {
					$headers['X-API-Secret'] = $secret;
				}
				break;
			case 'bearer':
				if ( '' !== $key ) {
					$headers['Authorization'] = 'Bearer ' . $key;
				}
				break;
			case 'basic':
				if ( '' !== $key ) {
					$headers['Authorization'] = 'Basic ' . base64_encode( $key . ':' . $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				}
				break;
		}

		/**
		 * Filter the outgoing headers (custom auth schemes, provider quirks).
		 * NOTE: never log these values.
		 *
		 * @param array<string, string> $headers
		 * @param array<string, mixed>  $settings
		 */
		$headers = apply_filters( 'gpu_api_request_headers', $headers, $this->settings );

		// Query-string auth is appended to the URL here.
		if ( 'query' === $auth && '' !== $key ) {
			$this->settings['api_url'] = add_query_arg( 'api_key', $key, (string) ( $this->settings['api_url'] ?? '' ) );
		}

		return $headers;
	}

	/**
	 * Extract the numeric price from the response body.
	 *
	 * Resolution order:
	 *  1. A provider preset parser (GPU_Api_Providers::parse) when one matches.
	 *  2. A configured JSON path (dot notation, arrays as [n]).
	 *  3. The first number found anywhere in the body (last resort).
	 *
	 * @param string $body
	 *
	 * @return array{ok: bool, price?: float, error?: string}
	 */
	private function parse_body( $body ) {
		$provider = (string) ( $this->settings['provider'] ?? 'custom' );

		if ( 'custom' !== $provider ) {
			$parsed = GPU_Api_Providers::parse( $provider, $body, $this->settings );
			if ( $parsed['ok'] ) {
				return $parsed;
			}
			// Fall through to json-path / raw parsing as a safety net.
		}

		$path = trim( (string) ( $this->settings['json_path'] ?? '' ) );
		if ( '' !== $path ) {
			$value = $this->extract_json_path( $body, $path );
			if ( null !== $value && is_numeric( $value ) ) {
				return array( 'ok' => true, 'price' => (float) $value );
			}

			return array( 'ok' => false, 'error' => 'JSON path "' . $path . '" did not resolve to a number.' );
		}

		// Last resort: first number in the body.
		if ( preg_match( '/\d+(?:[.,]\d+)?/', strip_tags( $body ), $m ) ) {
			return array( 'ok' => true, 'price' => (float) str_replace( ',', '', $m[0] ) );
		}

		return array( 'ok' => false, 'error' => 'No numeric price found in the API response.' );
	}

	/**
	 * Dot-notation JSON path lookup (e.g. data.price.0.value).
	 *
	 * @param string $body
	 * @param string $path
	 *
	 * @return mixed|null
	 */
	private function extract_json_path( $body, $path ) {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) && null === $data ) {
			return null;
		}

		$current = $data;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
				$current = $current[ $segment ];
			} elseif ( is_object( $current ) && isset( $current->{$segment} ) ) {
				$current = $current->{$segment};
			} else {
				return null;
			}
		}

		return $current;
	}

	/**
	 * Sanitized one-line summary of a response body (for diagnostics only).
	 *
	 * @param string $body
	 *
	 * @return string
	 */
	private function summary( $body ) {
		$debug = (int) ( GPU_Settings::get_setting( 'debug', 0 ) );
		$body  = trim( strip_tags( (string) $body ) );

		if ( ! $debug ) {
			// Non-debug: strip digits (prices) too, keep only the shape.
			$body = preg_replace( '/\d+(?:[.,]\d+)?/', '#', $body );
		}

		$body = (string) preg_replace( '/\s+/', ' ', $body );

		return substr( $body, 0, 300 );
	}
}
