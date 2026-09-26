<?php
// Core integration tests. Run with: wp eval-file tests/integration/test-plugin.php (see tests/README.md).
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/portal-helpers.php';

echo "HPOS: " . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . "\n";

// ---------- Bug 3: saving an order must not mark untouched lines as 'cleared' ----------
echo "\n[Bug 3] Re-marking after earlier saves\n";
$o = make_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array() );
$o = wc_get_order( $o->get_id() );
t_ok( ! $o->get_item( $iid )->meta_exists( '_lp_missing_data' ), 'plain order save writes no tracking meta' );
t_ok( '' === $o->get_meta( '_lp_missing_has_data' ), 'plain order save sets no order flags' );
$GLOBALS['lp_mails'] = array();
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $B->get_id(), $C->get_id() ) ) ) );
$d = item_data( $o->get_id(), $iid );
t_eq( 'pending', $d['status'], 'marked line is pending' );
t_eq( 2, $d['qty_missing'], 'qty_missing stored' );
t_eq( 1, count( $GLOBALS['lp_mails'] ), 'customer portal email sent once' );
t_ok( call( 'get_next_reminder_timestamp', $o ) > time(), 'reminder scheduled' );
t_eq( 'yes', wc_get_order( $o->get_id() )->get_meta( '_lp_missing_has_open' ), 'order flagged open' );
// Clear then re-mark (the old code left status 'cleared' and treated the re-marked line as resolved).
admin_save( $o, array() );
t_eq( 'cleared', item_data( $o->get_id(), $iid )['status'], 'unticking clears the case' );
$GLOBALS['lp_mails'] = array();
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '0', 'alternatives' => array( $B->get_id() ) ) ) );
$d = item_data( $o->get_id(), $iid );
t_eq( 'pending', $d['status'], 're-marked line is pending again' );
t_eq( 5, $d['qty_missing'], 'qty 0 defaults to full line quantity' );
t_eq( 1, count( $GLOBALS['lp_mails'] ), 're-marked line notifies customer' );
// Saving again unchanged must not re-send or re-fire.
$GLOBALS['lp_mails'] = array();
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '5', 'alternatives' => array( $B->get_id() ) ) ) );
t_eq( 0, count( $GLOBALS['lp_mails'] ), 'unchanged save sends nothing' );

// ---------- Bug 1: guest portal flow ----------
// The portal flow itself is covered in depth by test-portal.php; this keeps the order used by the apply tests below.
echo "\n[Bug 1] Guest portal verification + choice\n";
portal_jar_reset();
$o = make_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $B->get_id(), $C->get_id() ) ) ) );
$res = portal_follow( portal_http( call( 'get_magic_link_for_order', $o ) ) );
t_ok( false !== strpos( $res['html'], 'lp_missing_verify_email' ), 'guest first sees verification form' );
$res = portal_login( $o, 'KUNDE@example.com' );
$token = extract_field( $res['html'], 'lp_missing_token' );
t_ok( ! empty( $token ) && false === strpos( $res['html'], 'lp_missing_verify_email' ), 'after verification (case-insensitive) the choice form is shown' );
$res = portal_save( $o, array( $iid => 'alt:' . $B->get_id() ), array( $iid => 2 ), $token );
t_ok( false !== strpos( $res['redirect'], 'lp_msg=saved' ), 'choice submission redirects with a saved message (not bounced to verification)' );
$d = item_data( $o->get_id(), $iid );
t_eq( 'alt_pending', $d['status'], 'customer choice stored' );
t_eq( $B->get_id(), $d['selected_alt_id'], 'selected alternative stored' );
t_eq( '100.00', $d['pricing_snapshot']['delta_total_incl'], 'frozen delta incl VAT for 2 units' );
// Re-submitting the same form (a refresh) changes nothing; a different answer changes the decision until staff applies it.
$frozen_at = $d['pricing_snapshot']['frozen_at'];
$res = portal_save( $o, array( $iid => 'alt:' . $B->get_id() ), array( $iid => 2 ), $token );
t_ok( false !== strpos( $res['redirect'], 'lp_msg=nochange' ), 're-submitted form is a no-op' );
t_eq( $frozen_at, item_data( $o->get_id(), $iid )['pricing_snapshot']['frozen_at'], 're-submitted form keeps the frozen price' );
portal_save( $o, array( $iid => 'alt:' . $C->get_id() ), array( $iid => 2 ), $token );
t_eq( $C->get_id(), item_data( $o->get_id(), $iid )['selected_alt_id'], 'customer can change the decision before staff applies it' );
portal_save( $o, array( $iid => 'alt:' . $B->get_id() ), array( $iid => 2 ), $token );
t_eq( $B->get_id(), item_data( $o->get_id(), $iid )['selected_alt_id'], 'changed back to the dearer alternative' );
t_eq( '100.00', item_data( $o->get_id(), $iid )['pricing_snapshot']['delta_total_incl'], 'price frozen again at the new choice' );
// Forged token is rejected.
$res = portal_save( $o, array( $iid => 'decline' ), array(), 'x.abc' );
t_ok( false !== strpos( $res['html'], 'Sikkerhetssjekken feilet' ) && 'alt_pending' === item_data( $o->get_id(), $iid )['status'], 'forged form token is rejected' );
portal_jar_reset();
$bad = portal_http( add_query_arg( array( 'oid' => $o->get_id(), 'key' => 'nope' ), portal_clean_url( $o ) ) );
t_ok( false !== strpos( $bad['html'], 'Lenken er ugyldig' ) && false === strpos( $bad['html'], 'utløpt' ), 'invalid link message no longer claims expiry' );
$_GET = array(); $_POST = array();

