<?php
/**
 * Product edit meta box — managed flag, making charge %, accessories
 * (accessory weight + stone fee), per-product seller profit / VAT overrides,
 * live preview.
 *
 * The preview endpoint computes a read-only breakdown with the Pricing
 * Engine and the CURRENT saved gold price; it never writes product data.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_Meta {

	/**
	 * @return void
	 */
	public static function register() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'wp_ajax_gpu_preview_price', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
	}

	/**
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'gpu-pricing',
			GPU_Translations::t( 'Gold Pricing' ),
			array( __CLASS__, 'render' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * @param WP_Post $post
	 *
	 * @return void
	 */
	public static function render( $post ) {
		$product_id = (int) $post->ID;
		$managed    = 'yes' === get_post_meta( $product_id, GPU_Products::META_MANAGED, true );
		$mk_pct     = get_post_meta( $product_id, GPU_Products::META_MAKING_CHARGE, true );
		$acc_w      = get_post_meta( $product_id, GPU_Products::META_ACCESSORY_WEIGHT, true );
		$stone_fee  = get_post_meta( $product_id, GPU_Products::META_STONE_FEE, true );
		$profit_ovr = get_post_meta( $product_id, GPU_Products::META_SELLER_PROFIT, true );
		$vat_ovr    = get_post_meta( $product_id, GPU_Products::META_VAT, true );
		$acc_making = 'yes' === get_post_meta( $product_id, GPU_Products::META_ACC_IN_MAKING, true );

		$settings   = GPU_Settings::get();
		$def_profit = (float) $settings['seller_profit_pct'];
		$def_vat    = (float) $settings['vat_pct'];

		wp_nonce_field( 'gpu_meta_save', 'gpu_meta_nonce' );
		?>
		<p>
			<label>
				<input type="checkbox" name="gpu_managed" value="yes" <?php checked( $managed ); ?>>
				<?php echo GPU_Translations::e( 'Managed by Gold Price Updater' ); ?>
			</label>
		</p>
		<p>
			<label for="gpu_making_charge"><strong><?php echo GPU_Translations::e( 'Making Charge %' ); ?></strong></label><br>
			<input type="number" step="0.01" min="0" max="100" id="gpu_making_charge" name="gpu_making_charge"
				value="<?php echo esc_attr( $mk_pct ); ?>" style="width:100%">
			<span class="description"><?php echo GPU_Translations::e( 'Percent of the raw gold value. Empty = use the plugin default.' ); ?></span>
		</p>

		<p><strong style="display:block;margin-bottom:4px"><?php echo GPU_Translations::e( 'Accessories (Extras)' ); ?></strong></p>
		<p style="margin-top:0">
			<label for="gpu_accessory_weight"><?php echo GPU_Translations::e( 'Accessory Weight (g)' ); ?></label><br>
			<input type="number" step="0.001" min="0" id="gpu_accessory_weight" name="gpu_accessory_weight"
				value="<?php echo esc_attr( $acc_w ); ?>" style="width:100%">
			<span class="description"><?php echo GPU_Translations::e( 'Extra metal (stone setting, second alloy...) priced at the same gold price. Empty/0 = none.' ); ?></span>
		</p>
		<p>
			<label for="gpu_stone_fee"><?php echo GPU_Translations::e( 'Stone / Accessory Fee' ); ?></label><br>
			<input type="number" step="any" min="0" id="gpu_stone_fee" name="gpu_stone_fee"
				value="<?php echo esc_attr( $stone_fee ); ?>" style="width:100%">
			<span class="description"><?php echo GPU_Translations::e( 'Fixed fee added to the final price, in the shop currency. Empty/0 = none.' ); ?></span>
		</p>
		<p>
			<label>
				<input type="checkbox" name="gpu_acc_in_making" value="yes" <?php checked( $acc_making ); ?>>
				<?php echo GPU_Translations::e( 'Include accessory weight in Making Charge' ); ?>
			</label>
		</p>

		<p><strong style="display:block;margin-bottom:4px"><?php echo GPU_Translations::e( 'Per-Product Overrides' ); ?></strong></p>
		<p style="margin-top:0">
			<label for="gpu_seller_profit"><?php echo GPU_Translations::e( 'Seller Profit %' ); ?></label><br>
			<input type="number" step="0.01" min="0" max="100" id="gpu_seller_profit" name="gpu_seller_profit"
				value="<?php echo esc_attr( $profit_ovr ); ?>" style="width:100%"
				placeholder="<?php echo esc_attr( $def_profit ); ?>">
			<span class="description"><?php echo GPU_Translations::e( 'Empty = plugin default' ); ?>
				(<?php echo esc_html( $def_profit ); ?>%)</span>
		</p>
		<p>
			<label for="gpu_vat"><?php echo GPU_Translations::e( 'VAT %' ); ?></label><br>
			<input type="number" step="0.01" min="0" max="100" id="gpu_vat" name="gpu_vat"
				value="<?php echo esc_attr( $vat_ovr ); ?>" style="width:100%"
				placeholder="<?php echo esc_attr( $def_vat ); ?>">
			<span class="description"><?php echo GPU_Translations::e( 'Empty = plugin default' ); ?>
				(<?php echo esc_html( $def_vat ); ?>%)</span>
		</p>

		<div id="gpu-preview-box" style="margin-top:10px">
			<button type="button" class="button" id="gpu-preview-btn"><?php echo GPU_Translations::e( 'Recalculate Preview' ); ?></button>
			<div id="gpu-preview-result" style="margin-top:8px;font-family:monospace;font-size:12px;display:none"></div>
		</div>
		<?php
	}

	/**
	 * Save the meta box (product save).
	 *
	 * @param int $product_id
	 *
	 * @return void
	 */
	public static function save( $product_id ) {
		if ( ! isset( $_POST['gpu_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['gpu_meta_nonce'] ), 'gpu_meta_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- values sanitized explicitly below.
		$managed = isset( $_POST['gpu_managed'] ) && 'yes' === $_POST['gpu_managed'] ? 'yes' : 'no';
		update_post_meta( $product_id, GPU_Products::META_MANAGED, $managed );

		// Making charge % (empty = inherit).
		if ( isset( $_POST['gpu_making_charge'] ) && '' !== trim( (string) $_POST['gpu_making_charge'] ) ) {
			$mk = (float) $_POST['gpu_making_charge'];
			if ( $mk >= 0 && $mk <= 100 ) {
				update_post_meta( $product_id, GPU_Products::META_MAKING_CHARGE, (string) $mk );
			}
		} else {
			delete_post_meta( $product_id, GPU_Products::META_MAKING_CHARGE );
		}

		// Accessory weight in grams (empty/0 = none → meta deleted).
		if ( isset( $_POST['gpu_accessory_weight'] ) && '' !== trim( (string) $_POST['gpu_accessory_weight'] ) ) {
			$acc = (float) $_POST['gpu_accessory_weight'];
			if ( $acc > 0 ) {
				update_post_meta( $product_id, GPU_Products::META_ACCESSORY_WEIGHT, (string) $acc );
			} else {
				delete_post_meta( $product_id, GPU_Products::META_ACCESSORY_WEIGHT );
			}
		} else {
			delete_post_meta( $product_id, GPU_Products::META_ACCESSORY_WEIGHT );
		}

		// Fixed stone/accessory fee (empty/0 = none → meta deleted).
		if ( isset( $_POST['gpu_stone_fee'] ) && '' !== trim( (string) $_POST['gpu_stone_fee'] ) ) {
			$fee = (float) $_POST['gpu_stone_fee'];
			if ( $fee > 0 ) {
				update_post_meta( $product_id, GPU_Products::META_STONE_FEE, (string) $fee );
			} else {
				delete_post_meta( $product_id, GPU_Products::META_STONE_FEE );
			}
		} else {
			delete_post_meta( $product_id, GPU_Products::META_STONE_FEE );
		}

		update_post_meta(
			$product_id,
			GPU_Products::META_ACC_IN_MAKING,
			isset( $_POST['gpu_acc_in_making'] ) && 'yes' === $_POST['gpu_acc_in_making'] ? 'yes' : 'no'
		);

		// Per-product seller profit % (empty = inherit).
		if ( isset( $_POST['gpu_seller_profit'] ) && '' !== trim( (string) $_POST['gpu_seller_profit'] ) ) {
			$pct = (float) $_POST['gpu_seller_profit'];
			if ( $pct >= 0 && $pct <= 100 ) {
				update_post_meta( $product_id, GPU_Products::META_SELLER_PROFIT, (string) $pct );
			}
		} else {
			delete_post_meta( $product_id, GPU_Products::META_SELLER_PROFIT );
		}

		// Per-product VAT % (empty = inherit).
		if ( isset( $_POST['gpu_vat'] ) && '' !== trim( (string) $_POST['gpu_vat'] ) ) {
			$pct = (float) $_POST['gpu_vat'];
			if ( $pct >= 0 && $pct <= 100 ) {
				update_post_meta( $product_id, GPU_Products::META_VAT, (string) $pct );
			}
		} else {
			delete_post_meta( $product_id, GPU_Products::META_VAT );
		}
		// phpcs:enable
	}

	/**
	 * AJAX preview — read-only breakdown for the product editor, including the
	 * saved accessory fields and per-product overrides (empty boxes resolve to
	 * the plugin defaults, exactly like a real sync).
	 *
	 * @return void
	 */
	public static function ajax_preview() {
		check_ajax_referer( 'gpu_preview', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$mk_pct_raw = isset( $_POST['making_charge'] ) ? wp_unslash( (string) $_POST['making_charge'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$acc_raw    = isset( $_POST['accessory_weight'] ) ? wp_unslash( (string) $_POST['accessory_weight'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$fee_raw    = isset( $_POST['stone_fee'] ) ? wp_unslash( (string) $_POST['stone_fee'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$profit_raw = isset( $_POST['seller_profit'] ) ? wp_unslash( (string) $_POST['seller_profit'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$vat_raw    = isset( $_POST['vat'] ) ? wp_unslash( (string) $_POST['vat'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$acc_making = isset( $_POST['acc_in_making'] ) && 'yes' === $_POST['acc_in_making']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => 'Product not found.' ), 404 );
		}

		$managed_id = $product->get_parent_id() ? $product->get_parent_id() : $product_id;
		$settings   = GPU_Settings::get();

		// Unsaved box → fall back to what a sync would use (saved meta, then defaults).
		$mk_pct = '' === trim( $mk_pct_raw )
			? GPU_Products::making_charge_pct( $product_id, $managed_id )
			: ( is_numeric( $mk_pct_raw ) ? (float) $mk_pct_raw : null );

		if ( null === $mk_pct ) {
			wp_send_json_error( array( 'message' => 'Invalid making charge.' ), 400 );
		}

		$accessory_weight = '' === trim( $acc_raw )
			? GPU_Products::accessory_weight( $product_id, $managed_id )
			: ( is_numeric( $acc_raw ) ? max( 0.0, (float) $acc_raw ) : 0.0 );

		$stone_fee = '' === trim( $fee_raw )
			? GPU_Products::stone_fee( $product_id, $managed_id )
			: ( is_numeric( $fee_raw ) ? max( 0.0, (float) $fee_raw ) : 0.0 );

		$profit_pct = '' === trim( $profit_raw )
			? GPU_Products::seller_profit_pct( $product_id, $managed_id )
			: ( is_numeric( $profit_raw ) ? (float) $profit_raw : (float) $settings['seller_profit_pct'] );

		$vat_pct = '' === trim( $vat_raw )
			? GPU_Products::vat_pct( $product_id, $managed_id )
			: ( is_numeric( $vat_raw ) ? (float) $vat_raw : (float) $settings['vat_pct'] );

		$weight = GPU_Products::weight_in_grams( $product, $managed_id );
		if ( null === $weight ) {
			wp_send_json_error( array( 'message' => 'Product has no weight set.' ), 400 );
		}

		$effective = GPU_Gold_Price_Service::effective_price();
		if ( null === $effective ) {
			wp_send_json_error( array( 'message' => 'No valid gold price yet — run a sync first.' ), 409 );
		}

		$result = GPU_Pricing_Engine::price(
			$weight,
			$effective,
			0.0,
			$mk_pct,
			$profit_pct,
			$vat_pct,
			array(
				'decimals'                    => (int) $settings['decimals'],
				'round_mode'                  => (string) $settings['round_mode'],
				'round_step'                  => (float) $settings['round_step'],
				'accessory_weight'            => $accessory_weight,
				'stone_fee'                   => $stone_fee,
				'include_accessory_in_making' => $acc_making,
			)
		);

		if ( null === $result ) {
			wp_send_json_error( array( 'message' => 'Pricing engine rejected the input.' ), 400 );
		}

		wp_send_json_success( $result );
	}
}
