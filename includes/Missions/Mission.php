<?php
/**
 * Mission value object for the FaraCart engine.
 *
 * @package FaraCart
 */

namespace FaraCart\Missions;

defined( 'ABSPATH' ) || exit;

/**
 * Class Mission
 *
 * An immutable, UI-independent representation of a mission. Instances are
 * built from a database row (see Database\Schema) or from a config array
 * (composite children, tests, REST payloads), so the engine never touches
 * the database itself — persistence is a separate concern.
 *
 * Phase 4 (Mission Engine) defines seven mission types; the three MVP types
 * (amount, quantity, category) plus distinct-quantity, product, weight and
 * composite are all first-class so the engine is complete up front.
 */
final class Mission {

	/**
	 * Mission types.
	 *
	 * Phase 32 (Advanced V2): tag and attribute missions extend the
	 * category/product family — the amount or quantity restricted to
	 * products carrying the configured tags / attribute taxonomies.
	 */
	const TYPE_AMOUNT            = 'amount';
	const TYPE_QUANTITY          = 'quantity';
	const TYPE_DISTINCT_QUANTITY = 'distinct_quantity';
	const TYPE_CATEGORY          = 'category';
	const TYPE_PRODUCT           = 'product';
	const TYPE_WEIGHT            = 'weight';
	const TYPE_COMPOSITE         = 'composite';
	const TYPE_TAG               = 'tag';
	const TYPE_ATTRIBUTE         = 'attribute';

	/**
	 * Calculation bases for amount-style missions.
	 */
	const MODE_SUBTOTAL            = 'subtotal';
	const MODE_TOTAL               = 'total';
	const MODE_DISCOUNTED_SUBTOTAL = 'discounted_subtotal';
	const MODE_QUANTITY            = 'quantity';

	/**
	 * Composite operators.
	 */
	const OP_AND = 'and';
	const OP_OR  = 'or';

	/**
	 * Mission statuses.
	 */
	const STATUS_ACTIVE   = 'active';
	const STATUS_INACTIVE = 'inactive';

	/**
	 * Mission identifier (0 for anonymous/embedded missions).
	 *
	 * @var int
	 */
	protected $id;

	/**
	 * Internal name.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * active|inactive.
	 *
	 * @var string
	 */
	protected $status;

	/**
	 * One of the TYPE_* constants.
	 *
	 * @var string
	 */
	protected $type;

	/**
	 * The threshold.
	 *
	 * @var float
	 */
	protected $target;

	/**
	 * Calculation basis (subtotal|total|discounted_subtotal|quantity).
	 *
	 * @var string
	 */
	protected $calculation_mode;

	/**
	 * Category term IDs for category missions.
	 *
	 * @var int[]
	 */
	protected $categories;

	/**
	 * Product/variation IDs for product missions.
	 *
	 * @var int[]
	 */
	protected $products;

	/**
	 * Product IDs excluded from every computation.
	 *
	 * @var int[]
	 */
	protected $excluded_products;

	/**
	 * and|or for composite missions.
	 *
	 * @var string
	 */
	protected $operator;

	/**
	 * Child mission configs for composite missions.
	 *
	 * @var array[] Each entry is a Mission::from_array() payload.
	 */
	protected $children;

	/**
	 * Product tag term IDs for tag missions (Phase 32).
	 *
	 * @var int[]
	 */
	protected $tags;

	/**
	 * Global attribute taxonomy slugs for attribute missions (Phase 32),
	 * e.g. array( 'pa_color' ) or array( 'pa_brand' ).
	 *
	 * @var string[]
	 */
	protected $attributes;

	/**
	 * Customer roles allowed to see/complete the mission (Phase 32). Empty =
	 * everyone.
	 *
	 * @var string[]
	 */
	protected $customer_roles;

	/**
	 * Required customer state (Phase 32): subset of 'guest' | 'logged_in'.
	 * Empty = everyone.
	 *
	 * @var string[]
	 */
	protected $customer_state;

	/**
	 * First-order-only mission (Phase 32): applies only to shoppers with zero
	 * completed orders.
	 *
	 * @var bool
	 */
	protected $first_order;

	/**
	 * VIP-only mission (Phase 32): applies only to logged-in customers meeting
	 * the spend/order thresholds.
	 *
	 * @var bool
	 */
	protected $vip;

