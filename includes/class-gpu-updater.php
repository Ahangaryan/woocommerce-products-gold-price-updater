<?php
/**
 * GitHub Releases updater.
 *
 * Opt-in: set the "GitHub repo (owner/name)" setting. The plugin then:
 *  - checks GitHub's releases/latest once every 12h (transient-cached),
 *  - shows "Update available" in the plugins list,
 *  - lets WordPress fetch + install the release ZIP ("view version details"
 *    and the standard updater flow), keeping local config intact.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_Updater {

	const TRANSIENT = 'gpu_gh_release';
	const TTL       = 12 * HOUR_IN_SECONDS;

	/**
	 * @return void
	 */
	public static function register() {
		if ( '' === (string) GPU_Settings::get_setting( 'github_repo', '' ) ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
	}

	/**
	 * Latest release info from GitHub (cached).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function latest_release() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$repo = (string) GPU_Settings::get_setting( 'github_repo', '' );
		if ( '' === $repo || ! preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ) {
			return null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		$release = array(
			'version'      => ltrim( (string) $data['tag_name'], 'vV' ),
			'zipball_url'  => (string) ( $data['zipball_url'] ?? '' ),
			'html_url'     => (string) ( $data['html_url'] ?? '' ),
			'changelog'    => (string) ( $data['body'] ?? '' ),
			'download_link'=> (string) ( $data['zipball_url'] ?? '' ),
		);

		set_transient( self::TRANSIENT, $release, self::TTL );

		return $release;
	}

	/**
	 * Newer version available?
	 *
	 * @return bool
	 */
	public static function update_available() {
		$release = self::latest_release();

		return $release && version_compare( $release['version'], GPU_VERSION, '>' );
	}

	/**
	 * Inject the update into WordPress's update transient.
	 *
	 * @param object $transient
	 *
	 * @return object
	 */
	public static function inject_update( $transient ) {
		if ( empty( $transient->checked ) || ! self::update_available() ) {
			return $transient;
		}

		$release = self::latest_release();

		$transient->response[ plugin_basename( GPU_PLUGIN_FILE ) ] = (object) array(
			'slug'        => 'gold-price-updater',
			'plugin'      => plugin_basename( GPU_PLUGIN_FILE ),
			'new_version' => (string) $release['version'],
			'url'         => (string) $release['html_url'],
			'package'     => (string) $release['download_link'],
			'icons'       => array(),
			'banners'     => array(),
			'update-supported' => true,
		);

		return $transient;
	}

	/**
	 * Plugin info popup (view version details).
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 *
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== 'gold-price-updater' ) {
			return $result;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Gold Price Updater for WooCommerce',
			'slug'          => 'gold-price-updater',
			'version'       => (string) $release['version'],
			'download_link' => (string) $release['download_link'],
			'requires'      => '6.0',
			'tested'        => '6.8',
			'sections'      => array(
				'description' => __( 'Automatic gold price updates for WooCommerce with a dedicated pricing engine.', 'gold-price-updater' ),
				'changelog'   => nl2br( esc_html( $release['changelog'] ) ),
			),
			'banners'       => array(),
		);
	}
}