// ---------- Bug 2: admin metabox renders no nested forms ----------
echo "\n[Bug 2] Metabox markup\n";
wp_set_current_user( 1 );
ob_start();
call( 'render_metabox', wc_get_order( $o->get_id() ) );
$box = ob_get_clean();
t_ok( false === stripos( $box, '<form' ), 'metabox contains no <form> (it lives inside the order form)' );
t_ok( false === strpos( $box, 'name="action"' ), 'metabox has no field named "action" to hijack the order Update' );
t_ok( false !== strpos( $box, 'lp_missing_apply_decision' ) && false !== strpos( $box, 'apply_mode=replace' ) && false !== strpos( $box, 'apply_mode=add' ), 'apply links for replace/add rendered' );
t_ok( false !== strpos( $box, 'wc-product-search' ), 'alternatives use WooCommerce product search' );
ob_start();
call( 'render_metabox', get_post( $o->get_id() ) ?: wc_get_order( $o->get_id() ) );
$box2 = ob_get_clean();
t_ok( strlen( $box2 ) > 100, 'metabox renders from a WP_Post argument (legacy screen)' );

// ---------- Bug 4 + 5: replace (partial) keeps totals balanced, surcharge includes VAT ----------
echo "\n[Bug 4/5] Apply alternative - replace, partial line\n";
$o = wc_get_order( $o->get_id() );
$before_total = (float) $o->get_total();
$before_tax   = (float) $o->get_total_tax();
t_eq( 500.0, $before_total, 'order total before apply (5 x 100)' );
$stock_b_before = wc_get_product( $B->get_id() )->get_stock_quantity();
$redirect = apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
t_ok( false !== strpos( $redirect, 'lp_missing_apply=success' ), 'apply handler (GET link + nonce) succeeded: ' . $redirect );
$o = wc_get_order( $o->get_id() );
$orig = $o->get_item( $iid );
t_eq( 3, $orig->get_quantity(), 'original line quantity reduced to 3' );
t_eq( 240.0, (float) $orig->get_total(), 'original line total reduced to 3 x 80 excl' );
t_eq( 60.0, (float) $orig->get_total_tax(), 'original line tax reduced to 3 x 20' );
$alt_item = null;
foreach ( $o->get_items( 'line_item' ) as $it ) {
	if ( $it->get_product_id() === $B->get_id() ) {
		$alt_item = $it;
	}
}
t_ok( (bool) $alt_item, 'alternative line added' );
t_eq( 160.0, (float) $alt_item->get_total(), 'alternative line carries the original 2 x 80 excl' );
t_eq( 40.0, (float) $alt_item->get_total_tax(), 'alternative line carries the original 2 x 20 VAT' );
t_ok( ! empty( $alt_item->get_taxes()['total'] ), 'alternative line has per-rate tax data' );
t_eq( $before_total, (float) $o->get_total(), 'order total unchanged (no double charge)' );
t_eq( $before_tax, order_tax_lines_total( $o ), 'order tax lines still sum to the original VAT' );
t_eq( items_tax_sum( $o ), (float) $o->get_total_tax(), 'order tax equals sum of line taxes' );
t_eq( 2, (int) $alt_item->get_meta( '_reduced_stock' ), 'alternative line records _reduced_stock' );
t_eq( $stock_b_before - 2, wc_get_product( $B->get_id() )->get_stock_quantity(), 'alternative stock decreased by 2' );
$sur = wc_get_orders( array( 'parent' => $o->get_id(), 'type' => 'shop_order', 'limit' => -1 ) );
t_eq( 1, count( $sur ), 'one surcharge order created' );
if ( $sur ) {
	$s = $sur[0];
	t_eq( 100.0, (float) $s->get_total(), 'surcharge invoice total = frozen delta incl VAT (2 x 50)' );
	t_eq( 20.0, (float) $s->get_total_tax(), 'surcharge carries 25% VAT' );
	$rate_ids = array_map( function ( $t ) { return $t->get_rate_id(); }, array_values( $s->get_taxes() ) );
	t_ok( ! in_array( 0, $rate_ids, true ) && count( $rate_ids ) === 1, 'surcharge VAT booked on a real tax rate' );
	t_eq( 'pending', $s->get_status(), 'surcharge order is pending payment' );
}
$d = item_data( $o->get_id(), $iid );
t_eq( 'alt_applied', $d['status'], 'line resolved after full missing qty applied' );
t_eq( '', $o->get_meta( '_lp_missing_has_open' ), 'order no longer flagged open' );

echo "\n[Bug 4] Apply alternative - replace, whole line removed\n";
$o = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $C->get_id() ) ) ) );
LP_Missing_Portal::handle_request(); // no-op without a portal request
$item = wc_get_order( $o->get_id() )->get_item( $iid );
$data = call( 'get_item_data', $item );
$data['status'] = 'alt_pending';
$data['selected_alt_id'] = $C->get_id();
$data['qty_alt'] = 2;
$data['pricing_snapshot'] = call( 'get_frozen_pricing_snapshot', wc_get_order( $o->get_id() ), $item, $data, wc_get_product( $C->get_id() ), 2 );
$item->update_meta_data( '_lp_missing_data', $data );
$item->save();
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
$o = wc_get_order( $o->get_id() );
t_ok( ! $o->get_item( $iid ), 'original line removed' );
t_eq( 200.0, (float) $o->get_total(), 'order total unchanged at 200 after swap' );
t_eq( 40.0, order_tax_lines_total( $o ), 'tax lines unchanged' );
t_eq( 0, count( wc_get_orders( array( 'parent' => $o->get_id(), 'type' => 'shop_order', 'limit' => -1 ) ) ), 'cheaper alternative: no surcharge order' );
t_eq( '', $o->get_meta( '_lp_missing_has_open' ), 'flags refreshed although the line was removed' );
t_ok( ! call( 'get_next_reminder_timestamp', $o ), 'reminder for removed line cleared' );

