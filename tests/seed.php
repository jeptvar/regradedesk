<?php
/** Synthetic fixtures for the disposable local demo, never a live shop. */
use RegradeDesk\Workbench as W;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( get_option( 'second_shelf_demo' ) ) {
    WP_CLI::success( 'Demo already seeded.' );
    return;
}
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$image = imagecreatetruecolor( 900, 700 );
$bg = imagecolorallocate( $image, 238, 234, 222 );
$green = imagecolorallocate( $image, 42, 88, 68 );
$brass = imagecolorallocate( $image, 172, 133, 66 );
$white = imagecolorallocate( $image, 255, 252, 235 );
imagefill( $image, 0, 0, $bg );
imagefilledellipse( $image, 450, 590, 360, 46, $green );
imagefilledrectangle( $image, 435, 240, 465, 585, $brass );
imagefilledpolygon( $image, array( 360, 150, 540, 150, 640, 345, 260, 345 ), $green );
imagefilledellipse( $image, 450, 345, 380, 55, $white );
imagestring( $image, 5, 280, 650, 'DEMO ILLUSTRATION - NOT AN INSPECTION PHOTO', $green );
ob_start(); imagepng( $image ); $png = ob_get_clean(); imagedestroy( $image );
$file = wp_upload_bits( 'second-shelf-demo-lamp.png', null, $png );
$photo = wp_insert_attachment( array( 'post_title' => 'Demo lamp illustration', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file['file'] );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $photo, wp_generate_attachment_metadata( $photo, $file['file'] ) );
update_post_meta( $photo, '_wp_attachment_image_alt', 'Illustrated green and brass desk lamp; demo fixture' );
$product = new WC_Product_Simple();
$product->set_name( 'Arc desk lamp · forest green' );
$product->set_status( 'publish' );
$product->set_regular_price( '89' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 24 );
$product->set_image_id( $photo );
$product->set_weight( '1.8' );
$product->save();
$order = wc_create_order();
$item = $order->add_product( $product, 4 );
$order->calculate_totals();
$order->set_status( 'completed' );
$order->save();
$ready = W::receive( $order->get_id(), $item, wp_generate_uuid4() );
$input = array(
    'state' => 'ready', 'grade' => 'open-box', 'listing_title' => 'Arc desk lamp · forest green', 'price' => '59.00',
    'disclosure' => 'Open-box return. A small cosmetic scratch is visible on the base. Lamp tested and working. Power cable included; original packaging is missing. Demo listing only.',
    'private_notes' => 'Demo inspection: checked switch, cable and base. Customer details are deliberately excluded from this example.',
    'photo_ids' => (string) $photo, 'photos_confirmed' => '1', 'inspected' => '1',
);
W::save( $ready, 1, $input );
$received = W::receive( $order->get_id(), $item, wp_generate_uuid4() );
$rejected = W::receive( $order->get_id(), $item, wp_generate_uuid4() );
W::save( $rejected, 1, array_merge( $input, array( 'state' => 'rejected', 'inspected' => '0', 'private_notes' => 'Demo: damaged power cable. Do not resell before repair.' ) ) );
update_option( 'second_shelf_demo', array( 'order' => $order->get_id(), 'item' => $item, 'photo' => $photo, 'ready' => $ready, 'received' => $received, 'rejected' => $rejected ) );
WP_CLI::success( 'Demo seeded: ' . wp_json_encode( get_option( 'second_shelf_demo' ) ) );
