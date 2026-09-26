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
    /** @var array|null Customer notes collected during an automatic run: one note per order instead of one per line. */
    protected static $note_buffer = null;

    /**
     * Apply the customer's chosen alternative. $mode: 'replace' (shrink the original line) or 'add' (keep it).
     * $context: 'manual' (staff) or 'automatic'. Callers hold the per-order apply lock.
     *
     * @return array status ('success'|'error'), message, and on success details (qty, remaining, delta, ...).
     */
    public static function apply_alternative_decision( $order, $item, $item_id, $data, $mode, $context = 'manual' ) {
        return self::run_apply( 'alternative', $order, $item, $item_id, $data, $mode, $context );
    }

    /**
     * Remove the missing quantity. $mode: 'refund' (record a refund, line kept) or 'reduce' (shrink/remove the line).
     * $context 'automatic' (decision deadline) may also remove lines still waiting for the customer.
     */
    public static function apply_delete_decision( $order, $item, $item_id, $data, $mode, $context = 'manual' ) {
        return self::run_apply( 'delete', $order, $item, $item_id, $data, $mode, $context );
    }

    protected static function run_apply( $type, $order, $item, $item_id, $data, $mode, $context ) {
        $context = 'automatic' === $context ? 'automatic' : 'manual';
        /**
         * Fires before a decision is applied to an order.
         *
         * @param WC_Order $order   Order.
         * @param int      $item_id Line item ID.
         * @param string   $type    'alternative' or 'delete'.
         * @param string   $mode    'replace'|'add' (alternative) or 'refund'|'reduce' (delete).
         * @param null     $result  Always null here (see lp_missing_after_apply_decision).
         * @param string   $context 'manual' or 'automatic'.
         */
        do_action( 'lp_missing_before_apply_decision', $order, $item_id, $type, $mode, null, $context );

        if ( 'alternative' === $type ) {
            $result = self::do_apply_alternative( $order, $item, $item_id, $data, $mode, $context );
        } else {
            $result = self::do_apply_delete( $order, $item, $item_id, $data, $mode, $context );
        }

        $log = array(
            'order_id' => $order->get_id(),
            'item_id'  => absint( $item_id ),
            'type'     => $type,
            'mode'     => $mode,
            'context'  => $context,
        );
        if ( 'success' === $result['status'] ) {
            LP_Missing_Logger::info( 'Decision applied.', $log );
        } else {
            LP_Missing_Logger::warning( 'Decision could not be applied.', $log + array( 'reason' => $result['message'] ) );
        }

        $fresh = has_action( 'lp_missing_after_apply_decision' ) ? wc_get_order( $order->get_id() ) : null;
        /**
         * Fires after a decision was applied (or failed to apply).
         *
         * @param WC_Order $order   Order (reloaded).
         * @param int      $item_id Line item ID (the line may be gone after a full replace/reduce).
         * @param string   $type    'alternative' or 'delete'.
         * @param string   $mode    'replace'|'add' or 'refund'|'reduce'.
         * @param array    $result  status ('success'|'error'), message and details.
         * @param string   $context 'manual' or 'automatic'.
         */
        do_action( 'lp_missing_after_apply_decision', $fresh instanceof WC_Order ? $fresh : $order, $item_id, $type, $mode, $result, $context );

        return $result;
    }

    protected static function do_apply_alternative( $order, $item, $item_id, $data, $mode, $context ) {
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
        /**
         * Filter the gross (incl. VAT) price difference used for the surcharge of an applied alternative.
         *
         * @param string     $delta            Frozen total difference for the applied quantity.
         * @param WC_Order   $order            Order.
         * @param WC_Order_Item_Product $item  Original line.
         * @param WC_Product $alt_product      Alternative.
         * @param int        $qty_alt          Applied quantity.
         * @param array      $pricing_snapshot Frozen pricing snapshot (scaled to $qty_alt).
         */
        $final_delta          = wc_format_decimal( apply_filters( 'lp_missing_price_delta', $pricing_snapshot['delta_total_incl'], $order, $item, $alt_product, $qty_alt, $pricing_snapshot ), wc_get_price_decimals() );

        // Move exactly the original line's share (totals + per-rate taxes) for the applied quantity onto the alternative line,
        // so the order total and tax lines stay balanced; any price difference is handled separately below.
        $share = LP_Missing_Pricing::get_item_share( $item, $qty_alt );
        LP_Missing_Pricing::subtract_share_from_item( $item, $share );

        $new_qty = $item->get_quantity();
        if ( 'replace' === $mode ) {
            $new_qty = max( 0, $item->get_quantity() - $qty_alt );
            $item->set_quantity( $new_qty );
        } else {
            // The original line keeps its quantity for reference; remember how many units no longer carry a price.
            $item->update_meta_data( LP_Missing_Plugin::MOVED_QTY_META, absint( $item->get_meta( LP_Missing_Plugin::MOVED_QTY_META, true ) ) + $qty_alt );
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
        $note_parts[] = sprintf(
            /* translators: 1: how it was applied, 2: quantity, 3: ordered product, 4: replacement product */
            __( 'Replacement applied (%1$s): %2$d × %3$s → %4$s.', 'lp-missing' ),
            'replace' === $mode ? __( 'replaced on the line', 'lp-missing' ) : __( 'added as a new line', 'lp-missing' ),
            $qty_alt,
            $original_name,
            $alt_product->get_name()
        );
        if ( ! empty( $surcharge_result['summary_note'] ) ) {
            $note_parts[] = $surcharge_result['summary_note'];
        }
        if ( ! $fully_resolved ) {
            /* translators: %d: quantity */
            $note_parts[] = sprintf( __( '%d still waiting for the customer to choose.', 'lp-missing' ), $remaining_missing_qty );
        }
        /* translators: 1: original unit price, 2: replacement unit price */
        $note_parts[] = sprintf( __( '(Prices locked when the customer chose: %1$s → %2$s per unit.)', 'lp-missing' ), LP_Missing_Util::plain_price( $original_unit_incl, $order ), LP_Missing_Util::plain_price( $alt_unit_incl, $order ) );
        $order->add_order_note( implode( ' ', $note_parts ) );
        self::add_customer_note( $order, self::describe_alternative_for_customer( $order, $original_name, $alt_product->get_name(), $qty_alt, $remaining_missing_qty, $final_delta, $surcharge_result ), $context );

        do_action( 'lp_missing_item_updated', $order, $item_id, $new_data, $data );

        return array(
            'status'             => 'success',
            'message'            => $fully_resolved ? __( 'Alternative applied to the order.', 'lp-missing' ) : __( 'Alternative partially applied. Remaining missing quantity is still open.', 'lp-missing' ),
            'qty'                => $qty_alt,
            'remaining'          => $remaining_missing_qty,
            'delta'              => $final_delta,
            'surcharge_order_id' => $surcharge_result['surcharge_order_id'],
        );
    }

    protected static function do_apply_delete( $order, $item, $item_id, $data, $mode, $context ) {
        // The decision deadline removes lines the customer never answered; staff need a customer decision (or escalation).
        $allowed = LP_Missing_Line::can_apply_deletion( $data ) || ( 'automatic' === $context && LP_Missing_Line::is_awaiting_customer( $data ) );
        if ( ! $allowed ) {
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
        $gross       = wc_format_decimal( (float) $share['total'] + array_sum( array_map( 'floatval', $share['taxes']['total'] ) ), wc_get_price_decimals() );
        $item_name   = $item->get_name();
        $new_qty     = $item->get_quantity();
        $stock_baseline = $data;

        if ( 'refund' === $mode ) {
            $refund_tax    = $share['taxes']['total'];
            $refund_amount = wc_format_decimal( (float) $share['total'] + array_sum( array_map( 'floatval', $refund_tax ) ), wc_get_price_decimals() );
            if ( $refund_amount > 0 ) {
                $refund = wc_create_refund( array(
                    'amount'         => $refund_amount,
                    'reason'         => 'automatic' === $context ? __( 'Decision deadline passed: missing items removed.', 'lp-missing' ) : __( 'Customer approved deletion of missing items.', 'lp-missing' ),
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
        } else {
            LP_Missing_Pricing::subtract_share_from_item( $item, $share );
            $new_qty = max( 0, $item->get_quantity() - $qty_remove );
            $item->set_quantity( $new_qty );
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

        $amount_text = LP_Missing_Util::plain_price( $gross, $order );
        if ( 'refund' === $mode ) {
            /* translators: 1: quantity, 2: product, 3: amount */
            $note = sprintf( __( 'Refund recorded: %1$d × %2$s (%3$s). Pay it back in the payment provider.', 'lp-missing' ), $qty_remove, $item_name, $amount_text );
        } else {
            /* translators: 1: quantity, 2: product, 3: amount */
            $note = sprintf( __( 'Removed from the order: %1$d × %2$s (−%3$s).', 'lp-missing' ), $qty_remove, $item_name, $amount_text );
        }
        $order->add_order_note( $note );
        self::add_customer_note( $order, self::describe_deletion_for_customer( $order, $item_name, $qty_remove, $mode, $gross ), $context );

        do_action( 'lp_missing_item_updated', $order, $item_id, $new_data, $data );

        return array(
            'status'  => 'success',
            'message' => __( 'Deletion applied to the order.', 'lp-missing' ),
            'qty'     => $qty_remove,
            'amount'  => $gross,
        );
    }

    public static function handle_price_difference_surcharge( $order, $difference, $original_product_name, $alt_product, $qty_alt, $pricing_snapshot, $price_handling_mode = 'charge_customer' ) {
        $alt_product_name = $alt_product->get_name();
        $difference = wc_format_decimal( $difference, wc_get_price_decimals() );
        $result = array(
            'mode'               => LP_Missing_Pricing::store_covers_difference( $difference ) ? 'store_covers' : 'charge_customer',
            'summary_note'       => '',
            'surcharge_order_id' => 0,
        );

        if ( $difference <= 0 ) {
            if ( $difference < 0 ) {
                /* translators: %s: amount */
                $result['summary_note'] = sprintf( __( 'Cheaper by %s; the order total is unchanged.', 'lp-missing' ), LP_Missing_Util::plain_price( abs( (float) $difference ), $order ) );
            } else {
                $result['summary_note'] = __( 'Same price.', 'lp-missing' );
            }
            return $result;
        }

        if ( 'store_covers' === $result['mode'] ) {
            /* translators: %s: amount */
            $result['summary_note'] = sprintf( __( 'The store covers the difference of %s.', 'lp-missing' ), LP_Missing_Util::plain_price( $difference, $order ) );
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

        $result['surcharge_order_id'] = $surcharge_order->get_id();
        /* translators: 1: order number, 2: amount */
        $result['summary_note']       = sprintf( __( 'Surcharge order #%1$s for %2$s sent to the customer.', 'lp-missing' ), $surcharge_order->get_order_number(), LP_Missing_Util::plain_price( $difference, $order ) );
        LP_Missing_Logger::info(
            'Surcharge order created.',
            array(
                'order_id'           => $order->get_id(),
                'surcharge_order_id' => $surcharge_order->get_id(),
                'amount'             => $difference,
            )
        );

        /**
         * Fires after the surcharge order for a dearer alternative was created (before its invoice is emailed).
         *
         * @param WC_Order $surcharge_order  The new pending order (child of $order).
         * @param WC_Order $order            The original order.
         * @param array    $pricing_snapshot Frozen pricing snapshot for the applied quantity.
         */
        do_action( 'lp_missing_surcharge_order_created', $surcharge_order, $order, $pricing_snapshot );

        $mailer = WC()->mailer();
        if ( $mailer && isset( $mailer->emails['WC_Email_Customer_Invoice'] ) ) {
            $mailer->emails['WC_Email_Customer_Invoice']->trigger( $surcharge_order->get_id(), $surcharge_order );
        }

        return $result;
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Customer-visible order notes (S2): what changed, in plain Norwegian. They also reach the customer through
     * WooCommerce's "Customer note" email.
     * --------------------------------------------------------------------------------------------------------- */

    protected static function add_customer_note( $order, $text, $context ) {
        if ( is_array( self::$note_buffer ) ) {
            self::$note_buffer[] = $text;
            return;
        }
        if ( 'automatic' === $context ) {
            $text = __( 'Vi fikk ikke svar fra deg innen fristen.', 'lp-missing' ) . ' ' . $text;
        }
        /**
         * Filter the customer-visible order note added when a decision is applied ('' adds none).
         *
         * @param string   $text    Note text.
         * @param WC_Order $order   Order.
         * @param string   $context 'manual' or 'automatic'.
         */
        $text = (string) apply_filters( 'lp_missing_customer_note', $text, $order, $context );
        if ( '' !== trim( $text ) ) {
            $order->add_order_note( $text, 1, false );
        }
    }

    public static function describe_alternative_for_customer( $order, $original_name, $alt_name, $qty, $remaining, $delta, $surcharge_result ) {
        /* translators: 1: original product, 2: alternative product, 3: quantity */
        $parts     = array( sprintf( __( 'Vi har byttet %1$s med %2$s (%3$d stk).', 'lp-missing' ), $original_name, $alt_name, $qty ) );
        $threshold = 0.5 * pow( 10, -wc_get_price_decimals() );
        $delta     = (float) $delta;
        if ( ! empty( $surcharge_result['surcharge_order_id'] ) ) {
            /* translators: %s: amount */
            $parts[] = sprintf( __( 'Mellomlegg på %s faktureres i en egen ordre.', 'lp-missing' ), LP_Missing_Util::plain_price( $delta, $order ) );
        } elseif ( $delta >= $threshold && 'store_covers' === $surcharge_result['mode'] ) {
            /* translators: %s: amount */
            $parts[] = sprintf( __( 'Prisforskjellen på %s dekker vi.', 'lp-missing' ), LP_Missing_Util::plain_price( $delta, $order ) );
        } elseif ( $delta <= -$threshold ) {
            $parts[] = __( 'Erstatningen er rimeligere, og ordresummen er uendret.', 'lp-missing' );
        }
        if ( $remaining > 0 ) {
            /* translators: 1: quantity, 2: product name */
            $parts[] = sprintf( __( 'Vi trenger fortsatt valget ditt for %1$d stk %2$s.', 'lp-missing' ), $remaining, $original_name );
        }
        return implode( ' ', $parts );
    }

    public static function describe_deletion_for_customer( $order, $item_name, $qty, $mode, $amount ) {
        if ( 'refund' === $mode ) {
            if ( (float) $amount > 0 ) {
                /* translators: 1: product name, 2: quantity, 3: amount */
                return sprintf( __( 'Vi har fjernet %1$s (%2$d stk) og refundert %3$s.', 'lp-missing' ), $item_name, $qty, LP_Missing_Util::plain_price( $amount, $order ) );
            }
            /* translators: 1: product name, 2: quantity */
            return sprintf( __( 'Vi har fjernet %1$s (%2$d stk).', 'lp-missing' ), $item_name, $qty );
        }
        /* translators: 1: product name, 2: quantity, 3: amount */
        return sprintf( __( 'Vi har fjernet %1$s (%2$d stk) fra ordren, og ordresummen er redusert med %3$s.', 'lp-missing' ), $item_name, $qty, LP_Missing_Util::plain_price( $amount, $order ) );
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Automatic path: the decision deadline (L11).
     * --------------------------------------------------------------------------------------------------------- */

    public static function is_deadline_due( $data, $now ) {
        return LP_Missing_Line::is_awaiting_customer( $data )
            && ! empty( $data['notified_at'] )
            && ! empty( $data['deadline_at'] )
            && (int) $data['deadline_at'] <= $now
            && empty( $data['auto_action_failed_at'] );
    }

    /**
     * Apply the configured default action to every line of the order that still waits for the customer after its
     * deadline. System path (no nonce/capability): takes the per-order apply lock and re-checks each line on a fresh
     * copy of the order, so a decision is never applied twice. 'refund' becomes 'reduce' on unpaid orders.
     *
     * @return array status: 'locked' (try later), 'done' or 'nothing'; results: item_id => details.
     */
    public static function apply_due_deadline_actions( $order_id, $now = 0 ) {
        $order_id = absint( $order_id );
        $now      = $now ? (int) $now : time();
        $results  = array();
        if ( ! LP_Missing_Deadline::enabled() ) {
            return array( 'status' => 'nothing', 'results' => $results );
        }
        if ( ! LP_Missing_Admin_Actions::acquire_apply_lock( $order_id ) ) {
            return array( 'status' => 'locked', 'results' => $results );
        }
        self::$note_buffer = array();
        try {
            $order = wc_get_order( $order_id );
            if ( ! $order instanceof WC_Order ) {
                return array( 'status' => 'nothing', 'results' => $results );
            }
            $configured = LP_Missing_Deadline::get_action();
            foreach ( array_keys( $order->get_items( 'line_item' ) ) as $item_id ) {
                // Fresh copy per line: an earlier line may have changed totals, refunds or the status.
                $order = wc_get_order( $order_id );
                $item  = $order instanceof WC_Order ? $order->get_item( $item_id, false ) : null;
                if ( ! $item instanceof WC_Order_Item_Product || LP_Missing_Lifecycle::order_is_closed( $order ) ) {
                    continue;
                }
                $data = LP_Missing_Line::get_item_data( $item );
                if ( ! self::is_deadline_due( $data, $now ) ) {
                    continue;
                }
                // Act only on what the customer was told: if staff changed the missing quantity, or refunded/removed units
                // of the line since, leave it to staff instead of refunding or removing twice.
                $changed_since = ( $data['notified_qty'] && $data['qty_missing'] !== $data['notified_qty'] )
                    || ( $data['notified_available'] && LP_Missing_Line::get_item_available_qty( $item ) < $data['notified_available'] );
                if ( $changed_since ) {
                    $data['needs_attention']       = true;
                    $data['auto_action_failed_at'] = $now;
                    $item->update_meta_data( LP_Missing_Plugin::META_KEY, $data );
                    $item->save();
                    LP_Missing_Orders::refresh_order_flags( $order );
                    $order->add_order_note(
                        sprintf(
                            /* translators: %s: product name */
                            __( 'Decision deadline passed for %s, but the line changed after the customer was notified (quantity, refund or removal). No automatic action taken; handle it manually.', 'lp-missing' ),
                            $item->get_name()
                        )
                    );
                    LP_Missing_Logger::warning( 'Deadline passed: line changed since notification, left to staff.', array( 'order_id' => $order_id, 'item_id' => absint( $item_id ) ) );
                    $results[ $item_id ] = array(
                        'name'    => $item->get_name(),
                        'qty'     => $data['qty_missing'],
                        'action'  => 'skipped',
                        'status'  => 'error',
                        'message' => __( 'The line changed after the customer was notified.', 'lp-missing' ),
                        'amount'  => 0,
                    );
                    continue;
                }
                // Nothing was paid on an unpaid order, so there is nothing to refund: remove the quantity instead.
                $mode   = 'refund' === $configured && $order->is_paid() ? 'refund' : 'reduce';
                if ( 'reduce' === $mode && $order->is_paid() ) {
                    $order->add_order_note( __( 'Deadline action "remove from order totals" on a paid order: the customer has paid for the removed quantity. Release or refund it with the payment provider.', 'lp-missing' ) );
                }
                $name   = $item->get_name();
                $qty    = $data['qty_missing'];
                $result = self::apply_delete_decision( $order, $item, $item_id, $data, $mode, 'automatic' );
                $order  = wc_get_order( $order_id );

                $results[ $item_id ] = array(
                    'name'    => $name,
                    'qty'     => isset( $result['qty'] ) ? $result['qty'] : $qty,
                    'action'  => $mode,
                    'status'  => $result['status'],
                    'message' => $result['message'],
                    'amount'  => isset( $result['amount'] ) ? $result['amount'] : 0,
                );

                if ( 'success' === $result['status'] ) {
                    $order->add_order_note(
                        sprintf(
                            /* translators: 1: product name, 2: action */
                            __( 'Decision deadline passed without an answer from the customer. Default action applied automatically to %1$s: %2$s.', 'lp-missing' ),
                            $name,
                            'refund' === $mode ? __( 'refund recorded (pay it back via the payment provider)', 'lp-missing' ) : __( 'missing quantity removed from the order', 'lp-missing' )
                        )
                    );
                    LP_Missing_Logger::info(
                        'Deadline passed: default action applied.',
                        array(
                            'order_id' => $order_id,
                            'item_id'  => absint( $item_id ),
                            'action'   => $mode,
                            'amount'   => $results[ $item_id ]['amount'],
                        )
                    );
                    /**
                     * Fires after the decision deadline's default action was applied to a line.
                     *
                     * @param WC_Order $order   Order (reloaded).
                     * @param int      $item_id Line item ID (the line may be gone after 'reduce').
                     * @param string   $action  'refund' or 'reduce' (what was actually applied).
                     */
                    do_action( 'lp_missing_deadline_action', $order, $item_id, $mode );
                    continue;
                }

                // Keep the line open for staff, and do not retry automatically until it starts waiting again.
                $item = $order ? $order->get_item( $item_id, false ) : null;
                if ( $item ) {
                    $failed                          = LP_Missing_Line::get_item_data( $item );
                    $failed['needs_attention']       = true;
                    $failed['auto_action_failed_at'] = $now;
                    $item->update_meta_data( LP_Missing_Plugin::META_KEY, $failed );
                    $item->save();
                    LP_Missing_Orders::refresh_order_flags( $order );
                }
                if ( $order ) {
                    $order->add_order_note(
                        sprintf(
                            /* translators: 1: product name, 2: error */
                            __( 'Decision deadline passed, but the automatic action for %1$s failed (%2$s). Handle it manually.', 'lp-missing' ),
                            $name,
                            $result['message']
                        )
                    );
                }
                LP_Missing_Logger::error(
                    'Deadline passed: default action failed.',
                    array(
                        'order_id' => $order_id,
                        'item_id'  => absint( $item_id ),
                        'action'   => $mode,
                        'reason'   => $result['message'],
                    )
                );
            }
        } finally {
            $notes             = self::$note_buffer;
            self::$note_buffer = null;
            $order             = $notes ? wc_get_order( $order_id ) : null;
            if ( $order instanceof WC_Order ) {
                self::add_customer_note( $order, implode( ' ', $notes ), 'automatic' );
            }
            LP_Missing_Admin_Actions::release_apply_lock( $order_id );
        }

        return array( 'status' => $results ? 'done' : 'nothing', 'results' => $results );
    }
}
