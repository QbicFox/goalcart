<?php
/**
 * Goal Cart REST cart-initialization regression tests.
 *
 * Database-independent reproduction of the custom REST lifecycle where
 * WooCommerce has a session-backed cart but has not initialized WC()->cart.
 *
 * Run: php tests/cart-rest-initialization-test.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'REST_REQUEST', true );

	$actions = array(
		'before_woocommerce_init' => 1,
		'woocommerce_init'        => 1,
		'wp_loaded'               => 1,
	);
	$current_user_id = 0;
	$user_logged_in  = false;

	function did_action( $hook ) {
		global $actions;

		return isset( $actions[ $hook ] ) ? $actions[ $hook ] : 0;
	}

	function doing_action( $hook ) {
		return false;
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function apply_filters( $hook, $value ) {
		return $value;
	}

	function wp_get_object_terms() {
		return array();
	}

	function is_wp_error() {
		return false;
	}

	function get_woocommerce_currency() {
		return 'USD';
	}

	function get_current_user_id() {
		global $current_user_id;

		return $current_user_id;
	}

	function is_user_logged_in() {
		global $user_logged_in;

		return $user_logged_in;
	}

	function wp_get_post_terms() {
		return array();
	}

	class WC_Product {
		protected $id;
		protected $name;
		protected $price;

		public function __construct( $id, $name, $price ) {
			$this->id    = $id;
			$this->name  = $name;
			$this->price = $price;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_name() {
			return $this->name;
		}

		public function get_price() {
			return $this->price;
		}

		public function get_weight() {
			return 0;
		}

		public function is_virtual() {
			return false;
		}

		public function is_downloadable() {
			return false;
		}

		public function is_on_sale() {
			return false;
		}
	}

	class WC_Cart {
		public $cart_contents = array();

		public function get_cart() {
			return $this->cart_contents;
		}

		public function get_total() {
			$total = 0;

			foreach ( $this->cart_contents as $item ) {
				$total += $item['line_total'];
			}

			return $total;
		}

		public function get_discount_total() {
			return 0;
		}

		public function get_total_tax() {
			return 0;
		}

		public function get_shipping_total() {
			return 0;
		}

		public function fees_api() {
			return new class() {
				public function get_fees() {
					return array();
				}
			};
		}
	}

	class FakeWooCommerce {
		public $cart;
		public $session;
		public $customer;
		public $session_cart = array();
		public $load_count = 0;
	}

	$woocommerce = new FakeWooCommerce();

	function WC() {
		global $woocommerce;

		return $woocommerce;
	}

	function wc_load_cart() {
		$woocommerce = WC();
		$woocommerce->load_count++;
		$woocommerce->session  = (object) array( 'loaded' => true );
		$woocommerce->customer = (object) array( 'guest' => true );
		$woocommerce->cart     = new WC_Cart();
		$woocommerce->cart->cart_contents = $woocommerce->session_cart;
	}
}

namespace GoalCart\Settings {
	class Settings {
		public function get( $key, $default = null ) {
			return $default;
		}
	}
}

namespace {
	require dirname( __DIR__ ) . '/includes/Goals/CartItem.php';
	require dirname( __DIR__ ) . '/includes/Goals/CartContext.php';
	require dirname( __DIR__ ) . '/includes/Cart/CartIntegration.php';
	require dirname( __DIR__ ) . '/includes/Goals/Goal.php';
	require dirname( __DIR__ ) . '/includes/Goals/GoalResult.php';
	require dirname( __DIR__ ) . '/includes/Goals/ProgressCalculator.php';
	require dirname( __DIR__ ) . '/includes/Goals/GoalEvaluator.php';
	require dirname( __DIR__ ) . '/includes/Goals/Evaluators/AmountEvaluator.php';

	$failures = 0;
	$checks   = 0;

	function check( $label, $condition ) {
		global $failures, $checks;
		$checks++;

		if ( $condition ) {
			echo "OK   {$label}\n";
			return;
		}

		$failures++;
		echo "FAIL {$label}\n";
	}

	function cart_line( $key, $product_id, $variation_id, $quantity, $amount ) {
		$product_id = $variation_id > 0 ? $variation_id : $product_id;

		return array(
			'key'               => $key,
			'product_id'        => $variation_id > 0 ? 10 : $product_id,
			'variation_id'      => $variation_id,
			'quantity'          => $quantity,
			'data'              => new WC_Product( $product_id, 'Product ' . $product_id, $amount / $quantity ),
			'line_subtotal'     => $amount,
			'line_total'        => $amount,
			'line_subtotal_tax' => 0,
			'line_tax'          => 0,
		);
	}

	$woocommerce->session_cart = array(
		'first' => cart_line( 'first', 101, 0, 1, 40 ),
	);

	$integration = new \GoalCart\Cart\CartIntegration();
	$context     = $integration->context();

	check( 'REST request initializes the missing WooCommerce cart', $woocommerce->cart instanceof WC_Cart );
	check( 'WooCommerce session and customer are initialized', null !== $woocommerce->session && null !== $woocommerce->customer );
	check( 'session-backed guest cart restores one item', 1 === count( $context->items() ) );
	check( 'restored CartContext subtotal is non-zero', 40.0 === $context->subtotal() );

	// The storefront gift endpoint acquires the cart through the same
	// live_cart() accessor (not a bare WC()->cart check, which would be
	// null on custom REST routes and reject every claim as an empty cart).
	$live = $integration->live_cart();
	check( 'live_cart exposes the REST-initialized WC cart', $live instanceof WC_Cart );
	check( 'gift-endpoint cart acquisition sees the session-backed item', null !== $live && 1 === count( $live->get_cart() ) );

	$goal = new \GoalCart\Goals\Goal(
		array(
			'type'   => \GoalCart\Goals\Goal::TYPE_AMOUNT,
			'target' => 100,
		)
	);
	$result = ( new \GoalCart\Goals\Evaluators\AmountEvaluator() )->evaluate( $goal, $context );

	check( 'restored cart produces the expected current goal value', 40.0 === $result->current() );
	check( 'restored cart produces non-zero progress', 40.0 === $result->percentage() );

	$repeat = $integration->context();
	check( 'repeated progress reads reuse the request-level context', $repeat === $context );
	check( 'repeated progress reads do not initialize WooCommerce twice', 1 === $woocommerce->load_count );

	$woocommerce->cart->cart_contents['second'] = cart_line( 'second', 102, 0, 2, 30 );
	$added = $integration->context();
	check( 'adding another product changes the cache key', $added !== $context );
	check( 'multiple lines contribute to progress', 70.0 === $added->subtotal() );

	unset( $woocommerce->cart->cart_contents['first'] );
	$integration->invalidate();
	$removed = $integration->context();
	check( 'removing a product changes progress', 30.0 === $removed->subtotal() );

	$woocommerce->cart->cart_contents['second']['quantity']      = 3;
	$woocommerce->cart->cart_contents['second']['line_subtotal'] = 45;
	$woocommerce->cart->cart_contents['second']['line_total']    = 45;
	$quantity = $integration->context();
	check( 'quantity changes invalidate via the cache key', 45.0 === $quantity->subtotal() );

	$woocommerce->cart->cart_contents['variation'] = cart_line( 'variation', 10, 201, 1, 25 );
	$variation = $integration->context();
	$items     = $variation->items();
	$last_item = $items[ count( $items ) - 1 ];
	check( 'variation lines are restored into CartContext', 201 === $last_item->variation_id() );
	check( 'variation amount contributes to progress', 70.0 === $variation->subtotal() );

	$woocommerce->cart->cart_contents = array();
	$integration->invalidate();
	$empty = $integration->context();
	check( 'an actually empty cart remains empty', $empty->is_empty() && 0.0 === $empty->subtotal() );

	$current_user_id = 77;
	$user_logged_in  = true;
	$woocommerce->cart = null;
	$woocommerce->session_cart = array(
		'member' => cart_line( 'member', 301, 0, 1, 55 ),
	);

	$member_integration = new \GoalCart\Cart\CartIntegration();
	$member_context     = $member_integration->context();
	check( 'logged-in session-backed cart restores its item', 1 === count( $member_context->items() ) );

	$member_live = $member_integration->live_cart();
	check( 'live_cart returns the logged-in session-backed cart', $member_live instanceof WC_Cart && 1 === count( $member_live->get_cart() ) );
	check( 'logged-in CartContext records the customer', 77 === $member_context->user_id() && ! $member_context->is_guest() );
	check( 'logged-in cart produces non-zero progress input', 55.0 === $member_context->subtotal() );

	$actions['woocommerce_init'] = 0;
	$woocommerce->cart           = null;
	$load_count                  = $woocommerce->load_count;
	$early_context               = ( new \GoalCart\Cart\CartIntegration() )->context();
	check( 'REST access before WooCommerce initialization stays safe', $early_context->is_empty() );
	check( 'REST access before WooCommerce initialization does not load the cart', $load_count === $woocommerce->load_count );

	$early_live = ( new \GoalCart\Cart\CartIntegration() )->live_cart();
	check( 'live_cart stays null before WooCommerce init (gift endpoint degrades safely)', null === $early_live );

	echo "\nChecks: {$checks}  Failures: {$failures}\n";
	exit( $failures > 0 ? 1 : 0 );
}
