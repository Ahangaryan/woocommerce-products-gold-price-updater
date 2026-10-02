<?php
/**
 * Smoke test — realtime sync pipeline (runs WITHOUT WordPress).
 *
 * Stubs the minimal WordPress API surface and verifies the actual behavior
 * of the sync/queue changes:
 *
 *   1. First sync stores the price and queues products.
 *   2. Fast path: an unchanged price fills NO queue rows (realtime cheapness).
 *   3. A changed price fills the queue via a multi-row INSERT and arms the
 *      request-level frontload signal.
 *   4. Force sync reprices even when the price is unchanged.
 *   5. realtime_tick() is a no-op when the plugin is disabled and hits the
 *      fast path when the price did not move.
 *   6. run_ticks() on an empty queue never touches the lock.
 *   7. maybe_frontload_queue() drains a pending queue on request load.
 *   8. cron_schedules()/reschedule() manage the realtime event.
 *
 * Run:  php tests/smoke-realtime-sync.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'GPU_TESTING', true );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );

// ------------------------------------------------------------------ WP stubs

$GLOBALS['__tests'] = array(
	'options' => array(),
	'events'  => array(),
);

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__tests']['options'] ) ? $GLOBALS['__tests']['options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['__tests']['options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	return update_option( $name, $value );
}

function get_transient( $key ) {
	return get_option( '_t_' . $key );
}

function set_transient( $key, $value, $expiration = 0 ) {
	return update_option( '_t_' . $key, $value );
}

function delete_transient( $key ) {
	unset( $GLOBALS['__tests']['options'][ '_t_' . $key ] );
	return true;
}

function wp_next_scheduled( $hook ) {
	return isset( $GLOBALS['__tests']['events'][ $hook ] ) ? $GLOBALS['__tests']['events'][ $hook ]['time'] : false;
}

function wp_schedule_single_event( $time, $hook, $args = array() ) {
	$GLOBALS['__tests']['events'][ $hook ] = array( 'time' => $time, 'args' => $args, 'single' => true );
	return true;
}

function wp_schedule_event( $time, $recurrence, $hook, $args = array() ) {
	$GLOBALS['__tests']['events'][ $hook ] = array( 'time' => $time, 'recurrence' => $recurrence, 'args' => $args );
	return true;
}

function wp_unschedule_event( $time, $hook, $args = array() ) {
	unset( $GLOBALS['__tests']['events'][ $hook ] );
	return true;
}

function wp_clear_scheduled_hook( $hook ) {
	unset( $GLOBALS['__tests']['events'][ $hook ] );
	return true;
}

function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	return true;
}

function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	return true;
}

function current_user_can( $cap ) {
	return true;
}

function wp_parse_args( $args, $defaults ) {
	return array_merge( (array) $defaults, (array) $args );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

function __( $text, $domain = null ) {
	return $text;
}

function esc_html( $text ) {
	return $text;
}

function get_post_type( $id ) {
	return 'product';
}

function is_email( $email ) {
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}

function wp_doing_ajax() {
	return false;
}

function wp_doing_cron() {
	return false;
}

function wc_get_product( $id ) {
	return new WC_Product_Stub( (int) $id );
}

class WC_Product_Stub {
	public $id;
	public function __construct( $id ) { $this->id = $id; }
	public function is_type( $type ) { return false; }
	public function get_parent_id() { return 0; }
}

// reschedule() early-returns without an active WooCommerce.
class WooCommerce {
}

class Fake_WPDB {
	public $prefix        = 'wp_';
	public $insert_id     = 0;
	public $queries       = array();
	public $get_col_queue = array();
	public $posts         = 'wp_posts';
	public $postmeta      = 'wp_postmeta';

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$sql = preg_replace(
				'/%[dsf]/',
				is_numeric( $arg ) ? (string) ( 0 + $arg ) : "'" . addslashes( (string) $arg ) . "'",
				$sql,
				1
			);
		}
		return $sql;
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'GET_LOCK' ) ) {
			return '1';
		}
		if ( false !== strpos( $sql, 'IS_USED_LOCK' ) ) {
			return '0';
		}
		return '1'; // RELEASE_LOCK.
	}

	public function get_results( $sql, $mode = null ) {
		$this->queries[] = $sql;
		return array();
	}

	public function get_row( $sql, $mode = null ) {
		$this->queries[] = $sql;
		return null;
	}

	public function get_col( $sql ) {
		$this->queries[] = $sql;
		return array_shift( $this->get_col_queue ) ?? array();
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}

	public function insert( $table, $data, $format = array() ) {
		$this->queries[] = 'INSERT INTO ' . $table;
		++$this->insert_id;
		return 1;
	}

	public function update( $table, $data, $where, $format = array(), $where_format = array() ) {
		$this->queries[] = 'UPDATE ' . $table;
		return 1;
	}

	public function get_charset_collate() {
		return '';
	}
}

$GLOBALS['wpdb'] = new Fake_WPDB();

// --------------------------------------------------------------- load plugin

require __DIR__ . '/../includes/class-gpu-logger.php';
require __DIR__ . '/../includes/class-gpu-settings.php';
require __DIR__ . '/../includes/class-gpu-formatter.php';
require __DIR__ . '/../includes/class-gpu-db.php';
require __DIR__ . '/../includes/class-gpu-products.php';
require __DIR__ . '/../includes/class-gpu-gold-price-service.php';
require __DIR__ . '/../includes/class-gpu-queue.php';
require __DIR__ . '/../includes/class-gpu-sync.php';
require __DIR__ . '/../includes/class-gpu-plugin.php';

// ----------------------------------------------------------------- harness

$failures = array();

function check( $name, $cond ) {
	global $failures;
	if ( $cond ) {
		echo "PASS  {$name}\n";
	} else {
		$failures[] = $name;
		echo "FAIL  {$name}\n";
	}
}

function queries_matching( $pattern, $since = 0 ) {
	return preg_grep( $pattern, array_slice( $GLOBALS['wpdb']->queries, $since ) );
}

function last_multirow_insert() {
	$found = '';
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( $q, 'INSERT INTO wp_gpu_queue' ) ) {
			$found = $q;
		}
	}
	return $found;
}

// ------------------------------------------------------------------ seeds

$settings               = GPU_Settings::defaults();
$settings['price_mode'] = 'manual';
$settings['manual_price'] = 5000000.0;
update_option( GPU_Settings::OPTION, $settings );
update_option( GPU_Settings::OPT_STATE, array() );

// 1) First sync — price appears, queue path runs.
$GLOBALS['wpdb']->get_col_queue = array( array(), array() );
$r                              = GPU_Sync::run( 'cron' );
check( 'first sync ok', ! empty( $r['ok'] ) );
check( 'first sync queued=0 (empty catalog)', 0 === ( $r['queued'] ?? -1 ) );
check( 'first sync stored the api price', 5000000.0 === (float) GPU_Settings::state_get( 'api_price', 0 ) );

// 2) Fast path — same price again: NO queue work at all.
$GLOBALS['wpdb']->get_col_queue = array( array( 11, 22 ), array(), array() ); // would be consumed by fill() if reached.
$r = GPU_Sync::run( 'cron' );
check( 'unchanged sync ok', ! empty( $r['ok'] ) );
check( 'unchanged → "unchanged" message', false !== strpos( (string) $r['message'], 'unchanged' ) );
check( 'unchanged → no product scan (fill not reached)', 3 === count( $GLOBALS['wpdb']->get_col_queue ) );

// 3) Changed price — fills the queue and arms the frontload signal.
$settings['manual_price'] = 5100000.0;
update_option( GPU_Settings::OPTION, $settings );
$GLOBALS['wpdb']->get_col_queue = array( array( 11, 22 ), array(), array() );
$r                              = GPU_Sync::run( 'cron' );
check( 'changed sync ok', ! empty( $r['ok'] ) );
check( 'changed → queued 2', 2 === ( $r['queued'] ?? 0 ) );
check( 'changed → api price updated', 5100000.0 === (float) GPU_Settings::state_get( 'api_price', 0 ) );
$insert = last_multirow_insert();
check( 'fill uses a multi-row INSERT', false !== $insert && 2 === preg_match_all( "/\(\d+, \d+, 'pending'\)/", $insert ) );
check( 'fill arms the frontload signal', 2 === (int) GPU_Settings::state_get( 'queue_pending', 0 ) );

// 4) Force sync — reprices even when the price did not move.
$GLOBALS['wpdb']->get_col_queue = array( array( 11, 22 ), array(), array() );
$r                              = GPU_Sync::run( 'cron', true );
check( 'force unchanged → ok', ! empty( $r['ok'] ) );
check( 'force unchanged → queued 2', 2 === ( $r['queued'] ?? 0 ) );

// 5) realtime_tick — disabled plugin is a full no-op.
$settings['enabled'] = 0;
update_option( GPU_Settings::OPTION, $settings );
$hist_before = count( queries_matching( '/INSERT INTO wp_gpu_sync_history/' ) );
GPU_Sync::realtime_tick();
check( 'realtime disabled → no sync at all', $hist_before === count( queries_matching( '/INSERT INTO wp_gpu_sync_history/' ) ) );

// 6) realtime_tick — enabled + unchanged price → fast path (no fill).
$settings['enabled'] = 1;
update_option( GPU_Settings::OPTION, $settings );
$GLOBALS['wpdb']->get_col_queue = array( array( 11, 22 ), array(), array() );
GPU_Sync::realtime_tick();
check( 'realtime tick keeps the price', 5100000.0 === (float) GPU_Settings::state_get( 'api_price', 0 ) );
check( 'realtime tick unchanged → fast path (fill not reached)', 3 === count( $GLOBALS['wpdb']->get_col_queue ) );

// 7) run_ticks() on an empty queue never touches the lock.
$q0 = count( $GLOBALS['wpdb']->queries );
GPU_Queue::run_ticks( 5 );
check( 'run_ticks empty queue → no lock acquired', 0 === count( queries_matching( '/GET_LOCK/', $q0 ) ) );

// 8) maybe_frontload_queue() drains a pending queue on request load.
GPU_Settings::state_save( array( 'queue_pending' => 3 ) );
$q0 = count( $GLOBALS['wpdb']->queries );
GPU_Plugin::maybe_frontload_queue();
check( 'frontload → tick acquired the lock', count( queries_matching( '/GET_LOCK/', $q0 ) ) >= 1 );
check( 'frontload → signal consumed', 0 === (int) GPU_Settings::state_get( 'queue_pending', 0 ) );

// 9) Cron schedules / reschedule manage the realtime event.
$settings['realtime_enabled']  = 1;
$settings['realtime_interval'] = 45;
update_option( GPU_Settings::OPTION, $settings );
$scheds = GPU_Plugin::cron_schedules( array() );
check( 'gpu_realtime schedule registered with the configured interval', isset( $scheds['gpu_realtime'] ) && 45 === $scheds['gpu_realtime']['interval'] );

GPU_Plugin::reschedule();
check( 'reschedule registers the realtime event', false !== wp_next_scheduled( GPU_Plugin::REALTIME_HOOK ) );
check( 'reschedule keeps the main sync event', false !== wp_next_scheduled( GPU_Plugin::CRON_HOOK ) );

$settings['realtime_enabled'] = 0;
update_option( GPU_Settings::OPTION, $settings );
GPU_Plugin::reschedule();
check( 'realtime off → event cleared', false === wp_next_scheduled( GPU_Plugin::REALTIME_HOOK ) );

// ------------------------------------------------------------------ report

echo "\n" . count( $failures ) . " failure(s)\n";
exit( $failures ? 1 : 0 );
