<?php
/**
 * Staff email: a customer decided on missing items (plain text).
 *
 * Override by copying it to yourtheme/lp-missing/emails/plain/lp-missing-staff-decision.php.
 *
 * @package LP_Missing
 * @version 1.2.0
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var array    $decisions
 * @var string   $order_url
 * @var string   $customer_name
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: 1: customer name, 2: order number */
echo esc_html( sprintf( __( '%1$s has made a choice for missing items in order %2$s.', 'lp-missing' ), $customer_name ? $customer_name : __( 'The customer', 'lp-missing' ), '#' . $order->get_order_number() ) ) . "\n\n";

foreach ( $decisions as $decision ) {
    /* translators: 1: product name, 2: quantity */
    echo esc_html( sprintf( __( '%1$s (missing: %2$d)', 'lp-missing' ), $decision['name'], absint( $decision['qty_missing'] ) ) ) . "\n";
    /* translators: %s: choice */
    echo '  ' . esc_html( sprintf( __( 'Customer choice: %s', 'lp-missing' ), $decision['choice'] ) ) . "\n";
    if ( $decision['previous'] ) {
        /* translators: %s: previous choice */
        echo '  ' . esc_html( sprintf( __( 'Changed from: %s', 'lp-missing' ), $decision['previous'] ) ) . "\n";
    }
    if ( $decision['price'] ) {
        /* translators: %s: price difference */
        echo '  ' . esc_html( sprintf( __( 'Price difference: %s', 'lp-missing' ), $decision['price'] ) ) . "\n";
    }
    if ( $decision['next_step'] ) {
        echo '  ' . esc_html( $decision['next_step'] ) . "\n";
    }
    echo "\n";
}

echo esc_html__( 'Open the order:', 'lp-missing' ) . "\n";
echo esc_url_raw( $order_url ) . "\n\n";

echo "----------------------------------------\n\n";

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
    echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
