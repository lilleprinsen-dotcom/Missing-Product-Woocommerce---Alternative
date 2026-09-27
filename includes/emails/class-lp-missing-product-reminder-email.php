<?php
/**
 * Customer reminder: one per order, listing every line still waiting for the customer's choice.
 *
 * Placeholders: {order_number}, {order_date}, {count} (number of lines waiting), {item_names} (also {item_name}),
 * {deadline} (formatted, empty without a deadline), {customer_first_name}, {magic_link}, plus WooCommerce's
 * {site_title}, {site_address}, {site_url}, {store_email}.
 *
 * Templates: emails/lp-missing-reminder.php and emails/plain/lp-missing-reminder.php.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Product_Reminder_Email extends LP_Missing_Email_Base {

    public function __construct() {
        $this->id             = 'lp_missing_customer_reminder';
        $this->customer_email = true;
        $this->title          = __( 'Missing items – reminder', 'lp-missing' );
        $this->description    = __( 'Reminder to the customer (one per order, inside the reminder window) listing the missing items still waiting for a choice.', 'lp-missing' );
        $this->template_html  = 'emails/lp-missing-reminder.php';
        $this->template_plain = 'emails/plain/lp-missing-reminder.php';
        $this->placeholders   = array_merge( $this->get_customer_placeholders(), array( '{item_name}' => '' ) );
        parent::__construct();
    }

    public function get_default_subject() {
        return __( 'Påminnelse: velg erstatning for det som mangler i ordre #{order_number}', 'lp-missing' );
    }

    public function get_default_heading() {
        return __( 'Vi venter fortsatt på valget ditt', 'lp-missing' );
    }

    public function get_default_additional_content() {
        return __( 'Har du spørsmål? Svar gjerne på denne e-posten, så hjelper vi deg.', 'lp-missing' );
    }

    /**
     * @param int           $order_id
     * @param int           $item_id Unused (reminders are per order); kept for backwards compatibility.
     * @param WC_Order|null $order   Optional order object (the caller's current copy).
     * @return bool Whether the email was sent.
     */
    public function trigger( $order_id, $item_id = 0, $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || ! LP_Missing_Orders::order_has_missing_items( $order ) ) {
            return false;
        }
        $this->setup_locale();
        $this->prepare_customer_email( $order, true );
        $sent = $this->lines ? $this->send_prepared() : false;
        $this->restore_locale();
        return $sent;
    }

    protected function get_template_args( $plain_text ) {
        return $this->get_customer_template_args( $plain_text );
    }
}
