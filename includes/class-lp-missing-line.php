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
}
