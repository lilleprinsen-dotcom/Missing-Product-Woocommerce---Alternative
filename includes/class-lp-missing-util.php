<?php
/**
 * Small shared helpers (order screens, URLs, capabilities, formatting).
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Util {
    /**
     * Take a short-lived named lock (a row in the options table; INSERT IGNORE is atomic on the unique option_name,
     * unlike add_option()). A lock older than $stale_after seconds is treated as left by a request that died.
     */
    public static function acquire_lock( $name, $stale_after = 120 ) {
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        if ( null !== $existing && (int) $existing < time() - $stale_after ) {
            $wpdb->delete( $wpdb->options, array( 'option_name' => $name, 'option_value' => $existing ) );
        }
        $inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
        return 1 === (int) $inserted;
    }

    public static function release_lock( $name ) {
        global $wpdb;
        $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
    }

    public static function get_cached_product( $product_id, &$cache ) {
        $product_id = absint( $product_id );
        if ( $product_id && ! isset( $cache[ $product_id ] ) ) {
            $cache[ $product_id ] = wc_get_product( $product_id );
        }
        return isset( $cache[ $product_id ] ) ? $cache[ $product_id ] : null;
    }

    public static function hpos_enabled() {
        return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    public static function get_order_screen_ids() {
        $ids = array( 'shop_order', 'woocommerce_page_wc-orders' );
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $ids[] = wc_get_page_screen_id( 'shop-order' );
        }
        return array_unique( array_filter( $ids ) );
    }

    public static function is_order_screen() {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return false;
        }
        $screen = get_current_screen();
        return $screen && ! empty( $screen->id ) && in_array( $screen->id, self::get_order_screen_ids(), true );
    }

    public static function get_orders_list_url() {
        return self::hpos_enabled() ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
    }

    public static function get_order_edit_url( $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        return $order ? $order->get_edit_order_url() : self::get_orders_list_url();
    }

    public static function current_user_can_edit_order( $order_id ) {
        // Legacy orders are posts and support the per-order meta capability; HPOS orders fall back to the generic capability.
        if ( 'shop_order' === get_post_type( $order_id ) ) {
            return current_user_can( 'edit_shop_order', $order_id );
        }
        return current_user_can( 'edit_shop_orders' );
    }

    public static function resolve_order( $post_or_order ) {
        if ( $post_or_order instanceof WC_Order ) {
            return $post_or_order;
        }
        if ( $post_or_order instanceof WP_Post ) {
            return wc_get_order( $post_or_order->ID );
        }
        return $post_or_order ? wc_get_order( $post_or_order ) : false;
    }

    public static function sanitize_alt_ids( $input ) {
        if ( is_string( $input ) ) {
            $input = preg_split( '/[,\s]+/', $input );
        }
        if ( ! is_array( $input ) ) {
            return array();
        }
        $ids = array();
        foreach ( $input as $part ) {
            $id = absint( $part );
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }
        $ids = array_slice( array_values( array_unique( $ids ) ), 0, LP_Missing_Plugin::MAX_ALTERNATIVES );
        return $ids;
    }

    /**
     * Render a plugin template. Themes can override it in yourtheme/lp-missing/{$template}.
     */
    public static function get_template_html( $template, $args = array() ) {
        return wc_get_template_html( $template, $args, 'lp-missing/', LP_MISSING_DIR . 'templates/' );
    }

    public static function get_customer_first_name( $order ) {
        $name = trim( (string) $order->get_billing_first_name() );
        return '' === $name ? __( 'der', 'lp-missing' ) : $name;
    }

    /**
     * A formatted price as plain text (wc_price() returns HTML, which esc_html() would show as markup).
     */
    public static function plain_price( $amount, $order ) {
        return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
    }
}
