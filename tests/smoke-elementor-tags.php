<?php
/**
 * Smoke test for the Elementor dynamic tags — runs WITHOUT WordPress.
 *
 * Stubs the minimal Elementor + WordPress API surface the tags touch, seeds
 * GPU_Settings / GPU_Products state via in-memory stubs, and asserts the
 * actual rendered values of every tag variant, including the before/after
 * wrapper and the two percent display modes.
 *
 * Run:  php tests/smoke-elementor-tags.php
 *
 * Reference numbers (verified against the real engine with
 * weight 2.5 g + accessory 0.5 g, effective gold 10,200,000,
 * making 15 %, stone fee 500,000, profit 7 %, VAT 10 %):
 *
 *   effective            10,200,000
 *   accessory value       5,100,000
 *   raw (incl. accessory)30,600,000
 *   making charge         3,825,000   (15 % of the main gold value only)
 *   stone fee               500,000
 *   seller profit         2,409,750   (7 % of raw + making)
 *   vat                     382,500   (10 % of making charge only)
 *   final                37,717,250
 *
 * @package GoldPriceUpdater
 */

namespace {
	error_reporting( E_ALL & ~E_DEPRECATED );

	define( 'GPU_TESTING', true );
	define( 'GPU_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	/*
	 * The WordPress-coupled classes (GPU_Settings, GPU_Gold_Price_Service,
	 * GPU_Products) guard with ABSPATH only; without it they silently
	 * exit(0). Define it so the harness can load them.
	 */
	define( 'ABSPATH', GPU_PLUGIN_DIR );

	// ------------------------------------------------------- WP stubs.
	$GLOBALS['__gpu_options'] = array();

	function get_option( $name, $default = false ) {
		return isset( $GLOBALS['__gpu_options'][ $name ] ) ? $GLOBALS['__gpu_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__gpu_options'][ $name ] = $value;
		return true;
	}
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		}
		if ( ! is_array( $args ) ) {
			$args = array();
		}
		return array_merge( $defaults, $args );
	}
	function get_locale() {
		return 'fa_IR';
	}
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, max( 0, (int) $decimals ), '.', ',' );
	}
	function esc_html( $text ) {
		return $text;
	}
	function __( $text, $domain = null ) {
		return $text;
	}
	function get_post_meta( $id, $key, $single = true ) {
		$meta = isset( $GLOBALS['__gpu_options']['__meta'] ) ? $GLOBALS['__gpu_options']['__meta'] : array();
		return isset( $meta[ $id ][ $key ] ) ? $meta[ $id ][ $key ] : '';
	}
	function get_post_type( $id ) {
		return 'product';
	}
	function get_the_ID() {
		return 0;
	}
	function wp_date( $format, $ts ) {
		return date( 'Y-m-d', (int) $ts );
	}

	// A minimal WC_Product-like object with exactly the getters tags call.
	class GPU_Fake_Product {
		public $id;
		public $parent_id = 0;
		public $weight;

		public function __construct( $id, $weight, $parent_id = 0 ) {
			$this->id        = $id;
			$this->weight    = $weight;
			$this->parent_id = $parent_id;
		}
		public function get_id() {
			return $this->id;
		}
		public function get_parent_id() {
			return $this->parent_id;
		}
		public function get_weight( $context = 'view' ) {
			return $this->weight;
		}
	}

	function wc_get_product( $id ) {
		static $repo = array(
			12 => array( 'id' => 12, 'weight' => 2.5, 'parent' => 0 ),
			13 => array( 'id' => 13, 'weight' => 1.0, 'parent' => 12 ), // variation of 12.
		);
		if ( ! isset( $repo[ $id ] ) ) {
			return null;
		}

		return new GPU_Fake_Product( $repo[ $id ]['id'], $repo[ $id ]['weight'], $repo[ $id ]['parent'] );
	}
}

namespace Elementor {
	class Controls_Manager {
		const TEXT   = 'text';
		const NUMBER = 'number';
		const SELECT = 'select';
	}

	// Minimal document/manager stub so GPU_Elem_Base::current_product()
	// resolves through the "Elementor document" strategy.
	class GPU_Document_Stub {
		public $main_post_id = 12;
		public function get_main_post_id() {
			return $this->main_post_id;
		}
	}
	class GPU_Documents_Stub {
		public $current_main_id = 12;
		public function get_current() {
			$d             = new GPU_Document_Stub();
			$d->main_post_id = $this->current_main_id;
			return $d;
		}
	}
	class Plugin {
		public static $instance;
	}
}

namespace Elementor\Core\DynamicTags {
	class Tag {
		public function add_control( $id, $args ) {}
	}
	class Data_Tag extends Tag {
		private $settings = array();
		public function __construct( $settings = array() ) {
			$this->settings = $settings;
		}
		public function get_settings( $key = null ) {
			if ( null === $key ) {
				return $this->settings;
			}
			return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : '';
		}
		public function get_content( array $options = array() ) {
			return $this->get_value( $options );
		}
	}
}

