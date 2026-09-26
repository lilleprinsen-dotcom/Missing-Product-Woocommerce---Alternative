<?php
// Shared helpers and store setup for the integration tests. Test files start with: require __DIR__ . '/bootstrap.php';
// Uses the real WordPress + WooCommerce runtime (SQLite).

$GLOBALS['lp_fail'] = 0;
$GLOBALS['lp_pass'] = 0;
$GLOBALS['lp_mails'] = array();

function t_ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['lp_pass']++;
		echo "  PASS  $msg\n";
	} else {
		$GLOBALS['lp_fail']++;
		echo "  FAIL  $msg\n";
	}
}
function t_eq( $expected, $actual, $msg ) {
	$ok = is_float( $expected ) || is_float( $actual ) ? abs( (float) $expected - (float) $actual ) < 0.011 : $expected === $actual;
	t_ok( $ok, $msg . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}
function call( $method, ...$args ) {
	static $classes = array( 'LP_Missing_Util', 'LP_Missing_Settings', 'LP_Missing_Line', 'LP_Missing_Orders', 'LP_Missing_Magic_Link', 'LP_Missing_Pricing', 'LP_Missing_Stock', 'LP_Missing_Apply_Service', 'LP_Missing_Lifecycle', 'LP_Missing_Notifier', 'LP_Missing_Scheduler', 'LP_Missing_Deadline', 'LP_Missing_Admin_Metabox', 'LP_Missing_Admin_Actions', 'LP_Missing_Admin_Settings_Page', 'LP_Missing_Admin_Orders_List', 'LP_Missing_Portal' );
	foreach ( $classes as $class ) {
		if ( class_exists( $class ) && method_exists( $class, $method ) ) {
			return call_user_func_array( array( $class, $method ), $args );
		}
	}
	throw new Exception( "No plugin method $method" );
}
function item_data( $order_id, $item_id ) {
	$order = wc_get_order( $order_id );
	$item  = $order->get_item( $item_id );
	return $item ? call( 'get_item_data', $item ) : null;
}
function reset_request() {
	$_POST = array();
	$_GET = array();
	$_REQUEST = array();
	$r = new ReflectionProperty( 'LP_Missing_Lifecycle', 'auto_email_sent' );
	$r->setAccessible( true );
	$r->setValue( null, array() );
}
class LP_Redirect extends Exception {}
add_filter( 'wp_redirect', function ( $location ) { throw new LP_Redirect( $location ); }, 1 );
add_filter( 'pre_wp_mail', function ( $null, $atts ) { $GLOBALS['lp_mails'][] = $atts; return true; }, 10, 2 );

// ---------- Store setup ----------
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'yes' );
update_option( 'woocommerce_default_country', 'NO' );
update_option( 'woocommerce_currency', 'NOK' );
update_option( 'woocommerce_tax_based_on', 'billing' );
update_option( 'woocommerce_price_num_decimals', 2 );
global $wpdb;
if ( ! $wpdb->get_var( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = 'NO'" ) ) {
	WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'NO', 'tax_rate_state' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'MVA', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) );
}
// Enable both plugin emails.
update_option( 'woocommerce_lp_missing_customer_email_settings', array( 'enabled' => 'yes' ) );
update_option( 'woocommerce_lp_missing_customer_reminder_settings', array( 'enabled' => 'yes' ) );
update_option( 'woocommerce_customer_invoice_settings', array( 'enabled' => 'yes' ) );

function make_product( $name, $price, $stock = 50 ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_sku( sanitize_title( $name ) . '-' . wp_rand( 1000, 9999 ) );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $stock );
	$p->save();
	return $p;
}
$A = make_product( 'Bleier str 4', '100' );   // 80 + 20 MVA
$B = make_product( 'Bleier str 5', '150' );   // dearer alternative
$C = make_product( 'Bleier eco', '50' );      // cheaper alternative

function make_order( $product, $qty, $email = 'kunde@example.com' ) {
	$order = wc_create_order();
	$order->set_billing_first_name( 'Kari' );
	$order->set_billing_email( $email );
	$order->set_billing_country( 'NO' );
	$order->set_shipping_country( 'NO' );
	$order->add_product( wc_get_product( $product->get_id() ), $qty );
	$order->calculate_totals( true );
	$order->set_status( 'processing' );
	$order->save();
	return wc_get_order( $order->get_id() );
}
function first_item_id( $order ) {
	$ids = array_keys( $order->get_items( 'line_item' ) );
	return $ids[0];
}
function admin_save( $order, $fields_by_item ) {
	reset_request();
	wp_set_current_user( 1 );
	$_POST['lp_missing_nonce'] = wp_create_nonce( 'lp_missing_metabox' );
	$_POST['lp_missing_items'] = array();
	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		// Mirror what the browser posts for every rendered line (unchecked boxes are absent).
		$_POST['lp_missing_items'][ $item_id ] = array( 'qty_missing' => '0', 'notes' => '', 'internal_notes' => '' );
		if ( isset( $fields_by_item[ $item_id ] ) ) {
			$_POST['lp_missing_items'][ $item_id ] = array_merge( $_POST['lp_missing_items'][ $item_id ], $fields_by_item[ $item_id ] );
		}
	}
	call( 'save_metabox', $order->get_id(), wc_get_order( $order->get_id() ) );
}
function portal_request( $order, $post = array(), $token_from_html = null ) {
	reset_request();
	wp_set_current_user( 0 );
	$link = call( 'get_magic_link_for_order', $order );
	parse_str( wp_parse_url( $link, PHP_URL_QUERY ), $q );
	$_GET = $q;
	$_POST = $post;
	$_REQUEST = array_merge( $_GET, $_POST );
	return call( 'render_shortcode', array() );
}
function extract_field( $html, $name ) {
	return preg_match( '/name="' . preg_quote( $name, '/' ) . '" value="([^"]*)"/', $html, $m ) ? html_entity_decode( $m[1] ) : null;
}
function apply_via_handler( $order_id, $item_id, $type, $mode ) {
	reset_request();
	wp_set_current_user( 1 );
	$_GET = $_REQUEST = array(
		'action'     => 'lp_missing_apply_decision',
		'order_id'   => $order_id,
		'item_id'    => $item_id,
		'apply_type' => $type,
		'apply_mode' => $mode,
		'_wpnonce'   => wp_create_nonce( 'lp_missing_apply_' . $order_id . '_' . $item_id ),
	);
	try {
		call( 'handle_apply_decision' );
	} catch ( LP_Redirect $r ) {
		return $r->getMessage();
	}
	return '';
}
function order_tax_lines_total( $order ) {
	$sum = 0;
	foreach ( $order->get_taxes() as $tax ) {
		$sum += (float) $tax->get_tax_total();
	}
	return $sum;
}
function items_tax_sum( $order ) {
	$sum = 0;
	foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
		$sum += (float) $item->get_total_tax();
	}
	return $sum;
}


function t_summary() {
	echo "\nRESULT: {$GLOBALS['lp_pass']} passed, {$GLOBALS['lp_fail']} failed\n";
}
