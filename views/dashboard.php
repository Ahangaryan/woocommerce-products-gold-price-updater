<?php
/**
 * Dashboard — Gold Price Updater.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed> $settings */
/** @var array<string, mixed> $state */
/** @var array<string, mixed> $api */
/** @var float|null           $effective */
/** @var array<string, mixed> $source */
/** @var array<string, int>   $counts */
/** @var array<string, mixed> $last_hist */
/** @var list<array<string, mixed>> $history */
/** @var list<array<string, mixed>> $logs */
/** @var int|false            $next_sync */
/** @var int|false            $next_realtime */
/** @var string               $message */
/** @var string               $msgtype */

$dir = GPU_Translations::is_rtl() ? 'rtl' : 'ltr';
$units     = GPU_Price_Normalizer::unit_keys();
$providers = GPU_Api_Providers::all();
$price     = $source['price'] ?? null;
$buffer    = (float) ( $settings['buffer'] ?? 0 );
$effective_price = null !== $price ? (float) $price + $buffer : null;

$api_connected = ( $api['status'] ?? '' ) === 'connected';
$has_error     = ! empty( $state['last_error'] );
$total_products = ( $counts['total'] ?? 0 );
$processed     = ( $counts['updated'] ?? 0 ) + ( $counts['skipped'] ?? 0 ) + ( $counts['error'] ?? 0 );
$progress_pct  = $total_products > 0 ? round( ( $processed / $total_products ) * 100, 1 ) : 0;
?>
<div class="gpu-wrap" dir="<?php echo $dir; ?>" lang="<?php echo esc_attr( get_locale() ); ?>">

	<!-- Header -->
	<div class="gpu-header">
		<h1>
			<span class="gpu-icon">⚜</span>
			<?php echo GPU_Translations::e( 'Gold Price Updater' ); ?>
			<span class="gpu-version">v<?php echo esc_html( GPU_VERSION ); ?></span>
		</h1>
		<div class="gpu-header-actions">
			<a class="gpu-btn gpu-btn-success" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gpu_sync_now' ), 'gpu_sync_now' ) ); ?>">
				⚡ <?php echo GPU_Translations::e( 'Sync Now' ); ?>
			</a>
			<a class="gpu-btn gpu-btn-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gpu_force_sync' ), 'gpu_force_sync' ) ); ?>">
				🔄 <?php echo GPU_Translations::e( 'Force Sync' ); ?>
			</a>
		</div>
	</div>

	<?php if ( '' !== $message ) : ?>
		<div class="gpu-notice notice-<?php echo esc_attr( $msgtype ); ?>">
			<span><?php echo esc_html( $message ); ?></span>
		</div>
	<?php endif; ?>

	<?php if ( empty( $settings['api_url'] ) && 'manual' !== $settings['price_mode'] ) : ?>
		<div class="gpu-notice gpu-notice-error">
			<span>⚠️ <?php echo GPU_Translations::e( 'API URL is not configured. Select a Provider below and save settings.' ); ?></span>
		</div>
	<?php endif; ?>

	<!-- Status Cards -->
	<div class="gpu-status-grid">
		<div class="gpu-card gpu-card-success">
			<div class="gpu-card-header">
				<span class="gpu-card-title"><?php echo GPU_Translations::e( 'Gold Price (18K)' ); ?></span>
				<span class="gpu-card-icon">🥇</span>
			</div>
			<div class="gpu-card-value"><?php echo null !== $price ? GPU_Formatter::number( (float) $price ) : '—'; ?></div>
			<div class="gpu-card-meta">
				<span class="gpu-dot <?php echo $api_connected ? '' : 'gpu-dot-error'; ?>"></span>
				<?php echo $api_connected ?GPU_Translations::e( 'Live from API' ) :GPU_Translations::e( 'Not connected' ); ?>
			</div>
		</div>

		<div class="gpu-card gpu-card-info">
			<div class="gpu-card-header">
				<span class="gpu-card-title"><?php echo GPU_Translations::e( 'Effective Price' ); ?></span>
				<span class="gpu-card-icon">💰</span>
			</div>
			<div class="gpu-card-value"><?php echo null !== $effective_price ? GPU_Formatter::number( $effective_price ) : '—'; ?></div>
			<div class="gpu-card-meta">
				+<?php echo GPU_Formatter::number( $buffer ); ?> <?php echo GPU_Translations::e( 'buffer' ); ?>
			</div>
		</div>

		<div class="gpu-card <?php echo $api_connected ? 'gpu-card-success' : 'gpu-card-danger'; ?>">
			<div class="gpu-card-header">
				<span class="gpu-card-title"><?php echo GPU_Translations::e( 'API Status' ); ?></span>
				<span class="gpu-card-icon"><?php echo $api_connected ? '✓' : '✕'; ?></span>
			</div>
			<div class="gpu-card-value" style="font-size: 20px;">
				<?php echo $api_connected ?GPU_Translations::e( 'Connected' ) :GPU_Translations::e( 'Disconnected' ); ?>
			</div>
			<div class="gpu-card-meta"><?php echo esc_html( (string) ( $api['message'] ?? '' ) ); ?></div>
		</div>

		<div class="gpu-card <?php echo $has_error ? 'gpu-card-warning' : 'gpu-card-success'; ?>">
			<div class="gpu-card-header">
				<span class="gpu-card-title"><?php echo GPU_Translations::e( 'Last Sync' ); ?></span>
				<span class="gpu-card-icon">🔄</span>
			</div>
			<div class="gpu-card-value" style="font-size: 16px;">
				<?php
				$last_ok = (int) ( $state['last_sync_ok'] ?? 0 );
				echo $last_ok
					? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_ok )
					: GPU_Translations::e( 'Never synced' );
				?>				</div>
				<div class="gpu-card-meta">
					<span class="gpu-dot <?php echo $has_error ? 'gpu-dot-error' : ''; ?>"></span>
							<?php echo $has_error ? esc_html( (string) $state['last_error'] ) : GPU_Translations::e( 'All good' ); ?>
						</div>
			</div>

			<div class="gpu-card <?php echo ! empty( $settings['realtime_enabled'] ) ? 'gpu-card-info' : 'gpu-card-danger'; ?>">
				<div class="gpu-card-header">
					<span class="gpu-card-title"><?php echo GPU_Translations::e( 'Realtime Poller' ); ?></span>
					<span class="gpu-card-icon">⚡</span>
				</div>
				<div class="gpu-card-value" style="font-size: 16px;">
					<?php
					if ( empty( $settings['realtime_enabled'] ) ) {
						echo GPU_Translations::e( 'Disabled' );
					} else {
						$rt_next = (int) $next_realtime;
						echo $rt_next
							? sprintf(
								/* translators: %s: human-readable time difference */
								GPU_Translations::t( 'Next poll in %s' ),
								human_time_diff( $rt_next, time() )
							)
							: GPU_Translations::e( 'Not scheduled yet' );
					}
					?>
				</div>
				<div class="gpu-card-meta">
					<?php
					$rt_at  = (int) ( $state['last_realtime_at'] ?? 0 );
					$rt_res = (string) ( $state['last_realtime_result'] ?? '' );
					if ( '' === $rt_res ) {
						echo esc_html( GPU_Translations::t( 'No realtime poll yet' ) );
					} else {
						echo esc_html(
							sprintf(
								/* translators: %1$s: poll outcome, %2$s: human-readable time difference */
								GPU_Translations::t( 'Last poll: %1$s (%2$s ago)' ),
								'ok' === $rt_res ? GPU_Translations::t( 'price checked' ) : GPU_Translations::t( 'failed' ),
								human_time_diff( $rt_at, time() )
							)
						);
						$rt_q = (int) ( $state['last_realtime_queued'] ?? 0 );
						if ( $rt_q > 0 ) {
							echo ' · ' . esc_html( sprintf( GPU_Translations::t( '%d product(s) repriced' ), $rt_q ) );
						}
					}
					?>
				</div>
			</div>
		</div>

	<!-- Progress -->
	<?php if ( $total_products > 0 ) : ?>
		<div class="gpu-progress-section">
			<div class="gpu-progress-header">
				<span class="gpu-progress-title"><?php echo GPU_Translations::e( 'Product Update Progress' ); ?></span>
				<span class="gpu-progress-stats"><?php echo $progress_pct; ?>%</span>
			</div>
			<div class="gpu-progress-bar">
				<div class="gpu-progress-fill" id="gpu-progress-bar" style="width: <?php echo esc_attr( $progress_pct ); ?>%"></div>
			</div>
			<div class="gpu-progress-label" id="gpu-progress-text">
				<span>✅ <?php echo ( $counts['updated'] ?? 0 ) . ' ' . GPU_Translations::e( 'Updated' ); ?></span>
				<span>⏭️ <?php echo ( $counts['skipped'] ?? 0 ) . ' ' . GPU_Translations::e( 'Skipped' ); ?></span>
				<span>❌ <?php echo ( $counts['error'] ?? 0 ) . ' ' . GPU_Translations::e( 'Errors' ); ?></span>
				<span>⏳ <?php echo ( $counts['pending'] ?? 0 ) . ' ' . GPU_Translations::e( 'Pending' ); ?></span>
			</div>
		</div>
	<?php endif; ?>

	<!-- Tabs -->
	<div class="gpu-tabs">
		<button class="gpu-tab active" data-tab="general"><?php echo GPU_Translations::e( 'General' ); ?></button>
		<button class="gpu-tab" data-tab="pricing"><?php echo GPU_Translations::e( 'Pricing' ); ?></button>
		<button class="gpu-tab" data-tab="api"><?php echo GPU_Translations::e( 'API' ); ?></button>
		<button class="gpu-tab" data-tab="sync"><?php echo GPU_Translations::e( 'Sync' ); ?></button>
		<button class="gpu-tab" data-tab="advanced"><?php echo GPU_Translations::e( 'Advanced' ); ?></button>
	</div>

	<form method="post">
		<?php wp_nonce_field( 'gpu_settings' ); ?>

		<!-- General -->
		<div class="gpu-tab-content" data-tab="general">
			<div class="gpu-settings-section">
				<div class="gpu-section-header"><span class="gpu-section-title"><span class="gpu-section-icon">⚙️</span> <?php echo GPU_Translations::e( 'General Settings' ); ?></span></div>
				<div class="gpu-section-body">
					<table class="gpu-form-table" role="presentation">
						<tr><th><?php echo GPU_Translations::e( 'Enable Plugin' ); ?></th><td><label><input type="checkbox" name="gpu_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> <?php echo GPU_Translations::e( 'Enable gold price auto-update' ); ?></label></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Price Mode' ); ?></th><td><select name="gpu_price_mode"><option value="api" <?php selected( $settings['price_mode'], 'api' ); ?>><?php echo GPU_Translations::e( 'API only' ); ?></option><option value="manual" <?php selected( $settings['price_mode'], 'manual' ); ?>><?php echo GPU_Translations::e( 'Manual only' ); ?></option><option value="fallback" <?php selected( $settings['price_mode'], 'fallback' ); ?>><?php echo GPU_Translations::e( 'API with fallback to manual (Recommended)' ); ?></option></select><span class="description"><?php echo GPU_Translations::e( 'How the gold price is determined.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Manual Price' ); ?></th><td><input type="number" step="any" min="0" name="gpu_manual_price" value="<?php echo esc_attr( $settings['manual_price'] ); ?>"><span class="description"><?php echo GPU_Translations::e( 'Used in manual mode or as fallback when API fails.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Price Buffer' ); ?></th><td><input type="number" step="any" min="0" name="gpu_buffer" value="<?php echo esc_attr( $settings['buffer'] ); ?>"><span class="description"><?php echo GPU_Translations::e( 'Added to the gold price before product calculation.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Gold Karat' ); ?></th><td><select name="gpu_karat"><?php foreach ( array( '18k', '21k', '24k', '14k' ) as $k ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $settings['karat'], $k ); ?>><?php echo esc_html( $k ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Target Currency' ); ?></th><td><select name="gpu_target_currency"><?php foreach ( array( 'IRR', 'TOMAN', 'USD' ) as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>" <?php selected( $settings['target_currency'], $c ); ?>><?php echo esc_html( $c ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Max Price Change %' ); ?></th><td><input type="number" step="any" min="0" name="gpu_max_change" value="<?php echo esc_attr( $settings['max_price_change'] ); ?>"><span class="description"><?php echo GPU_Translations::e( '0 = disabled. Blocks sync if price changes more than this.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Sale Price Behavior' ); ?></th><td><select name="gpu_sale_mode"><option value="regular_only" <?php selected( $settings['sale_mode'], 'regular_only' ); ?>><?php echo GPU_Translations::e( 'Update regular price only' ); ?></option><option value="skip_on_sale" <?php selected( $settings['sale_mode'], 'skip_on_sale' ); ?>><?php echo GPU_Translations::e( 'Skip products with active sale' ); ?></option></select></td></tr>
					</table>
				</div>
			</div>
		</div>

		<!-- Pricing -->
		<div class="gpu-tab-content" data-tab="pricing" style="display:none">
			<div class="gpu-settings-section">
				<div class="gpu-section-header"><span class="gpu-section-title"><span class="gpu-section-icon">💎</span> <?php echo GPU_Translations::e( 'Pricing Formula' ); ?></span></div>
				<div class="gpu-section-body">
					<table class="gpu-form-table" role="presentation">
						<tr><th><?php echo GPU_Translations::e( 'Making Charge %' ); ?></th><td><input type="number" step="any" min="0" max="100" name="gpu_default_mk" value="<?php echo esc_attr( $settings['default_making_charge_pct'] ); ?>"><span class="description"><?php echo GPU_Translations::e( 'Labor cost percentage applied to gold value.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Seller Profit %' ); ?></th><td><input type="number" step="any" min="0" max="100" name="gpu_profit" value="<?php echo esc_attr( $settings['seller_profit_pct'] ); ?>"><span class="description"><?php echo GPU_Translations::e( 'Applied to (gold value + making charge).' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'VAT %' ); ?></th><td><input type="number" step="any" min="0" max="100" name="gpu_vat" value="<?php echo esc_attr( $settings['vat_pct'] ); ?>"><span class="description"><?php echo GPU_Translations::e( 'Applied to making charge only.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Rounding Mode' ); ?></th><td><div class="gpu-field-group"><select name="gpu_round_mode"><?php foreach ( array( 'none' => 'None', 'nearest' => 'Nearest', 'up' => 'Round Up', 'down' => 'Round Down' ) as $val => $label ) : ?><option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['round_mode'], $val ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><input type="number" step="any" min="0" name="gpu_round_step" value="<?php echo esc_attr( $settings['round_step'] ); ?>" placeholder="Step" style="max-width:120px"></div><span class="description"><?php echo GPU_Translations::e( 'e.g. step 1000 rounds to nearest/next 1,000.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Price Decimals' ); ?></th><td><input type="number" min="0" max="6" name="gpu_decimals" value="<?php echo esc_attr( $settings['decimals'] ); ?>"></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Thousands Separator' ); ?></th><td><input type="text" maxlength="4" style="max-width:80px" name="gpu_thousand_sep" value="<?php echo esc_attr( $settings['thousand_sep'] ); ?>" placeholder="."><span class="description"><?php echo GPU_Translations::e( 'Character between digit groups (e.g. . , space). Empty = no grouping.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Decimal Separator' ); ?></th><td><input type="text" maxlength="4" style="max-width:80px" name="gpu_decimal_sep" value="<?php echo esc_attr( $settings['decimal_sep'] ); ?>" placeholder=","><span class="description"><?php echo GPU_Translations::e( 'Character before the decimals (e.g. , or .).' ); ?></span></td></tr>
					</table>
				</div>
			</div>
		</div>

		<!-- API -->
		<div class="gpu-tab-content" data-tab="api" style="display:none">
			<div class="gpu-settings-section">
				<div class="gpu-section-header"><span class="gpu-section-title"><span class="gpu-section-icon">🔌</span> <?php echo GPU_Translations::e( 'API Configuration' ); ?></span></div>
				<div class="gpu-section-body">
					<table class="gpu-form-table" role="presentation">
						<tr><th><?php echo GPU_Translations::e( 'Provider' ); ?></th><td><select name="gpu_provider"><?php foreach ( $providers as $key => $p ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['provider'], $key ); ?>><?php echo esc_html( $p['label'] ); ?></option><?php endforeach; ?></select><span class="description"><?php echo GPU_Translations::e( 'Select a preset or choose Custom.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'API URL' ); ?></th><td><input type="url" name="gpu_api_url" value="<?php echo esc_attr( $settings['api_url'] ); ?>"></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Auth Method' ); ?></th><td><select name="gpu_auth"><?php foreach ( array( 'none' => 'None', 'header' => 'API Key Header', 'bearer' => 'Bearer Token', 'query' => 'Query String', 'basic' => 'Basic Auth' ) as $val => $label ) : ?><option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['auth_method'], $val ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'API Key' ); ?></th><td><input type="text" name="gpu_api_key" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off"><?php if ( '' !== $settings['api_key'] ) : ?><span class="description">🔐 <?php echo esc_html( GPU_Settings::mask( $settings['api_key'] ) ); ?></span><?php endif; ?></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'API Secret' ); ?></th><td><input type="text" name="gpu_api_secret" value="<?php echo esc_attr( $settings['api_secret'] ); ?>" autocomplete="off"></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Request Method' ); ?></th><td><select name="gpu_method"><option value="GET" <?php selected( $settings['request_method'], 'GET' ); ?>>GET</option><option value="POST" <?php selected( $settings['request_method'], 'POST' ); ?>>POST</option></select></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'JSON Path' ); ?></th><td><input type="text" name="gpu_json_path" value="<?php echo esc_attr( $settings['json_path'] ); ?>" placeholder="data.price"><span class="description"><?php echo GPU_Translations::e( 'Dot notation to extract price from JSON response.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Price Unit' ); ?></th><td><select name="gpu_price_unit"><?php foreach ( $units as $u ) : ?><option value="<?php echo esc_attr( $u ); ?>" <?php selected( $settings['price_unit'], $u ); ?>><?php echo esc_html( $u ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'API Currency' ); ?></th><td><select name="gpu_api_currency"><?php foreach ( array( 'IRR', 'TOMAN', 'USD' ) as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>" <?php selected( $settings['api_currency'], $c ); ?>><?php echo esc_html( $c ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Timeout (seconds)' ); ?></th><td><input type="number" min="2" max="120" name="gpu_timeout" value="<?php echo esc_attr( $settings['timeout'] ); ?>"></td></tr>
					</table>
					<p style="margin-top:16px"><a class="gpu-btn gpu-btn-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gpu_test_api' ), 'gpu_test_api' ) ); ?>">🔍 <?php echo GPU_Translations::e( 'Test API Connection' ); ?></a></p>
				</div>
			</div>
		</div>

		<!-- Sync -->
		<div class="gpu-tab-content" data-tab="sync" style="display:none">
			<div class="gpu-settings-section">
				<div class="gpu-section-header"><span class="gpu-section-title"><span class="gpu-section-icon">🔄</span> <?php echo GPU_Translations::e( 'Sync Settings' ); ?></span></div>
				<div class="gpu-section-body">
					<table class="gpu-form-table" role="presentation">
						<tr><th><?php echo GPU_Translations::e( 'Update Interval' ); ?></th><td><input type="number" min="60" name="gpu_interval" value="<?php echo esc_attr( $settings['update_interval'] ); ?>"><span class="description"><?php echo GPU_Translations::e( 'Seconds between automatic syncs (min 60).' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Realtime Updates' ); ?></th><td><label><input type="checkbox" name="gpu_realtime_enabled" value="1" <?php checked( ! empty( $settings['realtime_enabled'] ) ); ?>> <?php echo GPU_Translations::e( 'Poll the gold price at short intervals and update prices instantly when it changes (nothing runs when the price is unchanged).' ); ?></label></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Realtime Poll Interval' ); ?></th><td><input type="number" min="15" max="3600" name="gpu_realtime_interval" value="<?php echo esc_attr( $settings['realtime_interval'] ); ?>"><span class="description"><?php echo GPU_Translations::e( 'Seconds between realtime polls (min 15). Unchanged polls are nearly free.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Batch Size' ); ?></th><td><input type="number" min="1" max="500" name="gpu_batch" value="<?php echo esc_attr( $settings['batch_size'] ); ?>"><span class="description"><?php echo GPU_Translations::e( 'Products processed per queue tick.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Retry Count' ); ?></th><td><input type="number" min="0" max="10" name="gpu_retries" value="<?php echo esc_attr( $settings['retry_count'] ); ?>"></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Retry Delay' ); ?></th><td><input type="number" min="30" name="gpu_retry_delay" value="<?php echo esc_attr( $settings['retry_delay'] ); ?>"><span class="description"><?php echo GPU_Translations::e( 'Seconds between retry attempts.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Lock Timeout' ); ?></th><td><input type="number" min="60" name="gpu_lock_timeout" value="<?php echo esc_attr( $settings['lock_timeout'] ); ?>"><span class="description"><?php echo GPU_Translations::e( 'Prevents concurrent syncs.' ); ?></span></td></tr>
					</table>
				</div>
			</div>
		</div>

		<!-- Advanced -->
		<div class="gpu-tab-content" data-tab="advanced" style="display:none">
			<div class="gpu-settings-section">
				<div class="gpu-section-header"><span class="gpu-section-title"><span class="gpu-section-icon">🔧</span> <?php echo GPU_Translations::e( 'Advanced Settings' ); ?></span></div>
				<div class="gpu-section-body">
					<table class="gpu-form-table" role="presentation">
						<tr><th><?php echo GPU_Translations::e( 'Debug Mode' ); ?></th><td><label><input type="checkbox" name="gpu_debug" value="1" <?php checked( ! empty( $settings['debug'] ) ); ?>> <?php echo GPU_Translations::e( 'Show response details in logs' ); ?></label></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Logging' ); ?></th><td><label><input type="checkbox" name="gpu_logging" value="1" <?php checked( ! empty( $settings['logging_enabled'] ) ); ?>> <?php echo GPU_Translations::e( 'Enable activity logging' ); ?></label></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Log Retention' ); ?></th><td><input type="number" min="1" name="gpu_log_days" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>"><span class="description"><?php echo GPU_Translations::e( 'Days to keep log entries.' ); ?></span></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Email on Failure' ); ?></th><td><label><input type="checkbox" name="gpu_email_on_error" value="1" <?php checked( ! empty( $settings['email_on_error'] ) ); ?>> <?php echo GPU_Translations::e( 'Send email after repeated sync failures' ); ?></label></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'Email Recipient' ); ?></th><td><input type="email" name="gpu_error_email" value="<?php echo esc_attr( $settings['error_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td></tr>
						<tr><th><?php echo GPU_Translations::e( 'GitHub Repo' ); ?></th><td><input type="text" name="gpu_github_repo" value="<?php echo esc_attr( $settings['github_repo'] ); ?>" placeholder="user/plugin-repo"><span class="description"><?php echo GPU_Translations::e( 'For automatic plugin updates.' ); ?></span></td></tr>
					</table>
				</div>
			</div>
		</div>

		<div style="margin-top:24px;padding:20px;background:var(--gpu-card-bg);border-radius:var(--gpu-radius);border:1px solid var(--gpu-border)">
			<button type="submit" name="gpu_save" class="gpu-btn gpu-btn-primary">💾 <?php echo GPU_Translations::e( 'Save Settings' ); ?></button>
		</div>
	</form>

	<!-- Sync History -->
	<?php if ( ! empty( $history ) ) : ?>
		<div class="gpu-settings-section" style="margin-top:24px">
			<div class="gpu-section-header"><span class="gpu-section-title"><span class="gpu-section-icon">📊</span> <?php echo GPU_Translations::e( 'Sync History' ); ?></span></div>
			<div class="gpu-section-body" style="padding:0">
				<table class="gpu-table">
					<thead><tr><th>#</th><th><?php echo GPU_Translations::e( 'Time' ); ?></th><th><?php echo GPU_Translations::e( 'Source' ); ?></th><th><?php echo GPU_Translations::e( 'Gold Price' ); ?></th><th><?php echo GPU_Translations::e( 'Effective' ); ?></th><th><?php echo GPU_Translations::e( 'Products' ); ?></th><th><?php echo GPU_Translations::e( 'Status' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( array_slice( $history, 0, 10 ) as $h ) : ?>
							<tr>
								<td><?php echo esc_html( $h['id'] ); ?></td>
								<td><?php echo wp_date( 'M j, Y H:i', (int) $h['started_at'] ); ?></td>
								<td><?php echo esc_html( $h['source'] ); ?></td>
								<td><?php echo null === $h['gold_price'] ? '—' : GPU_Formatter::number( (float) $h['gold_price'] ); ?></td>
								<td><?php echo null === $h['effective_price'] ? '—' : GPU_Formatter::number( (float) $h['effective_price'] ); ?></td>
								<td><?php echo (int) $h['products_total']; ?></td>
								<td><span class="gpu-badge gpu-badge-<?php echo 'queued' === $h['status'] ? 'info' : ( 'failed' === $h['status'] ? 'danger' : 'success' ); ?>"><?php echo esc_html( $h['status'] ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

	<!-- Logs -->
	<?php if ( ! empty( $logs ) ) : ?>
		<div class="gpu-settings-section" style="margin-top:24px">
			<div class="gpu-section-header"><span class="gpu-section-title"><span class="gpu-section-icon">📝</span> <?php echo GPU_Translations::e( 'Recent Logs' ); ?></span></div>
			<div class="gpu-section-body" style="padding:0">
				<table class="gpu-table">
					<thead><tr><th><?php echo GPU_Translations::e( 'Time' ); ?></th><th><?php echo GPU_Translations::e( 'Level' ); ?></th><th><?php echo GPU_Translations::e( 'Event' ); ?></th><th><?php echo GPU_Translations::e( 'Message' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( array_slice( $logs, 0, 15 ) as $log ) : ?>
							<tr>
								<td><?php echo wp_date( 'H:i:s', (int) $log['create_at'] ); ?></td>
								<td><span class="gpu-badge gpu-badge-<?php echo 'error' === $log['level'] ? 'danger' : ( 'warning' === $log['level'] ? 'warning' : 'info' ); ?>"><?php echo esc_html( $log['level'] ); ?></span></td>
								<td><?php echo esc_html( $log['event'] ); ?></td>
								<td><?php echo esc_html( mb_strimwidth( $log['message'], 0, 80, '...' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

</div>

<!-- AraDev Footer -->
<div class="gpu-wrap" dir="<?php echo $dir; ?>">
	<div class="gpu-footer">
		<div class="gpu-footer-content">
			<span class="gpu-footer-logo">⚜</span>
			<span>ساخته شده توسط <strong>AraDev</strong></span>
			<span class="gpu-footer-version">v<?php echo esc_html( GPU_VERSION ); ?></span>
			<a href="https://aradev.ir" target="_blank" rel="noopener">aradev.ir</a>
		</div>
	</div>
</div>
