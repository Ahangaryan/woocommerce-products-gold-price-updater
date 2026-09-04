<?php
/**
 * Product edit meta box — managed flag, making charge %, live preview.
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
			__( 'Gold Pricing', 'gold-price-updater' ),
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

		wp_nonce_field( 'gpu_meta_save', 'gpu_meta_nonce' );
		?>
		<p>
			<label>
				<input type="checkbox" name="gpu_managed" value="yes" <?php checked( $managed ); ?>>
				<?php esc_html_e( 'Managed by Gold Price Updater', 'gold-price-updater' ); ?>
			</label>
		</p>
		<p>
			<label for="gpu_making_charge"><strong><?php esc_html_e( 'Making Charge %', 'gold-price-updater' ); ?></strong></label><br>
			<input type="number" step="0.01" min="0" max="100" id="gpu_making_charge" name="gpu_making_charge"
				value="<?php echo esc_attr( $mk_pct ); ?>" style="width:100%">
			<span class="description"><?php esc_html_e( 'Percent of the raw gold value. Empty = use the plugin default.', 'gold-price-updater' ); ?></span>
		</p>
		<div id="gpu-preview-box" style="margin-top:10px">
			<button type="button" class="button" id="gpu-preview-btn"><?php esc_html_e( 'Recalculate Preview', 'gold-price-updater' ); ?></button>
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

		$managed = isset( $_POST['gpu_managed'] ) && 'yes' === $_POST['gpu_managed'] ? 'yes' : 'no';
		update_post_meta( $product_id, GPU_Products::META_MANAGED, $managed );

		if ( isset( $_POST['gpu_making_charge'] ) && '' !== trim( (string) $_POST['gpu_making_charge'] ) ) {
			$mk = (float) $_POST['gpu_making_charge'];
			if ( $mk >= 0 && $mk <= 100 ) {
				update_post_meta( $product_id, GPU_Products::META_MAKING_CHARGE, (string) $mk );
			}
		} else {
			delete_post_meta( $product_id, GPU_Products::META_MAKING_CHARGE );
		}
	}

	/**
	 * AJAX preview — read-only breakdown for the product editor.
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

		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => 'Product not found.' ), 404 );
		}

		$mk_pct = '' === trim( $mk_pct_raw )
			? GPU_Products::making_charge_pct( $product_id, $product_id )
			: ( is_numeric( $mk_pct_raw ) ? (float) $mk_pct_raw : null );

		if ( null === $mk_pct ) {
			wp_send_json_error( array( 'message' => 'Invalid making charge.' ), 400 );
		}

		$weight = GPU_Products::weight_in_grams( $product, $product_id );
		if ( null === $weight ) {
			wp_send_json_error( array( 'message' => 'Product has no weight set.' ), 400 );
		}

		$effective = GPU_Gold_Price_Service::effective_price();
		if ( null === $effective ) {
			wp_send_json_error( array( 'message' => 'No valid gold price yet — run a sync first.' ), 409 );
		}

		$settings = GPU_Settings::get();
		$result   = GPU_Pricing_Engine::price(
			$weight,
			$effective,
			0.0,
			$mk_pct,
			(float) $settings['seller_profit_pct'],
			(float) $settings['vat_pct'],
			array(
				'decimals'   => (int) $settings['decimals'],
				'round_mode' => (string) $settings['round_mode'],
				'round_step' => (float) $settings['round_step'],
			)
		);

		if ( null === $result ) {
			wp_send_json_error( array( 'message' => 'Pricing engine rejected the input.' ), 400 );
		}

		wp_send_json_success( $result );
	}
}
