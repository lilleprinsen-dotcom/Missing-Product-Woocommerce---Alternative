<?php
/**
 * Plugin Name: Missing Product WooCommerce Alternative
 * Description: Handles missing items, alternatives, and customer responses for WooCommerce orders.
 * Version: 1.3.0
 * Text Domain: lp-missing
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LP_MISSING_FILE', __FILE__ );
define( 'LP_MISSING_DIR', __DIR__ . '/' );

add_action(
    'before_woocommerce_init',
    function() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    },
    0
);

// LP_Missing_Foo_Bar lives in class-lp-missing-foo-bar.php in one of the include folders.
spl_autoload_register(
    function( $class ) {
        if ( 0 !== strpos( $class, 'LP_Missing_' ) ) {
            return;
        }
        $file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
        foreach ( array( 'includes/', 'includes/admin/', 'includes/frontend/', 'includes/emails/' ) as $dir ) {
            if ( is_readable( LP_MISSING_DIR . $dir . $file ) ) {
                require_once LP_MISSING_DIR . $dir . $file;
                return;
            }
        }
    }
);

if ( ! function_exists( 'lp_missing_bootstrap_plugin' ) ) {
    function lp_missing_bootstrap_plugin() {
        // Bail out early when WooCommerce is not available to prevent fatal errors.
        if ( ! class_exists( 'WooCommerce', false ) && ! function_exists( 'WC' ) ) {
            add_action(
                'admin_notices',
                function() {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'Missing Product WooCommerce Alternative requires WooCommerce to be active.', 'lp-missing' ) . '</p></div>';
                }
            );

            return;
        }

        LP_Missing_Plugin::init();
    }
}

add_action( 'plugins_loaded', 'lp_missing_bootstrap_plugin', 20 );

// Creates the "Velg erstatning" customer portal page when no valid portal page is configured.
register_activation_hook( __FILE__, array( 'LP_Missing_Portal_Setup', 'activate' ) );

// Cancel the plugin's background jobs (Action Scheduler group "lp-missing" and any WP-Cron leftovers).
register_deactivation_hook(
    __FILE__,
    function() {
        if ( class_exists( 'LP_Missing_Scheduler' ) && class_exists( 'LP_Missing_Plugin' ) ) {
            LP_Missing_Scheduler::unschedule_all();
            return;
        }
        foreach ( array( 'lp_missing_order_reminder', 'lp_missing_order_deadline', 'lp_missing_cleanup_order', 'lp_missing_daily_cleanup', 'lp_missing_send_reminder' ) as $hook ) {
            wp_unschedule_hook( $hook );
        }
    }
);
