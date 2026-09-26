<?php
/**
 * Applies customer decisions to orders (alternative, removal, refund, surcharge).
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Apply_Service {
    public static function apply_alternative_decision( $order, $item, $item_id, $data, $mode ) {
        if ( 'alt_pending' !== $data['status'] || empty( $data['selected_alt_id'] ) ) {
            return array( 'status' => 'error', 'message' => __( 'No customer-approved alternative to apply.', 'lp-missing' ) );
        }

        $alt_product = wc_get_product( $data['selected_alt_id'] );
        if ( ! $alt_product ) {
            return array( 'status' => 'error', 'message' => __( 'Selected alternative is no longer available.', 'lp-missing' ) );
        }

        $billable_qty         = LP_Missing_Line::get_item_available_qty( $item );
        if ( $billable_qty < 1 ) {
            return array( 'status' => 'error', 'message' => __( 'All units on this line have already been refunded.', 'lp-missing' ) );
        }
        $previous_missing_qty = absint( $data['qty_missing'] );
        $qty_alt = $data['qty_alt'] ? absint( $data['qty_alt'] ) : $previous_missing_qty;
        $qty_alt = max( 1, min( $qty_alt, max( 1, $previous_missing_qty ), $billable_qty ) );
        $remaining_missing_qty = max( 0, $previous_missing_qty - $qty_alt );

        $settings             = LP_Missing_Settings::get_settings();
        $price_handling_mode  = isset( $settings['alt_price_handling'] ) ? $settings['alt_price_handling'] : 'charge_customer';
        $original_product     = $item->get_product();
        $original_name        = $original_product ? $original_product->get_name() : $item->get_name();
        $pricing_snapshot     = LP_Missing_Pricing::scale_pricing_snapshot( LP_Missing_Pricing::get_frozen_pricing_snapshot( $order, $item, $data, $alt_product, $qty_alt ), $qty_alt );
        $original_unit_incl   = floatval( $pricing_snapshot['original_unit_incl'] );
        $alt_unit_incl        = floatval( $pricing_snapshot['alternative_unit_incl'] );
        $final_delta          = wc_format_decimal( $pricing_snapshot['delta_total_incl'], wc_get_price_decimals() );

        // Move exactly the original line's share (totals + per-rate taxes) for the applied quantity onto the alternative line,
        // so the order total and tax lines stay balanced; any price difference is handled separately below.
        $share = LP_Missing_Pricing::get_item_share( $item, $qty_alt );
        LP_Missing_Pricing::subtract_share_from_item( $item, $share );

        $new_qty = $item->get_quantity();
        if ( 'replace' === $mode ) {
            $new_qty = max( 0, $item->get_quantity() - $qty_alt );
            $item->set_quantity( $new_qty );
            $line_result = $new_qty > 0 ? __( 'original line quantity and totals reduced', 'lp-missing' ) : __( 'original line removed', 'lp-missing' );
        } else {
            // The original line keeps its quantity for reference; remember how many units no longer carry a price.
            $item->update_meta_data( LP_Missing_Plugin::MOVED_QTY_META, absint( $item->get_meta( LP_Missing_Plugin::MOVED_QTY_META, true ) ) + $qty_alt );
            $line_result = __( 'original line totals reduced', 'lp-missing' );
        }

        $alt_share = $share;
        if ( LP_Missing_Pricing::needs_tax_rebase( $item, $alt_product ) ) {
            $alt_share = LP_Missing_Pricing::rebase_share_to_tax_class( $order, $share, $alt_product->get_tax_class(), $alt_product->is_taxable() );
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
            // Visible on order screens, emails, invoices and packing slips.
            $alt_item->add_meta_data( __( 'Erstatter', 'lp-missing' ), $original_name, true );
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

        LP_Missing_Stock::maybe_adjust_stock( $item, $data, $new_data, $order );
        $item->update_meta_data( LP_Missing_Plugin::META_KEY, $new_data );
        $item->save();
        if ( 'replace' === $mode ) {
            LP_Missing_Stock::shrink_line_reduced_stock( $order, $item, $new_qty );
        }
        if ( $alt_item ) {
            LP_Missing_Stock::reduce_stock_for_added_line( $order, $alt_item );
        }
        if ( 'replace' === $mode && $new_qty < 1 ) {
            $order->remove_item( $item_id );
        }
        LP_Missing_Pricing::recalculate_order_totals( $order );

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

    public static function apply_delete_decision( $order, $item, $item_id, $data, $mode ) {
        if ( ! LP_Missing_Line::can_apply_deletion( $data ) ) {
            return array( 'status' => 'error', 'message' => __( 'No customer-approved deletion to apply.', 'lp-missing' ) );
        }

        $billable_qty         = LP_Missing_Line::get_item_available_qty( $item );
        if ( $billable_qty < 1 ) {
            return array( 'status' => 'error', 'message' => __( 'All units on this line have already been refunded.', 'lp-missing' ) );
        }
        $previous_missing_qty = absint( $data['qty_missing'] );
        $qty_remove = $previous_missing_qty ? $previous_missing_qty : $billable_qty;
        $qty_remove = max( 1, min( $qty_remove, $billable_qty ) );

        $share       = LP_Missing_Pricing::get_item_share( $item, $qty_remove );
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
                $stock_baseline = LP_Missing_Line::get_item_data( $item );
            }
            $line_result = sprintf( __( 'refund of %s recorded (line kept for accounting; pay it back via the payment provider)', 'lp-missing' ), wp_strip_all_tags( wc_price( $refund_amount, array( 'currency' => $order->get_currency() ) ) ) );
        } else {
            LP_Missing_Pricing::subtract_share_from_item( $item, $share );
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

        LP_Missing_Stock::maybe_adjust_stock( $item, $stock_baseline, $new_data, $order );
        $item->update_meta_data( LP_Missing_Plugin::META_KEY, $new_data );
        $item->save();
        if ( 'reduce' === $mode ) {
            LP_Missing_Stock::shrink_line_reduced_stock( $order, $item, $new_qty );
            if ( $new_qty < 1 ) {
                $order->remove_item( $item_id );
            }
            LP_Missing_Pricing::recalculate_order_totals( $order );
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

    public static function handle_price_difference_surcharge( $order, $difference, $original_product_name, $alt_product, $qty_alt, $pricing_snapshot, $price_handling_mode = 'charge_customer' ) {
        $alt_product_name = $alt_product->get_name();
        $difference = wc_format_decimal( $difference, wc_get_price_decimals() );
        $result = array(
            'mode'         => LP_Missing_Pricing::store_covers_difference( $difference ) ? 'store_covers' : 'charge_customer',
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
        $taxes      = $alt_product->is_taxable() ? LP_Missing_Pricing::split_gross_by_tax_class( $order, $difference, $alt_product->get_tax_class() ) : array();
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
}
