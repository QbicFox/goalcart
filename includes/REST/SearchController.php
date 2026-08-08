<?php
/**
 * REST controller for product / category / coupon search.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class SearchController
 *
 * Phase 7 (REST API / AJAX Layer) admin search endpoints used by the goal
 * builder to pick products, categories and coupons:
 * *  - `GET /goalcart/v1/search/products`    — products/variations by name or
 *    SKU (q), capped at 50 results.
 *  - `GET /goalcart/v1/search/categories`  — product_cat terms by name.
 *  - `GET /goalcart/v1/search/coupons`     — shop_coupon posts by code.
 *
 * Every route also accepts an `ids` array: when present, the search is
 * narrowed to exactly those ids (Phase 9: the goal builder uses it to
 * preload already-selected products/categories/coupons when editing a
 * goal, since the search endpoints are the only admin lookup available).
 *
 * Searches are admin-only (manage_options). Results are capped so the
 * builder never loads thousands of products at once (Phase 23 performance
 * requirement: server-side search, no client-side filtering).
 */
class SearchController extends BaseController {

	/**
	 * Maximum results returned by any search route.
	 *
	 * @var int
	 */
	const MAX_RESULTS = 50;

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
	 * Register the search routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/search/products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_products' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->search_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search/categories',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_categories' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->search_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search/coupons',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_coupons' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->search_args(),
			)
		);
	}

	/**
	 * Shared search arg schema.
	 *
	 * `ids` narrows the result to exactly the given positive ids (used by
	 * the goal builder to preload saved selections).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function search_args() {
		return array(
			'q'        => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'ids'      => array(
				'type'  => 'array',
				'items' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'default' => array(),
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => self::MAX_RESULTS,
			),
		);
	}

	/**
	 * Search products and variations.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_products( $request ) {
		$q        = (string) $request->get_param( 'q' );
		$per_page = min( self::MAX_RESULTS, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$ids      = $this->positive_ints( $request->get_param( 'ids' ) );

		$args = array(
			'post_type'        => array( 'product', 'product_variation' ),
			'post_status'      => 'publish',
			'posts_per_page'   => $per_page,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => true,
			'no_found_rows'    => true,
		);

		if ( ! empty( $ids ) ) {
			$args['post__in']    = $ids;
			$args['orderby']     = 'post__in';
			$args['no_found_rows'] = true;
		}

		if ( '' !== $q ) {
			$args['s'] = $q;
		}

		$query = new \WP_Query( $args );

		$items = array();

		foreach ( (array) $query->posts as $post ) {
			if ( ! function_exists( 'wc_get_product' ) ) {
				continue;
			}

			$product = wc_get_product( $post );

			if ( ! $product ) {
				continue;
			}

			$price = $product->get_price();

			$items[] = array(
				'id'           => (int) $product->get_id(),
				'name'         => $product->get_name(),
				'type'         => $product->get_type(),
				'sku'          => (string) $product->get_sku(),
				'price'        => '' !== $price ? (float) $price : null,
				'stock_status' => $product->get_stock_status(),
				'permalink'    => $product->get_permalink(),
			);
		}

		return $this->success( array( 'items' => $items ) );
	}

	/**
	 * Search product categories.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_categories( $request ) {
		$q        = (string) $request->get_param( 'q' );
		$per_page = min( self::MAX_RESULTS, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$ids      = $this->positive_ints( $request->get_param( 'ids' ) );

		$args = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'number'     => $per_page,
			'orderby'    => 'name',
			'search'     => '' !== $q ? $q : '',
		);

		if ( ! empty( $ids ) ) {
			$args['include'] = $ids;
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$items = array();

		foreach ( (array) $terms as $term ) {
			$items[] = array(
				'id'     => (int) $term->term_id,
				'name'   => $term->name,
				'slug'   => $term->slug,
				'parent' => (int) $term->parent,
				'count'  => (int) $term->count,
			);
		}

		return $this->success( array( 'items' => $items ) );
	}

	/**
	 * Search coupons (shop_coupon posts).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_coupons( $request ) {
		$q        = (string) $request->get_param( 'q' );
		$per_page = min( self::MAX_RESULTS, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$ids      = $this->positive_ints( $request->get_param( 'ids' ) );

		$args = array(
			'post_type'        => 'shop_coupon',
			'post_status'      => 'publish',
			'posts_per_page'   => $per_page,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => true,
			'no_found_rows'    => true,
		);

		if ( ! empty( $ids ) ) {
			$args['post__in']    = $ids;
			$args['orderby']     = 'post__in';
			$args['no_found_rows'] = true;
		}

		if ( '' !== $q ) {
			$args['s'] = $q;
		}

		$query = new \WP_Query( $args );

		$items = array();

		foreach ( (array) $query->posts as $post ) {
			$items[] = array(
				'id'            => (int) $post->ID,
				'code'          => $post->post_title,
				'discount_type' => function_exists( 'wc_get_coupon_discount_type' ) ? wc_get_coupon_discount_type( $post->ID ) : '',
				'amount'        => function_exists( 'wc_get_coupon_amount' ) ? (float) wc_get_coupon_amount( $post->ID ) : null,
			);
		}

		return $this->success( array( 'items' => $items ) );
	}

	/**
	 * Cast a mixed value to a list of positive ints.
	 *
	 * @param mixed $value Raw value (REST array param).
	 * @return int[]
	 */
	protected function positive_ints( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', $value ), function ( $id ) {
			return $id > 0;
		} ) );
	}
}
