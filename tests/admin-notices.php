<?php
/** Run on an isolated site with --skip-plugins=regradedesk (also woocommerce for the missing dependency case). */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    exit;
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
$scenario = $args[0] ?? 'resolved';
if ( $scenario === 'legacy' ) {
    class RegradeDesk_Legacy_Notice_Fixture {}
    class_alias( 'RegradeDesk_Legacy_Notice_Fixture', 'SecondShelf\\Workbench' );
}
global $wp_filter;
$before = $wp_filter['plugins_loaded']->callbacks[10] ?? array();
$notices_before = $wp_filter['admin_notices']->callbacks[10] ?? array();
require WP_PLUGIN_DIR . '/regradedesk/regradedesk.php';
// Exercise only this plugin's real bootstrap, without rerunning other plugins' startup hooks.
foreach ( array_diff_key( $wp_filter['plugins_loaded']->callbacks[10], $before ) as $hook ) {
    call_user_func( $hook['function'] );
}
$notice_callbacks = array_diff_key( $wp_filter['admin_notices']->callbacks[10] ?? array(), $notices_before );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$subscriber = wp_insert_user( array( 'user_login' => 'notice-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
if ( is_wp_error( $subscriber ) ) {
    throw new RuntimeException( $subscriber->get_error_message() );
}
$checks = 0;
foreach ( array( $admins[0]->ID, $subscriber ) as $user_id ) {
    wp_set_current_user( $user_id );
    foreach ( array( 'plugins', 'dashboard', 'edit-product', 'woocommerce_page_second-shelf' ) as $screen_id ) {
        set_current_screen( $screen_id );
        ob_start();
        foreach ( $notice_callbacks as $hook ) {
            call_user_func( $hook['function'] );
        }
        $output = ob_get_clean();
        $expected = $scenario !== 'resolved' && $user_id === $admins[0]->ID && $screen_id === 'plugins';
        $message = $scenario === 'legacy' ? 'Deactivate the earlier Second Shelf' : 'RegradeDesk requires WooCommerce';
        if ( ( $expected && ! str_contains( $output, $message ) ) || ( ! $expected && $output !== '' ) ) {
            throw new RuntimeException( "Notice visibility failed: $scenario, user $user_id, screen $screen_id" );
        }
        ++$checks;
    }
}
wp_delete_user( $subscriber );
WP_CLI::success( "$checks admin notice assertions passed ($scenario)." );
