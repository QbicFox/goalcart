<?php
/**
 * REST controller for campaigns.
 *
 * @package FaraCart
 */

namespace FaraCart\REST;

use FaraCart\Campaigns\CampaignRepository;
use FaraCart\Goals\Goal;
use FaraCart\Hooks\HookManager;
use FaraCart\Templates\TemplateEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Class CampaignsController
 *
 * Campaign endpoints (Phase 10 — Campaign Builder):
 *
 *  - `GET    /faracart/v1/campaigns`      — campaign list (goal_count each)
 *  - `GET    /faracart/v1/campaigns/{id}` — a single campaign + milestones
 *  - `POST   /faracart/v1/campaigns`      — create a campaign
 *  - `PUT    /faracart/v1/campaigns/{id}` — update a campaign (partial)
 *  - `DELETE /faracart/v1/campaigns/{id}` — delete a campaign (goals detach)
 *  - `POST   /faracart/v1/campaigns/{id}/duplicate` — duplicate a campaign
 *    (copy starts inactive; its goals are copied as new goal rows)
 *
 * The payload mirrors the campaigns table plus an ordered `goals` array
 * of goal ids that becomes the campaign's milestone ordering
 * (`goals.campaign_id` + `goals.menu_order`, Phase 10). Admin-only
 * (manage_options, P07-T04).
 */
class CampaignsController extends BaseController {

	/**
	 * Campaign repository instance.
	 *
	 * @var CampaignRepository
	 */
	protected $campaigns;

	/**
	 * Template engine (pluggable templates): validates the campaign's
	 * display_rules.template_id / template_settings on save. Null when not
	 * injected — resolved lazily from the plugin container.
	 *
	 * @var TemplateEngine|null
	 */
	protected $templates;

