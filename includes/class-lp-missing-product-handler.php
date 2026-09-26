<?php
/**
 * Backwards-compatible facade for code that called the old single class.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Product_Handler {

    public static function init() {
        // Kept for backwards compatibility; the plugin bootstraps itself via LP_Missing_Plugin::init().
    }

    public static function order_has_missing_items( $order ) {
        return LP_Missing_Orders::order_has_missing_items( $order );
    }

    public static function get_magic_link_for_order( $order ) {
        return LP_Missing_Magic_Link::get_magic_link_for_order( $order );
    }

    public static function get_customer_first_name( $order ) {
        return LP_Missing_Util::get_customer_first_name( $order );
    }

    public static function get_awaiting_item_names( $order ) {
        return LP_Missing_Orders::get_awaiting_item_names( $order );
    }
}
