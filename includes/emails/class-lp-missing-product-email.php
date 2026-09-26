<?php
/**
 * Customer email: which items are missing and the link to choose an alternative or removal.
 *
 * Placeholders: {order_number}, {order_date}, {count} (number of missing lines listed), {item_names},
 * {deadline} (formatted, empty without a deadline), {customer_first_name}, {magic_link}, plus WooCommerce's
 * {site_title}, {site_address}, {site_url}, {store_email}.
 *
 * Templates: emails/lp-missing-customer.php and emails/plain/lp-missing-customer.php.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Product_Email extends LP_Missing_Email_Base {

    public function __construct() {
        $this->id             = 'lp_missing_customer_email';
        $this->customer_email = true;
        $this->title          = __( 'Missing items – customer choice', 'lp-missing' );
        $this->description    = __( 'Sent to the customer when items in the order are missing: lists them and links to the secure page where the customer chooses an alternative or removal.', 'lp-missing' );
        $this->template_html  = 'emails/lp-missing-customer.php';
        $this->template_plain = 'emails/plain/lp-missing-customer.php';
        $this->placeholders   = $this->get_customer_placeholders();
        parent::__construct();
    }

    public function get_default_subject() {
        return __( 'Velg erstatning for {count} vare(r) i ordre #{order_number}', 'lp-missing' );
    }

    public function get_default_heading() {
        return __( 'Vi trenger et raskt valg fra deg', 'lp-missing' );
    }

    public function get_default_additional_content() {
        return __( 'Har du spørsmål? Svar gjerne på denne e-posten, så hjelper vi deg.', 'lp-missing' );
    }

    /**
     * @param int           $order_id
     * @param WC_Order|null $order Optional order object (the caller's current copy).
     * @return bool Whether the email was sent.
     */
    public function trigger( $order_id, $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || ! LP_Missing_Orders::order_has_missing_items( $order ) ) {
            return false;
        }
        $this->setup_locale();
        $this->prepare_customer_email( $order, false );
        $sent = $this->lines ? $this->send_prepared() : false;
        $this->restore_locale();
        return $sent;
    }

    protected function get_template_args( $plain_text ) {
        return $this->get_customer_template_args( $plain_text );
    }
}
