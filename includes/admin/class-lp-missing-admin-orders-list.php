<?php
/**
 * Order list view, filter and column.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Admin_Orders_List {
    public static function register() {
        // Legacy (post based) order list.
        add_filter( 'views_edit-shop_order', array( __CLASS__, 'add_missing_orders_view' ) );
        add_filter( 'request', array( __CLASS__, 'filter_missing_orders_view' ) );
        add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'register_missing_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_missing_column' ), 10, 2 );
        // HPOS order list.
        add_filter( 'views_woocommerce_page_wc-orders', array( __CLASS__, 'add_missing_orders_view' ) );
        add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'filter_missing_orders_view_hpos' ) );
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'register_missing_column' ) );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_missing_column' ), 10, 2 );
    }

    public static function is_missing_view_requested() {
        return isset( $_GET['lp_missing_view'] ) && 'open' === sanitize_key( wp_unslash( $_GET['lp_missing_view'] ) );
    }

    public static function add_missing_orders_view( $views ) {
        $count = LP_Missing_Orders::count_orders_with_open_missing();
        $url   = add_query_arg( 'lp_missing_view', 'open', LP_Missing_Util::get_orders_list_url() );
        $class = self::is_missing_view_requested() ? 'class="current" aria-current="page"' : '';

        $views['lp_missing'] = '<a href="' . esc_url( $url ) . '" ' . $class . '>' . esc_html( sprintf( __( 'Missing items (%d)', 'lp-missing' ), $count ) ) . '</a>';
        return $views;
    }

    public static function filter_missing_orders_view( $vars ) {
        // Legacy order list only: this filter also runs for every other admin and front-end query.
        if ( ! is_admin() || ! self::is_missing_view_requested() || empty( $vars['post_type'] ) || 'shop_order' !== $vars['post_type'] ) {
            return $vars;
        }

        $meta_query   = isset( $vars['meta_query'] ) ? (array) $vars['meta_query'] : array();
        $meta_query[] = array(
            'key'     => LP_Missing_Plugin::OPTION_HAS_OPEN_MISSING,
            'value'   => 'yes',
            'compare' => '=',
        );

        $vars['meta_query'] = $meta_query;
        return $vars;
    }

    public static function filter_missing_orders_view_hpos( $args ) {
        if ( ! self::is_missing_view_requested() ) {
            return $args;
        }

        $meta_query   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();
        $meta_query[] = array(
            'key'     => LP_Missing_Plugin::OPTION_HAS_OPEN_MISSING,
            'value'   => 'yes',
            'compare' => '=',
        );

        $args['meta_query'] = $meta_query;
        return $args;
    }

    public static function register_missing_column( $columns ) {
        $columns['lp_missing_status'] = __( 'Missing items', 'lp-missing' );
        return $columns;
    }

    public static function render_missing_column( $column, $post_id_or_order ) {
        if ( 'lp_missing_status' !== $column ) {
            return;
        }

        // Legacy list passes the post ID, the HPOS list passes the order object.
        $order = LP_Missing_Util::resolve_order( $post_id_or_order );
        if ( ! $order ) {
            echo '&mdash;';
            return;
        }

        $has_open   = 'yes' === $order->get_meta( LP_Missing_Plugin::OPTION_HAS_OPEN_MISSING );
        $attention  = 'yes' === $order->get_meta( LP_Missing_Plugin::OPTION_ATTENTION_FLAG );
        $has_data   = 'yes' === $order->get_meta( LP_Missing_Plugin::OPTION_HAS_MISSING_DATA );

        if ( $has_open ) {
            echo '<span class="dashicons dashicons-warning" style="color:#d63638;" aria-hidden="true"></span> ' . esc_html__( 'Awaiting resolution', 'lp-missing' );
            return;
        }

        if ( $attention ) {
            echo '<span class="dashicons dashicons-flag" style="color:#d63638;" aria-hidden="true"></span> ' . esc_html__( 'Needs follow-up', 'lp-missing' );
            return;
        }

        if ( $has_data ) {
            echo '<span class="dashicons dashicons-yes-alt" style="color:#2271b1;" aria-hidden="true"></span> ' . esc_html__( 'Resolved (cleanup pending)', 'lp-missing' );
            return;
        }

        echo '&mdash;';
    }
}
