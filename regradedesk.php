<?php
/**
 * Plugin Name: RegradeDesk
 * Description: Inspect returned units and prepare traceable, single-unit resale drafts for WooCommerce.
 * Version: 0.1.2
 * Plugin URI: https://github.com/jeptvar/regradedesk
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 9.5
 * WC tested up to: 11.2.1
 * Author: jeptvar
 * License: GPL-2.0-or-later
 * Text Domain: regradedesk
 */

defined( 'ABSPATH' ) || exit;
define( 'REGRADEDESK_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-workbench.php';

register_activation_hook( __FILE__, array( 'RegradeDesk\Workbench', 'install' ) );
add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
add_action( 'plugins_loaded', static function () {
    if ( class_exists( '\\SecondShelf\\Workbench', false ) ) {
        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Deactivate the earlier Second Shelf plugin to enable RegradeDesk. Your existing inspections and resale products will be retained.', 'regradedesk' ) . '</p></div>';
        } );
        return;
    }
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'RegradeDesk requires WooCommerce to be active.', 'regradedesk' ) . '</p></div>';
        } );
        return;
    }
    if ( get_option( 'second_shelf_schema' ) !== '1' ) {
        \RegradeDesk\Workbench::install();
    }
    require_once __DIR__ . '/includes/class-admin.php';
    ( new \RegradeDesk\Admin() )->register();
} );
