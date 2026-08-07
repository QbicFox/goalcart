<?php
/**
 * Cart line-item value object for the Goal Cart engine.
 *
 * @package GoalCart
 */

namespace GoalCart\Goals;

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
		$this->price         = isset( $data['price'] ) ? (float) $data['price'] : 0.0;
		$this->weight        = isset( $data['weight'] ) ? (float) $data['weight'] : 0.0;
		$this->categories    = isset( $data['categories'] ) && is_array( $data['categories'] ) ? array_map( 'intval', $data['categories'] ) : array();
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
