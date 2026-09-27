<?php
// Customer portal request simulation for the integration tests: runs the template_redirect handler and the shortcode
// like one front-end request, with a cookie jar, captured redirects (URL + status), cookies and private headers.
// Require after bootstrap.php.
defined( 'ABSPATH' ) || exit; // Runs inside WordPress (wp eval-file), never over HTTP.

$GLOBALS['lp_jar']             = array();
$GLOBALS['lp_cookie_log']      = array();
$GLOBALS['lp_private_headers'] = array();
$GLOBALS['lp_redirect_status'] = 0;

add_action(
	'lp_missing_portal_set_cookie',
	function ( $name, $value, $expires, $params ) {
		$GLOBALS['lp_cookie_log'][] = compact( 'name', 'value', 'expires', 'params' );
		if ( '' === $value || $expires < time() ) {
			unset( $GLOBALS['lp_jar'][ $name ] );
		} else {
			$GLOBALS['lp_jar'][ $name ] = $value;
		}
	},
	10,
	4
);
add_action(
	'lp_missing_portal_private_headers',
	function ( $headers ) {
		$GLOBALS['lp_private_headers'] = $headers;
	}
);
// Runs before the bootstrap's wp_redirect filter (priority 1), which throws LP_Redirect.
add_filter(
	'wp_redirect',
	function ( $location, $status ) {
		$GLOBALS['lp_redirect_status'] = $status;
		return $location;
	},
	0,
	2
);

/**
 * One portal request. Returns array( 'redirect' => URL or '', 'status' => redirect status, 'html' => shortcode output ).
 */
function portal_http( $url, $post = array(), $user = 0, $atts = array() ) {
	reset_request();
	wp_set_current_user( $user );
	$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
	parse_str( $query, $get );
	$_GET                           = wp_slash( $get );
	$_POST                          = wp_slash( $post );
	$_REQUEST                       = array_merge( $_GET, $_POST );
	$_COOKIE                        = wp_slash( $GLOBALS['lp_jar'] );
	$_SERVER['REQUEST_METHOD']      = $post ? 'POST' : 'GET';
	$GLOBALS['lp_private_headers']  = array();
	$GLOBALS['lp_redirect_status']  = 0;
	LP_Missing_Portal::reset_request_state();
	$res = array( 'redirect' => '', 'status' => 0, 'html' => '' );
	try {
		LP_Missing_Portal::handle_request( true );
	} catch ( LP_Redirect $r ) {
		$res['redirect'] = $r->getMessage();
		$res['status']   = $GLOBALS['lp_redirect_status'];
		return $res;
	}
	$res['html'] = LP_Missing_Portal::render_shortcode( $atts );
	return $res;
}

/**
 * Follow redirects (max 3) like a browser.
 */
function portal_follow( $res, $user = 0 ) {
	for ( $i = 0; $i < 3 && $res['redirect']; $i++ ) {
		$res = portal_http( $res['redirect'], array(), $user );
	}
	return $res;
}

function portal_jar_reset() {
	$GLOBALS['lp_jar'] = array();
}

/**
 * Open the customer link of an order and confirm the email: returns the portal page response.
 */
function portal_login( $order, $email = null ) {
	$res = portal_follow( portal_http( call( 'get_magic_link_for_order', $order ) ) );
	$token = extract_field( $res['html'], 'lp_missing_token' );
	if ( false === strpos( $res['html'], 'lp_missing_verify_email' ) ) {
		return $res;
	}
	return portal_follow(
		portal_http(
			portal_clean_url( $order ),
			array(
				'lp_oid'                   => $order->get_id(),
				'lp_missing_portal_action' => 'verify',
				'lp_missing_token'         => $token,
				'lp_missing_verify_email'  => null === $email ? $order->get_billing_email() : $email,
			)
		)
	);
}

function portal_clean_url( $order ) {
	return add_query_arg( 'oid', $order->get_id(), LP_Missing_Magic_Link::get_portal_base_url() );
}

/**
 * Submit the portal form: $choices = item_id => 'alt:ID' | 'decline' | 'delete', $qtys = item_id => qty.
 */
function portal_save( $order, $choices, $qtys = array(), $token = null, $user = 0 ) {
	if ( null === $token ) {
		$page  = portal_http( portal_clean_url( $order ), array(), $user );
		$token = extract_field( $page['html'], 'lp_missing_token' );
	}
	return portal_http(
		portal_clean_url( $order ),
		array(
			'lp_oid'                   => $order->get_id(),
			'lp_missing_portal_action' => 'save',
			'lp_missing_token'         => $token,
			'lp_choice'                => $choices,
			'lp_qty'                   => $qtys,
		),
		$user
	);
}