echo "\n[Bug 4] Apply alternative - add mode, then partial again\n";
$o = make_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '3', 'alternatives' => array( $C->get_id() ) ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid );
$data = call( 'get_item_data', $item );
$data['status'] = 'alt_pending';
$data['selected_alt_id'] = $C->get_id();
$data['qty_alt'] = 1;
$item->update_meta_data( '_lp_missing_data', $data );
$item->save();
$GLOBALS['lp_mails'] = array();
apply_via_handler( $o->get_id(), $iid, 'alternative', 'add' );
$o = wc_get_order( $o->get_id() );
$orig = $o->get_item( $iid );
t_eq( 5, $orig->get_quantity(), 'add mode keeps original quantity' );
t_eq( 320.0, (float) $orig->get_total(), 'add mode moves 1 x 80 off the original line' );
t_eq( 500.0, (float) $o->get_total(), 'order total unchanged' );
$d = item_data( $o->get_id(), $iid );
t_eq( 'pending', $d['status'], 'partial apply re-opens remaining qty for the customer' );
t_eq( 2, $d['qty_missing'], 'remaining missing qty = 2' );
// The customer also gets the "what changed" customer note (S2); count the portal emails.
t_eq( 1, count( array_filter( $GLOBALS['lp_mails'], function ( $m ) { return false !== strpos( $m['subject'], 'Velg erstatning' ); } ) ), 'customer notified about the remaining quantity' );
t_ok( call( 'get_next_reminder_timestamp', $o ) > time(), 'reminder re-scheduled for remaining qty' );
t_eq( 4, call( 'get_item_billable_qty', $orig ), 'billable qty tracks units moved in add mode' );
t_eq( 100.0, call( 'get_item_unit_price_incl_tax', $orig ), 'unit price still 100 incl after add mode' );

// ---------- Bug 4: delete reduce / refund ----------
echo "\n[Bug 4] Delete - reduce partial\n";
$o = make_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'propose_delete' => '1' ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid );
$data = call( 'get_item_data', $item );
$data['status'] = 'delete_pending';
$item->update_meta_data( '_lp_missing_data', $data );
$item->save();
apply_via_handler( $o->get_id(), $iid, 'delete', 'reduce' );
$o = wc_get_order( $o->get_id() );
t_eq( 3, $o->get_item( $iid )->get_quantity(), 'line quantity reduced to 3' );
t_eq( 300.0, (float) $o->get_total(), 'order total reduced by 2 x 100' );
t_eq( 60.0, order_tax_lines_total( $o ), 'VAT reduced to 60' );

echo "\n[Bug 4] Delete - reduce whole line\n";
$o = make_order( $A, 2 );
$o->add_product( wc_get_product( $C->get_id() ), 1 );
$o->calculate_totals( true );
$o = wc_get_order( $o->get_id() );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'propose_delete' => '1' ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid );
$data = call( 'get_item_data', $item );
$data['status'] = 'delete_pending';
$item->update_meta_data( '_lp_missing_data', $data );
$item->save();
t_eq( 250.0, (float) wc_get_order( $o->get_id() )->get_total(), 'total before (2 x 100 + 50)' );
apply_via_handler( $o->get_id(), $iid, 'delete', 'reduce' );
$o = wc_get_order( $o->get_id() );
t_ok( ! $o->get_item( $iid ), 'line removed' );
t_eq( 50.0, (float) $o->get_total(), 'order total reduced to the remaining 50' );
t_eq( 10.0, order_tax_lines_total( $o ), 'VAT reduced to 10' );

echo "\n[Bug 4] Delete - refund\n";
$o = make_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'propose_delete' => '1' ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid );
$data = call( 'get_item_data', $item );
$data['status'] = 'delete_pending';
$item->update_meta_data( '_lp_missing_data', $data );
$item->save();
apply_via_handler( $o->get_id(), $iid, 'delete', 'refund' );
$o = wc_get_order( $o->get_id() );
t_eq( 200.0, (float) $o->get_total_refunded(), 'refund of 2 x 100 recorded' );
t_eq( 2, absint( $o->get_qty_refunded_for_item( $iid ) ), 'refund attributed to the line (2 units)' );
t_eq( 'delete_applied', item_data( $o->get_id(), $iid )['status'], 'line resolved after refund' );
// Second refund attempt beyond remaining amount must fail and leave the line open.
$o2 = make_order( $A, 1 );
$iid2 = first_item_id( $o2 );
wc_create_refund( array( 'amount' => 100, 'order_id' => $o2->get_id(), 'refund_payment' => false ) );
admin_save( wc_get_order( $o2->get_id() ), array( $iid2 => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
$item = wc_get_order( $o2->get_id() )->get_item( $iid2 );
$data = call( 'get_item_data', $item );
$data['status'] = 'delete_pending';
$item->update_meta_data( '_lp_missing_data', $data );
$item->save();
$redirect = apply_via_handler( $o2->get_id(), $iid2, 'delete', 'refund' );
t_ok( false !== strpos( $redirect, 'lp_missing_apply=error' ), 'failed refund reports an error' );
t_eq( 'delete_pending', item_data( $o2->get_id(), $iid2 )['status'], 'failed refund leaves the line open' );

// ---------- Declined -> new suggestions re-open; declined can be removed ----------
echo "\n[Minor] Declined line\n";
$o = make_order( $A, 4 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ) ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid );
$data = call( 'get_item_data', $item );
$data['status'] = 'declined';
$item->update_meta_data( '_lp_missing_data', $data );
$item->save();
$GLOBALS['lp_mails'] = array();
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ) ) ) );
t_eq( 'declined', item_data( $o->get_id(), $iid )['status'], 'unchanged suggestions keep the decline' );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $C->get_id() ) ) ) );
t_eq( 'pending', item_data( $o->get_id(), $iid )['status'], 'new suggestions re-open the line' );
t_eq( 1, count( $GLOBALS['lp_mails'] ), 'customer notified about new suggestions' );
$item = wc_get_order( $o->get_id() )->get_item( $iid );
$data = call( 'get_item_data', $item );
$data['status'] = 'declined';
$item->update_meta_data( '_lp_missing_data', $data );
$item->save();
apply_via_handler( $o->get_id(), $iid, 'delete', 'reduce' );
t_eq( 300.0, (float) wc_get_order( $o->get_id() )->get_total(), 'declined line can be removed by staff (400 -> 300)' );

