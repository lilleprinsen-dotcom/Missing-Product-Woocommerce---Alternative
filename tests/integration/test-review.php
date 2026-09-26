<?php
// Regression tests for findings of the final review (stale screens, deadline safety, concurrency).
// Run with: wp eval-file tests/integration/test-review.php (see tests/README.md).
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/portal-helpers.php';

function review_settings( $changes ) {
	$s = get_option( 'lp_missing_settings', array() );
	update_option( 'lp_missing_settings', array_merge( is_array( $s ) ? $s : array(), $changes ) );
	LP_Missing_Settings::flush();
}
function review_set_line( $order_id, $item_id, $changes ) {
	$item = wc_get_order( $order_id )->get_item( $item_id, false );
	$item->update_meta_data( '_lp_missing_data', array_merge( call( 'get_item_data', $item ), $changes ) );
	$item->save();
}
function review_run_deadline( $order_id ) {
	return LP_Missing_Apply_Service::apply_due_deadline_actions( $order_id, time() + 10 * DAY_IN_SECONDS );
}
$original_settings = get_option( 'lp_missing_settings', array() );
review_settings( array( 'deadline_action' => 'refund', 'decision_deadline_days' => 2 ) );
update_option( 'woocommerce_lp_missing_customer_email_settings', array( 'enabled' => 'yes' ) );

echo "\n[Review] Deadline only after the customer was emailed\n";
update_option( 'woocommerce_lp_missing_customer_email_settings', array( 'enabled' => 'no' ) );
WC()->mailer()->get_emails()['lp_missing_customer_email']->enabled = 'no';
$o   = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$d = item_data( $o->get_id(), $iid );
t_eq( 0, $d['deadline_at'], 'no deadline while the customer was not emailed' );
t_eq( 0, $d['notified_at'], 'not marked as notified' );
$r = review_run_deadline( $o->get_id() );
t_eq( 0.0, (float) wc_get_order( $o->get_id() )->get_total_refunded(), 'nothing refunded for a customer who was never asked' );
update_option( 'woocommerce_lp_missing_customer_email_settings', array( 'enabled' => 'yes' ) );
WC()->mailer()->get_emails()['lp_missing_customer_email']->enabled = 'yes';
$o   = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$d = item_data( $o->get_id(), $iid );
t_ok( $d['notified_at'] > 0 && $d['deadline_at'] > $d['notified_at'], 'emailed customer gets a deadline' );
t_eq( 1, $d['notified_qty'], 'what the customer was told is recorded' );

echo "\n[Review] Deadline leaves lines changed after the notification to staff\n";
$o   = make_order( $A, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$line = wc_get_order( $o->get_id() )->get_item( $iid, false );
$rate = key( $line->get_taxes()['total'] );
wc_create_refund( array( 'amount' => 100, 'order_id' => $o->get_id(), 'line_items' => array( $iid => array( 'qty' => 1, 'refund_total' => 80, 'refund_tax' => array( $rate => 20 ) ) ) ) );
review_run_deadline( $o->get_id() );
t_eq( 100.0, (float) wc_get_order( $o->get_id() )->get_total_refunded(), 'no second refund after staff refunded the unit themselves' );
$d = item_data( $o->get_id(), $iid );
t_ok( $d['needs_attention'] && $d['auto_action_failed_at'] > 0, 'line flagged for staff instead' );
$o   = make_order( $A, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
review_set_line( $o->get_id(), $iid, array( 'qty_missing' => 2 ) );
review_run_deadline( $o->get_id() );
t_eq( 0.0, (float) wc_get_order( $o->get_id() )->get_total_refunded(), 'raised missing quantity (not told to the customer) is not refunded automatically' );

echo "\n[Review] A stale order screen does not reopen a resolved line\n";
$o   = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$rendered_rev = LP_Missing_Line::get_revision( wc_get_order( $o->get_id() )->get_item( $iid, false ) );
$r            = review_run_deadline( $o->get_id() );
t_eq( 'delete_applied', item_data( $o->get_id(), $iid )['status'], 'deadline job resolved the line' );
$refunded     = (float) wc_get_order( $o->get_id() )->get_total_refunded();
$GLOBALS['lp_mails'] = array();
reset_request();
wp_set_current_user( 1 );
$_POST = array(
	'lp_missing_nonce' => wp_create_nonce( 'lp_missing_metabox' ),
	'lp_missing_items' => array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'notes' => '', 'internal_notes' => '', 'rev' => $rendered_rev ) ),
	'order_item_id'    => array( $iid ),
);
LP_Missing_Admin_Metabox::protect_stale_lines( $o->get_id() );
t_ok( ! in_array( $iid, array_map( 'absint', $_POST['order_item_id'] ), true ), 'stale line removed from the item fields WooCommerce is about to save' );
LP_Missing_Admin_Metabox::save_metabox( $o->get_id() );
t_eq( 'delete_applied', item_data( $o->get_id(), $iid )['status'], 'stale save leaves the resolved line alone' );
t_eq( 0, count( $GLOBALS['lp_mails'] ), 'no new customer email' );
review_run_deadline( $o->get_id() );
t_eq( $refunded, (float) wc_get_order( $o->get_id() )->get_total_refunded(), 'no second refund' );
t_ok( is_array( get_transient( 'lp_missing_stale_1' ) ), 'staff get a notice about the skipped line' );
delete_transient( 'lp_missing_stale_1' );
// A current screen still saves normally.
$o   = make_order( $A, 2 );
$iid = first_item_id( $o );
reset_request();
$_POST = array(
	'lp_missing_nonce' => wp_create_nonce( 'lp_missing_metabox' ),
	'lp_missing_items' => array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'notes' => '', 'internal_notes' => '', 'rev' => LP_Missing_Line::get_revision( wc_get_order( $o->get_id() )->get_item( $iid, false ) ) ) ),
);
LP_Missing_Admin_Metabox::save_metabox( $o->get_id() );
t_eq( 'pending', item_data( $o->get_id(), $iid )['status'], 'current screen saves normally' );

