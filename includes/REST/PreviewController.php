<?php
/**
 * REST controller for the admin preview system.
 *
 * @package FaraCart
 */

namespace FaraCart\REST;

use FaraCart\Campaigns\CampaignRepository;
use FaraCart\Missions\CartContext;
use FaraCart\Missions\CartItem;
use FaraCart\Missions\ConflictResolver;
use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionEngine;
use FaraCart\Missions\MissionRepository;
use FaraCart\Missions\MissionResult;
use FaraCart\Hooks\HookManager;
use FaraCart\Rewards\RewardEngine;
use FaraCart\Rewards\RewardResult;
use FaraCart\Settings\Settings;
use FaraCart\Templates\TemplateEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Class PreviewController
 *
 * Phase 15 (Admin Preview System): lets administrators see the customer
 * experience before publishing.
 *
 *  - `POST /faracart/v1/preview` — evaluates a mission (or a campaign's
 *    milestone missions) against a SIMULATED cart and returns the exact same
 *    per-mission payload shape as the public `GET /progress` endpoint, so the
 *    admin React preview renders the real storefront widget (templates,
 *    messages, rewards, suggestions) without publishing anything.
 *
 * Preview never affects the real WooCommerce cart: a synthetic CartContext
 * is built purely from the request's `simulated` values (cart amount and
 * item quantity), and the engine / message / suggestion services are all
 * pure — no cart is loaded, no session is touched, no fees or coupons are
 * applied. Publish gating is ignored on purpose (the mission is previewed as
 * active and in-schedule) so drafts, inactive missions and scheduled
 * campaigns can be seen before they go live.
 *
 * Simulation fidelity (documented trade-off): a single synthetic cart line
 * carries the simulated amount, quantity, weight, categories and product
 * id, so amount/quantity/distinct-quantity/category/weight missions evaluate
 * honestly; product missions use the first configured product id; composite
 * missions union their children's constraints (a product child beyond the
 * first cannot be represented by one line and is approximated).
 *
 * Security (P07-T04, mirrored): admin-only — `manage_options` capability
 * plus per-user rate limiting, every input validated through the route arg
 * schema, and the payload carries only aggregate numbers (plus the admin's
 * own simulated values).
 */
class PreviewController extends BaseController {

	/**
	 * Rate-limit budget for the preview route (requests per window).
	 *
	 * Higher than the default 60/min: the preview dialog fires a debounced
	 * request per control change (simulated amount/quantity typing, preset
	 * clicks), so generous headroom avoids tripping admins mid-interaction.
	 *
	 * @var int
	 */
	const PREVIEW_RATE_LIMIT_COUNT = 300;

	/**
	 * Mission engine instance.
	 *
	 * @var MissionEngine
	 */
	protected $engine;

	/**
	 * Mission repository instance (stored rows, for preview mission loading).
	 *
	 * @var MissionRepository
	 */
	protected $missions;

	/**
	 * Campaign repository instance (milestone loading).
	 *
	 * @var CampaignRepository
	 */
	protected $campaigns;

	/**
	 * Frontend controller (shared payload shaping — message, suggestions,
	 * reward, icon, template all flow through shape_mission()).
	 *
	 * @var FrontendController
	 */
	protected $frontend;

	/**
	 * Settings instance (conflict-resolution mode, Phase 26).
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Reward engine (Phase 26 display/grant parity): evaluates each
	 * completed milestone's reward against its simulated cart so 'best'
	 * mode compares real computed amounts and the preview reflects
	 * stacking suppression exactly like the live cart would.
	 *
	 * @var RewardEngine|null
	 */
	protected $reward_engine;

	/**
	 * Template engine (pluggable templates): resolves the previewed
	 * campaign's group template. Null when not injected — resolved lazily
	 * from the plugin container.
	 *
	 * @var TemplateEngine|null
	 */
	protected $templates;

