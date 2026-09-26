<?php
/**
 * Staff email "Missing items – customer decided": the customer chose an alternative, approved removal or declined
 * the suggestions (also when a decision is changed). Shows the choice, the frozen price difference and a link to
 * the order screen.
 *
 * Recipient: "Staff email address" in Missing Items Settings, or the site admin email. Sent when this email is
 * enabled here and "Email staff when a customer has chosen" is on in Missing Items Settings.
 *
 * Placeholders: {order_number}, {order_date}, {customer_name}, {item_names}, plus WooCommerce's {site_title} etc.
 * Templates: emails/lp-missing-staff-decision.php and emails/plain/lp-missing-staff-decision.php.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Staff_Decision_Email extends LP_Missing_Email_Base {
    /** @var array Decisions (see LP_Missing_Notifier::describe_decision()). */
    protected $decisions = array();

    public function __construct() {
        $this->id             = 'lp_missing_staff_decision';
        $this->customer_email = false;
        $this->title          = __( 'Missing items – customer decided', 'lp-missing' );
        $this->description    = __( 'Sent to staff when a customer chooses an alternative, approves removal or declines the suggested alternatives. Recipient and on/off: Missing Items Settings > Staff notifications.', 'lp-missing' );
        $this->template_html  = 'emails/lp-missing-staff-decision.php';
        $this->template_plain = 'emails/plain/lp-missing-staff-decision.php';
        $this->placeholders   = array(
            '{order_number}'  => '',
            '{order_date}'    => '',
            '{customer_name}' => '',
            '{item_names}'    => '',
        );
        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Customer decided on missing items in order #{order_number}', 'lp-missing' );
    }

    public function get_default_heading() {
        return __( 'Customer decision on order #{order_number}', 'lp-missing' );
    }

    public function is_enabled() {
        return parent::is_enabled() && 'yes' === LP_Missing_Settings::get( 'notify_staff_on_decision' );
    }

    /**
     * @param WC_Order $order
     * @param array    $changes item_id => array( 'new' => line data, 'old' => line data ).
     * @return bool Whether the email was sent.
     */
    public function trigger( $order, $changes ) {
        if ( ! $order instanceof WC_Order || ! $changes ) {
            return false;
        }
        $this->object    = $order;
        $this->recipient = LP_Missing_Notifier::get_staff_recipient();
        $this->decisions = array();
        foreach ( $changes as $item_id => $change ) {
            $new = wp_parse_args( (array) $change['new'], LP_Missing_Line::default_item_data() );
            $old = wp_parse_args( (array) $change['old'], LP_Missing_Line::default_item_data() );
            $this->decisions[ $item_id ] = LP_Missing_Notifier::describe_decision( $order, $item_id, $new, $old );
        }
        $this->lines = $this->decisions;

        $date_created = $order->get_date_created();
        $this->placeholders['{order_number}']  = $order->get_order_number();
        $this->placeholders['{order_date}']    = $date_created ? wc_format_datetime( $date_created ) : '';
        $this->placeholders['{customer_name}'] = trim( $order->get_formatted_billing_full_name() );
        $this->placeholders['{item_names}']    = implode( ', ', wp_list_pluck( $this->decisions, 'name' ) );

        return $this->send_prepared();
    }

    protected function get_template_args( $plain_text ) {
        return array_merge(
            $this->get_base_template_args( $plain_text ),
            array(
                'decisions'     => $this->decisions,
                'order_url'     => $this->object instanceof WC_Order ? LP_Missing_Util::get_order_edit_url( $this->object ) : '',
                'customer_name' => $this->placeholders['{customer_name}'],
            )
        );
    }

    public function prepare_preview() {
        parent::prepare_preview();
        $this->decisions = array();
        foreach ( $this->lines as $key => $line ) {
            $this->decisions[ $key ] = array(
                'item_id'     => $key,
                'name'        => $line['name'],
                'qty_missing' => $line['qty_missing'],
                /* translators: 1: product name, 2: quantity */
                'choice'      => sprintf( __( 'Alternative: %1$s × %2$d', 'lp-missing' ), __( 'Sample alternative', 'lp-missing' ), $line['qty_missing'] ),
                'previous'    => '',
                'price'       => __( 'No price difference.', 'lp-missing' ),
                'next_step'   => __( 'Apply the alternative in the Missing / Problem Items box on the order.', 'lp-missing' ),
            );
        }
        $this->placeholders['{item_names}'] = implode( ', ', wp_list_pluck( $this->decisions, 'name' ) );
    }
}
