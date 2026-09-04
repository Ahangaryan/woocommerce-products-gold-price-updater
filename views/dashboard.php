<?php
/**
 * Dashboard + settings view. Variables are provided by GPU_Admin::render_dashboard().
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @var array<string, mixed> $settings
 * @var array<string, mixed> $state
 * @var array<string, mixed> $api
 * @var float|null           $effective
 * @var array<string, mixed> $source
 * @var array<string, int>   $counts
 * @var array<string, mixed> $last_hist
 * @var list<array<string, mixed>> $history
 * @var list<array<string, mixed>> $logs
 * @var int|false            $next_sync
 * @var string               $message
 * @var string               $msgtype
 */

$units       = GPU_Price_Normalizer::unit_keys();
$providers   = GPU_Api_Providers::all();
$price       = $source['price'] ?? null;
$price_label = null === $price ? '—' : number_format_i18n( (float) $price );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Gold Price Updater', 'gold-price-updater' ); ?>
		<span style="font-weight:normal;color:#777">v<?php echo esc_html( GPU_VERSION ); ?></span>
	</h1>

	<?php if ( '' !== $message ) : ?>
		<div class="notice notice-<?php echo esc_attr( $msgtype ); ?>"><p><?php echo esc_html( $message ); ?></p></div>
	<?php endif; ?>

	<!-- ============ Status cards ============ -->
	<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">
		<div class="card" style="min-width:200px;padding:12px 16px">
			<strong><?php esc_html_e( 'Current API price', 'gold-price-updater' ); ?></strong><br>
			<span style="font-size:1.6em"><?php echo esc_html( $price_label ); ?></span><br>
			<span class="description">
				<?php
				printf(
					/* translators: %s: source */
					esc_html__( 'Source: %s', 'gold-price-updater' ),
					esc_html( (string) ( $source['source'] ?? 'none' ) )
				);
				?>
			</span>
		</div>
		<div class="card" style="min-width:200px;padding:12px 16px">
			<strong><?php esc_html_e( 'Effective price (with buffer)', 'gold-price-updater' ); ?></strong><br>
			<span style="font-size:1.6em"><?php echo esc_html( null === $effective ? '—' : number_format_i18n( $effective ) ); ?></span><br>
			<span class="description">
				<?php echo esc_html( number_format_i18n( (float) $settings['buffer'] ) ); ?>
				<?php esc_html_e( 'buffer', 'gold-price-updater' ); ?>
			</span>
		</div>
		<div class="card" style="min-width:200px;padding:12px 16px">
			<strong><?php esc_html_e( 'API status', 'gold-price-updater' ); ?></strong><br>
			<span style="font-size:1.6em;color:<?php echo esc_attr( ( $api['status'] ?? '' ) === 'connected' ? 'green' : '#b32d2e' ); ?>">
				<?php echo esc_html( ( $api['status'] ?? 'not-tested' ) === 'connected' ? __( 'Connected', 'gold-price-updater' ) : __( 'Error / not tested', 'gold-price-updater' ) ); ?>
			</span><br>
			<span class="description"><?php echo esc_html( (string) ( $api['message'] ?? '' ) ); ?></span>
		</div>
		<div class="card" style="min-width:200px;padding:12px 16px">
			<strong><?php esc_html_e( 'Sync', 'gold-price-updater' ); ?></strong><br>
			<?php
			$last_ok = (int) ( $state['last_sync_ok'] ?? 0 );
			/* translators: %s: time */
			echo esc_html( $last_ok ? sprintf( __( 'Last OK: %s', 'gold-price-updater' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_ok ) ) : __( 'Never synced', 'gold-price-updater' ) );
			?>
			<br>
			<?php if ( $next_sync ) : ?>
				<?php
				/* translators: %s: time */
				echo esc_html( sprintf( __( 'Next: %s', 'gold-price-updater' ), date_i18n( get_option( 'time_format' ), $next_sync ) ) );
				?>
			<?php endif; ?>
		</div>
	</div>

	<!-- ============ Sync actions ============ -->
	<p>
		<a class="button button-primary button-hero"
			href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gpu_sync_now' ), 'gpu_sync_now' ) ); ?>">
			<?php esc_html_e( 'Sync Gold Price Now', 'gold-price-updater' ); ?>
		</a>
		<a class="button"
			href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gpu_force_sync' ), 'gpu_force_sync' ) ); ?>">
			<?php esc_html_e( 'Force sync (ignore change limit)', 'gold-price-updater' ); ?>
		</a>
	</p>

	<?php if ( empty( $settings['api_url'] ) && 'manual' !== $settings['price_mode'] ) : ?>
		<div class="notice notice-error"><p>
			<?php esc_html_e( 'API URL is not configured. Select a Provider below (the URL will auto-fill) or enter a custom API URL, then save and try Sync again.', 'gold-price-updater' ); ?>
		</p></div>
	<?php endif; ?>

	<!-- ============ Queue progress ============ -->
	<div style="margin:12px 0;max-width:640px">
		<strong><?php esc_html_e( 'Product update progress', 'gold-price-updater' ); ?></strong>
		<div style="background:#dcdcde;border-radius:4px;height:22px;position:relative;overflow:hidden">
			<div id="gpu-progress-bar" style="background:#2271b1;height:100%;width:0%"></div>
			<span id="gpu-progress-text"
				style="position:absolute;inset:0;text-align:center;line-height:22px;font-size:12px">—</span>
		</div>
		<p style="margin:6px 0 0" class="description">
			<?php
			printf(
				/* translators: %1$d updated, %2$d skipped, %3$d errors, %4$d pending */
				esc_html__( 'Updated: %1$d — Skipped: %2$d — Errors: %3$d — Pending: %4$d', 'gold-price-updater' ),
				(int) $counts['updated'],
				(int) $counts['skipped'],
				(int) $counts['error'],
				(int) $counts['pending']
			);
			?>
		</p>
	</div>

	<!-- ============ Settings form ============ -->
	<h2><?php esc_html_e( 'Settings', 'gold-price-updater' ); ?></h2>
	<form method="post">
		<?php wp_nonce_field( 'gpu_settings' ); ?>

		<h2 class="title"><?php esc_html_e( 'General', 'gold-price-updater' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable plugin', 'gold-price-updater' ); ?></th>
				<td><input type="checkbox" name="gpu_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sale price behavior', 'gold-price-updater' ); ?></th>
				<td>
					<select name="gpu_sale_mode">
						<option value="regular_only" <?php selected( $settings['sale_mode'], 'regular_only' ); ?>><?php esc_html_e( 'Update regular price only, never touch Sale Price', 'gold-price-updater' ); ?></option>
						<option value="skip_on_sale" <?php selected( $settings['sale_mode'], 'skip_on_sale' ); ?>><?php esc_html_e( 'Skip products with an active sale', 'gold-price-updater' ); ?></option>
					</select>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Gold price', 'gold-price-updater' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Price mode', 'gold-price-updater' ); ?></th>
				<td>
					<select name="gpu_price_mode">
						<option value="api" <?php selected( $settings['price_mode'], 'api' ); ?>><?php esc_html_e( 'API only', 'gold-price-updater' ); ?></option>
						<option value="manual" <?php selected( $settings['price_mode'], 'manual' ); ?>><?php esc_html_e( 'Manual only', 'gold-price-updater' ); ?></option>
						<option value="fallback" <?php selected( $settings['price_mode'], 'fallback' ); ?>><?php esc_html_e( 'API with fallback to manual (recommended)', 'gold-price-updater' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Manual price', 'gold-price-updater' ); ?></th>
				<td><input type="number" step="any" min="0" name="gpu_manual_price" value="<?php echo esc_attr( $settings['manual_price'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Buffer', 'gold-price-updater' ); ?></th>
				<td><input type="number" step="any" min="0" name="gpu_buffer" value="<?php echo esc_attr( $settings['buffer'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Karat', 'gold-price-updater' ); ?></th>
				<td>
					<select name="gpu_karat">
						<?php foreach ( array( '18k', '21k', '24k', '14k' ) as $k ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $settings['karat'], $k ); ?>><?php echo esc_html( $k ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Target currency', 'gold-price-updater' ); ?></th>
				<td>
					<select name="gpu_target_currency">
						<?php foreach ( array( 'IRR', 'TOMAN', 'USD' ) as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $settings['target_currency'], $c ); ?>><?php echo esc_html( $c ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Max price change % (0 = off)', 'gold-price-updater' ); ?></th>
				<td><input type="number" step="any" min="0" name="gpu_max_change" value="<?php echo esc_attr( $settings['max_price_change'] ); ?>"></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Pricing', 'gold-price-updater' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Default making charge %', 'gold-price-updater' ); ?></th>
				<td><input type="number" step="any" min="0" max="100" name="gpu_default_mk" value="<?php echo esc_attr( $settings['default_making_charge_pct'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Seller profit %', 'gold-price-updater' ); ?></th>
				<td><input type="number" step="any" min="0" max="100" name="gpu_profit" value="<?php echo esc_attr( $settings['seller_profit_pct'] ); ?>"></td>
			</tr>
			<tr>
			<th scope="row"><?php esc_html_e( 'VAT % (on making charge only)', 'gold-price-updater' ); ?></th>
				<td><input type="number" step="any" min="0" max="100" name="gpu_vat" value="<?php echo esc_attr( $settings['vat_pct'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Rounding', 'gold-price-updater' ); ?></th>
				<td>
					<select name="gpu_round_mode">
						<?php foreach ( array( 'none', 'nearest', 'up', 'down' ) as $m ) : ?>
							<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $settings['round_mode'], $m ); ?>><?php echo esc_html( $m ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="number" step="any" min="0" name="gpu_round_step" value="<?php echo esc_attr( $settings['round_step'] ); ?>" placeholder="step">
					<span class="description"><?php esc_html_e( 'e.g. step 1000 rounds the final price to the nearest/next 1,000.', 'gold-price-updater' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Price decimals', 'gold-price-updater' ); ?></th>
				<td><input type="number" min="0" max="6" name="gpu_decimals" value="<?php echo esc_attr( $settings['decimals'] ); ?>"></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Sync', 'gold-price-updater' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Update interval (seconds, min 60)', 'gold-price-updater' ); ?></th>
				<td><input type="number" min="60" name="gpu_interval" value="<?php echo esc_attr( $settings['update_interval'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Batch size', 'gold-price-updater' ); ?></th>
				<td><input type="number" min="1" max="500" name="gpu_batch" value="<?php echo esc_attr( $settings['batch_size'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Retry count', 'gold-price-updater' ); ?></th>
				<td><input type="number" min="0" max="10" name="gpu_retries" value="<?php echo esc_attr( $settings['retry_count'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Retry delay (seconds)', 'gold-price-updater' ); ?></th>
				<td><input type="number" min="30" name="gpu_retry_delay" value="<?php echo esc_attr( $settings['retry_delay'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Lock timeout (seconds)', 'gold-price-updater' ); ?></th>
				<td><input type="number" min="60" name="gpu_lock_timeout" value="<?php echo esc_attr( $settings['lock_timeout'] ); ?>"></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'API', 'gold-price-updater' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Provider', 'gold-price-updater' ); ?></th>
				<td>
					<select name="gpu_provider">
						<?php foreach ( $providers as $key => $p ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['provider'], $key ); ?>><?php echo esc_html( $p['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'API URL', 'gold-price-updater' ); ?></th>
				<td><input type="url" class="regular-text" name="gpu_api_url" value="<?php echo esc_attr( $settings['api_url'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'API key', 'gold-price-updater' ); ?></th>
				<td>
					<input type="text" class="regular-text" name="gpu_api_key" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off">
					<span class="description"><?php echo esc_html( '' !== $settings['api_key'] ? GPU_Settings::mask( $settings['api_key'] ) : '' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'API secret (optional)', 'gold-price-updater' ); ?></th>
				<td><input type="text" class="regular-text" name="gpu_api_secret" value="<?php echo esc_attr( $settings['api_secret'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Auth method', 'gold-price-updater' ); ?></th>
				<td>
					<select name="gpu_auth">
						<?php foreach ( array( 'none', 'header', 'bearer', 'query', 'basic' ) as $a ) : ?>
							<option value="<?php echo esc_attr( $a ); ?>" <?php selected( $settings['auth_method'], $a ); ?>><?php echo esc_html( $a ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Request method', 'gold-price-updater' ); ?></th>
				<td>
					<select name="gpu_method">
						<option value="GET" <?php selected( $settings['request_method'], 'GET' ); ?>>GET</option>
						<option value="POST" <?php selected( $settings['request_method'], 'POST' ); ?>>POST</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'JSON path', 'gold-price-updater' ); ?></th>
				<td>
					<input type="text" class="regular-text" name="gpu_json_path" value="<?php echo esc_attr( $settings['json_path'] ); ?>" placeholder="data.0.price">
					<span class="description"><?php esc_html_e( 'Dot notation into the JSON response; empty = first number found.', 'gold-price-updater' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Price unit', 'gold-price-updater' ); ?></th>
				<td>
					<select name="gpu_price_unit">
						<?php foreach ( $units as $u ) : ?>
							<option value="<?php echo esc_attr( $u ); ?>" <?php selected( $settings['price_unit'], $u ); ?>><?php echo esc_html( $u ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'API currency', 'gold-price-updater' ); ?></th>
				<td>
					<select name="gpu_api_currency">
						<?php foreach ( array( 'IRR', 'TOMAN', 'USD' ) as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $settings['api_currency'], $c ); ?>><?php echo esc_html( $c ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Timeout (seconds)', 'gold-price-updater' ); ?></th>
				<td><input type="number" min="2" max="120" name="gpu_timeout" value="<?php echo esc_attr( $settings['timeout'] ); ?>"></td>
			</tr>
		</table>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gpu_test_api' ), 'gpu_test_api' ) ); ?>">
				<?php esc_html_e( 'Test API Connection', 'gold-price-updater' ); ?>
			</a>
		</p>

		<h2 class="title"><?php esc_html_e( 'Notifications', 'gold-price-updater' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Email on repeated failure', 'gold-price-updater' ); ?></th>
				<td><input type="checkbox" name="gpu_email_on_error" value="1" <?php checked( ! empty( $settings['email_on_error'] ) ); ?>></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Recipient', 'gold-price-updater' ); ?></th>
				<td><input type="email" class="regular-text" name="gpu_error_email" value="<?php echo esc_attr( $settings['error_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Advanced', 'gold-price-updater' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Debug mode (shows response shape in logs)', 'gold-price-updater' ); ?></th>
				<td><input type="checkbox" name="gpu_debug" value="1" <?php checked( ! empty( $settings['debug'] ) ); ?>></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Logging', 'gold-price-updater' ); ?></th>
				<td><input type="checkbox" name="gpu_logging" value="1" <?php checked( ! empty( $settings['logging_enabled'] ) ); ?>></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Log retention (days)', 'gold-price-updater' ); ?></th>
				<td><input type="number" min="1" name="gpu_log_days" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'GitHub repo (owner/name) for updates', 'gold-price-updater' ); ?></th>
				<td><input type="text" class="regular-text" name="gpu_github_repo" value="<?php echo esc_attr( $settings['github_repo'] ); ?>" placeholder="user/plugin-repo"></td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'gold-price-updater' ), 'primary', 'gpu_save' ); ?>
	</form>

	<!-- ============ Sync history ============ -->
	<h2><?php esc_html_e( 'Sync history', 'gold-price-updater' ); ?></h2>
	<table class="widefat striped" style="max-width:900px">
		<thead>
			<tr>
				<th>#</th><th><?php esc_html_e( 'Started', 'gold-price-updater' ); ?></th>
				<th><?php esc_html_e( 'Source', 'gold-price-updater' ); ?></th>
				<th><?php esc_html_e( 'Gold price', 'gold-price-updater' ); ?></th>
				<th><?php esc_html_e( 'Effective', 'gold-price-updater' ); ?></th>
				<th><?php esc_html_e( 'Queued', 'gold-price-updater' ); ?></th>
				<th><?php esc_html_e( 'Status', 'gold-price-updater' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( $history === array() ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No syncs yet.', 'gold-price-updater' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $history as $h ) : ?>
				<tr>
					<td><?php echo esc_html( $h['id'] ); ?></td>
					<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $h['started_at'] ) ); ?></td>
					<td><?php echo esc_html( $h['source'] ); ?></td>
					<td><?php echo esc_html( null === $h['gold_price'] ? '—' : number_format_i18n( (float) $h['gold_price'] ) ); ?></td>
					<td><?php echo esc_html( null === $h['effective_price'] ? '—' : number_format_i18n( (float) $h['effective_price'] ) ); ?></td>
					<td><?php echo esc_html( (int) $h['products_total'] ); ?></td>
					<td><?php echo esc_html( $h['status'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<!-- ============ Logs ============ -->
	<h2><?php esc_html_e( 'Recent logs', 'gold-price-updater' ); ?></h2>
	<table class="widefat striped" style="max-width:900px">
		<thead>
			<tr><th><?php esc_html_e( 'Time', 'gold-price-updater' ); ?></th><th><?php esc_html_e( 'Level', 'gold-price-updater' ); ?></th><th><?php esc_html_e( 'Event', 'gold-price-updater' ); ?></th><th><?php esc_html_e( 'Message', 'gold-price-updater' ); ?></th></tr>
		</thead>
		<tbody>
			<?php if ( $logs === array() ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No log entries.', 'gold-price-updater' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $logs as $log ) : ?>
				<tr>
					<td><?php echo esc_html( date_i18n( get_option( 'time_format' ), (int) $log['create_at'] ) ); ?></td>
					<td><?php echo esc_html( $log['level'] ); ?></td>
					<td><?php echo esc_html( $log['event'] ); ?></td>
					<td><?php echo esc_html( $log['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
