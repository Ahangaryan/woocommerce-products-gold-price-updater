<?php
/**
 * Pricing Engine — the single source of truth for the gold pricing formula.
 *
 * PURE class: no WordPress, no WooCommerce, no I/O. Everything is passed in.
 * All arithmetic is done with BCMath (string math) to avoid floating point
 * drift; results are rounded once, at the end, with a deterministic mode.
 *
 * Formula (documented in docs/pricing-engine.md — do NOT copy it elsewhere):
 *
 *   effective_gold_price = gold_price + buffer
 *   accessory_gold_value = accessory_weight * effective_gold_price
 *   raw_gold_value       = (weight + accessory_weight) * effective_gold_price
 *   making_charge        = raw_gold_value * making_charge_pct / 100
 *   stone_fee            = fixed, added as-is
 *   seller_profit        = (raw_gold_value + making_charge) * profit_pct / 100
 *   vat                  = making_charge * vat_pct / 100          (making charge only!)
 *   final_price          = raw_gold_value + making_charge + stone_fee
 *                        + seller_profit + vat
 *
 * Extras (accessories) are opt-in: when `include_accessory_in_making` is
 * falsy the making charge applies to the main gold value only — accessory
 * weight is still priced (you pay for the metal) but carries no labor.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'GPU_TESTING' ) ) {
	exit;
}

final class GPU_Pricing_Engine {

	/**
	 * Compute a price breakdown from a structured input array.
	 *
	 * Input keys (all numeric, strings or ints; percentages are 0-100):
	 *   weight, gold_price, buffer, making_charge_pct, seller_profit_pct, vat_pct
	 * Optional: round_mode ('none'|'nearest'|'up'|'down'), round_step (>0),
	 *           decimals (default 2), currency_divisor (default 1),
	 *           accessory_weight (grams, priced at the same effective price),
	 *           stone_fee (fixed amount added to the final price),
	 *           include_accessory_in_making (truthy → making charge % also
	 *           applies to the accessory gold value; default falsy).
	 *
	 * @param array<string, mixed> $input See above.
	 *
	 * @return array<string, float>|null Null when the input is structurally invalid.
	 */
	public static function calculate( array $input ) {
		if ( ! self::has_valid_numbers( $input ) ) {
			return null;
		}

		$decimals = isset( $input['decimals'] ) ? max( 0, min( 6, (int) $input['decimals'] ) ) : 2;
		$divisor  = isset( $input['currency_divisor'] ) && (float) $input['currency_divisor'] > 0
			? (string) (float) $input['currency_divisor']
			: '1';

		$weight    = self::num( $input['weight'] );
		$gold      = self::num( $input['gold_price'] );
		$buffer    = self::num( isset( $input['buffer'] ) ? $input['buffer'] : 0 );
		$mk_pct    = self::num( isset( $input['making_charge_pct'] ) ? $input['making_charge_pct'] : 0 );
		$pr_pct    = self::num( isset( $input['seller_profit_pct'] ) ? $input['seller_profit_pct'] : 0 );
		$vat_pct   = self::num( isset( $input['vat_pct'] ) ? $input['vat_pct'] : 0 );
		$acc_w     = self::num( isset( $input['accessory_weight'] ) ? $input['accessory_weight'] : 0 );
		$stone_fee = self::num( isset( $input['stone_fee'] ) ? $input['stone_fee'] : 0 );

		// Domain validation: negative inputs are refused, not clamped.
		if ( self::cmp( $weight, '0' ) < 0 || self::cmp( $gold, '0' ) < 0 || self::cmp( $buffer, '0' ) < 0
			|| self::cmp( $mk_pct, '0' ) < 0 || self::cmp( $pr_pct, '0' ) < 0 || self::cmp( $vat_pct, '0' ) < 0
			|| self::cmp( $acc_w, '0' ) < 0 || self::cmp( $stone_fee, '0' ) < 0 ) {
			return null;
		}

		// §72: a zero/negative gold price or weight must never flow into a
		// product price — refuse instead of computing a 0/buffer-only price.
		if ( self::cmp( $gold, '0' ) <= 0 || self::cmp( $weight, '0' ) <= 0 ) {
			return null;
		}

		$effective  = self::add( $gold, $buffer );
		$acc_value  = self::div( self::mul( $acc_w, $effective ), $divisor );
		$raw        = self::add( self::div( self::mul( $weight, $effective ), $divisor ), $acc_value );

		// Making charge base: the whole (main + accessory) gold value when the
		// shop includes accessories in labor, otherwise the main value only.
		$mk_base    = ! empty( $input['include_accessory_in_making'] ) ? $raw : self::sub( $raw, $acc_value );
		$making     = self::div( self::mul( $mk_base, $mk_pct ), '100' );
		$profit     = self::div( self::mul( self::add( $raw, $making ), $pr_pct ), '100' );
		$vat        = self::div( self::mul( $making, $vat_pct ), '100' );
		$final_raw  = self::add( self::add( self::add( self::add( $raw, $making ), $stone_fee ), $profit ), $vat );

		$round_mode = isset( $input['round_mode'] ) ? (string) $input['round_mode'] : 'none';
		$round_step = isset( $input['round_step'] ) ? (float) $input['round_step'] : 0.0;
		$final      = $final_raw;
		if ( 'none' !== $round_mode && $round_step > 0 ) {
			$final = self::round_to_step( $final_raw, (string) $round_step, $round_mode );
		}

		return array(
			'effective_gold_price' => self::to_float( $effective ),
			'accessory_gold_value' => self::to_float( $acc_value ),
			'raw_gold_value'       => self::to_float( $raw ),
			'making_charge'        => self::to_float( $making ),
			'stone_fee'            => self::to_float( $stone_fee ),
			'seller_profit'        => self::to_float( $profit ),
			'vat'                  => self::to_float( $vat ),
			'final_price'          => self::to_float( $final ),
			'final_price_exact'    => self::to_float( $final_raw ),
		);
	}

	/**
	 * Convenience wrapper: structured input from WordPress settings/meta in one call.
	 *
	 * @param float $weight            Product weight in grams.
	 * @param float $gold_price        Current gold price (pre-buffer).
	 * @param float $buffer            Buffer added to the gold price.
	 * @param float $making_charge_pct Making charge percent.
	 * @param float $seller_profit_pct Seller profit percent.
	 * @param float $vat_pct           VAT percent (applied to making charge only).
	 * @param array<string, mixed> $opts decimals/round_mode/round_step/currency_divisor.
	 *
	 * @return array<string, float>|null
	 */
	public static function price( $weight, $gold_price, $buffer, $making_charge_pct, $seller_profit_pct, $vat_pct, array $opts = array() ) {
		// $opts may carry accessory_weight / stone_fee / include_accessory_in_making.
		return self::calculate(
			array_merge(
				array(
					'weight'            => $weight,
					'gold_price'        => $gold_price,
					'buffer'            => $buffer,
					'making_charge_pct' => $making_charge_pct,
					'seller_profit_pct' => $seller_profit_pct,
					'vat_pct'           => $vat_pct,
				),
				$opts
			)
		);
	}

	// ------------------------------------------------------------ BCMath core

	/**
	 * Convert arbitrary numeric input to a fixed BCMath string ('' when invalid).
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
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

	private static function has_valid_numbers( array $input ) {
		foreach ( array( 'weight', 'gold_price' ) as $required ) {
			if ( ! isset( $input[ $required ] ) || '' === self::num( $input[ $required ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function add( $a, $b ) {
		$a = self::num( $a );
		$b = self::num( $b );
		if ( '' === $a || '' === $b ) {
			return '';
		}

		return function_exists( 'bcadd' ) ? bcadd( $a, $b, 12 ) : self::fallback_add( $a, $b );
	}

	private static function mul( $a, $b ) {
		$a = self::num( $a );
		$b = self::num( $b );
		if ( '' === $a || '' === $b ) {
			return '';
		}

		return function_exists( 'bcmul' ) ? bcmul( $a, $b, 12 ) : self::fallback_mul( $a, $b );
	}

	private static function div( $a, $b ) {
		$a = self::num( $a );
		$b = self::num( $b );
		if ( '' === $a || '' === $b || self::cmp( $b, '0' ) === 0 ) {
			return '';
		}

		return function_exists( 'bcdiv' ) ? bcdiv( $a, $b, 12 ) : self::fallback_div( $a, $b );
	}

	/**
	 * Sign-safe comparison of two numeric strings.
	 *
	 * @return int -1, 0, 1
	 */
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

	/**
	 * Deterministic rounding to a step ('nearest', 'up', 'down').
	 *
	 * @param string $value BCMath string.
	 * @param string $step  Positive step.
	 * @param string $mode
	 *
	 * @return string
	 */
	private static function round_to_step( $value, $step, $mode ) {
		$steps = self::div( $value, $step );
		if ( '' === $steps ) {
			return $value;
		}

		$floored = self::floor_str( $steps );
		$rem     = self::sub_str( $steps, $floored );

		if ( 'down' === $mode ) {
			$n = $floored;
		} elseif ( 'up' === $mode ) {
			$n = self::cmp( $rem, '0' ) === 0 ? $floored : self::add( $floored, '1' );
		} else { // nearest (half up).
			$n = self::cmp( $rem, '0.5' ) >= 0 ? self::add( $floored, '1' ) : $floored;
		}

		return self::mul( $n, $step );
	}

	private static function floor_str( $value ) {
		$parts = explode( '.', $value );
		$int   = $parts[0];

		if ( isset( $parts[1] ) && self::cmp( '0.' . $parts[1], '0' ) > 0 && self::cmp( $value, '0' ) < 0 ) {
			$int = self::sub_str( $int, '1' ); // floor() for negatives.
		}

		return $int;
	}

	private static function sub_str( $a, $b ) {
		return function_exists( 'bcsub' ) ? bcsub( $a, $b, 12 ) : self::fallback_add( $a, (string) ( - (float) $b ) );
	}

	/**
	 * a − b on BCMath strings ('' propagates).
	 *
	 * @param string $a
	 * @param string $b
	 *
	 * @return string
	 */
	private static function sub( $a, $b ) {
		return self::sub_str( $a, $b );
	}

	// -------------------------------------------------- no-BCMath fallbacks.

	private static function fallback_add( $a, $b ) {
		return (string) ( (float) $a + (float) $b );
	}

	private static function fallback_mul( $a, $b ) {
		return (string) ( (float) $a * (float) $b );
	}

	private static function fallback_div( $a, $b ) {
		$b = (float) $b;

		return 0.0 === $b ? '' : (string) ( (float) $a / $b );
	}

	private static function to_float( $value ) {
		return '' === $value ? 0.0 : (float) $value;
	}
}
