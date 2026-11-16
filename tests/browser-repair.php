<?php
/** Synthetic interrupted preparation for the disposable browser test site. */
use RegradeDesk\Workbench as W;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
$demo = get_option( 'second_shelf_demo' );
$source = wc_get_order( $demo['order'] )->get_item( $demo['item'] )->get_product();
$order = wc_create_order();
$item = $order->add_product( $source, 1 );
$order->set_status( 'completed' );
$order->save();
$file = wp_upload_bits( 'browser-repair.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aONsAAAAASUVORK5CYII=' ) );
$photo = wp_insert_attachment( array( 'post_title' => 'Temporary repair photo', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file['file'] );
$unit = W::receive( $order->get_id(), $item, wp_generate_uuid4() );
W::save( $unit, 1, array( 'state' => 'ready', 'grade' => 'open-box', 'listing_title' => 'Repair browser fixture', 'price' => '39', 'disclosure' => 'Demo only.', 'photo_ids' => (string) $photo, 'photos_confirmed' => 1, 'inspected' => 1 ) );
$failure = static function ( $product ) {
    if ( $product->get_meta( '_second_shelf_unit' ) ) { throw new RuntimeException( 'Browser fixture interruption' ); }
};
add_action( 'woocommerce_before_product_object_save', $failure );
try { W::create_draft( $unit, 2 ); } catch ( RuntimeException $error ) {
    if ( $error->getMessage() !== 'Browser fixture interruption' ) { throw $error; }
} finally { remove_action( 'woocommerce_before_product_object_save', $failure ); }
wp_delete_attachment( $photo, true );
WP_CLI::line( wp_json_encode( array( 'unit' => $unit, 'product' => (int) W::get( $unit )['product_id'], 'photo' => $demo['photo'] ) ) );
