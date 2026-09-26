<?php
/**
 * Staff email: the decision deadline passed and the default action was applied (plain text).
 *
 * Override by copying it to yourtheme/lp-missing/emails/plain/lp-missing-staff-deadline.php.
 *
 * @package LP_Missing
 * @version 1.2.0
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var array    $results
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
echo esc_html( sprintf( __( '%1$s did not choose before the decision deadline in order %2$s, so the default action was applied:', 'lp-missing' ), $customer_name ? $customer_name : __( 'The customer', 'lp-missing' ), '#' . $order->get_order_number() ) ) . "\n\n";

foreach ( $results as $result ) {
    /* translators: 1: product name, 2: quantity */
    echo esc_html( sprintf( __( '%1$s (quantity: %2$d)', 'lp-missing' ), $result['name'], absint( $result['qty'] ) ) ) . "\n";
    if ( 'success' !== $result['status'] ) {
        echo '  ' . esc_html__( 'Failed – handle it manually.', 'lp-missing' ) . ' ' . esc_html( $result['message'] ) . "\n\n";
        continue;
    }
    if ( 'refund' === $result['action'] ) {
        /* translators: %s: amount */
        echo '  ' . esc_html( sprintf( __( 'Refund of %s recorded in WooCommerce only: pay it back via the payment provider.', 'lp-missing' ), LP_Missing_Util::plain_price( $result['amount'], $order ) ) ) . "\n\n";
    } else {
        /* translators: %s: amount */
        echo '  ' . esc_html( sprintf( __( 'Removed from the order; the order total was reduced by %s.', 'lp-missing' ), LP_Missing_Util::plain_price( $result['amount'], $order ) ) ) . "\n\n";
    }
}

echo esc_html__( 'Open the order:', 'lp-missing' ) . "\n";
echo esc_url_raw( $order_url ) . "\n\n";

echo "----------------------------------------\n\n";

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
    echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