	/**
	 * Minimum lifetime spend for VIP missions.
	 *
	 * @var float
	 */
	protected $vip_min_spend;

	/**
	 * Minimum completed-order count for VIP missions.
	 *
	 * @var int
	 */
	protected $vip_min_orders;

	/**
	 * Shipping zone ids the mission applies to (Phase 32). Empty = every zone.
	 *
	 * @var int[]
	 */
	protected $shipping_zones;

	/**
	 * Coupon codes the cart must have applied for the mission to apply
	 * (Phase 32 cart-state condition). Empty = no coupon requirement.
	 *
	 * @var string[]
	 */
	protected $cart_coupons;

	/**
	 * Minimum cart item count for the mission to apply (Phase 32 cart-state
	 * condition). 0 = no minimum.
	 *
	 * @var int
	 */
	protected $cart_min_items;

	/**
	 * Recurring schedule days (Phase 32, advanced scheduling): 1 (Mon) to
	 * 7 (Sun), matching PHP date('N'). Empty = every day.
	 *
	 * @var int[]
	 */
	protected $schedule_days;

	/**
	 * Recurring schedule day window start 'H:i' (Phase 32). Empty = no
	 * time window.
	 *
	 * @var string
	 */
	protected $schedule_start_time;

	/**
	 * Recurring schedule day window end 'H:i' (Phase 32).
	 *
	 * @var string
	 */
	protected $schedule_end_time;

	/**
	 * Schedule window (site timezone, 'Y-m-d H:i:s' or 'Y-m-d'). Null = open.
	 *
	 * @var string|null
	 */
	protected $starts_at;

	/**
	 * @var string|null
	 */
	protected $ends_at;

	/**
	 * Priority used for conflict resolution (Phase 26).
	 *
	 * @var int
	 */
	protected $priority;

	/**
	 * Whether the mission is mutually exclusive (Phase 26).
	 *
	 * A completed exclusive mission suppresses every lower-priority mission's
	 * reward; priority above it is still respected.
	 *
	 * @var bool
	 */
	protected $exclusive;

	/**
	 * Maximum completion cycles per user (Phase 36). Null = unlimited.
	 *
	 * @var int|null
	 */
	protected $max_completions_per_user;

	/**
	 * Reward type (Phase 5): free_shipping|percent_discount|fixed_discount.
	 *
	 * @var string|null
	 */
	protected $reward_type;

	/**
	 * Reward value (percentage or fixed amount).
	 *
	 * @var float|null
	 */
	protected $reward_value;

	/**
	 * Reward cap for percentage discounts.
	 *
	 * @var float|null
	 */
	protected $reward_max_value;

	/**
	 * Extended reward configuration (Phase 5): eligible products/categories,
	 * excluded products, stacking rules, shipping zone/method filters, gift
	 * product, coupon settings. Stored as JSON in `reward_meta`; parsed to
	 * an array here so the Rewards layer can read it without re-decoding.
	 *
	 * @var array<string, mixed>
	 */
	protected $reward_meta;

	/**
	 * Id of the campaign the mission belongs to (0 when standalone).
	 *
	 * @var int
	 */
	protected $campaign_id;

	/**
	 * Name of the campaign the mission belongs to (empty when standalone).
	 *
	 * Folded in by the repository's campaign join (like campaign status and
	 * schedule) so display layers can reference {campaign_name} without an
	 * extra query.
	 *
	 * @var string
	 */
	protected $campaign_name;

	/**
	 * The campaign's display_rules (empty when standalone).
	 *
	 * Folded in by the repository's campaign join so the template engine
	 * can resolve the campaign-scoped template (e.g. the milestone chain)
	 * from the mission payload without a second query.
	 *
	 * @var array<string, mixed>
	 */
	protected $campaign_display_rules;

	/**
	 * Display configuration (Phase 9 builder): title, message,
	 * completed_message, icon, template. Stored as JSON in
	 * `display_settings`; parsed to an array here so the frontend can read
	 * the card icon without re-decoding.
	 *
	 * @var array<string, mixed>
	 */
	protected $display_settings;