// ---------- Order list count + cleanup (meta_key works on both storages) ----------
echo "\n[New] Order list count and daily cleanup\n";
$open_before = call( 'count_orders_with_open_missing' );
$real_open   = count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'meta_key' => '_lp_missing_has_open', 'meta_value' => 'yes', 'type' => 'shop_order' ) ) );
$all_orders  = count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order' ) ) );
t_eq( $real_open, $open_before, 'view count equals the number of open orders' );
t_ok( $open_before < $all_orders, "view count ($open_before) is not the count of all orders ($all_orders)" );
// Make a resolved order look old and run the cleanup.
$old = make_order( $A, 1 );
$oid = first_item_id( $old );
admin_save( $old, array( $oid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
admin_save( wc_get_order( $old->get_id() ), array() ); // clear -> resolved
$old = wc_get_order( $old->get_id() );
t_eq( 'yes', $old->get_meta( '_lp_missing_has_data' ), 'resolved order still flagged with data' );
$old_date = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
	$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'date_updated_gmt' => $old_date, 'date_created_gmt' => $old_date ), array( 'id' => $old->get_id() ) );
} else {
	$wpdb->update( $wpdb->posts, array( 'post_modified' => $old_date, 'post_modified_gmt' => $old_date, 'post_date' => $old_date, 'post_date_gmt' => $old_date ), array( 'ID' => $old->get_id() ) );
	clean_post_cache( $old->get_id() );
}
wp_cache_flush();
call( 'run_daily_cleanup' );
$old = wc_get_order( $old->get_id() );
t_ok( ! $old->get_item( $oid )->meta_exists( '_lp_missing_data' ), 'daily cleanup purged the old resolved order' );
t_eq( '', $old->get_meta( '_lp_missing_has_data' ), 'daily cleanup removed the order flag' );


// ---------- Stock bookkeeping ----------
echo "\n[Stock] lock, WooCommerce line stock and releases\n";
$S = make_product( 'Lager-test', '100', 50 );
$Alt = make_product( 'Lager-alt', '100', 50 );
$o = make_order( $S, 5 );
$iid = first_item_id( $o );
$after_order = wc_get_product( $S->get_id() )->get_stock_quantity();
t_eq( 45, $after_order, 'processing order reduced stock by 5' );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $Alt->get_id() ) ) ) );
t_eq( 43, wc_get_product( $S->get_id() )->get_stock_quantity(), 'marking 2 missing locks 2 units' );
$item = wc_get_order( $o->get_id() )->get_item( $iid, false );
$data = call( 'get_item_data', $item );
$data['status'] = 'alt_pending'; $data['selected_alt_id'] = $Alt->get_id(); $data['qty_alt'] = 2;
$item->update_meta_data( '_lp_missing_data', $data ); $item->save();
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
t_eq( 45, wc_get_product( $S->get_id() )->get_stock_quantity(), 'apply: lock released; confirmed-missing units do not return to stock' );
t_eq( 48, wc_get_product( $Alt->get_id() )->get_stock_quantity(), 'apply: alternative stock reduced by 2' );
$o = wc_get_order( $o->get_id() );
t_eq( 3, (int) $o->get_item( $iid, false )->get_meta( '_reduced_stock' ), 'original line _reduced_stock follows new quantity' );
// What a later "Update" in the order screen does: must not move stock again.
foreach ( $o->get_items() as $it ) { wc_maybe_adjust_line_item_product_stock( $it ); }
t_eq( 45, wc_get_product( $S->get_id() )->get_stock_quantity(), 'later order Update does not change original stock again' );
t_eq( 48, wc_get_product( $Alt->get_id() )->get_stock_quantity(), 'later order Update does not change alternative stock again' );

$o = make_order( $S, 2 );
$iid = first_item_id( $o );
$base = wc_get_product( $S->get_id() )->get_stock_quantity();
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2' ) ) );
t_eq( $base - 2, wc_get_product( $S->get_id() )->get_stock_quantity(), 'lock taken' );
wc_delete_order_item( $iid );
t_eq( $base, wc_get_product( $S->get_id() )->get_stock_quantity(), 'deleting the line in the order editor releases the lock' );

$o = make_order( $S, 3 );
$iid = first_item_id( $o );
$before_order = wc_get_product( $S->get_id() )->get_stock_quantity() + 3;
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$o = wc_get_order( $o->get_id() );
$o->update_status( 'cancelled' );
t_eq( $before_order, wc_get_product( $S->get_id() )->get_stock_quantity(), 'cancelling restores order stock and releases the lock' );
t_eq( 'cleared', item_data( $o->get_id(), $iid )['status'], 'cancelled order closes the missing case' );
t_ok( ! call( 'get_next_reminder_timestamp', $o ), 'cancelled order has no reminders left' );

update_option( 'lp_missing_settings', array_merge( get_option( 'lp_missing_settings', array() ), array( 'enable_stock_lock' => 'no' ) ) );
$r = new ReflectionProperty( 'LP_Missing_Settings', 'settings' ); $r->setAccessible( true ); $r->setValue( null, null );
update_option( 'lp_missing_enable_stock_log', 'no' );
$o = make_order( $S, 2 );
$iid = first_item_id( $o );
$base = wc_get_product( $S->get_id() )->get_stock_quantity();
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2' ) ) );
t_eq( 0, item_data( $o->get_id(), $iid )['stock_locked_qty'], 'lock disabled: no lock recorded' );
update_option( 'lp_missing_settings', array_merge( get_option( 'lp_missing_settings', array() ), array( 'enable_stock_lock' => 'yes' ) ) );
update_option( 'lp_missing_enable_stock_log', 'yes' );
$r->setValue( null, null );
admin_save( wc_get_order( $o->get_id() ), array() );
t_eq( $base, wc_get_product( $S->get_id() )->get_stock_quantity(), 'no phantom stock when the lock is enabled later and the line is cleared' );

