<?php
/**
 * Order list views, filters and column.
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

    /**
     * The plugin's list views: view slug => order flag it filters on.
     */
    public static function get_view_flags() {
        return array(
            'open'  => LP_Missing_Plugin::ORDER_META_HAS_OPEN,
            'ready' => LP_Missing_Plugin::ORDER_META_READY,
        );
    }

    /**
     * The requested plugin view ('open', 'ready') or '' when none of ours is requested.
     */
    public static function get_requested_view() {
        $view = isset( $_GET['lp_missing_view'] ) ? sanitize_key( wp_unslash( $_GET['lp_missing_view'] ) ) : '';
        return array_key_exists( $view, self::get_view_flags() ) ? $view : '';
    }

    public static function is_missing_view_requested() {
        return 'open' === self::get_requested_view();
    }

    public static function add_missing_orders_view( $views ) {
        $current = self::get_requested_view();
        if ( $current ) {
            // Our views filter all statuses, so WordPress/WooCommerce mark "All" as current as well: only ours is.
            foreach ( $views as $key => $link ) {
                $views[ $key ] = preg_replace( '/\s+(class="current"|aria-current="page")/', '', $link );
            }
        }
        $links = array(
            /* translators: %d: number of orders */
            'open'  => array( 'lp_missing', __( 'Missing items (%d)', 'lp-missing' ), LP_Missing_Orders::count_orders_with_open_missing() ),
            /* translators: %d: number of orders */
            'ready' => array( 'lp_missing_ready', __( 'Customer answered (%d)', 'lp-missing' ), LP_Missing_Orders::count_orders_ready_for_staff() ),
        );

        foreach ( $links as $view => $config ) {
            list( $key, $label, $count ) = $config;
            $url   = add_query_arg( 'lp_missing_view', $view, LP_Missing_Util::get_orders_list_url() );
            $class = $view === $current ? ' class="current" aria-current="page"' : '';

            $views[ $key ] = '<a href="' . esc_url( $url ) . '"' . $class . '>' . esc_html( sprintf( $label, $count ) ) . '</a>';
        }
        return $views;
    }

    /**
     * Meta query clause for the requested view, or null.
     */
    protected static function get_view_meta_clause() {
        $view = self::get_requested_view();
        if ( ! $view ) {
            return null;
        }
        $flags = self::get_view_flags();
        return array(
            'key'     => $flags[ $view ],
            'value'   => 'yes',
            'compare' => '=',
        );
    }

    public static function filter_missing_orders_view( $vars ) {
        // Legacy order list only: this filter also runs for every other admin and front-end query.
        // (The legacy list runs through WP_Query, which supports meta_query.)
        $clause = self::get_view_meta_clause();
        if ( ! is_admin() || ! $clause || empty( $vars['post_type'] ) || 'shop_order' !== $vars['post_type'] ) {
            return $vars;
        }

        $meta_query   = isset( $vars['meta_query'] ) ? (array) $vars['meta_query'] : array();
        $meta_query[] = $clause;

        $vars['meta_query'] = $meta_query;
        return $vars;
    }

    public static function filter_missing_orders_view_hpos( $args ) {
        $clause = self::get_view_meta_clause();
        if ( ! $clause ) {
            return $args;
        }

        // The HPOS order query supports meta_query.
        $meta_query   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();
        $meta_query[] = $clause;

        $args['meta_query'] = $meta_query;
        return $args;
    }

    public static function register_missing_column( $columns ) {
        $columns['lp_missing_status'] = __( 'Missing items', 'lp-missing' );
        return $columns;
    }

    /**
     * Column state of an order as array( slug, label, dashicon ), or null when it has no missing-item data.
     * A customer answer comes first (staff can act now), then escalations (always open lines), then open cases.
     */
    public static function get_column_state( $order ) {
        $states = array(
            array( LP_Missing_Plugin::ORDER_META_READY, 'ready', __( 'Customer answered – ready to apply', 'lp-missing' ), 'yes-alt' ),
            array( LP_Missing_Plugin::ORDER_META_NEEDS_ATTENTION, 'attention', __( 'Needs follow-up', 'lp-missing' ), 'flag' ),
            array( LP_Missing_Plugin::ORDER_META_HAS_OPEN, 'open', __( 'Awaiting resolution', 'lp-missing' ), 'warning' ),
            array( LP_Missing_Plugin::ORDER_META_HAS_DATA, 'resolved', __( 'Resolved (cleanup pending)', 'lp-missing' ), 'saved' ),
        );
        foreach ( $states as $state ) {
            if ( 'yes' === $order->get_meta( $state[0] ) ) {
                return array_slice( $state, 1 );
            }
        }
        return null;
    }

    public static function render_missing_column( $column, $post_id_or_order ) {
        if ( 'lp_missing_status' !== $column ) {
            return;
        }

        // Legacy list passes the post ID, the HPOS list passes the order object.
        $order = LP_Missing_Util::resolve_order( $post_id_or_order );
        $state = $order ? self::get_column_state( $order ) : null;
        if ( ! $state ) {
            echo '&mdash;';
            return;
        }

        list( $slug, $label, $icon ) = $state;
        echo '<span class="lp-missing-state lp-missing-state--' . esc_attr( $slug ) . '">';
        echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span> ' . esc_html( $label );
        echo '</span>';
    }
}
