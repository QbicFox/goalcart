<?php
/**
 * Dynamic message template engine for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\Goals;

defined( 'ABSPATH' ) || exit;

/**
 * Class MessageEngine
 *
 * Phase 13 (Dynamic Messaging) — a reusable, UI-independent message
 * template engine. Given a Goal + GoalResult it decides the message
 * state (inactive / unavailable / progressing / nearly complete /
 * completed / reward activated) and renders a localized message from a
 * template, substituting variables such as:
 *
 * ```text
 * {current}  {target}  {remaining}  {percentage}
 * {quantity} {remaining_quantity}  {reward}  {goal_name}  {campaign_name}
 * ```
 *
 * Template selection: the goal's Display settings
 * (`display_settings.message` for progress, `display_settings.completed_message`
 * for completion) win when set; otherwise a localized default per state
 * applies. Unknown placeholders are left untouched (never a throw), and
 * every value is formatted locale-aware (currency via `wc_price` when
 * WooCommerce is active, plain numbers via `number_format_i18n`).
 *
 * The engine is stateless and database-free (Phase 4 contract) — callers
 * supply the Goal + GoalResult. The frontend controller (Phase 7) renders
 * every progress message through this service.
 */
final class MessageEngine {

	/**
	 * Message states.
	 */
	const STATE_INACTIVE          = 'inactive';
	const STATE_UNAVAILABLE       = 'unavailable';
	const STATE_PROGRESSING       = 'progressing';
	const STATE_NEARLY_COMPLETE   = 'nearly_complete';
	const STATE_COMPLETED         = 'completed';
	const STATE_REWARD_ACTIVATED  = 'reward_activated';
	const STATE_COMPLETION_LIMIT  = 'completion_limit_reached';

	/**
	 * The progress percentage at or above which a goal is "nearly complete".
	 *
	 * @var float
	 */
	const NEARLY_COMPLETE_PERCENTAGE = 80.0;

	/**
	 * All supported placeholder variables, in substitution order.
	 *
	 * @var string[]
	 */
	const VARIABLES = array(
		'current',
		'target',
		'remaining',
		'percentage',
		'quantity',
		'remaining_quantity',
		'reward',
		'goal_name',
		'campaign_name',
	);

	/**
	 * The message state for a goal evaluation.
	 *
	 * State semantics (P13-T03):
	 *  - inactive          goal is not active (status/campaign folded)
	 *  - unavailable       goal cannot apply to this cart/shopper
	 *  - completion_limit_reached
	 *                      the shopper already completed this goal the
	 *                      configured maximum number of times (Phase 36)
	 *  - progressing       eligible, target not reached, below the
	 *                      "nearly complete" threshold
	 *  - nearly_complete   eligible, >= NEARLY_COMPLETE_PERCENTAGE
	 *  - completed         target reached, no reward configured
	 *  - reward_activated  target reached and a reward is configured
	 *
	 * @param Goal                     $goal       Goal.
	 * @param GoalResult               $result     Evaluation result.
	 * @param array<string, mixed>|null $completion Optional completion status
	 *                      (completion_limit / completion_count /
	 *                      remaining_completions / can_complete, Phase 36);
	 *                      when it says the shopper cannot complete the goal
	 *                      again, the limit state wins over every
	 *                      progress/completion state (the goal is no longer
	 *                      actionable for them).
	 * @return string One of the STATE_* constants.
	 */
	public function state( Goal $goal, GoalResult $result, $completion = null ) {
		if ( ! $result->eligible() ) {
			return GoalResult::REASON_GOAL_INACTIVE === $result->reason()
				? self::STATE_INACTIVE
				: self::STATE_UNAVAILABLE;
		}

		if ( is_array( $completion ) && empty( $completion['can_complete'] ) ) {
			return self::STATE_COMPLETION_LIMIT;
		}

		if ( $result->completed() ) {
			return empty( $goal->reward_type() )
				? self::STATE_COMPLETED
				: self::STATE_REWARD_ACTIVATED;
		}

		return $result->percentage() >= self::NEARLY_COMPLETE_PERCENTAGE
			? self::STATE_NEARLY_COMPLETE
			: self::STATE_PROGRESSING;
	}