// ---------- Concurrency ----------
echo "\n[New] Double apply\n";
$o = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ) ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid, false );
$data = call( 'get_item_data', $item );
$data['status'] = 'alt_pending'; $data['selected_alt_id'] = $B->get_id(); $data['qty_alt'] = 1;
$item->update_meta_data( '_lp_missing_data', $data ); $item->save();
t_ok( call( 'acquire_apply_lock', $o->get_id() ), 'first request takes the apply lock' );
$redirect = apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
t_ok( false !== strpos( $redirect, 'lp_missing_apply=error' ), 'concurrent second apply is refused' );
t_eq( 1, count( wc_get_order( $o->get_id() )->get_items() ), 'no alternative line added by the refused request' );
call( 'release_apply_lock', $o->get_id() );
$redirect = apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
t_ok( false !== strpos( $redirect, 'lp_missing_apply=success' ), 'apply works once the lock is free' );
$redirect = apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
t_ok( false !== strpos( $redirect, 'lp_missing_apply=error' ), 'repeating an applied decision is refused' );
t_eq( 1, count( wc_get_orders( array( 'parent' => $o->get_id(), 'type' => 'shop_order', 'limit' => -1 ) ) ), 'still exactly one surcharge order' );

// ---------- Portal output and access ----------
echo "\n[New] Portal text, access and robustness\n";
$o = make_order( $A, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id(), $C->get_id() ) ) ) );
wp_set_current_user( 0 );
$_GET = array(); $_POST = array();
$html = call( 'render_shortcode', array( 'order_id' => $o->get_id(), 'email' => 'kunde@example.com' ) );
t_ok( false !== strpos( $html, 'lp-missing-portal-error' ), 'shortcode attributes alone give a guest no access' );
wp_set_current_user( 1 );
$html = call( 'render_shortcode', array( 'order_id' => $o->get_id(), 'email' => 'kunde@example.com' ) );
t_ok( false !== strpos( $html, 'lp-portal-item' ), 'staff can preview through shortcode attributes' );
t_ok( false === strpos( $html, '&lt;span' ) && false === strpos( $html, '&lt;bdi' ), 'price texts contain no escaped HTML markup' );
t_ok( false !== strpos( $html, 'Mellomlegget faktureres' ), 'dearer alternative explains the surcharge' );
t_ok( false !== strpos( $html, 'Ordren beholder opprinnelig pris' ), 'cheaper alternative no longer promises a lower price' );
t_ok( false === strpos( $html, '<form' ) && false === strpos( $html, 'lp_missing_token' ), 'staff preview through attributes is read-only (no form)' );
$refund = wc_create_refund( array( 'amount' => 1, 'order_id' => $o->get_id() ) );
wp_set_current_user( 0 );
$_GET = array( 'oid' => $refund->get_id(), 'key' => 'x' );
$html = call( 'render_shortcode', array() );
t_ok( false !== strpos( $html, 'Lenken er ugyldig' ), 'refund ID in the link is handled without a fatal error' );
$_GET = array();

// Portal on the default My Account URL.
$page_id = wc_get_page_id( 'myaccount' );
if ( $page_id < 1 ) {
	$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Min konto', 'post_content' => '[woocommerce_my_account]' ) );
	update_option( 'woocommerce_myaccount_page_id', $page_id );
}
$link = call( 'get_magic_link_for_order', $o );
t_ok( 0 === strpos( $link, get_permalink( $page_id ) ), 'magic link points at My Account by default' );
parse_str( wp_parse_url( $link, PHP_URL_QUERY ), $q );
$_GET = $q;
global $wp_query, $wp_the_query, $post;
$wp_query = new WP_Query( array( 'page_id' => $page_id ) );
$wp_the_query = $wp_query;
$wp_query->the_post();
$filtered = apply_filters( 'the_content', get_post( $page_id )->post_content );
t_ok( false !== strpos( $filtered, 'lp_missing_verify_email' ) || false !== strpos( $filtered, 'lp-missing-portal' ), 'opening the magic link on My Account shows the portal' );
wp_reset_postdata();
$_GET = array();

// ---------- Cleanup scheduling ----------
echo "\n[New] Cleanup events\n";
$o = make_order( $A, 1 );
admin_save( $o, array() );
call( 'handle_cleanup_order', $o->get_id() );
t_ok( ! LP_Missing_Scheduler::next( 'lp_missing_cleanup_order', array( $o->get_id() ) ), 'orders without plugin data get no cleanup event' );

