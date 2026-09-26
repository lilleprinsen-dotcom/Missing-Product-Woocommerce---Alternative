<?php
/**
 * Signed customer links, portal sessions and portal form tokens.
 *
 * Link v2: ?oid={order_id}&lpk={key_id}.{generation}.{expires}.{signature}
 *   - expires after the "magic_link_ttl_days" setting,
 *   - revoked per order by bumping the order meta _lp_missing_link_gen (revoke_links()),
 *   - signed with a key from a key ring (key id in the token, so keys can be rotated without breaking live links);
 *     every HMAC key is derived from the stored key secret combined with wp_salt( 'auth' ),
 *   - bound to the billing email, so changing the email invalidates earlier links.
 * Link v1 (legacy): ?oid={order_id}&key=HMAC(order_id|email) is accepted until the cutoff recorded by the upgrade
 * step plus the link TTL, unless the order's links were revoked.
 *
 * A valid link is exchanged for a signed, per-order session cookie (see LP_Missing_Portal_Access).
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Magic_Link {
    const LINK_GENERATION_META = '_lp_missing_link_gen';

    const PARAM_ORDER   = 'oid';
    const PARAM_TOKEN   = 'lpk';
    const PARAM_LEGACY  = 'key';
    const PARAM_PREVIEW = 'lp_preview';

    const OPTION_KEYS      = 'lp_missing_link_keys';
    const OPTION_V1_CUTOFF = 'lp_missing_link_v1_cutoff';

    const SESSION_COOKIE_PREFIX = 'lp_missing_portal_';

    // ---------------------------------------------------------------------------------------------------------------
    // Legacy (v1) signatures. Kept to validate links sent before v2 during the transition period.
    // ---------------------------------------------------------------------------------------------------------------

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
     * Remember when v2 links took over; v1 links keep working until this moment plus the link TTL.
     * Called by the upgrade step, and lazily the first time a v1 link is checked.
     */
    public static function record_v1_cutoff() {
        $cutoff = absint( get_option( self::OPTION_V1_CUTOFF, 0 ) );
        if ( ! $cutoff ) {
            $cutoff = time();
            add_option( self::OPTION_V1_CUTOFF, $cutoff, '', false );
        }
        return $cutoff;
    }

    public static function get_v1_valid_until() {
        return self::record_v1_cutoff() + self::get_ttl_days() * DAY_IN_SECONDS;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Key ring.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * array( 'current' => key id, 'keys' => array( key id => array( 'secret', 'created', 'retired' ) ) )
     */
    public static function get_keyring() {
        $ring = get_option( self::OPTION_KEYS, array() );
        if ( ! is_array( $ring ) || empty( $ring['current'] ) || empty( $ring['keys'][ $ring['current'] ]['secret'] ) ) {
            $ring = self::add_key( is_array( $ring ) ? $ring : array() );
            update_option( self::OPTION_KEYS, $ring, false );
        }
        return $ring;
    }

    public static function get_current_key_id() {
        $ring = self::get_keyring();
        return (string) $ring['current'];
    }

    /**
     * Start signing with a new key. Links and sessions signed with older keys stay valid until they expire;
     * keys that can no longer have live links are pruned. Returns the new key id.
     */
    public static function rotate_key() {
        $ring = self::prune_keys( self::add_key( self::get_keyring() ) );
        update_option( self::OPTION_KEYS, $ring, false );
        LP_Missing_Logger::info( 'Customer link signing key rotated.', array( 'key_id' => $ring['current'] ) );
        return (string) $ring['current'];
    }

    protected static function add_key( $ring ) {
        $ring['keys'] = isset( $ring['keys'] ) && is_array( $ring['keys'] ) ? $ring['keys'] : array();
        do {
            // Prefixed so a key id is never a numeric array key.
            $kid = 'k' . strtolower( wp_generate_password( 7, false, false ) );
        } while ( isset( $ring['keys'][ $kid ] ) );
        if ( ! empty( $ring['current'] ) && isset( $ring['keys'][ $ring['current'] ] ) ) {
            $ring['keys'][ $ring['current'] ]['retired'] = time();
        }
        $ring['keys'][ $kid ] = array(
            'secret'  => wp_generate_password( 64, true, true ),
            'created' => time(),
            'retired' => 0,
        );
        $ring['current'] = $kid;
        return $ring;
    }

    protected static function prune_keys( $ring ) {
        // A key retired longer ago than the longest link lifetime cannot have a live link or session left.
        $keep_for = self::get_ttl_days() * DAY_IN_SECONDS + DAY_IN_SECONDS;
        foreach ( $ring['keys'] as $kid => $key ) {
            if ( (string) $kid !== (string) $ring['current'] && ! empty( $key['retired'] ) && absint( $key['retired'] ) < time() - $keep_for ) {
                unset( $ring['keys'][ $kid ] );
            }
        }
        return $ring;
    }

    /**
     * HMAC key for a purpose ('link', 'session', 'form'), derived from the key's stored secret and wp_salt( 'auth' ).
     */
    protected static function derive_key( $kid, $purpose ) {
        $ring = self::get_keyring();
        if ( ! is_string( $kid ) || '' === $kid || empty( $ring['keys'][ $kid ]['secret'] ) ) {
            return '';
        }
        return hash_hmac( 'sha256', 'lp-missing|' . $purpose . '|' . $kid, $ring['keys'][ $kid ]['secret'] . wp_salt( 'auth' ) );
    }

    protected static function sign( $kid, $purpose, $data ) {
        $key = self::derive_key( $kid, $purpose );
        if ( '' === $key ) {
            return '';
        }
        return self::base64url( hash_hmac( 'sha256', $data, $key, true ) );
    }

    protected static function check_signature( $kid, $purpose, $data, $signature ) {
        $expected = self::sign( $kid, $purpose, $data );
        return '' !== $expected && is_string( $signature ) && hash_equals( $expected, $signature );
    }

    protected static function base64url( $binary ) {
        return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Links (v2).
    // ---------------------------------------------------------------------------------------------------------------

    public static function get_ttl_days() {
        return max( 1, absint( LP_Missing_Settings::get( 'magic_link_ttl_days' ) ) );
    }

    public static function get_generation( $order ) {
        return absint( $order->get_meta( self::LINK_GENERATION_META, true ) );
    }

    protected static function normalize_email( $email ) {
        return strtolower( trim( (string) $email ) );
    }

    protected static function link_data( $order, $generation, $expires ) {
        return 'link|v2|' . $order->get_id() . '|' . $generation . '|' . $expires . '|' . self::normalize_email( $order->get_billing_email() );
    }

    /**
     * The signed token of a v2 link. $now is only for tests and tools (e.g. to build an already expired link).
     */
    public static function create_link_token( $order, $now = null ) {
        $now        = null === $now ? time() : (int) $now;
        $kid        = self::get_current_key_id();
        $generation = self::get_generation( $order );
        $expires    = $now + self::get_ttl_days() * DAY_IN_SECONDS;
        return implode( '.', array( $kid, $generation, $expires, self::sign( $kid, 'link', self::link_data( $order, $generation, $expires ) ) ) );
    }

    public static function get_portal_base_url() {
        $settings   = LP_Missing_Settings::get_settings();
        $configured = trim( (string) ( isset( $settings['portal_base_url'] ) ? $settings['portal_base_url'] : '' ) );
        $page_id    = absint( $settings['portal_page_id'] );
        $page_url   = $page_id && 'publish' === get_post_status( $page_id ) ? get_permalink( $page_id ) : '';
        $base_url   = $configured ? $configured : ( $page_url ? $page_url : wc_get_page_permalink( 'myaccount' ) );
        if ( ! $base_url ) {
            $base_url = home_url( '/' );
        }
        return apply_filters( 'lp_missing_portal_base_url', $base_url );
    }

    /**
     * Customer link for the portal (v2). Every call issues a fresh link valid for the configured number of days.
     */
    public static function get_magic_link_for_order( $order ) {
        if ( ! $order instanceof WC_Order || ! $order->get_billing_email() ) {
            return '';
        }
        $token = self::create_link_token( $order );
        $parts = explode( '.', $token );
        LP_Missing_Logger::info(
            'Customer link issued.',
            array(
                'order_id'   => $order->get_id(),
                'key_id'     => $parts[0],
                'generation' => (int) $parts[1],
                'expires'    => gmdate( 'c', (int) $parts[2] ),
            )
        );
        return add_query_arg(
            array(
                self::PARAM_ORDER => $order->get_id(),
                self::PARAM_TOKEN => $token,
            ),
            self::get_portal_base_url()
        );
    }

    /**
     * Check a link. Pass the v2 token, or '' and the v1 key.
     *
     * @return array {
     *     @type string $status  valid, invalid, expired or revoked.
     *     @type int    $version 2, 1 or 0 (nothing to check).
     *     @type int    $expires When the link stops working.
     *     @type string $key_id  Signing key (v2).
     * }
     */
    public static function validate_link( $order, $token, $legacy_key = '' ) {
        $result = array(
            'status'  => 'invalid',
            'version' => 0,
            'expires' => 0,
            'key_id'  => '',
        );
        if ( ! $order instanceof WC_Order || ! $order->get_billing_email() ) {
            return $result;
        }

        if ( is_string( $token ) && '' !== $token ) {
            $result['version'] = 2;
            $parts = explode( '.', $token );
            if ( 4 !== count( $parts ) || ! ctype_digit( $parts[1] ) || ! ctype_digit( $parts[2] ) ) {
                return $result;
            }
            list( $kid, $generation, $expires, $signature ) = $parts;
            // The signature is checked first, so a forged token learns nothing about the order.
            if ( ! self::check_signature( $kid, 'link', self::link_data( $order, $generation, $expires ), $signature ) ) {
                return $result;
            }
            $result['key_id']  = $kid;
            $result['expires'] = (int) $expires;
            if ( (int) $generation !== self::get_generation( $order ) ) {
                $result['status'] = 'revoked';
            } elseif ( (int) $expires < time() ) {
                $result['status'] = 'expired';
            } else {
                $result['status'] = 'valid';
            }
            return $result;
        }

        if ( is_string( $legacy_key ) && '' !== $legacy_key ) {
            $result['version'] = 1;
            if ( ! self::validate_signature( $order->get_id(), $order->get_billing_email(), $legacy_key ) ) {
                return $result;
            }
            $result['expires'] = self::get_v1_valid_until();
            if ( self::get_generation( $order ) > 0 ) {
                // v1 links carry no generation: any revocation ends them.
                $result['status'] = 'revoked';
            } elseif ( $result['expires'] < time() ) {
                $result['status'] = 'expired';
            } else {
                $result['status'] = 'valid';
            }
        }

        return $result;
    }

    /**
     * Invalidate every customer link issued for this order so far, and every portal session opened with them
     * (new emails carry new links). Contract used by the admin screen.
     */
    public static function revoke_links( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $generation = self::get_generation( $order ) + 1;
        $order->update_meta_data( self::LINK_GENERATION_META, $generation );
        $order->save();
        LP_Missing_Logger::info( 'Customer links revoked.', array( 'order_id' => $order->get_id(), 'generation' => $generation ) );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Portal sessions (cookie values).
    // ---------------------------------------------------------------------------------------------------------------

    public static function get_session_cookie_name( $order_id ) {
        return self::SESSION_COOKIE_PREFIX . absint( $order_id );
    }

    protected static function session_data( $order, $generation, $expires, $verified, $sid ) {
        return 'session|' . $order->get_id() . '|' . $generation . '|' . $expires . '|' . ( $verified ? '1' : '0' ) . '|' . $sid . '|' . self::normalize_email( $order->get_billing_email() );
    }

    /**
     * Signed cookie value: {key id}.{generation}.{expires}.{verified 0/1}.{session id}.{signature}
     */
    public static function create_session_value( $order, $verified, $expires, $sid ) {
        $kid        = self::get_current_key_id();
        $generation = self::get_generation( $order );
        $expires    = (int) $expires;
        return implode( '.', array( $kid, $generation, $expires, $verified ? '1' : '0', $sid, self::sign( $kid, 'session', self::session_data( $order, $generation, $expires, $verified, $sid ) ) ) );
    }

    /**
     * @return array { status: valid|invalid|expired|revoked, verified: bool, expires: int, sid: string }
     */
    public static function parse_session_value( $order, $value ) {
        $result = array(
            'status'   => 'invalid',
            'verified' => false,
            'expires'  => 0,
            'sid'      => '',
        );
        $parts = is_string( $value ) ? explode( '.', $value ) : array();
        if ( 6 !== count( $parts ) || ! ctype_digit( $parts[1] ) || ! ctype_digit( $parts[2] ) || ! in_array( $parts[3], array( '0', '1' ), true ) || ! preg_match( '/^[A-Za-z0-9]{16,64}$/', $parts[4] ) ) {
            return $result;
        }
        list( $kid, $generation, $expires, $verified, $sid, $signature ) = $parts;
        if ( ! $order instanceof WC_Order || ! self::check_signature( $kid, 'session', self::session_data( $order, $generation, $expires, '1' === $verified, $sid ), $signature ) ) {
            return $result;
        }
        if ( (int) $generation !== self::get_generation( $order ) ) {
            $result['status'] = 'revoked';
        } elseif ( (int) $expires < time() ) {
            $result['status'] = 'expired';
        } else {
            $result = array(
                'status'   => 'valid',
                'verified' => '1' === $verified,
                'expires'  => (int) $expires,
                'sid'      => $sid,
            );
        }
        return $result;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Portal form tokens (CSRF). Bound to the visitor's portal session (or login session), so they work for guests.
    // ---------------------------------------------------------------------------------------------------------------

    public static function create_form_token( $order_id, $binding ) {
        $kid = self::get_current_key_id();
        return $kid . '.' . self::sign( $kid, 'form', 'form|' . absint( $order_id ) . '|' . $binding );
    }

    public static function verify_form_token( $order_id, $binding, $token ) {
        if ( '' === (string) $binding || ! is_string( $token ) ) {
            return false;
        }
        $parts = explode( '.', $token, 2 );
        return 2 === count( $parts ) && self::check_signature( $parts[0], 'form', 'form|' . absint( $order_id ) . '|' . $binding, $parts[1] );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Staff preview.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * URL for staff to open the portal of an order as the customer sees it (read-only preview).
     * Contract used by the admin screen; the portal validates the nonce and the capability.
     */
    public static function get_staff_preview_url( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }
        return add_query_arg(
            array(
                self::PARAM_ORDER   => $order->get_id(),
                self::PARAM_PREVIEW => wp_create_nonce( 'lp_missing_preview_' . $order->get_id() ),
            ),
            self::get_portal_base_url()
        );
    }

    public static function verify_staff_preview( $order_id, $nonce ) {
        return $order_id && is_string( $nonce ) && '' !== $nonce && wp_verify_nonce( $nonce, 'lp_missing_preview_' . absint( $order_id ) ) && current_user_can( 'edit_shop_orders' );
    }
}
