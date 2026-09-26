<?php
/**
 * Reminder email for lines still waiting for the customer.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Product_Reminder_Email extends WC_Email {

    protected $item_name = '';

    public function __construct() {
        $this->id             = 'lp_missing_customer_reminder';
        $this->customer_email = true;
        $this->title          = __( 'Påminnelse – manglende varer', 'lp-missing' );
        $this->description    = __( 'Påminnelse til kunden om å velge løsning for manglende varer.', 'lp-missing' );
        $this->heading        = __( 'Liten påminnelse fra oss 💌', 'lp-missing' );
        $this->subject        = __( 'Påminnelse: vi venter på valget ditt for ordre #{order_number}', 'lp-missing' );
        $this->placeholders   = array(
            '{order_number}' => '',
            '{magic_link}'   => '',
            '{item_name}'    => '',
        );
        parent::__construct();
    }

    public function trigger( $order_id, $item_id = 0 ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || ! LP_Missing_Product_Handler::order_has_missing_items( $order ) ) {
            return false;
        }
        $item = $order->get_item( $item_id, false );
        if ( ! $item ) {
            return false;
        }
        $this->object     = $order;
        $this->recipient  = $order->get_billing_email();
        // One reminder covers every line still waiting for the customer.
        $names            = LP_Missing_Product_Handler::get_awaiting_item_names( $order );
        $this->item_name  = $names ? implode( ', ', $names ) : $item->get_name();
        $this->placeholders['{order_number}'] = $order->get_order_number();
        $this->placeholders['{magic_link}']   = LP_Missing_Product_Handler::get_magic_link_for_order( $order );
        $this->placeholders['{item_name}']    = $this->item_name;

        if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
            return false;
        }

        return $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
    }

    public function get_content_html() {
        ob_start();
        wc_get_template( 'emails/email-header.php', array( 'email_heading' => $this->get_heading(), 'email' => $this ) );
        $name = LP_Missing_Product_Handler::get_customer_first_name( $this->object );
        ?>
        <p><?php printf( esc_html__( 'Hei %1$s 👋 Vi trenger fortsatt valget ditt for %2$s i ordre %3$s.', 'lp-missing' ), esc_html( $name ), esc_html( $this->placeholders['{item_name}'] ), esc_html( $this->object->get_order_number() ) ); ?></p>
        <p><?php esc_html_e( 'Åpne lenken under for å fullføre valget ditt.', 'lp-missing' ); ?></p>
        <p><?php esc_html_e( 'Du ser samtidig en enkel prisoversikt (mer, mindre eller samme pris).', 'lp-missing' ); ?></p>
        <p>
            <a class="button" href="<?php echo esc_url( $this->placeholders['{magic_link}'] ); ?>"><?php esc_html_e( 'Åpne valgside', 'lp-missing' ); ?></a>
        </p>
        <p><?php esc_html_e( 'Hvis knappen ikke virker, kopier denne lenken inn i nettleseren:', 'lp-missing' ); ?><br />
            <a href="<?php echo esc_url( $this->placeholders['{magic_link}'] ); ?>"><?php echo esc_html( $this->placeholders['{magic_link}'] ); ?></a>
        </p>
        <?php
        wc_get_template( 'emails/email-footer.php', array( 'email' => $this ) );
        return ob_get_clean();
    }

    public function get_content_plain() {
        $name = LP_Missing_Product_Handler::get_customer_first_name( $this->object );
        $lines = array(
            sprintf( __( 'Hei %1$s! Vi trenger fortsatt valget ditt for %2$s i ordre %3$s.', 'lp-missing' ), $name, $this->placeholders['{item_name}'], $this->object->get_order_number() ),
            __( 'Bruk lenken under for å velge alternativ.', 'lp-missing' ),
            __( 'Lenken viser tydelig om alternativet blir dyrere, billigere eller lik pris.', 'lp-missing' ),
            $this->placeholders['{magic_link}'],
        );
        return implode( "\n\n", $lines );
    }
}
