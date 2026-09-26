<?php
/**
 * Order-level helpers: open-case checks, list flags and queries.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Orders {
    /**
     * Order flag: at least one line has a customer answer that staff must act on (apply the alternative or the
     * removal, or handle a declined line). Maintained by refresh_order_flags() like the other flags.
     */
    const READY_FLAG = '_lp_missing_ready';

    /**
     * Line statuses where the customer has answered and the next step is the store's.
     */
    public static function get_staff_action_statuses() {
        return array( 'alt_pending', 'delete_pending', 'declined' );
    }

    /**
     * Whether a (still open) line has a customer answer that staff must act on.
     */
    public static function line_needs_staff_action( $data ) {
        return ! empty( $data['missing'] ) && ! LP_Missing_Line::is_line_resolved( $data ) && in_array( $data['status'], self::get_staff_action_statuses(), true );
    }

    public static function order_is_ready_for_staff( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( self::line_needs_staff_action( LP_Missing_Line::get_item_data( $item ) ) ) {
                return true;
            }
        }
        return false;
    }

    public static function order_has_missing_items( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $data = LP_Missing_Line::get_item_data( $item );
            if ( ! empty( $data['missing'] ) && ! LP_Missing_Line::is_line_resolved( $data ) ) {
                return true;
            }
        }
        return false;
    }

    public static function order_has_open_missing_items( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $data = LP_Missing_Line::get_item_data( $item );
            if ( ! empty( $data['missing'] ) && ! LP_Missing_Line::is_line_resolved( $data ) ) {
                return true;
            }
        }
        return false;
    }

    public static function refresh_order_flags( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $has_data        = false;
        $has_open        = false;
        $needs_attention = false;
        $ready           = false;

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $data        = LP_Missing_Line::get_item_data( $item );
            $meta_exists = $item->meta_exists( LP_Missing_Plugin::META_KEY );

            if ( $meta_exists || ! empty( $data['missing'] ) ) {
                $has_data = true;
            }

            if ( ! empty( $data['needs_attention'] ) && ! LP_Missing_Line::is_line_resolved( $data ) ) {
                $needs_attention = true;
            }

            if ( ! empty( $data['missing'] ) && ! LP_Missing_Line::is_line_resolved( $data ) ) {
                $has_open = true;
            }

            if ( self::line_needs_staff_action( $data ) ) {
                $ready = true;
            }
        }

        $flags = array(
            LP_Missing_Plugin::OPTION_ATTENTION_FLAG    => $needs_attention,
            LP_Missing_Plugin::OPTION_HAS_MISSING_DATA  => $has_data,
            LP_Missing_Plugin::OPTION_HAS_OPEN_MISSING  => $has_open,
            self::READY_FLAG                            => $ready,
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

    public static function get_awaiting_item_names( $order ) {
        $names = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( LP_Missing_Line::is_awaiting_customer( LP_Missing_Line::get_item_data( $item ) ) ) {
                $names[] = $item->get_name();
            }
        }
        return $names;
    }

    public static function get_order_last_activity_timestamp( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return 0;
        }

        $modified = $order->get_date_modified();
        $created  = $order->get_date_created();

        $modified_ts = $modified ? $modified->getTimestamp() : 0;
        $created_ts  = $created ? $created->getTimestamp() : 0;

        return max( $modified_ts, $created_ts );
    }

    public static function refresh_order_attention_flag( $order ) {
        self::refresh_order_flags( $order );
    }

    /**
     * Count orders with an open missing-item case. An escalated line is always an open line.
     */
    public static function count_orders_with_open_missing() {
        return self::count_orders_with_flag( LP_Missing_Plugin::OPTION_HAS_OPEN_MISSING );
    }

    /**
     * Count orders where a customer has answered and staff must act (the "Customer answered" view).
     */
    public static function count_orders_ready_for_staff() {
        return self::count_orders_with_flag( self::READY_FLAG );
    }

    /**
     * Count orders carrying one of the order flags. Uses meta_key/meta_value, which (unlike meta_query)
     * wc_get_orders() honours for both legacy post storage and HPOS.
     */
    public static function count_orders_with_flag( $meta_key ) {
        $result = wc_get_orders( array(
            'type'       => 'shop_order',
            'limit'      => 1,
            'paginate'   => true,
            'return'     => 'ids',
            'meta_key'   => $meta_key,
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

    /**
     * Upgrade step: repair open cases saved by earlier versions (missing quantity 0, or a stock lock recorded for a
     * product that does not manage stock, which was never taken), and set the order flags added since (ready flag).
     */
    public static function upgrade_normalize_open_cases() {
        // Collect the IDs first: refreshing the flags can drop an order out of the flag query, which would shift pages.
        $order_ids = wc_get_orders( array(
            'type'       => 'shop_order',
            'limit'      => -1,
            'return'     => 'ids',
            'meta_key'   => LP_Missing_Plugin::OPTION_HAS_MISSING_DATA,
            'meta_value' => 'yes',
        ) );
        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order instanceof WC_Order ) {
                continue;
            }
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                if ( ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
                    continue;
                }
                $data    = LP_Missing_Line::get_item_data( $item );
                $changed = false;
                if ( ! empty( $data['missing'] ) && ! LP_Missing_Line::is_line_resolved( $data ) && $data['qty_missing'] < 1 ) {
                    $data['qty_missing'] = max( 1, LP_Missing_Line::get_item_available_qty( $item ) );
                    $changed = true;
                }
                $product = $item->get_product();
                if ( $data['stock_locked_qty'] && ( ! $product || ! $product->managing_stock() ) ) {
                    $data['stock_locked_qty'] = 0;
                    $changed = true;
                }
                if ( $changed ) {
                    $item->update_meta_data( LP_Missing_Plugin::META_KEY, $data );
                    $item->save();
                }
            }
            self::refresh_order_flags( $order );
        }
    }
}
