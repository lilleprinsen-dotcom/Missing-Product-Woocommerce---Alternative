<?php
/**
 * Saves the customer's answers from the portal form (one form for all missing lines).
 *
 * A decision can be changed until staff has applied it; a changed alternative is priced again (frozen at the new
 * choice), while a new quantity of the same alternative keeps the unit prices the customer was shown.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Portal_Decisions {
    const RATE_MAX    = 10;
    const RATE_WINDOW = 900;

    /**
     * Lines the customer may answer or change: open cases whose decision staff has not applied yet.
     */
    public static function is_editable( $data ) {
        return ! empty( $data['missing'] ) && ! LP_Missing_Line::is_line_resolved( $data ) && in_array( $data['status'], array( 'pending', 'alt_pending', 'declined', 'delete_pending' ), true );
    }

    /**
     * The customer's current decision as a form value: 'alt:{id}', 'decline', 'delete' or ''.
     */
    public static function get_current_choice( $data ) {
        if ( 'alt_pending' === $data['status'] && ! empty( $data['selected_alt_id'] ) ) {
            return 'alt:' . absint( $data['selected_alt_id'] );
        }
        if ( 'declined' === $data['status'] ) {
            return 'decline';
        }
        if ( 'delete_pending' === $data['status'] ) {
            return 'delete';
        }
        return '';
    }

    /**
     * Posted answers: item_id => array( 'choice' => string, 'qty' => int|null ).
     */
    public static function get_posted() {
        $choices = isset( $_POST['lp_choice'] ) && is_array( $_POST['lp_choice'] ) ? wp_unslash( $_POST['lp_choice'] ) : array();
        $qtys    = isset( $_POST['lp_qty'] ) && is_array( $_POST['lp_qty'] ) ? wp_unslash( $_POST['lp_qty'] ) : array();
        $posted  = array();
        foreach ( $choices as $item_id => $choice ) {
            $item_id = absint( $item_id );
            if ( ! $item_id || ! is_string( $choice ) ) {
                continue;
            }
            $qty = isset( $qtys[ $item_id ] ) && is_scalar( $qtys[ $item_id ] ) && '' !== trim( (string) $qtys[ $item_id ] ) ? (int) $qtys[ $item_id ] : null;
            $posted[ $item_id ] = array(
                'choice' => sanitize_text_field( $choice ),
                'qty'    => $qty,
            );
        }
        return $posted;
    }

    /**
     * Process the portal form. Returns a result array (status, code, message, line_errors, posted).
     */
    public static function handle( &$ctx ) {
        if ( ! LP_Missing_Portal_Access::check_form_token( $ctx ) ) {
            return LP_Missing_Portal::error_result( 'csrf' );
        }
        $posted = self::get_posted();
        if ( ! $posted ) {
            return array( 'status' => 'success', 'code' => 'nochange' );
        }

        // Shares the apply lock with staff, so a decision is never saved over one that is being applied.
        $locked = is_callable( array( 'LP_Missing_Admin_Actions', 'acquire_apply_lock' ) ) ? LP_Missing_Admin_Actions::acquire_apply_lock( $ctx['order_id'] ) : true;
        if ( ! $locked ) {
            return LP_Missing_Portal::error_result( 'busy', array( 'posted' => $posted ) );
        }
        try {
            $result = self::save( $ctx, $posted );
        } finally {
            if ( is_callable( array( 'LP_Missing_Admin_Actions', 'release_apply_lock' ) ) ) {
                LP_Missing_Admin_Actions::release_apply_lock( $ctx['order_id'] );
            }
        }
        return $result;
    }

    protected static function save( &$ctx, $posted ) {
        // Fresh copy: staff may have applied something since the page was shown.
        $order = wc_get_order( $ctx['order_id'] );
        if ( ! $order instanceof WC_Order ) {
            return LP_Missing_Portal::error_result( 'invalid' );
        }
        $ctx['order'] = $order;
        $items        = $order->get_items( 'line_item' );
        $plan         = array();
        $line_errors  = array();
        $handled      = 0;

        foreach ( $posted as $item_id => $answer ) {
            if ( '' === $answer['choice'] || ! isset( $items[ $item_id ] ) ) {
                continue;
            }
            $item     = $items[ $item_id ];
            $existing = LP_Missing_Line::get_item_data( $item );
            if ( ! self::is_editable( $existing ) ) {
                // Staff applied or closed the case after the page was shown: that line is no longer the customer's to change.
                $handled++;
                continue;
            }
            $new = self::build_decision( $order, $item, $existing, $answer, $error );
            if ( $error ) {
                $line_errors[ $item_id ] = $error;
                continue;
            }
            if ( $new ) {
                $plan[ $item_id ] = array( $item, $existing, $new );
            }
        }

        if ( $line_errors ) {
            return LP_Missing_Portal::error_result(
                'lines',
                array(
                    'line_errors' => $line_errors,
                    'posted'      => $posted,
                )
            );
        }
        if ( ! $plan ) {
            return array( 'status' => 'success', 'code' => $handled ? 'handled' : 'nochange' );
        }
        // Only real changes count towards the limit, and one save counts once however many lines it answers.
        if ( ! self::window_allows( self::rate_key( $order->get_id() ), self::get_rate_limit()['max'] ) ) {
            LP_Missing_Logger::warning( 'Customer portal save blocked by the rate limit.', array( 'order_id' => $order->get_id() ) );
            return LP_Missing_Portal::error_result( 'rate', array( 'posted' => $posted ) );
        }
        self::window_hit( self::rate_key( $order->get_id() ), self::get_rate_limit()['window'] );

        // One staff email and one schedule update for everything answered in this save.
        LP_Missing_Lifecycle::begin_batch();
        try {
        foreach ( $plan as $item_id => $step ) {
            list( $item, $existing, $new ) = $step;
            $item->update_meta_data( LP_Missing_Plugin::META_KEY, $new );
            $item->save();
            $changed = LP_Missing_Line::has_customer_decision( $existing );
            LP_Missing_Logger::info(
                $changed ? 'Customer changed a decision in the portal.' : 'Customer decision saved in the portal.',
                array(
                    'order_id'        => $order->get_id(),
                    'item_id'         => $item_id,
                    'status'          => $new['status'],
                    'alt_id'          => $new['selected_alt_id'],
                    'qty'             => $new['qty_alt'],
                    'previous_status' => $existing['status'],
                    'previous_alt_id' => $existing['selected_alt_id'],
                )
            );
            do_action( 'lp_missing_item_updated', $order, $item_id, $new, $existing );
            /**
             * Fires after the customer saved a new or changed decision for a missing line in the portal
             * (after lp_missing_item_updated).
             *
             * @param WC_Order $order    The order.
             * @param int      $item_id  Order line ID.
             * @param array    $new_data Line data after the decision.
             * @param array    $old_data Line data before the decision.
             */
            do_action( 'lp_missing_customer_decision', $order, $item_id, $new, $existing );
        }
        } finally {
            LP_Missing_Lifecycle::end_batch();
        }

        $ctx['order'] = wc_get_order( $order->get_id() );
        return array(
            'status' => 'success',
            'code'   => $handled ? 'saved_partial' : 'saved',
        );
    }

    /**
     * The line data after the customer's answer, null when nothing changes, or false with $error set.
     */
    protected static function build_decision( $order, $item, $existing, $answer, &$error ) {
        $error   = '';
        $choice  = $answer['choice'];
        $current = self::get_current_choice( $existing );
        $new     = $existing;

        if ( 0 === strpos( $choice, 'alt:' ) ) {
            $alt_id = absint( substr( $choice, 4 ) );
            $max    = max( 1, absint( $existing['qty_missing'] ) );
            $qty    = $answer['qty'];
            if ( null === $qty ) {
                // No quantity field (one missing unit, or it was left empty): keep the chosen quantity, else all of it.
                $qty = $current === $choice && $existing['qty_alt'] ? (int) $existing['qty_alt'] : $max;
            }
            if ( $current === $choice && (int) $existing['qty_alt'] === $qty ) {
                return null;
            }
            if ( ! in_array( $alt_id, $existing['alternatives'], true ) ) {
                $error = __( 'Dette alternativet kan ikke velges lenger. Velg et annet svar.', 'lp-missing' );
                return false;
            }
            $alt_product = wc_get_product( $alt_id );
            if ( ! $alt_product || 'trash' === $alt_product->get_status() ) {
                $error = __( 'Dette alternativet kan ikke velges lenger. Velg et annet svar.', 'lp-missing' );
                return false;
            }
            if ( $qty < 1 || $qty > $max ) {
                /* translators: %d: highest quantity */
                $error = sprintf( __( 'Velg et antall mellom 1 og %d.', 'lp-missing' ), $max );
                return false;
            }
            if ( ! $alt_product->is_in_stock() ) {
                /* translators: %s: product name */
                $error = sprintf( __( '%s er dessverre utsolgt nå. Velg et annet svar.', 'lp-missing' ), $alt_product->get_name() );
                return false;
            }
            if ( ! $alt_product->has_enough_stock( $qty ) ) {
                /* translators: 1: units in stock, 2: product name */
                $error = sprintf( __( 'Vi har bare %1$d stk av %2$s på lager. Velg færre eller et annet svar.', 'lp-missing' ), max( 0, (int) $alt_product->get_stock_quantity() ), $alt_product->get_name() );
                return false;
            }
            $new['status']          = 'alt_pending';
            $new['selected_alt_id'] = $alt_id;
            $new['qty_alt']         = $qty;
            // Same alternative: keep the frozen unit prices (what the customer was shown), scaled to the new quantity.
            // Another alternative: no usable snapshot, so the price is frozen now, at the new choice.
            $new['pricing_snapshot'] = LP_Missing_Pricing::scale_pricing_snapshot(
                LP_Missing_Pricing::get_frozen_pricing_snapshot( $order, $item, $new, $alt_product, $qty ),
                $qty
            );
        } elseif ( 'decline' === $choice || 'delete' === $choice ) {
            if ( $current === $choice ) {
                return null;
            }
            if ( 'delete' === $choice && empty( $existing['propose_delete'] ) ) {
                $error = __( 'Denne varen kan ikke fjernes her. Velg et annet svar.', 'lp-missing' );
                return false;
            }
            $new['status']           = 'decline' === $choice ? 'declined' : 'delete_pending';
            $new['selected_alt_id']  = 0;
            $new['qty_alt']          = 0;
            $new['pricing_snapshot'] = array();
        } else {
            $error = __( 'Velg et av svarene.', 'lp-missing' );
            return false;
        }

        $new['decision_made_at']       = time();
        $new['last_updated']           = time();
        $new['needs_attention']        = false;
        $new['reminder_scheduled_for'] = 0;
        $new['resolved_at']            = 0;
        return $new;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Fixed-window counters (the window starts at the first hit and is not extended by later hits).
    // ---------------------------------------------------------------------------------------------------------------

    public static function get_rate_limit() {
        /**
         * Filter how many decision changes a customer can save per order within a window (seconds).
         *
         * @param array $limit array( 'max' => int, 'window' => int ).
         */
        $limit = apply_filters( 'lp_missing_portal_rate_limit', array( 'max' => self::RATE_MAX, 'window' => self::RATE_WINDOW ) );
        return array(
            'max'    => max( 1, absint( isset( $limit['max'] ) ? $limit['max'] : self::RATE_MAX ) ),
            'window' => max( 60, absint( isset( $limit['window'] ) ? $limit['window'] : self::RATE_WINDOW ) ),
        );
    }

    public static function rate_key( $order_id ) {
        return 'lp_missing_portal_rl_' . absint( $order_id );
    }

    public static function window_allows( $key, $max ) {
        $state = get_transient( $key );
        return ! is_array( $state ) || empty( $state['start'] ) || absint( $state['count'] ) < $max;
    }

    public static function window_hit( $key, $window ) {
        $now   = time();
        $state = get_transient( $key );
        if ( ! is_array( $state ) || empty( $state['start'] ) || absint( $state['start'] ) + $window <= $now ) {
            $state = array(
                'count' => 0,
                'start' => $now,
            );
        }
        $state['count'] = absint( $state['count'] ) + 1;
        set_transient( $key, $state, max( 1, absint( $state['start'] ) + $window - $now ) );
        return $state['count'];
    }
}
