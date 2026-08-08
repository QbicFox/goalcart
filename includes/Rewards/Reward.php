<?php
/**
 * Reward value object for the Goal Cart engine.
 *
 * @package GoalCart
 */

namespace GoalCart\Rewards;

use GoalCart\Goals\Goal;

defined( 'ABSPATH' ) || exit;

/**
 * Class Reward
 *
 * An immutable, UI-independent representation of a goal's reward (P05-T02).
 * Rewards are decoupled from goal calculation: the GoalEngine computes a
 * GoalResult and the RewardEngine turns that result into a RewardResult
 * using the reward configuration carried here.
 *
 * The MVP embeds exactly one reward per goal (see Database\Schema), so a
 * Reward is derived from a Goal's reward columns plus the JSON `reward_meta`
 * (eligible products/categories, stacking rules, shipping method/zone
 * filters, gift product, coupon settings). The value object normalizes that
 * raw config into typed accessors the applicators can rely on.
 */
final class Reward {

	/**
	 * Reward types.
	 */
	const TYPE_FREE_SHIPPING    = 'free_shipping';
	const TYPE_PERCENT_DISCOUNT = 'percent_discount';
	const TYPE_FIXED_DISCOUNT   = 'fixed_discount';
	const TYPE_FREE_GIFT        = 'free_gift';
	const TYPE_COUPON           = 'coupon';

	/**
	 * Stacking rules.
	 */
	const STACK_NONE  = 'none';
	const STACK_STACK = 'stack';

	/**
	 * Gift add modes.
	 */
	const GIFT_AUTOMATIC = 'automatic';
	const GIFT_OPTIONAL  = 'optional';

	/**
	 * Coupon discount types (generated coupons).
	 */
	const COUPON_PERCENT   = 'percent';
	const COUPON_FIXED_CART = 'fixed_cart';

	/**
	 * The whitelist of reward types.
	 *
	 * Used by the analytics layer to validate the reward-type filter and
	 * by the admin app for the reward dropdown options.
	 *
	 * @return string[]
	 */
	public static function types() {
		return array(
			self::TYPE_FREE_SHIPPING,
			self::TYPE_PERCENT_DISCOUNT,
			self::TYPE_FIXED_DISCOUNT,
			self::TYPE_FREE_GIFT,
			self::TYPE_COUPON,
		);
	}
	/**
	 * Reward type (one of the TYPE_* constants, null when no reward).
	 *
	 * @var string|null
	 */
	protected $type;

	/**
	 * Reward value (percentage for percent discounts, amount otherwise).
	 *
	 * @var float|null
	 */
	protected $value;

	/**
	 * Cap for percentage discounts / generated coupons.
	 *
	 * @var float|null
	 */
	protected $max_value;

	/**
	 * Display label (fee/coupon name shown to the shopper).
	 *
	 * @var string
	 */
	protected $label;

	/**
	 * none|stack — whether this reward may combine with others.
	 *
	 * @var string
	 */
	protected $stacking;

	/**
	 * Product ids the reward applies to (empty = all).
	 *
	 * @var int[]
	 */
	protected $eligible_products;

	/**
	 * Category ids the reward applies to (empty = all).
	 *
	 * @var int[]
	 */
	protected $eligible_categories;

	/**
	 * Product ids the reward never applies to.
	 *
	 * @var int[]
	 */
	protected $excluded_products;

	/**
	 * Shipping zone ids the free-shipping reward covers (empty = all).
	 *
	 * @var int[]
	 */
	protected $shipping_zone_ids;

	/**
	 * Shipping method specs ('flat_rate' or 'flat_rate:3') the free-shipping
	 * reward covers (empty = all methods).
	 *
	 * @var string[]
	 */
	protected $shipping_method_ids;

	/**
	 * Gift product id for free-gift rewards.
	 *
	 * @var int
	 */
	protected $gift_product_id;

	/**
	 * automatic|optional — how the gift is added.
	 *
	 * @var string
	 */
	protected $gift_add_mode;

	/**
	 * Existing coupon code to apply (coupon rewards).
	 *
	 * @var string
	 */
	protected $coupon_code;

	/**
	 * Whether to generate a coupon from the reward rules (coupon rewards).
	 *
	 * @var bool
	 */
	protected $coupon_generate;

	/**
	 * Discount type for generated coupons (percent|fixed_cart).
	 *
	 * @var string
	 */
	protected $coupon_discount_type;

	/**
	 * Build a reward from a config array.
	 *
	 * @param array<string, mixed> $data Reward data.
	 */
	public function __construct( array $data = array() ) {
		$this->type                = isset( $data['type'] ) ? (string) $data['type'] : null;
		$this->value               = isset( $data['value'] ) ? (float) $data['value'] : null;
		$this->max_value           = isset( $data['max_value'] ) ? (float) $data['max_value'] : null;
		$this->label               = isset( $data['label'] ) ? (string) $data['label'] : '';
		$this->stacking            = isset( $data['stacking'] ) ? (string) $data['stacking'] : self::STACK_NONE;
		$this->eligible_products   = $this->ints( isset( $data['eligible_products'] ) ? $data['eligible_products'] : array() );
		$this->eligible_categories = $this->ints( isset( $data['eligible_categories'] ) ? $data['eligible_categories'] : array() );
		$this->excluded_products   = $this->ints( isset( $data['excluded_products'] ) ? $data['excluded_products'] : array() );
		$this->shipping_zone_ids   = $this->ints( isset( $data['shipping_zone_ids'] ) ? $data['shipping_zone_ids'] : array() );
		$this->shipping_method_ids = $this->strings( isset( $data['shipping_method_ids'] ) ? $data['shipping_method_ids'] : array() );
		$this->gift_product_id     = isset( $data['gift_product_id'] ) ? (int) $data['gift_product_id'] : 0;
		$this->gift_add_mode       = isset( $data['gift_add_mode'] ) ? (string) $data['gift_add_mode'] : self::GIFT_AUTOMATIC;
		$this->coupon_code         = isset( $data['coupon_code'] ) ? (string) $data['coupon_code'] : '';
		$this->coupon_generate     = ! empty( $data['coupon_generate'] );
		$this->coupon_discount_type = isset( $data['coupon_discount_type'] ) ? (string) $data['coupon_discount_type'] : self::COUPON_PERCENT;
	}

