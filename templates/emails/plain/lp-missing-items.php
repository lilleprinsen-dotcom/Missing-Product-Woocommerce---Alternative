<?php
/**
 * Missing items list used in the customer emails (plain text).
 *
 * Override by copying it to yourtheme/lp-missing/emails/plain/lp-missing-items.php.
 *
 * @package LP_Missing
 * @version 1.2.0
 *
 * @var array $lines item_id => array( name, qty_ordered, qty_missing, note, alternatives (names), propose_delete ).
 */

defined( 'ABSPATH' ) || exit;

foreach ( (array) $lines as $line ) {
    /* translators: 1: product name, 2: missing quantity, 3: ordered quantity */
    echo esc_html( sprintf( __( '- %1$s: mangler %2$d av %3$d stk', 'lp-missing' ), $line['name'], absint( $line['qty_missing'] ), absint( $line['qty_ordered'] ) ) ) . "\n";
    $note = trim( wp_strip_all_tags( $line['note'] ) );
    if ( '' !== $note ) {
        echo '  ' . esc_html( $note ) . "\n";
    }
    if ( ! empty( $line['alternatives'] ) ) {
        /* translators: %s: comma separated product names */
        echo '  ' . esc_html( sprintf( __( 'Forslag til erstatning: %s', 'lp-missing' ), implode( ', ', $line['alternatives'] ) ) ) . "\n";
    }
    if ( ! empty( $line['propose_delete'] ) ) {
        echo '  ' . esc_html__( 'Du kan også velge å fjerne varen fra ordren.', 'lp-missing' ) . "\n";
    }
}
echo "\n";
