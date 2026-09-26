<?php
/**
 * Missing-item stock locks and line stock bookkeeping.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Stock {
    public static function register() {
        // Release stock locks and stop reminders when a line/order leaves the flow outside this plugin.
        add_action( 'woocommerce_before_delete_order_item', array( __CLASS__, 'handle_order_item_deleted' ) );
        add_action( 'woocommerce_delete_order_item', array( __CLASS__, 'handle_order_item_after_delete' ) );
        add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'handle_order_closed' ), 10, 2 );
        add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'handle_order_closed' ), 10, 2 );
        // A failed payment can still be retried: give the stock back but keep the case; take it again when paid.
        add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'handle_order_failed' ), 10, 2 );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'handle_order_recovered' ), 10, 4 );
        // Fires before the order's items are deleted, for both storages (a permanently deleted order).
        add_action( 'woocommerce_delete_order_items', array( __CLASS__, 'handle_order_closed' ) );
        // Units moved to a replacement line (add mode) are not shipped from the original product.
        add_action( 'woocommerce_reduce_order_stock', array( __CLASS__, 'sync_moved_units_for_order' ) );
        add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', array( __CLASS__, 'adjust_moved_line_stock' ), 10, 3 );
    }

    /** Set while this plugin runs WooCommerce's line stock sync itself. */
    protected static $adjusting = false;

    /*
     * Stock model: units confirmed missing never come back into stock. The order's own stock reduction already
     * covers them; the missing-item lock only holds them apart while the case is open and is released when the case
     * is resolved. So when the plugin shrinks a line, only its _reduced_stock record follows the new quantity
     * (no restock), which also keeps WooCommerce's own stock sync on a later order "Update" from restocking them.
     */

    public static function order_stock_reduced( $order ) {
        return $order instanceof WC_Order && (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() );
    }

    public static function shrink_line_reduced_stock( $order, $item, $quantity ) {
        if ( ! self::order_stock_reduced( $order ) || '' === $item->get_meta( '_reduced_stock', true ) ) {
            return;
        }
        $reduced = wc_stock_amount( $item->get_meta( '_reduced_stock', true ) );
        $new     = min( $reduced, max( 0, absint( $quantity ) ) );
        if ( $new !== $reduced ) {
            $item->update_meta_data( '_reduced_stock', $new );
            $item->save();
        }
    }

    /**
     * A replacement added as a separate line (add mode) leaves the original line's quantity as it was, for reference.
     * Its moved units are treated like a shrunk line: only the _reduced_stock record follows (no restock), so a
     * cancellation gives back only the units that were really picked. Runs once per moved unit (tracked in a meta).
     */
    public static function sync_moved_units( $order, $item ) {
        $moved  = absint( $item->get_meta( LP_Missing_Plugin::MOVED_QTY_META, true ) );
        $synced = absint( $item->get_meta( '_lp_missing_moved_stock_synced', true ) );
        if ( $moved <= $synced || ! self::order_stock_reduced( $order ) || '' === $item->get_meta( '_reduced_stock', true ) ) {
            return;
        }
        $reduced = wc_stock_amount( $item->get_meta( '_reduced_stock', true ) );
        $item->update_meta_data( '_reduced_stock', max( 0, $reduced - ( $moved - $synced ) ) );
        $item->update_meta_data( '_lp_missing_moved_stock_synced', $moved );
        $item->save();
    }

    /**
     * The order's stock was reduced (at payment) after a replacement was added: sync the moved units now.
     */
    public static function sync_moved_units_for_order( $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( $item->get_meta( LP_Missing_Plugin::MOVED_QTY_META, true ) ) {
                self::sync_moved_units( $order, $item );
            }
        }
    }

    /**
     * WooCommerce syncs a line's stock with its quantity when staff save the order items. On a line with moved units
     * that sync must count only the units still billed on it, or it would take the moved units from stock again.
     */
    public static function adjust_moved_line_stock( $prevent, $item, $item_quantity = -1 ) {
        if ( $prevent || self::$adjusting || ! $item instanceof WC_Order_Item_Product ) {
            return $prevent;
        }
        $moved = absint( $item->get_meta( LP_Missing_Plugin::MOVED_QTY_META, true ) );
        if ( ! $moved || ! function_exists( 'wc_maybe_adjust_line_item_product_stock' ) ) {
            return $prevent;
        }
        $order = $item->get_order();
        if ( $order instanceof WC_Order ) {
            self::sync_moved_units( $order, $item );
        }
        $item_quantity = (int) $item_quantity;
        if ( 0 === $item_quantity ) {
            // The line is being deleted: WooCommerce gives back what the line took.
            return $prevent;
        }
        $billable = ( $item_quantity > 0 ? $item_quantity : absint( $item->get_quantity() ) ) - $moved;
        if ( $billable > 0 ) {
            self::$adjusting = true;
            try {
                wc_maybe_adjust_line_item_product_stock( $item, $billable );
            } finally {
                self::$adjusting = false;
            }
        }
        return true;
    }

    /**
     * Take stock for a line the plugin added (the alternative) the way WooCommerce does for lines added to an order
     * whose stock is already reduced, recording _reduced_stock so a cancellation restores it. Orders that have not
     * reduced stock yet (unpaid) reduce it for all lines at payment instead.
     */
    public static function reduce_stock_for_added_line( $order, $item ) {
        if ( ! self::order_stock_reduced( $order ) ) {
            return;
        }
        if ( ! function_exists( 'wc_maybe_adjust_line_item_product_stock' ) && defined( 'WC_ABSPATH' ) ) {
            include_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
        }
        if ( ! function_exists( 'wc_maybe_adjust_line_item_product_stock' ) ) {
            return;
        }
        $change = wc_maybe_adjust_line_item_product_stock( $item );
        if ( $change && ! is_wp_error( $change ) && 'yes' === LP_Missing_Settings::get( 'enable_stock_notes' ) ) {
            $product = $item->get_product();
            $order->add_order_note( sprintf( __( 'Adjusted stock for %1$s: %2$s &rarr; %3$s (line quantity %4$d).', 'lp-missing' ), $product ? $product->get_name() : $item->get_name(), $change['from'], $change['to'], $item->get_quantity() ) );
        }
    }

    public static function maybe_adjust_stock( $item, $existing, &$new_data, $order ) {
        $product       = $item->get_product();
        $previous_lock = isset( $existing['stock_locked_qty'] ) ? absint( $existing['stock_locked_qty'] ) : 0;

        if ( ! $product || ! $product->managing_stock() ) {
            // Nothing can be (or was) taken from stock; never record a lock that was not applied.
            $new_data['stock_locked_qty'] = 0;
            return;
        }

        // Only lock while locking is enabled; an existing lock is always released, whatever the current setting.
        $new_lock = LP_Missing_Settings::stock_lock_enabled() && ! empty( $new_data['missing'] ) ? absint( $new_data['qty_missing'] ) : 0;
        $delta    = $new_lock - $previous_lock;
        $new_data['stock_locked_qty'] = $new_lock;
        if ( 0 === $delta ) {
            return;
        }

        $settings   = LP_Missing_Settings::get_settings();
        $add_notes  = 'yes' === $settings['enable_stock_notes'];

        if ( $delta > 0 ) {
            wc_update_product_stock( $product, $delta, 'decrease' );
            if ( $add_notes ) {
                /* translators: 1: quantity, 2: product name */
                $order->add_order_note( sprintf( __( 'Stock of %2$s lowered by %1$s (held for the missing item).', 'lp-missing' ), $delta, $product->get_name() ) );
            }
        } else {
            wc_update_product_stock( $product, abs( $delta ), 'increase' );
            if ( $add_notes ) {
                /* translators: 1: quantity, 2: product name */
                $order->add_order_note( sprintf( __( 'Stock of %2$s raised by %1$s (hold for the missing item released).', 'lp-missing' ), abs( $delta ), $product->get_name() ) );
            }
        }
    }

    /**
     * Give back a missing-item stock lock when the line leaves the plugin's flow some other way
     * (line deleted in the order editor, order cancelled/refunded/deleted).
     */
    public static function release_item_lock( $item, $order ) {
        if ( ! $item instanceof WC_Order_Item_Product || ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
            return false;
        }
        $data     = LP_Missing_Line::get_item_data( $item );
        $new_data = $data;
        if ( ! LP_Missing_Line::is_line_resolved( $data ) ) {
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
        $item->update_meta_data( LP_Missing_Plugin::META_KEY, $new_data );
        $item->save();
        return true;
    }

    /** @var array Order IDs whose flags must be refreshed once a deleted line is gone (item_id => order_id). */
    protected static $deleted_item_orders = array();

    public static function handle_order_item_deleted( $item_id ) {
        $item = WC_Order_Factory::get_order_item( $item_id );
        if ( ! $item instanceof WC_Order_Item_Product ) {
            return;
        }
        $order = $item->get_order();
        if ( $order instanceof WC_Order && $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
            self::release_item_lock( $item, $order );
            self::$deleted_item_orders[ $item_id ] = $order->get_id();
        }
    }

    public static function handle_order_item_after_delete( $item_id ) {
        if ( empty( self::$deleted_item_orders[ $item_id ] ) ) {
            return;
        }
        $order = wc_get_order( self::$deleted_item_orders[ $item_id ] );
        unset( self::$deleted_item_orders[ $item_id ] );
        if ( $order instanceof WC_Order ) {
            LP_Missing_Orders::refresh_order_flags( $order );
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
            LP_Missing_Orders::refresh_order_flags( $order );
        }
    }

    public static function handle_order_failed( $order_id, $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
                continue;
            }
            $data = LP_Missing_Line::get_item_data( $item );
            if ( ! $data['stock_locked_qty'] ) {
                continue;
            }
            $released            = $data;
            $released['missing'] = false;
            self::maybe_adjust_stock( $item, $data, $released, $order );
            $data['stock_locked_qty'] = $released['stock_locked_qty'];
            $item->update_meta_data( LP_Missing_Plugin::META_KEY, $data );
            $item->save();
        }
    }

    public static function handle_order_recovered( $order_id, $from, $to, $order = null ) {
        if ( 'failed' !== $from || ! in_array( $to, array( 'pending', 'processing', 'on-hold', 'completed' ), true ) ) {
            return;
        }
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
                continue;
            }
            $data = LP_Missing_Line::get_item_data( $item );
            if ( LP_Missing_Line::is_line_resolved( $data ) ) {
                continue;
            }
            $locked = $data;
            self::maybe_adjust_stock( $item, $data, $locked, $order );
            if ( $locked['stock_locked_qty'] !== $data['stock_locked_qty'] ) {
                $data['stock_locked_qty'] = $locked['stock_locked_qty'];
                $item->update_meta_data( LP_Missing_Plugin::META_KEY, $data );
                $item->save();
            }
        }
        LP_Missing_Orders::refresh_order_flags( $order );
        LP_Missing_Lifecycle::sync_order_schedule( $order );
    }
}
