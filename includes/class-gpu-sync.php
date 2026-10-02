<?php
/**
 * Sync orchestrator — the guarded pipeline from API to product prices.
 *
 * Pipeline (§73/§74 of the spec):
 *   request → validate → normalize → apply buffer → max-change guard →
 *   save gold price → fill queue → background ticks → per-product update.
 *
 * The current gold price and all product prices only change AFTER every
 * validation step passed. Any failure keeps the last valid price untouched.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_Sync {

	const CRON_HOOK   = 'gpu_sync_cron';
	const NOTICE_KEY  = 'gpu_price_guard';

	/**
	 * Full sync: fetch, validate, guard, store price, queue products.
	 *
	 * @param string $source  api|manual|cron (for history + logging).
	 * @param bool   $force   Bypass the max-change guard (admin override).
	 *
	 * @return array{ok: bool, message: string, sync_id?: int, queued?: int}
	 */
	public static function run( $source = 'cron', $force = false ) {
		if ( ! GPU_DB::acquire_lock( (int) GPU_Settings::get_setting( 'lock_timeout', 300 ) ) ) {
			return array( 'ok' => false, 'message' => __( 'Another sync is already running.', 'gold-price-updater' ) );
		}

		try {
			$sync_id = GPU_DB::history_start( $source );			GPU_Logger::info( 'sync_start', 'Sync started', array( 'sync_id' => $sync_id, 'source' => $source ) );

			// 1. Resolve the base price by mode.
			$mode    = (string) GPU_Settings::get_setting( 'price_mode', 'fallback' );
			$fetched = null;
			$used_manual_fallback = false;

			if ( 'manual' === $mode ) {
				$manual = (float) GPU_Settings::get_setting( 'manual_price', 0 );
				if ( $manual <= 0 ) {
					return self::fail( $sync_id, 'Manual mode is on but the manual price is not set.' );
				}
				$base_price = $manual;
			} else {
				$fetched    = self::fetch_with_retry();
				$base_price = null;

				if ( is_array( $fetched ) ) {
					$fetch_error = isset( $fetched['error'] ) ? (string) $fetched['error'] : 'Unknown API error.';
				} elseif ( is_float( $fetched ) || is_int( $fetched ) ) {
					$base_price = (float) $fetched;
				}

				// Fallback mode: use the manual price when the API failed.
				if ( null === $base_price && 'fallback' === $mode ) {
					$manual = (float) GPU_Settings::get_setting( 'manual_price', 0 );
					if ( $manual > 0 ) {
						$base_price = $manual;
						$fetch_error = null;
						$used_manual_fallback = true;
					}
				}
			}

			if ( null === $base_price || $base_price <= 0 ) {
				$msg = 'API failed';
				if ( isset( $fetch_error ) && '' !== $fetch_error ) {
					$msg .= ': ' . $fetch_error;
				}
				// Fallback mode: keep last valid price, products are NOT re-priced.
				return self::fail( $sync_id, $msg, true );
			}

			// 2. Max-change guard (§11, §72).
			if ( ! $force && ! self::within_max_change( $base_price ) ) {
				$pct = self::change_percent( $base_price );
				GPU_Logger::error( 'price_guard', 'Price change exceeded the limit', array( 'new' => $base_price, 'change_pct' => $pct ) );
				GPU_Settings::push_notice(
					self::NOTICE_KEY,
					sprintf(
						/* translators: %1$s: new price, %2$s: change percent */
						__( 'Gold price changed by %2$s%% (to %1$s) — above the allowed maximum. Products were NOT updated; adjust the limit or use "Force sync".', 'gold-price-updater' ),
						number_format_i18n( $base_price ),
						number_format_i18n( abs( $pct ), 1 )
					)
				);
				return self::fail( $sync_id, 'Price-change guard tripped (+' . $pct . '%).', false );
			}			// 3. Store the price and queue the product updates.
			$previous_price = (float) GPU_Settings::state_get( 'api_price', 0 );

			GPU_Gold_Price_Service::store_api_price( $base_price );
			GPU_Gold_Price_Service::record_success();

			/*
			 * Fast path: when the base price is byte-identical to the stored
			 * one, every managed product would compute the exact same number
			 * (the engine is deterministic) — so there is nothing to do beyond
			 * bookkeeping. This is what makes high-frequency (realtime)
			 * polling cheap: unchanged polls cost two option writes + one
			 * history row, never a product scan.
			 */
			$price_changed = abs( $base_price - $previous_price ) >= 0.000001;

			$queued = 0;
			if ( ! $price_changed ) {
				if ( $force ) {
					// Forced sync: reprice everything even when the price did not move.
					$queued        = GPU_Queue::fill( $sync_id );
					$price_changed = $queued > 0;
				}
			} else {
				$queued = GPU_Queue::fill( $sync_id );
			}

			GPU_DB::history_finish(
				$sync_id,
				array(
					'gold_price'      => $base_price,
					'buffer_amount'   => (float) GPU_Settings::get_setting( 'buffer', 0 ),
					'effective_price' => $base_price + (float) GPU_Settings::get_setting( 'buffer', 0 ),
					'products_total'  => $queued,
					'status'          => $price_changed ? 'queued' : 'no_change',
					'message'         => $price_changed
						? 'Queued ' . $queued . ' product(s) for background update.'
						: 'Gold price unchanged — product prices left as-is.',
				)
			);

			GPU_Logger::info(
				$price_changed ? 'sync_queued' : 'sync_no_change',
				$price_changed ? 'Products queued' : 'Price unchanged — skipped product updates',
				array( 'sync_id' => $sync_id, 'queued' => $queued )
			);
			if ( $used_manual_fallback ) {
				GPU_Logger::warning( 'manual_fallback', 'API failed — manual price used for this sync.', array( 'sync_id' => $sync_id ) );
			}
			GPU_Logger::flush();

			if ( ! $price_changed ) {
				return array(
					'ok'      => true,
					'message' => __( 'Gold price unchanged — products already up to date.', 'gold-price-updater' ),
					'sync_id' => $sync_id,
					'queued'  => 0,
				);
			}

			/*
			 * Front-load the queue: process as much as possible inside THIS
			 * request (we already hold the sync lock) so store-front prices
			 * reflect the new gold price within seconds, then hand any
			 * remainder to the scheduler.
			 */
			GPU_Queue::run_ticks( true );

			return array(
				'ok'      => true,
				'message' => sprintf( __( 'Sync started — %d product(s) queued.', 'gold-price-updater' ), $queued ),
				'sync_id' => $sync_id,
				'queued'  => $queued,
			);
		} finally {
			GPU_DB::release_lock();
		}
	}

	/**
	 * Cron entry point (with retry-on-temporary-failure).
	 *
	 * @return void
	 */
	public static function cron_tick() {
		$settings = GPU_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$result = self::run( 'cron' );

		if ( ! $result['ok'] && GPU_Gold_Price_Service::failure_count() > 0 ) {
			$max_retries = max( 0, (int) $settings['retry_count'] );
			$attempt     = GPU_Gold_Price_Service::failure_count();

			if ( $attempt <= $max_retries ) {
				// Temporary failure — retry after the delay.
				wp_schedule_single_event( time() + max( 30, (int) $settings['retry_delay'] ), self::CRON_HOOK );
				GPU_Logger::warning( 'sync_retry', 'Scheduling retry', array( 'attempt' => $attempt, 'of' => $max_retries ) );
			} elseif ( $attempt === $max_retries + 1 ) {
				// Retry budget just exhausted — notify once, not on every further failure.
				self::notify_admin( $result['message'] );
			}
		}
	}

	/**
	 * Realtime poll (high-frequency tick): fetch the price, store it when it
	 * actually changed, and reprice the products immediately.
	 *
	 * Intentionally bypasses the max-change guard: the price source publishes
	 * live market prices, so any movement is legitimate. Validation,
	 * normalization and the "never store on failure" rule are identical to
	 * the regular sync. Unchanged polls are nearly free (fast path in run()).
	 *
	 * @return void
	 */
	public static function realtime_tick() {
		$settings = GPU_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$result = self::run( 'api' );

		// Dashboard-visible health bookkeeping for the realtime poller.
		GPU_Settings::state_save(
			array(
				'last_realtime_at'     => time(),
				'last_realtime_result' => ! empty( $result['ok'] ) ? 'ok' : 'fail',
				'last_realtime_queued' => max( 0, (int) ( $result['queued'] ?? 0 ) ),
			)
		);

		if ( ! $result['ok'] && GPU_Gold_Price_Service::failure_count() > 0 ) {
			$max_retries = max( 0, (int) $settings['retry_count'] );
			$attempt     = GPU_Gold_Price_Service::failure_count();

			if ( $attempt <= $max_retries ) {
				wp_schedule_single_event( time() + max( 30, (int) $settings['retry_delay'] ), self::CRON_HOOK );
				GPU_Logger::warning( 'realtime_retry', 'Scheduling retry', array( 'attempt' => $attempt, 'of' => $max_retries ) );
			}
		}
	}

	// ------------------------------------------------------------ internals

	/**
	 * Fetch + normalize to per-gram site-currency price, with a transport retry.
	 *
	 * @return array|float|null float = per-gram price; array = failure info.
	 */
	private static function fetch_with_retry() {
		$attempts = 1 + max( 0, (int) GPU_Settings::get_setting( 'retry_count', 2 ) );

		for ( $i = 0; $i < $attempts; $i++ ) {
			$client = new GPU_Api_Client( GPU_Settings::get() );
			$result = $client->fetch();
			if ( $result['ok'] ) {
				GPU_Settings::set_api_status( true, 'Connected', $result['price'] );

				$normalized = GPU_Price_Normalizer::to_gram(
					array(
						'price'           => $result['price'],
						'price_unit'      => GPU_Settings::get_setting( 'price_unit', 'gram_18k' ),
						'currency'        => GPU_Settings::get_setting( 'api_currency', 'IRR' ),
						'target_currency' => GPU_Settings::get_setting( 'target_currency', 'IRR' ),
					),
					GPU_Settings::get_setting( 'karat', '18k' )
				);

				if ( null === $normalized ) {
					GPU_Settings::set_api_status( false, 'Price unit/currency/karat conversion failed.', $result['price'] );

					return array( 'error' => 'Normalization failed (unit/currency/karat).' );
				}

				return $normalized;
			}

			// Auth errors (401/403) must not be retried blindly (§42).
			$http = isset( $result['http_code'] ) ? (int) $result['http_code'] : 0;
			if ( 401 === $http || 403 === $http ) {
				break;
			}

			if ( $i < $attempts - 1 ) {
				sleep( 1 );
			}
		}

		GPU_Settings::set_api_status( false, $result['error'] ?? 'Unknown API error.' );

		return array( 'error' => $result['error'] ?? 'Unknown API error.' );
	}

	/**
	 * True when the new price is within the max-change limit.
	 *
	 * @param float $new_price
	 *
	 * @return bool
	 */
	private static function within_max_change( $new_price ) {
		$limit = (float) GPU_Settings::get_setting( 'max_price_change', 20.0 );
		if ( $limit <= 0 ) {
			return true; // Guard disabled.
		}

		$current = GPU_Settings::state_get( 'api_price', null );
		if ( null === $current || (float) $current <= 0 ) {
			return true; // First sync — nothing to compare.
		}

		return abs( self::change_percent( $new_price ) ) <= $limit;
	}

	/**
	 * Change percent vs the stored API price.
	 *
	 * @param float $new_price
	 *
	 * @return float
	 */
	private static function change_percent( $new_price ) {
		$current = (float) GPU_Settings::state_get( 'api_price', 0 );
		if ( $current <= 0 ) {
			return 0.0;
		}

		return ( ( $new_price - $current ) / $current ) * 100.0;
	}

	/**
	 * Record a failed sync (history + state + log + optional email).
	 *
	 * @param int    $sync_id
	 * @param string $message
	 * @param bool   $fallback_note Add the keep-last-price note.
	 *
	 * @return array{ok: bool, message: string}
	 */
	private static function fail( $sync_id, $message, $fallback_note = false ) {
		if ( $fallback_note ) {
			$message .= ' ' . __( 'Last valid price kept; products untouched.', 'gold-price-updater' );
		}

		GPU_DB::history_finish( $sync_id, array( 'status' => 'failed', 'message' => $message ) );
		GPU_Gold_Price_Service::record_failure( $message );
		GPU_Logger::error( 'sync_failed', $message, array( 'sync_id' => $sync_id ) );
		GPU_Logger::flush();

		return array( 'ok' => false, 'message' => $message );
	}

	/**
	 * Email the admin after the failure streak exceeds the retry budget.
	 *
	 * @param string $message
	 *
	 * @return void
	 */
	private static function notify_admin( $message ) {
		$to = (string) GPU_Settings::get_setting( 'error_email', get_option( 'admin_email' ) );
		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$subject = __( '[Gold Price Updater] Sync failure', 'gold-price-updater' );
		wp_mail(
			$to,
			$subject,
			sprintf(
				/* translators: %1$s: site name, %2$s: error */
				__( "Gold price sync failed on %1\$s:\n\n%2\$s\n\nThe last valid price is still active; products were not changed.", 'gold-price-updater' ),
				get_bloginfo( 'name' ),
				$message
			)
		);

		GPU_Logger::info( 'notify_admin', 'Failure email sent', array( 'to' => $to ) );
	}
}
