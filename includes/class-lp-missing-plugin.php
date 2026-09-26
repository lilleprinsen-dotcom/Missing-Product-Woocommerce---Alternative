<?php
/**
 * Plugin bootstrap: shared keys, module registration and upgrades.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Plugin {
    const VERSION = '1.2.0';
    const META_KEY = '_lp_missing_data';
    const OPTION_ENABLE_STOCK = 'lp_missing_enable_stock_log';
    const OPTION_SECRET = 'lp_missing_secret';
    const OPTION_PORTAL_URL = 'lp_missing_portal_url';
    const OPTION_SETTINGS = 'lp_missing_settings';
    const OPTION_ATTENTION_FLAG = '_lp_missing_needs_attention';
    const OPTION_HAS_MISSING_DATA = '_lp_missing_has_data';
    const OPTION_HAS_OPEN_MISSING = '_lp_missing_has_open';
    const CLEANUP_HOOK = 'lp_missing_cleanup_order';
    const DAILY_CLEANUP_HOOK = 'lp_missing_daily_cleanup';
    const DAILY_CLEANUP_LIMIT = 25;
    const CLEANUP_MIN_DAYS = 7;
    const SHORTCODE = 'lp_missing_items';
    const MOVED_QTY_META = '_lp_missing_moved_qty';
    const VERIFY_TOKEN_TTL = 7200;
    const MAX_ALTERNATIVES = 3;

    const DB_VERSION_OPTION = 'lp_missing_db_version';

    public static function init() {
        foreach ( self::modules() as $class ) {
            if ( class_exists( $class ) && method_exists( $class, 'register' ) ) {
                call_user_func( array( $class, 'register' ) );
            }
        }
        // Action Scheduler and friends are only safe to use from init onwards.
        add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 20 );
    }

    /**
     * Classes that register their own hooks.
     */
    public static function modules() {
        return array(
            'LP_Missing_Admin_Metabox',
            'LP_Missing_Admin_Actions',
            'LP_Missing_Admin_Settings_Page',
            'LP_Missing_Admin_Orders_List',
            'LP_Missing_Portal',
            'LP_Missing_Notifier',
            'LP_Missing_Lifecycle',
            'LP_Missing_Stock',
        );
    }

    /**
     * Versioned upgrade steps: version => callable. Each step runs once, in order, when the stored
     * version is lower than the step's version.
     */
    public static function upgrades() {
        $steps = array(
            '1.2.0' => array( 'LP_Missing_Orders', 'upgrade_normalize_open_cases' ),
        );
        /**
         * Filter the upgrade steps.
         *
         * @param array $steps version => callable.
         */
        return apply_filters( 'lp_missing_upgrade_steps', $steps );
    }

    public static function maybe_upgrade() {
        $installed = get_option( self::DB_VERSION_OPTION, '1.1.0' );
        if ( version_compare( $installed, self::VERSION, '>=' ) ) {
            return;
        }
        // Simple lock so concurrent requests do not run the same step twice.
        if ( get_transient( 'lp_missing_upgrading' ) ) {
            return;
        }
        set_transient( 'lp_missing_upgrading', 1, 5 * MINUTE_IN_SECONDS );
        $steps = self::upgrades();
        uksort( $steps, 'version_compare' );
        foreach ( $steps as $version => $callback ) {
            if ( version_compare( $installed, $version, '<' ) && is_callable( $callback ) ) {
                call_user_func( $callback );
                LP_Missing_Logger::info( sprintf( 'Upgrade step %s done.', $version ) );
            }
        }
        update_option( self::DB_VERSION_OPTION, self::VERSION );
        delete_transient( 'lp_missing_upgrading' );
    }
}
