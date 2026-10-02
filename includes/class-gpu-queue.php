<?php
/**
 * Work queue — cron-safe batch processing without Action Scheduler.
 *
 * A custom table holds one row per managed product/variation per sync. Each
 * queue tick claims a batch with an atomic UPDATE (no double-claiming even
 * when two workers race), processes it, and re-schedules itself until the
 * queue is empty. The whole sync runs inside the sync lock so cron + manual
 * + concurrent ticks can never interleave.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_Queue {

	const CRON_HOOK      = 'gpu_queue_tick';
	const MAX_RUNTIME    = 20;  // seconds per tick (well under PHP max_execution_time).

	/**
	 * Fill the queue from the managed-products list.
	 *
	 * @param int $sync_id Sync history row (for tracing).
	 *
	 * @return int Number of queued items.
	 */
	public static function fill( $sync_id ) {
		global $wpdb;

		$table = self::table();
		$ids   = array();
		$offset = 0;
		$batch = 200;

		do {
			$parents = GPU_Products::managed_product_ids( $offset, $batch );
			if ( $parents === array() ) {
				break;
			}

			// Separate simple products from variable parents.
			foreach ( $parents as $pid ) {
				$type = get_post_type( $pid );
				if ( 'product' === $type ) {
					$product = wc_get_product( $pid );
					if ( $product && ! $product->is_type( 'variable' ) ) {
						$ids[] = $pid;
					}
				}
			}

			// Add variations for variable parents.
			$ids = array_merge( $ids, GPU_Products::variation_ids( $parents ) );
			$offset += $batch;
		} while ( count( $parents ) === $batch );		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$values     = array();
			$placeholders = array();

			foreach ( $chunk as $id ) {
				$values[]        = (int) $sync_id;
				$values[]        = (int) $id;
				$values[]        = 'pending';
				$placeholders[] = '(%d, %d, %s)';
			}

			// One multi-row INSERT per 200 products instead of one query per product.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $table . ' (sync_id, product_id, status) VALUES ' . implode( ', ', $placeholders ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$values
				)
			);
		}

		// Arm the request-level frontload signal so the very next page view
		// starts draining this queue even if WP-Cron is slow.
		GPU_Settings::state_save(
			array(
				'queue_total'        => count( $ids ),
				'queue_pending'      => count( $ids ),
				'queue_frontload_at' => time(),
			)
		);

		return count( $ids );
	}

	/**
	 * Atomically claim one pending item (race-safe).
	 *
	 * @return object|null {id, product_id}
	 */
	public static function claim() {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( "SELECT id, product_id FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}

		// Atomic claim: only the first UPDATE wins.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'processing', claimed_at = %d WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				time(),
				(int) $row['id']
			)
		);

		if ( ! $updated ) {
			return self::claim(); // Lost the race — try the next row.
		}

		return (object) array(
			'id'         => (int) $row['id'],
			'product_id' => (int) $row['product_id'],
		);
	}

	/**
	 * Mark an item done.
	 *
	 * @param int    $item_id
	 * @param string $status updated|skipped|error
	 *
	 * 'processing' rows stuck for > 10 minutes are re-claimed by
	 * reclaim_stale() on the next tick, so a crashed worker never blocks
	 * the queue.
	 *
	 * @return void
	 */
	public static function done( $item_id, $status ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array( 'status' => $status, 'finished_at' => time() ),
			array( 'id' => (int) $item_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Re-queue rows stuck in 'processing' (crashed worker recovery).
	 *
	 * @return void
	 */
	public static function reclaim_stale() {
		global $wpdb;
		$table = self::table();

		// phpcs:active-composer-ignore
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', claimed_at = NULL WHERE status = 'processing' AND claimed_at IS NOT NULL AND claimed_at < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				time() - 600
			)
		);
	}	/**
     * Process one batch (bounded by MAX_RUNTIME), then reschedule if work remains.
     *
     * @param bool $full_tick When true (admin_init path), process all remaining
     *                        items instead of one batch.
     *
     * @return void
     */
	public static function tick( $full_tick = false ) {
		$start = microtime( true );

		if ( ! GPU_DB::acquire_lock( (int) GPU_Settings::get_setting( 'lock_timeout', 300 ) ) ) {
			// Another sync is running — try again shortly.
			self::schedule_next();
			return;
		}

		try {
			$batch_size = max( 1, (int) GPU_Settings::get_setting( 'batch_size', 50 ) );
			$context    = self::build_context();
			$processed  = 0;
			$limit      = $full_tick ? PHP_INT_MAX : $batch_size;

			GPU_Queue::reclaim_stale();

			while ( $processed < $limit && ( microtime( true ) - $start ) < self::MAX_RUNTIME ) {
				$item = self::claim();
				if ( ! $item ) {
					break;
				}

				$outcome = GPU_Products::update_product( $item->product_id, $context );
				$status  = 'error' === $outcome['status'] ? 'error' : ('skipped' === $outcome['status'] ? 'skipped' : 'updated');

				self::done( $item->id, $status );
				$processed++;
			}

			// More work? Reschedule immediately (5s) to keep the pipeline moving.
			$remaining = self::counts();
			if ( $remaining['pending'] > 0 || $remaining['processing'] > 0 ) {
				self::schedule_next( 5 );
			}
		} finally {
			GPU_DB::release_lock();
		}
	}

	/**
	 * Build the pricing context shared by every item in a batch.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_context() {
		$settings = GPU_Settings::get();
		$effective = GPU_Gold_Price_Service::effective_price();

		return array(
			'effective_price' => $effective,
			'profit_pct'      => (float) $settings['seller_profit_pct'],
			'vat_pct'         => (float) $settings['vat_pct'],
			'decimals'        => (int) $settings['decimals'],
			'round_mode'      => (string) $settings['round_mode'],
			'round_step'      => (float) $settings['round_step'],
			'divisor'         => 1.0,
			'sale_mode'       => (string) $settings['sale_mode'],
		);
	}

	/**
	 * Process the queue through bounded ticks until it drains.
	 *
	 * Used by the sync pipeline right after a price change: each tick keeps
	 * the lock, processes one batch, reschedules, and re-acquires — the loop
	 * only stops when the queue is empty, the time budget is spent, or the
	 * lock is lost to another worker (whose ticks keep draining anyway).
	 *
	 * @param int $max_seconds Wall-clock budget in seconds (0 = unbounded).
	 *
	 * @return void
	 */
	public static function run_ticks( $max_seconds = 0 ) {
		$start = microtime( true );

		do {
			$before = self::counts();
			if ( $before['pending'] <= 0 && $before['processing'] <= 0 ) {
				break;
			}

			self::tick();

			$after = self::counts();
			if ( $after['pending'] + $after['processing'] >= $before['pending'] + $before['processing']
				&& $after['pending'] + $after['processing'] > 0 ) {
				// No progress — another worker holds the lock; its ticks continue.
				break;
			}
		} while ( $max_seconds <= 0 || ( microtime( true ) - $start ) < $max_seconds );
	}

	/**
	 * (Re)schedule the queue-tick cron event.
	 *
	 * @param int $delay Seconds from now.
	 *
	 * @return void
	 */
	public static function schedule_next( $delay = 5 ) {
		if ( null === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}

	/**
	 * Queue depth counters for the dashboard progress display.
	 *
	 * @return array{pending: int, processing: int, updated: int, skipped: int, error: int, total: int}
	 */
	public static function counts() {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array(
			'pending'    => 0,
			'processing' => 0,
            'updated'    => 0,
			'skipped'    => 0,
			'error'      => 0,
			'total'      => 0,
		);

		foreach ( $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['c'];
			$out['total']         += (int) $row['c'];
		}

		return $out;
	}

	/**
	 * Drop finished items (keeps the table tiny between syncs).
	 *
	 * @return void
	 */
	public static function clear_finished() {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$table} WHERE status != 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Drop everything (sync aborted).
	 *
	 * @return void
	 */
	public static function clear_all() {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @return string Fully-qualified queue table name.
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'gpu_queue';
	}
}