echo "\n[Review] Reminder never overwrites a decision saved at the same time\n";
$o   = make_order( $A, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ) ) ) );
$hijack = function ( $null ) use ( $o, $iid, $B ) {
	review_set_line( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'selected_alt_id' => $B->get_id(), 'qty_alt' => 1 ) );
	return $null;
};
add_filter( 'pre_wp_mail', $hijack, 5 );
LP_Missing_Lifecycle::send_order_reminder( wc_get_order( $o->get_id() ) );
remove_filter( 'pre_wp_mail', $hijack, 5 );
$d = item_data( $o->get_id(), $iid );
t_eq( 'alt_pending', $d['status'], 'decision saved during the reminder survives' );
t_eq( $B->get_id(), $d['selected_alt_id'], 'chosen alternative survives' );
// A reminder that runs while the order is locked waits instead of writing.
$o   = make_order( $A, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
review_settings( array( 'reminder_window_start' => 0, 'reminder_window_end' => 24 ) );
call( 'acquire_apply_lock', $o->get_id() );
$GLOBALS['lp_mails'] = array();
$before              = item_data( $o->get_id(), $iid )['reminder_count'];
LP_Missing_Lifecycle::handle_order_reminder( $o->get_id() );
t_eq( 0, count( $GLOBALS['lp_mails'] ), 'reminder waits while the order is locked' );
t_eq( $before, item_data( $o->get_id(), $iid )['reminder_count'], 'nothing written while locked' );
t_ok( LP_Missing_Scheduler::next( LP_Missing_Scheduler::REMINDER_HOOK, array( $o->get_id() ) ) > time(), 'reminder retried later' );
call( 'release_apply_lock', $o->get_id() );
review_settings( array( 'reminder_window_start' => 9, 'reminder_window_end' => 20 ) );

echo "\n[Review] One staff email for several answers in one portal save\n";
review_settings( array( 'notify_staff_on_decision' => 'yes', 'staff_notification_email' => 'lager@example.com' ) );
update_option( 'woocommerce_lp_missing_staff_decision_settings', array( 'enabled' => 'yes' ) );
$o = make_order( $A, 2 );
$o->add_product( wc_get_product( $C->get_id() ), 2 );
$o->calculate_totals( true );
$ids = array_keys( wc_get_order( $o->get_id() )->get_items() );
admin_save( wc_get_order( $o->get_id() ), array( $ids[0] => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ) ), $ids[1] => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
$GLOBALS['lp_mails'] = array();
portal_jar_reset();
portal_login( wc_get_order( $o->get_id() ) );
portal_save( wc_get_order( $o->get_id() ), array( $ids[0] => 'alt:' . $B->get_id(), $ids[1] => 'delete' ), array( $ids[0] => 1 ) );
$staff = array_values( array_filter( $GLOBALS['lp_mails'], function ( $m ) { return false !== strpos( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'], 'lager@example.com' ); } ) );
t_eq( 'alt_pending', item_data( $o->get_id(), $ids[0] )['status'], 'first answer saved' );
t_eq( 'delete_pending', item_data( $o->get_id(), $ids[1] )['status'], 'second answer saved' );
t_eq( 1, count( $staff ), 'one staff email for both answers' );

echo "\n[Review] Remove-from-totals on a paid order warns staff\n";
review_settings( array( 'deadline_action' => 'reduce' ) );
$o   = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
review_run_deadline( $o->get_id() );
$notes = wc_get_order_notes( array( 'order_id' => $o->get_id() ) );
$warned = false;
foreach ( $notes as $n ) {
	$warned = $warned || false !== strpos( $n->content, 'customer has paid for the removed quantity' );
}
t_ok( $warned, 'paid order: staff are told to release or refund the removed amount' );

review_settings( array( 'deadline_action' => 'refund' ) );

echo "\n[Review 2] Recording the notification keeps a choice saved while the email was sent\n";
$o   = make_order( $A, 3 );
$iid = first_item_id( $o );
$hijack = function ( $null ) use ( $o, $iid, $B ) {
	review_set_line( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'selected_alt_id' => $B->get_id(), 'qty_alt' => 1, 'decision_made_at' => time() ) );
	return $null;
};
add_filter( 'pre_wp_mail', $hijack, 5 );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ) ) ) );
remove_filter( 'pre_wp_mail', $hijack, 5 );
$d = item_data( $o->get_id(), $iid );
t_eq( 'alt_pending', $d['status'], 'choice saved during the initial email survives' );
t_eq( $B->get_id(), $d['selected_alt_id'], 'chosen alternative survives' );
t_ok( $d['notified_at'] > 0 && 1 === $d['notified_qty'], 'notification still recorded' );

