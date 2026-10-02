<?php
/**
 * Number formatting — one place decides how prices look.
 *
 * Separators are user-configurable (dashboard → Pricing):
 *   thousand_sep — character between digit groups ('' = no grouping).
 *   decimal_sep  — character before the decimals.
 * Defaults: '.' (thousands) and ',' (decimals).
 *
 * Always use GPU_Formatter::number() instead of number_format_i18n() so the
 * dashboard, admin notices and Elementor tags all render identically.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'GPU_TESTING' ) ) {
	exit;
}

final class GPU_Formatter {

	/**
	 * Format a number with the configured separators.
	 *
	 * @param float|int|string $value    Numeric value.
	 * @param int              $decimals Decimal places (>= 0).
	 *
	 * @return string
	 */
	public static function number( $value, $decimals = 0 ) {
		$th = self::separator( 'thousand_sep', '.' );
		$de = self::separator( 'decimal_sep', ',' );
		if ( '' === $de ) {
			$de = '.'; // A price always needs a decimal point fallback.
		}

		if ( '' === $th ) {
			// Grouping disabled: format with a placeholder comma, then strip it.
			return str_replace( ',', '', number_format( (float) $value, max( 0, (int) $decimals ), $de, ',' ) );
		}

		return number_format( (float) $value, max( 0, (int) $decimals ), $de, $th );
	}

	/**
	 * Read a separator setting (first character only; '' = none).
	 *
	 * @param string $key      Setting key.
	 * @param string $fallback Default when the setting is missing entirely.
	 *
	 * @return string Single character, or '' when grouping is disabled.
	 */
	private static function separator( $key, $fallback ) {
		$raw = trim( (string) GPU_Settings::get_setting( $key, $fallback ) );

		if ( '' === $raw ) {
			return '';
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, 1 ) : substr( $raw, 0, 1 );
	}

	/**
	 * Sanitize a separator posted from the settings form.
	 *
	 * Accepts any single (multi-byte) character; letters and digits are
	 * rejected (a separator like "a" or "7" would corrupt every price).
	 *
	 * @param string $raw      Posted value.
	 * @param string $fallback Default when the value is invalid.
	 *
	 * @return string Single character, or '' for thousand_sep to disable grouping.
	 */
	public static function sanitize_separator( $raw, $fallback ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return '';
		}

		$char = function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, 1 ) : substr( $raw, 0, 1 );

		if ( function_exists( 'ctype_alnum' ) && ctype_alnum( $char ) ) {
			return $fallback; // Never let a digit/letter become a separator.
		}

		return $char;
	}
}
