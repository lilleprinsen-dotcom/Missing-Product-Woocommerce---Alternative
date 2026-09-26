<?php
/**
 * Sends the plugin emails (WooCommerce emails, configurable under WooCommerce > Settings > Emails):
 *
 * - lp_missing_customer_email    Customer: which items are missing, link to choose (sent when lines start waiting).
 * - lp_missing_customer_reminder Customer: one reminder per order for the lines still waiting.
 * - lp_missing_staff_decision    Staff: a customer chose (alternative, removal, declined) or changed the choice.
 * - lp_missing_staff_deadline    Staff: the decision deadline passed and the default action was applied.
 *
 * Templates live in templates/emails/ (HTML) and templates/emails/plain/ (plain text); themes override them in
 * yourtheme/lp-missing/emails/.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Notifier {
    const TEMPLATE_PATH = 'lp-missing/';

    public static function register() {
        add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_class' ) );
        // WooCommerce's email settings offer to copy templates to the theme: point that at yourtheme/lp-missing/.
        add_filter( 'woocommerce_template_directory', array( __CLASS__, 'template_directory' ), 10, 2 );
    }

    public static function register_email_class( $emails ) {
        $emails['lp_missing_customer_email']    = new LP_Missing_Product_Email();
        $emails['lp_missing_customer_reminder'] = new LP_Missing_Product_Reminder_Email();
        $emails['lp_missing_staff_decision']    = new LP_Missing_Staff_Decision_Email();
        $emails['lp_missing_staff_deadline']    = new LP_Missing_Staff_Deadline_Email();
        return $emails;
    }

    public static function is_plugin_template( $template ) {
        return (bool) preg_match( '#^emails/(plain/)?lp-missing-#', (string) $template );
    }

    public static function template_directory( $directory, $template ) {
        return self::is_plugin_template( $template ) ? untrailingslashit( self::TEMPLATE_PATH ) : $directory;
    }

    /**
     * @return WC_Email|null
     */
    public static function get_email( $id ) {
        if ( ! function_exists( 'WC' ) ) {
            return null;
        }
        $mailer = WC()->mailer();
        if ( ! $mailer ) {
            return null;
        }
        $emails = $mailer->get_emails();
        return isset( $emails[ $id ] ) ? $emails[ $id ] : null;
    }

    /**
     * Email the customer which items are missing and where to choose. Sets notified_at on the listed lines.
     */
    public static function send_customer_email( $order ) {
        if ( ! $order instanceof WC_Order || ! LP_Missing_Orders::order_has_missing_items( $order ) ) {
            return false;
        }
        $email = self::get_email( 'lp_missing_customer_email' );
        if ( ! $email ) {
            return false;
        }
        $sent = (bool) $email->trigger( $order->get_id(), $order );
        if ( ! $sent ) {
            LP_Missing_Logger::warning( 'Customer email not sent (disabled, no billing email or mail failure).', array( 'order_id' => $order->get_id() ) );
            return false;
        }
        $now = time();
        foreach ( $email->get_line_ids() as $item_id ) {
            $item = $order->get_item( $item_id, false );
            if ( ! $item || ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
                continue;
            }
            $data                = LP_Missing_Line::get_item_data( $item );
            $data['notified_at'] = $now;
            $item->update_meta_data( LP_Missing_Plugin::META_KEY, $data );
            $item->save();
        }
        LP_Missing_Logger::info(
            'Customer email sent.',
            array(
                'order_id' => $order->get_id(),
                'items'    => $email->get_line_ids(),
            )
        );
        /**
         * Fires after a customer email about missing items was sent.
         *
         * @param WC_Order $order Order.
         * @param string   $type  'initial' (items to choose for) or 'reminder'.
         */
        do_action( 'lp_missing_customer_notified', $order, 'initial' );
        return true;
    }

    /**
     * Email the customer one reminder listing every line still waiting.
     *
     * @param WC_Order $order
     * @param int      $item_id Unused (reminders are per order); kept for backwards compatibility.
     */
    public static function send_reminder_email( $order, $item_id = 0 ) {
        if ( ! $order instanceof WC_Order || ! LP_Missing_Orders::order_has_missing_items( $order ) ) {
            return false;
        }
        $email = self::get_email( 'lp_missing_customer_reminder' );
        if ( ! $email ) {
            return false;
        }
        $sent = (bool) $email->trigger( $order->get_id(), $item_id, $order );
        if ( ! $sent ) {
            LP_Missing_Logger::warning( 'Reminder email not sent (disabled, no billing email or mail failure).', array( 'order_id' => $order->get_id() ) );
            return false;
        }
        LP_Missing_Logger::info(
            'Reminder email sent.',
            array(
                'order_id' => $order->get_id(),
                'items'    => $email->get_line_ids(),
            )
        );
        do_action( 'lp_missing_customer_notified', $order, 'reminder' );
        return true;
    }

    /**
     * Tell staff that the customer decided (or changed a decision) on one or more lines.
     *
     * @param WC_Order $order
     * @param array    $changes item_id => array( 'new' => line data, 'old' => line data ).
     */
    public static function send_staff_decision_email( $order, $changes ) {
        $email = self::get_email( 'lp_missing_staff_decision' );
        if ( ! $email || ! $order instanceof WC_Order || ! $changes ) {
            return false;
        }
        $sent = (bool) $email->trigger( $order, $changes );
        $log  = array(
            'order_id' => $order->get_id(),
            'items'    => array_keys( $changes ),
        );
        if ( $sent ) {
            LP_Missing_Logger::info( 'Staff notified about a customer decision.', $log );
        } elseif ( $email->is_enabled() ) {
            LP_Missing_Logger::warning( 'Staff decision email could not be sent.', $log );
        }
        return $sent;
    }

    /**
     * Tell staff which default actions the decision deadline applied (or failed to apply).
     *
     * @param WC_Order $order
     * @param array    $results item_id => array( name, qty, action, status, message, amount ).
     */
    public static function send_staff_deadline_email( $order, $results ) {
        $email = self::get_email( 'lp_missing_staff_deadline' );
        if ( ! $email || ! $order instanceof WC_Order || ! $results ) {
            return false;
        }
        $sent = (bool) $email->trigger( $order, $results );
        $log  = array(
            'order_id' => $order->get_id(),
            'items'    => array_keys( $results ),
        );
        if ( $sent ) {
            LP_Missing_Logger::info( 'Staff notified about deadline actions.', $log );
        } elseif ( $email->is_enabled() ) {
            LP_Missing_Logger::warning( 'Staff deadline email could not be sent.', $log );
        }
        return $sent;
    }

    public static function get_staff_recipient() {
        $recipient = (string) LP_Missing_Settings::get( 'staff_notification_email' );
        return is_email( $recipient ) ? $recipient : (string) get_option( 'admin_email' );
    }

    /**
     * Missing lines as shown in customer emails: item_id => array( item_id, name, qty_ordered, qty_missing, note,
     * alternatives (names), propose_delete, awaiting ).
     *
     * @param WC_Order $order
     * @param bool     $awaiting_only Only lines waiting for the customer (otherwise every open missing line).
     */
    public static function get_customer_lines( $order, $awaiting_only = true ) {
        $lines = array();
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( ! $item->meta_exists( LP_Missing_Plugin::META_KEY ) ) {
                continue;
            }
            $data     = LP_Missing_Line::get_item_data( $item );
            $awaiting = LP_Missing_Line::is_awaiting_customer( $data );
            if ( empty( $data['missing'] ) || LP_Missing_Line::is_line_resolved( $data ) || ( $awaiting_only && ! $awaiting ) ) {
                continue;
            }
            $alternatives = array();
            foreach ( $data['alternatives'] as $alt_id ) {
                $alt = wc_get_product( $alt_id );
                if ( $alt ) {
                    $alternatives[] = $alt->get_name();
                }
            }
            $lines[ $item_id ] = array(
                'item_id'        => $item_id,
                'name'           => $item->get_name(),
                'qty_ordered'    => (int) $item->get_quantity(),
                'qty_missing'    => $data['qty_missing'] ? (int) $data['qty_missing'] : (int) $item->get_quantity(),
                'note'           => (string) $data['notes'],
                'alternatives'   => $alternatives,
                'propose_delete' => ! empty( $data['propose_delete'] ),
                'awaiting'       => $awaiting,
            );
        }
        /**
         * Filter the missing lines listed in customer emails.
         *
         * @param array    $lines         item_id => line details.
         * @param WC_Order $order         Order.
         * @param bool     $awaiting_only Whether only lines waiting for the customer were requested.
         */
        return apply_filters( 'lp_missing_email_lines', $lines, $order, $awaiting_only );
    }

    /**
     * A customer decision as staff see it in the staff email: choice, previous choice and the frozen price difference.
     */
    public static function describe_decision( $order, $item_id, $new_data, $old_data ) {
        $item = $order->get_item( $item_id, false );
        $row  = array(
            'item_id'     => $item_id,
            /* translators: %d: line item ID */
            'name'        => $item ? $item->get_name() : sprintf( __( 'Line #%d', 'lp-missing' ), $item_id ),
            'qty_missing' => $new_data['qty_missing'] ? (int) $new_data['qty_missing'] : ( $item ? (int) $item->get_quantity() : 0 ),
            'choice'      => self::describe_choice( $new_data ),
            'previous'    => '',
            'price'       => '',
            'next_step'   => '',
        );
        if ( LP_Missing_Line::has_customer_decision( $old_data ) && ! LP_Missing_Line::is_line_resolved( $old_data ) ) {
            $row['previous'] = self::describe_choice( $old_data );
        }

        if ( 'alt_pending' === $new_data['status'] ) {
            $row['next_step'] = __( 'Apply the alternative in the Missing / Problem Items box on the order.', 'lp-missing' );
            $snapshot         = is_array( $new_data['pricing_snapshot'] ) ? $new_data['pricing_snapshot'] : array();
            if ( isset( $snapshot['delta_total_incl'] ) ) {
                $delta     = (float) $snapshot['delta_total_incl'];
                $threshold = 0.5 * pow( 10, -wc_get_price_decimals() );
                $amount    = LP_Missing_Util::plain_price( abs( $delta ), $order );
                if ( $delta >= $threshold ) {
                    $row['price'] = LP_Missing_Pricing::store_covers_difference( $delta )
                        /* translators: %s: amount */
                        ? sprintf( __( '%s more incl. VAT. The store covers it (no surcharge).', 'lp-missing' ), $amount )
                        /* translators: %s: amount */
                        : sprintf( __( '%s more incl. VAT. Invoiced to the customer as a separate surcharge order when you apply the alternative.', 'lp-missing' ), $amount );
                } elseif ( $delta <= -$threshold ) {
                    /* translators: %s: amount */
                    $row['price'] = sprintf( __( '%s less incl. VAT. The order keeps its original price.', 'lp-missing' ), $amount );
                } else {
                    $row['price'] = __( 'No price difference.', 'lp-missing' );
                }
                $row['price'] .= ' ' . __( '(Frozen when the customer chose.)', 'lp-missing' );
            }
        } elseif ( 'delete_pending' === $new_data['status'] ) {
            $row['next_step'] = __( 'Remove or refund the missing quantity in the Missing / Problem Items box on the order.', 'lp-missing' );
        } elseif ( 'declined' === $new_data['status'] ) {
            $row['next_step'] = __( 'Suggest new alternatives (the customer is notified again) or remove/refund the missing quantity.', 'lp-missing' );
        }
        return $row;
    }

    protected static function describe_choice( $data ) {
        switch ( $data['status'] ) {
            case 'alt_pending':
                $alt = $data['selected_alt_id'] ? wc_get_product( $data['selected_alt_id'] ) : null;
                /* translators: 1: product name, 2: quantity */
                return sprintf( __( 'Alternative: %1$s × %2$d', 'lp-missing' ), $alt ? $alt->get_name() : '#' . absint( $data['selected_alt_id'] ), $data['qty_alt'] ? $data['qty_alt'] : $data['qty_missing'] );
            case 'delete_pending':
                return __( 'Remove the missing quantity from the order', 'lp-missing' );
            case 'declined':
                return __( 'Declined all suggested alternatives', 'lp-missing' );
        }
        return '';
    }
}
