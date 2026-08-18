<?php
/**
 * REST controller for mission management.
 *
 * @package FaraCart
 */

namespace FaraCart\REST;

use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionRepository;
use FaraCart\Hooks\HookManager;
use FaraCart\Rewards\Reward;
use FaraCart\Templates\TemplateEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Class MissionsController
 *	 * Phase 7 (REST API / AJAX Layer) admin endpoints for missions:
	 *
	 *  - `GET    /faracart/v1/missions`                 — paginated list (status
	 *    filter + name search)
	 *  - `GET    /faracart/v1/missions/{id}`            — mission details
	 *  - `POST   /faracart/v1/missions`                 — create a mission
	 *  - `PUT    /faracart/v1/missions/{id}`            — update a mission
	 *  - `DELETE /faracart/v1/missions/{id}`            — delete a mission
	 *  - `POST   /faracart/v1/missions/{id}/duplicate`  — duplicate a mission
	 *
	 * Every route is admin-only (manage_options, P07-T04) and every input is
 * validated/sanitized through the REST arg schemas before the repository
 * persists it. The payload shape mirrors the Mission model 1:1 (flat reward
 * columns, condition keys, JSON passthroughs).
 */
class MissionsController extends BaseController {

	/**
	 * Mission repository instance.
	 *
	 * @var MissionRepository
	 */
	protected $missions;

	/**
	 * Template engine (pluggable templates): validates the mission's
	 * display_settings.template_id / template_settings on save. Null when
	 * not injected — resolved lazily from the plugin container.
	 *
	 * @var TemplateEngine|null
	 */
	protected $templates;

	/**
	 * Constructor.
	 *
	 * @param MissionRepository     $missions Mission repository.
	 * @param TemplateEngine|null $templates Template engine (optional).
	 */
	public function __construct( MissionRepository $missions, ?TemplateEngine $templates = null ) {
		$this->missions = $missions;
		$this->templates = $templates;
	}

	/**
	 * The template engine, resolved lazily when not injected.
	 *
	 * @return TemplateEngine
	 */
	protected function templates() {
		if ( null === $this->templates ) {
			$this->templates = \FaraCart\Plugin::instance()->container()->get( TemplateEngine::class );
		}

		return $this->templates;
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
	 * Register the mission routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		foreach ( $this->route_specs() as $spec ) {
			register_rest_route( self::NAMESPACE, $spec[0], $spec[1] );
		}
	}

