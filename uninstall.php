<?php
/**
 * Uninstall: remove the plugin's settings, secrets, transients and scheduled actions.
 *
 * Order data (missing-item records on order lines, order flags) and the portal page are kept, like WooCommerce keeps
 * its data, unless the site defines LP_MISSING_REMOVE_ALL_DATA as true in wp-config.php.
 *
 * @package LP_Missing
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Scheduled work (Action Scheduler when available, plus any WP-Cron leftovers).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    foreach ( array( 'lp_missing_send_reminder', 'lp_missing_order_reminder', 'lp_missing_order_deadline', 'lp_missing_send_customer_notes', 'lp_missing_cleanup_order', 'lp_missing_daily_cleanup' ) as $hook ) {
        as_unschedule_all_actions( $hook );
    }
    as_unschedule_all_actions( '', array(), 'lp-missing' );
}
foreach ( array( 'lp_missing_send_reminder', 'lp_missing_order_reminder', 'lp_missing_order_deadline', 'lp_missing_send_customer_notes', 'lp_missing_cleanup_order', 'lp_missing_daily_cleanup' ) as $hook ) {
    wp_unschedule_hook( $hook );
}

// Options, transients, apply locks (all prefixed lp_missing_) and the WooCommerce settings of the plugin's emails.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lp\\_missing\\_%' OR option_name LIKE '\\_transient\\_lp\\_missing\\_%' OR option_name LIKE '\\_transient\\_timeout\\_lp\\_missing\\_%' OR option_name LIKE 'woocommerce\\_lp\\_missing\\_%\\_settings'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( defined( 'LP_MISSING_REMOVE_ALL_DATA' ) && true === LP_MISSING_REMOVE_ALL_DATA ) {
    // Line-level records and order flags, for both order storages.
    $wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key LIKE '\\_lp\\_missing\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_lp\\_missing\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $orders_meta = $wpdb->prefix . 'wc_orders_meta';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta ) ) === $orders_meta ) {
        $wpdb->query( "DELETE FROM {$orders_meta} WHERE meta_key LIKE '\\_lp\\_missing\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
}
