<?php
/**
 * Signed customer links and portal verification tokens.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Magic_Link {
    public static function get_secret() {
        $secret = (string) get_option( LP_Missing_Plugin::OPTION_SECRET, '' );
        if ( empty( $secret ) ) {
            $secret = wp_generate_password( 64, true, true );
            add_option( LP_Missing_Plugin::OPTION_SECRET, $secret, '', false );
        }
        return $secret;
    }

    public static function generate_signature( $order_id, $email ) {
        if ( ! $order_id || ! $email ) {
            return '';
        }
        $data = $order_id . '|' . strtolower( (string) $email );
        return hash_hmac( 'sha256', $data, self::get_secret() );
    }

    public static function validate_signature( $order_id, $email, $key ) {
        if ( ! $order_id || ! $email || ! $key ) {
            return false;
        }
        $expected = self::generate_signature( $order_id, $email );
        return hash_equals( $expected, (string) $key );
    }

    /**
     * Short-lived proof that the portal visitor confirmed the billing email, carried in the portal forms
     * so a verified guest is not sent back to the verification step when submitting a choice.
     */
    public static function generate_verification_token( $order_id, $email ) {
        $expires = time() + LP_Missing_Plugin::VERIFY_TOKEN_TTL;
        $data    = 'verified|' . absint( $order_id ) . '|' . strtolower( (string) $email ) . '|' . $expires;
        return $expires . ':' . hash_hmac( 'sha256', $data, self::get_secret() );
    }

    public static function validate_verification_token( $order_id, $email, $token ) {
        $parts = explode( ':', (string) $token, 2 );
        if ( 2 !== count( $parts ) ) {
            return false;
        }
        $expires = absint( $parts[0] );
        if ( $expires < time() ) {
            return false;
        }
        $data     = 'verified|' . absint( $order_id ) . '|' . strtolower( (string) $email ) . '|' . $expires;
        $expected = hash_hmac( 'sha256', $data, self::get_secret() );
        return hash_equals( $expected, $parts[1] );
    }

    public static function get_portal_base_url() {
        $settings = LP_Missing_Settings::get_settings();
        $configured = trim( (string) ( isset( $settings['portal_base_url'] ) ? $settings['portal_base_url'] : '' ) );
        $page_id    = absint( $settings['portal_page_id'] );
        $page_url   = $page_id && 'publish' === get_post_status( $page_id ) ? get_permalink( $page_id ) : '';
        $base_url   = $configured ? $configured : ( $page_url ? $page_url : wc_get_page_permalink( 'myaccount' ) );
        if ( ! $base_url ) {
            $base_url = home_url( '/' );
        }
        return apply_filters( 'lp_missing_portal_base_url', $base_url );
    }

    public static function get_magic_link_for_order( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }
        $args = array(
            'oid' => $order->get_id(),
            'key' => self::generate_signature( $order->get_id(), $order->get_billing_email() ),
        );
        return add_query_arg( $args, self::get_portal_base_url() );
    }
}
