<?php
/**
 * Smart product suggestion engine for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\Suggestions;

use GoalCart\Goals\CartContext;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalResult;

defined( 'ABSPATH' ) || exit;

/**
 * Class SuggestionEngine
 *
 * Phase 14 (Smart Product Suggestions) — turns goal progress into product
 * recommendations that close the gap. Given a Goal + GoalResult + the
 * CartContext it gathers candidate products from six sources, filters to
 * suggestible products (published, in stock, priced, not already in the
 * cart), ranks them by goal eligibility, relevance, WooCommerce-endorsed
 * sources and price proximity to the remaining amount, and returns a
 * capped list for the frontend payload.
 *
 * Sources (P14-T02), in candidate-gathering order:
 *
 *   1. manual          — the goal's own `products` (they count toward it)
 *   2. category        — products in the goal's `categories` (category goals)
 *   3. upsell          — `_upsell_ids` of the cart's items
 *   4. cross_sell      — `_crosssell_ids` of the cart's items
 *   5. related         — wc_get_related_products() of the cart's items
 *   6. recently_viewed — the shopper's `woocommerce_recently_viewed` cookie
 *   7. best_seller     — top by total_sales (fallback filler, ranks low)
 *
 * Ranking (P14-T03): stock availability filters first; then a score is
 * built from goal eligibility (explicit products +3, goal category +2,
 * shares a cart item's category +1, WC-endorsed source +0.5) and, for
 * money goals, price proximity to `remaining` — products priced in the
 * 0.6–1.4× band score +2 (the spec's "prefer 150K–220K when 180K is
 * left"), cheaper items +0.75, and far-away expensive items nothing.
 *
 * Suggestions only make sense while there is a gap to close: completed or
 * ineligible goals return an empty list. The final list is filterable via
 * `goalcart_suggestions` (Phase 28 developer API). Margin-aware and
 * AI-ranked recommendations remain roadmap futures (P14-T05).
 *
 * Queries are bounded (per-source limits, one batched include-load) and
 * products are memoized per request; the engine is stateless between
 * requests.
 */
final class SuggestionEngine {

	/**
	 * Maximum suggestions returned per goal.
	 *
	 * @var int
	 */
	const MAX_SUGGESTIONS = 4;

	/**
	 * Hard cap on candidates gathered before scoring (bounds queries).
	 *
	 * @var int
	 */
	const MAX_CANDIDATES = 40;

	/**
	 * Price-proximity band around the remaining amount (ratio).
	 *
	 * @var float
	 */
	const PRICE_MIN_RATIO = 0.6;

	/**
	 * @var float
	 */
	const PRICE_MAX_RATIO = 1.4;

	/**
	 * Recommendation sources.
	 */
	const SOURCE_MANUAL          = 'manual';
	const SOURCE_CATEGORY        = 'category';
	const SOURCE_UPSELL          = 'upsell';
	const SOURCE_CROSS_SELL      = 'cross_sell';
	const SOURCE_RELATED         = 'related';
	const SOURCE_RECENTLY_VIEWED = 'recently_viewed';
	const SOURCE_BEST_SELLER     = 'best_seller';

	/**
	 * Request-level product cache (id => WC_Product).
	 *
	 * @var array<int, \WC_Product>
	 */
	protected $loaded = array();

