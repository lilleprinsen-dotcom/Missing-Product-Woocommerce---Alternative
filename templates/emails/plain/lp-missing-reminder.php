<?php
/**
 * Customer reminder: we are still waiting for the choice (plain text).
 *
 * Override by copying it to yourtheme/lp-missing/emails/plain/lp-missing-reminder.php.
 *
 * @package LP_Missing
 * @version 1.2.0
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var array    $lines
 * @var string   $portal_url
 * @var int      $deadline
 * @var string   $deadline_text
 * @var string   $customer_name
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: %s: customer first name */
echo esc_html( sprintf( __( 'Hei %s,', 'lp-missing' ), $customer_name ) ) . "\n\n";
/* translators: %s: order number */
echo esc_html( sprintf( _n( 'Vi venter fortsatt på valget ditt for denne varen i ordre #%s:', 'Vi venter fortsatt på valget ditt for disse varene i ordre #%s:', count( $lines ), 'lp-missing' ), $order->get_order_number() ) ) . "\n\n";

echo LP_Missing_Util::get_template_html( 'emails/plain/lp-missing-items.php', array( 'lines' => $lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.

if ( $deadline_text ) {
    echo esc_html( $deadline_text ) . "\n\n";
}

echo esc_html__( 'Velg løsning her:', 'lp-missing' ) . "\n";
echo esc_url_raw( $portal_url ) . "\n\n";

echo "----------------------------------------\n\n";

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
    echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
