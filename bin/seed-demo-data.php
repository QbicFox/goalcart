<?php
/**
 * FaraCart — demo data seeder (manual-testing helper).
 *
 * Seeds a realistic, fully-removable demo dataset so every revenue
 * dashboard (Sales Performance, Goal Conversion & Purchase Analysis,
 * Goal Performance, Smart Recommendations, Upsell Analytics) has data
 * to show. Mirrors the exact fixture patterns the plugin's own test
 * suites use (goals + campaigns, costed products, funnel events,
 * order attribution, upsell funnel, daily aggregation).
 *
 * Usage:
 *   php bin/seed-demo-data.php                # seed (rich by default)
 *   php bin/seed-demo-data.php --scale small  # 2 goals / 4 products / ~25 orders
 *   php bin/seed-demo-data.php --scale medium # 4 goals / 6 products / ~90 orders
 *   php bin/seed-demo-data.php --scale rich   # 6 goals / 10 products / ~220 orders
 *   php bin/seed-demo-data.php --clean        # remove every seeded demo row
 *
 * What is seeded (rich):
 *   - 6 goals + 2 campaigns (names prefixed "[Demo]")
 *   - 10 products with WooCommerce cost data (_cost) — marked
 *   - ~220 completed orders spread over the last 90 days — marked
 *   - goal funnel events (view / progress / completed) + order
 *     attribution (direct / assisted) + upsell funnel events, all
 *     backdated to match each order's date
 *   - revenue_daily + upsell_stats aggregation, then cache invalidation
 *
 * Reversibility: every row is marked (goal/campaign name prefix "[Demo]",
 * order/product meta `_goalcart_demo_seed`, event meta `demo_seed`), so
 * `--clean` deletes exactly the demo rows and never touches existing
 * store data. Run from the plugin directory.
 */

$dir = __DIR__;
while ( ! file_exists( $dir . '/wp-load.php' ) ) {
	$parent = dirname( $dir );
	if ( $parent === $dir ) {
		fwrite( STDERR, "Could not locate wp-load.php.\n" );
		exit( 2 );
	}
	$dir = $parent;
}

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

require $dir . '/wp-load.php';
require dirname( __DIR__ ) . '/ravis-faracart.php';

use GoalCart\Analytics\AttributionEngine;
use GoalCart\Analytics\DailyAggregator;
use GoalCart\Analytics\RevenueRepository;
use GoalCart\Analytics\RevenueTracker;
use GoalCart\Campaigns\CampaignRepository;
use GoalCart\Database\Installer;
use GoalCart\Database\Schema;
use GoalCart\Goals\GoalRepository;
use GoalCart\Settings\Settings;

// ---------------------------------------------------------------------------
// Bootstrap services (mirrors the test suites).
// ---------------------------------------------------------------------------
Installer::maybe_create_tables();

$container     = \GoalCart\Plugin::instance()->container();
$engine        = $container->get( AttributionEngine::class );
$tracker       = $container->get( RevenueTracker::class );
$settings      = $container->get( Settings::class );
$goals_repo    = $container->get( GoalRepository::class );
$campaigns_repo = $container->get( CampaignRepository::class );
$repo          = $container->get( RevenueRepository::class );
$aggregator    = $container->get( DailyAggregator::class );
$wpdb          = $GLOBALS['wpdb'];

$revenue_table     = Schema::table( 'revenue_events' );
$attrib_table      = Schema::table( 'goal_attribution' );
$goals_table       = Schema::table( 'goals' );
$campaigns_table   = Schema::table( 'campaigns' );
$upsell_table      = Schema::table( 'upsell_events' );
$daily_table       = Schema::table( 'revenue_daily' );
$upsell_stats_table = Schema::table( 'upsell_stats' );

$clean = in_array( '--clean', $argv, true );

