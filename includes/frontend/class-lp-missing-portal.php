<?php
/**
 * Customer portal: request handling (template_redirect: link exchange, email confirmation, decisions with
 * post/redirect/get, private headers) and rendering (the [lp_missing_items] shortcode).
 *
 * @package LP_Missing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Missing_Portal {
    const ACTION_FIELD  = 'lp_missing_portal_action';
    const TOKEN_FIELD   = 'lp_missing_token';
    const ORDER_FIELD   = 'lp_oid';
    const MESSAGE_PARAM = 'lp_msg';
    const ASSET_HANDLE  = 'lp-missing-portal';

    /** @var array|null Context of the handled request. */
    protected static $context = null;
    /** @var string|null Request the context belongs to. */
    protected static $context_key = null;
    /** @var bool */
    protected static $private_headers_sent = false;

    public static function register() {
        add_shortcode( LP_Missing_Plugin::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
        add_filter( 'the_content', array( __CLASS__, 'maybe_inject_portal' ), 1 );
        add_action( 'template_redirect', array( __CLASS__, 'on_template_redirect' ), 1 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'on_enqueue_scripts' ) );
        LP_Missing_Portal_Setup::register();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Request handling.
    // ---------------------------------------------------------------------------------------------------------------

    public static function get_requested_order_id() {
        if ( isset( $_GET[ LP_Missing_Magic_Link::PARAM_ORDER ] ) && is_scalar( $_GET[ LP_Missing_Magic_Link::PARAM_ORDER ] ) ) {
            return absint( $_GET[ LP_Missing_Magic_Link::PARAM_ORDER ] );
        }
        if ( isset( $_POST[ self::ORDER_FIELD ], $_POST[ self::ACTION_FIELD ] ) && is_scalar( $_POST[ self::ORDER_FIELD ] ) ) {
            return absint( $_POST[ self::ORDER_FIELD ] );
        }
        return 0;
    }

    /**
     * Whether this front-end request is for the portal: our parameters, our cookie or a portal page with an order.
     */
    public static function is_portal_request() {
        $order_id = self::get_requested_order_id();
        if ( ! $order_id ) {
            return false;
        }
        if ( LP_Missing_Portal_Access::has_link_params() || isset( $_GET[ LP_Missing_Magic_Link::PARAM_PREVIEW ] ) || isset( $_GET[ self::MESSAGE_PARAM ] ) || isset( $_POST[ self::ACTION_FIELD ] ) ) {
            return true;
        }
        if ( isset( $_COOKIE[ LP_Missing_Magic_Link::get_session_cookie_name( $order_id ) ] ) ) {
            return true;
        }
        return self::is_portal_page();
    }

    /**
     * The configured portal page, a page carrying the shortcode, or My Account opened with an order in the URL.
     */
    public static function is_portal_page() {
        if ( is_admin() || ! did_action( 'wp' ) ) {
            return false;
        }
        $page_id = absint( LP_Missing_Settings::get( 'portal_page_id' ) );
        if ( $page_id && is_page( $page_id ) ) {
            return true;
        }
        if ( ! empty( $_GET[ LP_Missing_Magic_Link::PARAM_ORDER ] ) && function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'myaccount' ) > 0 && is_page( wc_get_page_id( 'myaccount' ) ) ) {
            return true;
        }
        $post = get_queried_object();
        return $post instanceof WP_Post && is_singular() && has_shortcode( $post->post_content, LP_Missing_Plugin::SHORTCODE );
    }

    public static function on_template_redirect() {
        if ( self::is_portal_request() ) {
            self::handle_request( true );
        }
    }

    /**
     * Resolve access and process the portal forms for the order in the request. With $allow_redirect (the
     * template_redirect run) a used link is swapped for the session cookie and a clean URL, and a successful POST
     * redirects (post/redirect/get). Returns the context, or null when the request names no order.
     */
    public static function handle_request( $allow_redirect = false ) {
        self::$context     = null;
        self::$context_key = self::request_key();

        $order_id = self::get_requested_order_id();
        if ( ! $order_id ) {
            return null;
        }
        self::send_private_headers();

        $ctx = LP_Missing_Portal_Access::resolve( $order_id );
        if ( 'customer' === $ctx['mode'] && 'link' === $ctx['source'] ) {
            LP_Missing_Portal_Access::start_session( $ctx );
        }

        if ( ! $ctx['error'] && 'customer' === $ctx['mode'] && isset( $_POST[ self::ACTION_FIELD ] ) && is_string( $_POST[ self::ACTION_FIELD ] ) ) {
            $action = sanitize_key( wp_unslash( $_POST[ self::ACTION_FIELD ] ) );
            if ( 'verify' === $action ) {
                $ctx['result'] = LP_Missing_Portal_Access::handle_verification( $ctx );
            } elseif ( 'save' === $action ) {
                $ctx['result'] = $ctx['verified'] ? LP_Missing_Portal_Decisions::handle( $ctx ) : self::error_result( 'not_verified' );
            }
        }

        self::$context = $ctx;

        if ( $allow_redirect && ! $ctx['error'] ) {
            if ( $ctx['result'] && 'success' === $ctx['result']['status'] ) {
                self::redirect( add_query_arg( self::MESSAGE_PARAM, $ctx['result']['code'], self::get_clean_url( $order_id ) ), 303 );
            } elseif ( $ctx['redirect'] && ! $ctx['result'] ) {
                self::redirect( self::get_clean_url( $order_id, true ), 302 );
            }
        }
        return $ctx;
    }

    /**
     * The handled context of this request, handling it now when template_redirect did not (e.g. a shortcode rendered
     * outside a normal page view). Never redirects.
     */
    public static function get_context() {
        if ( self::$context_key === self::request_key() ) {
            return self::$context;
        }
        return self::handle_request( false );
    }

    /**
     * Forget the handled request (for tests and tools that run several requests in one process).
     */
    public static function reset_request_state() {
        self::$context              = null;
        self::$context_key          = null;
        self::$private_headers_sent = false;
    }

    protected static function request_key() {
        return md5( wp_json_encode( array( $_GET, $_POST, get_current_user_id() ) ) );
    }

    public static function error_result( $code, $extra = array() ) {
        return array_merge(
            array(
                'status'  => 'error',
                'code'    => $code,
                'message' => self::get_error_message( $code ),
            ),
            $extra
        );
    }

    public static function get_error_message( $code ) {
        $messages = array(
            'invalid'         => __( 'Lenken er ugyldig. Bruk lenken i den nyeste e-posten fra oss, eller kontakt oss.', 'lp-missing' ),
            'expired'         => __( 'Lenken er utløpt. Bruk lenken i den nyeste e-posten fra oss, eller kontakt oss, så sender vi en ny.', 'lp-missing' ),
            'revoked'         => __( 'Denne lenken er ikke lenger i bruk. Bruk lenken i den nyeste e-posten fra oss.', 'lp-missing' ),
            'no_session'      => __( 'Åpne lenken i e-posten fra oss for å se varene som mangler. Siden bruker en informasjonskapsel (cookie) for å huske deg, så den må være tillatt i nettleseren.', 'lp-missing' ),
            'no_access'       => __( 'Fant ikke ordren, eller e-postadressen stemmer ikke.', 'lp-missing' ),
            'preview'         => __( 'This preview link is invalid or has expired. Open the preview again from the order screen.', 'lp-missing' ),
            'csrf'            => __( 'Sikkerhetssjekken feilet. Last inn siden på nytt og prøv igjen.', 'lp-missing' ),
            'busy'            => __( 'Vi oppdaterer ordren din akkurat nå. Prøv igjen om et øyeblikk.', 'lp-missing' ),
            'rate'            => __( 'Du har gjort mange endringer på kort tid. Vent litt og prøv igjen.', 'lp-missing' ),
            'lines'           => __( 'Noen av valgene må rettes før vi kan lagre. Se merknadene under.', 'lp-missing' ),
            'verify_mismatch' => __( 'E-postadressen stemmer ikke med denne ordren.', 'lp-missing' ),
            'verify_rate'     => __( 'For mange forsøk. Vent litt og prøv igjen.', 'lp-missing' ),
            'not_verified'    => __( 'Bekreft e-postadressen din først.', 'lp-missing' ),
        );
        return isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['invalid'];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // URLs, redirects and headers.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * The URL of this request (host from home_url(), never from the Host header).
     */
    public static function get_current_url() {
        if ( empty( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
            return '';
        }
        $home = wp_parse_url( home_url() );
        if ( empty( $home['host'] ) ) {
            return '';
        }
        $scheme = is_ssl() ? 'https' : ( isset( $home['scheme'] ) ? $home['scheme'] : 'http' );
        return $scheme . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . wp_unslash( $_SERVER['REQUEST_URI'] );
    }

    /**
     * This page without the link key, preview nonce or message. $canonical prefers the configured portal URL
     * (links from older emails may point at another page).
     */
    public static function get_clean_url( $order_id = 0, $canonical = false ) {
        $strip   = array( LP_Missing_Magic_Link::PARAM_TOKEN, LP_Missing_Magic_Link::PARAM_LEGACY, LP_Missing_Magic_Link::PARAM_PREVIEW, self::MESSAGE_PARAM );
        $current = self::get_current_url();
        if ( $canonical || '' === $current ) {
            $base = remove_query_arg( $strip, LP_Missing_Magic_Link::get_portal_base_url() );
            if ( wp_validate_redirect( $base, '' ) ) {
                return $order_id ? add_query_arg( LP_Missing_Magic_Link::PARAM_ORDER, absint( $order_id ), $base ) : $base;
            }
        }
        $url = remove_query_arg( $strip, $current );
        return $order_id && isset( $_GET[ LP_Missing_Magic_Link::PARAM_ORDER ] ) ? add_query_arg( LP_Missing_Magic_Link::PARAM_ORDER, absint( $order_id ), $url ) : $url;
    }

    protected static function redirect( $url, $status ) {
        wp_safe_redirect( $url, $status, 'LP Missing portal' );
        exit;
    }

    /**
     * Portal pages carry personal data and a link key: never cache, index or leak them in a Referer.
     */
    public static function send_private_headers() {
        if ( self::$private_headers_sent ) {
            return;
        }
        self::$private_headers_sent = true;
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        add_filter( 'wp_robots', array( __CLASS__, 'filter_robots' ), 99 );
        $headers = array(
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag'    => 'noindex, nofollow',
        );
        /**
         * Fires when the portal sends its private headers (in addition to nocache_headers()).
         *
         * @param array $headers Header name => value.
         */
        do_action( 'lp_missing_portal_private_headers', $headers );
        if ( headers_sent() ) {
            return;
        }
        nocache_headers();
        foreach ( $headers as $name => $value ) {
            header( $name . ': ' . $value );
        }
    }

    public static function filter_robots( $robots ) {
        $robots['noindex']   = true;
        $robots['nofollow']  = true;
        $robots['noarchive'] = true;
        unset( $robots['follow'], $robots['max-image-preview'] );
        return $robots;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Assets.
    // ---------------------------------------------------------------------------------------------------------------

    public static function on_enqueue_scripts() {
        self::register_assets();
        if ( self::is_portal_page() || self::is_portal_request() ) {
            self::enqueue_assets();
        }
    }

    protected static function asset_version( $relative ) {
        $file = LP_MISSING_DIR . $relative;
        return LP_Missing_Plugin::VERSION . ( is_readable( $file ) ? '.' . filemtime( $file ) : '' );
    }

    public static function register_assets() {
        if ( wp_style_is( self::ASSET_HANDLE, 'registered' ) ) {
            return;
        }
        wp_register_style( self::ASSET_HANDLE, plugins_url( 'assets/css/portal.css', LP_MISSING_FILE ), array(), self::asset_version( 'assets/css/portal.css' ) );
        wp_register_script(
            self::ASSET_HANDLE,
            plugins_url( 'assets/js/portal.js', LP_MISSING_FILE ),
            array(),
            self::asset_version( 'assets/js/portal.js' ),
            array(
                'in_footer' => true,
                'strategy'  => 'defer',
            )
        );
    }

    public static function enqueue_assets() {
        self::register_assets();
        wp_enqueue_style( self::ASSET_HANDLE );
        wp_enqueue_script( self::ASSET_HANDLE );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Rendering.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * The default portal URL is the My Account page, which does not carry the shortcode by itself:
     * show the portal there when a portal URL (link, session or preview) is opened.
     */
    public static function maybe_inject_portal( $content ) {
        if ( is_admin() || empty( $_GET[ LP_Missing_Magic_Link::PARAM_ORDER ] ) || ! is_main_query() || ! in_the_loop() ) {
            return $content;
        }
        if ( has_shortcode( $content, LP_Missing_Plugin::SHORTCODE ) || ! function_exists( 'wc_get_page_id' ) || ! is_page( wc_get_page_id( 'myaccount' ) ) ) {
            return $content;
        }
        return '[' . LP_Missing_Plugin::SHORTCODE . ']' . "\n\n" . $content;
    }

    /**
     * Shortcode attributes (order_id + email) give access to staff (read-only preview) and to the order's own
     * logged-in customer only.
     */
    public static function get_order_for_shortcode( $atts ) {
        $order_id = isset( $atts['order_id'] ) ? absint( $atts['order_id'] ) : 0;
        $email    = isset( $atts['email'] ) ? sanitize_email( $atts['email'] ) : '';
        if ( ! $order_id || ! $email ) {
            return array( null, '' );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return array( null, '' );
        }
        if ( strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
            return array( null, '' );
        }
        if ( ! LP_Missing_Portal_Access::is_order_owner( $order ) && ! current_user_can( 'edit_shop_orders' ) ) {
            return array( null, '' );
        }
        return array( $order, $email );
    }

    protected static function context_from_attributes( $atts ) {
        if ( empty( $atts['order_id'] ) && empty( $atts['email'] ) ) {
            return null;
        }
        list( $order ) = self::get_order_for_shortcode( $atts );
        $ctx = LP_Missing_Portal_Access::empty_context( $order ? $order->get_id() : 0 );
        if ( ! $order ) {
            $ctx['error'] = 'no_access';
            return $ctx;
        }
        $ctx['order']    = $order;
        $ctx['verified'] = true;
        if ( LP_Missing_Portal_Access::is_order_owner( $order ) ) {
            $ctx['mode']   = 'customer';
            $ctx['source'] = 'attributes';
        } else {
            $ctx['mode']   = 'preview';
            $ctx['source'] = 'preview';
        }
        return $ctx;
    }

    public static function render_shortcode( $atts = array() ) {
        $atts = shortcode_atts(
            array(
                'order_id' => 0,
                'email'    => '',
            ),
            $atts,
            LP_Missing_Plugin::SHORTCODE
        );

        $ctx = self::get_context();
        if ( ! $ctx ) {
            $ctx = self::context_from_attributes( $atts );
        }
        self::enqueue_assets();

        if ( ! $ctx ) {
            return LP_Missing_Portal_View::render_landing();
        }
        if ( $ctx['error'] ) {
            return LP_Missing_Portal_View::render_message( 'error', self::get_error_message( $ctx['error'] ) );
        }
        if ( 'customer' === $ctx['mode'] && ! $ctx['verified'] ) {
            return LP_Missing_Portal_View::render_verify( $ctx );
        }
        return LP_Missing_Portal_View::render_portal( $ctx );
    }
}