	/**
	 * Constructor.
	 *
	 * @param MissionEngine         $engine       Mission engine.
	 * @param MissionRepository     $missions        Mission repository.
	 * @param CampaignRepository $campaigns    Campaign repository.
	 * @param FrontendController $frontend     Frontend controller (shape_mission).
	 * @param Settings           $settings     Settings service.
	 * @param RewardEngine|null  $reward_engine Reward engine (Phase 26
	 *                                          display/grant parity).
	 * @param TemplateEngine|null $templates   Template engine (optional).
	 */
	public function __construct( MissionEngine $engine, MissionRepository $missions, CampaignRepository $campaigns, FrontendController $frontend, Settings $settings, ?RewardEngine $reward_engine = null, ?TemplateEngine $templates = null ) {
		$this->engine        = $engine;
		$this->missions         = $missions;
		$this->campaigns     = $campaigns;
		$this->frontend      = $frontend;
		$this->settings      = $settings;
		$this->reward_engine = $reward_engine;
		$this->templates     = $templates;
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
	 * Register the preview route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_preview' ),
				'permission_callback' => $this->get_preview_permission_callback(),
				'args'                => $this->preview_args(),
			)
		);
	}

	/**
	 * Permission callback for the preview route.
	 *
	 * Admin-only (manage_options + rate limit) like every admin endpoint,
	 * but with a larger per-window budget so the dialog's debounced
	 * preview requests cannot trip the default 60/min limiter.
	 *
	 * @return callable
	 */
	public function get_preview_permission_callback() {
		return function ( $request ) {
			$capability = apply_filters( 'faracart_rest_capability', self::CAPABILITY );

			if ( ! current_user_can( $capability ) ) {
				return $this->error(
					'faracart_forbidden',
					__( 'You are not allowed to access this endpoint.', 'faracart' ),
					403
				);
			}

			$limited = $this->rate_limit( $request, self::PREVIEW_RATE_LIMIT_COUNT );

			if ( is_wp_error( $limited ) ) {
				return $limited;
			}

			return true;
		};
	}

	/**
	 * Arg schema for the preview route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function preview_args() {
		return array(
			'mission_id' => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'campaign_id' => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			// Unsaved form state (builder live preview): a MissionInput-shaped
			// mission or a CampaignInput-shaped campaign may be submitted
			// instead of (or alongside) the saved ids, so the builder can
			// preview the current form values before they are persisted.
			// When both a saved id and a payload are present, the payload
			// wins (it reflects the latest unsaved edits); an existing
			// mission's row is still merged underneath for campaign context.
			'mission' => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => true,
			),
			'campaign' => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => true,
			),
			'simulated' => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => array(
					'amount'   => array( 'type' => 'number', 'minimum' => 0 ),
					'quantity' => array( 'type' => 'number', 'minimum' => 0 ),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Handle a preview read.
	 *
	 * Exactly one of mission_id / campaign_id is required. Evaluates the
	 * target mission(s) against the simulated cart and returns the shared
	 * progress payload shape (`missions`, `currency`, plus the `simulated`
	 * values echoed back so the UI can label the frame).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_preview( $request ) {
		$mission_id          = (int) $request->get_param( 'mission_id' );
		$campaign_id      = (int) $request->get_param( 'campaign_id' );
		$mission_payload     = $request->get_param( 'mission' );
		$campaign_payload = $request->get_param( 'campaign' );

		$mission_payload     = is_array( $mission_payload ) && ! empty( $mission_payload ) ? $mission_payload : array();
		$campaign_payload = is_array( $campaign_payload ) && ! empty( $campaign_payload ) ? $campaign_payload : array();

		// Exactly one target: a mission (saved id and/or unsaved form payload —
		// the payload wins for edited fields) or a campaign (same rule).
		// Mixing a mission and a campaign (or providing neither) is invalid.
		$mission_target     = $mission_id > 0 || ! empty( $mission_payload );
		$campaign_target = $campaign_id > 0 || ! empty( $campaign_payload );

		if ( $mission_target === $campaign_target ) {
			return $this->error(
				'faracart_preview_target_required',
				__( 'Provide exactly one of mission_id, campaign_id, mission or campaign to preview.', 'faracart' ),
				400
			);
		}

		$simulated = $request->get_param( 'simulated' );

		if ( ! is_array( $simulated ) ) {
			$simulated = array();
		}

		$amount   = isset( $simulated['amount'] ) ? (float) $simulated['amount'] : 0.0;
		$quantity = isset( $simulated['quantity'] ) ? (float) $simulated['quantity'] : 0.0;

		$missions = $this->resolve_missions( $mission_id, $campaign_id, $mission_payload, $campaign_payload );

		if ( empty( $missions ) ) {
			return $this->error(
				'faracart_preview_not_found',
				__( 'The mission or campaign could not be found.', 'faracart' ),
				404
			);
		}

		// Evaluate every milestone first, then resolve conflicts (Phase 26)
		// across the completed ones so the preview shows exactly which
		// milestones grant their rewards — and which are suppressed by the
		// priority / exclusive / mode rules — before the payload is shaped.
		$evaluated = array();

		foreach ( $missions as $mission ) {
			$context = $this->simulated_context( $mission, $amount, $quantity );
			$result  = $this->engine->evaluate( $mission, $context );

			$evaluated[] = array(
				'mission'    => $mission,
				'result'  => $result,
				'context' => $context,
			);
		}

		$resolution = $this->resolve_conflicts( $evaluated );

		$items = array();

		foreach ( $evaluated as $entry ) {
			$items[] = $this->frontend->shape_mission(
				$entry['mission'],
				$entry['result'],
				$entry['context'],
				array( 'quantity' => $entry['context']->total_quantity() ),
				true, // Admin preview: expose the full reward meta (manage_options-gated).
				$this->conflict_for( $resolution, $entry['mission'] )
			);
		}

		return $this->success(
			array(
				'missions'    => $items,
				// Campaign template group (pluggable engine) — mirrors the
				// live /progress payload so the preview renders a configured
				// campaign template (e.g. the milestone chain) exactly like
				// the storefront.
				'campaigns' => $this->campaign_groups( $mission_id, $campaign_id, $campaign_payload ),
				'currency' => $this->settings->currency(),
				'simulated' => array(
					'amount'   => $amount,
					'quantity' => $quantity,
				),
			),
			array(
				'mode' => ( $campaign_id > 0 || ! empty( $campaign_payload ) ) ? 'campaign' : 'mission',
			)
		);
	}

	/**
	 * Resolve conflict winners among the previewed missions.
	 *
	 * Same contract as the reward engine (Phase 26): completed missions that
	 * carry a reward compete under the configured resolution mode — 'best'
	 * compares the real computed reward amounts on each milestone's
	 * simulated cart — and the per-reward stacking safety applies to the
	 * winners in priority order, so the preview's conflict chips are
	 * exactly what the live cart would grant.
	 *
	 * @param array<int, array<string, mixed>> $evaluated Entries with
	 *                             'mission', 'result' and 'context' keys.
	 * @return array<int, string> mission_id => ConflictResolver::REASON_*.
	 */
	protected function resolve_conflicts( array $evaluated ) {
		$missions   = array();
		$results = array();
		$scores  = array();
		$rewards = array();

		foreach ( $evaluated as $entry ) {
			$mission   = $entry['mission'];
			$result = $entry['result'];

			$missions[] = $mission;

			if ( ! $result->eligible() || MissionResult::REWARD_UNLOCKED !== $result->reward_state() ) {
				continue;
			}

			if ( empty( $mission->reward_type() ) ) {
				continue;
			}

			$results[ $mission->id() ] = $result;

			// The reward is evaluated WITHOUT the stacking guard (empty
			// already_applied pass), exactly like RewardEngine::sync_cart()
			// pass 1 — the amount drives 'best', the state drives the
			// stacking mirror below.
			if ( null !== $this->reward_engine ) {
				$reward_result = $this->reward_engine->evaluate(
					$result,
					array( 'cart' => $entry['context'] )
				);

				$rewards[ $mission->id() ] = $reward_result;

				if ( RewardResult::STATE_AVAILABLE === $reward_result->state() ) {
					$scores[ $mission->id() ] = $reward_result->amount();
				}
			}
		}

		$mode       = (string) $this->settings->get( 'conflict_resolution', ConflictResolver::MODE_CUMULATIVE );
		$resolver   = new ConflictResolver();
		$resolution = $resolver->resolve( $missions, $results, $mode, $scores );

		if ( null !== $this->reward_engine ) {
			$resolution = $resolver->apply_stacking( $missions, $resolution, $rewards );
		}

		return $resolution;
	}

	/**
	 * The per-mission conflict payload fragment (mirrors the frontend path).
	 *
	 * @param array<int, string> $resolution mission_id => reason.
	 * @param Mission               $mission       Mission.
	 * @return array<string, mixed>
	 */
	protected function conflict_for( array $resolution, Mission $mission ) {
		$reason = isset( $resolution[ $mission->id() ] ) ? $resolution[ $mission->id() ] : ConflictResolver::REASON_NONE;

		return array(
			'resolved' => ConflictResolver::REASON_NONE === $reason,
			'reason'   => $reason,
		);
	}

	/**
	 * The campaign template group for the preview payload.
	 *
	 * Mirrors FrontendController::campaign_groups(): a single-entry list
	 * for campaign previews (resolved from the submitted display_rules —
	 * the stored row or the unsaved form payload), and empty for mission
	 * previews — so the preview resolves the campaign template + settings
	 * identically to the live frontend.
	 *
	 * @param int                    $mission_id          Mission id (0 when previewing a campaign).
	 * @param int                    $campaign_id      Campaign id (0 when previewing a mission).
	 * @param array<string, mixed>   $campaign_payload Unsaved campaign form state ('' for saved-id previews).
	 * @return array<int, array<string, mixed>>
	 */
	protected function campaign_groups( $mission_id, $campaign_id, array $campaign_payload = array() ) {
		if ( $campaign_id <= 0 && empty( $campaign_payload ) ) {
			return array();
		}

		$campaign = ! empty( $campaign_payload ) ? $campaign_payload : $this->campaigns->get( (int) $campaign_id );

		if ( ! is_array( $campaign ) || empty( $campaign ) ) {
			return array();
		}

		$rules    = isset( $campaign['display_rules'] ) && is_array( $campaign['display_rules'] ) ? $campaign['display_rules'] : array();
		$resolved = $this->templates()->resolve_campaign( $rules );

		if ( '' === $resolved['template_id'] ) {
			return array();
		}

		return array(
			array(
				'campaign_id' => $this->campaign_group_id( $campaign_id, $campaign_payload ),
				'name'        => isset( $campaign['name'] ) ? (string) $campaign['name'] : '',
				'template'    => $resolved['template_id'],
				'settings'    => $resolved['settings'],
			),
		);
	}

	/**
	 * The stable group id shared by the campaign group and its milestone
	 * mission rows. A saved campaign uses its own id; an unsaved campaign
	 * form (no id yet) uses a synthetic negative id so PreviewWidget's
	 * campaign grouping still matches the milestone rows.
	 *
	 * @param int                  $campaign_id      Saved campaign id (0 when creating).
	 * @param array<string, mixed> $campaign_payload Unsaved campaign form state.
	 * @return int
	 */
	protected function campaign_group_id( $campaign_id, array $campaign_payload ) {
		if ( $campaign_id > 0 ) {
			return $campaign_id;
		}

		if ( ! empty( $campaign_payload ) && isset( $campaign_payload['id'] ) ) {
			$id = (int) $campaign_payload['id'];

			if ( $id > 0 ) {
				return $id;
			}
		}

		return -1;
	}

	/**
	 * Load the preview missions, forced into their "published" state.
	 *
	 * Single-mission mode returns one Mission (from the stored row or the
	 * unsaved form payload, merged when both are present); campaign mode
	 * returns every milestone mission in menu order (with the campaign name
	 * folded in for the {campaign_name} message variable). Empty array
	 * when the target does not exist or has no missions.
	 *
	 * @param int                  $mission_id          Mission id (0 when previewing a campaign or a new mission).
	 * @param int                  $campaign_id      Campaign id (0 when previewing a mission or a new campaign).
	 * @param array<string, mixed> $mission_payload     Unsaved mission form state.
	 * @param array<string, mixed> $campaign_payload Unsaved campaign form state.
	 * @return Mission[]
	 */
	protected function resolve_missions( $mission_id, $campaign_id, array $mission_payload = array(), array $campaign_payload = array() ) {
		$missions = array();

		if ( $campaign_id > 0 || ! empty( $campaign_payload ) ) {
			$campaign = ! empty( $campaign_payload ) ? $campaign_payload : $this->campaigns->get( $campaign_id );

			if ( ! is_array( $campaign ) || empty( $campaign ) ) {
				return $missions;
		}

			$group_id = $this->campaign_group_id( $campaign_id, $campaign_payload );
			$name     = isset( $campaign['name'] ) ? (string) $campaign['name'] : '';
			$missions_in = isset( $campaign['missions'] ) && is_array( $campaign['missions'] ) ? $campaign['missions'] : array();

			foreach ( $missions_in as $milestone ) {
				// The campaign form submits ordered mission ids; the stored row
				// carries {id, ...} entries — accept both.
				$milestone_id = is_array( $milestone )
					? (int) ( isset( $milestone['id'] ) ? $milestone['id'] : 0 )
					: (int) $milestone;

				$row = $this->missions->get( $milestone_id );

				if ( ! is_array( $row ) ) {
					continue;
				}

				// The submitted campaign owns the previewed milestone rows,
				// so the payload's campaign group (and the campaign template)
				// can always be associated, even when the missions are not yet
				// stored under this campaign.
				$row['campaign_id'] = $group_id;

				$mission = $this->preview_mission( $row, $name );

				if ( $mission ) {
					$missions[] = $mission;
				}
			}

			return $missions;
		}

		if ( ! empty( $mission_payload ) ) {
			// Unsaved form state: the payload wins for the edited fields,
			// the stored row underneath keeps the id + campaign context
			// when editing an existing mission.
			$row = $mission_id > 0 ? $this->missions->get( $mission_id ) : null;
			$row = is_array( $row ) ? array_merge( $row, $mission_payload ) : $mission_payload;

			$mission = $this->preview_mission( $row );

			return $mission ? array( $mission ) : array();
		}

		$mission = $this->preview_mission( $this->missions->get( $mission_id ) );

		return $mission ? array( $mission ) : array();
	}

	/**
	 * Build a preview Mission from a stored repository row.
	 *
	 * Publish gating is intentionally ignored (Phase 15 objective: see the
	 * customer experience BEFORE publishing): the mission is forced active and
	 * its schedule cleared, so drafts, inactive missions and scheduled
	 * campaigns evaluate as they will once live.
	 *
	 * @param mixed  $row           Stored mission row (array) or null.
	 * @param string $campaign_name Campaign name to fold in ('' for singles).
	 * @return Mission|null Null when the row does not exist.
	 */
	protected function preview_mission( $row, $campaign_name = '' ) {
		if ( ! is_array( $row ) || empty( $row ) ) {
			return null;
		}

		$row['status']     = Mission::STATUS_ACTIVE;
		$row['starts_at']  = null;
		$row['ends_at']    = null;

		if ( '' !== $campaign_name ) {
			$row['campaign_name'] = $campaign_name;
		}

		return new Mission( $row );
	}

	/**
	 * Build the synthetic CartContext that yields the simulated values.
	 *
	 * One (or, for distinct-quantity, several) synthetic cart line carries
	 * the simulated amount / quantity / weight / categories / product id,
	 * so each evaluator reads exactly what the admin dialed in. The engine
	 * is exercised end-to-end — eligibility, progress math, message engine,
	 * suggestions — against a context that never came from a real cart.
	 *
	 * @param Mission  $mission     Mission being previewed.
	 * @param float $amount   Simulated cart amount (money basis).
	 * @param float $quantity Simulated item quantity (count basis).
	 * @return CartContext
	 */
	protected function simulated_context( Mission $mission, $amount, $quantity ) {
		$amount   = max( 0.0, (float) $amount );
		$quantity = max( 0.0, (float) $quantity );
		$items    = array();

		switch ( $mission->type() ) {
			case Mission::TYPE_QUANTITY:
				$items[] = $this->simulated_item(
					array(
						'quantity'      => $quantity,
						'line_subtotal' => $amount,
						'line_total'    => $amount,
						'price'         => $amount,
					)
				);
				break;

			case Mission::TYPE_DISTINCT_QUANTITY:
				// One unique product per simulated unit (capped so a huge
				// simulated quantity cannot balloon the payload).
				$count = (int) ceil( $quantity );
				for ( $i = 1; $i <= $count && $i <= 50; $i++ ) {
					$items[] = $this->simulated_item( array( 'product_id' => $i ) );
				}
				break;

			case Mission::TYPE_CATEGORY:
				$items[] = $this->simulated_item(
					array(
						'categories'    => $mission->categories(),
						'quantity'      => $quantity,
						'line_subtotal' => $amount,
						'line_total'    => $amount,
						'price'         => $amount,
					)
				);
				break;

			case Mission::TYPE_PRODUCT:
				$products = $mission->products();
				$items[] = $this->simulated_item(
					array(
						'product_id'    => ! empty( $products ) ? (int) $products[0] : 0,
						'quantity'      => $quantity,
						'line_subtotal' => $amount,
						'line_total'    => $amount,
						'price'         => $amount,
					)
				);
				break;

			case Mission::TYPE_WEIGHT:
				$items[] = $this->simulated_item(
					array(
						'weight'        => $quantity,
						'line_subtotal' => $amount,
						'line_total'    => $amount,
					)
				);
				break;

			case Mission::TYPE_COMPOSITE:
				$items[] = $this->composite_item( $mission, $amount, $quantity );
				break;

			default:
				// Amount (and any unknown type — the engine rejects unknown
				// types as ineligible anyway): a single line worth $amount.
				$items[] = $this->simulated_item(
					array(
						'quantity'      => 1.0,
						'line_subtotal' => $amount,
						'line_total'    => $amount,
						'price'         => $amount,
					)
				);
				break;
		}

		return new CartContext(
			array(
				'subtotal' => $amount,
				'total'    => $amount,
				'currency' => $this->settings->currency(),
				'items'    => $items,
			)
		);
	}

	/**
	 * The synthetic line for a composite mission.
	 *
	 * Unions the children's constraints onto a single line: categories of
	 * every category child, the first product child's id, the simulated
	 * quantity for count children, and weight for a weight child — so
	 * amount/quantity/category/weight children evaluate honestly. Product
	 * children beyond the first cannot share one line and are approximated
	 * (documented trade-off).
	 *
	 * @param Mission  $mission     Composite mission.
	 * @param float $amount   Simulated amount.
	 * @param float $quantity Simulated quantity.
	 * @return CartItem
	 */
	protected function composite_item( Mission $mission, $amount, $quantity ) {
		$categories = array();
		$products   = array();
		$weight     = 0.0;

		foreach ( $mission->children() as $child_data ) {
			if ( ! is_array( $child_data ) ) {
				continue;
			}

			$child = new Mission( $child_data );

			foreach ( $child->categories() as $id ) {
				$categories[ (int) $id ] = true;
			}

			foreach ( $child->products() as $id ) {
				$products[ (int) $id ] = true;
			}

			if ( Mission::TYPE_WEIGHT === $child->type() ) {
				$weight = (float) $quantity;
			}
		}

		$product_ids = array_keys( $products );

		return $this->simulated_item(
			array(
				'product_id'    => ! empty( $product_ids ) ? (int) $product_ids[0] : 0,
				'categories'    => array_keys( $categories ),
				'quantity'      => $quantity,
				'weight'        => $weight,
				'line_subtotal' => $amount,
				'line_total'    => $amount,
				'price'         => $amount,
			)
		);
	}

	/**
	 * Build a synthetic cart line from overrides + safe defaults.
	 *
	 * @param array<string, mixed> $overrides CartItem payload overrides.
	 * @return CartItem
	 */
	protected function simulated_item( array $overrides = array() ) {
		$data = array_merge(
			array(
				'product_id'    => 0,
				'variation_id'  => 0,
				'name'          => __( 'Preview item', 'faracart' ),
				'quantity'      => 1.0,
				'line_subtotal' => 0.0,
				'line_total'    => 0.0,
				'price'         => 0.0,
				'weight'        => 0.0,
				'categories'    => array(),
				'virtual'       => false,
				'downloadable'  => false,
			),
			$overrides
		);

		return new CartItem( $data );
	}
}
