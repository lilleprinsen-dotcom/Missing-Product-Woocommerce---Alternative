<?php
/**
 * Missing-item data stored on an order line and its status rules.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Line {
    public static function default_item_data() {
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
            'deadline_at'     => 0,
            'notified_at'     => 0,
            'notified_qty'    => 0,
            'notified_available' => 0,
        );
    }

    public static function get_item_data( $item ) {
        $data = $item->get_meta( LP_Missing_Plugin::META_KEY, true );
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
        $data['deadline_at'] = absint( $data['deadline_at'] );
        $data['notified_at'] = absint( $data['notified_at'] );
        $data['notified_qty'] = absint( $data['notified_qty'] );
        $data['notified_available'] = absint( $data['notified_available'] );

        if ( 'alt_accepted' === $data['status'] ) {
            $data['status'] = 'alt_pending';
        } elseif ( 'delete_accepted' === $data['status'] ) {
            $data['status'] = 'delete_pending';
        }

        return $data;
    }

    public static function can_apply_deletion( $data ) {
        if ( empty( $data['missing'] ) || self::is_line_resolved( $data ) ) {
            return false;
        }
        return in_array( $data['status'], array( 'delete_pending', 'declined' ), true ) || ( 'pending' === $data['status'] && ! empty( $data['needs_attention'] ) );
    }

    /**
     * Units on the line that still carry a price. Differs from the quantity after an "add as extra line" apply.
     */
    public static function get_item_billable_qty( $item ) {
        $moved = absint( $item->get_meta( LP_Missing_Plugin::MOVED_QTY_META, true ) );
        return max( 1, absint( $item->get_quantity() ) - $moved );
    }

    /**
     * Units that can still be marked missing, replaced or removed: billable units minus units already refunded.
     * (Pricing keeps using the billable quantity, because a refund does not change the line totals.)
     */
    public static function get_item_available_qty( $item ) {
        $refunded = 0;
        $order    = $item->get_order();
        if ( $order instanceof WC_Order ) {
            $refunded = absint( $order->get_qty_refunded_for_item( $item->get_id() ) );
        }
        return max( 0, self::get_item_billable_qty( $item ) - $refunded );
    }

    public static function is_line_resolved( $data ) {
        $resolved_statuses = array( 'alt_applied', 'delete_applied', 'cleared' );
        return empty( $data['missing'] ) || in_array( $data['status'], $resolved_statuses, true );
    }

    public static function has_customer_decision( $data ) {
        $decision_statuses = array( 'alt_pending', 'delete_pending', 'declined', 'alt_applied', 'delete_applied' );
        return in_array( $data['status'], $decision_statuses, true );
    }

    public static function is_awaiting_customer( $data ) {
        return ! empty( $data['missing'] ) && ! self::is_line_resolved( $data ) && ! self::has_customer_decision( $data );
    }

    /**
     * Fingerprint of what a stale order screen must not overwrite: the case state and the line quantities.
     * Rendered into the order screen and compared on save.
     */
    public static function get_revision( $item ) {
        $data = self::get_item_data( $item );
        $parts = array(
            (int) $data['missing'],
            $data['status'],
            $data['qty_missing'],
            $data['selected_alt_id'],
            $data['qty_alt'],
            $data['resolved_at'],
            $item->get_quantity(),
            absint( $item->get_meta( LP_Missing_Plugin::MOVED_QTY_META, true ) ),
        );
        return substr( md5( implode( '|', $parts ) ), 0, 12 );
    }

    /**
     * Fingerprint of the customer's current decision (what staff see next to the apply buttons). Apply links carry it,
     * so a link rendered before the customer changed their mind does not apply the new choice unseen.
     */
    public static function get_decision_key( $data ) {
        $parts = array( $data['status'], (int) $data['selected_alt_id'], (int) $data['qty_alt'], (int) $data['qty_missing'], (int) $data['decision_made_at'] );
        return substr( md5( implode( '|', $parts ) ), 0, 10 );
    }

    /**
     * Merge fields into a line's stored data, re-reading the line first so that changes other requests saved after
     * the caller loaded the order (a customer's choice, a staff edit) are kept. The caller's copy of the line, when
     * given, is updated to the same merged data.
     *
     * @param int                $item_id
     * @param array              $fields    Fields to set.
     * @param WC_Order_Item|null $loaded    The caller's copy of the line.
     * @param callable|null      $condition Receives the stored data; nothing is written unless it returns true.
     * @return bool Whether the fields were written.
     */
    public static function update_fields( $item_id, $fields, $loaded = null, $condition = null ) {
        $item = WC_Order_Factory::get_order_item( absint( $item_id ) );
        if ( ! $item instanceof WC_Order_Item || ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
            return false;
        }
        $data = self::get_item_data( $item );
        if ( $condition && ! call_user_func( $condition, $data ) ) {
            return false;
        }
        $data = array_merge( $data, $fields );
        $item->update_meta_data( LP_Missing_Plugin::META_KEY, $data );
        $item->save();
        if ( $loaded instanceof WC_Order_Item ) {
            $loaded->update_meta_data( LP_Missing_Plugin::META_KEY, $data );
        }
        return true;
    }
}
