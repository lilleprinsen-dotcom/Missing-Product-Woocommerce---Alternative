<?php
/**
 * Where the customer's money stands, so removals and refunds do the right thing with the payment provider.
 *
 * States:
 * - unpaid:   nothing is charged or reserved (pending payment, on hold, failed). Removing lowers what will be paid.
 * - reserved: the payment is authorised and is charged when the order is completed (Dintero before capture).
 *             Removing lowers the amount that will be charged; a refund would be wrong (nothing is charged yet).
 * - captured: the money is charged by a gateway the plugin can refund through (Dintero after capture). Refunds are
 *             sent to the customer through the gateway; removing from the order total would overcharge.
 * - paid:     paid through a gateway the plugin does not know. Refunds are recorded; staff pay them back manually.
 *
 * Other reserve-and-capture gateways can report their state with the lp_missing_payment_state filter.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Payment {
    const UNPAID   = 'unpaid';
    const RESERVED = 'reserved';
    const CAPTURED = 'captured';
    const PAID     = 'paid';

    /** Payment method ID of Dintero Checkout for WooCommerce. */
    const DINTERO = 'dintero_checkout';

    public static function register() {
        // Before Dintero captures (priority 10): note the risk of completing an order with unsettled missing items.
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'handle_completed' ), 5, 2 );
        add_action( 'admin_notices', array( __CLASS__, 'render_completed_notice' ) );
        // Surcharge orders follow their main order.
        add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'cancel_surcharge_orders' ), 20, 2 );
        add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'cancel_surcharge_orders' ), 20, 2 );
        // Replacement lines on Dintero orders.
        add_action( 'lp_missing_replacement_line_added', array( __CLASS__, 'set_dintero_line_id' ), 10, 5 );
        // Refunds made by the plugin: the customer gets the plugin's note, not also WooCommerce's refund email.
        add_action( 'woocommerce_create_refund', array( __CLASS__, 'mark_refund' ), 10, 2 );
        add_filter( 'woocommerce_email_enabled_customer_refunded_order', array( __CLASS__, 'skip_refund_email' ), 10, 3 );
        add_filter( 'woocommerce_email_enabled_customer_partially_refunded_order', array( __CLASS__, 'skip_refund_email' ), 10, 3 );
    }

    public static function mark_refund( $refund, $args ) {
        if ( ! empty( $args['lp_missing'] ) && $refund instanceof WC_Order_Refund ) {
            $refund->update_meta_data( '_lp_missing_refund', 1 );
        }
    }

    public static function skip_refund_email( $enabled, $order = null, $email = null ) {
        $refund = is_object( $email ) && isset( $email->refund ) ? $email->refund : null;
        if ( $enabled && $refund instanceof WC_Order_Refund && $refund->get_meta( '_lp_missing_refund', true ) ) {
            return false;
        }
        return $enabled;
    }

    /**
     * @return string One of the state constants.
     */
    public static function get_state( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return self::UNPAID;
        }
        // Waiting for payment (again) is unpaid; refunded or cancelled after payment still counts as paid (there is
        // money to account for).
        $paid = ! $order->has_status( array( 'pending', 'on-hold', 'failed', 'checkout-draft' ) ) && ( $order->is_paid() || null !== $order->get_date_paid() );
        if ( ! $paid ) {
            $state = self::UNPAID;
        } elseif ( self::DINTERO === $order->get_payment_method() ) {
            $state = $order->get_meta( '_wc_dintero_captured' ) ? self::CAPTURED : self::RESERVED;
        } else {
            $state = self::PAID;
        }
        /**
         * Filter where the customer's money stands for an order.
         *
         * @param string   $state unpaid, reserved, captured or paid.
         * @param WC_Order $order Order.
         */
        $state = (string) apply_filters( 'lp_missing_payment_state', $state, $order );
        return in_array( $state, array( self::UNPAID, self::RESERVED, self::CAPTURED, self::PAID ), true ) ? $state : self::PAID;
    }

    /**
     * Whether a refund for this order is sent to the customer through the payment gateway (not only recorded).
     */
    public static function refunds_through_gateway( $order ) {
        return self::CAPTURED === self::get_state( $order );
    }

    /**
     * How a missing quantity is taken off the order: 'reduce' (lower the order total) or 'refund' (record or send a
     * refund). $preferred is what staff or the deadline setting asked for; the payment state decides when only one
     * of them is right.
     */
    public static function get_removal_mode( $order, $preferred ) {
        switch ( self::get_state( $order ) ) {
            case self::UNPAID:
            case self::RESERVED:
                return 'reduce';
            case self::CAPTURED:
                return 'refund';
            default:
                return 'refund' === $preferred ? 'refund' : 'reduce';
        }
    }

    /**
     * Whether the payment provider receives the order lines when it charges (so a replacement kept on the original
     * line reads better than one added as an extra line).
     */
    public static function captures_order_lines( $order ) {
        return $order instanceof WC_Order && self::DINTERO === $order->get_payment_method();
    }

    public static function get_gateway_title( $order ) {
        $title = $order instanceof WC_Order ? trim( wp_strip_all_tags( $order->get_payment_method_title() ) ) : '';
        return '' !== $title ? $title : __( 'the payment provider', 'lp-missing' );
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Completing an order while missing items are not settled.
     * --------------------------------------------------------------------------------------------------------- */

    public static function count_open_cases( $order ) {
        $open = 0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
                $data = LP_Missing_Line::get_item_data( $item );
                $open += ! empty( $data['missing'] ) && ! LP_Missing_Line::is_line_resolved( $data ) ? 1 : 0;
            }
        }
        return $open;
    }

    public static function handle_completed( $order_id, $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $open = self::count_open_cases( $order );
        if ( ! $open ) {
            return;
        }
        $order->add_order_note(
            sprintf(
                /* translators: 1: number of lines, 2: payment provider */
                _n(
                    'Completed while %1$d missing item was not settled. %2$s may charge the full amount: settle the item in the Missing items box, and a refund is then sent to the customer.',
                    'Completed while %1$d missing items were not settled. %2$s may charge the full amount: settle the items in the Missing items box, and refunds are then sent to the customer.',
                    $open,
                    'lp-missing'
                ),
                $open,
                self::get_gateway_title( $order )
            )
        );
        LP_Missing_Logger::warning( 'Order completed with open missing-item cases.', array( 'order_id' => $order->get_id(), 'open' => $open ) );
        if ( is_admin() && get_current_user_id() ) {
            set_transient( 'lp_missing_completed_' . get_current_user_id(), array( 'order_id' => $order->get_id(), 'open' => $open ), 5 * MINUTE_IN_SECONDS );
        }
    }

    public static function render_completed_notice() {
        $key    = 'lp_missing_completed_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( ! is_array( $notice ) || empty( $notice['order_id'] ) ) {
            return;
        }
        delete_transient( $key );
        $order = wc_get_order( $notice['order_id'] );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $text = sprintf(
            /* translators: 1: order number, 2: number of lines */
            _n(
                'Order #%1$s was completed while %2$d missing item was not settled. The customer may have been charged for it: settle it in the Missing items box, and the refund is sent to the customer.',
                'Order #%1$s was completed while %2$d missing items were not settled. The customer may have been charged for them: settle them in the Missing items box, and the refunds are sent to the customer.',
                absint( $notice['open'] ),
                'lp-missing'
            ),
            $order->get_order_number(),
            absint( $notice['open'] )
        );
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $text ) . ' <a href="' . esc_url( LP_Missing_Util::get_order_edit_url( $order ) ) . '">' . esc_html__( 'Open the order', 'lp-missing' ) . '</a></p></div>';
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Surcharge orders (the price difference of a dearer replacement).
     * --------------------------------------------------------------------------------------------------------- */

    /**
     * Surcharge orders created for an order.
     *
     * @return WC_Order[]
     */
    public static function get_surcharge_orders( $order ) {
        if ( ! $order instanceof WC_Order || ! $order->get_id() ) {
            return array();
        }
        $children = wc_get_orders(
            array(
                'parent' => $order->get_id(),
                'type'   => 'shop_order',
                'limit'  => -1,
            )
        );
        return array_values(
            array_filter(
                $children,
                function ( $child ) {
                    return $child instanceof WC_Order && 'lp_missing_surcharge' === $child->get_created_via();
                }
            )
        );
    }

    /**
     * The main order was cancelled or refunded: an unpaid surcharge for it must not be paid any more.
     */
    public static function cancel_surcharge_orders( $order_id, $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        foreach ( self::get_surcharge_orders( $order ) as $surcharge ) {
            if ( $surcharge->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
                /* translators: %s: order number */
                $surcharge->update_status( 'cancelled', sprintf( __( 'Main order #%s was cancelled or refunded, so this surcharge is no longer due.', 'lp-missing' ), $order->get_order_number() ) );
            }
        }
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Dintero: line IDs of replacement lines.
     * --------------------------------------------------------------------------------------------------------- */

    /**
     * Dintero identifies order lines by the line ID given at checkout. A replacement that takes over a whole line keeps
     * that ID, so the capture carries the line the customer authorised (same amount). A partial replacement gets an
     * ID of its own instead of the product SKU, so two replacements with the same product never share one.
     *
     * @param WC_Order_Item_Product $alt_item Replacement line.
     * @param WC_Order_Item_Product $item     Original line.
     * @param WC_Order              $order    Order.
     * @param string                $mode     'replace' or 'add'.
     * @param bool                  $whole    Whether the original line was replaced completely (and removed).
     */
    public static function set_dintero_line_id( $alt_item, $item, $order, $mode, $whole ) {
        if ( self::DINTERO !== $order->get_payment_method() || ! $alt_item instanceof WC_Order_Item_Product ) {
            return;
        }
        $original = (string) $item->get_meta( '_dintero_checkout_line_id', true );
        if ( $whole && '' !== $original ) {
            $alt_item->update_meta_data( '_dintero_checkout_line_id', $original );
        } else {
            $alt_item->update_meta_data( '_dintero_checkout_line_id', ( '' !== $original ? $original : 'line' ) . '-lp' . $alt_item->get_id() );
        }
        $alt_item->save();
    }
}
