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
    /**
     * Rebuild order tax lines from the line item tax data and re-sum totals, without re-rating taxes.
     */
    public static function recalculate_order_totals( $order ) {
        $order->update_taxes();
        $order->calculate_totals( false );
    }

    /**
     * The part of a line's subtotal, total and per-rate taxes that belongs to $qty billable units.
     */
    public static function get_item_share( $item, $qty ) {
        $billable = LP_Missing_Line::get_item_billable_qty( $item );
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

    public static function subtract_share_from_item( $item, $share ) {
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
    public static function get_item_unit_price_incl_tax( $item ) {
        $qty   = LP_Missing_Line::get_item_billable_qty( $item );
        $total = floatval( $item->get_subtotal() ) + floatval( $item->get_subtotal_tax() );
        return $total / $qty;
    }

    public static function get_item_unit_price_excl_tax( $item ) {
        $qty   = LP_Missing_Line::get_item_billable_qty( $item );
        $total = floatval( $item->get_subtotal() );
        return $total / $qty;
    }

    /**
     * The address WooCommerce taxes this order on (per the "Calculate tax based on" setting).
     */
    public static function get_order_tax_location( $order ) {
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
        $snapshot      = self::get_frozen_pricing_snapshot( $order, $item, $preview_data, $alt_product, $qty );
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
        return 'yes' === $order->get_meta( 'is_vat_exempt', true );
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
        return array_map( 'wc_format_decimal', WC_Tax::calc_tax( (float) $gross, $rates, true ) );
    }

    /**
     * Keep the gross (what the customer paid) of a moved line share, but re-split net/VAT for another tax class
     * (e.g. 25% goods replaced by a 15% food item).
     */
    public static function rebase_share_to_tax_class( $order, $share, $tax_class ) {
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
}