// ---------- Reminders ----------
echo "\n[New] Reminders\n";
$o = make_order( $A, 2 );
$o->add_product( wc_get_product( $C->get_id() ), 1 );
$o->calculate_totals( true );
$ids = array_keys( wc_get_order( $o->get_id() )->get_items() );
admin_save( wc_get_order( $o->get_id() ), array( $ids[0] => array( 'missing' => '1', 'qty_missing' => '1' ), $ids[1] => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$GLOBALS['lp_mails'] = array();
// One reminder per order (the job itself also checks the reminder window; see test-notify.php).
call( 'send_order_reminder', wc_get_order( $o->get_id() ) );
call( 'send_order_reminder', wc_get_order( $o->get_id() ) );
t_eq( 1, count( $GLOBALS['lp_mails'] ), 'two missing lines produce one reminder email' );
t_ok( ! empty( $GLOBALS['lp_mails'] ) && false !== strpos( $GLOBALS['lp_mails'][0]['message'], 'Bleier eco' ) && false !== strpos( $GLOBALS['lp_mails'][0]['message'], 'Bleier str 4' ), 'reminder names every waiting line' );
t_eq( 1, item_data( $o->get_id(), $ids[1] )['reminder_count'], 'deduplicated line still counts the reminder' );


// ---------- Different tax class (NO: 25% goods vs 15% food) ----------
echo "\n[New] Alternative with another tax class\n";
if ( ! in_array( 'redusert-sats', WC_Tax::get_tax_class_slugs(), true ) ) {
	WC_Tax::create_tax_class( 'Redusert sats', 'redusert-sats' );
}
if ( ! $wpdb->get_var( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = 'redusert-sats'" ) ) {
	WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'NO', 'tax_rate_state' => '', 'tax_rate' => '15.0000', 'tax_rate_name' => 'MVA mat', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => 'redusert-sats' ) );
}
$food_rate = (int) $wpdb->get_var( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = 'redusert-sats'" );
$F = make_product( 'Barnemat', '150' );
$F->set_tax_class( 'redusert-sats' ); $F->save();
$o = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $F->get_id() ) ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid, false );
$data = call( 'get_item_data', $item );
$data['status'] = 'alt_pending'; $data['selected_alt_id'] = $F->get_id(); $data['qty_alt'] = 1;
$data['pricing_snapshot'] = call( 'get_frozen_pricing_snapshot', wc_get_order( $o->get_id() ), $item, $data, wc_get_product( $F->get_id() ), 1 );
t_eq( '50.00', $data['pricing_snapshot']['delta_total_incl'], 'delta for a 150 incl food item vs 100 incl original = 50' );
$item->update_meta_data( '_lp_missing_data', $data ); $item->save();
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
$o = wc_get_order( $o->get_id() );
$alt = null;
foreach ( $o->get_items() as $it ) { if ( $it->get_product_id() === $F->get_id() ) { $alt = $it; } }
t_eq( 100.0, (float) $alt->get_total() + (float) $alt->get_total_tax(), 'food line keeps the 100 gross the customer paid' );
t_eq( 13.04, (float) $alt->get_total_tax(), 'food line VAT re-split at 15% (100 gross -> 13.04)' );
t_eq( array( $food_rate ), array_keys( $alt->get_taxes()['total'] ), 'food line VAT booked on the 15% rate' );
t_eq( 200.0, (float) $o->get_total(), 'order total unchanged' );
$sur = wc_get_orders( array( 'parent' => $o->get_id(), 'type' => 'shop_order', 'limit' => -1 ) );
t_eq( 1, count( $sur ), 'surcharge order created' );
if ( $sur ) {
	t_eq( 50.0, (float) $sur[0]->get_total(), 'surcharge = 50 gross' );
	t_eq( 6.52, (float) $sur[0]->get_total_tax(), 'surcharge VAT at 15% (50 gross -> 6.52)' );
}


// ---------- Fixes from the verification passes ----------
echo "\n[Verify] Unpaid orders keep reducing stock at payment\n";
$P1 = make_product( 'Ubetalt-A', '100', 50 );
$P2 = make_product( 'Ubetalt-B', '100', 50 );
$P3 = make_product( 'Ubetalt-alt', '100', 50 );
$o = wc_create_order();
$o->set_billing_email( 'kunde@example.com' ); $o->set_billing_country( 'NO' );
$o->add_product( wc_get_product( $P1->get_id() ), 3 );
$o->add_product( wc_get_product( $P2->get_id() ), 1 );
$o->calculate_totals( true ); $o->set_status( 'pending' ); $o->save();
$ids = array_keys( wc_get_order( $o->get_id() )->get_items() );
admin_save( wc_get_order( $o->get_id() ), array( $ids[0] => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $P3->get_id() ) ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $ids[0], false );
$data = call( 'get_item_data', $item ); $data['status'] = 'alt_pending'; $data['selected_alt_id'] = $P3->get_id(); $data['qty_alt'] = 1;
$item->update_meta_data( '_lp_missing_data', $data ); $item->save();
apply_via_handler( $o->get_id(), $ids[0], 'alternative', 'replace' );
t_eq( 50, wc_get_product( $P2->get_id() )->get_stock_quantity(), 'unpaid order: untouched line not reduced yet' );
wc_get_order( $o->get_id() )->payment_complete();
t_eq( 49, wc_get_product( $P2->get_id() )->get_stock_quantity(), 'payment reduces stock for the untouched line' );
t_eq( 48, wc_get_product( $P1->get_id() )->get_stock_quantity(), 'payment reduces the shrunk line by its new quantity (2)' );
t_eq( 49, wc_get_product( $P3->get_id() )->get_stock_quantity(), 'payment reduces the alternative line' );

echo "\n[Verify] No rounding drift\n";
$R = make_product( 'Pris 99,99', '99.99' );
$R2 = make_product( 'Pris 99,99 alt', '99.99' );
$o = make_order( $R, 3 );
$iid = first_item_id( $o );
t_eq( 299.97, (float) $o->get_total(), '3 x 99.99 = 299.97' );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $R2->get_id() ) ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid, false );
$data = call( 'get_item_data', $item ); $data['status'] = 'alt_pending'; $data['selected_alt_id'] = $R2->get_id(); $data['qty_alt'] = 1;
$item->update_meta_data( '_lp_missing_data', $data ); $item->save();
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
$o = wc_get_order( $o->get_id() );
t_eq( 299.97, (float) $o->get_total(), 'same-priced swap keeps 299.97' );
t_eq( 59.99, (float) $o->get_total_tax(), 'same-priced swap keeps VAT 59.99' );
$o = make_order( $R, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid, false );
$data = call( 'get_item_data', $item ); $data['status'] = 'delete_pending';
$item->update_meta_data( '_lp_missing_data', $data ); $item->save();
apply_via_handler( $o->get_id(), $iid, 'delete', 'reduce' );
t_eq( 199.98, (float) wc_get_order( $o->get_id() )->get_total(), 'reduce 1 of 3 x 99.99 = 199.98' );
update_option( 'woocommerce_price_num_decimals', 0 );
$Z = make_product( 'Pris 99 kr', '99' );
$o = make_order( $Z, 3 );
$iid = first_item_id( $o );
t_eq( 297.0, (float) $o->get_total(), '0 decimals: 3 x 99 = 297' );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid, false );
$data = call( 'get_item_data', $item ); $data['status'] = 'delete_pending';
$item->update_meta_data( '_lp_missing_data', $data ); $item->save();
apply_via_handler( $o->get_id(), $iid, 'delete', 'reduce' );
t_eq( 198.0, (float) wc_get_order( $o->get_id() )->get_total(), '0 decimals: reduce 1 of 3 x 99 = 198' );
update_option( 'woocommerce_price_num_decimals', 2 );