	/**
	 * Suggest products that help reach the goal.
	 *
	 * @param Goal       $goal    Goal.
	 * @param GoalResult $result  Evaluation result.
	 * @param CartContext $context Cart snapshot.
	 * @return array<int, array<string, mixed>> Suggested products, ranked.
	 */
	public function suggest( Goal $goal, GoalResult $result, CartContext $context ) {
		if ( ! $result->eligible() || $result->completed() || $result->remaining() <= 0 ) {
			return array();
		}

		$candidates = $this->candidates( $goal, $context );

		if ( empty( $candidates ) ) {
			return array();
		}

		$in_cart    = $this->cart_product_ids( $context );
		$is_money   = $goal->is_money_goal();
		$remaining  = (float) $result->remaining();
		$excluded   = array_flip( $goal->excluded_products() );

		$scored = array();

		foreach ( $this->load_products( array_keys( $candidates ) ) as $id => $product ) {
			if ( isset( $excluded[ $id ] ) || isset( $in_cart[ $id ] ) ) {
				continue;
			}

			if ( ! $this->is_suggestible( $product ) ) {
				continue;
			}

			$scored[] = array(
				'product' => $product,
				'source'  => $candidates[ $id ],
				'score'   => $this->score( $product, $goal, $context, $candidates[ $id ], $is_money, $remaining ),
			);
		}

		// Rank: score desc, then id asc (deterministic).
		usort( $scored, function ( $a, $b ) {
			if ( abs( $a['score'] - $b['score'] ) > 0.0001 ) {
				return $a['score'] > $b['score'] ? -1 : 1;
			}

			return $a['product']->get_id() <=> $b['product']->get_id();
		} );

		$items = array();

		foreach ( array_slice( $scored, 0, self::MAX_SUGGESTIONS ) as $entry ) {
			$items[] = $this->shape( $entry['product'], $entry['source'] );
		}

		/**
		 * Filters the suggestions for a goal before they reach the payload.
		 *
		 * @param array      $items   Shaped suggestion items.
		 * @param Goal       $goal    Goal.
		 * @param GoalResult $result  Evaluation result.
		 * @param CartContext $context Cart snapshot.
		 */
		return (array) apply_filters( 'goalcart_suggestions', $items, $goal, $result, $context );
	}

	/**
	 * Gather candidate product ids from every source, deduped.
	 *
	 * Each id keeps its first (highest-priority) source for the badge.
	 *
	 * @param Goal        $goal    Goal.
	 * @param CartContext $context Cart snapshot.
	 * @return array<int, string> Product id => source key.
	 */
	protected function candidates( Goal $goal, CartContext $context ) {
		$candidates = array();

		$add = function ( array $ids, $source ) use ( &$candidates ) {
			foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
				if ( $id > 0 && ! isset( $candidates[ $id ] ) ) {
					$candidates[ $id ] = $source;
				}
			}
		};

		// 1. Manual: the goal's own products count toward it.
		$add( $goal->products(), self::SOURCE_MANUAL );

		// 2. Category goals: products inside the goal's categories.
		if ( ! empty( $goal->categories() ) ) {
			$add( $this->category_product_ids( $goal->categories() ), self::SOURCE_CATEGORY );
		}

		// 3–5. Cart items' upsells, cross-sells and related products.
		foreach ( $context->items() as $item ) {
			$product = $this->get( $item->product_id() );

			if ( ! $product ) {
				continue;
			}

			$add( $product->get_upsell_ids(), self::SOURCE_UPSELL );
			$add( $product->get_cross_sell_ids(), self::SOURCE_CROSS_SELL );

			if ( function_exists( 'wc_get_related_products' ) ) {
				$add( wc_get_related_products( $product->get_id(), 5 ), self::SOURCE_RELATED );
			}
		}

		// 6. Recently viewed (shopper's own cookie).
		$add( $this->recently_viewed_ids(), self::SOURCE_RECENTLY_VIEWED );

		// 7. Best sellers — low-scoring fallback filler.
		$add( $this->best_seller_ids(), self::SOURCE_BEST_SELLER );

