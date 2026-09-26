<?php
/**
 * Event logging to WooCommerce > Status > Logs (source "lp-missing").
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Logger {
    const SOURCE = 'lp-missing';

    /**
     * @param string $level   A PSR-3 level: debug, info, notice, warning, error, critical, alert, emergency.
     * @param string $message Human readable message. Never include secrets (keys, tokens).
     * @param array  $context Extra context, e.g. array( 'order_id' => 1, 'item_id' => 2 ).
     */
    public static function log( $level, $message, $context = array() ) {
        if ( ! function_exists( 'wc_get_logger' ) || 'yes' !== LP_Missing_Settings::get( 'enable_logging' ) ) {
            return;
        }
        if ( $context ) {
            $message .= ' ' . wp_json_encode( $context );
        }
        wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
    }

    public static function info( $message, $context = array() ) {
        self::log( 'info', $message, $context );
    }

    public static function warning( $message, $context = array() ) {
        self::log( 'warning', $message, $context );
    }

    public static function error( $message, $context = array() ) {
        self::log( 'error', $message, $context );
    }
}
