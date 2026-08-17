<?php
/**
 * Unified product recommendation engine for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart\Recommendations;

use FaraCart\Analytics\UpsellRanker;
use FaraCart\Missions\CartContext;
use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionResult;
use FaraCart\Suggestions\SuggestionEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Class ProductRecommendationEngine
 *
 * The single customer-facing recommendation layer. It preserves BOTH
 * internal recommendation strategies — the Phase 14 SuggestionEngine
 * (mission-gap product suggestions) and the Phase 33.5 UpsellRanker (six
 * normalized component scorers over the same candidates) — and merges
 * them into ONE ranked, deduplicated list that the storefront renders as
 * a single panel. The customer never sees "suggestions" vs "upsells":
 *
 *   Suggestion Engine ──┐
 *                       ↓
 *   Upsell Ranker ──────┤→ Candidate Pool → Normalize → Deduplicate →
 *                       ↓
 *   Score / Rank → Business Filters → Limit → Unified items
 *
 * Pipeline (per mission, per request):
 *
 *  1. Generate — run SuggestionEngine::suggest() (already filtered and
 *     capped by its own rules) and UpsellRanker::rank() on the same
 *     mission + cart context.
 *  2. Normalize — both halves are reduced to one candidate shape; every
 *     product is scored on the SAME 0–100 scale. Upsell candidates keep
 *     their composite ranker score; suggestion-only candidates are
 *     scored through the ranker's public score_product() (the shared,
 *     normalized scorer — never two incompatible scales compared
 *     directly).
 *  3. Deduplicate — a product present in both halves appears exactly
 *     once with source 'both' (its upsell score is the merged score; the
 *     stronger signal wins).
 *  4. Rank — score desc; ties → lower price, then product id
 *     (deterministic, mirrors the ranker).
 *  5. Limit — the same configurable limit the storefront upsell panel
 *     uses (faracart_frontend_upsell_limit, 1–6, default 3). Weak
 *     candidates are never invented to fill slots: fewer strong
 *     candidates → fewer items.
 *  6. Shape — customer-facing fields plus `source` (suggestion | upsell |
 *     both) and `score` so the storefront can preserve source
 *     attribution in its existing tracking funnels without exposing it
 *     to the shopper.
 *
 * Business rules come from the two engines unchanged: published +
 * priced + in-stock products only, cart items and mission-excluded products
 * never recommended, quantity missions degrade to suggestion-only. The
 * whole pipeline is pure — no writes, no cache churn — and rides inside
 * the already-cached progress payload, so the storefront makes no extra
 * recommendation request.
 *
 * Extensibility: the final list is filterable via the existing
 * `faracart_suggestions` developer API (same signature the Phase 14
 * engine exposes), so stores keep their custom shaping rules.
 */
final class ProductRecommendationEngine {

	/**
	 * Default maximum products in the unified panel (the same filter the
	 * storefront upsell panel uses — one configurable limit, not two).
	 *
	 * @var int
	 */
	const DEFAULT_LIMIT = 3;

	/**
	 * Clamp bounds for the limit.
	 *
	 * @var int
	 */
	const MIN_LIMIT = 1;
	const MAX_LIMIT = 6;

	/**
	 * Minimum unified score for a candidate to be recommended.
	 *
	 * A candidate that scored 0 under the merged weights contributed no
	 * positive signal (e.g. a live-catalog best seller with no mission or
	 * cart link under relevance-only weights) — it must never pad the
	 * panel. Fewer strong candidates → fewer items, never invented
	 * fillers.
	 *
	 * @var float
	 */
	const MIN_SCORE = 0.0;

	/**
	 * Suggestion engine (Phase 14) — the mission-gap candidate half.
	 *
	 * @var SuggestionEngine
	 */
	protected $suggestions;

	/**
	 * Upsell ranker (Phase 33.5) — the normalized 0–100 scorer + the
	 * richer candidate half. Its public score_product() scores the
	 * suggestion-only candidates on the SAME scale.
	 *
	 * @var UpsellRanker
	 */
	protected $upsells;

	/**
	 * Constructor.
	 *
	 * @param SuggestionEngine $suggestions Suggestion engine.
	 * @param UpsellRanker     $upsells     Upsell ranker.
	 */
	public function __construct( SuggestionEngine $suggestions, UpsellRanker $upsells ) {
		$this->suggestions = $suggestions;
		$this->upsells     = $upsells;
	}

