<?php
/**
 * Customer email: items are missing, choose what we should do (HTML).
 *
 * Override by copying it to yourtheme/lp-missing/emails/lp-missing-customer.php.
 *
 * @package LP_Missing
 * @version 1.2.0
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var array    $lines          Missing lines (see LP_Missing_Notifier::get_customer_lines()).
 * @var string   $portal_url     Secure link to the choice page.
 * @var int      $deadline       Deadline timestamp (0 = none).
 * @var string   $deadline_text  What happens at the deadline ('' = no deadline).
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
    /* translators: %s: customer first name */
    printf( esc_html__( 'Hei %s,', 'lp-missing' ), esc_html( $customer_name ) );
    ?>
</p>
<p>
    <?php
    /* translators: %s: order number */
    printf( esc_html( _n( 'Vi har dessverre ikke denne varen på lager til ordre #%s:', 'Vi har dessverre ikke disse varene på lager til ordre #%s:', count( $lines ), 'lp-missing' ) ), esc_html( $order->get_order_number() ) );
    ?>
</p>

<?php echo LP_Missing_Util::get_template_html( 'emails/lp-missing-items.php', array( 'lines' => $lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template. ?>

<p><?php esc_html_e( 'Velg hva du ønsker på valgsiden – det tar bare et minutt. Der ser du også om et alternativ koster mer, mindre eller det samme som originalen.', 'lp-missing' ); ?></p>

<?php if ( $deadline_text ) : ?>
    <p><strong><?php echo esc_html( $deadline_text ); ?></strong></p>
<?php endif; ?>

<p style="margin:24px 0;">
    <a class="button lp-missing-button" href="<?php echo esc_url( $portal_url ); ?>" style="display:inline-block;padding:12px 20px;border-radius:4px;background:#1f2937;color:#ffffff;font-weight:bold;text-decoration:none;"><?php esc_html_e( 'Velg løsning', 'lp-missing' ); ?></a>
</p>
<p>
    <?php esc_html_e( 'Hvis knappen ikke virker, kopier denne lenken inn i nettleseren:', 'lp-missing' ); ?><br />
    <a href="<?php echo esc_url( $portal_url ); ?>"><?php echo esc_html( $portal_url ); ?></a>
</p>

<?php
if ( $additional_content ) {
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
