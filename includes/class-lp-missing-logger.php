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

    /**
     * A warning that anonymous visitors can trigger (bad links, refused previews): at most $max per $window seconds
     * for the same $bucket, so the log cannot be flooded. The last one logged says further ones are suppressed.
     */
    public static function throttled_warning( $bucket, $message, $context = array(), $max = 20, $window = 600 ) {
        $key   = 'lp_missing_log_' . sanitize_key( $bucket );
        $state = get_transient( $key );
        $now   = time();
        if ( ! is_array( $state ) || empty( $state['start'] ) || absint( $state['start'] ) + $window <= $now ) {
            $state = array(
                'count' => 0,
                'start' => $now,
            );
        }
        $state['count'] = absint( $state['count'] ) + 1;
        set_transient( $key, $state, max( 1, absint( $state['start'] ) + $window - $now ) );
        if ( $state['count'] > $max ) {
            return;
        }
        if ( $state['count'] === $max ) {
            $message .= ' ' . sprintf( 'Further warnings of this kind are suppressed for %d minutes.', (int) ceil( $window / 60 ) );
        }
        self::warning( $message, $context );
    }
}
