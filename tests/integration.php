<?php
/** Run only on the disposable development site: wp eval-file .../tests/integration.php */
use SecondShelf\Workbench as W;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    exit;
}
$checks = 0;
$assert = static function ( bool $ok, string $label ) use ( &$checks ) {
    if ( ! $ok ) {
        throw new RuntimeException( 'FAIL: ' . $label );
    }
    ++$checks;
    WP_CLI::log( 'PASS: ' . $label );
};
$throws = static function ( callable $fn, string $contains ) use ( $assert ) {
    try {
        $fn();
    } catch ( Throwable $e ) {
        $assert( str_contains( $e->getMessage(), $contains ), 'Reject: ' . $contains . ' [' . $e->getMessage() . ']' );
        return;
    }
    $assert( false, 'Expected failure: ' . $contains );
};
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$product = new WC_Product_Simple();
$product->set_name( 'Integration Desk Lamp' );
$product->set_status( 'publish' );
$product->set_regular_price( '89.00' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 10 );
$product->set_weight( '2.5' );
$product->save();
$order = wc_create_order();
$item_id = $order->add_product( $product, 5 );
$order->calculate_totals();
$order->set_status( 'completed' );
$order->save();
$stock_before = wc_get_product( $product->get_id() )->get_stock_quantity();
$order_before = wc_get_order( $order->get_id() )->get_total();
$photo_file = wp_upload_bits( 'ss-test.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aONsAAAAASUVORK5CYII=' ) );
$photo = wp_insert_attachment( array( 'post_title' => 'Inspection photo', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $photo_file['file'] );
$token = wp_generate_uuid4();
$id = W::receive( $order->get_id(), $item_id, $token );
$assert( W::get( $id )['state'] === 'received', 'Receive physical unit' );
$assert( W::receive( $order->get_id(), $item_id, $token ) === $id, 'Duplicate receipt submission is idempotent' );
$throws( static fn() => W::receive( $order->get_id() + 999999, $item_id, wp_generate_uuid4() ), 'belonging' );
$throws( static fn() => W::create_draft( $id, 1 ), 'Save a ready' );
$input = array(
    'state' => 'ready', 'grade' => 'open-box', 'listing_title' => 'Desk lamp — brass', 'price' => '49.50',
    'disclosure' => 'Small scratch on base. Power cable included. Original box missing.',
    'private_notes' => 'PRIVATE-SENTINEL customer account details must never be copied.',
    'photo_ids' => (string) $photo, 'photos_confirmed' => '1', 'inspected' => '1',
);
$throws( static fn() => W::save( $id, 1, array_merge( $input, array( 'inspected' => '0' ) ) ), 'physical inspection' );
$throws( static fn() => W::save( $id, 1, array_merge( $input, array( 'photo_ids' => '' ) ) ), 'at least one photo' );
$throws( static fn() => W::save( $id, 1, array_merge( $input, array( 'price' => '-50' ) ) ), 'plain price' );
$throws( static fn() => W::save( $id, 1, array_merge( $input, array( 'price' => '0' ) ) ), 'greater than zero' );
W::save( $id, 1, $input );
$throws( static fn() => W::save( $id, 1, $input ), 'Another save' );
$draft_id = W::create_draft( $id, 2 );
$draft = wc_get_product( $draft_id );
$assert( $draft->get_status() === 'draft', 'Resale product stays unpublished' );
$assert( $draft->get_stock_quantity() === 1 && $draft->get_manage_stock() && ! $draft->backorders_allowed() && $draft->is_sold_individually(), 'Exactly one unit, no backorders' );
$assert( $draft->get_regular_price() === '49.50', 'Explicit resale price' );
$assert( $draft->get_image_id() === (string) $photo || (int) $draft->get_image_id() === $photo, 'Selected photo copied' );
$assert( str_contains( $draft->get_description(), 'Original box missing' ) && ! str_contains( wp_json_encode( $draft->get_data() ), 'PRIVATE-SENTINEL' ), 'Public disclosure copied; private notes excluded' );
$assert( $draft->get_weight() === '2.5', 'Physical shipping data inherited' );
$assert( (int) $draft->get_meta( '_second_shelf_source_order' ) === $order->get_id(), 'Private order linkage retained' );
$assert( wc_get_product( $product->get_id() )->get_stock_quantity() === $stock_before, 'Original inventory unchanged' );
$assert( wc_get_order( $order->get_id() )->get_total() === $order_before && wc_get_order( $order->get_id() )->get_total_refunded() == 0, 'Original totals and refunds unchanged' );
wc_update_product_stock( $draft_id, 0 );
$assert( W::create_draft( $id, 2 ) === $draft_id && wc_get_product( $draft_id )->get_stock_quantity() === 0, 'Retry returns same product without replenishing sold stock' );
$throws( static fn() => W::save( $id, (int) W::get( $id )['version'], $input ), 'frozen' );
wp_trash_post( $draft_id );
$throws( static fn() => W::create_draft( $id, 2 ), 'deleted or trashed' );
wp_untrash_post( $draft_id );

// Simulate a hook failure after draft reservation, then retry the same durable product ID.
$retry_id = W::receive( $order->get_id(), $item_id, wp_generate_uuid4() );
W::save( $retry_id, 1, $input );
$failure = static function ( $p ) {
    if ( $p->get_meta( '_second_shelf_unit' ) ) {
        throw new RuntimeException( 'Injected product save failure' );
    }
};
add_action( 'woocommerce_before_product_object_save', $failure );
$throws( static fn() => W::create_draft( $retry_id, 2 ), 'Injected' );
remove_action( 'woocommerce_before_product_object_save', $failure );
$retry_row = W::get( $retry_id );
$assert( $retry_row['state'] === 'building' && $retry_row['product_id'] > 0, 'Interrupted draft has durable reservation' );
wp_update_post( array( 'ID' => $retry_row['product_id'], 'post_status' => 'publish' ) );
$assert( get_post_status( $retry_row['product_id'] ) === 'draft', 'Incomplete draft cannot be published' );
$assert( W::create_draft( $retry_id, (int) $retry_row['version'] ) === (int) $retry_row['product_id'], 'Interrupted save resumes same draft' );

$rejected = W::receive( $order->get_id(), $item_id, wp_generate_uuid4() );
W::save( $rejected, 1, array( 'state' => 'rejected', 'private_notes' => 'Damaged cord' ) );
$throws( static fn() => W::create_draft( $rejected, 2 ), 'Save a ready' );
W::receive( $order->get_id(), $item_id, wp_generate_uuid4() );
W::receive( $order->get_id(), $item_id, wp_generate_uuid4() );
$throws( static fn() => W::receive( $order->get_id(), $item_id, wp_generate_uuid4() ), 'All units' );

$parent = new WC_Product_Variable();
$parent->set_name( 'Shirt' );
$parent->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent->get_id() );
$variation->set_regular_price( '20' );
$variation->set_attributes( array( 'size' => 'large' ) );
$variation->save();
$variant_order = wc_create_order();
$variant_item = $variant_order->add_product( $variation );
$variant_order->set_status( 'completed' );
$variant_order->save();
$variant_unit = W::receive( $variant_order->get_id(), $variant_item, wp_generate_uuid4() );
W::save( $variant_unit, 1, $input );
$assert( wc_get_product( W::create_draft( $variant_unit, 2 ) )->is_type( 'simple' ), 'Returned variation becomes independent simple product' );
$digital = new WC_Product_Simple();
$digital->set_name( 'Digital' );
$digital->set_virtual( true );
$digital->save();
$digital_item = $variant_order->add_product( $digital );
$throws( static fn() => W::receive( $variant_order->get_id(), $digital_item, wp_generate_uuid4() ), 'physical simple' );

$customer = wp_insert_user( array( 'user_login' => 'ss-test-' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
wp_set_current_user( $customer );
$throws( static fn() => W::get( $id ), 'permission' );
$throws( static fn() => W::receive( $order->get_id(), $item_id, wp_generate_uuid4() ), 'permission' );
$throws( static fn() => W::save( $id, 1, $input ), 'permission' );
$throws( static fn() => W::create_draft( $id, 1 ), 'permission' );
wp_set_current_user( $admins[0]->ID );
$server = rest_get_server();
$routes = array_filter( array_keys( $server->get_routes() ), static fn( $route ) => str_contains( $route, 'second-shelf' ) || str_contains( $route, 'second_shelf' ) );
$assert( ! $routes, 'No inspection REST routes exposed' );
WP_CLI::success( "$checks checks passed; storage=" . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'legacy' ) );
