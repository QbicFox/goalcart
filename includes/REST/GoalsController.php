<?php
/**
 * REST controller for goal management.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalRepository;
use GoalCart\Hooks\HookManager;
use GoalCart\Rewards\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Class GoalsController
 *	 * Phase 7 (REST API / AJAX Layer) admin endpoints for goals:
	 *
	 *  - `GET    /goalcart/v1/goals`                 — paginated list (status
	 *    filter + name search)
	 *  - `GET    /goalcart/v1/goals/{id}`            — goal details
	 *  - `POST   /goalcart/v1/goals`                 — create a goal
	 *  - `PUT    /goalcart/v1/goals/{id}`            — update a goal
	 *  - `DELETE /goalcart/v1/goals/{id}`            — delete a goal
	 *  - `POST   /goalcart/v1/goals/{id}/duplicate`  — duplicate a goal
	 *
	 * Every route is admin-only (manage_options, P07-T04) and every input is
 * validated/sanitized through the REST arg schemas before the repository
 * persists it. The payload shape mirrors the Goal model 1:1 (flat reward
 * columns, condition keys, JSON passthroughs).
 */
class GoalsController extends BaseController {

	/**
	 * Goal repository instance.
	 *
	 * @var GoalRepository
	 */
	protected $goals;

	/**
	 * Constructor.
	 *
	 * @param GoalRepository $goals Goal repository.
	 */
	public function __construct( GoalRepository $goals ) {
		$this->goals = $goals;
	}

