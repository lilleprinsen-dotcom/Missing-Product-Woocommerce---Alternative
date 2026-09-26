<?php
/**
 * Shared base for the plugin's WooCommerce emails: templates from templates/emails/ (theme override in
 * yourtheme/lp-missing/emails/), and the customer email set-up (missing lines, portal link, deadline, placeholders).
 *
 * Only loaded from the woocommerce_email_classes filter, when WC_Email exists.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class LP_Missing_Email_Base extends WC_Email {
    /** @var array Lines listed in the email being sent (item_id => details). */
    protected $lines = array();
    /** @var string Customer portal link. */
    protected $portal_url = '';
    /** @var int Deadline shown to the customer (0 = none). */
    protected $deadline = 0;

    public function __construct() {
        $this->template_base = LP_MISSING_DIR . 'templates/';
        if ( property_exists( $this, 'email_group' ) ) {
            $this->email_group = 'order-changes';
        }
        parent::__construct();
    }

    /**
     * Item IDs listed in the last email prepared by this object.
     */
    public function get_line_ids() {
        return array_keys( $this->lines );
    }

    /**
     * Template variables. Children override this to add their own (on top of get_base_template_args()).
     */
    protected function get_template_args( $plain_text ) {
        return $this->get_base_template_args( $plain_text );
    }

    protected function get_base_template_args( $plain_text ) {
        return array(
            'order'              => $this->object,
            'email_heading'      => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => ! $this->is_customer_email(),
            'plain_text'         => $plain_text,
            'email'              => $this,
        );
    }

    public function get_content_html() {
        return wc_get_template_html( $this->template_html, $this->get_template_args( false ), LP_Missing_Notifier::TEMPLATE_PATH, $this->template_base );
    }

    public function get_content_plain() {
        return wc_get_template_html( $this->template_plain, $this->get_template_args( true ), LP_Missing_Notifier::TEMPLATE_PATH, $this->template_base );
    }

    protected function send_prepared() {
        if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
            return false;
        }
        return (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
    }

    /**
     * Placeholders of the customer emails (documented in the email settings).
     */
    protected function get_customer_placeholders() {
        return array(
            '{order_number}'        => '',
            '{order_date}'          => '',
            '{count}'               => '',
            '{item_names}'          => '',
            '{deadline}'            => '',
            '{customer_first_name}' => '',
            '{magic_link}'          => '',
        );
    }

    /**
     * Set up a customer email for $order: recipient, the lines to list, portal link, deadline and placeholders.
     *
     * @param WC_Order $order
     * @param bool     $awaiting_only List only lines waiting for the customer; otherwise fall back to every open line
     *                                (e.g. staff re-send the email after the customer already answered).
     */
    protected function prepare_customer_email( $order, $awaiting_only ) {
        $this->object     = $order;
        $this->recipient  = $order->get_billing_email();
        $this->lines      = LP_Missing_Notifier::get_customer_lines( $order, true );
        if ( ! $this->lines && ! $awaiting_only ) {
            $this->lines = LP_Missing_Notifier::get_customer_lines( $order, false );
        }
        $this->portal_url = LP_Missing_Magic_Link::get_magic_link_for_order( $order );
        $this->deadline   = LP_Missing_Lifecycle::get_order_deadline( $order );

        $names         = wp_list_pluck( $this->lines, 'name' );
        $date_created  = $order->get_date_created();
        $replacements  = array(
            '{order_number}'        => $order->get_order_number(),
            '{order_date}'          => $date_created ? wc_format_datetime( $date_created ) : '',
            '{count}'               => (string) count( $this->lines ),
            '{item_names}'          => implode( ', ', $names ),
            '{item_name}'           => implode( ', ', $names ),
            '{deadline}'            => $this->deadline ? LP_Missing_Deadline::format( $this->deadline ) : '',
            '{customer_first_name}' => LP_Missing_Util::get_customer_first_name( $order ),
            '{magic_link}'          => $this->portal_url,
        );
        foreach ( $replacements as $key => $value ) {
            if ( array_key_exists( $key, $this->placeholders ) ) {
                $this->placeholders[ $key ] = $value;
            }
        }
    }

    protected function get_customer_template_args( $plain_text ) {
        return array_merge(
            $this->get_base_template_args( $plain_text ),
            array(
                'lines'         => $this->lines,
                'portal_url'    => $this->portal_url,
                'deadline'      => $this->deadline,
                'deadline_text' => LP_Missing_Notifier::get_deadline_text( $this->object, $this->deadline ),
                'customer_name' => $this->object instanceof WC_Order ? LP_Missing_Util::get_customer_first_name( $this->object ) : '',
            )
        );
    }

    /**
     * Sample content for WooCommerce's email preview (the object is a dummy order that is not saved).
     */
    public function prepare_preview() {
        $order       = $this->object instanceof WC_Order ? $this->object : null;
        $this->lines = array();
        $key         = 0;
        foreach ( $order ? $order->get_items( 'line_item' ) : array() as $item ) {
            $this->lines[ ++$key ] = array(
                'item_id'        => $key,
                'name'           => $item->get_name(),
                'qty_ordered'    => max( 1, (int) $item->get_quantity() ),
                'qty_missing'    => 1,
                'note'           => __( 'Kommer tilbake på lager om ca. to uker.', 'lp-missing' ),
                'alternatives'   => array( __( 'Tilsvarende vare fra et annet merke', 'lp-missing' ) ),
                'propose_delete' => true,
                'awaiting'       => true,
            );
        }
        if ( ! $this->lines ) {
            $this->lines[1] = array(
                'item_id'        => 1,
                'name'           => __( 'Eksempelvare', 'lp-missing' ),
                'qty_ordered'    => 2,
                'qty_missing'    => 1,
                'note'           => '',
                'alternatives'   => array(),
                'propose_delete' => true,
                'awaiting'       => true,
            );
        }
        $this->portal_url = add_query_arg( array( 'oid' => 0, 'key' => 'preview' ), LP_Missing_Magic_Link::get_portal_base_url() );
        $this->deadline   = LP_Missing_Deadline::enabled() ? LP_Missing_Deadline::calculate( time() ) : 0;
        $names            = implode( ', ', wp_list_pluck( $this->lines, 'name' ) );
        foreach ( array( '{count}' => (string) count( $this->lines ), '{item_names}' => $names, '{item_name}' => $names, '{deadline}' => $this->deadline ? LP_Missing_Deadline::format( $this->deadline ) : '', '{magic_link}' => $this->portal_url ) as $placeholder => $value ) {
            if ( array_key_exists( $placeholder, $this->placeholders ) ) {
                $this->placeholders[ $placeholder ] = $value;
            }
        }
    }
}
