<?php
/**
 * Staff email: a customer decided on missing items (HTML).
 *
 * Override by copying it to yourtheme/lp-missing/emails/lp-missing-staff-decision.php.
 *
 * @package LP_Missing
 * @version 1.2.0
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var array    $decisions      item_id => array( name, qty_missing, choice, previous, price, next_step ).
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
        esc_html__( '%1$s has made a choice for missing items in order %2$s.', 'lp-missing' ),
        esc_html( $customer_name ? $customer_name : __( 'The customer', 'lp-missing' ) ),
        '<a href="' . esc_url( $order_url ) . '">#' . esc_html( $order->get_order_number() ) . '</a>'
    );
    ?>
</p>

<table class="td" cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:0 0 24px;" role="presentation">
    <thead>
        <tr>
            <th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Item', 'lp-missing' ); ?></th>
            <th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Customer choice', 'lp-missing' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ( $decisions as $decision ) : ?>
            <tr>
                <td class="td" style="text-align:left;vertical-align:top;">
                    <strong><?php echo esc_html( $decision['name'] ); ?></strong><br />
                    <?php
                    /* translators: %d: quantity */
                    printf( esc_html__( 'Missing: %d', 'lp-missing' ), absint( $decision['qty_missing'] ) );
                    ?>
                </td>
                <td class="td" style="text-align:left;vertical-align:top;">
                    <strong><?php echo esc_html( $decision['choice'] ); ?></strong>
                    <?php if ( $decision['previous'] ) : ?>
                        <br /><small>
                            <?php
                            /* translators: %s: previous choice */
                            printf( esc_html__( 'Changed from: %s', 'lp-missing' ), esc_html( $decision['previous'] ) );
                            ?>
                        </small>
                    <?php endif; ?>
                    <?php if ( $decision['price'] ) : ?>
                        <br />
                        <?php
                        /* translators: %s: price difference */
                        printf( esc_html__( 'Price difference: %s', 'lp-missing' ), esc_html( $decision['price'] ) );
                        ?>
                    <?php endif; ?>
                    <?php if ( $decision['next_step'] ) : ?>
                        <br /><em><?php echo esc_html( $decision['next_step'] ); ?></em>
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
