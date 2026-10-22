<?php
/**
 * Plugin Name: Second Shelf
 * Description: Inspect returned units and prepare traceable, single-unit resale drafts for WooCommerce.
 * Version: 0.1.0
 * Plugin URI: https://github.com/jeptvar/second-shelf
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 9.5
 * WC tested up to: 11.2.1
 * Author: jeptvar
 * License: GPL-2.0-or-later
 * Text Domain: second-shelf
 */

defined( 'ABSPATH' ) || exit;
define( 'SECOND_SHELF_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-workbench.php';

register_activation_hook( __FILE__, array( 'SecondShelf\Workbench', 'install' ) );
add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Second Shelf requires WooCommerce to be active.', 'second-shelf' ) . '</p></div>';
        } );
        return;
    }
    if ( get_option( 'second_shelf_schema' ) !== '1' ) {
        \SecondShelf\Workbench::install();
    }
    require_once __DIR__ . '/includes/class-admin.php';
    ( new \SecondShelf\Admin() )->register();
} );
