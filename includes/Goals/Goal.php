<?php
/**
 * Goal value object for the Goal Cart engine.
 *
 * @package GoalCart
 */

namespace GoalCart\Goals;

defined( 'ABSPATH' ) || exit;

/**
 * Class Goal
 *
 * An immutable, UI-independent representation of a goal. Instances are
 * built from a database row (see Database\Schema) or from a config array
 * (composite children, tests, REST payloads), so the engine never touches
 * the database itself — persistence is a separate concern.
 *
 * Phase 4 (Goal Engine) defines seven goal types; the three MVP types
 * (amount, quantity, category) plus distinct-quantity, product, weight and
 * composite are all first-class so the engine is complete up front.
 */
final class Goal {

	/**
	 * Goal types.
	 */
	const TYPE_AMOUNT            = 'amount';
	const TYPE_QUANTITY          = 'quantity';
	const TYPE_DISTINCT_QUANTITY = 'distinct_quantity';
	const TYPE_CATEGORY          = 'category';
	const TYPE_PRODUCT           = 'product';
	const TYPE_WEIGHT            = 'weight';
	const TYPE_COMPOSITE         = 'composite';

	/**
	 * Calculation bases for amount-style goals.
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
	 * Goal statuses.
	 */
	const STATUS_ACTIVE   = 'active';
	const STATUS_INACTIVE = 'inactive';

	/**
	 * Goal identifier (0 for anonymous/embedded goals).
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
	 * Category term IDs for category goals.
	 *
	 * @var int[]
	 */
	protected $categories;

	/**
	 * Product/variation IDs for product goals.
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
	 * and|or for composite goals.
	 *
	 * @var string
	 */
	protected $operator;

	/**
	 * Child goal configs for composite goals.
	 *
	 * @var array[] Each entry is a Goal::from_array() payload.
	 */
	protected $children;

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
	 * Name of the campaign the goal belongs to (empty when standalone).
	 *
	 * Folded in by the repository's campaign join (like campaign status and
	 * schedule) so display layers can reference {campaign_name} without an
	 * extra query.
	 *
	 * @var string
	 */
	protected $campaign_name;

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
	 * Build a goal from a config array / database row.
	 *
	 * Accepts a superset of the Schema columns plus composite-only keys:
	 * 'operator', 'children', 'categories', 'products', 'excluded_products'.
	 *
	 * @param array<string, mixed> $data Goal data.
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
		$this->starts_at         = isset( $data['starts_at'] ) ? (string) $data['starts_at'] : null;
		$this->ends_at           = isset( $data['ends_at'] ) ? (string) $data['ends_at'] : null;
		$this->priority          = isset( $data['priority'] ) ? (int) $data['priority'] : 10;
		$this->reward_type       = isset( $data['reward_type'] ) ? (string) $data['reward_type'] : null;
		$this->reward_value      = isset( $data['reward_value'] ) ? (float) $data['reward_value'] : null;
		$this->reward_max_value  = isset( $data['reward_max_value'] ) ? (float) $data['reward_max_value'] : null;
		$this->reward_meta       = $this->meta( isset( $data['reward_meta'] ) ? $data['reward_meta'] : array() );
		$this->display_settings = $this->meta( isset( $data['display_settings'] ) ? $data['display_settings'] : array() );
		$this->campaign_name    = isset( $data['campaign_name'] ) ? (string) $data['campaign_name'] : '';
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
	 * Product goals measure "how many / how much of these products", so
	 * quantity is the natural default; every other type measures money and
	 * defaults to the pre-discount subtotal. Explicit configuration always
	 * wins.
	 *
	 * @param string $type Goal type.
	 * @return string
	 */
	public static function default_calculation_mode( $type ) {
		return self::TYPE_PRODUCT === $type ? self::MODE_QUANTITY : self::MODE_SUBTOTAL;
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
	 * Child goal configs (composite goals only).
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
	 * Name of the campaign this goal belongs to ('' when standalone).
	 *
	 * @return string
	 */
	public function campaign_name() {
		return $this->campaign_name;
	}

	/**
	 * Whether this goal's progress is measured in money.
	 *
	 * Quantity/distinct-quantity/weight goals count items, not money, and
	 * quantity-mode category/product goals do too. Quantity goals default
	 * to the subtotal calculation mode (default_calculation_mode), so the
	 * type is checked in addition to the mode. Single source of truth for
	 * the display layers (message engine, frontend payload, suggestion
	 * engine) so money-vs-plain formatting never drifts.
	 *
	 * @return bool
	 */
	public function is_money_goal() {
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
