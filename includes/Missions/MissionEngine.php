<?php
/**
 * Mission engine facade.
 *
 * @package FaraCart
 */

namespace FaraCart\Missions;

defined( 'ABSPATH' ) || exit;

/**
 * Class MissionEngine
 *
 * The central calculation engine (P04-T02 pipeline entry point). Runs the
 * mission through its evaluator and returns a MissionResult. The engine is
 * stateless and UI-independent — it never renders anything, never touches
 * the database, and never reads request state; callers supply the Mission and
 * CartContext.
 *
 * Pre-evaluation eligibility:
 *  - mission status must be 'active'
 *  - the current time must be inside the mission's schedule window (if any)
 *  - the target must not be negative (zero is a valid, trivially completed
 *    mission; a negative target is a configuration error)
 */
final class MissionEngine {

	/**
	 * Evaluator registry.
	 *
	 * @var MissionEvaluatorRegistry
	 */
	protected $registry;

	/**
	 * Constructor.
	 *
	 * @param MissionEvaluatorRegistry|null $registry Evaluator registry.
	 */
	public function __construct( ?MissionEvaluatorRegistry $registry = null ) {
		$this->registry = null !== $registry ? $registry : new MissionEvaluatorRegistry();
	}

	/**
	 * Evaluate a mission against a cart context.
	 *
	 * Pre-evaluation eligibility covers status, the calendar schedule
	 * window, the recurring day/time rules, target validity and the Phase
	 * 32 customer/order/cart/shipping conditions.
	 *
	 * @param Mission        $mission    Mission to evaluate.
	 * @param CartContext $context Cart snapshot.
	 * @param string|null $now     Current time 'Y-m-d H:i:s' (site tz) for
	 *                             schedule checks; defaults to current_time().
	 * @return MissionResult
	 * @throws \InvalidArgumentException When the mission type has no evaluator.
	 */
	public function evaluate( Mission $mission, CartContext $context, $now = null ) {
		$reason = self::eligibility_reason( $mission, $now );

		if ( MissionResult::REASON_NONE !== $reason ) {
			return MissionResult::ineligible( $mission, $reason );
		}

		$reason = self::conditions_reason( $mission, $context );

		if ( MissionResult::REASON_NONE !== $reason ) {
			return MissionResult::ineligible( $mission, $reason );
		}

		if ( ! $this->registry->supports( $mission->type() ) ) {
			return MissionResult::ineligible( $mission, MissionResult::REASON_UNKNOWN_TYPE );
		}

		return $this->registry->evaluator( $mission->type() )->evaluate( $mission, $context );
	}

	/**
	 * Shared eligibility pre-checks (status, calendar schedule, recurring
	 * day/time rules, target validity).
	 *
	 * Exposed statically so composite children are held to the same rules
	 * as top-level missions without routing them through a MissionEngine
	 * instance. Returns REASON_NONE when the mission is eligible.
	 *
	 * @param Mission        $mission Mission.
	 * @param string|null $now  Reference time, defaults to current_time( 'mysql' ).
	 * @return string MissionResult::REASON_* constant.
	 */
	public static function eligibility_reason( Mission $mission, $now = null ) {
		if ( ! $mission->is_active() ) {
			return MissionResult::REASON_MISSION_INACTIVE;
		}

		if ( ! self::is_in_schedule( $mission, $now ) ) {
			return MissionResult::REASON_OUT_OF_SCHEDULE;
		}

		if ( $mission->target() < 0 ) {
			return MissionResult::REASON_INVALID_TARGET;
		}

		return MissionResult::REASON_NONE;
	}