echo "\n[Verify] Refunded units are not refunded again\n";
$o = make_order( $A, 5 );
$iid = first_item_id( $o );
$line = wc_get_order( $o->get_id() )->get_item( $iid, false );
$taxes = $line->get_taxes()['total'];
$rate = key( $taxes );
wc_create_refund( array( 'amount' => 200, 'order_id' => $o->get_id(), 'line_items' => array( $iid => array( 'qty' => 2, 'refund_total' => 160, 'refund_tax' => array( $rate => 40 ) ) ) ) );
admin_save( wc_get_order( $o->get_id() ), array( $iid => array( 'missing' => '1', 'qty_missing' => '0', 'propose_delete' => '1' ) ) );
t_eq( 3, item_data( $o->get_id(), $iid )['qty_missing'], 'missing qty defaults to the 3 units not refunded' );
$item = wc_get_order( $o->get_id() )->get_item( $iid, false );
$data = call( 'get_item_data', $item ); $data['status'] = 'delete_pending';
$item->update_meta_data( '_lp_missing_data', $data ); $item->save();
apply_via_handler( $o->get_id(), $iid, 'delete', 'refund' );
t_eq( 500.0, (float) wc_get_order( $o->get_id() )->get_total_refunded(), 'total refunded is the line value (500), not 700' );

echo "\n[Verify] Escalation without a working reminder email\n";
update_option( 'woocommerce_lp_missing_customer_reminder_settings', array( 'enabled' => 'no' ) );
$o = make_order( $A, 1 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid, false );
$data = call( 'get_item_data', $item ); $data['first_missing_at'] = time() - 30 * DAY_IN_SECONDS;
$item->update_meta_data( '_lp_missing_data', $data ); $item->save();
call( 'send_order_reminder', wc_get_order( $o->get_id() ) );
t_ok( item_data( $o->get_id(), $iid )['needs_attention'], 'old unanswered line escalates although the reminder is disabled' );
update_option( 'woocommerce_lp_missing_customer_reminder_settings', array( 'enabled' => 'yes' ) );

echo "\n[Verify] Tax address and tax status\n";
$o = wc_create_order();
$o->set_billing_email( 'kunde@example.com' ); $o->set_billing_country( 'SE' ); $o->set_shipping_country( 'SE' );
$o->add_product( wc_get_product( $A->get_id() ), 2 );
$ship = new WC_Order_Item_Shipping(); $ship->set_method_id( 'local_pickup' ); $ship->set_method_title( 'Hent selv' ); $ship->set_total( 0 );
$o->add_item( $ship );
$o->calculate_totals( true ); $o->set_status( 'processing' ); $o->save();
$o = wc_get_order( $o->get_id() );
t_eq( 40.0, (float) $o->get_total_tax(), 'local pickup: WooCommerce taxes at the shop base (25%)' );
$iid = first_item_id( $o );
t_eq( 20.0, call( 'get_product_unit_prices_for_order', $o, wc_get_product( $A->get_id() ) )['incl'] - call( 'get_product_unit_prices_for_order', $o, wc_get_product( $A->get_id() ) )['excl'], 'local pickup: alternative priced with base VAT (20 of 100)' );
$FF = wc_get_product( $F->get_id() );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $FF->get_id() ) ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid, false );
$data = call( 'get_item_data', $item ); $data['status'] = 'alt_pending'; $data['selected_alt_id'] = $FF->get_id(); $data['qty_alt'] = 1;
$item->update_meta_data( '_lp_missing_data', $data ); $item->save();
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
$o = wc_get_order( $o->get_id() );
$alt = null; foreach ( $o->get_items() as $it ) { if ( $it->get_product_id() === $FF->get_id() ) { $alt = $it; } }
t_eq( 13.04, (float) $alt->get_total_tax(), 'local pickup: 15% alternative keeps VAT (13.04 of 100)' );
$N = make_product( 'Gavekort', '100' ); $N->set_tax_status( 'none' ); $N->save();
$o = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $N->get_id() ) ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $iid, false );
$data = call( 'get_item_data', $item ); $data['status'] = 'alt_pending'; $data['selected_alt_id'] = $N->get_id(); $data['qty_alt'] = 1;
$item->update_meta_data( '_lp_missing_data', $data ); $item->save();
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
$o = wc_get_order( $o->get_id() );
$alt = null; foreach ( $o->get_items() as $it ) { if ( $it->get_product_id() === $N->get_id() ) { $alt = $it; } }
t_eq( 0.0, (float) $alt->get_total_tax(), 'non-taxable alternative carries no VAT' );
t_eq( 100.0, (float) $alt->get_total(), 'non-taxable alternative keeps the 100 gross as net' );
t_eq( 200.0, (float) $o->get_total(), 'order total unchanged' );

