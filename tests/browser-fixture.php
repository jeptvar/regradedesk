<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
$demo = get_option( 'second_shelf_demo' );
$source = wc_get_order( $demo['order'] )->get_item( $demo['item'] )->get_product();
$order = wc_create_order();
$item = $order->add_product( $source, 1 );
$order->calculate_totals();
$order->set_status( 'completed' );
$order->save();
WP_CLI::line( wp_json_encode( array( 'order' => $order->get_id(), 'item' => $item, 'photo' => $demo['photo'] ) ) );