	/**
	 * Recommend products for one mission on the current cart context.
	 *
	 * Deterministic: the same mission + cart + catalog always yields the
	 * same ranked list. Never writes anything. Returns an empty array
	 * (never a fabricated list) when the mission is completed, ineligible,
	 * the gap is closed, or no candidate survives the business filters.
	 *
	 * @param Mission        $mission    Mission being recommended for.
	 * @param MissionResult  $result  Evaluation result (current/target/remaining).
	 * @param CartContext $context Cart snapshot.
	 * @return array<int, array<string, mixed>> Unified items, ranked.
	 */
	public function recommend( Mission $mission, MissionResult $result, CartContext $context ) {
		// A closed gap (completed / ineligible / nothing left) has no
		// recommendation to make — the suggestion engine agrees.
		if ( ! $result->eligible() || $result->completed() || $result->remaining() <= 0 ) {
			return array();
		}

		// 1. Generate candidates from BOTH engines (each already applies
		// its own business filters: stock, price, cart + mission exclusions).
		$suggestion_items = $this->suggestions->suggest( $mission, $result, $context );
		$upsell_items     = $this->upsell_items( $mission, $result, $context );

		// 2–3. Normalize + deduplicate by product id. The upsell half is
		// merged first so a product in both halves keeps the ranker's
		// composite score (the unified 0–100 signal) with source 'both'.
		$unified = array();

		foreach ( $upsell_items as $item ) {
			$id = (int) ( isset( $item['product_id'] ) ? $item['product_id'] : 0 );

			if ( $id < 1 ) {
				continue;
			}

			$unified[ $id ] = array(
				'source' => 'upsell',
				'item'   => $item,
			);
		}

		foreach ( $suggestion_items as $item ) {
			$id = (int) ( isset( $item['id'] ) ? $item['id'] : 0 );

			if ( $id < 1 ) {
				continue;
			}

			if ( isset( $unified[ $id ] ) ) {
				$unified[ $id ]['source'] = 'both';
			} else {
				$unified[ $id ] = array(
					'source' => 'suggestion',
					'item'   => $item,
				);
			}
		}

		if ( empty( $unified ) ) {
			return array();
		}

		$remaining = (float) $result->remaining();
		$cart_ids  = $this->cart_ids( $context );

		// 4. Score every candidate on one 0–100 scale, then rank.
		$candidates = array();

		foreach ( $unified as $id => $entry ) {
			$item  = $entry['item'];
			$score = 0.0;

			if ( 'suggestion' === $entry['source'] ) {
				// Suggestion-only: score through the shared normalized
				// scorer so it ranks on the SAME scale as the upsell half.
				$product = $this->product( $id );

				if ( null === $product ) {
					continue;
				}

				$scored = $this->upsells->score_product(
					$product,
					isset( $item['source'] ) ? (string) $item['source'] : UpsellRanker::SOURCE_POPULAR,
					$remaining,
					$cart_ids,
					$mission
				);

				$item  = $scored;
				$score = (float) $scored['score'];
			} else {
				$score = isset( $item['score'] ) ? (float) $item['score'] : 0.0;
			}

			$candidates[] = array(
				'id'     => $id,
				'score'  => $score,
				'source' => $entry['source'],
				'item'   => $item,
			);
		}

		if ( empty( $candidates ) ) {
			return array();
		}

		// Weak-candidate floor: drop everything that scored 0 so the
		// panel is never padded with no-signal fillers (e.g. live-catalog
		// best sellers with no mission/cart link under relevance-only
		// weights). This is the "never invent fillers" guarantee — the
		// limit caps, it never fabricates.
		$candidates = array_values( array_filter( $candidates, function ( $candidate ) {
			return (float) $candidate['score'] > self::MIN_SCORE;
		} ) );

		if ( empty( $candidates ) ) {
			return array();
		}

		usort( $candidates, function ( $a, $b ) {
			if ( abs( $a['score'] - $b['score'] ) > 0.0001 ) {
				return $a['score'] > $b['score'] ? -1 : 1;
			}

			$a_price = isset( $a['item']['price'] ) ? (float) $a['item']['price'] : 0.0;
			$b_price = isset( $b['item']['price'] ) ? (float) $b['item']['price'] : 0.0;

			if ( abs( $a_price - $b_price ) > 0.0001 ) {
				return $a_price < $b_price ? -1 : 1;
			}

			return (int) $a['id'] <=> (int) $b['id'];
		} );

		// 5. Limit — never pad with weak candidates.
		$candidates = array_slice( $candidates, 0, $this->limit() );

		// 6. Shape the customer-facing items (source stays for the
		// storefront's existing tracking funnels).
		$items = array();

		foreach ( $candidates as $candidate ) {
			$items[] = $this->shape( $candidate );
		}

		/**
		 * Filters the unified recommendations for a mission before they
		 * reach the payload — the same developer API the Phase 14
		 * suggestion engine exposes, now applied to the merged list.
		 *
		 * @param array       $items   Shaped unified items.
		 * @param Mission        $mission    Mission.
		 * @param MissionResult  $result  Evaluation result.
		 * @param CartContext $context Cart snapshot.
		 */
		return (array) apply_filters( 'faracart_suggestions', $items, $mission, $result, $context );
	}