echo "\n[Review 2] An apply link only applies the decision staff were shown\n";
$o   = make_order( $A, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id(), $C->get_id() ) ) ) );
portal_jar_reset();
portal_login( wc_get_order( $o->get_id() ) );
portal_save( wc_get_order( $o->get_id() ), array( $iid => 'alt:' . $C->get_id() ), array( $iid => 1 ) );
t_eq( $C->get_id(), item_data( $o->get_id(), $iid )['selected_alt_id'], 'customer chose the cheaper alternative' );
$shown = LP_Missing_Line::get_decision_key( item_data( $o->get_id(), $iid ) );
$box   = LP_Missing_Admin_Metabox::get_apply_url( wc_get_order( $o->get_id() ), $iid, 'alternative', 'replace' );
t_ok( false !== strpos( $box, 'decision=' . $shown ), 'apply link carries the decision shown' );
sleep( 1 );
portal_save( wc_get_order( $o->get_id() ), array( $iid => 'alt:' . $B->get_id() ), array( $iid => 1 ) );
t_eq( $B->get_id(), item_data( $o->get_id(), $iid )['selected_alt_id'], 'customer then switched to the dearer alternative' );
$orders_before = count( wc_get_orders( array( 'parent' => $o->get_id(), 'limit' => -1 ) ) );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace', $shown );
$d = item_data( $o->get_id(), $iid );
t_eq( 'alt_pending', $d['status'], 'old link applies nothing' );
t_eq( $orders_before, count( wc_get_orders( array( 'parent' => $o->get_id(), 'limit' => -1 ) ) ), 'no surcharge order from the old link' );
$notice = get_transient( 'lp_missing_notice_1' );
t_ok( is_array( $notice ) && 'error' === $notice['status'] && false !== strpos( $notice['message'], 'changed after this page was loaded' ), 'staff are told the choice changed' );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
t_eq( 'alt_applied', item_data( $o->get_id(), $iid )['status'], 'a link for the current decision applies it' );

