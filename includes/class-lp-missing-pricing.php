<?php
/**
 * Price, tax and line-share calculations for alternatives, refunds and surcharges.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Pricing {
    public static function register() {
        add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'coupon_valid_for_replacement' ), 10, 4 );
    }

    /**
     * A replacement line carries the price (and discount) of the item it replaces. When WooCommerce recalculates the
     * order's coupons, a coupon limited to certain products must treat the replacement like the original product, or
     * the discount would move (or disappear) and the order total change.
     */
    public static function coupon_valid_for_replacement( $valid, $product, $coupon, $values ) {
        if ( ! $values instanceof WC_Order_Item_Product || ! $coupon instanceof WC_Coupon ) {
            return $valid;
        }
        $original_id = absint( $values->get_meta( '_lp_missing_alt_original_product_id', true ) );
        $original    = $original_id ? wc_get_product( $original_id ) : null;
        if ( ! $original instanceof WC_Product || ( $product instanceof WC_Product && $product->get_id() === $original->get_id() ) ) {
            return $valid;
        }
        return $coupon->is_valid_for_product( $original );
    }

    /**
     * Rebuild order tax lines from the line item tax data and re-sum totals, without re-rating taxes.
     */
    public static function recalculate_order_totals( $order ) {
        $order->update_taxes();
        $order->calculate_totals( false );
    }

    /**
     * The part of a line's subtotal, total and per-rate taxes that belongs to $qty billable units.
     *
     * The split is made on the gross amount the customer sees (net + VAT), rounded to the shop's price decimals: the
     * moved units get what a line of the remaining units would lose, so 3 × 19.99 splits into 19.99 + 39.98 (not
     * 19.98 + 39.99), also with 0 price decimals. VAT is split per rate the same way; net is gross minus VAT.
     *
     * @param WC_Order_Item_Product $item       Line.
     * @param int                   $qty        Units.
     * @param bool                  $unrefunded Split what is not refunded yet (for a refund), so the last refunded
     *                                          unit takes the exact remainder of the line.
     */
    public static function get_item_share( $item, $qty, $unrefunded = false ) {
        $billable = LP_Missing_Line::get_item_billable_qty( $item );
        $taxes    = $item->get_taxes();
        $base     = array(
            'total'    => array(
                'qty'   => $billable,
                'net'   => (float) $item->get_total(),
                'taxes' => ! empty( $taxes['total'] ) && is_array( $taxes['total'] ) ? array_map( 'floatval', $taxes['total'] ) : array(),
            ),
            'subtotal' => array(
                'qty'   => $billable,
                'net'   => (float) $item->get_subtotal(),
                'taxes' => ! empty( $taxes['subtotal'] ) && is_array( $taxes['subtotal'] ) ? array_map( 'floatval', $taxes['subtotal'] ) : array(),
            ),
        );
        $order = $unrefunded ? $item->get_order() : null;
        if ( $order instanceof WC_Order ) {
            // Refunds are recorded against the line total and its taxes only.
            $base['total']['qty'] = max( 0, $billable - absint( $order->get_qty_refunded_for_item( $item->get_id() ) ) );
            $base['total']['net'] = max( 0, $base['total']['net'] - (float) $order->get_total_refunded_for_item( $item->get_id() ) );
            foreach ( $base['total']['taxes'] as $rate_id => $amount ) {
                $base['total']['taxes'][ $rate_id ] = max( 0, $amount - (float) $order->get_tax_refunded_for_item( $item->get_id(), $rate_id ) );
            }
        }
        $qty   = max( 0, min( absint( $qty ), $base['total']['qty'] ) );
        $share = array(
            'qty'      => $qty,
            'subtotal' => 0,
            'total'    => 0,
            'taxes'    => array(
                'total'    => array(),
                'subtotal' => array(),
            ),
        );
        $decimals       = wc_get_price_decimals();
        $round_per_line = 'yes' !== get_option( 'woocommerce_tax_round_at_subtotal' );
        foreach ( $base as $type => $line ) {
            $ratio = $line['qty'] > 0 ? min( 1, $qty / $line['qty'] ) : 0;
            if ( $ratio >= 1 || $ratio <= 0 ) {
                // All of it (exactly what is left) or nothing.
                $factor                  = $ratio >= 1 ? 1 : 0;
                $share[ $type ]          = wc_format_decimal( $line['net'] * $factor );
                $share['taxes'][ $type ] = array_map(
                    function ( $amount ) use ( $factor ) {
                        return wc_format_decimal( $amount * $factor );
                    },
                    $line['taxes']
                );
                continue;
            }
            $tax_share = array();
            foreach ( $line['taxes'] as $rate_id => $amount ) {
                // With taxes rounded per line, both the moved and the remaining part stay rounded amounts.
                $tax_share[ $rate_id ] = $round_per_line ? wc_round_tax_total( $amount ) - wc_round_tax_total( $amount * ( 1 - $ratio ) ) : $amount * $ratio;
            }
            $gross       = $line['net'] + array_sum( $line['taxes'] );
            $gross_share = round( $gross, $decimals ) - round( $gross * ( 1 - $ratio ), $decimals );
            $net_share   = $gross_share - array_sum( $tax_share );
            if ( $net_share < -0.000001 || $net_share > $line['net'] + 0.000001 ) {
                // Only on odd lines (e.g. VAT larger than the net after discounts): fall back to a plain split.
                $net_share = $line['net'] * $ratio;
            }
            $share[ $type ]          = wc_format_decimal( $net_share );
            $share['taxes'][ $type ] = array_map( 'wc_format_decimal', $tax_share );
        }
        return $share;
    }

    public static function subtract_share_from_item( $item, $share ) {
        $item->set_subtotal( wc_format_decimal( max( 0, (float) $item->get_subtotal() - (float) $share['subtotal'] ) ) );
        $item->set_total( wc_format_decimal( max( 0, (float) $item->get_total() - (float) $share['total'] ) ) );

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
    public static function get_item_unit_price_incl_tax( $item ) {
        $qty   = max( 1, LP_Missing_Line::get_item_billable_qty( $item ) );
        $total = floatval( $item->get_subtotal() ) + floatval( $item->get_subtotal_tax() );
        return $total / $qty;
    }

    public static function get_item_unit_price_excl_tax( $item ) {
        $qty   = max( 1, LP_Missing_Line::get_item_billable_qty( $item ) );
        $total = floatval( $item->get_subtotal() );
        return $total / $qty;
    }

    /**
     * The address WooCommerce taxes this order on (per the "Calculate tax based on" setting).
     */
    /**
     * The address WooCommerce taxes this order on. Mirrors WC_Abstract_Order::get_tax_location() (which is protected):
     * "Calculate tax based on" setting, local pickup taxed at the shop base, and the same filter.
     */
    public static function get_order_tax_location( $order ) {
        $based_on = get_option( 'woocommerce_tax_based_on', 'billing' );
        if ( 'shipping' === $based_on && ! $order->get_shipping_country() ) {
            $based_on = 'billing';
        }
        $type = 'billing' === $based_on ? 'billing' : 'shipping';
        $args = array(
            'country'  => $order->{"get_{$type}_country"}(),
            'state'    => $order->{"get_{$type}_state"}(),
            'postcode' => $order->{"get_{$type}_postcode"}(),
            'city'     => $order->{"get_{$type}_city"}(),
        );

        $apply_base_tax       = true === apply_filters( 'woocommerce_apply_base_tax_for_local_pickup', true );
        $local_pickup_methods = apply_filters( 'woocommerce_local_pickup_methods', array( 'legacy_local_pickup', 'local_pickup' ) );
        $shipping_method_ids  = array();
        foreach ( $order->get_shipping_methods() as $shipping ) {
            $shipping_method_ids[] = $shipping->get_method_id();
        }
        if ( $apply_base_tax && array_intersect( $shipping_method_ids, (array) $local_pickup_methods ) ) {
            $based_on = 'base';
        }

        if ( 'base' === $based_on || empty( $args['country'] ) ) {
            $args = array(
                'country'  => WC()->countries->get_base_country(),
                'state'    => WC()->countries->get_base_state(),
                'postcode' => WC()->countries->get_base_postcode(),
                'city'     => WC()->countries->get_base_city(),
            );
        }

        return apply_filters( 'woocommerce_order_get_tax_location', $args, $order );
    }

    /**
     * Price of one unit of $product for this order, taxed for the order's own address and VAT status
     * (not for whoever happens to run the request: admin, cron or a guest browser).
     */
    public static function get_product_unit_prices_for_order( $order, $product ) {
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

    public static function get_frozen_pricing_snapshot( $order, $item, $data, $alt_product = null, $qty = 0 ) {
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

    public static function get_alternative_price_delta( $order, $item, $alt_product, $qty = 1 ) {
        $qty = max( 1, absint( $qty ) );
        $data          = LP_Missing_Line::get_item_data( $item );
        $preview_data  = $data;
        $preview_data['selected_alt_id'] = $alt_product->get_id();
        // A frozen snapshot may be for another quantity than the one shown: scale it.
        $snapshot      = self::scale_pricing_snapshot( self::get_frozen_pricing_snapshot( $order, $item, $preview_data, $alt_product, $qty ), $qty );
        $delta_unit    = floatval( $snapshot['delta_per_unit_incl'] );
        $delta_total   = floatval( $snapshot['delta_total_incl'] );

        // Treat sub-cent differences as "same price" so rounding noise is never shown as a surcharge.
        $threshold = 0.5 * pow( 10, -wc_get_price_decimals() );
        return array(
            'unit_delta_raw'  => $delta_unit,
            'total_delta_raw' => $delta_total,
            'unit_delta'      => LP_Missing_Util::plain_price( abs( $delta_unit ), $order ),
            'total_delta'     => LP_Missing_Util::plain_price( abs( $delta_total ), $order ),
            'direction'       => $delta_unit >= $threshold ? 'up' : ( $delta_unit <= -$threshold ? 'down' : 'same' ),
        );
    }

    /**
     * Scale a frozen snapshot's totals to the quantity actually applied (it may differ from the quantity chosen in the portal).
     */
    public static function scale_pricing_snapshot( $snapshot, $qty ) {
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

    /**
     * Whether the store absorbs a (gross) price difference instead of invoicing it: always in "store covers" mode,
     * and in "charge customer" mode when the difference is within the configured threshold.
     */
    public static function store_covers_difference( $gross_delta ) {
        $gross_delta = (float) $gross_delta;
        if ( $gross_delta <= 0 ) {
            return false;
        }
        if ( 'store_covers' === LP_Missing_Settings::get( 'alt_price_handling' ) ) {
            return true;
        }
        $threshold = (float) LP_Missing_Settings::get( 'store_covers_below' );
        $covers    = $threshold > 0 && $gross_delta <= $threshold + 0.000001;
        /**
         * Filter whether the store covers a price difference.
         *
         * @param bool  $covers      Whether the store covers it.
         * @param float $gross_delta Total difference incl. VAT.
         */
        return (bool) apply_filters( 'lp_missing_store_covers_difference', $covers, $gross_delta );
    }

    public static function order_is_vat_exempt( $order ) {
        return (bool) apply_filters( 'woocommerce_order_is_vat_exempt', 'yes' === $order->get_meta( 'is_vat_exempt', true ), $order );
    }

    public static function get_order_tax_rates( $order, $tax_class ) {
        if ( ! wc_tax_enabled() || self::order_is_vat_exempt( $order ) ) {
            return array();
        }
        return WC_Tax::find_rates( array_merge( self::get_order_tax_location( $order ), array( 'tax_class' => $tax_class ) ) );
    }

    /**
     * Per-rate VAT contained in a gross amount for a tax class at this order's address (rate_id => tax).
     */
    public static function split_gross_by_tax_class( $order, $gross, $tax_class ) {
        $rates = self::get_order_tax_rates( $order, $tax_class );
        if ( ! $rates ) {
            return array();
        }
        $taxes = WC_Tax::calc_tax( (float) $gross, $rates, true );
        if ( 'yes' !== get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
            $taxes = array_map( 'wc_round_tax_total', $taxes );
        }
        return array_map( 'wc_format_decimal', $taxes );
    }

    /**
     * Keep the gross (what the customer paid) of a moved line share, but re-split net/VAT for another tax class
     * (e.g. 25% goods replaced by a 15% food item).
     */
    /**
     * Keep the gross (what the customer paid) of a moved line share, but re-split net/VAT for the alternative's own
     * tax status and class (e.g. 25% goods replaced by a 15% food item, or a non-taxable gift card).
     */
    public static function rebase_share_to_tax_class( $order, $share, $tax_class, $taxable = true ) {
        foreach ( array( 'total', 'subtotal' ) as $type ) {
            $gross = (float) $share[ $type ] + array_sum( array_map( 'floatval', $share['taxes'][ $type ] ) );
            $taxes = $taxable ? self::split_gross_by_tax_class( $order, $gross, $tax_class ) : array();
            // Net = gross minus the (rounded) taxes, so the line's gross stays exactly what was paid.
            $share[ $type ]          = wc_format_decimal( $gross - array_sum( array_map( 'floatval', $taxes ) ) );
            $share['taxes'][ $type ] = $taxes;
        }
        return $share;
    }

    /**
     * Whether an alternative must be re-taxed instead of inheriting the original line's taxes.
     */
    public static function needs_tax_rebase( $item, $alt_product ) {
        $original_taxable = 'taxable' === $item->get_tax_status();
        return $alt_product->get_tax_class() !== $item->get_tax_class() || $alt_product->is_taxable() !== $original_taxable;
    }
}