namespace Elementor\Modules\DynamicTags {
	class Module {
		const TEXT_CATEGORY = 'text';
	}
}

namespace {
	require GPU_PLUGIN_DIR . 'includes/class-gpu-pricing-engine.php';
	require GPU_PLUGIN_DIR . 'includes/class-gpu-price-normalizer.php';
	require GPU_PLUGIN_DIR . 'includes/class-gpu-settings.php';
	require GPU_PLUGIN_DIR . 'includes/class-gpu-gold-price-service.php';
	require GPU_PLUGIN_DIR . 'includes/class-gpu-products.php';
	require GPU_PLUGIN_DIR . 'includes/class-gpu-translations.php';
	require GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-base.php';
	require GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-price.php';
	require GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-updated.php';
	require GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-product-fields.php';
	require GPU_PLUGIN_DIR . 'includes/elementor/class-gpu-elem-product-price.php';

	// Wire the Elementor singleton used by current_product().
	\Elementor\Plugin::$instance          = new \Elementor\Plugin();
	\Elementor\Plugin::$instance->documents = new \Elementor\GPU_Documents_Stub();

	// ------------------------------------------------------- seed data.
	// API 10,000,000 IRR/g + buffer 200,000 → effective 10,200,000.
	update_option( 'gpu_settings', array(
		'price_mode'                => 'api',
		'manual_price'              => 0.0,
		'buffer'                    => 200000.0,
		'karat'                     => '18k',
		'target_currency'           => 'IRR',
		'seller_profit_pct'         => 7.0,
		'vat_pct'                   => 10.0,
		'default_making_charge_pct' => 15.0,
		'decimals'                  => 2,
	) );
	update_option( 'gpu_state', array( 'api_price' => 10000000.0, 'last_sync_ok' => time() ) );

	// Product 12: 2.5 g, accessory 0.5 g, stone fee 500,000, accessory NOT in making.
	$GLOBALS['__gpu_options']['__meta'] = array(
		12 => array(
			'_gpu_managed'           => 'yes',
			'_gpu_making_charge_pct' => '15',
			'_gpu_accessory_weight'  => '0.5',
			'_gpu_stone_fee'         => '500000',
			'_gpu_seller_profit_pct' => '7',
			'_gpu_vat_pct'           => '10',
			'_gpu_acc_in_making'     => 'no',
		),
	);

