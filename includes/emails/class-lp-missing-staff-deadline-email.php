<?php
/**
 * Staff email "Missing items – deadline action": the customer did not answer before the decision deadline and the
 * default action (refund / remove) was applied automatically, or failed and needs manual handling.
 *
 * Recipient: "Staff email address" in Missing Items Settings, or the site admin email.
 *
 * Placeholders: {order_number}, {order_date}, {customer_name}, {item_names}, plus WooCommerce's {site_title} etc.
 * Templates: emails/lp-missing-staff-deadline.php and emails/plain/lp-missing-staff-deadline.php.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Staff_Deadline_Email extends LP_Missing_Email_Base {
    /** @var array item_id => array( name, qty, action, status, message, amount ). */
    protected $results = array();

    public function __construct() {
        $this->id             = 'lp_missing_staff_deadline';
        $this->customer_email = false;
        $this->title          = __( 'Missing items – deadline action', 'lp-missing' );
        $this->description    = __( 'Sent to staff when the decision deadline passed and the default action was applied automatically (or failed). Recipient: Missing Items Settings > Staff notifications.', 'lp-missing' );
        $this->template_html  = 'emails/lp-missing-staff-deadline.php';
        $this->template_plain = 'emails/plain/lp-missing-staff-deadline.php';
        $this->placeholders   = array(
            '{order_number}'  => '',
            '{order_date}'    => '',
            '{customer_name}' => '',
            '{item_names}'    => '',
        );
        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Decision deadline passed for order #{order_number}', 'lp-missing' );
    }

    public function get_default_heading() {
        return __( 'Automatic action on order #{order_number}', 'lp-missing' );
    }

    /**
     * @param WC_Order $order
     * @param array    $results item_id => array( name, qty, action, status, message, amount ).
     * @return bool Whether the email was sent.
     */
    public function trigger( $order, $results ) {
        if ( ! $order instanceof WC_Order || ! $results ) {
            return false;
        }
        $this->object    = $order;
        $this->recipient = LP_Missing_Notifier::get_staff_recipient();
        $this->results   = $results;
        $this->lines     = $results;

        $date_created = $order->get_date_created();
        $this->placeholders['{order_number}']  = $order->get_order_number();
        $this->placeholders['{order_date}']    = $date_created ? wc_format_datetime( $date_created ) : '';
        $this->placeholders['{customer_name}'] = trim( $order->get_formatted_billing_full_name() );
        $this->placeholders['{item_names}']    = implode( ', ', wp_list_pluck( $results, 'name' ) );

        return $this->send_prepared();
    }

    protected function get_template_args( $plain_text ) {
        return array_merge(
            $this->get_base_template_args( $plain_text ),
            array(
                'results'       => $this->results,
                'order_url'     => $this->object instanceof WC_Order ? LP_Missing_Util::get_order_edit_url( $this->object ) : '',
                'customer_name' => $this->placeholders['{customer_name}'],
            )
        );
    }
}
