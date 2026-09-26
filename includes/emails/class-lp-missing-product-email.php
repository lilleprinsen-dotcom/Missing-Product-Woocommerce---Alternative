<?php
/**
 * Customer email with the portal link.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Product_Email extends WC_Email {

    public function __construct() {
        $this->id             = 'lp_missing_customer_email';
        $this->customer_email = true;
        $this->title          = __( 'Manglende varer – kundeportal', 'lp-missing' );
        $this->description    = __( 'E-post som ber kunden velge løsning for manglende varer i en sikker portal.', 'lp-missing' );
        $this->heading        = __( 'Vi trenger et raskt valg fra deg 💛', 'lp-missing' );
        $this->subject        = __( 'Vi mangler noen varer i ordre #{order_number}', 'lp-missing' );
        $this->placeholders   = array(
            '{order_number}' => '',
            '{magic_link}'   => '',
        );
        parent::__construct();
    }

    public function trigger( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || ! LP_Missing_Product_Handler::order_has_missing_items( $order ) ) {
            return false;
        }
        $this->object     = $order;
        $this->recipient  = $order->get_billing_email();
        $this->placeholders['{order_number}'] = $order->get_order_number();
        $this->placeholders['{magic_link}']   = LP_Missing_Product_Handler::get_magic_link_for_order( $order );

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
        <p><?php printf( esc_html__( 'Hei %1$s 👋 Vi har dessverre ikke alt på lager i ordre %2$s.', 'lp-missing' ), esc_html( $name ), esc_html( $this->object->get_order_number() ) ); ?></p>
        <p><?php esc_html_e( 'Trykk på knappen under for å velge et alternativ. Det tar bare et minutt.', 'lp-missing' ); ?></p>
        <p><?php esc_html_e( 'På valgsiden ser du tydelig om et alternativ koster mer, mindre eller det samme som originalen.', 'lp-missing' ); ?></p>
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
            sprintf( __( 'Hei %1$s! Vi mangler noen varer i ordre %2$s.', 'lp-missing' ), $name, $this->object->get_order_number() ),
            __( 'Velg hva som passer best for deg i den sikre lenken under.', 'lp-missing' ),
            __( 'Du får en enkel prisoversikt som viser om alternativet blir dyrere eller billigere.', 'lp-missing' ),
            $this->placeholders['{magic_link}'],
        );
        return implode( "\n\n", $lines );
    }
}