echo "\n[Review 2] Email confirmation limits are per browser session\n";
$o = make_order( $A, 2 );
admin_save( $o, array( first_item_id( $o ) => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$o = wc_get_order( $o->get_id() );
portal_jar_reset();
$page  = portal_follow( portal_http( call( 'get_magic_link_for_order', $o ) ) );
$token = extract_field( $page['html'], 'lp_missing_token' );
$verify = function ( $email ) use ( $o, &$token ) {
	return portal_http( portal_clean_url( $o ), array( 'lp_oid' => $o->get_id(), 'lp_missing_portal_action' => 'verify', 'lp_missing_token' => $token, 'lp_missing_verify_email' => $email ) );
};
for ( $i = 0; $i < 10; $i++ ) {
	$res = $verify( 'feil' . $i . '@example.com' );
}
$res = $verify( $o->get_billing_email() );
t_ok( false !== strpos( $res['html'], 'For mange forsøk' ), 'the guessing browser is blocked after 10 failures' );
$attacker_jar = $GLOBALS['lp_jar'];
portal_jar_reset();
$res = portal_login( $o );
t_ok( false === strpos( $res['html'], 'For mange forsøk' ) && false === strpos( $res['html'], 'lp_missing_verify_email' ), 'the customer in another browser can still confirm' );
t_ok( ! LP_Missing_Util::acquire_lock( 'lp_missing_verifying_' . $o->get_id() ) || ( LP_Missing_Util::release_lock( 'lp_missing_verifying_' . $o->get_id() ) || true ), 'confirmation lock is released after each attempt' );
// Per-order cap across sessions.
for ( $s = 0; $s < 3; $s++ ) {
	portal_jar_reset();
	$page  = portal_follow( portal_http( call( 'get_magic_link_for_order', $o ) ) );
	$token = extract_field( $page['html'], 'lp_missing_token' );
	for ( $i = 0; $i < 10; $i++ ) {
		$verify( 'x' . $s . $i . '@example.com' );
	}
}
portal_jar_reset();
$page  = portal_follow( portal_http( call( 'get_magic_link_for_order', $o ) ) );
$token = extract_field( $page['html'], 'lp_missing_token' );
$res   = $verify( $o->get_billing_email() );
t_ok( false !== strpos( $res['html'], 'For mange forsøk' ), 'per-order cap stops guessing across many sessions' );
delete_transient( 'lp_missing_portal_vfo_' . $o->get_id() );

echo "\n[Review 2] Signing key ring created once under concurrency\n";
$ring_backup = get_option( LP_Missing_Magic_Link::OPTION_KEYS );
delete_option( LP_Missing_Magic_Link::OPTION_KEYS );
$rival   = array( 'current' => 'krival01', 'keys' => array( 'krival01' => array( 'secret' => str_repeat( 'r', 64 ), 'created' => time(), 'retired' => 0 ) ) );
$raced   = false;
$racer   = function ( $query ) use ( &$raced, $rival ) {
	global $wpdb;
	if ( ! $raced && false !== strpos( $query, 'INSERT IGNORE' ) && false !== strpos( $query, LP_Missing_Magic_Link::OPTION_KEYS ) ) {
		$raced = true;
		// Another request created the ring a moment earlier.
		$wpdb->insert( $wpdb->options, array( 'option_name' => LP_Missing_Magic_Link::OPTION_KEYS, 'option_value' => maybe_serialize( $rival ), 'autoload' => 'no' ) );
	}
	return $query;
};
add_filter( 'query', $racer );
$ring = LP_Missing_Magic_Link::get_keyring();
remove_filter( 'query', $racer );
t_ok( $raced, 'race simulated' );
t_eq( 'krival01', $ring['current'], 'the losing request uses the ring that was stored first' );
wp_cache_delete( LP_Missing_Magic_Link::OPTION_KEYS, 'options' );
t_eq( 'krival01', get_option( LP_Missing_Magic_Link::OPTION_KEYS )['current'], 'stored ring not overwritten' );
update_option( LP_Missing_Magic_Link::OPTION_KEYS, $ring_backup, false );

echo "\n[Review 2] Settings reject arrays and non-page portal pages\n";
$fields = LP_Missing_Settings::get_fields();
t_eq( 30, LP_Missing_Settings::sanitize_field( $fields['magic_link_ttl_days'], array( '5' ) ), 'array value falls back to the default' );
t_eq( 0, LP_Missing_Settings::sanitize_field( $fields['portal_page_id'], $A->get_id() ), 'a product cannot be the portal page' );
$pg = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Portal test', 'post_content' => '[lp_missing_items]' ) );
t_eq( $pg, LP_Missing_Settings::sanitize_field( $fields['portal_page_id'], (string) $pg ), 'a page is accepted' );
wp_delete_post( $pg, true );
$before = LP_Missing_Settings::get_settings( true );
reset_request();
wp_set_current_user( 1 );
$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'lp_missing_settings' ), 'lp_magic_link_ttl_days' => array( '5' ), 'lp_deadline_action' => 'refund', 'lp_enable_stock_lock' => 'yes', 'lp_enable_stock_notes' => 'yes', 'lp_show_stock_preview' => 'yes', 'lp_enable_logging' => 'yes', 'lp_notify_staff_on_decision' => 'yes' );
try {
	LP_Missing_Admin_Settings_Page::handle_settings_save();
} catch ( LP_Redirect $r ) {
}
t_eq( $before['magic_link_ttl_days'], LP_Missing_Settings::get_settings( true )['magic_link_ttl_days'], 'posted array keeps the current value' );