	/**
	 * Build the reward configured on a goal.
	 *
	 * @param Goal $goal Goal to read the reward from.
	 * @return Reward
	 */
	public static function from_goal( Goal $goal ) {
		$meta = $goal->reward_meta();

		return new self(
			array(
				'type'                => $goal->reward_type(),
				'value'               => $goal->reward_value(),
				'max_value'           => $goal->reward_max_value(),
				'label'               => isset( $meta['label'] ) ? (string) $meta['label'] : $goal->name(),
				'stacking'            => isset( $meta['stacking'] ) ? (string) $meta['stacking'] : self::STACK_NONE,
				'eligible_products'   => isset( $meta['eligible_products'] ) ? $meta['eligible_products'] : array(),
				'eligible_categories' => isset( $meta['eligible_categories'] ) ? $meta['eligible_categories'] : array(),
				'excluded_products'   => isset( $meta['excluded_products'] ) ? $meta['excluded_products'] : array(),
				'shipping_zone_ids'   => isset( $meta['shipping_zone_ids'] ) ? $meta['shipping_zone_ids'] : array(),
				'shipping_method_ids' => isset( $meta['shipping_method_ids'] ) ? $meta['shipping_method_ids'] : array(),
				'gift_product_id'     => isset( $meta['gift_product_id'] ) ? (int) $meta['gift_product_id'] : 0,
				'gift_add_mode'       => isset( $meta['gift_add_mode'] ) ? (string) $meta['gift_add_mode'] : self::GIFT_AUTOMATIC,
				'coupon_code'         => isset( $meta['coupon_code'] ) ? (string) $meta['coupon_code'] : '',
				'coupon_generate'     => ! empty( $meta['coupon_generate'] ),
				'coupon_discount_type' => isset( $meta['coupon_discount_type'] ) ? (string) $meta['coupon_discount_type'] : self::COUPON_PERCENT,
			)
		);
	}

	/**
	 * Cast a mixed value to a list of positive ints.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	protected function ints( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', $value ), function ( $id ) {
			return $id > 0;
		} ) );
	}

	/**
	 * Cast a mixed value to a list of non-empty strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	protected function strings( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $value ), function ( $v ) {
			return '' !== $v;
		} ) );
	}

	/**
	 * Whether a reward is actually configured (type present).
	 *
	 * @return bool
	 */
	public function has_config() {
		return null !== $this->type && '' !== $this->type;
	}

	/**
	 * @return string|null
	 */
	public function type() {
		return $this->type;
	}

	/**
	 * @return float|null
	 */
	public function value() {
		return $this->value;
	}

	/**
	 * @return float|null
	 */
	public function max_value() {
		return $this->max_value;
	}

	/**
	 * @return string
	 */
	public function label() {
		return $this->label;
	}

	/**
	 * @return string
	 */
	public function stacking() {
		return $this->stacking;
	}

	/**
	 * @return bool
	 */
	public function stacking_is_stack() {
		return self::STACK_STACK === $this->stacking;
	}

	/**
	 * @return int[]
	 */
	public function eligible_products() {
		return $this->eligible_products;
	}

	/**
	 * @return int[]
	 */
	public function eligible_categories() {
		return $this->eligible_categories;
	}

	/**
	 * @return int[]
	 */
	public function excluded_products() {
		return $this->excluded_products;
	}

	/**
	 * @return int[]
	 */
	public function shipping_zone_ids() {
		return $this->shipping_zone_ids;
	}

	/**
	 * @return string[]
	 */
	public function shipping_method_ids() {
		return $this->shipping_method_ids;
	}

	/**
	 * @return int
	 */
	public function gift_product_id() {
		return $this->gift_product_id;
	}

	/**
	 * @return string
	 */
	public function gift_add_mode() {
		return $this->gift_add_mode;
	}

	/**
	 * @return bool
	 */
	public function is_gift_automatic() {
		return self::GIFT_AUTOMATIC === $this->gift_add_mode;
	}

	/**
	 * @return string
	 */
	public function coupon_code() {
		return $this->coupon_code;
	}

	/**
	 * @return bool
	 */
	public function coupon_generate() {
		return $this->coupon_generate;
	}

	/**
	 * @return string
	 */
	public function coupon_discount_type() {
		return $this->coupon_discount_type;
	}

	/**
	 * Serializable array form (used by the REST/JS layer in later phases).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'type'                 => $this->type,
			'value'                => $this->value,
			'max_value'            => $this->max_value,
			'label'                => $this->label,
			'stacking'             => $this->stacking,
			'eligible_products'    => $this->eligible_products,
			'eligible_categories'  => $this->eligible_categories,
			'excluded_products'    => $this->excluded_products,
			'shipping_zone_ids'    => $this->shipping_zone_ids,
			'shipping_method_ids'  => $this->shipping_method_ids,
			'gift_product_id'      => $this->gift_product_id,
			'gift_add_mode'        => $this->gift_add_mode,
			'coupon_code'          => $this->coupon_code,
			'coupon_generate'      => $this->coupon_generate,
			'coupon_discount_type' => $this->coupon_discount_type,
		);
	}
}