		return array_slice( $candidates, 0, self::MAX_CANDIDATES, true );
	}

	/**
	 * Score a candidate product.
	 *
	 * @param \WC_Product $product   Product.
	 * @param Goal        $goal      Goal.
	 * @param CartContext $context   Cart snapshot.
	 * @param string      $source    Source key.
	 * @param bool        $is_money  Whether the goal measures money.
	 * @param float       $remaining Remaining amount to the goal.
	 * @return float
	 */
	protected function score( \WC_Product $product, Goal $goal, CartContext $context, $source, $is_money, $remaining ) {
		$score = 0.0;
		$id    = $product->get_id();

		// Manual priority: explicitly selected products rank first.
		if ( in_array( $id, $goal->products(), true ) ) {
			$score += 3.0;
		}

		// Goal eligibility: the product counts toward the goal.
		if ( $this->counts_toward_goal( $product, $goal ) ) {
			$score += 2.0;
		}

		// Relevance: shares a category with something already in the cart.
		if ( $this->shares_cart_category( $product, $context ) ) {
			$score += 1.0;
		}

		// WooCommerce-endorsed sources carry a small trust bonus.
		if ( in_array( $source, array( self::SOURCE_UPSELL, self::SOURCE_CROSS_SELL, self::SOURCE_RELATED ), true ) ) {
			$score += 0.5;
		}

		// Price proximity to the remaining amount (money goals only — a
		// quantity goal has no sensible price target).
		if ( $is_money && $remaining > 0 ) {
			$price = (float) $product->get_price();

			if ( $price > 0 ) {
				$ratio = $price / $remaining;

				if ( $ratio >= self::PRICE_MIN_RATIO && $ratio <= self::PRICE_MAX_RATIO ) {
					$score += 2.0;
				} elseif ( $ratio < self::PRICE_MIN_RATIO ) {
					$score += 0.75; // cheaper than ideal but still helps.
				}
			}
		}

		return $score;
	}

	/**
	 * Whether a product can be suggested at all.
	 *
	 * Stock availability filters first (P14-T03): published, priced and
	 * not sold out. Managing stock with 0 units is treated like out of
	 * stock when the stock status reports it.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	protected function is_suggestible( \WC_Product $product ) {
		if ( 'publish' !== $product->get_status() ) {
			return false;
		}

		if ( 'outofstock' === $product->get_stock_status() ) {
			return false;
		}

		return '' !== $product->get_price();
	}

	/**
	 * Whether the product counts toward the goal (eligibility bonus).
	 *
	 * @param \WC_Product $product Product.
	 * @param Goal        $goal    Goal.
	 * @return bool
	 */
	protected function counts_toward_goal( \WC_Product $product, Goal $goal ) {
		if ( in_array( $product->get_id(), $goal->products(), true ) ) {
			return true;
		}

		if ( empty( $goal->categories() ) ) {
			return false;
		}

		$product_categories = $product->get_category_ids();

		foreach ( $goal->categories() as $category_id ) {
			if ( in_array( (int) $category_id, $product_categories, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the product shares a category with any cart item.
	 *
	 * @param \WC_Product $product Product.
	 * @param CartContext $context Cart snapshot.
	 * @return bool
	 */
	protected function shares_cart_category( \WC_Product $product, CartContext $context ) {
		$cart_categories = array();

		foreach ( $context->items() as $item ) {
			$cart_categories = array_merge( $cart_categories, $item->categories() );
		}

		if ( empty( $cart_categories ) ) {
			return false;
		}

		return count( array_intersect( $product->get_category_ids(), $cart_categories ) ) > 0;
	}

	/**
	 * Flipped set of the cart's product ids (never suggest what is in the
	 * cart already).
	 *
	 * @param CartContext $context Cart snapshot.
	 * @return array<int, true>
	 */
	protected function cart_product_ids( CartContext $context ) {
		$ids = array();

		foreach ( $context->items() as $item ) {
			$ids[ (int) $item->product_id() ] = true;

			$effective = (int) $item->effective_product_id();

			if ( $effective > 0 ) {
				$ids[ $effective ] = true;
			}
		}

		return $ids;
	}

	/**
	 * Shape a product into the payload item.
	 *
	 * The price label is plain text — the storefront inserts it via
	 * `textContent` — so the stripped `wc_price` markup has its entities
	 * decoded too (WooCommerce ships the IRT "\u062A\u0648\u0645\u0627\u0646"
	 * symbol as an entity, which would otherwise render literally).
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $source  Source key.
	 * @return array<string, mixed>
	 */
	protected function shape( \WC_Product $product, $source ) {
		$price = $product->get_price();

		return array(
			'id'           => (int) $product->get_id(),
			'name'         => $product->get_name(),
			'permalink'    => $product->get_permalink(),
			'price'        => '' !== $price ? (float) $price : null,
			'price_html'   => '' !== $price && function_exists( 'wc_price' )
				? html_entity_decode(
					wp_strip_all_tags( wc_price( (float) $price ) ),
					ENT_QUOTES,
					'UTF-8'
				)
				: '',
			'image'        => $this->image_url( $product ),
			'stock_status' => $product->get_stock_status(),
			'source'       => (string) $source,
		);
	}

	/**
	 * The product's gallery thumbnail URL ('' when none).
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	protected function image_url( \WC_Product $product ) {
		$image_id = $product->get_image_id();

		if ( ! $image_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );

		return $url ? $url : '';
	}

	/**
	 * Load products by id in one batched query, memoized per request.
	 *
	 * Missing/deleted ids are skipped (never a throw). Only published
	 * products are returned.
	 *
	 * @param int[] $ids Product ids.
	 * @return array<int, \WC_Product>
	 */
	protected function load_products( array $ids ) {
		$result  = array();
		$missing = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( isset( $this->loaded[ $id ] ) ) {
				$result[ $id ] = $this->loaded[ $id ];
			} elseif ( $id > 0 ) {
				$missing[] = $id;
			}
		}

		if ( ! empty( $missing ) ) {
			$found = array();

			foreach ( $this->query_products( array( 'include' => $missing ) ) as $product ) {
				$found[ $product->get_id() ] = $product;
			}

			// The batched query reads through the wc_product_meta_lookup
			// table, which can lag behind the posts table (bulk imports,
			// direct wp_insert_post, tests). Fall back to the direct data
			// store so candidates are never lost to a stale lookup row.
			foreach ( $missing as $id ) {
				if ( isset( $found[ $id ] ) ) {
					continue;
				}

				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;

				if ( ! $product || 'publish' !== $product->get_status() ) {
					continue;
				}

				$found[ $id ] = $product;
			}

			foreach ( $found as $product ) {
				$this->loaded[ $product->get_id() ] = $product;
				$result[ $product->get_id() ]       = $product;
			}
		}

		return $result;
	}

	/**
	 * Get a single product through the request cache.
	 *
	 * @param int $id Product id.
	 * @return \WC_Product|null
	 */
	protected function get( $id ) {
		$id = (int) $id;

		if ( isset( $this->loaded[ $id ] ) ) {
			return $this->loaded[ $id ];
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;

		if ( ! $product ) {
			return null;
		}

		$this->loaded[ $id ] = $product;

		return $product;
	}

	/**
	 * Product ids inside the given categories (bounded).
	 *
	 * @param int[] $category_ids Category term ids.
	 * @return int[]
	 */
	protected function category_product_ids( array $category_ids ) {
		if ( empty( $category_ids ) ) {
			return array();
		}

		// A taxonomy query reads term relationships directly instead of the
		// wc_product_meta_lookup table, so products created outside WC CRUD
		// (bulk imports, tests) are found too.
		$query = new \WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 20,
				'no_found_rows'  => true,
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => array_map( 'intval', $category_ids ),
					),
				),
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Best-selling product ids (fallback filler).
	 *
	 * @return int[]
	 */
	protected function best_seller_ids() {
		if ( ! class_exists( '\WC_Product_Query' ) ) {
			return array();
		}

		$query = new \WC_Product_Query(
			array(
				'status' => 'publish',
				'limit'  => 10,
				'orderby' => 'popularity',
				'order'  => 'DESC',
				'return' => 'ids',
			)
		);

		return $query->get_products();
	}

	/**
	 * The shopper's recently viewed product ids (from the WC cookie).
	 *
	 * @return int[]
	 */
	protected function recently_viewed_ids() {
		if ( empty( $_COOKIE['woocommerce_recently_viewed'] ) ) {
			return array();
		}

		$ids = json_decode( wp_unslash( $_COOKIE['woocommerce_recently_viewed'] ), true );

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Run a WC_Product_Query and normalize the result to objects.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return \WC_Product[]
	 */
	protected function query_products( array $args ) {
		if ( ! class_exists( '\WC_Product_Query' ) ) {
			return array();
		}

		$args['status'] = 'publish';
		$args['return'] = 'objects';

		$query = new \WC_Product_Query( $args );

		return $query->get_products();
	}
}