	$failures = 0;
	function check( $label, $actual, $expected ) {
		global $failures;
		$ok = $actual === $expected;
		if ( ! $ok ) {
			$failures++;
		}
		echo ( $ok ? 'PASS' : 'FAIL' ) . '  ' . $label . "\n";
		if ( ! $ok ) {
			echo '      expected: ' . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) . "\n";
		}
	}

	// ------------------------------------------------------- 1) Gold Price.
	$tag = new GPU_Elem_Price( array( 'variant' => 'effective', 'decimals' => 0 ) );
	check( 'price/effective', $tag->get_value(), '10,200,000' );

	$tag = new GPU_Elem_Price( array( 'variant' => 'api', 'decimals' => 0 ) );
	check( 'price/api', $tag->get_value(), '10,000,000' );

	$tag = new GPU_Elem_Price( array( 'variant' => 'buffer', 'decimals' => 0 ) );
	check( 'price/buffer', $tag->get_value(), '200,000' );

	$tag = new GPU_Elem_Price( array( 'variant' => 'karat' ) );
	check( 'price/karat', $tag->get_value(), '18k' );

	$tag = new GPU_Elem_Price( array( 'variant' => 'currency' ) );
	check( 'price/currency', $tag->get_value(), 'IRR' );

	$tag = new GPU_Elem_Price( array( 'variant' => 'source' ) );
	check( 'price/source', $tag->get_value(), 'api' );

	// ------------------------------------------------------- 2) Wrapper.
	$tag = new GPU_Elem_Price( array(
		'variant'      => 'effective',
		'decimals'     => 0,
		'gpu_before'   => 'قیمت: ',
		'gpu_after'    => ' تومان',
		'gpu_fallback' => 'هنوز قیمت ثبت نشده',
	) );
	check( 'wrapper/before-after', $tag->get_value(), 'قیمت: 10,200,000 تومان' );

	// Fallback: no synced price at all → fallback text, no before/after.
	update_option( 'gpu_state', array() );
	$tag = new GPU_Elem_Price( array(
		'variant'      => 'effective',
		'decimals'     => 0,
		'gpu_before'   => 'قیمت: ',
		'gpu_fallback' => 'هنوز قیمت ثبت نشده',
	) );
	check( 'wrapper/fallback', $tag->get_value(), 'هنوز قیمت ثبت نشده' );
	update_option( 'gpu_state', array( 'api_price' => 10000000.0, 'last_sync_ok' => time() ) );

	// ------------------------------------------------------- 3) Last update.
	$tag = new GPU_Elem_Updated( array(
		'format'     => 'Y-m-d',
		'gpu_before' => '[ ',
		'gpu_after'  => ' ]',
	) );
	check( 'updated/wrapped', $tag->get_value(), '[ ' . date( 'Y-m-d' ) . ' ]' );

	// ------------------------------------------------------- 4) Product fields.
	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'weight', 'decimals' => 1 ) );
	check( 'fields/weight', $tag->get_value(), '2.5' );

	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'making_charge', 'pct_display' => 'raw_number' ) );
	check( 'fields/making-charge/raw', $tag->get_value(), '15' );

	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'profit', 'pct_display' => 'raw_number' ) );
	check( 'fields/profit/raw', $tag->get_value(), '7' );

	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'vat', 'pct_display' => 'raw_number' ) );
	check( 'fields/vat/raw', $tag->get_value(), '10' );

	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'making_charge', 'pct_display' => 'money_value' ) );
	check( 'fields/making-charge/money', $tag->get_value(), '3,825,000' );

	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'profit', 'pct_display' => 'money_value' ) );
	check( 'fields/profit/money', $tag->get_value(), '2,409,750' );

	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'vat', 'pct_display' => 'money_value' ) );
	check( 'fields/vat/money', $tag->get_value(), '382,500' );

	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'accessory', 'decimals' => 1 ) );
	check( 'fields/accessory', $tag->get_value(), '0.5' );

	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'stone_fee', 'decimals' => 0 ) );
	check( 'fields/stone-fee', $tag->get_value(), '500,000' );

	$tag = new GPU_Elem_Product_Fields( array(
		'field'        => 'stone_fee',
		'decimals'     => 0,
		'gpu_after'    => ' تومان',
		'gpu_fallback' => '—',
	) );
	check( 'fields/stone-fee/wrapped', $tag->get_value(), '500,000 تومان' );

	// Decimal percents must survive display and calculation untouched.
	$GLOBALS['__gpu_options']['__meta'][12]['_gpu_making_charge_pct'] = '7.7';

	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'making_charge', 'pct_display' => 'raw_number', 'decimals' => 0 ) );
	check( 'fields/making-charge/decimal-raw-0dp', $tag->get_value(), '7.7' );

	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'making_charge', 'pct_display' => 'raw_number', 'decimals' => 2 ) );
	check( 'fields/making-charge/decimal-raw-2dp', $tag->get_value(), '7.7' );

	$GLOBALS['__gpu_options']['__meta'][12]['_gpu_making_charge_pct'] = '8.25';
	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'making_charge', 'pct_display' => 'raw_number', 'decimals' => 0 ) );
	check( 'fields/making-charge/decimal-raw-two-decimals', $tag->get_value(), '8.25' );

	// 7.7% of the 25,500,000 making base must be exact: 1,963,500.
	$GLOBALS['__gpu_options']['__meta'][12]['_gpu_making_charge_pct'] = '7.7';
	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'making_charge', 'pct_display' => 'money_value', 'decimals' => 0 ) );
	check( 'fields/making-charge/decimal-money-exact', $tag->get_value(), '1,963,500' );

	$GLOBALS['__gpu_options']['__meta'][12]['_gpu_making_charge_pct'] = '15';

	// ------------------------------------------------------- 5) Price breakdown.
	$tag = new GPU_Elem_Product_Price( array( 'component' => 'final_price', 'decimals' => 0 ) );
	check( 'breakdown/final', $tag->get_value(), '37,717,250' );

	$tag = new GPU_Elem_Product_Price( array( 'component' => 'raw_gold_value', 'decimals' => 0 ) );
	check( 'breakdown/raw', $tag->get_value(), '30,600,000' );

	$tag = new GPU_Elem_Product_Price( array( 'component' => 'accessory_gold_value', 'decimals' => 0 ) );
	check( 'breakdown/accessory', $tag->get_value(), '5,100,000' );

	$tag = new GPU_Elem_Product_Price( array( 'component' => 'making_charge', 'decimals' => 0 ) );
	check( 'breakdown/making', $tag->get_value(), '3,825,000' );

	$tag = new GPU_Elem_Product_Price( array( 'component' => 'stone_fee', 'decimals' => 0 ) );
	check( 'breakdown/stone', $tag->get_value(), '500,000' );

	$tag = new GPU_Elem_Product_Price( array( 'component' => 'effective_gold_price', 'decimals' => 0 ) );
	check( 'breakdown/effective', $tag->get_value(), '10,200,000' );

	// ------------------------------------------------------- 6) Variation context.
	\Elementor\Plugin::$instance->documents->current_main_id = 13;
	$tag = new GPU_Elem_Product_Fields( array( 'field' => 'making_charge', 'pct_display' => 'raw_number' ) );
	check( 'variation/managed-inherit', $tag->get_value(), '15' );
	\Elementor\Plugin::$instance->documents->current_main_id = 12;

	echo "\n" . ( 0 === $failures ? 'ALL CHECKS PASSED' : $failures . ' CHECK(S) FAILED' ) . "\n";
	exit( 0 === $failures ? 0 : 1 );
}
