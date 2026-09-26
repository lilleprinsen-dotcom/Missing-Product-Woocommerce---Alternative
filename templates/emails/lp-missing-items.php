<?php
/**
 * Missing items table used in the customer emails (HTML).
 *
 * Override by copying it to yourtheme/lp-missing/emails/lp-missing-items.php.
 *
 * @package LP_Missing
 * @version 1.2.0
 *
 * @var array $lines item_id => array( name, qty_ordered, qty_missing, note, alternatives (names), propose_delete ).
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $lines ) ) {
    return;
}
?>
<table class="td lp-missing-items" cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:0 0 24px;" role="presentation">
    <thead>
        <tr>
            <th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Vare', 'lp-missing' ); ?></th>
            <th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Mangler', 'lp-missing' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ( $lines as $line ) : ?>
            <tr>
                <td class="td" style="text-align:left;vertical-align:top;">
                    <strong><?php echo esc_html( $line['name'] ); ?></strong>
                    <?php if ( '' !== trim( wp_strip_all_tags( $line['note'] ) ) ) : ?>
                        <br /><em><?php echo wp_kses_post( $line['note'] ); ?></em>
                    <?php endif; ?>
                    <?php if ( ! empty( $line['alternatives'] ) ) : ?>
                        <br /><small>
                            <?php
                            /* translators: %s: comma separated product names */
                            printf( esc_html__( 'Forslag til erstatning: %s', 'lp-missing' ), esc_html( implode( ', ', $line['alternatives'] ) ) );
                            ?>
                        </small>
                    <?php endif; ?>
                    <?php if ( ! empty( $line['propose_delete'] ) ) : ?>
                        <br /><small><?php esc_html_e( 'Du kan også velge å fjerne varen fra ordren.', 'lp-missing' ); ?></small>
                    <?php endif; ?>
                </td>
                <td class="td" style="text-align:left;vertical-align:top;white-space:nowrap;">
                    <?php
                    /* translators: 1: missing quantity, 2: ordered quantity */
                    printf( esc_html__( '%1$d av %2$d stk', 'lp-missing' ), absint( $line['qty_missing'] ), absint( $line['qty_ordered'] ) );
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