	/**
	 * Render the message for a goal evaluation.
	 *
	 * @param Goal                     $goal       Goal.
	 * @param GoalResult               $result     Evaluation result.
	 * @param array<string, mixed>     $extra      Extra variables (quantity,
	 *                                      remaining_quantity, campaign_name
	 *                                      overrides). Values may be ints,
	 *                                      floats or strings; formatted by
	 *                                      the engine.
	 * @param array<string, mixed>|null $completion Optional completion status
	 *                                      (Phase 36) forwarded to state().
	 * @return string
	 */
	public function message( Goal $goal, GoalResult $result, array $extra = array(), $completion = null ) {
		$state    = $this->state( $goal, $result, $completion );
		$template = $this->template( $goal, $state );

		return $this->render( $template, $goal, $result, $extra );
	}

	/**
	 * The message template for a state.
	 *
	 * The goal's Display settings override the per-state defaults:
	 * `display_settings.message` drives progress copy (progressing +
	 * nearly complete), `display_settings.completed_message` drives
	 * completion copy (completed + reward activated).
	 *
	 * @param Goal   $goal  Goal.
	 * @param string $state STATE_* constant.
	 * @return string
	 */
	public function template( Goal $goal, $state ) {
		$display = $goal->display_settings();
		$message = isset( $display['message'] ) && is_string( $display['message'] ) ? trim( $display['message'] ) : '';
		$done    = isset( $display['completed_message'] ) && is_string( $display['completed_message'] ) ? trim( $display['completed_message'] ) : '';

		switch ( $state ) {
			case self::STATE_INACTIVE:
				return __( 'This offer is not active right now.', 'goalcart' );

			case self::STATE_UNAVAILABLE:
				return __( 'This offer is not available for your cart.', 'goalcart' );

			// Phase 36 (per-user completion limit): the shopper already
			// completed this goal the configured maximum number of times —
			// the plain "you've done this" copy, never the reward-unlocked
			// claim (no reward can be granted again). The per-goal Display
			// `completed_message` override deliberately does NOT apply here:
			// it celebrates a fresh completion, which this is not.
			case self::STATE_COMPLETION_LIMIT:
				return __( 'You have already completed this goal.', 'goalcart' );

			case self::STATE_NEARLY_COMPLETE:
				return '' !== $message
					? $message
					: __( 'Almost there! Only {remaining} left', 'goalcart' );

			case self::STATE_COMPLETED:
			case self::STATE_REWARD_ACTIVATED:
				if ( '' !== $done ) {
					return $done;
				}

				if ( self::STATE_REWARD_ACTIVATED === $state ) {
					return __( 'Reward unlocked: {reward}', 'goalcart' );
				}

				return __( 'You reached your goal!', 'goalcart' );

			case self::STATE_PROGRESSING:
			default:
				return '' !== $message
					? $message
					: __( 'Only {remaining} left to reach your goal', 'goalcart' );
		}
	}

	/**
	 * The variable map for a goal evaluation.
	 *
	 * Money-based goals format current/target/remaining as currency; every
	 * other basis (quantity, weight, distinct quantity) formats plain
	 * locale-aware numbers. `quantity` / `remaining_quantity` come from the
	 * optional extra values, or fall back to the current/remaining for
	 * quantity-mode goals. `campaign_name` comes from the goal (the
	 * repository folds it in) or an extra override.
	 *
	 * @param Goal                  $goal   Goal.
	 * @param GoalResult            $result Evaluation result.
	 * @param array<string, mixed>  $extra  Extra variables.
	 * @return array<string, string> Placeholder => formatted value.
	 */
	public function variables( Goal $goal, GoalResult $result, array $extra = array() ) {
		$is_money = $this->is_money_goal( $goal );

		$format = function ( $value ) use ( $is_money ) {
			return $this->format_number( (float) $value, $is_money );
		};

		$quantity = isset( $extra['quantity'] ) ? $extra['quantity'] : null;
		$remaining_quantity = isset( $extra['remaining_quantity'] ) ? $extra['remaining_quantity'] : null;

		if ( Goal::MODE_QUANTITY === $goal->calculation_mode() ) {
			$quantity           = null === $quantity ? $result->current() : $quantity;
			$remaining_quantity = null === $remaining_quantity ? $result->remaining() : $remaining_quantity;
		}

		$campaign_name = (string) $goal->campaign_name();

		if ( '' === $campaign_name && isset( $extra['campaign_name'] ) ) {
			$campaign_name = (string) $extra['campaign_name'];
		}

		return array(
			'current'            => $format( $result->current() ),
			'target'             => $format( $result->target() ),
			'remaining'          => $format( $result->remaining() ),
			'percentage'         => (string) number_format_i18n( $result->percentage(), 0 ),
			'quantity'           => $this->format_number( (float) $quantity, false ),
			'remaining_quantity' => $this->format_number( (float) $remaining_quantity, false ),
			'reward'             => $this->reward_label( $goal ),
			'goal_name'          => $goal->name(),
			'campaign_name'      => $campaign_name,
		);
	}