echo "\n[Review 2] Portal does not reveal which orders exist and is closed for closed orders\n";
portal_jar_reset();
$res_existing = portal_http( add_query_arg( array( 'oid' => $o->get_id(), 'lp_preview' => 'x' ), home_url( '/' ) ) );
$res_missing  = portal_http( add_query_arg( array( 'oid' => 99999999, 'lp_preview' => 'x' ), home_url( '/' ) ) );
t_eq( $res_existing['html'], $res_missing['html'], 'same answer for an existing and a non-existing order' );
$o = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ) ) ) );
portal_jar_reset();
portal_login( wc_get_order( $o->get_id() ) );
$page  = portal_http( portal_clean_url( $o ) );
$token = extract_field( $page['html'], 'lp_missing_token' );
wp_trash_post( $o->get_id() );
if ( LP_Missing_Util::hpos_enabled() ) {
	wc_get_order( $o->get_id() )->delete( false );
}
$GLOBALS['lp_mails'] = array();
$res = portal_save( wc_get_order( $o->get_id() ), array( $iid => 'alt:' . $B->get_id() ), array( $iid => 1 ), $token );
t_eq( 'pending', item_data( $o->get_id(), $iid )['status'], 'no decision saved on a trashed order' );
t_eq( 0, count( $GLOBALS['lp_mails'] ), 'no staff email' );
t_ok( false !== strpos( portal_http( portal_clean_url( $o ) )['html'], 'Denne ordren er avsluttet' ), 'customer sees that the order is closed' );

echo "\n[Review 2] Pages that show order data without an order in the URL are private\n";
$pg = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Mine valg', 'post_content' => '[lp_missing_items]' ) );
$GLOBALS['lp_private_headers'] = array();
LP_Missing_Portal::reset_request_state();
$_GET = array();
$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $pg ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$wp_done = isset( $GLOBALS['wp_actions']['wp'] ) ? $GLOBALS['wp_actions']['wp'] : 0;
$GLOBALS['wp_actions']['wp'] = max( 1, $wp_done ); // As on a front-end request, after the main query ran.
LP_Missing_Portal::on_template_redirect();
t_ok( ! empty( $GLOBALS['lp_private_headers'] ), 'portal page without an order gets the private headers' );
LP_Missing_Portal::reset_request_state();
$GLOBALS['lp_private_headers'] = array();
$GLOBALS['wp_query']           = new WP_Query( array( 'p' => 1 ) );
$GLOBALS['wp_the_query']       = $GLOBALS['wp_query'];
LP_Missing_Portal::on_template_redirect();
t_ok( empty( $GLOBALS['lp_private_headers'] ), 'other pages are left alone' );
$GLOBALS['wp_actions']['wp'] = $wp_done;
wp_reset_query();
wp_delete_post( $pg, true );

echo "\n[Review 2] Order screen views do not log customer links\n";
$log_dir = trailingslashit( WC_LOG_DIR );
$count_issued = function () use ( $log_dir ) {
	$n = 0;
	foreach ( glob( $log_dir . 'lp-missing-*.log' ) as $f ) {
		$n += substr_count( file_get_contents( $f ), 'Customer link issued.' );
	}
	return $n;
};
$before = $count_issued();
LP_Missing_Magic_Link::get_magic_link_for_order( wc_get_order( $o->get_id() ) );
t_eq( $before, $count_issued(), 'a link only shown to staff is not logged as issued' );

update_option( 'lp_missing_settings', $original_settings );
LP_Missing_Settings::flush();
t_summary();