	/**
	 * Build a mission from a config array / database row.
	 *
	 * Accepts a superset of the Schema columns plus composite-only keys:
	 * 'operator', 'children', 'categories', 'products', 'excluded_products'.
	 *
	 * @param array<string, mixed> $data Mission data.
	 */
	public function __construct( array $data = array() ) {
		$this->id                = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->name              = isset( $data['name'] ) ? (string) $data['name'] : '';
		$this->status            = isset( $data['status'] ) ? (string) $data['status'] : self::STATUS_ACTIVE;
		$this->type              = isset( $data['type'] ) ? (string) $data['type'] : self::TYPE_AMOUNT;
		$this->target            = isset( $data['target'] ) ? (float) $data['target'] : 0.0;
		$this->calculation_mode  = isset( $data['calculation_mode'] ) ? (string) $data['calculation_mode'] : self::default_calculation_mode( $this->type );
		$this->categories        = $this->ints( isset( $data['categories'] ) ? $data['categories'] : array() );
		$this->products          = $this->ints( isset( $data['products'] ) ? $data['products'] : array() );
		$this->excluded_products = $this->ints( isset( $data['excluded_products'] ) ? $data['excluded_products'] : array() );
		$this->operator          = isset( $data['operator'] ) ? (string) $data['operator'] : self::OP_AND;
		$this->children          = isset( $data['children'] ) && is_array( $data['children'] ) ? $data['children'] : array();
		$this->tags              = $this->ints( isset( $data['tags'] ) ? $data['tags'] : array() );
		$this->attributes        = $this->strings( isset( $data['attributes'] ) ? $data['attributes'] : array() );
		$this->customer_roles    = $this->strings( isset( $data['customer_roles'] ) ? $data['customer_roles'] : array() );
		$this->customer_state    = $this->strings( isset( $data['customer_state'] ) ? $data['customer_state'] : array() );
		$this->first_order       = ! empty( $data['first_order'] );
		$this->vip               = ! empty( $data['vip'] );
		$this->vip_min_spend     = isset( $data['vip_min_spend'] ) ? (float) $data['vip_min_spend'] : 0.0;
		$this->vip_min_orders    = isset( $data['vip_min_orders'] ) ? (int) $data['vip_min_orders'] : 0;
		$this->shipping_zones    = $this->ints( isset( $data['shipping_zones'] ) ? $data['shipping_zones'] : array() );
		$this->cart_coupons      = $this->strings( isset( $data['cart_coupons'] ) ? $data['cart_coupons'] : array() );
		$this->cart_min_items    = isset( $data['cart_min_items'] ) ? (int) $data['cart_min_items'] : 0;
		$this->schedule_days     = $this->ints( isset( $data['schedule_days'] ) ? $data['schedule_days'] : array() );
		$this->schedule_start_time = isset( $data['schedule_start_time'] ) ? (string) $data['schedule_start_time'] : '';
		$this->schedule_end_time   = isset( $data['schedule_end_time'] ) ? (string) $data['schedule_end_time'] : '';
		$this->starts_at         = isset( $data['starts_at'] ) ? (string) $data['starts_at'] : null;
		$this->ends_at           = isset( $data['ends_at'] ) ? (string) $data['ends_at'] : null;
		$this->priority          = isset( $data['priority'] ) ? (int) $data['priority'] : 10;
		$this->exclusive         = ! empty( $data['exclusive'] );
		$this->max_completions_per_user = $this->limit_int( isset( $data['max_completions_per_user'] ) ? $data['max_completions_per_user'] : null );
		$this->reward_type       = isset( $data['reward_type'] ) ? (string) $data['reward_type'] : null;
		$this->reward_value      = isset( $data['reward_value'] ) ? (float) $data['reward_value'] : null;
		$this->reward_max_value  = isset( $data['reward_max_value'] ) ? (float) $data['reward_max_value'] : null;
		$this->reward_meta       = $this->meta( isset( $data['reward_meta'] ) ? $data['reward_meta'] : array() );
		$this->display_settings = $this->meta( isset( $data['display_settings'] ) ? $data['display_settings'] : array() );
		$this->campaign_id      = isset( $data['campaign_id'] ) ? (int) $data['campaign_id'] : 0;
		$this->campaign_name    = isset( $data['campaign_name'] ) ? (string) $data['campaign_name'] : '';
		$this->campaign_display_rules = $this->meta( isset( $data['campaign_display_rules'] ) ? $data['campaign_display_rules'] : array() );
	}

