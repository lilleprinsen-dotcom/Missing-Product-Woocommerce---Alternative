<?php
// Browser fixture for tests/e2e/admin.e2e.cjs: orders for the admin order screen and the order list.
//   wp eval-file tests/e2e/admin-fixture.php | tail -1 > tests/e2e/admin-fixture.json
defined( 'ABSPATH' ) || exit; // Runs inside WordPress (wp eval-file), never over HTTP.

function afx_simple( $name, $price, $stock ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $stock );
	$p->save();
	return $p;
}

function afx_variable( $name, $rows ) {
	$attrs = array();
	foreach ( array( 'Size' => array( 'S', 'M', 'L' ), 'Color' => array( 'Red', 'Blue' ) ) as $label => $options ) {
		$attr = new WC_Product_Attribute();
		$attr->set_name( $label );
		$attr->set_options( $options );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$attrs[] = $attr;
	}
	$parent = new WC_Product_Variable();
	$parent->set_name( $name );
	$parent->set_attributes( $attrs );
	$parent->save();
	$ids = array();
	foreach ( $rows as $key => $row ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent->get_id() );
		$v->set_attributes( array( 'size' => $row[0], 'color' => $row[1] ) );
		$v->set_regular_price( $row[2] );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( $row[3] );
		$v->save();
		$ids[ $key ] = $v->get_id();
	}
	WC_Product_Variable::sync( $parent->get_id() );
	wc_delete_product_transients( $parent->get_id() );
	return $ids;
}

function afx_order( $product, $qty ) {
	$o = wc_create_order();
	$o->set_billing_first_name( 'Kari' );
	$o->set_billing_email( 'kunde@example.com' );
	$o->set_billing_country( 'NO' );
	$o->add_product( wc_get_product( $product->get_id() ), $qty );
	$o->calculate_totals( true );
	$o->set_status( 'processing' );
	$o->save();
	return wc_get_order( $o->get_id() );
}

function afx_mark( $order, $data ) {
	$item_id = array_keys( $order->get_items() )[0];
	$item    = $order->get_item( $item_id, false );
	$item->update_meta_data( '_lp_missing_data', array_merge( array( 'missing' => true, 'status' => 'pending', 'first_missing_at' => time() ), $data ) );
	$item->save();
	$order = wc_get_order( $order->get_id() );
	LP_Missing_Orders::refresh_order_flags( $order );
	return $item_id;
}

$tag  = wp_rand( 1000, 9999 );
$base = afx_simple( "E2E Bleier $tag", '100', 50 );
$dear = afx_simple( "E2E Dyr $tag", '150', 50 );
$none = afx_simple( "E2E Tom $tag", '100', 0 );
$var  = afx_variable(
	"E2E Body $tag",
	array(
		'M-Red'  => array( 'M', 'Red', '100', 10 ),
		'M-Blue' => array( 'M', 'Blue', '100', 10 ),
		'L-Red'  => array( 'L', 'Red', '110', 10 ),
		'L-Blue' => array( 'L', 'Blue', '100', 10 ),
		'S-Red'  => array( 'S', 'Red', '100', 0 ),
	)
);

// 1) Simple line waiting for the customer, with a dearer and an out-of-stock alternative.
$open      = afx_order( $base, 3 );
$open_item = afx_mark( $open, array( 'qty_missing' => 2, 'alternatives' => array( $dear->get_id(), $none->get_id() ), 'notified_at' => time() - HOUR_IN_SECONDS ) );
wp_schedule_single_event( time() + 2 * DAY_IN_SECONDS, 'lp_missing_send_reminder', array( $open->get_id(), $open_item ) );

// 2) Variation line waiting for the customer, no alternatives yet.
$vorder = afx_order( wc_get_product( $var['M-Red'] ), 2 );
$v_item = afx_mark( $vorder, array( 'qty_missing' => 2, 'alternatives' => array() ) );

// 3) Customer chose an alternative: ready for staff.
$ready      = afx_order( $base, 2 );
$ready_item = afx_mark( $ready, array( 'qty_missing' => 1, 'alternatives' => array( $dear->get_id() ), 'status' => 'alt_pending', 'selected_alt_id' => $dear->get_id(), 'qty_alt' => 1, 'decision_made_at' => time() ) );

// 4) Nothing missing yet (the simple "Missing" flow).
$fresh = afx_order( $base, 2 );
$fresh->add_product( wc_get_product( $dear->get_id() ), 1 );
$fresh->calculate_totals( true );
$fresh->save();

$open = wc_get_order( $open->get_id() );
echo wp_json_encode(
	array(
		'site'       => site_url( '/' ),
		'login'      => wp_login_url(),
		'hpos'       => LP_Missing_Util::hpos_enabled(),
		'version'    => LP_Missing_Plugin::VERSION,
		'open'       => $open->get_id(),
		'openItem'   => $open_item,
		'openEdit'   => $open->get_edit_order_url(),
		'openLink'   => LP_Missing_Magic_Link::get_magic_link_for_order( $open ),
		'dear'       => $dear->get_id(),
		'none'       => $none->get_id(),
		'vorder'     => $vorder->get_id(),
		'vItem'      => $v_item,
		'vEdit'      => $vorder->get_edit_order_url(),
		'variants'   => $var,
		'ready'      => $ready->get_id(),
		'fresh'      => $fresh->get_id(),
		'freshItem'  => array_keys( wc_get_order( $fresh->get_id() )->get_items() )[0],
		'freshEdit'  => wc_get_order( $fresh->get_id() )->get_edit_order_url(),
		'readyCount' => LP_Missing_Orders::count_orders_ready_for_staff(),
		'openCount'  => LP_Missing_Orders::count_orders_with_open_missing(),
		'listReady'  => add_query_arg( 'lp_missing_view', 'ready', LP_Missing_Util::get_orders_list_url() ),
		'listOpen'   => add_query_arg( 'lp_missing_view', 'open', LP_Missing_Util::get_orders_list_url() ),
	)
);
