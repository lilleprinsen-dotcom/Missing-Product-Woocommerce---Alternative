<?php
/**
 * Portal installation: the "Velg erstatning" page (activation and upgrade), the v1 link transition cutoff,
 * and an admin notice when the configured portal page cannot show the portal.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Portal_Setup {
    const PAGE_SLUG = 'velg-erstatning';

    /**
     * Upgrade step key. Sorts right after the 1.2.0 step, so installs coming from 1.1.x run it in the same upgrade.
     */
    const UPGRADE_VERSION = '1.2.0.1';

    const OPTION_PENDING    = 'lp_missing_portal_page_pending';
    const OPTION_SETUP_DONE = 'lp_missing_portal_setup_done';

    public static function register() {
        add_filter( 'lp_missing_upgrade_steps', array( __CLASS__, 'add_upgrade_steps' ) );
        add_action( 'init', array( __CLASS__, 'maybe_finish_activation' ), 30 );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
    }

    protected static function woocommerce_ready() {
        return function_exists( 'wc_get_page_id' ) && function_exists( 'wc_format_decimal' );
    }

    /**
     * register_activation_hook() callback.
     */
    public static function activate() {
        if ( self::woocommerce_ready() ) {
            self::ensure_portal_page();
        } else {
            // WooCommerce is not loaded yet (e.g. activated in the same request): finish on the next page load.
            update_option( self::OPTION_PENDING, 1, false );
        }
    }

    public static function maybe_finish_activation() {
        if ( get_option( self::OPTION_PENDING ) && self::woocommerce_ready() ) {
            delete_option( self::OPTION_PENDING );
            self::ensure_portal_page();
        }
    }

    public static function add_upgrade_steps( $steps ) {
        $steps[ self::UPGRADE_VERSION ] = array( __CLASS__, 'upgrade' );
        return $steps;
    }

    /**
     * Upgrade step for existing installs: start the v1 link transition period, create the signing key ring and
     * the portal page (once; later re-runs leave a page choice made by the shop alone).
     */
    public static function upgrade() {
        LP_Missing_Magic_Link::record_v1_cutoff();
        LP_Missing_Magic_Link::get_keyring();
        if ( ! get_option( self::OPTION_SETUP_DONE ) ) {
            self::ensure_portal_page();
            update_option( self::OPTION_SETUP_DONE, time(), false );
        }
    }

    public static function is_valid_portal_page( $page_id ) {
        $page = $page_id ? get_post( $page_id ) : null;
        return $page instanceof WP_Post && 'page' === $page->post_type && 'publish' === $page->post_status;
    }

    public static function page_has_shortcode( $page_id ) {
        $page = get_post( $page_id );
        $has  = $page instanceof WP_Post && ( has_shortcode( $page->post_content, LP_Missing_Plugin::SHORTCODE ) || false !== strpos( $page->post_content, '[' . LP_Missing_Plugin::SHORTCODE ) );
        /**
         * Filter whether the portal page renders the portal (e.g. when a page builder stores the shortcode elsewhere).
         *
         * @param bool $has     Whether the page content contains the shortcode.
         * @param int  $page_id Page ID.
         */
        return (bool) apply_filters( 'lp_missing_portal_page_has_shortcode', $has, $page_id );
    }

    /**
     * A published page that already shows the portal: the "velg-erstatning" page, else any page with the shortcode.
     */
    protected static function find_existing_page() {
        $page = get_page_by_path( self::PAGE_SLUG );
        if ( $page && self::is_valid_portal_page( $page->ID ) && self::page_has_shortcode( $page->ID ) ) {
            return (int) $page->ID;
        }
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1", '%' . $wpdb->esc_like( '[' . LP_Missing_Plugin::SHORTCODE ) . '%' ) );
    }

    /**
     * When no valid portal page is configured (and no external portal URL is), use an existing portal page or
     * create "Velg erstatning", and store it in the portal_page_id setting. Returns the page ID, or 0.
     */
    public static function ensure_portal_page() {
        LP_Missing_Settings::flush();
        $settings = LP_Missing_Settings::get_settings( true );
        if ( self::is_valid_portal_page( $settings['portal_page_id'] ) ) {
            return (int) $settings['portal_page_id'];
        }
        if ( '' !== trim( (string) $settings['portal_base_url'] ) ) {
            return 0;
        }

        $page_id = self::find_existing_page();
        $created = false;
        if ( ! $page_id ) {
            $page_id = wp_insert_post(
                array(
                    'post_type'      => 'page',
                    'post_status'    => 'publish',
                    'post_title'     => __( 'Velg erstatning', 'lp-missing' ),
                    'post_name'      => self::PAGE_SLUG,
                    'post_content'   => '<!-- wp:shortcode -->[' . LP_Missing_Plugin::SHORTCODE . ']<!-- /wp:shortcode -->',
                    'post_author'    => get_current_user_id(),
                    'comment_status' => 'closed',
                    'ping_status'    => 'closed',
                ),
                true
            );
            if ( is_wp_error( $page_id ) || ! $page_id ) {
                LP_Missing_Logger::error( 'The customer portal page could not be created.', array( 'error' => is_wp_error( $page_id ) ? $page_id->get_error_message() : 'unknown' ) );
                return 0;
            }
            $created = true;
        }

        $settings['portal_page_id'] = (int) $page_id;
        LP_Missing_Settings::update_settings( $settings );
        LP_Missing_Logger::info( $created ? 'Customer portal page created.' : 'Existing customer portal page selected.', array( 'page_id' => (int) $page_id ) );
        return (int) $page_id;
    }

    /**
     * What is wrong with the configured portal page, as admin HTML, or '' when it is fine.
     */
    public static function get_portal_page_problem() {
        $settings = LP_Missing_Settings::get_settings();
        $page_id  = absint( $settings['portal_page_id'] );
        if ( '' !== trim( (string) $settings['portal_base_url'] ) || ! $page_id ) {
            // An external portal URL, or the My Account page (which shows the portal by itself).
            return '';
        }
        $settings_url = admin_url( 'admin.php?page=lp-missing-settings' );
        if ( ! self::is_valid_portal_page( $page_id ) ) {
            return sprintf(
                /* translators: 1: page ID, 2: settings URL */
                __( '<strong>Missing Items:</strong> the customer portal page (ID %1$d) is missing or not published, so customer links open the My Account page instead. <a href="%2$s">Choose a portal page</a>.', 'lp-missing' ),
                $page_id,
                esc_url( $settings_url )
            );
        }
        if ( ! self::page_has_shortcode( $page_id ) ) {
            return sprintf(
                /* translators: 1: page title, 2: shortcode, 3: edit page URL, 4: settings URL */
                __( '<strong>Missing Items:</strong> the customer portal page “%1$s” does not contain the %2$s shortcode, so customers who open their link see no choices. <a href="%3$s">Edit the page</a> or <a href="%4$s">choose another page</a>.', 'lp-missing' ),
                esc_html( get_the_title( $page_id ) ),
                '<code>[' . LP_Missing_Plugin::SHORTCODE . ']</code>',
                esc_url( (string) get_edit_post_link( $page_id, 'raw' ) ),
                esc_url( $settings_url )
            );
        }
        return '';
    }

    public static function get_notice_screens() {
        /**
         * Filter the admin screens that warn about a misconfigured portal page.
         *
         * @param string[] $screen_ids Screen IDs.
         */
        return apply_filters( 'lp_missing_portal_notice_screens', array( 'woocommerce_page_wc-settings', 'woocommerce_page_lp-missing-settings', 'plugins' ) );
    }

    public static function admin_notice() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, self::get_notice_screens(), true ) ) {
            return;
        }
        $problem = self::get_portal_page_problem();
        if ( '' === $problem ) {
            return;
        }
        echo '<div class="notice notice-warning lp-missing-portal-page-notice"><p>' . wp_kses(
            $problem,
            array(
                'a'      => array( 'href' => array() ),
                'code'   => array(),
                'strong' => array(),
            )
        ) . '</p></div>';
    }
}