	/**
	 * The upsell half of the pool (Phase 33.5 ranker).
	 *
	 * Only money missions with a positive remaining gap produce upsell
	 * candidates; the ranker's own gate (master enabled + analytics +
	 * faracart_upsells_enabled) is respected, so a store that disabled
	 * smart upsells simply gets the suggestion half.
	 *
	 * @param Mission        $mission    Mission.
	 * @param MissionResult  $result  Evaluation result.
	 * @param CartContext $context Cart snapshot.
	 * @return array<int, array<string, mixed>> Ranked upsell items.
	 */
	protected function upsell_items( Mission $mission, MissionResult $result, CartContext $context ) {
		if ( ! $this->upsells->enabled() || ! $mission->is_money_mission() ) {
			return array();
		}

		$remaining = (float) $result->remaining();

		if ( $remaining <= 0 ) {
			return array();
		}

		$payload = $this->upsells->rank(
			array(
				// Hand the in-memory Mission over directly: the ranker's
				// manual-source candidates and relevance scoring need the
				// mission's own products even when the mission is fresh/unpersisted
				// (the id-only path resolves from the repository instead).
				'mission'       => $mission,
				'mission_id'    => $mission->id(),
				'cart_value' => (float) $result->current(),
				'remaining'  => $remaining,
				'cart'       => $this->cart_ids( $context ),
				'exclude'    => $mission->excluded_products(),
				'limit'      => $this->limit(),
			)
		);

		return isset( $payload['recommendations'] ) && is_array( $payload['recommendations'] )
			? $payload['recommendations']
			: array();
	}

	/**
	 * Shape one merged candidate into the payload item.
	 *
	 * `id` keeps the suggestion-stream contract (mission.suggestions[].id);
	 * `product_id` feeds the storefront add-to-cart flow; `source` and
	 * `score` preserve the attribution/analytics signal without exposing
	 * it to the shopper.
	 *
	 * @param array<string, mixed> $candidate Merged candidate.
	 * @return array<string, mixed>
	 */
	protected function shape( array $candidate ) {
		$item = $candidate['item'];
		$id   = (int) $candidate['id'];

		return array(
			'id'           => $id,
			'product_id'   => $id,
			'name'         => isset( $item['name'] ) ? (string) $item['name'] : '',
			'permalink'    => isset( $item['permalink'] ) ? (string) $item['permalink'] : '',
			'price'        => isset( $item['price'] ) && null !== $item['price'] ? round( (float) $item['price'], 4 ) : null,
			'price_html'   => isset( $item['price_html'] ) ? (string) $item['price_html'] : '',
			'image'        => isset( $item['image'] ) ? (string) $item['image'] : '',
			'stock_status' => isset( $item['stock_status'] ) ? (string) $item['stock_status'] : '',
			'source'       => (string) $candidate['source'],
			'score'        => round( (float) $candidate['score'], 2 ),
		);
	}

	/**
	 * The configurable recommendation limit (1–6, default 3) — the same
	 * filter the storefront upsell panel config uses, so there is ONE
	 * limit, not a second setting.
	 *
	 * @return int
	 */
	protected function limit() {
		$limit = (int) apply_filters( 'faracart_frontend_upsell_limit', self::DEFAULT_LIMIT );

		return max( self::MIN_LIMIT, min( self::MAX_LIMIT, $limit ) );
	}

	/**
	 * Product ids in the cart (never recommended again) — mirrors the
	 * suggestion engine's exclusion set.
	 *
	 * @param CartContext $context Cart snapshot.
	 * @return int[]
	 */
	protected function cart_ids( CartContext $context ) {
		$ids = array();

		foreach ( $context->items() as $item ) {
			$id = (int) $item->product_id();

			if ( $id > 0 ) {
				$ids[] = $id;
			}

			$effective = (int) $item->effective_product_id();

			if ( $effective > 0 ) {
				$ids[] = $effective;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Load one product (null when missing/unpublished).
	 *
	 * @param int $id Product id.
	 * @return \WC_Product|null
	 */
	protected function product( $id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( (int) $id );

		return $product && 'publish' === $product->get_status() ? $product : null;
	}
}