	/**
	 * The Phase 32 customer/order/cart/shipping condition checks.
	 *
	 * Runs after the shared eligibility pre-checks. Every check is derived
	 * from the CartContext snapshot (user id, guest flag, applied coupons,
	 * shipping zone, item count) plus WooCommerce customer-history lookups
	 * for first-order/VIP missions, so the engine stays request-state-free.
	 *
	 * @param Mission        $mission    Mission.
	 * @param CartContext $context Cart snapshot.
	 * @return string MissionResult::REASON_* constant (REASON_NONE = pass).
	 */
	public static function conditions_reason( Mission $mission, CartContext $context ) {
		$roles = $mission->customer_roles();

		if ( ! empty( $roles ) ) {
			if ( $context->is_guest() || ! self::user_has_role( $context->user_id(), $roles ) ) {
				return MissionResult::REASON_CUSTOMER_CONDITIONS;
			}
		}

		$state = $mission->customer_state();

		if ( ! empty( $state ) ) {
			$actual = $context->is_guest() ? 'guest' : 'logged_in';

			if ( ! in_array( $actual, $state, true ) ) {
				return MissionResult::REASON_CUSTOMER_CONDITIONS;
			}
		}

		if ( $mission->is_first_order() ) {
			// Guests are anonymous — their order history is unknowable, so
			// a first-order mission never blocks a guest checkout.
			if ( ! $context->is_guest() && self::customer_order_count( $context->user_id() ) > 0 ) {
				return MissionResult::REASON_FIRST_ORDER_ONLY;
			}
		}

		if ( $mission->is_vip() ) {
			if ( $context->is_guest()
				|| self::customer_total_spent( $context->user_id() ) < (float) $mission->vip_min_spend()
				|| self::customer_order_count( $context->user_id() ) < (int) $mission->vip_min_orders() ) {
				return MissionResult::REASON_VIP_ONLY;
			}
		}

		$zones = $mission->shipping_zones();

		if ( ! empty( $zones ) && ! in_array( (int) $context->shipping_zone_id(), array_map( 'intval', $zones ), true ) ) {
			return MissionResult::REASON_SHIPPING_ZONE;
		}

		$coupons = $mission->cart_coupons();

		if ( ! empty( $coupons ) && 0 === count( array_intersect( $coupons, $context->coupons() ) ) ) {
			return MissionResult::REASON_CART_CONDITIONS;
		}

		if ( (int) $mission->cart_min_items() > 0 && $context->total_quantity() < (int) $mission->cart_min_items() ) {
			return MissionResult::REASON_CART_CONDITIONS;
		}

		return MissionResult::REASON_NONE;
	}

	/**
	 * Whether the user has any of the given roles.
	 *
	 * @param int      $user_id User id.
	 * @param string[] $roles   Allowed role slugs.
	 * @return bool
	 */
	protected static function user_has_role( $user_id, array $roles ) {
		if ( ! function_exists( 'get_userdata' ) ) {
			return false;
		}

		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			return false;
		}

		return count( array_intersect( (array) $user->roles, $roles ) ) > 0;
	}

	/**
	 * The customer's completed-order count (processing + completed).
	 *
	 * Bounded to a single id lookup; used by the first-order and VIP
	 * conditions.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	protected static function customer_order_count( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'processing', 'completed' ),
				'limit'       => 1,
				'return'      => 'ids',
				'paginate'    => false,
			)
		);

		return is_array( $orders ) ? count( $orders ) : 0;
	}

	/**
	 * The customer's lifetime spend (paid orders).
	 *
	 * @param int $user_id User id.
	 * @return float
	 */
	protected static function customer_total_spent( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 || ! function_exists( 'wc_get_customer_total_spent' ) ) {
			return 0.0;
		}

		return (float) wc_get_customer_total_spent( $user_id );
	}

	/**
	 * Whether the mission's calendar window AND recurring day/time rules
	 * contain the given time.
	 *
	 * Phase 32 (advanced scheduling): a mission can additionally restrict to
	 * specific weekdays (1=Mon..7=Sun) and/or a daily time window (the
	 * window may cross midnight — start > end means "after start OR before
	 * end").
	 *
	 * @param Mission        $mission Mission.
	 * @param string|null $now  Reference time, defaults to current_time( 'mysql' ).
	 * @return bool
	 */
	protected static function is_in_schedule( Mission $mission, $now = null ) {
		$starts_at = $mission->starts_at();
		$ends_at   = $mission->ends_at();

		if ( empty( $starts_at ) && empty( $ends_at ) && ! $mission->has_schedule_rules() ) {
			return true;
		}

		$now = null === $now ? current_time( 'mysql' ) : (string) $now;

		if ( ! empty( $starts_at ) && $now < $starts_at ) {
			return false;
		}

		if ( ! empty( $ends_at ) && $now > $ends_at ) {
			return false;
		}

		if ( $mission->has_schedule_rules() ) {
			$timestamp = strtotime( $now );
			$days      = $mission->schedule_days();

			if ( ! empty( $days ) && ! in_array( (int) date( 'N', $timestamp ), array_map( 'intval', $days ), true ) ) {
				return false;
			}

			$start = $mission->schedule_start_time();
			$end   = $mission->schedule_end_time();

			if ( '' !== $start || '' !== $end ) {
				$time = date( 'H:i', $timestamp );
				$in   = true;

				if ( '' !== $start && $time < $start ) {
					$in = false;
				}

				if ( '' !== $end && $time > $end ) {
					$in = false;
				}

				// A window that crosses midnight (start > end) means "after
				// start OR before end" — e.g. 22:00–06:00.
				if ( '' !== $start && '' !== $end && $start > $end ) {
					$in = $time >= $start || $time <= $end;
				}

				if ( ! $in ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * The evaluator registry (exposed for extension).
	 *
	 * @return MissionEvaluatorRegistry
	 */
	public function registry() {
		return $this->registry;
	}
}
