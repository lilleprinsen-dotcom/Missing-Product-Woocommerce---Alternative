<?php
/**
 * Sends the plugin emails.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Notifier {
    public static function register() {
        add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_class' ) );
    }

    public static function send_customer_email( $order ) {
        if ( ! $order instanceof WC_Order || ! LP_Missing_Orders::order_has_missing_items( $order ) ) {
            return false;
        }
        $mailer = WC()->mailer();
        if ( ! $mailer ) {
            return false;
        }
        $emails = $mailer->get_emails();
        if ( empty( $emails['lp_missing_customer_email'] ) ) {
            return false;
        }
        return (bool) $emails['lp_missing_customer_email']->trigger( $order->get_id() );
    }

    public static function send_reminder_email( $order, $item_id ) {
        if ( ! $order instanceof WC_Order || ! LP_Missing_Orders::order_has_missing_items( $order ) ) {
            return false;
        }
        $mailer = WC()->mailer();
        if ( ! $mailer ) {
            return false;
        }
        $emails = $mailer->get_emails();
        if ( empty( $emails['lp_missing_customer_reminder'] ) ) {
            return false;
        }
        return (bool) $emails['lp_missing_customer_reminder']->trigger( $order->get_id(), $item_id );
    }

    public static function register_email_class( $emails ) {
        $emails['lp_missing_customer_email'] = new LP_Missing_Product_Email();
        $emails['lp_missing_customer_reminder'] = new LP_Missing_Product_Reminder_Email();
        return $emails;
    }
}
