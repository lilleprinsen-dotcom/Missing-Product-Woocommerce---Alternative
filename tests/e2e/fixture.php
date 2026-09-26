<?php
// Browser fixture: a portal page, an order with an open missing line and one with a pending customer choice.
$p = wc_get_products( array( 'limit' => 2, 'orderby' => 'ID', 'order' => 'ASC' ) );
$A = $p[0]; $B = $p[1];
$page = get_page_by_path( 'velg-erstatning' );
$page_id = $page ? $page->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Velg erstatning', 'post_name' => 'velg-erstatning', 'post_content' => '[lp_missing_items]' ) );
$s = get_option( 'lp_missing_settings', array() );
$s['portal_base_url'] = get_permalink( $page_id );
update_option( 'lp_missing_settings', $s );
function fx_order( $A, $qty ) {
	$o = wc_create_order();
	$o->set_billing_first_name( 'Kari' ); $o->set_billing_email( 'kunde@example.com' ); $o->set_billing_country( 'NO' );
	$o->add_product( $A, $qty ); $o->calculate_totals( true ); $o->set_status( 'processing' ); $o->save();
	return wc_get_order( $o->get_id() );
}
$o1 = fx_order( $A, 3 );
$i1 = array_keys( $o1->get_items() )[0];
$item = $o1->get_item( $i1, false );
$item->update_meta_data( '_lp_missing_data', array( 'missing' => true, 'qty_missing' => 2, 'alternatives' => array( $B->get_id() ), 'status' => 'pending', 'first_missing_at' => time() ) );
$item->save();
$o2 = fx_order( $A, 2 );
$i2 = array_keys( $o2->get_items() )[0];
$item = $o2->get_item( $i2, false );
$item->update_meta_data( '_lp_missing_data', array( 'missing' => true, 'qty_missing' => 1, 'alternatives' => array( $B->get_id() ), 'status' => 'alt_pending', 'selected_alt_id' => $B->get_id(), 'qty_alt' => 1, 'first_missing_at' => time(), 'decision_made_at' => time() ) );
$item->save();
echo wp_json_encode( array(
	'portal'    => LP_Missing_Product_Handler::get_magic_link_for_order( $o1 ),
	'order1'    => $o1->get_id(),
	'item1'     => $i1,
	'order2'    => $o2->get_id(),
	'edit2'     => $o2->get_edit_order_url(),
	'total2'    => $o2->get_total(),
) );
