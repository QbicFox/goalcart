<?php
/**
 * REST controller for the progress template registry.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Hooks\HookManager;
use GoalCart\Templates\TemplateEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Class TemplatesController
 *
 * Template registry endpoints (pluggable template engine):
 *
 *  - `GET /goalcart/v1/templates` — every registered template grouped by
 *    scope (goal / campaign) with its settings schema, the current scope
 *    defaults, and the effective default appearance per template. The
 *    React admin app reads this to render the template pickers and the
 *    schema-driven settings forms; the backend stays the source of truth
 *    for which templates exist and what they accept.
 *
 * Admin-only (manage_options + rate limit, P07-T04), same conventions as
 * every FaraCart admin endpoint.
 */
class TemplatesController extends BaseController {

	/**
	 * Template engine instance.
	 *
	 * @var TemplateEngine
	 */
	protected $templates;

	/**
	 * Constructor.
	 *
	 * @param TemplateEngine $templates Template engine.
	 */
	public function __construct( TemplateEngine $templates ) {
		$this->templates = $templates;
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
	 * Register the templates route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/templates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_index' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => array(),
			)
		);
	}

	/**
	 * List all registered templates with their settings schemas.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_index( $request ) {
		return $this->success( $this->templates->data() );
	}
}