	/**
	 * Register REST hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$hooks->add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the goal routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/goals',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_index' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->index_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/goals',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_create' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->save_args( true ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/goals/(?P<id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->id_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/goals/(?P<id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'handle_update' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->update_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/goals/(?P<id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->id_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/goals/(?P<id>[\d]+)/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_duplicate' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->id_args(),
			)
		);
	}

	/**
	 * List goals (paginated).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_index( $request ) {
		// Defaults are applied here (not only by the REST server) so direct
		// handler calls behave like dispatched requests.
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );

		$result = $this->goals->all(
			array(
				'page'     => $page,
				'per_page' => $per_page,
				'status'   => (string) $request->get_param( 'status' ),
				'search'   => (string) $request->get_param( 'search' ),
			)
		);

		$items = array();

		foreach ( $result['items'] as $row ) {
			$items[] = $this->shape( $row );
		}

		return $this->paginated( $items, $result['total'], $page, $per_page );
	}

	/**
	 * Get a single goal.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get( $request ) {
		$row = $this->goals->get( (int) $request->get_param( 'id' ) );

		if ( null === $row ) {
			return $this->error(
				'goalcart_goal_not_found',
				__( 'The goal could not be found.', 'goalcart' ),
				404
			);
		}

		return $this->success( $this->shape( $row ) );
	}

	/**
	 * Create a goal.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_create( $request ) {
		$goal_id = $this->goals->create( $request->get_params() );

		if ( ! $goal_id ) {
			return $this->error(
				'goalcart_goal_create_failed',
				__( 'The goal could not be created.', 'goalcart' ),
				500
			);
		}

		return $this->success( $this->shape( $this->goals->get( $goal_id ) ) );
	}

	/**
	 * Update a goal (partial update — only provided keys are written).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_update( $request ) {
		$goal_id = (int) $request->get_param( 'id' );
		$row     = $this->goals->get( $goal_id );

		if ( null === $row ) {
			return $this->error(
				'goalcart_goal_not_found',
				__( 'The goal could not be found.', 'goalcart' ),
				404
			);
		}

		if ( ! $this->goals->update( $goal_id, $request->get_params() ) ) {
			return $this->error(
				'goalcart_goal_update_failed',
				__( 'The goal could not be updated.', 'goalcart' ),
				500
			);
		}

		return $this->success( $this->shape( $this->goals->get( $goal_id ) ) );
	}

	/**
	 * Delete a goal.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_delete( $request ) {
		$goal_id = (int) $request->get_param( 'id' );

		if ( null === $this->goals->get( $goal_id ) ) {
			return $this->error(
				'goalcart_goal_not_found',
				__( 'The goal could not be found.', 'goalcart' ),
				404
			);
		}

		if ( ! $this->goals->delete( $goal_id ) ) {
			return $this->error(
				'goalcart_goal_delete_failed',
				__( 'The goal could not be deleted.', 'goalcart' ),
				500
			);
		}

		return $this->success(
			array(
				'deleted' => true,
				'id'      => $goal_id,
			)
		);
	}

	/**
	 * Duplicate a goal.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_duplicate( $request ) {
		$goal_id = (int) $request->get_param( 'id' );

		if ( null === $this->goals->get( $goal_id ) ) {
			return $this->error(
				'goalcart_goal_not_found',
				__( 'The goal could not be found.', 'goalcart' ),
				404
			);
		}

		$copy_id = $this->goals->duplicate( $goal_id );

		if ( ! $copy_id ) {
			return $this->error(
				'goalcart_goal_duplicate_failed',
				__( 'The goal could not be duplicated.', 'goalcart' ),
				500
			);
		}

		return $this->success( $this->shape( $this->goals->get( $copy_id ) ) );
	}

	/**
	 * Arg schema for the list route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function index_args() {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			),
			'status'   => array(
				'type'    => 'string',
				'default' => '',
				'enum'    => array( '', Goal::STATUS_ACTIVE, Goal::STATUS_INACTIVE ),
			),
			'search'   => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * Arg schema for id-parameterized routes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function id_args() {
		return array(
			'id' => array(
				'type'              => 'integer',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
		);
	}

	/**
	 * Arg schema for the create route (name + type required).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function save_args( $required = false ) {
		$args = array(
			'name'              => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description'       => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'status'            => array(
				'type'    => 'string',
				'default' => Goal::STATUS_ACTIVE,
				'enum'    => array( Goal::STATUS_ACTIVE, Goal::STATUS_INACTIVE ),
			),
			'type'              => array(
				'type'    => 'string',
				'default' => Goal::TYPE_AMOUNT,
				'enum'    => array(
					Goal::TYPE_AMOUNT,
					Goal::TYPE_QUANTITY,
					Goal::TYPE_DISTINCT_QUANTITY,
					Goal::TYPE_CATEGORY,
					Goal::TYPE_PRODUCT,
					Goal::TYPE_WEIGHT,
					Goal::TYPE_COMPOSITE,
				),
			),
			'target'            => array(
				'type'    => 'number',
				'default' => 0,
				'minimum' => 0,
			),
			'calculation_mode'  => array(
				'type'    => 'string',
				'default' => Goal::MODE_SUBTOTAL,
				'enum'    => array(
					Goal::MODE_SUBTOTAL,
					Goal::MODE_TOTAL,
					Goal::MODE_DISCOUNTED_SUBTOTAL,
					Goal::MODE_QUANTITY,
				),
			),
			'categories'        => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'products'          => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'excluded_products' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'operator'          => array(
				'type'    => 'string',
				'default' => Goal::OP_AND,
				'enum'    => array( Goal::OP_AND, Goal::OP_OR ),
			),
			'children'          => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
			'priority'          => array(
				'type'    => 'integer',
				'default' => 10,
				'minimum' => 0,
			),
			'campaign_id'       => array(
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'validate_callback' => array( $this, 'validate_campaign' ),
			),
			'reward_type'       => array(
				'type'    => array( 'string', 'null' ),
				'default' => null,
				'enum'    => array_merge(
					array( null ),
					array(
						Reward::TYPE_FREE_SHIPPING,
						Reward::TYPE_PERCENT_DISCOUNT,
						Reward::TYPE_FIXED_DISCOUNT,
						Reward::TYPE_FREE_GIFT,
						Reward::TYPE_COUPON,
					)
				),
			),
			'reward_value'      => array(
				'type'    => array( 'number', 'null' ),
				'default' => null,
			),
			'reward_max_value'  => array(
				'type'    => array( 'number', 'null' ),
				'default' => null,
			),
			'reward_meta'       => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => true,
			),
			'starts_at'         => array(
				'type'              => array( 'string', 'null' ),
				'default'           => null,
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
			'ends_at'           => array(
				'type'              => array( 'string', 'null' ),
				'default'           => null,
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
			'display_settings'  => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => true,
			),
			'limits'            => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => true,
			),
		);

		if ( $required ) {
			$args['name']['required'] = true;
			$args['type']['required'] = true;
		}

		return $args;
	}

	/**
	 * Arg schema for the update route (same fields, nothing required).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function update_args() {
		$args = $this->save_args( false );
		$args['id'] = $this->id_args()['id'];

		return $args;
	}

	/**
	 * Validate a campaign_id: 0 (none) or an existing campaign.
	 *
	 * @param mixed $value Value to validate.
	 * @return bool
	 */
	public function validate_campaign( $value ) {
		$campaign_id = (int) $value;

		if ( $campaign_id <= 0 ) {
			return true;
		}

		global $wpdb;

		$table = \GoalCart\Database\Schema::table( 'campaigns' );

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $campaign_id )
		);

		return (int) $exists > 0;
	}

	/**
	 * Normalize a repository row into the REST payload shape.
	 *
	 * @param array<string, mixed> $row Normalized goal row.
	 * @return array<string, mixed>
	 */
	protected function shape( array $row ) {
		return array(
			'id'                => (int) $row['id'],
			'name'              => (string) $row['name'],
			'description'       => isset( $row['description'] ) ? (string) $row['description'] : '',
			'status'            => (string) $row['status'],
			'type'              => (string) $row['type'],
			'target'            => (float) $row['target'],
			'calculation_mode'  => (string) $row['calculation_mode'],
			'categories'        => $this->ints( isset( $row['categories'] ) ? $row['categories'] : array() ),
			'products'          => $this->ints( isset( $row['products'] ) ? $row['products'] : array() ),
			'excluded_products' => $this->ints( isset( $row['excluded_products'] ) ? $row['excluded_products'] : array() ),
			'operator'          => isset( $row['operator'] ) ? (string) $row['operator'] : Goal::OP_AND,
			'children'          => isset( $row['children'] ) && is_array( $row['children'] ) ? $row['children'] : array(),
			'reward_type'       => ! empty( $row['reward_type'] ) ? (string) $row['reward_type'] : null,
			'reward_value'      => null !== $row['reward_value'] && '' !== $row['reward_value'] ? (float) $row['reward_value'] : null,
			'reward_max_value'  => null !== $row['reward_max_value'] && '' !== $row['reward_max_value'] ? (float) $row['reward_max_value'] : null,
			'reward_meta'       => isset( $row['reward_meta'] ) && is_array( $row['reward_meta'] ) ? $row['reward_meta'] : array(),
			'priority'          => (int) $row['priority'],
			'campaign_id'       => ! empty( $row['campaign_id'] ) ? (int) $row['campaign_id'] : null,
			'menu_order'        => (int) $row['menu_order'],
			'starts_at'         => ! empty( $row['starts_at'] ) ? (string) $row['starts_at'] : null,
			'ends_at'           => ! empty( $row['ends_at'] ) ? (string) $row['ends_at'] : null,
			'display_settings'  => isset( $row['display_settings'] ) && is_array( $row['display_settings'] ) ? $row['display_settings'] : array(),
			'limits'            => isset( $row['limits'] ) && is_array( $row['limits'] ) ? $row['limits'] : array(),
			'created_at'        => (string) $row['created_at'],
			'updated_at'        => (string) $row['updated_at'],
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
}