	/**
	 * Substitute the known placeholders in a template.
	 *
	 * Unknown placeholders (typos, future variables) are left as-is so a
	 * template can never render empty tokens the author did not intend.
	 *
	 * @param string                $template Template with {placeholders}.
	 * @param Goal                  $goal     Goal.
	 * @param GoalResult            $result   Evaluation result.
	 * @param array<string, mixed>  $extra    Extra variables.
	 * @return string
	 */
	public function render( $template, Goal $goal, GoalResult $result, array $extra = array() ) {
		$template = (string) $template;

		if ( '' === $template ) {
			return '';
		}

		$variables = $this->variables( $goal, $result, $extra );

		// Only the documented VARIABLES set is ever substituted, so a
		// template can never pick up an undocumented placeholder.
		$search  = array();
		$replace = array();

		foreach ( self::VARIABLES as $key ) {
			if ( ! array_key_exists( $key, $variables ) ) {
				continue;
			}

			$search[]  = '{' . $key . '}';
			$replace[] = (string) $variables[ $key ];
		}

		return str_replace( $search, $replace, $template );
	}

	/**
	 * The localized reward label for a goal.
	 *
	 * Value-aware where it reads naturally ("10% discount", "Fixed $20
	 * off"); falls back to a bare type label for rewards without a value.
	 *
	 * @param Goal $goal Goal.
	 * @return string
	 */
	public function reward_label( Goal $goal ) {
		$type  = $goal->reward_type();
		$value = $goal->reward_value();

		switch ( $type ) {
			case 'free_shipping':
				return __( 'Free shipping', 'goalcart' );

			case 'percent_discount':
				return null === $value
					? __( 'Percentage discount', 'goalcart' )
					: sprintf(
						/* translators: %d: discount percentage. */
						__( '%d%% discount', 'goalcart' ),
						(int) round( (float) $value )
					);

			case 'fixed_discount':
				if ( null === $value ) {
					return __( 'Fixed discount', 'goalcart' );
				}

				return sprintf(
					/* translators: %s: formatted discount amount. */
					__( 'Fixed %s off', 'goalcart' ),
					$this->format_number( (float) $value, true )
				);

			case 'free_gift':
				return __( 'Free gift', 'goalcart' );

			case 'coupon':
				return __( 'Coupon', 'goalcart' );

			default:
				return '';
		}
	}

	/**
	 * Whether a goal's progress is measured in money.
	 *
	 * Delegates to the single source of truth (Goal::is_money_goal) so
	 * message numbers and widget labels never drift from the payload flag.
	 *
	 * @param Goal $goal Goal.
	 * @return bool
	 */
	protected function is_money_goal( Goal $goal ) {
		return $goal->is_money_goal();
	}

	/**
	 * Format a number: currency when money, plain locale number otherwise.
	 *
	 * Money values use `wc_price` so the store's price format, position
	 * and currency symbol apply. The result is plain text (messages and
	 * reward labels are inserted into the DOM via `textContent`, never
	 * parsed as HTML), so the symbol's markup must be stripped AND its
	 * entities decoded — WooCommerce ships symbols like the IRT
	 * "\u062A\u0648\u0645\u0627\u0646" as an HTML entity
	 * (`&#x062A;&#x0648;&#x0645;&#x0627;&#x0646;`), which would otherwise
	 * render to the shopper as literal entity text.
	 *
	 * @param float  $value   Number.
	 * @param bool   $is_money Whether to format as currency.
	 * @return string
	 */
	protected function format_number( $value, $is_money ) {
		if ( $is_money && function_exists( 'wc_price' ) ) {
			return html_entity_decode(
				wp_strip_all_tags( wc_price( (float) $value ) ),
				ENT_QUOTES,
				'UTF-8'
			);
		}

		// Currency without WooCommerce: 2 decimals, locale-aware.
		$decimals = $is_money ? 2 : 0;

		return (string) number_format_i18n( (float) $value, $decimals );
	}
}