	/**
	 * Constructor.
	 *
	 * @param CampaignRepository  $campaigns Campaign repository.
	 * @param TemplateEngine|null $templates Template engine (optional).
	 */
	public function __construct( CampaignRepository $campaigns, ?TemplateEngine $templates = null ) {
		$this->campaigns = $campaigns;
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
	 * Register the campaign routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/campaigns',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_index' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => array(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaigns',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_create' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->save_args( true ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaigns/(?P<id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->id_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaigns/(?P<id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'handle_update' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->update_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaigns/(?P<id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->id_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaigns/(?P<id>[\d]+)/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_duplicate' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->id_args(),
			)
		);
	}

	/**
	 * List campaigns.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_index( $request ) {
		$items = array();

		foreach ( $this->campaigns->all() as $row ) {
			$items[] = $this->shape( $row );
		}

		return $this->success( array( 'items' => $items ) );
	}

	/**
	 * Get a single campaign.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get( $request ) {
		$campaign = $this->campaigns->get( (int) $request->get_param( 'id' ) );

		if ( null === $campaign ) {
			return $this->error(
				'faracart_campaign_not_found',
				__( 'The campaign could not be found.', 'faracart' ),
				404
			);
		}

		return $this->success( $this->shape( $campaign ) );
	}

	/**
	 * Create a campaign.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_create( $request ) {
		$campaign_id = $this->campaigns->create( $request->get_params() );

		if ( ! $campaign_id ) {
			return $this->error(
				'faracart_campaign_create_failed',
				__( 'The campaign could not be created.', 'faracart' ),
				500
			);
		}

		return $this->success( $this->shape( $this->campaigns->get( $campaign_id ) ) );
	}

	/**
	 * Update a campaign (partial update — only provided keys are written).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_update( $request ) {
		$campaign_id = (int) $request->get_param( 'id' );

		if ( null === $this->campaigns->get( $campaign_id ) ) {
			return $this->error(
				'faracart_campaign_not_found',
				__( 'The campaign could not be found.', 'faracart' ),
				404
			);
		}

		if ( ! $this->campaigns->update( $campaign_id, $request->get_params() ) ) {
			return $this->error(
				'faracart_campaign_update_failed',
				__( 'The campaign could not be updated.', 'faracart' ),
				500
			);
		}

		return $this->success( $this->shape( $this->campaigns->get( $campaign_id ) ) );
	}

	/**
	 * Delete a campaign.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_delete( $request ) {
		$campaign_id = (int) $request->get_param( 'id' );

		if ( null === $this->campaigns->get( $campaign_id ) ) {
			return $this->error(
				'faracart_campaign_not_found',
				__( 'The campaign could not be found.', 'faracart' ),
				404
			);
		}

		if ( ! $this->campaigns->delete( $campaign_id ) ) {
			return $this->error(
				'faracart_campaign_delete_failed',
				__( 'The campaign could not be deleted.', 'faracart' ),
				500
			);
		}

		return $this->success(
			array(
				'deleted' => true,
				'id'      => $campaign_id,
			)
		);
	}

	/**
	 * Duplicate a campaign.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_duplicate( $request ) {
		$campaign_id = (int) $request->get_param( 'id' );

		if ( null === $this->campaigns->get( $campaign_id ) ) {
			return $this->error(
				'faracart_campaign_not_found',
				__( 'The campaign could not be found.', 'faracart' ),
				404
			);
		}

		$copy_id = $this->campaigns->duplicate( $campaign_id );

		if ( ! $copy_id ) {
			return $this->error(
				'faracart_campaign_duplicate_failed',
				__( 'The campaign could not be duplicated.', 'faracart' ),
				500
			);
		}

		return $this->success( $this->shape( $this->campaigns->get( $copy_id ) ) );
	}

	/**
	 * Validate the display_rules payload (template engine).
	 *
	 * A `template_id` must be registered for the campaign scope.
	 *
	 * @param mixed $value Raw display_rules value.
	 * @return bool
	 */
	public function validate_display_rules( $value ) {
		if ( ! is_array( $value ) ) {
			return true;
		}

		if ( isset( $value['template_id'] ) ) {
			return $this->templates()->is_registered( TemplateEngine::SCOPE_CAMPAIGN, $value['template_id'] );
		}

		return true;
	}

	/**
	 * Sanitize the display_rules payload (template engine).
	 *
	 * Normalizes template_id to the registry and validates template_settings
	 * against that template's schema. All other display keys pass through.
	 *
	 * @param mixed $value Raw display_rules value.
	 * @return array<string, mixed>
	 */
	public function sanitize_display_rules( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = $value;

		if ( isset( $clean['template_id'] ) ) {
			$clean['template_id'] = $this->templates()->normalize_template_id(
				TemplateEngine::SCOPE_CAMPAIGN,
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
	 * Arg schema for the create route (name required).
	 *
	 * @param bool $required Whether `name` is required (create only).
	 * @return array<string, array<string, mixed>>
	 */
	public function save_args( $required = false ) {
		$args = array(
			'name'          => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'status'        => array(
				'type'    => 'string',
				'default' => Goal::STATUS_ACTIVE,
				'enum'    => array( Goal::STATUS_ACTIVE, Goal::STATUS_INACTIVE ),
			),
			'starts_at'     => array(
				'type'              => array( 'string', 'null' ),
				'default'           => null,
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
			'ends_at'       => array(
				'type'              => array( 'string', 'null' ),
				'default'           => null,
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
			'priority'      => array(
				'type'    => 'integer',
				'default' => 10,
				'minimum' => 0,
			),
			'display_rules' => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => true,
				// Template engine: template_id must be registered for the
				// campaign scope and template_settings must conform to that
				// template's schema — never trust client-side validation.
				'validate_callback'    => array( $this, 'validate_display_rules' ),
				'sanitize_callback'    => array( $this, 'sanitize_display_rules' ),
			),
			'goals'         => array(
				'type'  => 'array',
				'items' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);

		if ( $required ) {
			$args['name']['required'] = true;
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
	 * Campaigns list switch) would otherwise silently overwrite untouched
	 * fields with their defaults — description → '', priority → 10,
	 * display_rules → []. Keeping the update schema default-free means
	 * only the keys the client actually sent are ever written (the create
	 * schema keeps its defaults for omitted optional fields).
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
	 * Normalize a repository row into the REST payload shape.
	 *
	 * @param array<string, mixed> $row Campaign row.
	 * @return array<string, mixed>
	 */
	protected function shape( array $row ) {
		$goals = array();

		foreach ( isset( $row['goals'] ) && is_array( $row['goals'] ) ? $row['goals'] : array() as $goal ) {
			$goals[] = array(
				'id'          => (int) $goal['id'],
				'name'        => (string) $goal['name'],
				'type'        => (string) $goal['type'],
				'target'      => (float) $goal['target'],
				'reward_type' => ! empty( $goal['reward_type'] ) ? (string) $goal['reward_type'] : null,
				'menu_order'  => (int) $goal['menu_order'],
			);
		}

		return array(
			'id'            => (int) $row['id'],
			'name'          => (string) $row['name'],
			'description'   => isset( $row['description'] ) ? (string) $row['description'] : '',
			'status'        => (string) $row['status'],
			'starts_at'     => ! empty( $row['starts_at'] ) ? (string) $row['starts_at'] : null,
			'ends_at'       => ! empty( $row['ends_at'] ) ? (string) $row['ends_at'] : null,
			'priority'      => (int) $row['priority'],
			'display_rules' => isset( $row['display_rules'] ) && is_array( $row['display_rules'] ) ? $row['display_rules'] : array(),
			'goal_count'    => isset( $row['goal_count'] ) ? (int) $row['goal_count'] : count( $goals ),
			'goals'         => $goals,
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
		);
	}
}