// ---------------------------------------------------------------------------
// Cleanup: remove exactly the demo rows (idempotent).
// ---------------------------------------------------------------------------
if ( $clean ) {
	$goal_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$goals_table} WHERE name LIKE '[Demo]%'" ) );
	$campaign_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$campaigns_table} WHERE name LIKE '[Demo]%'" ) );
	$product_ids = array_map( 'intval', (array) $wpdb->get_col(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_goalcart_demo_seed'"
	) );
	$order_ids = array_map( 'intval', (array) $wpdb->get_col(
		"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_goalcart_demo_seed' WHERE p.post_type IN ('shop_order','shop_order_placehold')"
	) );

	$goal_in   = $goal_ids ? implode( ',', $goal_ids ) : '0';
	$order_in  = $order_ids ? implode( ',', $order_ids ) : '0';
	$product_in = $product_ids ? implode( ',', $product_ids ) : '0';

	$wpdb->query( "DELETE FROM {$revenue_table} WHERE goal_id IN ({$goal_in}) OR order_id IN ({$order_in}) OR meta LIKE '%demo_seed%'" );
	$wpdb->query( "DELETE FROM {$attrib_table} WHERE order_id IN ({$order_in})" );
	$wpdb->query( "DELETE FROM {$upsell_table} WHERE goal_id IN ({$goal_in}) OR order_id IN ({$order_in}) OR meta LIKE '%demo_seed%'" );
	$wpdb->query( "DELETE FROM {$daily_table} WHERE goal_id IN ({$goal_in})" );
	$wpdb->query( "DELETE FROM {$upsell_stats_table} WHERE product_id IN ({$product_in})" );

	foreach ( $order_ids as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	foreach ( $product_ids as $product_id ) {
		wp_delete_post( $product_id, true );
	}
	foreach ( $goal_ids as $goal_id ) {
		$goals_repo->delete( $goal_id );
	}
	foreach ( $campaign_ids as $campaign_id ) {
		$campaigns_repo->delete( $campaign_id );
	}

	$repo->invalidate();

	printf(
		"Cleaned %d goals, %d campaigns, %d products, %d orders (and their events/attribution/aggregates).\n",
		count( $goal_ids ),
		count( $campaign_ids ),
		count( $product_ids ),
		count( $order_ids )
	);
	exit( 0 );
}

// ---------------------------------------------------------------------------
// Scale presets.
// ---------------------------------------------------------------------------
$scale = 'rich';
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--scale=' ) ) {
		$scale = substr( $arg, 8 );
	}
}
$scales = array(
	'small'  => array( 'orders' => 25,  'window' => 30,  'goals' => array( 0, 1 ),           'products' => array( 0, 1, 2, 3 ) ),
	'medium' => array( 'orders' => 90,  'window' => 60,  'goals' => array( 0, 1, 2, 3 ),     'products' => array( 0, 1, 2, 3, 4, 5 ) ),
	'rich'   => array( 'orders' => 220, 'window' => 90,  'goals' => array( 0, 1, 2, 3, 4, 5 ), 'products' => array( 0, 1, 2, 3, 4, 5, 6, 7, 8, 9 ) ),
);
$scale = isset( $scales[ $scale ] ) ? $scale : 'rich';
$cfg  = $scales[ $scale ];

$GOAL_SPECS = array(
	array( 'name' => '[Demo] Free Shipping on 500K',  'type' => 'amount', 'target' => 500000,  'reward_type' => 'free_shipping',     'priority' => 10 ),
	array( 'name' => '[Demo] 10% Discount on 800K',   'type' => 'amount', 'target' => 800000,  'reward_type' => 'percent_discount', 'reward_value' => 10, 'reward_max_value' => 100000, 'priority' => 20 ),
	array( 'name' => '[Demo] Free Gift on 1.2M',      'type' => 'amount', 'target' => 1200000, 'reward_type' => 'free_gift',         'priority' => 30 ),
	array( 'name' => '[Demo] 5% Discount on 400K',    'type' => 'amount', 'target' => 400000,  'reward_type' => 'percent_discount', 'reward_value' => 5,  'reward_max_value' => 50000,  'priority' => 40 ),
	array( 'name' => '[Demo] 15% Discount on 1.5M',   'type' => 'amount', 'target' => 1500000, 'reward_type' => 'percent_discount', 'reward_value' => 15, 'reward_max_value' => 200000, 'priority' => 50 ),
	array( 'name' => '[Demo] Free Shipping on 300K',  'type' => 'amount', 'target' => 300000,  'reward_type' => 'free_shipping',     'priority' => 60 ),
);

