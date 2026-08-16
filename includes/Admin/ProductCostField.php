<?php
/**
 * WooCommerce product-cost field for FaraCart (UPSELL_REFACTOR §19/§20).
 *
 * @package GoalCart
 */

namespace GoalCart\Admin;

use GoalCart\Analytics\RewardCostEstimator;
use GoalCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class ProductCostField
 *
 * UPSELL_REFACTOR §19/§20 — adds a "Product cost" field to the WooCommerce
 * product editor (simple products AND per-variation), stored under Goal
 * Cart's own namespaced meta key (`_goalcart_product_cost`) so it never
 * overloads unrelated WooCommerce fields and never duplicates an existing
 * cost source.
 *
 * The field is optional: products without a cost simply have limited
 * profit/economics data (the estimator degrades gracefully, §18/§19).
 * Only administrators who can edit the product can read or write the
 * value — the product-edit screen is already capability-gated by
 * WooCommerce core, and this class adds an explicit guard on top.
 *
 * Reads flow through the normal RewardCostEstimator chain
 * (`_goalcart_product_cost` → `_cost` → `_wc_cog_cost`), so once a cost
 * is saved here, Estimated Profit, Goal economics and the upsell margin
 * scorer all pick it up with no further configuration.
 */
final class ProductCostField {

	/**
	 * Register the product-edit hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$hooks->add_action( 'woocommerce_product_options_pricing', array( $this, 'render_simple_field' ) );
		$hooks->add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_field' ), 10, 3 );
		$hooks->add_action( 'woocommerce_process_product_meta', array( $this, 'save_simple' ) );
		$hooks->add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
	}

	/**
	 * Render the cost field for simple (non-variable) products.
	 *
	 * Runs inside the General → Pricing section; the field follows the
	 * same woocommerce_wp_text_input conventions as the core price fields.
	 *
	 * @return void
	 */
	public function render_simple_field() {
		global $post;

		if ( ! $post || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return;
		}

		$product = wc_get_product( (int) $post->ID );

		// Variable products manage cost per variation — no parent field.
		if ( $product && 'variable' === $product->get_type() ) {
			return;
		}

		woocommerce_wp_text_input(
			array(
				'id'                => RewardCostEstimator::PRODUCT_COST_META,
				'label'             => __( 'Product cost', 'goalcart' ) . ' (' . __( 'FaraCart', 'goalcart' ) . ')',
				'description'       => __( 'The unit cost FaraCart uses for Estimated Profit, Goal economics and upsell margin scoring. Leave empty when unknown.', 'goalcart' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				),
			)
		);
	}

	/**
	 * Render the cost field inside each variation's pricing section.
	 *
	 * @param int               $loop           Variation loop index.
	 * @param array<string, mixed> $variation_data Variation data (unused).
	 * @param \WP_Post          $variation      Variation post.
	 * @return void
	 */
	public function render_variation_field( $loop, $variation_data, $variation ) {
		if ( ! $variation || ! current_user_can( 'edit_post', (int) $variation->ID ) ) {
			return;
		}

		$value = get_post_meta( (int) $variation->ID, RewardCostEstimator::PRODUCT_COST_META, true );

		echo '<div class="form-row form-row-full">';
		printf(
			'<label for="%1$s">%2$s</label>',
			esc_attr( 'goalcart_product_cost_' . (int) $loop ),
			esc_html__( 'Product cost', 'goalcart' ) . ' (' . esc_html__( 'FaraCart', 'goalcart' ) . ')'
		);
		printf(
			'<input type="number" step="any" min="0" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s" class="short" />',
			esc_attr( 'goalcart_product_cost_' . (int) $loop ),
			esc_attr( 'goalcart_product_cost[' . (int) $loop . ']' ),
			esc_attr( is_numeric( $value ) ? (string) round( (float) $value, 4 ) : '' ),
			esc_attr__( 'Cost per unit (FaraCart)', 'goalcart' )
		);
		echo '</div>';
	}

	/**
	 * Save the simple-product cost field.
	 *
	 * @param int $post_id Product id.
	 * @return void
	 */
	public function save_simple( $post_id ) {
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce core verifies the product-edit nonce before this hook fires.
		$raw = isset( $_POST[ RewardCostEstimator::PRODUCT_COST_META ] ) ? wp_unslash( $_POST[ RewardCostEstimator::PRODUCT_COST_META ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.

		$this->save_cost_meta( (int) $post_id, $raw );
	}

	/**
	 * Save one variation's cost field.
	 *
	 * @param int $variation_id Variation id.
	 * @param int $loop         Variation loop index.
	 * @return void
	 */
	public function save_variation( $variation_id, $loop ) {
		if ( ! current_user_can( 'edit_post', (int) $variation_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce core verifies the product-edit nonce before this hook fires.
		$values = isset( $_POST['goalcart_product_cost'] ) && is_array( $_POST['goalcart_product_cost'] )
			? wp_unslash( $_POST['goalcart_product_cost'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
			: array();

		$raw = isset( $values[ (int) $loop ] ) ? $values[ (int) $loop ] : '';

		$this->save_cost_meta( (int) $variation_id, $raw );
	}

	/**
	 * Persist a cost value (or delete the meta when empty/invalid).
	 *
	 * @param int    $id   Product or variation id.
	 * @param string $raw  Raw submitted value.
	 * @return void
	 */
	protected function save_cost_meta( $id, $raw ) {
		if ( $id < 1 ) {
			return;
		}

		if ( ! is_numeric( $raw ) || (float) $raw <= 0 ) {
			delete_post_meta( $id, RewardCostEstimator::PRODUCT_COST_META );

			return;
		}

		update_post_meta( $id, RewardCostEstimator::PRODUCT_COST_META, round( (float) $raw, 4 ) );
	}
}