	/**
	 * Route definitions: path => route args.
	 *
	 * @return array<int, array{0: string, 1: array<string, mixed>}>
	 */
	protected function route_specs() {
		return array(
			array(
				'/missions',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_index' ),
					'permission_callback' => $this->get_permission_callback(),
					'args'                => $this->index_args(),
				),
			),
			array(
				'/missions',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_create' ),
					'permission_callback' => $this->get_permission_callback(),
					'args'                => $this->save_args( true ),
				),
			),
			array(
				'/missions/(?P<id>[\d]+)',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => $this->get_permission_callback(),
					'args'                => $this->id_args(),
				),
			),
			array(
				'/missions/(?P<id>[\d]+)',
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'handle_update' ),
					'permission_callback' => $this->get_permission_callback(),
					'args'                => $this->update_args(),
				),
			),
			array(
				'/missions/(?P<id>[\d]+)',
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete' ),
					'permission_callback' => $this->get_permission_callback(),
					'args'                => $this->id_args(),
				),
			),
			array(
				'/missions/(?P<id>[\d]+)/duplicate',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_duplicate' ),
					'permission_callback' => $this->get_permission_callback(),
					'args'                => $this->id_args(),
				),
			),
		);
	}

	/**
	 * List missions (paginated).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_index( $request ) {
		// Defaults are applied here (not only by the REST server) so direct
		// handler calls behave like dispatched requests.
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );

		$result = $this->missions->all(
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
	 * Get a single mission.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get( $request ) {
		$row = $this->missions->get( (int) $request->get_param( 'id' ) );

		if ( null === $row ) {
			return $this->error(
				'faracart_mission_not_found',
				__( 'The mission could not be found.', 'faracart' ),
				404
			);
		}

		return $this->success( $this->shape( $row ) );
	}

	/**
	 * Create a mission.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_create( $request ) {
		$mission_id = $this->missions->create( $request->get_params() );

		if ( ! $mission_id ) {
			return $this->error(
				'faracart_mission_create_failed',
				__( 'The mission could not be created.', 'faracart' ),
				500
			);
		}

		return $this->success( $this->shape( $this->missions->get( $mission_id ) ) );
	}

	/**
	 * Update a mission (partial update — only provided keys are written).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_update( $request ) {
		$mission_id = (int) $request->get_param( 'id' );
		$row     = $this->missions->get( $mission_id );

		if ( null === $row ) {
			return $this->error(
				'faracart_mission_not_found',
				__( 'The mission could not be found.', 'faracart' ),
				404
			);
		}

		if ( ! $this->missions->update( $mission_id, $request->get_params() ) ) {
			return $this->error(
				'faracart_mission_update_failed',
				__( 'The mission could not be updated.', 'faracart' ),
				500
			);
		}

		return $this->success( $this->shape( $this->missions->get( $mission_id ) ) );
	}

	/**
	 * Delete a mission.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_delete( $request ) {
		$mission_id = (int) $request->get_param( 'id' );

		if ( null === $this->missions->get( $mission_id ) ) {
			return $this->error(
				'faracart_mission_not_found',
				__( 'The mission could not be found.', 'faracart' ),
				404
			);
		}

		if ( ! $this->missions->delete( $mission_id ) ) {
			return $this->error(
				'faracart_mission_delete_failed',
				__( 'The mission could not be deleted.', 'faracart' ),
				500
			);
		}

		return $this->success(
			array(
				'deleted' => true,
				'id'      => $mission_id,
			)
		);
	}

	/**
	 * Duplicate a mission.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_duplicate( $request ) {
		$mission_id = (int) $request->get_param( 'id' );

		if ( null === $this->missions->get( $mission_id ) ) {
			return $this->error(
				'faracart_mission_not_found',
				__( 'The mission could not be found.', 'faracart' ),
				404
			);
		}

		$copy_id = $this->missions->duplicate( $mission_id );

		if ( ! $copy_id ) {
			return $this->error(
				'faracart_mission_duplicate_failed',
				__( 'The mission could not be duplicated.', 'faracart' ),
				500
			);
		}

		return $this->success( $this->shape( $this->missions->get( $copy_id ) ) );
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
				'enum'    => array( '', Mission::STATUS_ACTIVE, Mission::STATUS_INACTIVE ),
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
				'default' => Mission::STATUS_ACTIVE,
				'enum'    => array( Mission::STATUS_ACTIVE, Mission::STATUS_INACTIVE ),
			),
			'type'              => array(
				'type'    => 'string',
				'default' => Mission::TYPE_AMOUNT,
				'enum'    => array(
					Mission::TYPE_AMOUNT,
					Mission::TYPE_QUANTITY,
					Mission::TYPE_DISTINCT_QUANTITY,
					Mission::TYPE_CATEGORY,
					Mission::TYPE_PRODUCT,
					Mission::TYPE_WEIGHT,
					Mission::TYPE_COMPOSITE,
					// Phase 32 (brand/tag/attribute conditions).
					Mission::TYPE_TAG,
					Mission::TYPE_ATTRIBUTE,
					Mission::TYPE_BRAND,
				),
			),
			'target'            => array(
				'type'    => 'number',
				'default' => 0,
				'minimum' => 0,
			),
			'calculation_mode'  => array(
				'type'    => 'string',
				'default' => Mission::MODE_SUBTOTAL,
				'enum'    => array(
					Mission::MODE_SUBTOTAL,
					Mission::MODE_TOTAL,
					Mission::MODE_DISCOUNTED_SUBTOTAL,
					Mission::MODE_QUANTITY,
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
				'default' => Mission::OP_AND,
				'enum'    => array( Mission::OP_AND, Mission::OP_OR ),
			),
			'children'          => array(
				'type'              => 'array',
				'default'           => array(),
				'items'             => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				// P22 hardening: children are Mission payloads persisted into
				// the conditions JSON; the schema itself is intentionally
				// open (a child is itself a Mission), so a sanitize_callback
				// whitelists the keys recursively and casts every value to
				// its safe scalar type before the payload reaches the DB.
				'sanitize_callback' => array( $this, 'sanitize_children' ),
			),
			// Phase 32 (Advanced V2): brand/tag/attribute mission types plus
			// the customer/order/cart/shipping condition keys — validated
			// and cast here so a bad value can never reach the conditions
			// JSON.
			'tags'              => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'attributes'        => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'customer_roles'    => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'customer_state'    => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
					'enum' => array( 'guest', 'logged_in' ),
				),
			),
			'first_order'       => array( 'type' => 'boolean', 'default' => false ),
			'vip'               => array( 'type' => 'boolean', 'default' => false ),
			'vip_min_spend'     => array( 'type' => 'number', 'minimum' => 0 ),
			'vip_min_orders'    => array( 'type' => 'integer', 'minimum' => 0 ),
			'shipping_zones'    => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'cart_coupons'      => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'cart_min_items'    => array( 'type' => 'integer', 'minimum' => 0 ),
			'schedule_days'     => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 7 ),
			),
			'schedule_start_time' => array(
				'type'              => array( 'string', 'null' ),
				'validate_callback' => array( $this, 'validate_time_param' ),
			),
			'schedule_end_time' => array(
				'type'              => array( 'string', 'null' ),
				'validate_callback' => array( $this, 'validate_time_param' ),
			),
			'priority'          => array(
				'type'    => 'integer',
				'default' => 10,
				'minimum' => 0,
			),
			'exclusive'         => array(
				'type'    => 'boolean',
				'default' => false,
			),
			// Phase 36 (Per-User Mission Completion Limit): how many times the
			// same user may complete this mission (null = unlimited). The
			// schema rejects zero, negatives and non-integers; the
			// repository additionally normalizes anything < 1 to null.
			'max_completions_per_user' => array(
				'type'    => array( 'integer', 'null' ),
				'default' => null,
				'minimum' => 1,
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
				// Template engine: template_id must be registered for the
				// mission scope and template_settings must conform to that
				// template's schema — never trust client-side validation.
				'validate_callback'    => array( $this, 'validate_display_settings' ),
				'sanitize_callback'    => array( $this, 'sanitize_display_settings' ),
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
	 * Schema defaults are stripped on purpose: WP_REST_Server applies a
	 * route arg's `default` to every param the client did not send during
	 * sanitization (only non-null defaults), and handle_update() passes
	 * the full param set to the repository. A status-only toggle (e.g. the
	 * Missions list switch) would otherwise silently overwrite untouched
	 * fields with their defaults — target → 0, campaign_id → null,
	 * children → [], reward_meta/display_settings/limits → [], priority →
	 * 10, exclusive → false, description → '', status → 'active',
	 * type/calculation_mode/operator → their defaults. Keeping the update
	 * schema default-free means only the keys the client actually sent
	 * are ever written (the create schema keeps its defaults for omitted
	 * optional fields).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function update_args() {
		$args = $this->save_args( false );

		foreach ( $args as $key => $arg ) {
			unset( $args[ $key ]['default'] );
		}

		$args['id'] = $this->id_args()['id'];

		return $args;
	}

	/**
	 * Validate the display_settings payload (template engine).
	 *
	 * A `template_id` must be registered for the mission scope.
	 *
	 * @param mixed $value Raw display_settings value.
	 * @return bool
	 */
	public function validate_display_settings( $value ) {
		if ( ! is_array( $value ) ) {
			return true;
		}

		if ( isset( $value['template_id'] ) ) {
			return $this->templates()->is_registered( TemplateEngine::SCOPE_MISSION, $value['template_id'] );
		}

		return true;
	}

	/**
	 * Sanitize the display_settings payload (template engine).
	 *
	 * Normalizes template_id to the registry and validates template_settings
	 * against that template's schema (unknown keys dropped, values cast and
	 * clamped). All other display keys pass through untouched.
	 *
	 * @param mixed $value Raw display_settings value.
	 * @return array<string, mixed>
	 */
	public function sanitize_display_settings( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = $value;

		if ( isset( $clean['template_id'] ) ) {
			$clean['template_id'] = $this->templates()->normalize_template_id(
				TemplateEngine::SCOPE_MISSION,
				$clean['template_id']
			);
		}

		if ( isset( $clean['template_settings'] ) ) {
			$template_id = isset( $clean['template_id'] ) && '' !== $clean['template_id']
				? (string) $clean['template_id']
				: '';

			$template = $this->templates()->registry()->has( $template_id )
				? $this->templates()->registry()->get( $template_id )
				: null;

			$clean['template_settings'] = $template
				? $this->templates()->sanitize_settings( $template, $clean['template_settings'] )
				: array();
		}

		return $clean;
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

		$table = \FaraCart\Database\Schema::table( 'campaigns' );

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $campaign_id )
		);

		return (int) $exists > 0;
	}

	/**
	 * Whitelist-and-cast composite children (P22 hardening).
	 *
	 * Children are nested Mission payloads persisted into the conditions
	 * JSON. Each child is filtered through an explicit scalar whitelist so
	 * arbitrary admin-supplied key/values can never reach the database
	 * verbatim: unknown keys are dropped, strings are sanitized, money is
	 * float-cast, ids are positive ints, and nested `children` recurse
	 * through the same rule.
	 *
	 * @param mixed $value Raw children array.
	 * @return array[] Sanitized children.
	 */
	public function sanitize_children( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$scalar = array(
			'name'             => 'sanitize_text_field',
			'status'           => 'sanitize_key',
			'type'             => 'sanitize_key',
			'calculation_mode' => 'sanitize_key',
			'operator'         => 'sanitize_key',
			'schedule_start_time' => 'sanitize_text_field',
			'schedule_end_time'   => 'sanitize_text_field',
		);

		$float = array( 'target', 'reward_value', 'reward_max_value', 'vip_min_spend' );
		$ints  = array( 'categories', 'products', 'excluded_products', 'tags', 'shipping_zones', 'schedule_days', 'cart_min_items', 'vip_min_orders' );
		$str_list = array( 'attributes', 'customer_roles', 'customer_state', 'cart_coupons' );
		$flags    = array( 'first_order', 'vip', 'exclusive' );

		$clean = array();

		foreach ( $value as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$node = array();

			foreach ( $scalar as $key => $sanitize ) {
				if ( array_key_exists( $key, $child ) ) {
					$node[ $key ] = call_user_func( $sanitize, (string) $child[ $key ] );
				}
			}

			foreach ( $float as $key ) {
				if ( array_key_exists( $key, $child ) ) {
					$node[ $key ] = (float) $child[ $key ];
				}
			}

			foreach ( $ints as $key ) {
				if ( isset( $child[ $key ] ) && is_array( $child[ $key ] ) ) {
					$node[ $key ] = array_values(
						array_filter( array_map( 'intval', $child[ $key ] ), function ( $id ) {
							return $id > 0;
						} )
					);
				}
			}

			foreach ( $str_list as $key ) {
				if ( isset( $child[ $key ] ) && is_array( $child[ $key ] ) ) {
					$node[ $key ] = array_values(
						array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $child[ $key ] ) ), function ( $v ) {
							return '' !== $v;
						} )
					);
				}
			}

			foreach ( $flags as $key ) {
				if ( array_key_exists( $key, $child ) ) {
					$node[ $key ] = (bool) $child[ $key ];
				}
			}

			if ( array_key_exists( 'children', $child ) ) {
				$node['children'] = $this->sanitize_children( $child['children'] );
			}

			$clean[] = $node;
		}

		return $clean;
	}

	/**
	 * Normalize a repository row into the REST payload shape.
	 *
	 * @param array<string, mixed> $row Normalized mission row.
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
			'operator'          => isset( $row['operator'] ) ? (string) $row['operator'] : Mission::OP_AND,
			'children'          => isset( $row['children'] ) && is_array( $row['children'] ) ? $row['children'] : array(),
			// Phase 32 condition surface (mirrors the Mission model accessors).
			'tags'              => $this->ints( isset( $row['tags'] ) ? $row['tags'] : array() ),
			'attributes'        => $this->strings( isset( $row['attributes'] ) ? $row['attributes'] : array() ),
			'customer_roles'    => $this->strings( isset( $row['customer_roles'] ) ? $row['customer_roles'] : array() ),
			'customer_state'    => $this->strings( isset( $row['customer_state'] ) ? $row['customer_state'] : array() ),
			'first_order'       => ! empty( $row['first_order'] ),
			'vip'               => ! empty( $row['vip'] ),
			'vip_min_spend'     => isset( $row['vip_min_spend'] ) ? (float) $row['vip_min_spend'] : 0.0,
			'vip_min_orders'    => isset( $row['vip_min_orders'] ) ? (int) $row['vip_min_orders'] : 0,
			'shipping_zones'    => $this->ints( isset( $row['shipping_zones'] ) ? $row['shipping_zones'] : array() ),
			'cart_coupons'      => $this->strings( isset( $row['cart_coupons'] ) ? $row['cart_coupons'] : array() ),
			'cart_min_items'    => isset( $row['cart_min_items'] ) ? (int) $row['cart_min_items'] : 0,
			'schedule_days'     => $this->ints( isset( $row['schedule_days'] ) ? $row['schedule_days'] : array() ),
			'schedule_start_time' => isset( $row['schedule_start_time'] ) ? (string) $row['schedule_start_time'] : '',
			'schedule_end_time' => isset( $row['schedule_end_time'] ) ? (string) $row['schedule_end_time'] : '',
			'reward_type'       => ! empty( $row['reward_type'] ) ? (string) $row['reward_type'] : null,
			'reward_value'      => null !== $row['reward_value'] && '' !== $row['reward_value'] ? (float) $row['reward_value'] : null,
			'reward_max_value'  => null !== $row['reward_max_value'] && '' !== $row['reward_max_value'] ? (float) $row['reward_max_value'] : null,
			'reward_meta'       => isset( $row['reward_meta'] ) && is_array( $row['reward_meta'] ) ? $row['reward_meta'] : array(),
			'priority'          => (int) $row['priority'],
			'exclusive'         => ! empty( $row['exclusive'] ),
			'max_completions_per_user' => isset( $row['max_completions_per_user'] ) && '' !== $row['max_completions_per_user']
				? max( 1, (int) $row['max_completions_per_user'] )
				: null,
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

		return array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $value ) ), function ( $v ) {
			return '' !== $v;
		} ) );
	}

	/**
	 * Validate a recurring schedule time ('H:i').
	 *
	 * @param mixed $value Value to validate.
	 * @return bool
	 */
	public function validate_time_param( $value ) {
		if ( null === $value || '' === $value ) {
			return true;
		}

		return (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value );
	}
}