$PRODUCT_SPECS = array(
	array( 'name' => '[Demo] Leather Wallet',           'price' => 450000, 'cost' => 190000 ),
	array( 'name' => '[Demo] Premium Sunglasses',       'price' => 850000, 'cost' => 380000 ),
	array( 'name' => '[Demo] Silk Scarf',               'price' => 620000, 'cost' => 300000 ),
	array( 'name' => '[Demo] Travel Mug',               'price' => 260000, 'cost' => 110000 ),
	array( 'name' => '[Demo] Desk Lamp',                'price' => 720000, 'cost' => 400000 ),
	array( 'name' => '[Demo] Scented Candle Set',       'price' => 190000, 'cost' => 70000 ),
	array( 'name' => '[Demo] Leather Notebook',         'price' => 240000, 'cost' => 95000 ),
	array( 'name' => '[Demo] Wireless Charger',         'price' => 480000, 'cost' => 260000 ),
	array( 'name' => '[Demo] Yoga Mat',                 'price' => 520000, 'cost' => 230000 ),
	array( 'name' => '[Demo] Insulated Water Bottle',   'price' => 350000, 'cost' => 140000 ),
);

// ---------------------------------------------------------------------------
// 1. Goals + campaigns.
// ---------------------------------------------------------------------------
$goal_ids = array();
foreach ( $GOAL_SPECS as $index => $spec ) {
	if ( ! in_array( $index, $cfg['goals'], true ) ) {
		continue;
	}
	$id = $goals_repo->create( $spec );
	if ( ! $id ) {
		fwrite( STDERR, "Failed to create demo goal: {$spec['name']}\n" );
		exit( 1 );
	}
	$goal_ids[ $index ] = (int) $id;
}

$campaign_goals = array( array( 1, 3 ), array( 2, 4 ) );
$campaign_names = array( '[Demo] Summer Boost', '[Demo] Premium Rewards' );
foreach ( $campaign_goals as $ci => $members ) {
	$linked = array();
	foreach ( $members as $gi ) {
		if ( isset( $goal_ids[ $gi ] ) ) {
			$linked[] = $goal_ids[ $gi ];
		}
	}
	if ( ! $linked ) {
		continue;
	}
	$campaigns_repo->create( array(
		'name'   => $campaign_names[ $ci ],
		'status' => 'active',
		'goals'  => $linked,
	) );
}

// ---------------------------------------------------------------------------
// 2. Products with cost data.
// ---------------------------------------------------------------------------
$product_ids = array();
foreach ( $PRODUCT_SPECS as $index => $spec ) {
	if ( ! in_array( $index, $cfg['products'], true ) ) {
		continue;
	}
	$post_id = wp_insert_post( array(
		'post_type'   => 'product',
		'post_title'  => $spec['name'],
		'post_status' => 'publish',
	) );
	if ( ! $post_id || is_wp_error( $post_id ) ) {
		fwrite( STDERR, "Failed to create demo product: {$spec['name']}\n" );
		exit( 1 );
	}
	$product = wc_get_product( $post_id );
	$product->set_regular_price( (string) $spec['price'] );
	$product->update_meta_data( '_cost', (string) $spec['cost'] );
	$product->update_meta_data( '_goalcart_demo_seed', '1' );
	$product->save();

	$product_ids[ $index ] = (int) $post_id;
}

// ---------------------------------------------------------------------------
// 3. Orders + funnel events + attribution + upsell events.
// ---------------------------------------------------------------------------
$order_total = $cfg['orders'];
$window      = $cfg['window'];
$demo_goal_list = array_values( $goal_ids );

// Goal pick weights — the small "Free Shipping 300K" and "500K" goals are the
// most common (they convert most), matching a realistic top-performing goal.
$goal_weights = array();
foreach ( $goal_ids as $gi => $id ) {
	$goal_weights[ $id ] = array( 30, 15, 12, 15, 8, 20 )[ $gi ] ?? 15;
}

$pick_goal = function () use ( $demo_goal_list, $goal_weights ) {
	$total = array_sum( $goal_weights );
	$r     = mt_rand( 1, $total );
	foreach ( $demo_goal_list as $gid ) {
		$r -= $goal_weights[ $gid ];
		if ( $r <= 0 ) {
			return (int) $gid;
		}
	}
	return (int) $demo_goal_list[0];
};

// A deterministic "random" date biased toward recent days.
$pick_date = function ( $window ) {
	$u = mt_rand() / mt_getrandmax();
	$days_ago = (int) floor( pow( $u, 1.55 ) * ( $window - 1 ) );
	$dt = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
	$dt->modify( "-{$days_ago} days" );
	$dt->setTime( mt_rand( 9, 21 ), mt_rand( 0, 59 ) );
	return $dt;
};

// Temporarily disable tracking so creating orders never self-attributes
// through the payment/status hooks (exactly like the test fixtures).
$prev_analytics = (bool) $settings->get( 'analytics_enabled', true );
$settings->set( 'analytics_enabled', false );

