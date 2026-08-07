<?php
/**
 * REST controller for campaigns.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Campaigns\CampaignRepository;
use GoalCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class CampaignsController
 *
 * Phase 7 (REST API / AJAX Layer) campaign endpoints:
 *
 *  - `GET /goalcart/v1/campaigns`      — campaign list (for the goal
 *    builder's campaign selector and the admin nav).
 *  - `GET /goalcart/v1/campaigns/{id}` — a single campaign.
 *
 * Read-only in Phase 7: the full campaign CRUD, milestone ordering and
 * scheduling is Phase 10 (Campaign Builder), which extends this controller
 * with create/update/delete/duplicate routes on the same repository.
 *
 * Admin-only (manage_options, P07-T04).
 */
class CampaignsController extends BaseController {

	/**
	 * Campaign repository instance.
	 *
	 * @var CampaignRepository
	 */
	protected $campaigns;

	/**
	 * Constructor.
	 *
	 * @param CampaignRepository $campaigns Campaign repository.
	 */
	public function __construct( CampaignRepository $campaigns ) {
		$this->campaigns = $campaigns;
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
			'/campaigns/(?P<id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
				),
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
		return $this->success( array( 'items' => $this->campaigns->all() ) );
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
				'goalcart_campaign_not_found',
				__( 'The campaign could not be found.', 'goalcart' ),
				404
			);
		}

		return $this->success( $campaign );
	}
}
