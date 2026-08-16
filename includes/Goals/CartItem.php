<?php
/**
 * Cart line-item value object for the FaraCart engine.
 *
 * @package FaraCart
 */

namespace FaraCart\Goals;

defined( 'ABSPATH' ) || exit;

/**
 * Class CartItem
 *
 * An immutable snapshot of one cart line, built either from a live
 * WooCommerce cart item (CartContext::from_cart()) or from plain data in
 * tests. Keeps the engine free of WooCommerce class dependencies: every
 * evaluator reads only these fields.
 */
final class CartItem {

	/**
	 * @var int
	 */
	protected $product_id;

	/**
	 * @var int
	 */
	protected $variation_id;

	/**
	 * @var string
	 */
	protected $name;

	/**
	 * Quantity (may be decimal, e.g. 0.5 kg of a product sold by weight).
	 *
	 * @var float
	 */
	protected $quantity;

	/**
	 * Line total before cart discounts, excluding tax (unit price x qty).
	 *
	 * @var float
	 */
	protected $line_subtotal;

	/**
	 * Line total after cart discounts, excluding tax (what the customer pays).
	 *
	 * @var float
	 */
	protected $line_total;

	/**
	 * Tax charged on this line (Phase 18: the `include_tax` calculation
	 * toggle folds line taxes into the money bases).
	 *
	 * @var float
	 */
	protected $line_tax;

	/**
	 * Current unit price.
	 *
	 * @var float
	 */
	protected $price;

	/**
	 * Unit weight (WooCommerce store unit).
	 *
	 * @var float
	 */
	protected $weight;

	/**
	 * Product category term IDs.
	 *
	 * @var int[]
	 */
	protected $categories;

	/**
	 * Product tag term IDs (Phase 32: tag / brand / attribute goals).
	 *
	 * @var int[]
	 */
	protected $tags;

	/**
	 * Global attribute taxonomy slugs present on the product, e.g.
	 * array( 'pa_color', 'pa_brand' ) (Phase 32).
	 *
	 * @var string[]
	 */
	protected $attributes;

	/**
	 * @var bool
	 */
	protected $virtual;

	/**
	 * @var bool
	 */
	protected $downloadable;

	/**
	 * Build an item from a data array.
	 *
	 * @param array<string, mixed> $data Item data.
	 */
	public function __construct( array $data = array() ) {
		$this->product_id   = isset( $data['product_id'] ) ? (int) $data['product_id'] : 0;
		$this->variation_id = isset( $data['variation_id'] ) ? (int) $data['variation_id'] : 0;
		$this->name         = isset( $data['name'] ) ? (string) $data['name'] : '';
		$this->quantity     = isset( $data['quantity'] ) ? (float) $data['quantity'] : 0.0;
		$this->line_subtotal = isset( $data['line_subtotal'] ) ? (float) $data['line_subtotal'] : 0.0;
		$this->line_total    = isset( $data['line_total'] ) ? (float) $data['line_total'] : 0.0;
		$this->line_tax      = isset( $data['line_tax'] ) ? (float) $data['line_tax'] : 0.0;
		$this->price         = isset( $data['price'] ) ? (float) $data['price'] : 0.0;
		$this->weight        = isset( $data['weight'] ) ? (float) $data['weight'] : 0.0;
		$this->categories    = isset( $data['categories'] ) && is_array( $data['categories'] ) ? array_map( 'intval', $data['categories'] ) : array();
		$this->tags          = isset( $data['tags'] ) && is_array( $data['tags'] ) ? array_map( 'intval', $data['tags'] ) : array();
		$this->attributes    = isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? array_map( array( $this, 'clean_text' ), array_map( 'strval', $data['attributes'] ) ) : array();
		$this->virtual       = ! empty( $data['virtual'] );
		$this->downloadable  = ! empty( $data['downloadable'] );
	}

	/**
	 * @return int
	 */
	public function product_id() {
		return $this->product_id;
	}

	/**
	 * @return int
	 */
	public function variation_id() {
		return $this->variation_id;
	}

	/**
	 * The ID the product goal matches against: the variation when present,
	 * otherwise the parent product.
	 *
	 * @return int
	 */
	public function effective_product_id() {
		return $this->variation_id > 0 ? $this->variation_id : $this->product_id;
	}

	/**
	 * @return string
	 */
	public function name() {
		return $this->name;
	}

	/**
	 * @return float
	 */
	public function quantity() {
		return $this->quantity;
	}

	/**
	 * @return float
	 */
	public function line_subtotal() {
		return $this->line_subtotal;
	}

	/**
	 * @return float
	 */
	public function line_total() {
		return $this->line_total;
	}

	/**
	 * @return float
	 */
	public function line_tax() {
		return $this->line_tax;
	}

	/**
	 * @return float
	 */
	public function price() {
		return $this->price;
	}

	/**
	 * @return float
	 */
	public function weight() {
		return $this->weight;
	}

	/**
	 * @return int[]
	 */
	public function categories() {
		return $this->categories;
	}

	/**
	 * @return int[]
	 */
	public function tags() {
		return $this->tags;
	}

	/**
	 * @return string[]
	 */
	public function attributes() {
		return $this->attributes;
	}

	/**
	 * Sanitize a plain string without hard-depending on WP being loaded
	 * (the standalone regression tests stub a minimal WP surface).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	protected function clean_text( $value ) {
		$value = (string) $value;

		return function_exists( 'sanitize_text_field' )
			? sanitize_text_field( $value )
			: trim( $value );
	}

	/**
	 * @return bool
	 */
	public function is_virtual() {
		return $this->virtual;
	}

	/**
	 * @return bool
	 */
	public function is_downloadable() {
		return $this->downloadable;
	}
}
