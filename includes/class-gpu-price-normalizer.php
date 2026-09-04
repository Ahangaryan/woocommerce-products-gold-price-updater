<?php
/**
 * Price Normalizer — converts any provider unit into "per gram, per karat".
 *
 * PURE class: no WordPress, no I/O, no API knowledge. BCMath string math
 * like the pricing engine so conversions never drift.
 *
 * Supported price units (provider side):
 *   gram_18k, gram_24k, gram_21k, gram_18k_v2, gram, ounce, kilogram,
 *   mithqal, mesghal — plus any custom unit registered via the
 *   gpu_price_units filter (value = grams per one unit).
 *
 * Supported currencies: IRR (Rial), Toman, USD — custom via gpu_currency_factors.
 * Toman ↔ Rial is not a hard-coded guess: the factor comes from the provider
 * preset (rare providers quote in Toman) and is validated in tests.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'GPU_TESTING' ) ) {
	exit;
}

final class GPU_Price_Normalizer {

	/** Grams per unit for known mass units. */
	const UNITS = array(
		'gram_18k'    => 1.0,
		'gram_24k'    => 1.0,
		'gram_21k'    => 1.0,
		'gram_18k_v2' => 1.0,
		'gram'        => 1.0,
		'ounce'       => 31.1034768,
		'kilogram'    => 1000.0,
		'mithqal'     => 4.6083,
		'mesghal'     => 4.3318,
	);

	/**
	 * Currency conversion factors: value = how many IRR in 1 unit of this currency.
	 *
	 * 1 TOMAN = 10 IRR → factor 10.
	 * 1 USD ≈ 500,000 IRR (approximate; override via gpu_currency_factors filter).
	 */
	const CURRENCY_FACTORS = array(
		'IRR'   => 1.0,
		'TOMAN' => 10.0,
		'USD'   => 500000.0,
	);

	/** Karat purities. */
	const PURITIES = array(
		'24k' => 0.999,
		'18k' => 0.750,
		'14k' => 0.585,	);

	/**
	 * Normalize a provider quote to a per-gram price in the target currency.
	 *
	 * Karat semantics: the quote has a SOURCE karat (implied by gram_XXk
	 * units; plain gram/ounce/kg are treated as already being the target
	 * basis) and $karat is the TARGET basis the shop prices with:
	 * target = source × purity(target) / purity(source).
	 *
	 * @param array<string, mixed> $quote price, price_unit, currency, target_currency.
	 * @param string               $karat Target karat ('18k'|'24k'|... or 'auto' = keep source).
	 *
	 * @return float|null Null when the quote or target is unusable.
	 */
	public static function to_gram( array $quote, $karat = 'auto' ) {
		$price = self::num( $quote['price'] ?? null );
		$unit  = strtolower( trim( (string) ( $quote['price_unit'] ?? '' ) ) );
		$cur   = strtoupper( trim( (string) ( $quote['currency'] ?? '' ) ) );
		$tcur  = strtoupper( trim( (string) ( $quote['target_currency'] ?? 'IRR' ) ) );
		$karat = strtolower( trim( (string) $karat ) );

		if ( '' === $price || '' === $unit || '' === $cur || '' === $tcur ) {
			return null;
		}

		$units = self::price_units();
		if ( ! isset( $units[ $unit ] ) ) {
			return null;
		}

		$factors = self::currency_factors();
		if ( ! isset( $factors[ $cur ] ) || ! isset( $factors[ $tcur ] ) ) {
			return null;
		}

		$grams_per_unit = self::num( $units[ $unit ] );
		if ( '' === $grams_per_unit || self::cmp( $grams_per_unit, '0' ) === 0 ) {
			return null;
		}

		// Per-gram in the provider's currency.
		$per_gram = self::div( $price, $grams_per_unit );

		// Currency conversion (only when the currencies actually differ).
		if ( $cur !== $tcur ) {
			// price_in_target = price_in_source × factor(source) / factor(target)
			$ratio = $factors[ $cur ] / $factors[ $tcur ];
			$converted = self::mul( $per_gram, self::num( $ratio ) );
			$per_gram  = '' === $converted ? '' : $converted;
		}

		if ( '' === $per_gram ) {
			return null;
		}

		// Karat conversion: source (unit-implied) → target basis.
		$source_karat  = self::karat_from_unit( $unit );
		$target_karat  = ( 'auto' === $karat || '' === $karat ) ? $source_karat : strtolower( trim( (string) $karat ) );
		$src_purity    = self::purity( $source_karat );
		$tgt_purity    = self::purity( $target_karat );

		if ( null === $src_purity || null === $tgt_purity || $src_purity <= 0 ) {
			return null;
		}

		if ( $target_karat !== $source_karat ) {
			$per_gram = self::mul( $per_gram, self::num( $tgt_purity / $src_purity ) );
		}

		return self::cmp( $per_gram, '0' ) <= 0 ? null : (float) $per_gram;
	}

	/**
	 * Registered price units (grams per one unit), filterable.
	 *
	 * @return array<string, float>
	 */
	public static function price_units() {
		$units = self::UNITS;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Add or override price units (grams per one unit).
			 *
			 * @param array<string, float> $units
			 */
			$units = apply_filters( 'gpu_price_units', $units );
		}

		return $units;
	}

	/**
	 * Registered currency factors relative to IRR, filterable.
	 *
	 * @return array<string, float>
	 */
	public static function currency_factors() {
		$factors = self::CURRENCY_FACTORS;

		if ( function_exists( 'apply_filters' ) ) {
			/** This filter is documented in self::currency_factors(). */
			$factors = apply_filters( 'gpu_currency_factors', $factors );
		}

		return $factors;
	}

	/**
	 * List of unit keys for settings dropdowns.
	 *
	 * @return list<string>
	 */
	public static function unit_keys() {
		return array_keys( self::price_units() );
	}

	/**
	 * The karat implied by a gram_XXk unit name, else the default 18k.
	 *
	 * @param string $unit
	 *
	 * @return string
	 */
	public static function karat_from_unit( $unit ) {
		if ( preg_match( '/gram_([0-9]{2})k/', strtolower( $unit ), $m ) ) {
			$purity = self::purity_of_karat( (int) $m[1] );
			if ( null !== $purity ) {
				return (string) $m[1] . 'k';
			}
		}

		return '18k';
	}

	/**
	 * Purity fraction for a karat string ('18k', '21k', ...).
	 *
	 * @param string $karat
	 *
	 * @return float|null
	 */
	public static function purity( $karat ) {
		$karat = strtolower( trim( (string) $karat ) );

		if ( isset( self::PURITIES[ $karat ] ) ) {
			return self::PURITIES[ $karat ];
		}

		if ( preg_match( '/^(\d{1,2})k$/', $karat, $m ) ) {
			return self::purity_of_karat( (int) $m[1] );
		}

		return null;
	}

	/**
	 * Purity fraction for a karat number (e.g. 18 → 0.75).
	 *
	 * @param int $karat
	 *
	 * @return float|null
	 */
	public static function purity_of_karat( $karat ) {
		if ( $karat <= 0 || $karat > 24 ) {
			return null;
		}

		return round( $karat / 24, 4 );
	}

	// ------------------------------------------------------------ BCMath core

	private static function num( $value ) {
		if ( is_bool( $value ) || null === $value ) {
			return '';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			return rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' );
		}
		$string = trim( (string) $value );
		if ( '' === $string || ! preg_match( '/^-?\d+(\.\d+)?$/', $string ) ) {
			return '';
		}

		return $string;
	}

	private static function add( $a, $b ) {
		$a = self::num( $a );
		$b = self::num( $b );
		if ( '' === $a || '' === $b ) {
			return '';
		}

		return function_exists( 'bcadd' ) ? bcadd( $a, $b, 12 ) : (string) ( (float) $a + (float) $b );
	}

	private static function mul( $a, $b ) {
		$a = self::num( $a );
		$b = self::num( $b );
		if ( '' === $a || '' === $b ) {
			return '';
		}

		return function_exists( 'bcmul' ) ? bcmul( $a, $b, 12 ) : (string) ( (float) $a * (float) $b );
	}

	private static function div( $a, $b ) {
		$a = self::num( $a );
		$b = self::num( $b );
		if ( '' === $a || '' === $b || self::cmp( $b, '0' ) === 0 ) {
			return '';
		}

		return function_exists( 'bcdiv' ) ? bcdiv( $a, $b, 12 ) : (string) ( (float) $a / (float) $b );
	}

	private static function cmp( $a, $b ) {
		$a = self::num( $a );
		$b = self::num( $b );
		if ( '' === $a || '' === $b ) {
			return - 1;
		}
		if ( function_exists( 'bccomp' ) ) {
			return bccomp( $a, $b, 12 );
		}

		return ( $a == $b ) ? 0 : ( (float) $a < (float) $b ? - 1 : 1 ); // phpcs:ignore Universal.Operators.StrictComparisons
	}
}
