<?php
/**
 * Free shipping reward applicator.
 *
 * @package FaraCart
 */

namespace FaraCart\Rewards\Applicators;

use FaraCart\Missions\CartContext;
use FaraCart\Missions\MissionResult;
use FaraCart\Rewards\Reward;
use FaraCart\Rewards\RewardApplicator;
use FaraCart\Rewards\RewardResult;

defined( 'ABSPATH' ) || exit;

/**
 * Class FreeShippingApplicator
 *
 * Waives shipping costs when the mission is met, applied through WooCommerce's
 * public 'woocommerce_package_rates' filter so it composes with existing
 * shipping zones and methods (P05-T02).
 *
 * Compatibility with existing shipping rules:
 *  - rates are only modified while a free-shipping reward is active; when
 *    the mission is incomplete every rate passes through untouched
 *  - the reward can be restricted to specific shipping zones and/or method
 *    instances (e.g. 'flat_rate' or 'flat_rate:3'); with no restrictions
 *    every rate in the package becomes free
 *  - the store's own free_shipping method settings are never altered
 *
 * The applicator is stateless: the RewardEngine evaluates mission completion
 * once per request and calls apply_to_rates() inside the package filter.
 */
final class FreeShippingApplicator implements RewardApplicator {

	/**
	 * {@inheritDoc}
	 */
	public function supports( $type ) {
		return Reward::TYPE_FREE_SHIPPING === $type;
	}

	/**
	 * {@inheritDoc}
	 */
	public function evaluate( Reward $reward, MissionResult $result, ?CartContext $context = null ) {
		return RewardResult::available(
			$reward,
			$result->mission()->id(),
			0.0,
			array(
				'shipping_zone_ids'   => $reward->shipping_zone_ids(),
				'shipping_method_ids' => $reward->shipping_method_ids(),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Free shipping is applied statelessly through the package-rates filter
	 * (apply_to_rates), so there is nothing to attach here.
	 */
	public function apply( Reward $reward, RewardResult $evaluation, \WC_Cart $cart, $mission_id ) {
		return true;
	}

	/**
	 * Zero out the eligible shipping rates for a package.
	 *
	 * @param array<string, \WC_Shipping_Rate> $rates   Shipping rates.
	 * @param array<string, mixed>             $package Shipping package.
	 * @param Reward[]                         $rewards Active free-shipping rewards.
	 * @return array<string, \WC_Shipping_Rate>
	 */
	public function apply_to_rates( $rates, array $package, array $rewards ) {
		$rewards = $this->matching_rewards( $rewards, $package );

		if ( empty( $rewards ) || ! is_array( $rates ) ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			if ( ! $this->method_allowed( $rate, $rewards ) ) {
				continue;
			}

			$rate->cost  = 0;
			$rate->taxes = array();
		}

		return $rates;
	}

	/**
	 * Rewards whose shipping-zone restrictions match the package.
	 *
	 * @param Reward[]                 $rewards Active rewards.
	 * @param array<string, mixed>     $package Shipping package.
	 * @return Reward[]
	 */
	protected function matching_rewards( array $rewards, array $package ) {
		$matching = array();

		foreach ( $rewards as $reward ) {
			$zone_ids = $reward->shipping_zone_ids();

			if ( empty( $zone_ids ) ) {
				$matching[] = $reward;
				continue;
			}

			if ( in_array( $this->package_zone_id( $package ), $zone_ids, true ) ) {
				$matching[] = $reward;
			}
		}

		return $matching;
	}

	/**
	 * Whether a rate is covered by any of the matching rewards.
	 *
	 * @param \WC_Shipping_Rate $rate    Shipping rate.
	 * @param Reward[]          $rewards Matching rewards.
	 * @return bool
	 */
	protected function method_allowed( $rate, array $rewards ) {
		foreach ( $rewards as $reward ) {
			$specs = $reward->shipping_method_ids();

			if ( empty( $specs ) ) {
				return true;
			}

			foreach ( $specs as $spec ) {
				$parts    = explode( ':', (string) $spec );
				$method   = $parts[0];
				$instance = isset( $parts[1] ) ? (int) $parts[1] : 0;

				if ( $rate->get_method_id() === $method && ( 0 === $instance || (int) $rate->get_instance_id() === $instance ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Resolve the shipping zone id matching the package destination.
	 *
	 * @param array<string, mixed> $package Shipping package.
	 * @return int 0 when no zone matches (e.g. WooCommerce not loaded).
	 */
	protected function package_zone_id( array $package ) {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! method_exists( 'WC_Shipping_Zones', 'get_zone_matching_package' ) ) {
			return 0;
		}

		$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );

		return $zone ? (int) $zone->get_id() : 0;
	}
}