$orders = array(); // list of order descriptors for phase two.
$dates  = array();
$counts = array( 'plain' => 0, 'assisted' => 0, 'progressed' => 0, 'completed' => 0 );

for ( $i = 1; $i <= $order_total; $i++ ) {
	$date = $pick_date( $window );
	$date_mysql = $date->format( 'Y-m-d H:i:s' );
	$dates[ substr( $date_mysql, 0, 10 ) ] = true;

	// Goal association: ~18% plain, ~25% view-only (assisted),
	// ~20% progressed (direct), ~37% completed (direct).
	$r = mt_rand( 1, 100 );
	if ( $r > 82 ) {
		$mode = 'plain';
	} elseif ( $r > 57 ) {
		$mode = 'assisted';
	} elseif ( $r > 37 ) {
		$mode = 'progressed';
	} else {
		$mode = 'completed';
	}
	$counts[ $mode ]++;

	$goal_id   = 'plain' === $mode ? 0 : $pick_goal();
	$goal_info = 0 !== $goal_id ? $goals_repo->get( $goal_id ) : null;
	$target    = $goal_info ? (float) $goal_info['target'] : 0.0;

	// Build the basket: 1-3 random demo products; for completed sessions
	// keep adding items until the subtotal clears the goal target.
	$items   = array();
	$subtotal = 0.0;
	$max_attempts = 6;
	for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
		$pindex = array_rand( $product_ids );
		$qty    = mt_rand( 1, 2 );
		$price  = (float) $PRODUCT_SPECS[ $pindex ]['price'];
		$items[] = array( 'product_id' => $product_ids[ $pindex ], 'qty' => $qty, 'price' => $price );
		$subtotal += $price * $qty;

		$done = 'completed' === $mode
			? $subtotal >= $target && count( $items ) >= 1
			: count( $items ) >= mt_rand( 1, 3 );
		if ( $done && count( $items ) >= 1 && ( 'completed' !== $mode || $subtotal >= $target ) ) {
			break;
		}
		if ( 'completed' === $mode && $subtotal >= $target ) {
			break;
		}
	}
	$shipping = mt_rand( 40000, 150000 );
	$total    = $subtotal + $shipping;

	// Create the order (tracking off).
	$order = wc_create_order();
	foreach ( $items as $item ) {
		$order->add_product( wc_get_product( $item['product_id'] ), $item['qty'] );
	}
	$order->set_shipping_total( $shipping );
	$order->set_total( $total );
	if ( method_exists( $order, 'set_date_created' ) ) {
		$order->set_date_created( wc_string_to_datetime( $date_mysql ) );
	}
	$order->set_status( 'completed' );
	$order->update_meta_data( '_goalcart_demo_seed', '1' );
	$order->save();
	$order_id = (int) $order->get_id();

	$orders[] = array(
		'i'         => $i,
		'order_id'  => $order_id,
		'mode'      => $mode,
		'goal_id'   => $goal_id,
		'target'    => $target,
		'subtotal'  => $subtotal,
		'shipping'  => $shipping,
		'total'     => $total,
		'date'      => $date_mysql,
		'products'  => array_map( function ( $item ) {
			return $item['product_id'];
		}, $items ),
	);
}

$settings->set( 'analytics_enabled', true );

// Phase two: funnel events + attribution + upsell events (tracking on).
$backdate = function ( $table, $id, $date_mysql ) use ( $wpdb ) {
	$wpdb->update( $table, array( 'created_at' => $date_mysql ), array( 'id' => (int) $id ) );
};

