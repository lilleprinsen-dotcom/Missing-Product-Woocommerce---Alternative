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

        $has_data       = false;
        $has_open       = false;
        $needs_attention = false;

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
        }

        $flags = array(
            LP_Missing_Plugin::OPTION_ATTENTION_FLAG    => $needs_attention,
            LP_Missing_Plugin::OPTION_HAS_MISSING_DATA  => $has_data,
            LP_Missing_Plugin::OPTION_HAS_OPEN_MISSING  => $has_open,
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
     * Count orders with an open missing-item case. Uses meta_key/meta_value, which (unlike meta_query)
     * wc_get_orders() honours for both legacy post storage and HPOS. An escalated line is always an open line.
     */
    public static function count_orders_with_open_missing() {
        $result = wc_get_orders( array(
            'type'       => 'shop_order',
            'limit'      => 1,
            'paginate'   => true,
            'return'     => 'ids',
            'meta_key'   => LP_Missing_Plugin::OPTION_HAS_OPEN_MISSING,
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
     * product that does not manage stock, which was never taken).
     */
    public static function upgrade_normalize_open_cases() {
        $page = 1;
        do {
            $orders = wc_get_orders( array(
                'type'       => 'shop_order',
                'limit'      => 100,
                'paged'      => $page,
                'return'     => 'objects',
                'meta_key'   => LP_Missing_Plugin::OPTION_HAS_MISSING_DATA,
                'meta_value' => 'yes',
            ) );
            foreach ( $orders as $order ) {
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
            }
            $page++;
        } while ( count( $orders ) === 100 );
    }
}
