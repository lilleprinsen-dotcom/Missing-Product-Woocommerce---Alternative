<?php
/**
 * Staff email: the decision deadline passed and the default action was applied (HTML).
 *
 * Override by copying it to yourtheme/lp-missing/emails/lp-missing-staff-deadline.php.
 *
 * @package LP_Missing
 * @version 1.2.0
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var array    $results        item_id => array( name, qty, action ('refund'|'reduce'), status, message, amount ).
 * @var string   $order_url      Order edit screen.
 * @var string   $customer_name
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
    <?php
    printf(
        /* translators: 1: customer name, 2: order number (linked) */
        esc_html__( '%1$s did not choose before the decision deadline in order %2$s, so the default action was applied:', 'lp-missing' ),
        esc_html( $customer_name ? $customer_name : __( 'The customer', 'lp-missing' ) ),
        '<a href="' . esc_url( $order_url ) . '">#' . esc_html( $order->get_order_number() ) . '</a>'
    );
    ?>
</p>

<table class="td" cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:0 0 24px;" role="presentation">
    <thead>
        <tr>
            <th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Item', 'lp-missing' ); ?></th>
            <th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Result', 'lp-missing' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ( $results as $result ) : ?>
            <tr>
                <td class="td" style="text-align:left;vertical-align:top;">
                    <strong><?php echo esc_html( $result['name'] ); ?></strong><br />
                    <?php
                    /* translators: %d: quantity */
                    printf( esc_html__( 'Quantity: %d', 'lp-missing' ), absint( $result['qty'] ) );
                    ?>
                </td>
                <td class="td" style="text-align:left;vertical-align:top;">
                    <?php if ( 'success' !== $result['status'] ) : ?>
                        <strong><?php esc_html_e( 'Failed – handle it manually.', 'lp-missing' ); ?></strong><br />
                        <?php echo esc_html( $result['message'] ); ?>
                    <?php elseif ( 'refund' === $result['action'] ) : ?>
                        <?php
                        /* translators: %s: amount */
                        printf( esc_html__( 'Refund of %s recorded in WooCommerce only: pay it back via the payment provider.', 'lp-missing' ), esc_html( LP_Missing_Util::plain_price( $result['amount'], $order ) ) );
                        ?>
                    <?php else : ?>
                        <?php
                        /* translators: %s: amount */
                        printf( esc_html__( 'Removed from the order; the order total was reduced by %s.', 'lp-missing' ), esc_html( LP_Missing_Util::plain_price( $result['amount'], $order ) ) );
                        ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<p style="margin:24px 0;">
    <a class="button lp-missing-button" href="<?php echo esc_url( $order_url ); ?>" style="display:inline-block;padding:12px 20px;border-radius:4px;background:#1f2937;color:#ffffff;font-weight:bold;text-decoration:none;"><?php esc_html_e( 'Open the order', 'lp-missing' ); ?></a>
</p>

<?php
if ( $additional_content ) {
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
