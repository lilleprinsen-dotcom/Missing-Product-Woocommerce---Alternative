<?php
/**
 * Plugin Name: Missing Product WooCommerce Alternative
 * Description: Handles missing items, alternatives, and customer responses for WooCommerce orders.
 * Version: 1.1.0
 * Text Domain: lp-missing
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action(
    'before_woocommerce_init',
    function() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    },
    0
);

if ( ! function_exists( 'lp_missing_bootstrap_plugin' ) ) {
    function lp_missing_bootstrap_plugin() {
        if ( class_exists( 'LP_Missing_Product_Handler' ) ) {
            return;
        }

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

        if ( ! class_exists( 'WC_Email', false ) && defined( 'WC_ABSPATH' ) ) {
            $wc_email_file = WC_ABSPATH . 'includes/emails/class-wc-email.php';

            if ( file_exists( $wc_email_file ) ) {
                require_once $wc_email_file;
            }
        }

class LP_Missing_Product_Handler {
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

    protected static $auto_email_sent = array();
    protected static $settings = null;

    protected static function get_cached_product( $product_id, &$cache ) {
        $product_id = absint( $product_id );
        if ( $product_id && ! isset( $cache[ $product_id ] ) ) {
            $cache[ $product_id ] = wc_get_product( $product_id );
        }
        return isset( $cache[ $product_id ] ) ? $cache[ $product_id ] : null;
    }

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
        // Fired by WooCommerce for both legacy (post) and HPOS order edit screens, after its own item/data saves.
        add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_metabox' ), 60, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
        add_action( 'admin_post_lp_missing_send_email', array( __CLASS__, 'handle_admin_send_email' ) );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
        add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_class' ) );
        add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
        add_filter( 'the_content', array( __CLASS__, 'maybe_inject_portal' ), 1 );
        add_action( 'lp_missing_item_updated', array( __CLASS__, 'handle_item_updated' ), 10, 4 );
        add_action( 'lp_missing_send_reminder', array( __CLASS__, 'handle_scheduled_reminder' ), 10, 2 );
        add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
        add_action( 'admin_post_lp_missing_save_settings', array( __CLASS__, 'handle_settings_save' ) );
        add_action( self::CLEANUP_HOOK, array( __CLASS__, 'handle_cleanup_order' ) );
        add_action( 'admin_post_lp_missing_apply_decision', array( __CLASS__, 'handle_apply_decision' ) );
        add_action( 'init', array( __CLASS__, 'maybe_schedule_daily_cleanup' ) );
        add_action( self::DAILY_CLEANUP_HOOK, array( __CLASS__, 'run_daily_cleanup' ) );
        // Release stock locks and stop reminders when a line/order leaves the flow outside this plugin.
        add_action( 'woocommerce_before_delete_order_item', array( __CLASS__, 'handle_order_item_deleted' ) );
        add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'handle_order_closed' ), 10, 2 );
        add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'handle_order_closed' ), 10, 2 );
        add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'handle_order_closed' ), 10, 2 );
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

    protected static function hpos_enabled() {
        return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    protected static function get_order_screen_ids() {
        $ids = array( 'shop_order', 'woocommerce_page_wc-orders' );
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $ids[] = wc_get_page_screen_id( 'shop-order' );
        }
        return array_unique( array_filter( $ids ) );
    }

    protected static function is_order_screen() {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return false;
        }
        $screen = get_current_screen();
        return $screen && ! empty( $screen->id ) && in_array( $screen->id, self::get_order_screen_ids(), true );
    }

    protected static function get_orders_list_url() {
        return self::hpos_enabled() ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
    }

    protected static function get_order_edit_url( $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        return $order ? $order->get_edit_order_url() : self::get_orders_list_url();
    }

    protected static function current_user_can_edit_order( $order_id ) {
        // Legacy orders are posts and support the per-order meta capability; HPOS orders fall back to the generic capability.
        if ( 'shop_order' === get_post_type( $order_id ) ) {
            return current_user_can( 'edit_shop_order', $order_id );
        }
        return current_user_can( 'edit_shop_orders' );
    }

    protected static function resolve_order( $post_or_order ) {
        if ( $post_or_order instanceof WC_Order ) {
            return $post_or_order;
        }
        if ( $post_or_order instanceof WP_Post ) {
            return wc_get_order( $post_or_order->ID );
        }
        return $post_or_order ? wc_get_order( $post_or_order ) : false;
    }

    public static function add_metabox() {
        $screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
        add_meta_box(
            'lp_missing_metabox',
            __( 'Missing / Problem Items', 'lp-missing' ),
            array( __CLASS__, 'render_metabox' ),
            $screen ? $screen : 'shop_order',
            'normal',
            'high'
        );
    }

    protected static function default_item_data() {
        return array(
            'missing'         => false,
            'propose_delete'  => false,
            'qty_missing'     => 0,
            'notes'           => '',
            'internal_notes'  => '',
            'alternatives'    => array(),
            'status'          => 'pending',
            'selected_alt_id' => 0,
            'qty_alt'         => 0,
            'stock_locked_qty'=> 0,
            'last_updated'    => 0,
            'first_missing_at'=> 0,
            'reminder_count'  => 0,
            'last_reminder_at'=> 0,
            'reminder_scheduled_for' => 0,
            'needs_attention' => false,
            'resolved_at'     => 0,
            'decision_made_at'=> 0,
            'pricing_snapshot'=> array(),
        );
    }

    protected static function get_item_data( $item ) {
        $data = $item->get_meta( self::META_KEY, true );
        if ( ! is_array( $data ) ) {
            $data = array();
        }

        $data = wp_parse_args( $data, self::default_item_data() );
        $data['missing'] = (bool) $data['missing'];
        $data['propose_delete'] = (bool) $data['propose_delete'];
        $data['qty_missing'] = absint( $data['qty_missing'] );
        $data['alternatives'] = is_array( $data['alternatives'] ) ? array_values( array_unique( array_map( 'absint' , $data['alternatives'] ) ) ) : array();
        $data['selected_alt_id'] = absint( $data['selected_alt_id'] );
        $data['qty_alt'] = absint( $data['qty_alt'] );
        $data['stock_locked_qty'] = absint( $data['stock_locked_qty'] );
        $data['last_updated'] = absint( $data['last_updated'] );
        $data['first_missing_at'] = absint( $data['first_missing_at'] );
        $data['reminder_count'] = absint( $data['reminder_count'] );
        $data['last_reminder_at'] = absint( $data['last_reminder_at'] );
        $data['reminder_scheduled_for'] = absint( $data['reminder_scheduled_for'] );
        $data['needs_attention'] = (bool) $data['needs_attention'];
        $data['resolved_at'] = absint( isset( $data['resolved_at'] ) ? $data['resolved_at'] : 0 );
        $data['decision_made_at'] = absint( isset( $data['decision_made_at'] ) ? $data['decision_made_at'] : 0 );
        $data['pricing_snapshot'] = is_array( $data['pricing_snapshot'] ) ? $data['pricing_snapshot'] : array();

        if ( 'alt_accepted' === $data['status'] ) {
            $data['status'] = 'alt_pending';
        } elseif ( 'delete_accepted' === $data['status'] ) {
            $data['status'] = 'delete_pending';
        }

        return $data;
    }

    protected static function get_default_settings() {
        return array(
            'enable_stock_lock'           => 'yes',
            'enable_stock_notes'          => 'yes',
            'reminder_delay_days'         => 3,
            'reminder_max_count'          => 3,
            'reminder_max_age_days'       => 7,
            'cleanup_resolved_after_days' => 30,
            'show_stock_preview'          => 'yes',
            'portal_base_url'             => '',
            'alt_price_handling'          => 'charge_customer',
        );
    }

    protected static function sanitize_settings( $settings ) {
        $defaults = self::get_default_settings();
        $settings = is_array( $settings ) ? wp_parse_args( $settings, $defaults ) : $defaults;

        $settings['enable_stock_lock']  = 'yes' === $settings['enable_stock_lock'] ? 'yes' : 'no';
        $settings['enable_stock_notes'] = 'yes' === $settings['enable_stock_notes'] ? 'yes' : 'no';
        $settings['show_stock_preview'] = 'yes' === $settings['show_stock_preview'] ? 'yes' : 'no';

        $settings['reminder_delay_days']   = max( 1, absint( $settings['reminder_delay_days'] ) );
        $settings['reminder_max_count']    = max( 1, absint( $settings['reminder_max_count'] ) );
        $settings['reminder_max_age_days'] = max( 1, absint( $settings['reminder_max_age_days'] ) );

        $settings['cleanup_resolved_after_days'] = max( self::CLEANUP_MIN_DAYS, absint( $settings['cleanup_resolved_after_days'] ) );

        $settings['portal_base_url'] = esc_url_raw( trim( $settings['portal_base_url'] ) );

        $allowed_price_handling = array( 'charge_customer', 'store_covers' );
        $settings['alt_price_handling'] = in_array( $settings['alt_price_handling'], $allowed_price_handling, true ) ? $settings['alt_price_handling'] : 'charge_customer';

        return $settings;
    }

    protected static function get_settings( $force = false ) {
        if ( is_array( self::$settings ) && ! $force ) {
            return self::$settings;
        }

        $raw = get_option( self::OPTION_SETTINGS, array() );

        if ( empty( $raw['enable_stock_lock'] ) ) {
            $raw['enable_stock_lock'] = get_option( self::OPTION_ENABLE_STOCK, 'yes' );
        }
        if ( empty( $raw['portal_base_url'] ) ) {
            $raw['portal_base_url'] = get_option( self::OPTION_PORTAL_URL, '' );
        }

        self::$settings = self::sanitize_settings( $raw );

        return self::$settings;
    }

    protected static function update_settings( $settings ) {
        $sanitized = self::sanitize_settings( $settings );
        update_option( self::OPTION_SETTINGS, $sanitized, false );
        update_option( self::OPTION_ENABLE_STOCK, $sanitized['enable_stock_lock'], false );
        update_option( self::OPTION_PORTAL_URL, $sanitized['portal_base_url'], false );
        self::$settings = $sanitized;
    }

    public static function render_metabox( $post_or_order ) {
        // Legacy screens pass a WP_Post, HPOS screens pass the WC_Order itself.
        $order = self::resolve_order( $post_or_order );
        if ( ! $order ) {
            return;
        }

        wp_nonce_field( 'lp_missing_metabox', 'lp_missing_nonce' );
        $items = $order->get_items( 'line_item' );
        $product_cache = array();
        $alt_ids = array();
        foreach ( $items as $item ) {
            $data = self::get_item_data( $item );
            if ( ! empty( $data['alternatives'] ) ) {
                $alt_ids = array_merge( $alt_ids, $data['alternatives'] );
            }
            if ( ! empty( $data['selected_alt_id'] ) ) {
                $alt_ids[] = absint( $data['selected_alt_id'] );
            }
        }
        if ( $alt_ids ) {
            $products = wc_get_products( array( 'include' => array_values( array_unique( $alt_ids ) ), 'limit' => -1, 'type' => array_merge( array_keys( wc_get_product_types() ), array( 'variation' ) ) ) );
            foreach ( $products as $product_obj ) {
                $product_cache[ $product_obj->get_id() ] = $product_obj;
            }
        }
        $show_stock = 'yes' === self::get_settings()['show_stock_preview'];

        echo '<div class="lp-missing-metabox">';
        foreach ( $items as $item_id => $item ) {
            $data = self::get_item_data( $item );
            $product = $item->get_product();
            $product_name = $product ? $product->get_name() : $item->get_name();
            echo '<div class="lp-missing-item" style="border-bottom:1px solid #ddd;padding:10px 0;">';
            echo '<strong>' . esc_html( $product_name ) . '</strong> (' . sprintf( __( 'Qty: %s', 'lp-missing' ), esc_html( $item->get_quantity() ) ) . ')';
            echo '<div style="margin-top:8px;">';
            echo '<label><input type="checkbox" name="lp_missing_items[' . absint( $item_id ) . '][missing]" value="1" ' . checked( true, $data['missing'], false ) . ' /> ' . esc_html__( 'Mark as missing', 'lp-missing' ) . '</label> ';
            echo '<label style="margin-left:12px;"><input type="checkbox" name="lp_missing_items[' . absint( $item_id ) . '][propose_delete]" value="1" ' . checked( true, $data['propose_delete'], false ) . ' /> ' . esc_html__( 'Propose deleting this item instead', 'lp-missing' ) . '</label>';
            echo '</div>';

            echo '<div style="margin-top:8px;">';
            echo '<label>' . esc_html__( 'Quantity missing', 'lp-missing' ) . ': <input type="number" min="0" max="' . esc_attr( self::get_item_billable_qty( $item ) ) . '" name="lp_missing_items[' . absint( $item_id ) . '][qty_missing]" value="' . esc_attr( $data['qty_missing'] ) . '" style="width:80px;" /></label>';
            echo ' <span class="description">' . esc_html__( 'Leave at 0 to use the full line quantity.', 'lp-missing' ) . '</span>';
            echo '</div>';

            echo '<div style="margin-top:8px;">';
            echo '<label>' . esc_html__( 'Notes (customer visible)', 'lp-missing' ) . '<br /><textarea name="lp_missing_items[' . absint( $item_id ) . '][notes]" rows="2" style="width:100%;">' . esc_textarea( $data['notes'] ) . '</textarea></label>';
            echo '</div>';

            echo '<div style="margin-top:8px;">';
            echo '<label>' . esc_html__( 'Internal notes (staff only)', 'lp-missing' ) . '<br /><textarea name="lp_missing_items[' . absint( $item_id ) . '][internal_notes]" rows="2" style="width:100%;">' . esc_textarea( $data['internal_notes'] ) . '</textarea></label>';
            echo '</div>';

            // WooCommerce's own product search (selectWoo) so staff pick from a result list instead of auto-added first hits.
            echo '<div style="margin-top:8px;" class="lp-missing-alternatives" data-item-id="' . absint( $item_id ) . '">';
            echo '<label for="lp-missing-alt-' . absint( $item_id ) . '">' . esc_html( sprintf( __( 'Suggested alternatives (up to %d)', 'lp-missing' ), self::MAX_ALTERNATIVES ) ) . '</label><br />';
            echo '<select id="lp-missing-alt-' . absint( $item_id ) . '" class="wc-product-search lp-alt-select" multiple="multiple" style="width:100%;" name="lp_missing_items[' . absint( $item_id ) . '][alternatives][]"';
            echo ' data-placeholder="' . esc_attr__( 'Search by SKU or title', 'lp-missing' ) . '"';
            echo ' data-action="woocommerce_json_search_products_and_variations"';
            echo ' data-max="' . esc_attr( self::MAX_ALTERNATIVES ) . '"';
            if ( $product ) {
                echo ' data-exclude="' . esc_attr( $product->get_id() ) . '"';
            }
            if ( $show_stock ) {
                echo ' data-display_stock="true"';
            }
            echo '>';
            foreach ( $data['alternatives'] as $alt_id ) {
                $alt_product = self::get_cached_product( $alt_id, $product_cache );
                if ( $alt_product ) {
                    echo '<option value="' . absint( $alt_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $alt_product->get_formatted_name() ) ) . '</option>';
                }
            }
            echo '</select>';
            if ( ! empty( $data['alternatives'] ) ) {
                echo '<ul class="lp-alt-list" style="margin:8px 0 0; padding-left:18px;">';
                foreach ( $data['alternatives'] as $alt_id ) {
                    $alt_product = self::get_cached_product( $alt_id, $product_cache );
                    if ( $alt_product ) {
                        echo '<li style="margin-bottom:6px;">' . self::get_alternative_preview_html( $alt_product, $show_stock ) . '</li>';
                    }
                }
                echo '</ul>';
            }
            echo '</div>';
            self::render_admin_line_status( $order, $item_id, $item, $data, $product_cache );
            echo '</div>';
        }
        if ( self::order_has_missing_items( $order ) ) {
            $send_url = wp_nonce_url( add_query_arg( array( 'action' => 'lp_missing_send_email', 'order_id' => $order->get_id() ), admin_url( 'admin-post.php' ) ), 'lp_missing_send_email_' . $order->get_id() );
            echo '<div class="lp-missing-admin-actions" style="margin-top:12px;">';
            echo '<a class="button" href="' . esc_url( $send_url ) . '">' . esc_html__( 'Send customer portal email', 'lp-missing' ) . '</a>';
            echo '</div>';
        }
        echo '</div>';
    }

    protected static function render_admin_line_status( $order, $item_id, $item, $data, &$product_cache ) {
        echo '<div class="lp-missing-status" style="margin-top:8px;padding:10px;background:#f8f8f8;border:1px solid #e2e2e2;">';
        if ( empty( $data['missing'] ) ) {
            echo '<strong>' . esc_html__( 'Status:', 'lp-missing' ) . '</strong> ' . esc_html__( 'Not marked as missing.', 'lp-missing' );
            echo '</div>';
            return;
        }

        $status_label = __( 'Awaiting customer choice.', 'lp-missing' );
        $decision_text = '';
        $selected_alt = ! empty( $data['selected_alt_id'] ) ? self::get_cached_product( $data['selected_alt_id'], $product_cache ) : null;

        if ( self::is_line_resolved( $data ) ) {
            if ( 'alt_applied' === $data['status'] ) {
                $status_label = __( 'Applied: alternative added.', 'lp-missing' );
            } elseif ( 'delete_applied' === $data['status'] ) {
                $status_label = __( 'Applied: line removed/refunded.', 'lp-missing' );
            } else {
                $status_label = __( 'Applied.', 'lp-missing' );
            }
        } elseif ( 'alt_pending' === $data['status'] && $selected_alt ) {
            $status_label = __( 'Customer chose an alternative (pending staff).', 'lp-missing' );
            $decision_text = sprintf(
                /* translators: 1: product name, 2: quantity */
                __( 'Alternative: %1$s (Qty %2$d)', 'lp-missing' ),
                $selected_alt->get_name(),
                $data['qty_alt'] ? absint( $data['qty_alt'] ) : absint( $data['qty_missing'] )
            );
        } elseif ( 'delete_pending' === $data['status'] ) {
            $status_label = __( 'Customer approved deletion (pending staff).', 'lp-missing' );
            $decision_text = sprintf( __( 'Agreed to delete %s.', 'lp-missing' ), $item->get_name() );
        } elseif ( 'declined' === $data['status'] ) {
            $status_label = __( 'Customer declined the listed alternatives. Suggest new alternatives (the customer is notified again) or remove/refund the missing quantity.', 'lp-missing' );
        } elseif ( $data['needs_attention'] ) {
            $status_label = __( 'Needs manual attention.', 'lp-missing' );
        }

        echo '<strong>' . esc_html__( 'Status:', 'lp-missing' ) . '</strong> ' . esc_html( $status_label );
        if ( $decision_text ) {
            echo '<br /><span>' . esc_html( $decision_text ) . '</span>';
        }

        // Plain nonce links instead of forms: this box is rendered inside the order edit <form>, and nested forms are dropped by browsers.
        if ( 'alt_pending' === $data['status'] && $selected_alt ) {
            echo '<p style="margin:8px 0 4px 0;">' . esc_html__( 'Apply this alternative to the order:', 'lp-missing' ) . '</p>';
            self::render_apply_link( $order, $item_id, 'alternative', 'replace', __( 'Replace missing quantity on this line', 'lp-missing' ), true );
            self::render_apply_link( $order, $item_id, 'alternative', 'add', __( 'Add as an extra line item', 'lp-missing' ) );
        } elseif ( self::can_apply_deletion( $data ) ) {
            $intro = 'delete_pending' === $data['status'] ? __( 'Customer approved deletion of this line.', 'lp-missing' ) : __( 'Remove the missing quantity without a customer choice:', 'lp-missing' );
            echo '<p style="margin:8px 0 4px 0;">' . esc_html( $intro ) . '</p>';
            self::render_apply_link( $order, $item_id, 'delete', 'reduce', __( 'Remove the missing quantity from the order totals', 'lp-missing' ), 'delete_pending' === $data['status'] );
            self::render_apply_link( $order, $item_id, 'delete', 'refund', __( 'Record a refund for the missing quantity (pay it back manually via the payment provider)', 'lp-missing' ) );
        }

        echo '</div>';
    }

    protected static function can_apply_deletion( $data ) {
        if ( empty( $data['missing'] ) || self::is_line_resolved( $data ) ) {
            return false;
        }
        return in_array( $data['status'], array( 'delete_pending', 'declined' ), true ) || ( 'pending' === $data['status'] && ! empty( $data['needs_attention'] ) );
    }

    protected static function get_apply_url( $order, $item_id, $type, $mode ) {
        $url = add_query_arg(
            array(
                'action'     => 'lp_missing_apply_decision',
                'order_id'   => $order->get_id(),
                'item_id'    => absint( $item_id ),
                'apply_type' => $type,
                'apply_mode' => $mode,
            ),
            admin_url( 'admin-post.php' )
        );
        return wp_nonce_url( $url, 'lp_missing_apply_' . $order->get_id() . '_' . absint( $item_id ) );
    }

    protected static function render_apply_link( $order, $item_id, $type, $mode, $label, $primary = false ) {
        echo '<a class="button' . ( $primary ? ' button-primary' : '' ) . ' lp-missing-confirm" style="margin:4px 4px 0 0;" href="' . esc_url( self::get_apply_url( $order, $item_id, $type, $mode ) ) . '" data-confirm="' . esc_attr__( 'This changes the order now. Continue?', 'lp-missing' ) . '">' . esc_html( $label ) . '</a>';
    }

    protected static function get_alternative_preview_html( $product, $show_stock ) {
        $html  = '';
        $thumb = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
        if ( $thumb ) {
            $html .= '<img src="' . esc_url( $thumb ) . '" alt="" style="width:20px;height:20px;object-fit:cover;margin-right:4px;vertical-align:middle;" />';
        }
        $parts = array( $product->get_name() );
        if ( $product->get_sku() ) {
            $parts[] = sprintf( __( 'SKU: %s', 'lp-missing' ), $product->get_sku() );
        }
        if ( $show_stock ) {
            $stock = $product->is_in_stock() ? __( 'In stock', 'lp-missing' ) : __( 'Out of stock', 'lp-missing' );
            if ( $product->managing_stock() ) {
                $stock .= ' (' . wc_stock_amount( $product->get_stock_quantity() ) . ')';
            }
            $parts[] = $stock;
        }
        return $html . '<span style="font-size:12px;color:#555;">' . esc_html( implode( ' | ', $parts ) ) . '</span>';
    }

    public static function enqueue_admin_scripts( $hook ) {
        if ( ! self::is_order_screen() ) {
            return;
        }

        // WooCommerce registers the selectWoo product search used by the alternatives field.
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_register_script( 'lp-missing-admin', false, array( 'jquery' ), '1.1.0', true );
        $inline = <<<'JS'
(function($){
    $(document.body).on('select2:selecting', '.lp-alt-select', function(e){
        var max = parseInt($(this).data('max'), 10) || 3;
        if (($(this).val() || []).length >= max) { e.preventDefault(); }
    });
    $(document).on('click', '.lp-missing-confirm', function(){
        return window.confirm($(this).data('confirm'));
    });
})(jQuery);
JS;
        wp_add_inline_script( 'lp-missing-admin', $inline );
        wp_enqueue_script( 'lp-missing-admin' );
    }

    protected static function sanitize_alt_ids( $input ) {
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
        $ids = array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_ALTERNATIVES );
        return $ids;
    }

    protected static function stock_lock_enabled() {
        $settings = self::get_settings();
        $enabled  = isset( $settings['enable_stock_lock'] ) ? $settings['enable_stock_lock'] : 'yes';
        return apply_filters( 'lp_missing_enable_stock_log', 'yes' === $enabled );
    }

    protected static function get_secret() {
        $secret = (string) get_option( self::OPTION_SECRET, '' );
        if ( empty( $secret ) ) {
            $secret = wp_generate_password( 64, true, true );
            add_option( self::OPTION_SECRET, $secret, '', false );
        }
        return $secret;
    }

    protected static function generate_signature( $order_id, $email ) {
        if ( ! $order_id || ! $email ) {
            return '';
        }
        $data = $order_id . '|' . strtolower( (string) $email );
        return hash_hmac( 'sha256', $data, self::get_secret() );
    }

    protected static function validate_signature( $order_id, $email, $key ) {
        if ( ! $order_id || ! $email || ! $key ) {
            return false;
        }
        $expected = self::generate_signature( $order_id, $email );
        return hash_equals( $expected, (string) $key );
    }

    /**
     * Short-lived proof that the portal visitor confirmed the billing email, carried in the portal forms
     * so a verified guest is not sent back to the verification step when submitting a choice.
     */
    protected static function generate_verification_token( $order_id, $email ) {
        $expires = time() + self::VERIFY_TOKEN_TTL;
        $data    = 'verified|' . absint( $order_id ) . '|' . strtolower( (string) $email ) . '|' . $expires;
        return $expires . ':' . hash_hmac( 'sha256', $data, self::get_secret() );
    }

    protected static function validate_verification_token( $order_id, $email, $token ) {
        $parts = explode( ':', (string) $token, 2 );
        if ( 2 !== count( $parts ) ) {
            return false;
        }
        $expires = absint( $parts[0] );
        if ( $expires < time() ) {
            return false;
        }
        $data     = 'verified|' . absint( $order_id ) . '|' . strtolower( (string) $email ) . '|' . $expires;
        $expected = hash_hmac( 'sha256', $data, self::get_secret() );
        return hash_equals( $expected, $parts[1] );
    }

    protected static function get_portal_base_url() {
        $settings = self::get_settings();
        $configured = trim( (string) ( isset( $settings['portal_base_url'] ) ? $settings['portal_base_url'] : '' ) );
        $base_url  = $configured ? $configured : wc_get_page_permalink( 'myaccount' );
        if ( ! $base_url ) {
            $base_url = home_url( '/' );
        }
        return apply_filters( 'lp_missing_portal_base_url', $base_url );
    }

    public static function get_magic_link_for_order( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }
        $args = array(
            'oid' => $order->get_id(),
            'key' => self::generate_signature( $order->get_id(), $order->get_billing_email() ),
        );
        return add_query_arg( $args, self::get_portal_base_url() );
    }

    public static function order_has_missing_items( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $data = self::get_item_data( $item );
            if ( ! empty( $data['missing'] ) && ! self::is_line_resolved( $data ) ) {
                return true;
            }
        }
        return false;
    }

    protected static function order_has_open_missing_items( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $data = self::get_item_data( $item );
            if ( ! empty( $data['missing'] ) && ! self::is_line_resolved( $data ) ) {
                return true;
            }
        }
        return false;
    }

    protected static function refresh_order_flags( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $has_data       = false;
        $has_open       = false;
        $needs_attention = false;

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $data        = self::get_item_data( $item );
            $meta_exists = $item->meta_exists( self::META_KEY );

            if ( $meta_exists || ! empty( $data['missing'] ) ) {
                $has_data = true;
            }

            if ( ! empty( $data['needs_attention'] ) && ! self::is_line_resolved( $data ) ) {
                $needs_attention = true;
            }

            if ( ! empty( $data['missing'] ) && ! self::is_line_resolved( $data ) ) {
                $has_open = true;
            }
        }

        $flags = array(
            self::OPTION_ATTENTION_FLAG    => $needs_attention,
            self::OPTION_HAS_MISSING_DATA  => $has_data,
            self::OPTION_HAS_OPEN_MISSING  => $has_open,
        );

        $changed = false;
        foreach ( $flags as $meta_key => $enabled ) {
            $current = 'yes' === $order->get_meta( $meta_key, true );
            if ( $current === $enabled ) {
                continue;
            }
            if ( $enabled ) {
                $order->update_meta_data( $meta_key, 'yes' );
            } else {
                $order->delete_meta_data( $meta_key );
            }
            $changed = true;
        }

        if ( $changed ) {
            $order->save();
        }
    }

    protected static function send_customer_email( $order ) {
        if ( ! $order instanceof WC_Order || ! self::order_has_missing_items( $order ) ) {
            return false;
        }
        $mailer = WC()->mailer();
        if ( ! $mailer ) {
            return false;
        }
        $emails = $mailer->get_emails();
        if ( empty( $emails['lp_missing_customer_email'] ) ) {
            return false;
        }
        return (bool) $emails['lp_missing_customer_email']->trigger( $order->get_id() );
    }

    protected static function send_reminder_email( $order, $item_id ) {
        if ( ! $order instanceof WC_Order || ! self::order_has_missing_items( $order ) ) {
            return false;
        }
        $mailer = WC()->mailer();
        if ( ! $mailer ) {
            return false;
        }
        $emails = $mailer->get_emails();
        if ( empty( $emails['lp_missing_customer_reminder'] ) ) {
            return false;
        }
        return (bool) $emails['lp_missing_customer_reminder']->trigger( $order->get_id(), $item_id );
    }

    public static function handle_admin_send_email() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        if ( ! $order_id ) {
            wp_die( esc_html__( 'Invalid order.', 'lp-missing' ) );
        }
        if ( ! self::current_user_can_edit_order( $order_id ) ) {
            wp_die( esc_html__( 'You do not have permission to send this email.', 'lp-missing' ) );
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'lp_missing_send_email_' . $order_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'lp-missing' ) );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            wp_die( esc_html__( 'Order not found.', 'lp-missing' ) );
        }

        $result = 'missing';
        if ( self::order_has_missing_items( $order ) ) {
            $result = self::send_customer_email( $order ) ? 'sent' : 'failed';
        }

        wp_safe_redirect( add_query_arg( array( 'lp_missing_email_sent' => $result ), self::get_order_edit_url( $order ) ) );
        exit;
    }

    public static function handle_apply_decision() {
        $order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
        $item_id  = isset( $_REQUEST['item_id'] ) ? absint( $_REQUEST['item_id'] ) : 0;
        $nonce    = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

        if ( ! $order_id || ! $item_id || ! wp_verify_nonce( $nonce, 'lp_missing_apply_' . $order_id . '_' . $item_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'lp-missing' ) );
        }

        if ( ! self::current_user_can_edit_order( $order_id ) ) {
            wp_die( esc_html__( 'You do not have permission to apply decisions.', 'lp-missing' ) );
        }

        $apply_type = isset( $_REQUEST['apply_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['apply_type'] ) ) : '';
        $apply_mode = isset( $_REQUEST['apply_mode'] ) ? sanitize_key( wp_unslash( $_REQUEST['apply_mode'] ) ) : '';
        $result     = array( 'status' => 'error', 'message' => __( 'Unknown action.', 'lp-missing' ) );

        // One apply per line at a time: a double click must not add the alternative or create a surcharge twice.
        if ( ! self::acquire_apply_lock( $item_id ) ) {
            $result = array( 'status' => 'error', 'message' => __( 'This decision is already being applied. Reload the order in a moment.', 'lp-missing' ) );
        } else {
            try {
                // Load after taking the lock so a request that just finished is seen.
                $order = wc_get_order( $order_id );
                $item  = $order instanceof WC_Order ? $order->get_item( $item_id, false ) : false;
                if ( ! $item instanceof WC_Order_Item_Product ) {
                    $result = array( 'status' => 'error', 'message' => __( 'Item not found.', 'lp-missing' ) );
                } elseif ( 'alternative' === $apply_type ) {
                    $result = self::apply_alternative_decision( $order, $item, $item_id, self::get_item_data( $item ), 'add' === $apply_mode ? 'add' : 'replace' );
                } elseif ( 'delete' === $apply_type ) {
                    $result = self::apply_delete_decision( $order, $item, $item_id, self::get_item_data( $item ), 'refund' === $apply_mode ? 'refund' : 'reduce' );
                }
            } finally {
                self::release_apply_lock( $item_id );
            }
        }

        // The message travels in a per-user transient, not in the URL, so a crafted link cannot show staff arbitrary text.
        set_transient( 'lp_missing_notice_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( 'lp_missing_apply', 'success' === $result['status'] ? 'success' : 'error', self::get_order_edit_url( $order_id ) ) );
        exit;
    }

    protected static function acquire_apply_lock( $item_id ) {
        global $wpdb;
        $name     = 'lp_missing_applying_' . absint( $item_id );
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        if ( null !== $existing && (int) $existing < time() - 2 * MINUTE_IN_SECONDS ) {
            // Stale lock left by a request that died.
            $wpdb->delete( $wpdb->options, array( 'option_name' => $name, 'option_value' => $existing ) );
        }
        // INSERT IGNORE is atomic on the unique option_name key, unlike add_option().
        $inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
        return 1 === (int) $inserted;
    }

    protected static function release_apply_lock( $item_id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->options, array( 'option_name' => 'lp_missing_applying_' . absint( $item_id ) ) );
    }

    protected static function apply_alternative_decision( $order, $item, $item_id, $data, $mode ) {
        if ( 'alt_pending' !== $data['status'] || empty( $data['selected_alt_id'] ) ) {
            return array( 'status' => 'error', 'message' => __( 'No customer-approved alternative to apply.', 'lp-missing' ) );
        }

        $alt_product = wc_get_product( $data['selected_alt_id'] );
        if ( ! $alt_product ) {
            return array( 'status' => 'error', 'message' => __( 'Selected alternative is no longer available.', 'lp-missing' ) );
        }

        $billable_qty         = self::get_item_billable_qty( $item );
        $previous_missing_qty = absint( $data['qty_missing'] );
        $qty_alt = $data['qty_alt'] ? absint( $data['qty_alt'] ) : $previous_missing_qty;
        $qty_alt = max( 1, min( $qty_alt, max( 1, $previous_missing_qty ), $billable_qty ) );
        $remaining_missing_qty = max( 0, $previous_missing_qty - $qty_alt );

        $settings             = self::get_settings();
        $price_handling_mode  = isset( $settings['alt_price_handling'] ) ? $settings['alt_price_handling'] : 'charge_customer';
        $original_product     = $item->get_product();
        $original_name        = $original_product ? $original_product->get_name() : $item->get_name();
        $pricing_snapshot     = self::scale_pricing_snapshot( self::get_frozen_pricing_snapshot( $order, $item, $data, $alt_product, $qty_alt ), $qty_alt );
        $original_unit_incl   = floatval( $pricing_snapshot['original_unit_incl'] );
        $alt_unit_incl        = floatval( $pricing_snapshot['alternative_unit_incl'] );
        $final_delta          = wc_format_decimal( $pricing_snapshot['delta_total_incl'], wc_get_price_decimals() );

        // Move exactly the original line's share (totals + per-rate taxes) for the applied quantity onto the alternative line,
        // so the order total and tax lines stay balanced; any price difference is handled separately below.
        $share = self::get_item_share( $item, $qty_alt );
        self::subtract_share_from_item( $item, $share );

        $new_qty = $item->get_quantity();
        if ( 'replace' === $mode ) {
            $new_qty = max( 0, $item->get_quantity() - $qty_alt );
            $item->set_quantity( $new_qty );
            $line_result = $new_qty > 0 ? __( 'original line quantity and totals reduced', 'lp-missing' ) : __( 'original line removed', 'lp-missing' );
        } else {
            // The original line keeps its quantity for reference; remember how many units no longer carry a price.
            $item->update_meta_data( self::MOVED_QTY_META, absint( $item->get_meta( self::MOVED_QTY_META, true ) ) + $qty_alt );
            $line_result = __( 'original line totals reduced', 'lp-missing' );
        }

        $alt_share = $share;
        if ( $alt_product->get_tax_class() !== $item->get_tax_class() ) {
            $alt_share = self::rebase_share_to_tax_class( $order, $share, $alt_product->get_tax_class() );
        }
        $alt_item_id = $order->add_product(
            $alt_product,
            $qty_alt,
            array(
                'subtotal' => $alt_share['subtotal'],
                'total'    => $alt_share['total'],
                'taxes'    => $alt_share['taxes'],
            )
        );
        if ( ! $alt_item_id ) {
            return array( 'status' => 'error', 'message' => __( 'Could not add selected alternative to the order.', 'lp-missing' ) );
        }

        $alt_item = $order->get_item( $alt_item_id, false );
        if ( $alt_item ) {
            $alt_item->add_meta_data( '_lp_missing_alt_pricing_source', 'original_comparison_unit', true );
            $alt_item->add_meta_data( '_lp_missing_alt_original_item_id', absint( $item_id ), true );
            $alt_item->save();
        }

        $surcharge_result = self::handle_price_difference_surcharge( $order, $final_delta, $original_name, $alt_product, $qty_alt, $pricing_snapshot, $price_handling_mode );

        $new_data = $data;
        $fully_resolved = $remaining_missing_qty < 1;
        $new_data['status'] = $fully_resolved ? 'alt_applied' : 'pending';
        $new_data['missing'] = ! $fully_resolved;
        $new_data['qty_missing'] = $remaining_missing_qty;
        $new_data['selected_alt_id'] = 0;
        $new_data['qty_alt'] = 0;
        $new_data['pricing_snapshot'] = array();
        $new_data['needs_attention'] = false;
        $new_data['reminder_scheduled_for'] = 0;
        $new_data['decision_made_at'] = $fully_resolved ? time() : 0;
        $new_data['resolved_at'] = $fully_resolved ? time() : 0;
        $new_data['last_updated'] = time();
        if ( ! $fully_resolved ) {
            // The remaining quantity starts a fresh customer round (email + reminders).
            $new_data['first_missing_at'] = time();
            $new_data['reminder_count'] = 0;
            $new_data['last_reminder_at'] = 0;
        }
        $new_data['stock_locked_qty'] = isset( $new_data['stock_locked_qty'] ) ? absint( $new_data['stock_locked_qty'] ) : 0;

        self::maybe_adjust_stock( $item, $data, $new_data, $order );
        $item->update_meta_data( self::META_KEY, $new_data );
        $item->save();
        if ( 'replace' === $mode ) {
            // Same stock bookkeeping WooCommerce does when staff change a line quantity (keeps _reduced_stock in sync).
            self::adjust_line_item_stock( $order, $item, $new_qty );
        }
        if ( $alt_item ) {
            // Reduces stock for the alternative (for paid/processing orders) and records _reduced_stock,
            // so WooCommerce restores it if the order is later cancelled or refunded.
            self::adjust_line_item_stock( $order, $alt_item, $qty_alt );
        }
        if ( 'replace' === $mode && $new_qty < 1 ) {
            $order->remove_item( $item_id );
        }
        self::recalculate_order_totals( $order );

        $note_parts   = array();
        $note_parts[] = sprintf( __( 'Applied customer-selected alternative %1$s (Qty %2$d).', 'lp-missing' ), $alt_product->get_name(), $qty_alt );
        $note_parts[] = sprintf( __( 'Mode: %s.', 'lp-missing' ), 'replace' === $mode ? __( 'Replace', 'lp-missing' ) : __( 'Add new line', 'lp-missing' ) );
        $note_parts[] = sprintf( __( 'Original product: %1$s. Alternative product: %2$s. Qty: %3$d.', 'lp-missing' ), $original_name, $alt_product->get_name(), $qty_alt );
        $note_parts[] = sprintf( __( 'Missing qty before apply: %1$d. Applied qty: %2$d. Remaining unresolved qty: %3$d.', 'lp-missing' ), $previous_missing_qty, $qty_alt, $remaining_missing_qty );
        $note_parts[] = sprintf( __( 'Line update result: %s.', 'lp-missing' ), $line_result );
        $note_parts[] = sprintf( __( 'Frozen unit amount used for comparison: original %1$s, alternative %2$s (captured at customer decision time).', 'lp-missing' ), wc_price( $original_unit_incl, array( 'currency' => $order->get_currency() ) ), wc_price( $alt_unit_incl, array( 'currency' => $order->get_currency() ) ) );
        $note_parts[] = sprintf( __( 'Final delta: %s.', 'lp-missing' ), wc_price( $final_delta, array( 'currency' => $order->get_currency() ) ) );
        $note_parts[] = sprintf( __( 'Price handling: %s.', 'lp-missing' ), 'store_covers' === $surcharge_result['mode'] ? __( 'Store covers any extra cost', 'lp-missing' ) : __( 'Customer is charged delta via separate surcharge order when positive', 'lp-missing' ) );
        if ( ! $fully_resolved ) {
            $note_parts[] = __( 'Missing item remains open with remaining quantity awaiting new customer decision.', 'lp-missing' );
        } else {
            $note_parts[] = __( 'Missing item is fully resolved.', 'lp-missing' );
        }
        if ( ! empty( $surcharge_result['summary_note'] ) ) {
            $note_parts[] = $surcharge_result['summary_note'];
        }
        $order->add_order_note( implode( ' ', $note_parts ) );

        do_action( 'lp_missing_item_updated', $order, $item_id, $new_data, $data );

        return array( 'status' => 'success', 'message' => $fully_resolved ? __( 'Alternative applied to the order.', 'lp-missing' ) : __( 'Alternative partially applied. Remaining missing quantity is still open.', 'lp-missing' ) );
    }

    protected static function apply_delete_decision( $order, $item, $item_id, $data, $mode ) {
        if ( ! self::can_apply_deletion( $data ) ) {
            return array( 'status' => 'error', 'message' => __( 'No customer-approved deletion to apply.', 'lp-missing' ) );
        }

        $billable_qty         = self::get_item_billable_qty( $item );
        $previous_missing_qty = absint( $data['qty_missing'] );
        $qty_remove = $previous_missing_qty ? $previous_missing_qty : $billable_qty;
        $qty_remove = max( 1, min( $qty_remove, $billable_qty ) );

        $share       = self::get_item_share( $item, $qty_remove );
        $new_qty     = $item->get_quantity();
        $line_result = __( 'line unchanged', 'lp-missing' );
        $stock_baseline = $data;

        if ( 'refund' === $mode ) {
            $refund_tax    = $share['taxes']['total'];
            $refund_amount = wc_format_decimal( (float) $share['total'] + array_sum( array_map( 'floatval', $refund_tax ) ), wc_get_price_decimals() );
            if ( $refund_amount > 0 ) {
                $refund = wc_create_refund( array(
                    'amount'         => $refund_amount,
                    'reason'         => __( 'Customer approved deletion of missing items.', 'lp-missing' ),
                    'order_id'       => $order->get_id(),
                    'line_items'     => array(
                        $item_id => array(
                            'qty'          => $qty_remove,
                            'refund_total' => $share['total'],
                            'refund_tax'   => $refund_tax,
                        ),
                    ),
                    'refund_payment' => false,
                    'restock_items'  => false,
                ) );
                if ( is_wp_error( $refund ) ) {
                    // Keep the line open so staff can retry or handle it manually.
                    $order->add_order_note( sprintf( __( 'Refund could not be created for missing-item deletion: %s', 'lp-missing' ), $refund->get_error_message() ) );
                    return array( 'status' => 'error', 'message' => sprintf( __( 'Refund could not be created: %s', 'lp-missing' ), $refund->get_error_message() ) );
                }
                $order = wc_get_order( $order->get_id() );
                $item  = $order ? $order->get_item( $item_id, false ) : null;
                if ( ! $order || ! $item ) {
                    return array( 'status' => 'error', 'message' => __( 'Order not found.', 'lp-missing' ) );
                }
                // A full refund moves the order to "refunded", which may already have released the stock lock.
                $stock_baseline = self::get_item_data( $item );
            }
            $line_result = sprintf( __( 'refund of %s recorded (line kept for accounting; pay it back via the payment provider)', 'lp-missing' ), wp_strip_all_tags( wc_price( $refund_amount, array( 'currency' => $order->get_currency() ) ) ) );
        } else {
            self::subtract_share_from_item( $item, $share );
            $new_qty = max( 0, $item->get_quantity() - $qty_remove );
            $item->set_quantity( $new_qty );
            $line_result = $new_qty > 0 ? __( 'line quantity and totals reduced', 'lp-missing' ) : __( 'line removed', 'lp-missing' );
        }

        $new_data = $data;
        $new_data['status'] = 'delete_applied';
        $new_data['missing'] = false;
        $new_data['qty_missing'] = 0;
        $new_data['pricing_snapshot'] = array();
        $new_data['needs_attention'] = false;
        $new_data['reminder_scheduled_for'] = 0;
        $new_data['resolved_at'] = time();
        $new_data['last_updated'] = time();
        $new_data['stock_locked_qty'] = isset( $new_data['stock_locked_qty'] ) ? absint( $new_data['stock_locked_qty'] ) : 0;

        self::maybe_adjust_stock( $item, $stock_baseline, $new_data, $order );
        $item->update_meta_data( self::META_KEY, $new_data );
        $item->save();
        if ( 'reduce' === $mode ) {
            self::adjust_line_item_stock( $order, $item, $new_qty );
            if ( $new_qty < 1 ) {
                $order->remove_item( $item_id );
            }
            self::recalculate_order_totals( $order );
        }

        $note = sprintf(
            __( 'Applied deletion for %1$s. Missing qty before apply: %2$d. Applied qty: %3$d. Remaining unresolved qty: %4$d. Mode: %5$s. Result: %6$s.', 'lp-missing' ),
            $item->get_name(),
            $previous_missing_qty,
            $qty_remove,
            0,
            'refund' === $mode ? __( 'Refund', 'lp-missing' ) : __( 'Reduce order totals', 'lp-missing' ),
            $line_result
        );
        $order->add_order_note( $note );

        do_action( 'lp_missing_item_updated', $order, $item_id, $new_data, $data );

        return array( 'status' => 'success', 'message' => __( 'Deletion applied to the order.', 'lp-missing' ) );
    }

    /**
     * Rebuild order tax lines from the line item tax data and re-sum totals, without re-rating taxes.
     */
    protected static function recalculate_order_totals( $order ) {
        $order->update_taxes();
        $order->calculate_totals( false );
    }

    /**
     * Sync a line's stock reduction with its (new) quantity the way WooCommerce does for edits in the order screen.
     * Only acts for orders whose stock WooCommerce has reduced (processing, on-hold, completed).
     */
    protected static function adjust_line_item_stock( $order, $item, $quantity ) {
        if ( ! function_exists( 'wc_maybe_adjust_line_item_product_stock' ) && defined( 'WC_ABSPATH' ) ) {
            include_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
        }
        if ( ! function_exists( 'wc_maybe_adjust_line_item_product_stock' ) ) {
            return;
        }
        $change = wc_maybe_adjust_line_item_product_stock( $item, max( 0, absint( $quantity ) ) );
        if ( $change && ! is_wp_error( $change ) && 'yes' === self::get_settings()['enable_stock_notes'] ) {
            $product = $item->get_product();
            $order->add_order_note( sprintf( __( 'Adjusted stock for %1$s: %2$s &rarr; %3$s (line quantity %4$d).', 'lp-missing' ), $product ? $product->get_name() : $item->get_name(), $change['from'], $change['to'], absint( $quantity ) ) );
        }
    }

    /**
     * Units on the line that still carry a price. Differs from the quantity after an "add as extra line" apply.
     */
    protected static function get_item_billable_qty( $item ) {
        $moved = absint( $item->get_meta( self::MOVED_QTY_META, true ) );
        return max( 1, absint( $item->get_quantity() ) - $moved );
    }

    /**
     * The part of a line's subtotal, total and per-rate taxes that belongs to $qty billable units.
     */
    protected static function get_item_share( $item, $qty ) {
        $billable = self::get_item_billable_qty( $item );
        $qty      = max( 0, min( absint( $qty ), $billable ) );
        $ratio    = $billable > 0 ? $qty / $billable : 0;
        $taxes    = $item->get_taxes();
        $share    = array(
            'qty'      => $qty,
            'subtotal' => wc_format_decimal( (float) $item->get_subtotal() * $ratio, wc_get_price_decimals() ),
            'total'    => wc_format_decimal( (float) $item->get_total() * $ratio, wc_get_price_decimals() ),
            'taxes'    => array(
                'total'    => array(),
                'subtotal' => array(),
            ),
        );
        foreach ( array( 'total', 'subtotal' ) as $type ) {
            if ( empty( $taxes[ $type ] ) || ! is_array( $taxes[ $type ] ) ) {
                continue;
            }
            foreach ( $taxes[ $type ] as $rate_id => $amount ) {
                $share['taxes'][ $type ][ $rate_id ] = wc_format_decimal( (float) $amount * $ratio );
            }
        }
        return $share;
    }

    protected static function subtract_share_from_item( $item, $share ) {
        $item->set_subtotal( wc_format_decimal( max( 0, (float) $item->get_subtotal() - (float) $share['subtotal'] ), wc_get_price_decimals() ) );
        $item->set_total( wc_format_decimal( max( 0, (float) $item->get_total() - (float) $share['total'] ), wc_get_price_decimals() ) );

        $taxes = $item->get_taxes();
        if ( empty( $taxes['total'] ) ) {
            return;
        }
        foreach ( array( 'total', 'subtotal' ) as $type ) {
            foreach ( $share['taxes'][ $type ] as $rate_id => $amount ) {
                if ( isset( $taxes[ $type ][ $rate_id ] ) ) {
                    $taxes[ $type ][ $rate_id ] = wc_format_decimal( max( 0, (float) $taxes[ $type ][ $rate_id ] - (float) $amount ) );
                }
            }
        }
        $item->set_taxes( $taxes );
    }

    /**
     * Unit price the customer was quoted for the original line, before coupon discounts, so a same-priced
     * alternative compares as "same price" also on discounted orders.
     */
    protected static function get_item_unit_price_incl_tax( $item ) {
        $qty   = self::get_item_billable_qty( $item );
        $total = floatval( $item->get_subtotal() ) + floatval( $item->get_subtotal_tax() );
        return $total / $qty;
    }

    protected static function get_item_unit_price_excl_tax( $item ) {
        $qty   = self::get_item_billable_qty( $item );
        $total = floatval( $item->get_subtotal() );
        return $total / $qty;
    }

    /**
     * The address WooCommerce taxes this order on (per the "Calculate tax based on" setting).
     */
    protected static function get_order_tax_location( $order ) {
        $based_on = get_option( 'woocommerce_tax_based_on', 'billing' );
        if ( 'shipping' === $based_on && ! $order->get_shipping_country() ) {
            $based_on = 'billing';
        }
        if ( 'base' === $based_on || ( 'billing' === $based_on && ! $order->get_billing_country() ) ) {
            return array(
                'country'  => WC()->countries->get_base_country(),
                'state'    => WC()->countries->get_base_state(),
                'postcode' => WC()->countries->get_base_postcode(),
                'city'     => WC()->countries->get_base_city(),
            );
        }
        $type = 'shipping' === $based_on ? 'shipping' : 'billing';
        return array(
            'country'  => $order->{"get_{$type}_country"}(),
            'state'    => $order->{"get_{$type}_state"}(),
            'postcode' => $order->{"get_{$type}_postcode"}(),
            'city'     => $order->{"get_{$type}_city"}(),
        );
    }

    /**
     * Price of one unit of $product for this order, taxed for the order's own address and VAT status
     * (not for whoever happens to run the request: admin, cron or a guest browser).
     */
    protected static function get_product_unit_prices_for_order( $order, $product ) {
        $excl = (float) wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'order' => $order ) );
        $incl = $excl;
        if ( $product->is_taxable() ) {
            $incl = $excl + array_sum( WC_Tax::calc_tax( $excl, self::get_order_tax_rates( $order, $product->get_tax_class() ), false ) );
        }
        return array(
            'incl' => $incl,
            'excl' => $excl,
        );
    }

    protected static function get_frozen_pricing_snapshot( $order, $item, $data, $alt_product = null, $qty = 0 ) {
        $qty                 = max( 1, absint( $qty ) );
        $snapshot            = isset( $data['pricing_snapshot'] ) && is_array( $data['pricing_snapshot'] ) ? $data['pricing_snapshot'] : array();
        $snapshot_alt_id     = isset( $snapshot['selected_alt_id'] ) ? absint( $snapshot['selected_alt_id'] ) : 0;
        $snapshot_qty        = isset( $snapshot['selected_qty'] ) ? absint( $snapshot['selected_qty'] ) : 0;
        $snapshot_is_usable  = ! empty( $snapshot['comparison_model'] ) && $snapshot_alt_id && $snapshot_qty;

        if ( $snapshot_is_usable && $snapshot_alt_id === absint( isset( $data['selected_alt_id'] ) ? $data['selected_alt_id'] : 0 ) ) {
            return wp_parse_args(
                $snapshot,
                array(
                    'selected_alt_id'     => $snapshot_alt_id,
                    'selected_qty'        => $snapshot_qty,
                    'comparison_model'    => 'incl_tax',
                    'original_unit_incl'  => 0,
                    'original_unit_excl'  => 0,
                    'alternative_unit_incl' => 0,
                    'alternative_unit_excl' => 0,
                    'delta_per_unit_incl' => 0,
                    'delta_per_unit_excl' => 0,
                    'delta_per_unit_tax'  => 0,
                    'delta_total_incl'    => 0,
                    'delta_total_excl'    => 0,
                    'delta_total_tax'     => 0,
                    'original_context'    => 'frozen',
                    'frozen_at'           => absint( $data['decision_made_at'] ),
                )
            );
        }

        if ( ! $alt_product && ! empty( $data['selected_alt_id'] ) ) {
            $alt_product = wc_get_product( $data['selected_alt_id'] );
        }

        $original_unit_incl = self::get_item_unit_price_incl_tax( $item );
        $original_unit_excl = self::get_item_unit_price_excl_tax( $item );
        $alt_prices         = $alt_product ? self::get_product_unit_prices_for_order( $order, $alt_product ) : array( 'incl' => 0.0, 'excl' => 0.0 );
        $alt_unit_incl      = $alt_prices['incl'];
        $alt_unit_excl      = $alt_prices['excl'];
        $delta_per_unit_incl = $alt_unit_incl - $original_unit_incl;
        $delta_per_unit_excl = $alt_unit_excl - $original_unit_excl;
        $delta_per_unit_tax  = $delta_per_unit_incl - $delta_per_unit_excl;

        return array(
            'selected_alt_id'       => absint( isset( $data['selected_alt_id'] ) ? $data['selected_alt_id'] : 0 ),
            'selected_qty'          => $qty,
            // We compare in incl-tax terms so portal and surcharge totals match what customers see.
            'comparison_model'      => 'incl_tax',
            'original_context'      => 'order_line_total',
            // Unit values are kept unrounded; only line totals are rounded, so the split does not drift with quantity.
            'original_unit_incl'    => wc_format_decimal( $original_unit_incl ),
            'original_unit_excl'    => wc_format_decimal( $original_unit_excl ),
            'alternative_unit_incl' => wc_format_decimal( $alt_unit_incl ),
            'alternative_unit_excl' => wc_format_decimal( $alt_unit_excl ),
            'delta_per_unit_incl'   => wc_format_decimal( $delta_per_unit_incl ),
            'delta_per_unit_excl'   => wc_format_decimal( $delta_per_unit_excl ),
            'delta_per_unit_tax'    => wc_format_decimal( $delta_per_unit_tax ),
            'delta_total_incl'      => wc_format_decimal( $delta_per_unit_incl * $qty, wc_get_price_decimals() ),
            'delta_total_excl'      => wc_format_decimal( $delta_per_unit_excl * $qty, wc_get_price_decimals() ),
            'delta_total_tax'       => wc_format_decimal( $delta_per_unit_tax * $qty, wc_get_price_decimals() ),
            'frozen_at'             => time(),
        );
    }

    public static function get_customer_first_name( $order ) {
        $name = trim( (string) $order->get_billing_first_name() );
        return '' === $name ? __( 'der', 'lp-missing' ) : $name;
    }

    protected static function get_alternative_price_delta( $order, $item, $alt_product, $qty = 1 ) {
        $qty = max( 1, absint( $qty ) );
        $data          = self::get_item_data( $item );
        $preview_data  = $data;
        $preview_data['selected_alt_id'] = $alt_product->get_id();
        $snapshot      = self::get_frozen_pricing_snapshot( $order, $item, $preview_data, $alt_product, $qty );
        $delta_unit    = floatval( $snapshot['delta_per_unit_incl'] );
        $delta_total   = floatval( $snapshot['delta_total_incl'] );

        // Treat sub-cent differences as "same price" so rounding noise is never shown as a surcharge.
        $threshold = 0.5 * pow( 10, -wc_get_price_decimals() );
        return array(
            'unit_delta_raw'  => $delta_unit,
            'total_delta_raw' => $delta_total,
            'unit_delta'      => self::plain_price( abs( $delta_unit ), $order ),
            'total_delta'     => self::plain_price( abs( $delta_total ), $order ),
            'direction'       => $delta_unit >= $threshold ? 'up' : ( $delta_unit <= -$threshold ? 'down' : 'same' ),
        );
    }

    /**
     * A formatted price as plain text (wc_price() returns HTML, which esc_html() would show as markup).
     */
    protected static function plain_price( $amount, $order ) {
        return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
    }

    /**
     * Scale a frozen snapshot's totals to the quantity actually applied (it may differ from the quantity chosen in the portal).
     */
    protected static function scale_pricing_snapshot( $snapshot, $qty ) {
        $qty = max( 1, absint( $qty ) );
        if ( absint( $snapshot['selected_qty'] ) === $qty ) {
            return $snapshot;
        }
        foreach ( array( 'incl', 'excl', 'tax' ) as $suffix ) {
            $snapshot[ 'delta_total_' . $suffix ] = wc_format_decimal( (float) $snapshot[ 'delta_per_unit_' . $suffix ] * $qty, wc_get_price_decimals() );
        }
        $snapshot['selected_qty'] = $qty;
        return $snapshot;
    }

    protected static function order_is_vat_exempt( $order ) {
        return 'yes' === $order->get_meta( 'is_vat_exempt', true );
    }

    protected static function get_order_tax_rates( $order, $tax_class ) {
        if ( ! wc_tax_enabled() || self::order_is_vat_exempt( $order ) ) {
            return array();
        }
        return WC_Tax::find_rates( array_merge( self::get_order_tax_location( $order ), array( 'tax_class' => $tax_class ) ) );
    }

    /**
     * Per-rate VAT contained in a gross amount for a tax class at this order's address (rate_id => tax).
     */
    protected static function split_gross_by_tax_class( $order, $gross, $tax_class ) {
        $rates = self::get_order_tax_rates( $order, $tax_class );
        if ( ! $rates ) {
            return array();
        }
        return array_map( 'wc_format_decimal', WC_Tax::calc_tax( (float) $gross, $rates, true ) );
    }

    /**
     * Keep the gross (what the customer paid) of a moved line share, but re-split net/VAT for another tax class
     * (e.g. 25% goods replaced by a 15% food item).
     */
    protected static function rebase_share_to_tax_class( $order, $share, $tax_class ) {
        if ( empty( $share['taxes']['total'] ) ) {
            return $share; // Untaxed line (tax off, exempt or export): nothing to re-split.
        }
        foreach ( array( 'total', 'subtotal' ) as $type ) {
            $gross = (float) $share[ $type ] + array_sum( array_map( 'floatval', $share['taxes'][ $type ] ) );
            $taxes = self::split_gross_by_tax_class( $order, $gross, $tax_class );
            $share[ $type ]          = wc_format_decimal( $gross - array_sum( $taxes ), wc_get_price_decimals() );
            $share['taxes'][ $type ] = $taxes;
        }
        return $share;
    }

    protected static function handle_price_difference_surcharge( $order, $difference, $original_product_name, $alt_product, $qty_alt, $pricing_snapshot, $price_handling_mode = 'charge_customer' ) {
        $alt_product_name = $alt_product->get_name();
        $difference = wc_format_decimal( $difference, wc_get_price_decimals() );
        $result = array(
            'mode'         => 'store_covers' === $price_handling_mode ? 'store_covers' : 'charge_customer',
            'summary_note' => '',
        );

        if ( $difference <= 0 ) {
            if ( $difference < 0 ) {
                $result['summary_note'] = sprintf( __( 'Cheaper alternative: no surcharge order created and no negative surcharge applied. Difference retained on original order totals: %1$s.', 'lp-missing' ), wc_price( $difference, array( 'currency' => $order->get_currency() ) ) );
            } else {
                $result['summary_note'] = __( 'No price delta between original and alternative; no surcharge order created.', 'lp-missing' );
            }
            return $result;
        }

        if ( 'store_covers' === $result['mode'] ) {
            $result['summary_note'] = sprintf( __( 'Store covers additional cost of %1$s; no surcharge order created.', 'lp-missing' ), wc_price( $difference, array( 'currency' => $order->get_currency() ) ) );
            return $result;
        }

        $surcharge_order = wc_create_order(
            array(
                'customer_id' => $order->get_customer_id(),
                'created_via' => 'lp_missing_surcharge',
            )
        );
        if ( is_wp_error( $surcharge_order ) ) {
            $result['summary_note'] = sprintf( __( 'Could not create surcharge order for price delta: %s', 'lp-missing' ), $surcharge_order->get_error_message() );
            return $result;
        }
        $surcharge_order->set_parent_id( $order->get_id() );
        $surcharge_order->set_currency( $order->get_currency() );
        $surcharge_order->set_address( $order->get_address( 'billing' ), 'billing' );
        $surcharge_order->set_address( $order->get_address( 'shipping' ), 'shipping' );

        $fee = new WC_Order_Item_Fee();
        $fee->set_name( sprintf( __( 'Mellomlegg for alternativ vare på ordre #%s', 'lp-missing' ), $order->get_order_number() ) );
        // The customer was quoted the gross (incl. VAT) delta; split it with the alternative's tax rates for this order,
        // so the invoice total equals the frozen portal price and the VAT lands on real tax rates.
        $taxes      = self::split_gross_by_tax_class( $order, $difference, $alt_product->get_tax_class() );
        $delta_excl = wc_format_decimal( (float) $difference - array_sum( $taxes ), wc_get_price_decimals() );
        $fee->set_tax_class( $alt_product->get_tax_class() );
        $fee->set_tax_status( $taxes ? 'taxable' : 'none' );
        $fee->set_amount( $delta_excl );
        $fee->set_total( $delta_excl );
        $fee->set_taxes( array( 'total' => $taxes ) );
        $surcharge_order->add_item( $fee );
        $surcharge_order->update_taxes();
        $surcharge_order->calculate_totals( false );
        $surcharge_order->set_status( 'pending' );
        $surcharge_order->add_order_note(
            sprintf(
                __( 'Surcharge created for alternative %1$s (Qty %2$d) chosen on order #%3$s. Original product: %4$s. Original unit: %5$s. Alternative unit: %6$s. Delta: %7$s.', 'lp-missing' ),
                $alt_product_name,
                $qty_alt,
                $order->get_order_number(),
                $original_product_name,
                wc_price( isset( $pricing_snapshot['original_unit_incl'] ) ? $pricing_snapshot['original_unit_incl'] : 0, array( 'currency' => $order->get_currency() ) ),
                wc_price( isset( $pricing_snapshot['alternative_unit_incl'] ) ? $pricing_snapshot['alternative_unit_incl'] : 0, array( 'currency' => $order->get_currency() ) ),
                wc_price( $difference, array( 'currency' => $order->get_currency() ) )
            )
        );
        $surcharge_order->save();

        $result['summary_note'] = sprintf( __( 'Created surcharge order #%1$s for delta %2$s.', 'lp-missing' ), $surcharge_order->get_order_number(), wc_price( $difference, array( 'currency' => $order->get_currency() ) ) );

        $mailer = WC()->mailer();
        if ( $mailer && isset( $mailer->emails['WC_Email_Customer_Invoice'] ) ) {
            $mailer->emails['WC_Email_Customer_Invoice']->trigger( $surcharge_order->get_id(), $surcharge_order );
        }

        return $result;
    }

    public static function admin_notices() {
        if ( ! self::is_order_screen() ) {
            return;
        }
        $email_status = isset( $_GET['lp_missing_email_sent'] ) ? sanitize_key( wp_unslash( $_GET['lp_missing_email_sent'] ) ) : '';
        $apply_status = isset( $_GET['lp_missing_apply'] ) ? sanitize_key( wp_unslash( $_GET['lp_missing_apply'] ) ) : '';
        $messages     = array();

        if ( $email_status ) {
            if ( 'sent' === $email_status ) {
                $messages[] = array( 'success', __( 'Missing items email sent to the customer.', 'lp-missing' ) );
            } elseif ( 'missing' === $email_status ) {
                $messages[] = array( 'warning', __( 'No missing items were found for this order.', 'lp-missing' ) );
            } elseif ( 'failed' === $email_status ) {
                $messages[] = array( 'error', __( 'The email could not be sent. Please check email settings.', 'lp-missing' ) );
            }
        }

        $notice_key = 'lp_missing_notice_' . get_current_user_id();
        $notice     = $apply_status ? get_transient( $notice_key ) : false;
        if ( is_array( $notice ) && isset( $notice['status'] ) ) {
            delete_transient( $notice_key );
            $success = 'success' === $notice['status'];
            $human   = $success ? __( 'Customer decision applied.', 'lp-missing' ) : __( 'Could not apply the customer decision.', 'lp-missing' );
            if ( ! empty( $notice['message'] ) ) {
                $human .= ' ' . $notice['message'];
            }
            $messages[] = array( $success ? 'success' : 'error', $human );
        }

        foreach ( $messages as $message ) {
            echo '<div class="notice notice-' . esc_attr( $message[0] ) . ' is-dismissible"><p>' . esc_html( $message[1] ) . '</p></div>';
        }
    }

    public static function register_settings_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Missing Items Settings', 'lp-missing' ),
            __( 'Missing Items Settings', 'lp-missing' ),
            'manage_woocommerce',
            'lp-missing-settings',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    public static function handle_settings_save() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage these settings.', 'lp-missing' ) );
        }
        check_admin_referer( 'lp_missing_settings' );

        $settings = array(
            'enable_stock_lock'           => ! empty( $_POST['lp_enable_stock_lock'] ) ? 'yes' : 'no',
            'enable_stock_notes'          => ! empty( $_POST['lp_enable_stock_notes'] ) ? 'yes' : 'no',
            'reminder_delay_days'         => isset( $_POST['lp_reminder_delay_days'] ) ? absint( $_POST['lp_reminder_delay_days'] ) : 3,
            'reminder_max_count'          => isset( $_POST['lp_reminder_max_count'] ) ? absint( $_POST['lp_reminder_max_count'] ) : 3,
            'reminder_max_age_days'       => isset( $_POST['lp_reminder_max_age_days'] ) ? absint( $_POST['lp_reminder_max_age_days'] ) : 7,
            'cleanup_resolved_after_days' => isset( $_POST['lp_cleanup_resolved_after_days'] ) ? absint( $_POST['lp_cleanup_resolved_after_days'] ) : 30,
            'show_stock_preview'          => ! empty( $_POST['lp_show_stock_preview'] ) ? 'yes' : 'no',
            'portal_base_url'             => isset( $_POST['lp_portal_base_url'] ) ? sanitize_text_field( wp_unslash( $_POST['lp_portal_base_url'] ) ) : '',
            'alt_price_handling'          => isset( $_POST['lp_alt_price_handling'] ) && 'store_covers' === $_POST['lp_alt_price_handling'] ? 'store_covers' : 'charge_customer',
        );

        self::update_settings( $settings );

        wp_safe_redirect( add_query_arg( 'updated', 'true', admin_url( 'admin.php?page=lp-missing-settings' ) ) );
        exit;
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings = self::get_settings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Missing Items Settings', 'lp-missing' ); ?></h1>
            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="updated notice is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'lp-missing' ); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'lp_missing_settings' ); ?>
                <input type="hidden" name="action" value="lp_missing_save_settings" />
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Lock stock for missing quantities', 'lp-missing' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="lp_enable_stock_lock" value="1" <?php checked( 'yes', $settings['enable_stock_lock'] ); ?> /> <?php esc_html_e( 'Adjust inventory when items are marked missing.', 'lp-missing' ); ?></label>
                                <p class="description"><?php esc_html_e( 'Disabling this will stop automatic stock decreases/increases for missing lines.', 'lp-missing' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Add inventory history notes', 'lp-missing' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="lp_enable_stock_notes" value="1" <?php checked( 'yes', $settings['enable_stock_notes'] ); ?> /> <?php esc_html_e( 'Write order notes when stock is adjusted.', 'lp-missing' ); ?></label>
                                <p class="description"><?php esc_html_e( 'Keep inventory audit trails concise by disabling stock adjustment notes if needed.', 'lp-missing' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Reminder spacing (days)', 'lp-missing' ); ?></th>
                            <td>
                                <input type="number" min="1" name="lp_reminder_delay_days" value="<?php echo esc_attr( $settings['reminder_delay_days'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'Controls how soon the first and subsequent reminders are sent after an item is marked missing.', 'lp-missing' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Maximum reminders before escalation', 'lp-missing' ); ?></th>
                            <td>
                                <input type="number" min="1" name="lp_reminder_max_count" value="<?php echo esc_attr( $settings['reminder_max_count'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'After this many reminders, the line will be flagged for manual follow-up.', 'lp-missing' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Maximum age before escalation (days)', 'lp-missing' ); ?></th>
                            <td>
                                <input type="number" min="1" name="lp_reminder_max_age_days" value="<?php echo esc_attr( $settings['reminder_max_age_days'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'If a line stays missing longer than this, it will be escalated even if reminder limits are not reached.', 'lp-missing' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Cleanup resolved records after (days)', 'lp-missing' ); ?></th>
                            <td>
                                <input type="number" min="<?php echo esc_attr( self::CLEANUP_MIN_DAYS ); ?>" name="lp_cleanup_resolved_after_days" value="<?php echo esc_attr( $settings['cleanup_resolved_after_days'] ); ?>" />
                                <p class="description"><?php printf( esc_html__( 'Resolved missing-item data will be removed after this many days (minimum %d days).', 'lp-missing' ), self::CLEANUP_MIN_DAYS ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Show stock in alternative previews', 'lp-missing' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="lp_show_stock_preview" value="1" <?php checked( 'yes', $settings['show_stock_preview'] ); ?> /> <?php esc_html_e( 'Display stock quantities and availability for suggested alternatives.', 'lp-missing' ); ?></label>
                                <p class="description"><?php esc_html_e( 'Disable to simplify the metabox for teams that do not need stock hints when picking alternatives.', 'lp-missing' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Price differences for alternatives', 'lp-missing' ); ?></th>
                            <td>
                                <fieldset>
                                    <label><input type="radio" name="lp_alt_price_handling" value="charge_customer" <?php checked( 'charge_customer', $settings['alt_price_handling'] ); ?> /> <?php esc_html_e( 'Charge the customer for more expensive alternatives.', 'lp-missing' ); ?></label><br />
                                    <label><input type="radio" name="lp_alt_price_handling" value="store_covers" <?php checked( 'store_covers', $settings['alt_price_handling'] ); ?> /> <?php esc_html_e( 'Store covers the extra cost (no charge to customer).', 'lp-missing' ); ?></label>
                                </fieldset>
                                <p class="description"><?php esc_html_e( 'Controls whether a separate surcharge order is raised when the chosen alternative costs more than the original item. Comparison values are frozen when the customer makes their choice.', 'lp-missing' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Customer portal base URL', 'lp-missing' ); ?></th>
                            <td>
                                <input type="url" class="regular-text" name="lp_portal_base_url" value="<?php echo esc_attr( $settings['portal_base_url'] ); ?>" placeholder="<?php echo esc_attr( wc_get_page_permalink( 'myaccount' ) ); ?>" />
                                <p class="description"><?php esc_html_e( 'Magic links in customer emails will point here. Leave blank to default to the My Account page.', 'lp-missing' ); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( __( 'Save settings', 'lp-missing' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function register_email_class( $emails ) {
        $emails['lp_missing_customer_email'] = new LP_Missing_Product_Email();
        $emails['lp_missing_customer_reminder'] = new LP_Missing_Product_Reminder_Email();
        return $emails;
    }

    public static function save_metabox( $order_id, $post_or_order = null ) {
        if ( ! isset( $_POST['lp_missing_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lp_missing_nonce'] ) ), 'lp_missing_metabox' ) ) {
            return;
        }
        if ( ! self::current_user_can_edit_order( $order_id ) ) {
            return;
        }
        if ( empty( $_POST['lp_missing_items'] ) || ! is_array( $_POST['lp_missing_items'] ) ) {
            return;
        }

        // Load fresh: WooCommerce saved the order form (billing email etc.) through its own order instance.
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $items = $order->get_items( 'line_item' );
        foreach ( $items as $item_id => $item ) {
            // Lines added after the page was rendered have no fields in this request; leave them untouched.
            if ( ! isset( $_POST['lp_missing_items'][ $item_id ] ) || ! is_array( $_POST['lp_missing_items'][ $item_id ] ) ) {
                continue;
            }
            $existing = self::get_item_data( $item );
            $posted   = $_POST['lp_missing_items'][ $item_id ];

            $missing = ! empty( $posted['missing'] );
            $was_missing = ! empty( $existing['missing'] );

            // Never write tracking data for lines that are not (and were not) missing.
            if ( ! $missing && ! $was_missing ) {
                continue;
            }

            $propose_delete = ! empty( $posted['propose_delete'] );
            $qty_missing = isset( $posted['qty_missing'] ) ? absint( $posted['qty_missing'] ) : 0;
            $notes = isset( $posted['notes'] ) ? wp_kses_post( wp_unslash( $posted['notes'] ) ) : '';
            $internal_notes = isset( $posted['internal_notes'] ) ? wp_kses_post( wp_unslash( $posted['internal_notes'] ) ) : '';
            $alt_ids = isset( $posted['alternatives'] ) ? self::sanitize_alt_ids( wp_unslash( $posted['alternatives'] ) ) : array();

            // A missing line always needs a usable quantity: default to (and cap at) the billable line quantity.
            $billable_qty = self::get_item_billable_qty( $item );
            if ( $missing ) {
                $qty_missing = $qty_missing < 1 ? $billable_qty : min( $qty_missing, $billable_qty );
            }

            $was_open = $was_missing && ! self::is_line_resolved( $existing );

            $new_data = $existing;
            $new_data['missing'] = $missing;
            $new_data['propose_delete'] = $missing && $propose_delete;
            $new_data['qty_missing'] = $missing ? $qty_missing : 0;
            $new_data['notes'] = $missing ? $notes : '';
            $new_data['internal_notes'] = $missing ? $internal_notes : '';
            $new_data['alternatives'] = $missing ? $alt_ids : array();

            if ( $missing && ! $was_open ) {
                // A new (or re-opened) case starts from a clean lifecycle, whatever status an earlier case left behind.
                $new_data['status'] = 'pending';
                $new_data['selected_alt_id'] = 0;
                $new_data['qty_alt'] = 0;
                $new_data['pricing_snapshot'] = array();
                $new_data['decision_made_at'] = 0;
                $new_data['first_missing_at'] = time();
                $new_data['reminder_count'] = 0;
                $new_data['last_reminder_at'] = 0;
                $new_data['reminder_scheduled_for'] = 0;
                $new_data['needs_attention'] = false;
                $new_data['resolved_at'] = 0;
            } elseif ( $missing && 'declined' === $existing['status'] ) {
                // New suggestions after the customer declined put the line back in the customer's hands.
                $alternatives_changed = $alt_ids !== $existing['alternatives'];
                $delete_newly_offered = $new_data['propose_delete'] && empty( $existing['propose_delete'] );
                if ( $alternatives_changed || $delete_newly_offered ) {
                    $new_data['status'] = 'pending';
                    $new_data['decision_made_at'] = 0;
                    $new_data['needs_attention'] = false;
                    $new_data['first_missing_at'] = time();
                    $new_data['reminder_count'] = 0;
                    $new_data['last_reminder_at'] = 0;
                }
            }

            if ( ! $missing ) {
                $new_data['status'] = 'cleared';
                $new_data['selected_alt_id'] = 0;
                $new_data['qty_alt'] = 0;
                $new_data['pricing_snapshot'] = array();
                $new_data['first_missing_at'] = 0;
                $new_data['reminder_count'] = 0;
                $new_data['last_reminder_at'] = 0;
                $new_data['reminder_scheduled_for'] = 0;
                $new_data['needs_attention'] = false;
                $new_data['resolved_at'] = time();
            } elseif ( ! self::is_line_resolved( $new_data ) ) {
                $new_data['resolved_at'] = 0;
            }

            self::maybe_adjust_stock( $item, $existing, $new_data, $order );

            if ( serialize( $existing ) === serialize( $new_data ) ) {
                continue;
            }

            $new_data['last_updated'] = time();
            $item->update_meta_data( self::META_KEY, $new_data );
            $item->save();
            do_action( 'lp_missing_item_updated', $order, $item_id, $new_data, $existing );
        }

        self::refresh_order_flags( $order );
    }

    protected static function maybe_adjust_stock( $item, $existing, &$new_data, $order ) {
        $product       = $item->get_product();
        $previous_lock = isset( $existing['stock_locked_qty'] ) ? absint( $existing['stock_locked_qty'] ) : 0;

        if ( ! $product || ! $product->managing_stock() ) {
            // Nothing can be (or was) taken from stock; never record a lock that was not applied.
            $new_data['stock_locked_qty'] = 0;
            return;
        }

        // Only lock while locking is enabled; an existing lock is always released, whatever the current setting.
        $new_lock = self::stock_lock_enabled() && ! empty( $new_data['missing'] ) ? absint( $new_data['qty_missing'] ) : 0;
        $delta    = $new_lock - $previous_lock;
        $new_data['stock_locked_qty'] = $new_lock;
        if ( 0 === $delta ) {
            return;
        }

        $settings   = self::get_settings();
        $add_notes  = 'yes' === $settings['enable_stock_notes'];

        if ( $delta > 0 ) {
            wc_update_product_stock( $product, $delta, 'decrease' );
            if ( $add_notes ) {
                $order->add_order_note( sprintf( __( 'Inventory decreased by %1$s for product %2$s. Reason: Missing-item stock lock delta.', 'lp-missing' ), $delta, $product->get_name() ) );
            }
        } else {
            wc_update_product_stock( $product, abs( $delta ), 'increase' );
            if ( $add_notes ) {
                $order->add_order_note( sprintf( __( 'Inventory increased by %1$s for product %2$s. Reason: Missing-item stock lock delta.', 'lp-missing' ), abs( $delta ), $product->get_name() ) );
            }
        }
    }

    /**
     * Give back a missing-item stock lock when the line leaves the plugin's flow some other way
     * (line deleted in the order editor, order cancelled/refunded/deleted).
     */
    protected static function release_item_lock( $item, $order ) {
        if ( ! $item instanceof WC_Order_Item_Product || ! $item->meta_exists( self::META_KEY ) ) {
            return false;
        }
        $data     = self::get_item_data( $item );
        $new_data = $data;
        if ( ! self::is_line_resolved( $data ) ) {
            $new_data['status'] = 'cleared';
            $new_data['reminder_scheduled_for'] = 0;
            $new_data['needs_attention'] = false;
            $new_data['resolved_at'] = time();
        }
        $new_data['missing'] = false;
        $new_data['qty_missing'] = 0;
        // With the case closed this gives back any recorded lock.
        self::maybe_adjust_stock( $item, $data, $new_data, $order );
        wp_clear_scheduled_hook( 'lp_missing_send_reminder', array( $order->get_id(), $item->get_id() ) );
        if ( serialize( $data ) === serialize( $new_data ) ) {
            return false;
        }
        $item->update_meta_data( self::META_KEY, $new_data );
        $item->save();
        return true;
    }

    public static function handle_order_item_deleted( $item_id ) {
        $item = WC_Order_Factory::get_order_item( $item_id );
        if ( ! $item instanceof WC_Order_Item_Product ) {
            return;
        }
        $order = $item->get_order();
        if ( $order instanceof WC_Order ) {
            self::release_item_lock( $item, $order );
        }
    }

    public static function handle_order_closed( $order_id, $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $changed = false;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $changed = self::release_item_lock( $item, $order ) || $changed;
        }
        if ( $changed ) {
            self::refresh_order_flags( $order );
        }
    }

    protected static function is_line_resolved( $data ) {
        $resolved_statuses = array( 'alt_applied', 'delete_applied', 'cleared' );
        return empty( $data['missing'] ) || in_array( $data['status'], $resolved_statuses, true );
    }

    protected static function has_customer_decision( $data ) {
        $decision_statuses = array( 'alt_pending', 'delete_pending', 'declined', 'alt_applied', 'delete_applied' );
        return in_array( $data['status'], $decision_statuses, true );
    }

    public static function get_awaiting_item_names( $order ) {
        $names = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( self::is_awaiting_customer( self::get_item_data( $item ) ) ) {
                $names[] = $item->get_name();
            }
        }
        return $names;
    }

    protected static function is_awaiting_customer( $data ) {
        return ! empty( $data['missing'] ) && ! self::is_line_resolved( $data ) && ! self::has_customer_decision( $data );
    }

    protected static function schedule_reminder_for_item( $order, $item_id, $delay_days = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $item = $order->get_item( $item_id, false );
        if ( ! $item ) {
            return;
        }
        $data = self::get_item_data( $item );
        if ( self::is_line_resolved( $data ) ) {
            return;
        }

        $settings = self::get_settings();
        $delay_days = is_null( $delay_days ) ? $settings['reminder_delay_days'] : absint( $delay_days );
        if ( $delay_days < 1 ) {
            $delay_days = $settings['reminder_delay_days'];
        }

        while ( $scheduled = wp_next_scheduled( 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) ) ) {
            wp_unschedule_event( $scheduled, 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) );
        }

        $timestamp = time() + ( $delay_days * DAY_IN_SECONDS );
        wp_schedule_single_event( $timestamp, 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) );

        $data['reminder_scheduled_for'] = $timestamp;
        if ( empty( $data['first_missing_at'] ) ) {
            $data['first_missing_at'] = time();
        }
        $item->update_meta_data( self::META_KEY, $data );
        $item->save();
    }

    protected static function cancel_reminder_for_item( $order, $item_id ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        while ( $scheduled = wp_next_scheduled( 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) ) ) {
            wp_unschedule_event( $scheduled, 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) );
        }
        $item = $order->get_item( $item_id, false );
        if ( $item ) {
            $data = self::get_item_data( $item );
            $data['reminder_scheduled_for'] = 0;
            $data['needs_attention'] = false;
            $item->update_meta_data( self::META_KEY, $data );
            $item->save();
        }
    }

    protected static function schedule_cleanup_for_order( $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $settings  = self::get_settings();
        $threshold = max( self::CLEANUP_MIN_DAYS, absint( $settings['cleanup_resolved_after_days'] ) ) * DAY_IN_SECONDS;
        $items     = $order->get_items( 'line_item' );
        $next_time = 0;

        foreach ( $items as $item ) {
            // Lines without plugin data (never missing, already purged, added alternatives) need no cleanup.
            if ( ! $item->meta_exists( self::META_KEY ) ) {
                continue;
            }
            $data = self::get_item_data( $item );
            if ( ! self::is_line_resolved( $data ) ) {
                continue;
            }
            $resolved_at = ! empty( $data['resolved_at'] ) ? $data['resolved_at'] : ( $data['last_updated'] ? $data['last_updated'] : time() );
            $candidate   = $resolved_at + $threshold;
            if ( 0 === $next_time || $candidate < $next_time ) {
                $next_time = $candidate;
            }
        }

        $existing = wp_next_scheduled( self::CLEANUP_HOOK, array( $order->get_id() ) );

        if ( ! $next_time ) {
            while ( $existing ) {
                wp_unschedule_event( $existing, self::CLEANUP_HOOK, array( $order->get_id() ) );
                $existing = wp_next_scheduled( self::CLEANUP_HOOK, array( $order->get_id() ) );
            }
            return;
        }

        if ( $existing && $existing <= $next_time ) {
            return;
        }

        if ( $existing ) {
            wp_unschedule_event( $existing, self::CLEANUP_HOOK, array( $order->get_id() ) );
        }

        wp_schedule_single_event( $next_time, self::CLEANUP_HOOK, array( $order->get_id() ) );
    }

    public static function maybe_schedule_daily_cleanup() {
        if ( ! wp_next_scheduled( self::DAILY_CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_CLEANUP_HOOK );
        }
    }

    protected static function get_order_last_activity_timestamp( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return 0;
        }

        $modified = $order->get_date_modified();
        $created  = $order->get_date_created();

        $modified_ts = $modified ? $modified->getTimestamp() : 0;
        $created_ts  = $created ? $created->getTimestamp() : 0;

        return max( $modified_ts, $created_ts );
    }

    protected static function purge_missing_data_for_order( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }

        $changed = false;

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item->meta_exists( self::META_KEY ) ) {
                continue;
            }
            $item->delete_meta_data( self::META_KEY );
            $item->save();
            $changed = true;
        }

        wp_clear_scheduled_hook( self::CLEANUP_HOOK, array( $order->get_id() ) );
        // Always drop the order-level flags, also when no line data was left, so the order leaves the cleanup queue.
        $order->delete_meta_data( self::OPTION_ATTENTION_FLAG );
        $order->delete_meta_data( self::OPTION_HAS_OPEN_MISSING );
        $order->delete_meta_data( self::OPTION_HAS_MISSING_DATA );
        $order->save();

        return $changed;
    }

    public static function run_daily_cleanup() {
        $settings  = self::get_settings();
        $threshold = max( self::CLEANUP_MIN_DAYS, absint( $settings['cleanup_resolved_after_days'] ) ) * DAY_IN_SECONDS;
        $limit     = max( 1, absint( apply_filters( 'lp_missing_daily_cleanup_limit', self::DAILY_CLEANUP_LIMIT ) ) );
        $cutoff    = time() - $threshold;
        $skipped   = 0;
        $purged    = 0;

        // meta_key/meta_value work for both storages (meta_query is ignored by legacy wc_get_orders()).
        // Purged orders lose the flag and drop out of the result, so the offset only has to step over skipped ones.
        for ( $batch = 0; $batch < 10 && $purged < $limit; $batch++ ) {
            $orders = wc_get_orders( array(
                'type'          => 'shop_order',
                'limit'         => $limit,
                'offset'        => $skipped,
                'return'        => 'objects',
                'orderby'       => 'modified',
                'order'         => 'ASC',
                'meta_key'      => self::OPTION_HAS_MISSING_DATA,
                'meta_value'    => 'yes',
                'date_modified' => '<' . $cutoff,
            ) );

            if ( empty( $orders ) ) {
                break;
            }

            foreach ( $orders as $order ) {
                if ( self::order_has_open_missing_items( $order ) ) {
                    $skipped++;
                    continue;
                }

                $last_activity = self::get_order_last_activity_timestamp( $order );
                if ( ! $last_activity || ( time() - $last_activity ) < $threshold ) {
                    $skipped++;
                    continue;
                }

                if ( self::purge_missing_data_for_order( $order ) ) {
                    $order->add_order_note( __( 'Missing-item data automatically cleaned up after resolution.', 'lp-missing' ) );
                }
                $purged++;
            }

            if ( count( $orders ) < $limit ) {
                break;
            }
        }
    }

    protected static function refresh_order_attention_flag( $order ) {
        self::refresh_order_flags( $order );
    }

    public static function handle_item_updated( $order, $item_id, $new_data, $old_data ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $item = $order->get_item( $item_id, false );
        if ( ! $item ) {
            // The line was removed while applying a decision: drop its reminders and refresh the order-level flags.
            wp_clear_scheduled_hook( 'lp_missing_send_reminder', array( $order->get_id(), $item_id ) );
            self::refresh_order_flags( $order );
            self::schedule_cleanup_for_order( $order );
            return;
        }

        $resolved = self::is_line_resolved( $new_data );

        if ( $resolved ) {
            if ( empty( $new_data['resolved_at'] ) ) {
                $new_data['resolved_at'] = time();
                $item->update_meta_data( self::META_KEY, $new_data );
                $item->save();
            }
            self::cancel_reminder_for_item( $order, $item_id );
            self::refresh_order_flags( $order );
            self::schedule_cleanup_for_order( $order );
            return;
        }

        // The line (again) needs a choice from the customer: newly marked, re-opened, new suggestions after a decline,
        // or quantity left over after a partial apply. Notify once per order per request and start the reminder cycle.
        if ( self::is_awaiting_customer( $new_data ) && ! self::is_awaiting_customer( $old_data ) ) {
            if ( empty( self::$auto_email_sent[ $order->get_id() ] ) ) {
                self::send_customer_email( $order );
                self::$auto_email_sent[ $order->get_id() ] = true;
            }
            self::schedule_reminder_for_item( $order, $item_id );
            self::refresh_order_flags( $order );
            return;
        }

        if ( self::has_customer_decision( $new_data ) ) {
            self::cancel_reminder_for_item( $order, $item_id );
            self::refresh_order_flags( $order );
            return;
        }

        self::refresh_order_flags( $order );
    }

    public static function handle_scheduled_reminder( $order_id, $item_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $item = $order->get_item( $item_id, false );
        if ( ! $item ) {
            return;
        }
        $data = self::get_item_data( $item );

        if ( self::is_line_resolved( $data ) ) {
            self::cancel_reminder_for_item( $order, $item_id );
            self::refresh_order_attention_flag( $order );
            return;
        }

        if ( empty( $data['missing'] ) || self::has_customer_decision( $data ) || $order->has_status( array( 'cancelled', 'refunded', 'failed', 'trash' ) ) ) {
            // Nothing to remind about: the customer already answered and the line waits for staff.
            self::cancel_reminder_for_item( $order, $item_id );
            self::refresh_order_attention_flag( $order );
            return;
        }

        // Lines of one order share a reminder: if one went out for this order within the hour, count it for this line too.
        $recent_key = 'lp_missing_reminded_' . $order->get_id();
        if ( get_transient( $recent_key ) ) {
            $sent = true;
        } else {
            $sent = self::send_reminder_email( $order, $item_id );
            if ( $sent ) {
                set_transient( $recent_key, 1, HOUR_IN_SECONDS );
            }
        }

        $data['first_missing_at'] = $data['first_missing_at'] ? $data['first_missing_at'] : time();
        if ( $sent ) {
            $data['reminder_count'] = $data['reminder_count'] + 1;
            $data['last_reminder_at'] = time();
        }
        $data['reminder_scheduled_for'] = 0;

        $item->update_meta_data( self::META_KEY, $data );
        $item->save();

        if ( ! $sent ) {
            self::schedule_reminder_for_item( $order, $item_id );
            return;
        }

        $age_days = $data['first_missing_at'] ? floor( ( time() - $data['first_missing_at'] ) / DAY_IN_SECONDS ) : 0;
        $settings = self::get_settings();
        if ( $data['reminder_count'] >= $settings['reminder_max_count'] || $age_days >= $settings['reminder_max_age_days'] ) {
            if ( empty( $data['needs_attention'] ) ) {
                $note = sprintf(
                    __( 'Manual follow-up needed: Item %1$s has %2$d reminder(s) over %3$d day(s).', 'lp-missing' ),
                    $item->get_name(),
                    $data['reminder_count'],
                    max( 0, $age_days )
                );
                $order->add_order_note( $note );
            }
            $data['needs_attention'] = true;
            $item->update_meta_data( self::META_KEY, $data );
            $item->save();
            $order->update_meta_data( self::OPTION_ATTENTION_FLAG, 'yes' );
            $order->save();
            return;
        }

        self::schedule_reminder_for_item( $order, $item_id );
    }

    public static function handle_cleanup_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $settings  = self::get_settings();
        $threshold = max( self::CLEANUP_MIN_DAYS, absint( $settings['cleanup_resolved_after_days'] ) ) * DAY_IN_SECONDS;
        $items     = $order->get_items( 'line_item' );
        $next_time = 0;
        $changed   = false;

        foreach ( $items as $item ) {
            if ( ! $item->meta_exists( self::META_KEY ) ) {
                continue;
            }
            $data = self::get_item_data( $item );
            if ( ! self::is_line_resolved( $data ) ) {
                continue;
            }

            $resolved_at = ! empty( $data['resolved_at'] ) ? $data['resolved_at'] : ( $data['last_updated'] ? $data['last_updated'] : time() );
            $age         = time() - $resolved_at;

            if ( $age >= $threshold ) {
                $item->delete_meta_data( self::META_KEY );
                $item->save();
                $changed = true;
                continue;
            }

            $candidate = $resolved_at + $threshold;
            if ( 0 === $next_time || $candidate < $next_time ) {
                $next_time = $candidate;
            }
        }

        if ( $changed ) {
            self::refresh_order_attention_flag( $order );
        }

        if ( $next_time ) {
            $existing = wp_next_scheduled( self::CLEANUP_HOOK, array( $order->get_id() ) );
            if ( $existing && $existing > $next_time ) {
                wp_unschedule_event( $existing, self::CLEANUP_HOOK, array( $order->get_id() ) );
            }
        }

        self::schedule_cleanup_for_order( $order );
    }

    protected static function get_order_for_shortcode( $atts ) {
        $order_id = isset( $atts['order_id'] ) ? absint( $atts['order_id'] ) : 0;
        $email    = isset( $atts['email'] ) ? sanitize_email( $atts['email'] ) : '';
        if ( ! $order_id || ! $email ) {
            return array( null, '' );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return array( null, '' );
        }
        if ( strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
            return array( null, '' );
        }
        // Attributes are written by whoever edits the page: only staff or the order's own customer may act through them.
        $user = wp_get_current_user();
        $is_owner = $user && $user->exists() && ( ( $order->get_customer_id() && $order->get_customer_id() === $user->ID ) || strtolower( $user->user_email ) === strtolower( $order->get_billing_email() ) );
        if ( ! $is_owner && ! current_user_can( 'edit_shop_orders' ) ) {
            return array( null, '' );
        }
        return array( $order, $email );
    }

    protected static function get_order_from_magic_link() {
        $order_id = isset( $_GET['oid'] ) ? absint( $_GET['oid'] ) : 0;
        $key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        if ( ! $order_id || ! $key ) {
            return array( null, '', '' );
        }
        $order = wc_get_order( $order_id );
        // wc_get_order() also returns refunds, which have no billing email.
        if ( ! $order instanceof WC_Order ) {
            return array( null, '', __( 'Lenken er ugyldig. Bruk lenken i den nyeste e-posten fra oss, eller kontakt oss.', 'lp-missing' ) );
        }
        if ( ! self::validate_signature( $order_id, $order->get_billing_email(), $key ) ) {
            return array( null, '', __( 'Lenken er ugyldig. Bruk lenken i den nyeste e-posten fra oss, eller kontakt oss.', 'lp-missing' ) );
        }
        return array( $order, $order->get_billing_email(), '' );
    }

    protected static function verify_customer_email( $order, &$error ) {
        $error         = '';
        $billing_email = $order->get_billing_email();
        if ( ! $billing_email ) {
            return false;
        }
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user && strtolower( $user->user_email ) === strtolower( $billing_email ) ) {
                return true;
            }
        }
        // Portal forms carry a signed token after a successful verification, so choices are not bounced back to this step.
        if ( isset( $_POST['lp_missing_verified'] ) && self::validate_verification_token( $order->get_id(), $billing_email, sanitize_text_field( wp_unslash( $_POST['lp_missing_verified'] ) ) ) ) {
            return true;
        }
        if ( isset( $_POST['lp_missing_verify_email'] ) ) {
            $nonce = isset( $_POST['lp_missing_verify_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lp_missing_verify_nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'lp_missing_verify_' . $order->get_id() ) ) {
                $error = __( 'Sikkerhetssjekk feilet. Prøv igjen.', 'lp-missing' );
                return false;
            }
            $submitted = sanitize_email( wp_unslash( $_POST['lp_missing_verify_email'] ) );
            if ( $submitted && strtolower( $submitted ) === strtolower( $billing_email ) ) {
                return true;
            }
            $error = __( 'E-postadressen stemmer ikke med denne ordren.', 'lp-missing' );
        }
        return false;
    }

    protected static function rate_limit_key( $order_id, $email ) {
        return 'lp_missing_rate_' . $order_id . '_' . md5( strtolower( $email ) );
    }

    protected static function handle_portal_action( $order, $email ) {
        if ( empty( $_POST['lp_missing_action'] ) || empty( $_POST['lp_missing_nonce'] ) ) {
            return array( 'message' => '', 'status' => '' );
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lp_missing_nonce'] ) ), 'lp_missing_portal_' . $order->get_id() ) ) {
            return array( 'message' => __( 'Sikkerhetssjekk feilet.', 'lp-missing' ), 'status' => 'error' );
        }
        if ( strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
            return array( 'message' => __( 'E-postadressen stemmer ikke med denne ordren.', 'lp-missing' ), 'status' => 'error' );
        }
        $key = self::rate_limit_key( $order->get_id(), $email );
        $count = (int) get_transient( $key );
        if ( $count >= 5 ) {
            return array( 'message' => __( 'Du gjorde mange valg på kort tid. Vent litt og prøv igjen.', 'lp-missing' ), 'status' => 'error' );
        }
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

        $action    = sanitize_text_field( wp_unslash( $_POST['lp_missing_action'] ) );
        $item_id   = isset( $_POST['lp_missing_item_id'] ) ? absint( $_POST['lp_missing_item_id'] ) : 0;
        $items     = $order->get_items( 'line_item' );
        $item      = isset( $items[ $item_id ] ) ? $items[ $item_id ] : null;
        if ( ! $item ) {
            return array( 'message' => __( 'Ugyldig vare valgt.', 'lp-missing' ), 'status' => 'error' );
        }

        $existing = self::get_item_data( $item );
        if ( empty( $existing['missing'] ) || self::is_line_resolved( $existing ) ) {
            return array( 'message' => __( 'Denne varen er ikke markert som manglende.', 'lp-missing' ), 'status' => 'error' );
        }
        if ( ! self::is_awaiting_customer( $existing ) ) {
            // Re-submitted form (e.g. page refresh): keep the first decision and its frozen price.
            return array( 'message' => __( 'Valget ditt for denne varen er allerede registrert.', 'lp-missing' ), 'status' => 'success' );
        }

        $new = $existing;
        $new['last_updated'] = time();

        if ( 'accept_alt' === $action ) {
            $alt_id = isset( $_POST['lp_missing_alt_id'] ) ? absint( $_POST['lp_missing_alt_id'] ) : 0;
            $qty_alt = isset( $_POST['lp_missing_alt_qty'] ) ? absint( $_POST['lp_missing_alt_qty'] ) : 0;
            if ( $qty_alt < 1 || $qty_alt > $existing['qty_missing'] ) {
                return array( 'message' => __( 'Ugyldig antall valgt.', 'lp-missing' ), 'status' => 'error' );
            }
            if ( ! in_array( $alt_id, $existing['alternatives'], true ) ) {
                return array( 'message' => __( 'Dette alternativet kan ikke velges.', 'lp-missing' ), 'status' => 'error' );
            }
            $alt_product = wc_get_product( $alt_id );
            if ( ! $alt_product ) {
                return array( 'message' => __( 'Dette alternativet er ikke lenger tilgjengelig.', 'lp-missing' ), 'status' => 'error' );
            }
            $new['status'] = 'alt_pending';
            $new['selected_alt_id'] = $alt_id;
            $new['qty_alt'] = $qty_alt;
            $new['pricing_snapshot'] = self::get_frozen_pricing_snapshot( $order, $item, $new, $alt_product, $qty_alt );
        } elseif ( 'decline_all' === $action ) {
            $new['status'] = 'declined';
            $new['selected_alt_id'] = 0;
            $new['qty_alt'] = 0;
            $new['pricing_snapshot'] = array();
        } elseif ( 'accept_delete' === $action ) {
            if ( empty( $existing['propose_delete'] ) ) {
                return array( 'message' => __( 'Sletting er ikke tilgjengelig for denne varen.', 'lp-missing' ), 'status' => 'error' );
            }
            $new['status'] = 'delete_pending';
            $new['pricing_snapshot'] = array();
        } else {
            return array( 'message' => __( 'Ukjent handling.', 'lp-missing' ), 'status' => 'error' );
        }

        if ( in_array( $new['status'], array( 'alt_pending', 'delete_pending' ), true ) ) {
            $new['needs_attention'] = false;
            $new['reminder_scheduled_for'] = 0;
            $new['decision_made_at'] = time();
            $new['resolved_at'] = 0;
        }

        if ( ! self::is_line_resolved( $new ) ) {
            $new['resolved_at'] = 0;
        }

        if ( serialize( $existing ) !== serialize( $new ) ) {
            $item->update_meta_data( self::META_KEY, $new );
            $item->save();
            do_action( 'lp_missing_item_updated', $order, $item_id, $new, $existing );
        }

        return array( 'message' => __( 'Takk! Valget ditt er lagret 💛', 'lp-missing' ), 'status' => 'success' );
    }

    /**
     * The default portal URL is the My Account page, which does not carry the shortcode by itself:
     * show the portal there when a magic link is opened.
     */
    public static function maybe_inject_portal( $content ) {
        if ( is_admin() || empty( $_GET['oid'] ) || empty( $_GET['key'] ) || ! is_main_query() || ! in_the_loop() ) {
            return $content;
        }
        if ( has_shortcode( $content, self::SHORTCODE ) || ! function_exists( 'wc_get_page_id' ) || ! is_page( wc_get_page_id( 'myaccount' ) ) ) {
            return $content;
        }
        return '[' . self::SHORTCODE . ']' . "\n\n" . $content;
    }

    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'order_id' => 0,
            'email'    => '',
        ), $atts, self::SHORTCODE );

        list( $order, $email, $link_error ) = self::get_order_from_magic_link();
        $using_magic   = $order instanceof WC_Order;
        $verify_error  = '';
        $response      = array( 'message' => '', 'status' => '' );

        if ( $link_error ) {
            return '<div class="lp-missing-portal-error">' . esc_html( $link_error ) . '</div>';
        }

        if ( ! $using_magic ) {
            list( $order, $email ) = self::get_order_for_shortcode( $atts );
            if ( ! $order ) {
                return '<div class="lp-missing-portal-error">' . esc_html__( 'Fant ikke ordren, eller e-postadressen stemmer ikke.', 'lp-missing' ) . '</div>';
            }
            $email_verified = true;
        } else {
            $email_verified = self::verify_customer_email( $order, $verify_error );
        }

        if ( $using_magic && ! $email_verified ) {
            ob_start();
            if ( $verify_error ) {
                echo '<div class="lp-missing-portal-error">' . esc_html( $verify_error ) . '</div>';
            }
            echo '<div class="lp-missing-portal-verify">';
            echo '<p>' . esc_html__( 'Bekreft e-postadressen du brukte i kassen for å fortsette.', 'lp-missing' ) . '</p>';
            echo '<form method="post">';
            wp_nonce_field( 'lp_missing_verify_' . $order->get_id(), 'lp_missing_verify_nonce' );
            echo '<label>' . esc_html__( 'E-postadresse', 'lp-missing' ) . ' <input type="email" name="lp_missing_verify_email" required /></label> ';
            echo '<button type="submit">' . esc_html__( 'Fortsett', 'lp-missing' ) . '</button>';
            echo '</form>';
            echo '</div>';
            return ob_get_clean();
        }

        $email    = $order->get_billing_email();
        $verification_token = $using_magic ? self::generate_verification_token( $order->get_id(), $email ) : '';
        $response = self::handle_portal_action( $order, $email );
        $items    = $order->get_items( 'line_item' );
        $missing_items = array();
        $alt_ids = array();
        foreach ( $items as $item_id => $item ) {
            $data = self::get_item_data( $item );
            if ( ! empty( $data['missing'] ) && ! self::is_line_resolved( $data ) ) {
                $missing_items[ $item_id ] = array( 'item' => $item, 'data' => $data );
                if ( ! empty( $data['alternatives'] ) ) {
                    $alt_ids = array_merge( $alt_ids, $data['alternatives'] );
                }
            }
        }
        if ( empty( $missing_items ) ) {
            return '<div class="lp-missing-portal-empty">' . esc_html__( 'Alt er i orden 🎉 Ingen valg venter på deg nå.', 'lp-missing' ) . '</div>';
        }

        $alt_products = array();
        if ( $alt_ids ) {
            $products = wc_get_products( array( 'include' => array_values( array_unique( $alt_ids ) ), 'limit' => -1, 'type' => array_merge( array_keys( wc_get_product_types() ), array( 'variation' ) ) ) );
            foreach ( $products as $product_obj ) {
                $alt_products[ $product_obj->get_id() ] = $product_obj;
            }
        }

        ob_start();
        if ( ! empty( $response['message'] ) ) {
            $class = 'success' === $response['status'] ? 'lp-message-success' : 'lp-message-error';
            echo '<div class="' . esc_attr( $class ) . '">' . esc_html( $response['message'] ) . '</div>';
        }
        $customer_name = self::get_customer_first_name( $order );
        $store_covers  = 'store_covers' === self::get_settings()['alt_price_handling'];
        echo '<style>
            .lp-missing-portal{font-family:inherit;display:grid;gap:12px}
            .lp-portal-card{border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fff}
            .lp-portal-muted{color:#6b7280;font-size:14px}
            .lp-pill{display:inline-block;background:#f3f4f6;border-radius:999px;padding:3px 10px;font-size:12px;margin-top:6px}
            .lp-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
            .lp-btn{border:1px solid #d1d5db;background:#111827;color:#fff;border-radius:8px;padding:8px 12px;cursor:pointer}
            .lp-btn--secondary{background:#fff;color:#111827}
            .lp-message-success,.lp-message-error{border-radius:10px;padding:10px 12px;margin-bottom:10px}
            .lp-message-success{background:#ecfdf5;border:1px solid #10b981}
            .lp-message-error{background:#fef2f2;border:1px solid #ef4444}
        </style>';
        echo '<div class="lp-missing-portal">';
        echo '<div class="lp-portal-card"><strong>' . esc_html( sprintf( __( 'Hei %s 👋', 'lp-missing' ), $customer_name ) ) . '</strong><div class="lp-portal-muted">' . esc_html__( 'Vi hjelper deg å velge hva som skal skje med varer som mangler. Prisforskjell fryses når du velger, slik at den ikke endres senere.', 'lp-missing' ) . '</div></div>';
        foreach ( $missing_items as $item_id => $payload ) {
            $item = $payload['item'];
            $data = $payload['data'];
            $product = $item->get_product();
            $product_name = $product ? $product->get_name() : $item->get_name();
            echo '<div class="lp-portal-card lp-portal-item">';
            echo '<strong>' . esc_html( $product_name ) . '</strong>';
            echo '<div class="lp-portal-muted">' . sprintf( esc_html__( 'Bestilt: %1$s stk · Mangler: %2$s stk', 'lp-missing' ), esc_html( $item->get_quantity() ), esc_html( $data['qty_missing'] ) ) . '</div>';
            if ( ! empty( $data['notes'] ) ) {
                echo '<div class="lp-pill">' . esc_html( $data['notes'] ) . '</div>';
            }
            if ( ! empty( $data['alternatives'] ) ) {
                echo '<div style="margin-top:8px;"><em>' . esc_html__( 'Forslag til alternativ:', 'lp-missing' ) . '</em><ul style="margin:6px 0 0; padding-left:18px;">';
                foreach ( $data['alternatives'] as $alt_id ) {
                    $alt_product = isset( $alt_products[ $alt_id ] ) ? $alt_products[ $alt_id ] : wc_get_product( $alt_id );
                    if ( ! $alt_product ) {
                        continue;
                    }
                    $delta = self::get_alternative_price_delta( $order, $item, $alt_product, $data['qty_missing'] );
                    $delta_text = __( 'Samme pris som original vare', 'lp-missing' );
                    if ( 'up' === $delta['direction'] ) {
                        $delta_text = $store_covers
                            ? sprintf( __( 'Koster %1$s mer per stk, men butikken dekker mellomlegget. Du betaler ikke noe ekstra.', 'lp-missing' ), $delta['unit_delta'] )
                            : sprintf( __( 'Koster %1$s mer per stk (%2$s mer for %3$s stk). Mellomlegget faktureres i en egen ordre.', 'lp-missing' ), $delta['unit_delta'], $delta['total_delta'], $data['qty_missing'] );
                    } elseif ( 'down' === $delta['direction'] ) {
                        $delta_text = sprintf( __( 'Rimeligere enn originalen (%1$s mindre per stk). Ordren beholder opprinnelig pris.', 'lp-missing' ), $delta['unit_delta'] );
                    }
                    echo '<li>';
                    echo '<a href="' . esc_url( $alt_product->get_permalink() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $alt_product->get_name() ) . '</a>';
                    $sku = $alt_product->get_sku();
                    if ( $sku ) {
                        echo ' <small>(' . esc_html( $sku ) . ')</small>';
                    }
                    echo '<div class="lp-portal-muted">' . esc_html( $delta_text ) . '</div>';
                    echo '</li>';
                }
                echo '</ul></div>';
            }

            if ( self::has_customer_decision( $data ) ) {
                echo '<div style="margin-top:8px;">' . esc_html__( 'Tusen takk! Valget ditt er registrert 💚 Teamet vårt oppdaterer ordren snart.', 'lp-missing' ) . '</div>';
                if ( 'alt_pending' === $data['status'] && ! empty( $data['selected_alt_id'] ) ) {
                    $alt_product = isset( $alt_products[ $data['selected_alt_id'] ] ) ? $alt_products[ $data['selected_alt_id'] ] : wc_get_product( $data['selected_alt_id'] );
                    if ( $alt_product ) {
                        $selected_qty = $data['qty_alt'] ? $data['qty_alt'] : $data['qty_missing'];
                        $delta = self::get_alternative_price_delta( $order, $item, $alt_product, $selected_qty );
                        echo '<div><em>' . esc_html__( 'Du valgte:', 'lp-missing' ) . '</em> ' . esc_html( $alt_product->get_name() ) . ' &times; ' . esc_html( $selected_qty ) . '</div>';
                        if ( 'up' === $delta['direction'] ) {
                            $locked_text = $store_covers
                                ? __( 'Pris låst ved valg: %s mer totalt, som butikken dekker.', 'lp-missing' )
                                : __( 'Pris låst ved valg: %s mer totalt. Mellomlegget faktureres i en egen ordre.', 'lp-missing' );
                            echo '<div class="lp-portal-muted">' . esc_html( sprintf( $locked_text, $delta['total_delta'] ) ) . '</div>';
                        } elseif ( 'down' === $delta['direction'] ) {
                            echo '<div class="lp-portal-muted">' . esc_html( sprintf( __( 'Rimeligere enn originalen (%s mindre totalt). Ordren beholder opprinnelig pris.', 'lp-missing' ), $delta['total_delta'] ) ) . '</div>';
                        } else {
                            echo '<div class="lp-portal-muted">' . esc_html__( 'Pris: ingen forskjell.', 'lp-missing' ) . '</div>';
                        }
                    }
                } elseif ( 'delete_pending' === $data['status'] ) {
                    echo '<div><em>' . esc_html__( 'Du godkjente å fjerne denne varen.', 'lp-missing' ) . '</em></div>';
                }
            } else {
                echo '<form method="post" class="lp-actions">';
                wp_nonce_field( 'lp_missing_portal_' . $order->get_id(), 'lp_missing_nonce' );
                echo '<input type="hidden" name="lp_missing_item_id" value="' . absint( $item_id ) . '" />';
                if ( $verification_token ) {
                    echo '<input type="hidden" name="lp_missing_verified" value="' . esc_attr( $verification_token ) . '" />';
                }

                if ( ! empty( $data['alternatives'] ) ) {
                    echo '<div>';
                    echo '<label>' . esc_html__( 'Velg alternativ:', 'lp-missing' ) . ' ';
                    echo '<select name="lp_missing_alt_id">';
                    foreach ( $data['alternatives'] as $alt_id ) {
                        $alt_product = isset( $alt_products[ $alt_id ] ) ? $alt_products[ $alt_id ] : wc_get_product( $alt_id );
                        if ( ! $alt_product ) {
                            continue;
                        }
                        $delta = self::get_alternative_price_delta( $order, $item, $alt_product, $data['qty_missing'] );
                        $option_label = $alt_product->get_name();
                        if ( 'up' === $delta['direction'] ) {
                            $option_label .= ' (+' . $delta['unit_delta'] . ' /stk)';
                        } elseif ( 'down' === $delta['direction'] ) {
                            $option_label .= ' (-' . $delta['unit_delta'] . ' /stk)';
                        } else {
                            $option_label .= ' (' . __( 'samme pris', 'lp-missing' ) . ')';
                        }
                        echo '<option value="' . absint( $alt_id ) . '">' . esc_html( $option_label ) . '</option>';
                    }
                    echo '</select></label> ';
                    echo '<label>' . esc_html__( 'Antall', 'lp-missing' ) . ' <input type="number" min="1" max="' . esc_attr( $data['qty_missing'] ) . '" name="lp_missing_alt_qty" value="' . esc_attr( $data['qty_missing'] ) . '" style="width:70px;" /></label> ';
                    echo '<div class="lp-portal-muted">' . esc_html__( 'Du kan velge deler av manglende antall nå. Resten blir stående åpen til vi får nytt valg.', 'lp-missing' ) . '</div>';
                    echo '<button class="lp-btn" type="submit" name="lp_missing_action" value="accept_alt">' . esc_html__( '✅ Velg dette alternativet', 'lp-missing' ) . '</button>';
                    echo '</div>';
                }

                echo '<div>';
                echo '<button class="lp-btn lp-btn--secondary" type="submit" name="lp_missing_action" value="decline_all" formnovalidate>' . esc_html__( '❌ Nei takk til alternativer', 'lp-missing' ) . '</button>';
                echo '</div>';

                if ( ! empty( $data['propose_delete'] ) ) {
                    echo '<div>';
                    echo '<button class="lp-btn lp-btn--secondary" type="submit" name="lp_missing_action" value="accept_delete" formnovalidate>' . esc_html__( '🗑️ Fjern denne varen fra ordren', 'lp-missing' ) . '</button>';
                    echo '</div>';
                }
                echo '</form>';
            }

            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * Count orders with an open missing-item case. Uses meta_key/meta_value, which (unlike meta_query)
     * wc_get_orders() honours for both legacy post storage and HPOS. An escalated line is always an open line.
     */
    protected static function count_orders_with_open_missing() {
        $result = wc_get_orders( array(
            'type'       => 'shop_order',
            'limit'      => 1,
            'paginate'   => true,
            'return'     => 'ids',
            'meta_key'   => self::OPTION_HAS_OPEN_MISSING,
            'meta_value' => 'yes',
        ) );

        if ( is_object( $result ) && isset( $result->total ) ) {
            return absint( $result->total );
        }

        if ( is_array( $result ) && isset( $result['total'] ) ) {
            return absint( $result['total'] );
        }

        return 0;
    }

    protected static function is_missing_view_requested() {
        return isset( $_GET['lp_missing_view'] ) && 'open' === sanitize_key( wp_unslash( $_GET['lp_missing_view'] ) );
    }

    public static function add_missing_orders_view( $views ) {
        $count = self::count_orders_with_open_missing();
        $url   = add_query_arg( 'lp_missing_view', 'open', self::get_orders_list_url() );
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
            'key'     => self::OPTION_HAS_OPEN_MISSING,
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
            'key'     => self::OPTION_HAS_OPEN_MISSING,
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
        $order = self::resolve_order( $post_id_or_order );
        if ( ! $order ) {
            echo '&mdash;';
            return;
        }

        $has_open   = 'yes' === $order->get_meta( self::OPTION_HAS_OPEN_MISSING );
        $attention  = 'yes' === $order->get_meta( self::OPTION_ATTENTION_FLAG );
        $has_data   = 'yes' === $order->get_meta( self::OPTION_HAS_MISSING_DATA );

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

class LP_Missing_Product_Email extends WC_Email {

    public function __construct() {
        $this->id             = 'lp_missing_customer_email';
        $this->customer_email = true;
        $this->title          = __( 'Manglende varer – kundeportal', 'lp-missing' );
        $this->description    = __( 'E-post som ber kunden velge løsning for manglende varer i en sikker portal.', 'lp-missing' );
        $this->heading        = __( 'Vi trenger et raskt valg fra deg 💛', 'lp-missing' );
        $this->subject        = __( 'Vi mangler noen varer i ordre #{order_number}', 'lp-missing' );
        $this->placeholders   = array(
            '{order_number}' => '',
            '{magic_link}'   => '',
        );
        parent::__construct();
    }

    public function trigger( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || ! LP_Missing_Product_Handler::order_has_missing_items( $order ) ) {
            return false;
        }
        $this->object     = $order;
        $this->recipient  = $order->get_billing_email();
        $this->placeholders['{order_number}'] = $order->get_order_number();
        $this->placeholders['{magic_link}']   = LP_Missing_Product_Handler::get_magic_link_for_order( $order );

        if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
            return false;
        }

        return $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
    }

    public function get_content_html() {
        ob_start();
        wc_get_template( 'emails/email-header.php', array( 'email_heading' => $this->get_heading(), 'email' => $this ) );
        $name = LP_Missing_Product_Handler::get_customer_first_name( $this->object );
        ?>
        <p><?php printf( esc_html__( 'Hei %1$s 👋 Vi har dessverre ikke alt på lager i ordre %2$s.', 'lp-missing' ), esc_html( $name ), esc_html( $this->object->get_order_number() ) ); ?></p>
        <p><?php esc_html_e( 'Trykk på knappen under for å velge et alternativ. Det tar bare et minutt.', 'lp-missing' ); ?></p>
        <p><?php esc_html_e( 'På valgsiden ser du tydelig om et alternativ koster mer, mindre eller det samme som originalen.', 'lp-missing' ); ?></p>
        <p>
            <a class="button" href="<?php echo esc_url( $this->placeholders['{magic_link}'] ); ?>"><?php esc_html_e( 'Åpne valgside', 'lp-missing' ); ?></a>
        </p>
        <p><?php esc_html_e( 'Hvis knappen ikke virker, kopier denne lenken inn i nettleseren:', 'lp-missing' ); ?><br />
            <a href="<?php echo esc_url( $this->placeholders['{magic_link}'] ); ?>"><?php echo esc_html( $this->placeholders['{magic_link}'] ); ?></a>
        </p>
        <?php
        wc_get_template( 'emails/email-footer.php', array( 'email' => $this ) );
        return ob_get_clean();
    }

    public function get_content_plain() {
        $name = LP_Missing_Product_Handler::get_customer_first_name( $this->object );
        $lines = array(
            sprintf( __( 'Hei %1$s! Vi mangler noen varer i ordre %2$s.', 'lp-missing' ), $name, $this->object->get_order_number() ),
            __( 'Velg hva som passer best for deg i den sikre lenken under.', 'lp-missing' ),
            __( 'Du får en enkel prisoversikt som viser om alternativet blir dyrere eller billigere.', 'lp-missing' ),
            $this->placeholders['{magic_link}'],
        );
        return implode( "\n\n", $lines );
    }
}

class LP_Missing_Product_Reminder_Email extends WC_Email {

    protected $item_name = '';

    public function __construct() {
        $this->id             = 'lp_missing_customer_reminder';
        $this->customer_email = true;
        $this->title          = __( 'Påminnelse – manglende varer', 'lp-missing' );
        $this->description    = __( 'Påminnelse til kunden om å velge løsning for manglende varer.', 'lp-missing' );
        $this->heading        = __( 'Liten påminnelse fra oss 💌', 'lp-missing' );
        $this->subject        = __( 'Påminnelse: vi venter på valget ditt for ordre #{order_number}', 'lp-missing' );
        $this->placeholders   = array(
            '{order_number}' => '',
            '{magic_link}'   => '',
            '{item_name}'    => '',
        );
        parent::__construct();
    }

    public function trigger( $order_id, $item_id = 0 ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || ! LP_Missing_Product_Handler::order_has_missing_items( $order ) ) {
            return false;
        }
        $item = $order->get_item( $item_id, false );
        if ( ! $item ) {
            return false;
        }
        $this->object     = $order;
        $this->recipient  = $order->get_billing_email();
        // One reminder covers every line still waiting for the customer.
        $names            = LP_Missing_Product_Handler::get_awaiting_item_names( $order );
        $this->item_name  = $names ? implode( ', ', $names ) : $item->get_name();
        $this->placeholders['{order_number}'] = $order->get_order_number();
        $this->placeholders['{magic_link}']   = LP_Missing_Product_Handler::get_magic_link_for_order( $order );
        $this->placeholders['{item_name}']    = $this->item_name;

        if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
            return false;
        }

        return $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
    }

    public function get_content_html() {
        ob_start();
        wc_get_template( 'emails/email-header.php', array( 'email_heading' => $this->get_heading(), 'email' => $this ) );
        $name = LP_Missing_Product_Handler::get_customer_first_name( $this->object );
        ?>
        <p><?php printf( esc_html__( 'Hei %1$s 👋 Vi trenger fortsatt valget ditt for %2$s i ordre %3$s.', 'lp-missing' ), esc_html( $name ), esc_html( $this->placeholders['{item_name}'] ), esc_html( $this->object->get_order_number() ) ); ?></p>
        <p><?php esc_html_e( 'Åpne lenken under for å fullføre valget ditt.', 'lp-missing' ); ?></p>
        <p><?php esc_html_e( 'Du ser samtidig en enkel prisoversikt (mer, mindre eller samme pris).', 'lp-missing' ); ?></p>
        <p>
            <a class="button" href="<?php echo esc_url( $this->placeholders['{magic_link}'] ); ?>"><?php esc_html_e( 'Åpne valgside', 'lp-missing' ); ?></a>
        </p>
        <p><?php esc_html_e( 'Hvis knappen ikke virker, kopier denne lenken inn i nettleseren:', 'lp-missing' ); ?><br />
            <a href="<?php echo esc_url( $this->placeholders['{magic_link}'] ); ?>"><?php echo esc_html( $this->placeholders['{magic_link}'] ); ?></a>
        </p>
        <?php
        wc_get_template( 'emails/email-footer.php', array( 'email' => $this ) );
        return ob_get_clean();
    }

    public function get_content_plain() {
        $name = LP_Missing_Product_Handler::get_customer_first_name( $this->object );
        $lines = array(
            sprintf( __( 'Hei %1$s! Vi trenger fortsatt valget ditt for %2$s i ordre %3$s.', 'lp-missing' ), $name, $this->placeholders['{item_name}'], $this->object->get_order_number() ),
            __( 'Bruk lenken under for å velge alternativ.', 'lp-missing' ),
            __( 'Lenken viser tydelig om alternativet blir dyrere, billigere eller lik pris.', 'lp-missing' ),
            $this->placeholders['{magic_link}'],
        );
        return implode( "\n\n", $lines );
    }
}

LP_Missing_Product_Handler::init();

    }
}

add_action( 'plugins_loaded', 'lp_missing_bootstrap_plugin', 20 );

// The daily cleanup is a recurring event; without this it keeps being re-armed after deactivation.
register_deactivation_hook(
    __FILE__,
    function() {
        wp_clear_scheduled_hook( 'lp_missing_daily_cleanup' );
    }
);