echo "\n[Verify] Locks and flags when lines/orders leave the flow\n";
$L = make_product( 'Laas', '100', 50 );
$o = wc_create_order();
$o->set_billing_email( 'kunde@example.com' ); $o->set_billing_country( 'NO' );
$o->add_product( wc_get_product( $L->get_id() ), 2 );
$o->calculate_totals( true ); $o->set_status( 'pending' ); $o->save();
$iid = first_item_id( $o );
admin_save( wc_get_order( $o->get_id() ), array( $iid => array( 'missing' => '1', 'qty_missing' => '2' ) ) );
t_eq( 48, wc_get_product( $L->get_id() )->get_stock_quantity(), 'lock taken on an unpaid order' );
wc_get_order( $o->get_id() )->update_status( 'failed' );
t_eq( 50, wc_get_product( $L->get_id() )->get_stock_quantity(), 'failed payment releases the lock' );
t_eq( 'pending', item_data( $o->get_id(), $iid )['status'], 'failed payment keeps the case (payment can be retried)' );
wc_get_order( $o->get_id() )->update_status( 'pending' );
t_eq( 48, wc_get_product( $L->get_id() )->get_stock_quantity(), 'retried payment takes the lock again' );
t_ok( call( 'get_next_reminder_timestamp', wc_get_order( $o->get_id() ) ) > 0, 'reminders resume after the retry' );
$o = make_order( $A, 1 );
$o->add_product( wc_get_product( $C->get_id() ), 1 ); $o->calculate_totals( true );
$ids = array_keys( wc_get_order( $o->get_id() )->get_items() );
admin_save( wc_get_order( $o->get_id() ), array( $ids[0] => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
t_eq( 'yes', wc_get_order( $o->get_id() )->get_meta( '_lp_missing_has_open' ), 'order open' );
wc_delete_order_item( $ids[0] );
t_eq( '', wc_get_order( $o->get_id() )->get_meta( '_lp_missing_has_open' ), 'deleting the missing line in the editor clears the open flag' );
$o = make_order( $L, 2 );
$iid = first_item_id( $o );
$base = wc_get_product( $L->get_id() )->get_stock_quantity();
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2' ) ) );
t_eq( $base - 2, wc_get_product( $L->get_id() )->get_stock_quantity(), 'lock taken' );
wc_get_order( $o->get_id() )->delete( true );
t_eq( $base, wc_get_product( $L->get_id() )->get_stock_quantity(), 'permanently deleting the order releases the lock' );
t_ok( ! wp_next_scheduled( 'lp_missing_send_reminder', array( $o->get_id(), $iid ) ) && ! LP_Missing_Scheduler::next( 'lp_missing_order_reminder', array( $o->get_id() ) ), 'deleted order has no reminder left' );

echo "\n[Verify] Portal input and access\n";
$o = make_order( $A, 1 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
portal_jar_reset();
$res  = portal_follow( portal_http( call( 'get_magic_link_for_order', $o ) ) );
$res  = portal_http( portal_clean_url( $o ), array( 'lp_oid' => $o->get_id(), 'lp_missing_portal_action' => 'verify', 'lp_missing_token' => extract_field( $res['html'], 'lp_missing_token' ), 'lp_missing_verify_email' => array( 'x' ) ) );
t_ok( is_string( $res['html'] ) && false !== strpos( $res['html'], 'lp_missing_verify_email' ), 'array-valued email field is handled without a fatal error' );
$u = wp_insert_user( array( 'user_login' => 'bidrag' . wp_rand( 1, 99999 ), 'user_pass' => 'x', 'user_email' => 'kunde@example.com', 'role' => 'contributor' ) );
if ( ! is_wp_error( $u ) ) {
	wp_set_current_user( $u );
	$_GET = array(); $_POST = array();
	$html = call( 'render_shortcode', array( 'order_id' => $o->get_id(), 'email' => 'kunde@example.com' ) );
	t_ok( false !== strpos( $html, 'lp-missing-portal-error' ), 'matching account email alone does not grant attribute access' );
}
wp_set_current_user( 1 );

echo "\n[Verify] One apply per order at a time\n";
$o = make_order( $A, 2 );
$o->add_product( wc_get_product( $C->get_id() ), 2 ); $o->calculate_totals( true );
$ids = array_keys( wc_get_order( $o->get_id() )->get_items() );
admin_save( wc_get_order( $o->get_id() ), array( $ids[1] => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
$item = wc_get_order( $o->get_id() )->get_item( $ids[1], false );
$data = call( 'get_item_data', $item ); $data['status'] = 'delete_pending';
$item->update_meta_data( '_lp_missing_data', $data ); $item->save();
call( 'acquire_apply_lock', $o->get_id() );
$redirect = apply_via_handler( $o->get_id(), $ids[1], 'delete', 'reduce' );
t_ok( false !== strpos( $redirect, 'lp_missing_apply=error' ), 'apply on another line of a locked order is refused' );
call( 'release_apply_lock', $o->get_id() );

echo "\n[Verify] Upgrade repairs old open cases\n";
$U = make_product( 'Uten lager', '100' ); $U->set_manage_stock( false ); $U->save();
$o = make_order( $U, 4 );
$iid = first_item_id( $o );
$item = wc_get_order( $o->get_id() )->get_item( $iid, false );
$item->update_meta_data( '_lp_missing_data', array( 'missing' => true, 'qty_missing' => 0, 'status' => 'pending', 'stock_locked_qty' => 2 ) );
$item->save();
$ord = wc_get_order( $o->get_id() ); $ord->update_meta_data( '_lp_missing_has_data', 'yes' ); $ord->save();
call( 'upgrade_normalize_open_cases' );
$d = item_data( $o->get_id(), $iid );
t_eq( 4, $d['qty_missing'], 'old case with qty 0 gets the line quantity' );
t_eq( 0, $d['stock_locked_qty'], 'phantom lock on an unmanaged product is dropped' );


t_summary();
