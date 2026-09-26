<?php
/**
 * Who may see which order in the portal: magic links, the signed session cookie they are exchanged for,
 * the logged-in order owner, staff previews and the one-time billing email confirmation.
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Portal_Access {
    const SESSION_TTL = 172800; // 2 days; never longer than the link that opened the session.

    const VERIFY_MAX_FAILURES       = 10; // Per browser session.
    const VERIFY_ORDER_MAX_FAILURES = 30; // Per order, across sessions.
    const VERIFY_WINDOW             = 900;

    /**
     * An empty request context. Keys:
     * order_id, order (WC_Order|null), mode ('' | customer | preview), source (link | session | owner | attributes | preview),
     * verified (bool), session (array|null), link (array|null), error ('' | invalid | expired | revoked | no_session | preview | no_access),
     * redirect (bool: drop the link from the URL), result (array|null: outcome of a POST),
     * closed (bool: the order is cancelled, refunded, failed or trashed; nothing can be chosen).
     */
    public static function empty_context( $order_id = 0 ) {
        return array(
            'order_id' => absint( $order_id ),
            'order'    => null,
            'mode'     => '',
            'source'   => '',
            'verified' => false,
            'session'  => null,
            'link'     => null,
            'error'    => '',
            'redirect' => false,
            'result'   => null,
            'closed'   => false,
        );
    }

    public static function is_order_owner( $order ) {
        $user_id = get_current_user_id();
        // Account emails can be changed without confirmation, so ownership is the order's customer ID only.
        return $user_id && $order instanceof WC_Order && (int) $order->get_customer_id() === (int) $user_id;
    }

    protected static function get_query_string( $name, $max_length = 256 ) {
        if ( ! isset( $_GET[ $name ] ) || ! is_string( $_GET[ $name ] ) ) {
            return '';
        }
        $value = sanitize_text_field( wp_unslash( $_GET[ $name ] ) );
        return strlen( $value ) > $max_length ? '' : $value;
    }

    public static function has_link_params() {
        return '' !== self::get_query_string( LP_Missing_Magic_Link::PARAM_TOKEN ) || '' !== self::get_query_string( LP_Missing_Magic_Link::PARAM_LEGACY );
    }

    /**
     * Resolve access for the order in the request URL (or posted by a portal form).
     */
    public static function resolve( $order_id ) {
        $ctx   = self::empty_context( $order_id );
        $order = $order_id ? wc_get_order( $order_id ) : false;
        // wc_get_order() also returns refunds, which have no billing email.
        if ( ! $order instanceof WC_Order ) {
            if ( self::has_link_params() ) {
                LP_Missing_Logger::throttled_warning( 'link_invalid', 'Customer link invalid.', array( 'order_id' => absint( $order_id ), 'reason' => 'unknown_order' ) );
            }
            // Same answers as for an existing order, so the portal does not reveal which order numbers exist.
            if ( '' !== self::get_query_string( LP_Missing_Magic_Link::PARAM_PREVIEW ) ) {
                $ctx['error'] = 'preview';
            } else {
                $ctx['error'] = self::has_link_params() ? 'invalid' : 'no_session';
            }
            return $ctx;
        }
        $ctx['order'] = $order;

        $preview_nonce = self::get_query_string( LP_Missing_Magic_Link::PARAM_PREVIEW );
        if ( '' !== $preview_nonce ) {
            if ( LP_Missing_Magic_Link::verify_staff_preview( $order->get_id(), $preview_nonce ) ) {
                $ctx['mode']     = 'preview';
                $ctx['source']   = 'preview';
                $ctx['verified'] = true;
                LP_Missing_Logger::info( 'Staff opened the customer portal preview.', array( 'order_id' => $order->get_id(), 'user_id' => get_current_user_id() ) );
            } else {
                $ctx['error'] = 'preview';
                LP_Missing_Logger::throttled_warning( 'preview_refused', 'Customer portal preview refused (invalid nonce or missing capability).', array( 'order_id' => $order->get_id(), 'user_id' => get_current_user_id() ) );
            }
            return $ctx;
        }

        $is_owner = self::is_order_owner( $order );
        $session  = self::read_session( $order );
        $token    = self::get_query_string( LP_Missing_Magic_Link::PARAM_TOKEN );
        $legacy   = '' === $token ? self::get_query_string( LP_Missing_Magic_Link::PARAM_LEGACY ) : '';

        if ( '' !== $token || '' !== $legacy ) {
            $check = LP_Missing_Magic_Link::validate_link( $order, $token, $legacy );
            self::log_link_check( $order, $check );
            if ( 'valid' === $check['status'] ) {
                $ctx['mode']     = 'customer';
                $ctx['source']   = 'link';
                $ctx['link']     = $check;
                $ctx['session']  = $session;
                $ctx['verified'] = $is_owner || ( $session && $session['verified'] );
                $ctx['redirect'] = true;
                return $ctx;
            }
            if ( $session || $is_owner ) {
                // An old link, but this browser already has access: continue on the clean URL.
                $ctx['mode']     = 'customer';
                $ctx['source']   = $session ? 'session' : 'owner';
                $ctx['session']  = $session;
                $ctx['verified'] = $is_owner || $session['verified'];
                $ctx['redirect'] = true;
                return $ctx;
            }
            $ctx['error'] = $check['status'];
            return $ctx;
        }

        if ( $session ) {
            $ctx['mode']     = 'customer';
            $ctx['source']   = 'session';
            $ctx['session']  = $session;
            $ctx['verified'] = $is_owner || $session['verified'];
        } elseif ( $is_owner ) {
            $ctx['mode']     = 'customer';
            $ctx['source']   = 'owner';
            $ctx['verified'] = true;
        } else {
            $ctx['error'] = 'no_session';
        }
        return $ctx;
    }

    protected static function log_link_check( $order, $check ) {
        $context = array(
            'order_id' => $order->get_id(),
            'version'  => $check['version'],
        );
        switch ( $check['status'] ) {
            case 'valid':
                if ( 1 === $check['version'] ) {
                    LP_Missing_Logger::info( 'Legacy (v1) customer link accepted during the transition period.', $context );
                }
                break;
            case 'expired':
                LP_Missing_Logger::info( 'Expired customer link used.', $context );
                break;
            case 'revoked':
                LP_Missing_Logger::info( 'Revoked customer link used.', $context );
                break;
            default:
                LP_Missing_Logger::throttled_warning( 'link_invalid', 'Customer link invalid.', array_merge( $context, array( 'reason' => 'signature' ) ) );
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Session cookie.
    // ---------------------------------------------------------------------------------------------------------------

    public static function get_session_ttl() {
        return max( 300, absint( apply_filters( 'lp_missing_portal_session_ttl', self::SESSION_TTL ) ) );
    }

    /**
     * The valid session of this browser for the order, or null. An unusable cookie is removed.
     */
    public static function read_session( $order ) {
        $name = LP_Missing_Magic_Link::get_session_cookie_name( $order->get_id() );
        if ( empty( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
            return null;
        }
        $session = LP_Missing_Magic_Link::parse_session_value( $order, wp_unslash( $_COOKIE[ $name ] ) );
        if ( 'valid' === $session['status'] ) {
            return $session;
        }
        LP_Missing_Logger::info( 'Customer portal session rejected.', array( 'order_id' => $order->get_id(), 'reason' => $session['status'] ) );
        self::set_cookie( $name, '', time() - YEAR_IN_SECONDS );
        return null;
    }

    /**
     * Exchange the valid link in $ctx for a session cookie (keeping an earlier email confirmation of this browser).
     */
    public static function start_session( &$ctx ) {
        $order    = $ctx['order'];
        $existing = $ctx['session'];
        $expires  = min( (int) $ctx['link']['expires'], time() + self::get_session_ttl() );
        if ( $existing && $existing['expires'] > $expires ) {
            $expires = $existing['expires'];
        }
        $sid = $existing ? $existing['sid'] : wp_generate_password( 32, false, false );
        self::write_session( $order, $ctx['verified'], $expires, $sid );
        $ctx['session'] = array(
            'status'   => 'valid',
            'verified' => (bool) $ctx['verified'],
            'expires'  => $expires,
            'sid'      => $sid,
        );
        LP_Missing_Logger::info(
            'Customer portal session started from a link.',
            array(
                'order_id'     => $order->get_id(),
                'link_version' => $ctx['link']['version'],
                'verified'     => (bool) $ctx['verified'],
                'expires'      => gmdate( 'c', $expires ),
            )
        );
    }

    public static function write_session( $order, $verified, $expires, $sid ) {
        self::set_cookie(
            LP_Missing_Magic_Link::get_session_cookie_name( $order->get_id() ),
            LP_Missing_Magic_Link::create_session_value( $order, $verified, $expires, $sid ),
            $expires
        );
    }

    /**
     * Set (or, with an empty value, delete) an HttpOnly, SameSite=Lax cookie, Secure on HTTPS.
     * The value is also put in $_COOKIE so the rest of this request sees it.
     */
    public static function set_cookie( $name, $value, $expires ) {
        $params = array(
            'expires'  => (int) $expires,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );
        if ( '' === $value ) {
            unset( $_COOKIE[ $name ] );
        } else {
            $_COOKIE[ $name ] = $value;
        }
        /**
         * Fires when the portal sets or deletes a cookie.
         *
         * @param string $name    Cookie name.
         * @param string $value   Value ('' deletes).
         * @param int    $expires Expiry timestamp.
         * @param array  $params  setcookie() options.
         */
        do_action( 'lp_missing_portal_set_cookie', $name, $value, (int) $expires, $params );
        if ( headers_sent() ) {
            return false;
        }
        return setcookie( $name, $value, $params );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Form tokens and the email confirmation.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * What the portal's form tokens are bound to: the browser's portal session, else the login session.
     */
    public static function get_form_binding( $ctx ) {
        if ( ! empty( $ctx['session']['sid'] ) ) {
            return 's:' . $ctx['session']['sid'];
        }
        if ( in_array( $ctx['source'], array( 'owner', 'attributes' ), true ) && get_current_user_id() ) {
            return 'u:' . get_current_user_id() . ':' . wp_get_session_token();
        }
        return '';
    }

    public static function get_form_token( $ctx ) {
        $binding = self::get_form_binding( $ctx );
        return '' === $binding ? '' : LP_Missing_Magic_Link::create_form_token( $ctx['order_id'], $binding );
    }

    public static function check_form_token( $ctx ) {
        $token = isset( $_POST[ LP_Missing_Portal::TOKEN_FIELD ] ) && is_string( $_POST[ LP_Missing_Portal::TOKEN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ LP_Missing_Portal::TOKEN_FIELD ] ) ) : '';
        return LP_Missing_Magic_Link::verify_form_token( $ctx['order_id'], self::get_form_binding( $ctx ), $token );
    }

    /**
     * Handle the "confirm your billing email" form. On success the session cookie is marked as confirmed.
     */
    public static function handle_verification( &$ctx ) {
        $order = $ctx['order'];
        if ( $ctx['verified'] ) {
            return array( 'status' => 'success', 'code' => 'verified' );
        }
        if ( ! self::check_form_token( $ctx ) ) {
            return LP_Missing_Portal::error_result( 'csrf' );
        }
        // Failures are counted per browser session (so someone else holding the link cannot lock the customer out
        // with a few guesses) and, with a higher cap, per order. Attempts on one order are serialised so parallel
        // requests cannot get past the counters.
        $lock = 'lp_missing_verifying_' . $order->get_id();
        if ( ! LP_Missing_Util::acquire_lock( $lock, 30 ) ) {
            return LP_Missing_Portal::error_result( 'busy' );
        }
        try {
            $sid         = ! empty( $ctx['session']['sid'] ) ? (string) $ctx['session']['sid'] : 'none';
            $session_key = 'lp_missing_portal_vf_' . $order->get_id() . '_' . substr( md5( $sid ), 0, 12 );
            $order_key   = 'lp_missing_portal_vfo_' . $order->get_id();
            if ( ! LP_Missing_Portal_Decisions::window_allows( $session_key, self::VERIFY_MAX_FAILURES ) || ! LP_Missing_Portal_Decisions::window_allows( $order_key, self::VERIFY_ORDER_MAX_FAILURES ) ) {
                return LP_Missing_Portal::error_result( 'verify_rate' );
            }
            $submitted = isset( $_POST['lp_missing_verify_email'] ) && is_string( $_POST['lp_missing_verify_email'] ) ? sanitize_email( wp_unslash( $_POST['lp_missing_verify_email'] ) ) : '';
            $billing   = strtolower( trim( (string) $order->get_billing_email() ) );
            if ( '' === $submitted || '' === $billing || strtolower( trim( $submitted ) ) !== $billing ) {
                $session_count = LP_Missing_Portal_Decisions::window_hit( $session_key, self::VERIFY_WINDOW );
                $order_count   = LP_Missing_Portal_Decisions::window_hit( $order_key, self::VERIFY_WINDOW );
                // The submitted address is personal data: it is not logged. Blocking is logged once per window.
                LP_Missing_Logger::warning( 'Customer email confirmation failed.', array( 'order_id' => $order->get_id() ) );
                if ( self::VERIFY_MAX_FAILURES === $session_count || self::VERIFY_ORDER_MAX_FAILURES === $order_count ) {
                    LP_Missing_Logger::warning( 'Customer email confirmation blocked after too many attempts.', array( 'order_id' => $order->get_id(), 'scope' => self::VERIFY_ORDER_MAX_FAILURES === $order_count ? 'order' : 'session' ) );
                }
                return LP_Missing_Portal::error_result( 'verify_mismatch' );
            }
        } finally {
            LP_Missing_Util::release_lock( $lock );
        }

        $ctx['verified'] = true;
        if ( $ctx['session'] ) {
            self::write_session( $order, true, $ctx['session']['expires'], $ctx['session']['sid'] );
            $ctx['session']['verified'] = true;
        }
        LP_Missing_Logger::info( 'Customer confirmed the billing email in the portal.', array( 'order_id' => $order->get_id() ) );
        return array( 'status' => 'success', 'code' => 'verified' );
    }
}