	/**
	 * Parse a reward_meta value (JSON string or array) into an array.
	 *
	 * @param mixed $value Raw reward_meta.
	 * @return array<string, mixed>
	 */
	protected function meta( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );

			return is_array( $decoded ) ? $decoded : array();
		}

		return array();
	}

	/**
	 * Type-aware default calculation basis.
	 *
	 * Product missions measure "how many / how much of these products", so
	 * quantity is the natural default; every other type measures money and
	 * defaults to the pre-discount subtotal. Explicit configuration always
	 * wins.
	 *
	 * Phase 18 (Settings → Mission Calculation): the store-wide default money
	 * basis is applied through the faracart_default_calculation_mode
	 * filter (registered by Settings), so a mission without its own mode
	 * follows the store default while quantity-style types stay unchanged.
	 *
	 * @param string $type Mission type.
	 * @return string
	 */
	public static function default_calculation_mode( $type ) {
		$mode = self::TYPE_PRODUCT === $type ? self::MODE_QUANTITY : self::MODE_SUBTOTAL;

		return (string) apply_filters( 'faracart_default_calculation_mode', $mode, (string) $type );
	}

	/**
	 * Cast a mixed value to a list of positive ints (ids).
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
	 * Cast a completion-limit value: positive int or null (unlimited).
	 *
	 * Empty strings, zero and negative values all normalize to null — the
	 * unlimited default — so a stored '' (dbDelta on some installs) or an
	 * explicit 0 can never accidentally lock a mission to zero completions.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	protected function limit_int( $value ) {
		if ( null === $value || '' === $value || false === $value ) {
			return null;
		}

		$limit = (int) $value;

		return $limit > 0 ? $limit : null;
	}

	/**
	 * Cast a mixed value to a list of non-empty sanitized strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	protected function strings( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = function ( $v ) {
			return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $v ) : trim( (string) $v );
		};

		return array_values( array_filter( array_map( $clean, array_map( 'strval', $value ) ), function ( $v ) {
			return '' !== $v;
		} ) );
	}

	/**
	 * @return int
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * @return string
	 */
	public function name() {
		return $this->name;
	}

	/**
	 * @return string
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * @return bool
	 */
	public function is_active() {
		return self::STATUS_ACTIVE === $this->status;
	}

	/**
	 * @return string
	 */
	public function type() {
		return $this->type;
	}

	/**
	 * @return float
	 */
	public function target() {
		return $this->target;
	}

	/**
	 * @return string
	 */
	public function calculation_mode() {
		return $this->calculation_mode;
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
	public function products() {
		return $this->products;
	}

	/**
	 * @return int[]
	 */
	public function excluded_products() {
		return $this->excluded_products;
	}

	/**
	 * Product tag term IDs (tag missions / tag conditions).
	 *
	 * @return int[]
	 */
	public function tags() {
		return $this->tags;
	}

	/**
	 * Global attribute taxonomy slugs (attribute missions).
	 *
	 * @return string[]
	 */
	public function attributes() {
		return $this->attributes;
	}

	/**
	 * Customer roles allowed to complete the mission (empty = everyone).
	 *
	 * @return string[]
	 */
	public function customer_roles() {
		return $this->customer_roles;
	}

	/**
	 * Required customer state: subset of 'guest' | 'logged_in' (empty =
	 * everyone).
	 *
	 * @return string[]
	 */
	public function customer_state() {
		return $this->customer_state;
	}

	/**
	 * Whether the mission applies to first orders only.
	 *
	 * @return bool
	 */
	public function is_first_order() {
		return $this->first_order;
	}

	/**
	 * Whether the mission is restricted to VIP customers.
	 *
	 * @return bool
	 */
	public function is_vip() {
		return $this->vip;
	}

	/**
	 * @return float
	 */
	public function vip_min_spend() {
		return $this->vip_min_spend;
	}

	/**
	 * @return int
	 */
	public function vip_min_orders() {
		return $this->vip_min_orders;
	}

	/**
	 * Shipping zone ids the mission applies to (empty = every zone).
	 *
	 * @return int[]
	 */
	public function shipping_zones() {
		return $this->shipping_zones;
	}

	/**
	 * Coupon codes the cart must carry (empty = no coupon requirement).
	 *
	 * @return string[]
	 */
	public function cart_coupons() {
		return $this->cart_coupons;
	}

	/**
	 * Minimum cart item count (0 = no minimum).
	 *
	 * @return int
	 */
	public function cart_min_items() {
		return $this->cart_min_items;
	}

	/**
	 * Recurring schedule days (1=Mon .. 7=Sun, empty = every day).
	 *
	 * @return int[]
	 */
	public function schedule_days() {
		return $this->schedule_days;
	}

	/**
	 * @return string
	 */
	public function schedule_start_time() {
		return $this->schedule_start_time;
	}

	/**
	 * @return string
	 */
	public function schedule_end_time() {
		return $this->schedule_end_time;
	}

	/**
	 * Whether any recurring schedule rule (days / time window) is set.
	 *
	 * @return bool
	 */
	public function has_schedule_rules() {
		return ! empty( $this->schedule_days )
			|| '' !== $this->schedule_start_time
			|| '' !== $this->schedule_end_time;
	}

	/**
	 * @return string
	 */
	public function operator() {
		return $this->operator;
	}

	/**
	 * @return bool
	 */
	public function is_operator_or() {
		return self::OP_OR === $this->operator;
	}

	/**
	 * Child mission configs (composite missions only).
	 *
	 * @return array[]
	 */
	public function children() {
		return $this->children;
	}

	/**
	 * @return string|null
	 */
	public function starts_at() {
		return $this->starts_at;
	}

	/**
	 * @return string|null
	 */
	public function ends_at() {
		return $this->ends_at;
	}

	/**
	 * @return int
	 */
	public function priority() {
		return $this->priority;
	}

	/**
	 * Whether the mission is mutually exclusive (Phase 26).
	 *
	 * @return bool
	 */
	public function is_exclusive() {
		return $this->exclusive;
	}

	/**
	 * Maximum completion cycles per user (Phase 36). Null = unlimited.
	 *
	 * @return int|null
	 */
	public function max_completions_per_user() {
		return $this->max_completions_per_user;
	}

	/**
	 * @return string|null
	 */
	public function reward_type() {
		return $this->reward_type;
	}

	/**
	 * @return float|null
	 */
	public function reward_value() {
		return $this->reward_value;
	}

	/**
	 * @return float|null
	 */
	public function reward_max_value() {
		return $this->reward_max_value;
	}

	/**
	 * Extended reward configuration array (Phase 5).
	 *
	 * @return array<string, mixed>
	 */
	public function reward_meta() {
		return $this->reward_meta;
	}

	/**
	 * Display configuration array (title, message, icon, template).
	 *
	 * @return array<string, mixed>
	 */
	public function display_settings() {
		return $this->display_settings;
	}

	/**
	 * Id of the campaign this mission belongs to (0 when standalone).
	 *
	 * @return int
	 */
	public function campaign_id() {
		return $this->campaign_id;
	}

	/**
	 * Name of the campaign this mission belongs to ('' when standalone).
	 *
	 * @return string
	 */
	public function campaign_name() {
		return $this->campaign_name;
	}

	/**
	 * The campaign's display_rules (empty when standalone).
	 *
	 * @return array<string, mixed>
	 */
	public function campaign_display_rules() {
		return $this->campaign_display_rules;
	}

	/**
	 * Whether this mission's progress is measured in money.
	 *
	 * Quantity/distinct-quantity/weight missions count items, not money, and
	 * quantity-mode category/product missions do too. Quantity missions default
	 * to the subtotal calculation mode (default_calculation_mode), so the
	 * type is checked in addition to the mode. Single source of truth for
	 * the display layers (message engine, frontend payload, suggestion
	 * engine) so money-vs-plain formatting never drifts.
	 *
	 * @return bool
	 */
	public function is_money_mission() {
		if ( in_array(
			$this->type,
			array( self::TYPE_QUANTITY, self::TYPE_DISTINCT_QUANTITY, self::TYPE_WEIGHT ),
			true
		) ) {
			return false;
		}

		return self::MODE_QUANTITY !== $this->calculation_mode;
	}
}
