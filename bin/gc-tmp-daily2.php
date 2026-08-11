<?php
$dir = __DIR__;
while ( ! file_exists( $dir . '/wp-load.php' ) ) {
	$parent = dirname( $dir );
	if ( $parent === $dir ) { exit( 2 ); }
	$dir = $parent;
}
require $dir . '/wp-load.php';
global $wpdb;
$p = $wpdb->prefix . 'goalcart_';

$res = $wpdb->query( "DELETE FROM {$p}revenue_daily WHERE id = 105 AND created_at >= '2026-08-11'" );
echo "delete by id: " . var_export( $res, true ) . ' err=' . var_export( $wpdb->last_error, true ) . "\n";

foreach ( array( 'revenue_events', 'goal_attribution', 'upsell_events', 'upsell_stats', 'revenue_daily', 'goals' ) as $t ) {
	echo "{$t}: " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}{$t}" ) . "\n";
}
