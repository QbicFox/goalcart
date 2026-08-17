<?php
/**
 * Reward applicator registry.
 *
 * @package FaraCart
 */

namespace FaraCart\Rewards;

use FaraCart\Rewards\Applicators\CouponApplicator;
use FaraCart\Rewards\Applicators\FixedDiscountApplicator;
use FaraCart\Rewards\Applicators\FreeGiftApplicator;
use FaraCart\Rewards\Applicators\FreeShippingApplicator;
use FaraCart\Rewards\Applicators\PercentageDiscountApplicator;

defined( 'ABSPATH' ) || exit;

/**
 * Class RewardApplicatorRegistry
 *
 * Maps reward types to applicator classes and resolves them lazily. The
 * class map is filterable through 'faracart_reward_applicator_classes' so
 * stores can register custom reward types without touching the core —
 * mirroring the MissionEvaluatorRegistry pattern from Phase 4.
 */
class RewardApplicatorRegistry {

	/**
	 * Default type => applicator class map.
	 *
	 * @return array<string, string>
	 */
	protected function default_classes() {
		return array(
			Reward::TYPE_FREE_SHIPPING    => FreeShippingApplicator::class,
			Reward::TYPE_PERCENT_DISCOUNT => PercentageDiscountApplicator::class,
			Reward::TYPE_FIXED_DISCOUNT   => FixedDiscountApplicator::class,
			Reward::TYPE_FREE_GIFT        => FreeGiftApplicator::class,
			Reward::TYPE_COUPON           => CouponApplicator::class,
		);
	}

	/**
	 * Resolved applicator instances, cached per type.
	 *
	 * @var array<string, RewardApplicator>
	 */
	protected $cache = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register_defaults();
	}

	/**
	 * Register the built-in applicators, honoring the extension filter.
	 *
	 * @return void
	 */
	protected function register_defaults() {
		$classes = $this->default_classes();

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the reward applicator class map.
			 *
			 * @param array<string, string> $classes Reward type => applicator class.
			 */
			$classes = apply_filters( 'faracart_reward_applicator_classes', $classes );
		}

		foreach ( $classes as $type => $class ) {
			if ( is_string( $class ) && class_exists( $class ) ) {
				$this->cache[ $type ] = new $class();
			}
		}
	}

	/**
	 * Resolve the applicator for a reward type.
	 *
	 * @param string $type Reward type.
	 * @return RewardApplicator
	 * @throws \InvalidArgumentException When no applicator is registered.
	 */
	public function applicator( $type ) {
		$type = (string) $type;

		if ( ! isset( $this->cache[ $type ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'No reward applicator registered for type "%s".', $type )
			);
		}

		return $this->cache[ $type ];
	}

	/**
	 * Whether an applicator is registered for the given type.
	 *
	 * @param string $type Reward type.
	 * @return bool
	 */
	public function supports( $type ) {
		return isset( $this->cache[ (string) $type ] );
	}

	/**
	 * All registered reward types.
	 *
	 * @return string[]
	 */
	public function types() {
		return array_keys( $this->cache );
	}
}
