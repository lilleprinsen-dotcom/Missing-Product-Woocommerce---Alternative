<?php
// Browser fixture (wp eval-file): portal page, products with images/weight/stock, orders with open missing lines,
// one order with a pending customer choice (admin test) and a staff preview URL with the matching login cookie.
// Prints one JSON line; see e2e.cjs.
defined( 'ABSPATH' ) || exit; // Runs inside WordPress (wp eval-file), never over HTTP.

update_option( 'woocommerce_weight_unit', 'kg' );

function fx_image( $label, $rgb ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return 0;
	}
	$img = imagecreatetruecolor( 120, 120 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, $rgb[0], $rgb[1], $rgb[2] ) );
	imagestring( $img, 5, 10, 50, $label, imagecolorallocate( $img, 255, 255, 255 ) );
	ob_start();
	imagepng( $img );
	$png = ob_get_clean();
	$upload = wp_upload_bits( sanitize_title( $label ) . '.png', null, $png );
	if ( ! empty( $upload['error'] ) ) {
		return 0;
	}
	return wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $label, 'post_status' => 'inherit' ), $upload['file'] );
}
function fx_product( $name, $price, $stock, $weight = '', $rgb = null ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $stock );
	if ( '' !== $weight ) {
		$p->set_weight( $weight );
	}
	if ( $rgb ) {
		$p->set_image_id( fx_image( $name, $rgb ) );
	}
	$p->save();
	return $p;
}
function fx_order( $lines, $status = 'processing' ) {
	$o = wc_create_order();
	$o->set_billing_first_name( 'Kari' );
	$o->set_billing_email( 'kunde@example.com' );
	$o->set_billing_country( 'NO' );
	foreach ( $lines as $line ) {
		$o->add_product( $line[0], $line[1] );
	}
	$o->calculate_totals( true );
	$o->set_status( $status );
	$o->save();
	return wc_get_order( $o->get_id() );
}
function fx_mark( $order, $index, $data ) {
	$ids  = array_keys( $order->get_items() );
	$item = $order->get_item( $ids[ $index ], false );
	$item->update_meta_data( '_lp_missing_data', array_merge( array( 'missing' => true, 'status' => 'pending', 'first_missing_at' => time() ), $data ) );
	$item->save();
	LP_Missing_Orders::refresh_order_flags( wc_get_order( $order->get_id() ) );
	return $ids[ $index ];
}

// Portal page (as created on activation) and settings.
$s = get_option( 'lp_missing_settings', array() );
$s['portal_base_url'] = '';
update_option( 'lp_missing_settings', $s );
update_option( 'lp_missing_portal_url', '' );
LP_Missing_Settings::flush();
$page_id = LP_Missing_Portal_Setup::ensure_portal_page();

$orig    = fx_product( 'E2E Bleier', '100', 50, '', array( 30, 64, 175 ) );
$premium = fx_product( 'E2E Bleier premium', '150', 50, '0.5', array( 4, 120, 87 ) );
$low     = fx_product( 'E2E Bleier eco', '50', 1, '', array( 146, 64, 14 ) );
$out     = fx_product( 'E2E Utsolgt', '90', 0 );
$milk    = fx_product( 'E2E Melk', '20', 50 );

// Guest flow with JavaScript.
$o1  = fx_order( array( array( $orig, 3 ), array( $milk, 1 ) ) );
$i1a = fx_mark( $o1, 0, array( 'qty_missing' => 2, 'alternatives' => array( $premium->get_id(), $low->get_id(), $out->get_id() ) ) );
$i1b = fx_mark( $o1, 1, array( 'qty_missing' => 1, 'propose_delete' => true ) );
// Guest flow without JavaScript.
$o3  = fx_order( array( array( $orig, 2 ) ) );
$i3  = fx_mark( $o3, 0, array( 'qty_missing' => 2, 'alternatives' => array( $premium->get_id() ) ) );
// Admin screen: a pending customer choice to apply.
$o2 = fx_order( array( array( $orig, 2 ) ) );
fx_mark( $o2, 0, array( 'qty_missing' => 1, 'alternatives' => array( $premium->get_id() ), 'status' => 'alt_pending', 'selected_alt_id' => $premium->get_id(), 'qty_alt' => 1, 'decision_made_at' => time() ) );

// Staff preview: a nonce for a real login session of user 1, and that session's cookie for the browser.
$expiration = time() + DAY_IN_SECONDS;
$session    = WP_Session_Tokens::get_instance( 1 )->create( $expiration );
$logged_in  = wp_generate_auth_cookie( 1, $expiration, 'logged_in', $session );
$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
wp_set_current_user( 1 );
$preview = LP_Missing_Magic_Link::get_staff_preview_url( $o1 );

echo wp_json_encode(
	array(
		'base'          => untrailingslashit( home_url() ),
		'page'          => get_permalink( $page_id ),
		'portal'        => LP_Missing_Product_Handler::get_magic_link_for_order( $o1 ),
		'order1'        => $o1->get_id(),
		'item1a'        => $i1a,
		'item1b'        => $i1b,
		'premium'       => $premium->get_id(),
		'low'           => $low->get_id(),
		'out'           => $out->get_id(),
		'portal3'       => LP_Missing_Product_Handler::get_magic_link_for_order( $o3 ),
		'order3'        => $o3->get_id(),
		'item3'         => $i3,
		'preview'       => $preview,
		'previewCookie' => array( 'name' => LOGGED_IN_COOKIE, 'value' => $logged_in, 'path' => COOKIEPATH ),
		'order2'        => $o2->get_id(),
		'edit2'         => $o2->get_edit_order_url(),
		'total2'        => $o2->get_total(),
	)
) . "\n";