foreach ( $orders as $descriptor ) {
	$i        = $descriptor['i'];
	$order_id = $descriptor['order_id'];
	$mode     = $descriptor['mode'];
	$goal_id  = $descriptor['goal_id'];
	$target   = $descriptor['target'];
	$subtotal = $descriptor['subtotal'];
	$date     = $descriptor['date'];
	$session  = md5( 'gc-demo-' . $i );

	$context = array(
		'goal_id'     => $goal_id,
		'session_id'  => $session,
		'goal_target' => $target,
		'meta'        => array( 'demo_seed' => 1 ),
	);

	// Funnel events (only for goal sessions).
	if ( 0 !== $goal_id ) {
		$rid = $tracker->record( RevenueTracker::EVENT_GOAL_VIEW, $context + array(
			'cart_value' => max( 1, (int) floor( $subtotal * 0.3 ) ),
		) );
		if ( $rid ) {
			$backdate( $revenue_table, $rid, $date );
		}

		if ( in_array( $mode, array( 'progressed', 'completed' ), true ) ) {
			$rid = $tracker->record( RevenueTracker::EVENT_GOAL_PROGRESS, $context + array(
				'cart_value' => max( 1, (int) floor( $subtotal * 0.7 ) ),
			) );
			if ( $rid ) {
				$backdate( $revenue_table, $rid, $date );
			}
		}

		if ( 'completed' === $mode ) {
			$rid = $tracker->record( RevenueTracker::EVENT_GOAL_COMPLETED, $context + array(
				'cart_value' => (int) $subtotal,
			) );
			if ( $rid ) {
				$backdate( $revenue_table, $rid, $date );
			}
		}
	}

	// Attribute the order (records order_paid + goal_attribution rows).
	$engine->attribute_order( $order_id, array(
		'total'          => $descriptor['total'],
		'status'         => 'completed',
		'shipping_total' => $descriptor['shipping'],
		'session_id'     => $session,
		'date'           => $date,
	) );

	// Backdate the order_paid event + attribution rows to the order date.
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$revenue_table} SET created_at = %s WHERE order_id = %d AND event_type = 'order_paid'",
		$date,
		$order_id
	) );
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$attrib_table} SET created_at = %s WHERE order_id = %d",
		$date,
		$order_id
	) );

	// Upsell funnel events for ~65% of orders.
	if ( mt_rand( 1, 100 ) <= 65 && $descriptor['products'] ) {
		$upsell_products = $descriptor['products'];
		if ( count( $upsell_products ) < 3 ) {
			$pool = array_values( $product_ids );
			shuffle( $pool );
			foreach ( $pool as $extra ) {
				if ( ! in_array( $extra, $upsell_products, true ) ) {
					$upsell_products[] = $extra;
					break;
				}
			}
		}
		foreach ( $upsell_products as $position => $product_id ) {
			$ucontext = array(
				'goal_id'    => $goal_id,
				'product_id' => $product_id,
				'session_id' => $session,
				'cart_value' => (int) $subtotal,
				'meta'       => array( 'demo_seed' => 1 ),
			);

			$rid = $tracker->record_upsell( RevenueTracker::EVENT_UPSELL_IMPRESSION, $ucontext );
			if ( $rid ) {
				$backdate( $upsell_table, $rid, $date );
			}

			if ( 0 === $position || mt_rand( 1, 100 ) <= 55 ) {
				$rid = $tracker->record_upsell( RevenueTracker::EVENT_UPSELL_CLICKED, $ucontext );
				if ( $rid ) {
					$backdate( $upsell_table, $rid, $date );
				}
			}

			if ( 0 === $position || mt_rand( 1, 100 ) <= 40 ) {
				$rid = $tracker->record_upsell( RevenueTracker::EVENT_UPSELL_ADDED, $ucontext );
				if ( $rid ) {
					$backdate( $upsell_table, $rid, $date );
				}
			}

			// upsell_order is deduped per order — record it once.
			if ( 0 === $position && 'plain' !== $mode ) {
				$rid = $tracker->record_upsell( RevenueTracker::EVENT_UPSELL_ORDER, $ucontext + array(
					'order_id' => $order_id,
				) );
				if ( $rid ) {
					$backdate( $upsell_table, $rid, $date );
				}
			}
		}
	}
}

// ---------------------------------------------------------------------------
// 4. Aggregation + cache invalidation so the dashboards read fresh data.
// ---------------------------------------------------------------------------
foreach ( array_keys( $dates ) as $day ) {
	$aggregator->aggregate_revenue_day( $day );
}
$aggregator->aggregate_upsells();
$repo->invalidate();

// Restore the store's original tracking setting.
$settings->set( 'analytics_enabled', $prev_analytics );

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
$event_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$revenue_table} WHERE meta LIKE '%demo_seed%' OR order_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_goalcart_demo_seed')" );

printf(
	"Seeded %s demo dataset: %d goals, %d campaigns, %d products, %d orders, %d revenue events.\n",
	strtoupper( $scale ),
	count( $goal_ids ),
	2,
	count( $product_ids ),
	count( $orders ),
	$event_count
);
printf( "Order mix: plain=%d assisted=%d progressed=%d completed=%d (window: last %d days).\n", $counts['plain'], $counts['assisted'], $counts['progressed'], $counts['completed'], $window );
echo "Open the revenue dashboards to see the data. Remove it any time with: php bin/seed-demo-data.php --clean\n";
