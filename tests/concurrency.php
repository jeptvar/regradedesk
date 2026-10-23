<?php
use RegradeDesk\Workbench as W;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$operation = $args[0] ?? '';
if ( $operation === 'setup' ) {
    $product = new WC_Product_Simple();
    $product->set_name( 'Race test product' );
    $product->set_regular_price( '20' );
    $product->save();
    $order = wc_create_order();
    $item = $order->add_product( $product, 1 );
    $order->set_status( 'completed' );
    $order->save();
    update_option( 'ss_race_fixture', array( 'order' => $order->get_id(), 'item' => $item ) );
    WP_CLI::line( 'READY' );
    return;
}
$fixture = get_option( 'ss_race_fixture' );
if ( $operation === 'receive' ) {
    try {
        $id = W::receive( $fixture['order'], $fixture['item'], wp_generate_uuid4() );
        WP_CLI::line( 'RECEIVED:' . $id );
    } catch ( RuntimeException $e ) {
        if ( ! str_contains( $e->getMessage(), 'All units' ) ) { throw $e; }
        WP_CLI::line( 'CAPACITY_BLOCKED' );
    }
} elseif ( $operation === 'prepare' ) {
    global $wpdb;
    $id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . W::table() . ' WHERE order_id = %d', $fixture['order'] ) );
    $file = wp_upload_bits( 'race.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aONsAAAAASUVORK5CYII=' ) );
    $photo = wp_insert_attachment( array( 'post_title' => 'Race fixture', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file['file'] );
    W::save( $id, 1, array( 'state' => 'ready', 'grade' => 'open-box', 'listing_title' => 'Race fixture', 'disclosure' => 'Test only', 'price' => '10', 'photo_ids' => (string) $photo, 'inspected' => 1, 'photos_confirmed' => 1 ) );
    $fixture['unit'] = $id;
    update_option( 'ss_race_fixture', $fixture );
    WP_CLI::line( 'PREPARED' );
} elseif ( $operation === 'create' ) {
    // Stretch the overlap window while keeping the actual service and database in use.
    add_action( 'woocommerce_before_product_object_save', static function () { usleep( 500000 ); } );
    WP_CLI::line( 'DRAFT:' . W::create_draft( $fixture['unit'], 2 ) );
} elseif ( $operation === 'verify' ) {
    global $wpdb;
    $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . W::table() . ' WHERE order_id = %d', $fixture['order'] ) );
    $products = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'meta_key' => '_second_shelf_unit', 'meta_value' => $fixture['unit'], 'fields' => 'ids' ) );
    if ( $count !== 1 || count( $products ) !== 1 || wc_get_product( $products[0] )->get_stock_quantity() !== 1 ) {
        throw new RuntimeException( 'Concurrent requests created duplicate units, products or stock.' );
    }
    WP_CLI::line( 'VERIFIED' );
}
