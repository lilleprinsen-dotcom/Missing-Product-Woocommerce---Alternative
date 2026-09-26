<?php
// Admin screen tests: ready flag, order list views/column, history line, customer link tools, AJAX endpoints.
// Run with: wp eval-file tests/integration/test-admin.php (see tests/README.md), with HPOS on and off.
defined( 'ABSPATH' ) || exit; // Runs inside WordPress (wp eval-file), never over HTTP.
require __DIR__ . '/bootstrap.php';

$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
echo 'HPOS: ' . ( $hpos ? 'on' : 'off' ) . "\n";

// ---------- Helpers ----------
class LP_Die extends Exception {}
function lp_test_die_handler( $message, $title = '', $args = array() ) {
	$code = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 0;
	throw new LP_Die( is_string( $message ) ? $message : '', $code );
}
add_filter( 'wp_die_handler', function () { return 'lp_test_die_handler'; }, 999 );
add_filter( 'wp_die_ajax_handler', function () { return 'lp_test_die_handler'; }, 999 );

$GLOBALS['lp_logs'] = array();
add_filter(
	'woocommerce_logger_log_message',
	function ( $message, $level, $context ) {
		if ( isset( $context['source'] ) && 'lp-missing' === $context['source'] ) {
			$GLOBALS['lp_logs'][ $level . ' ' . $message ] = true; // Keyed: every log handler sees the message.
		}
		return null;
	},
	10,
	3
);
function logged( $needle ) {
	foreach ( array_keys( $GLOBALS['lp_logs'] ) as $line ) {
		if ( false !== strpos( $line, $needle ) ) {
			return $line;
		}
	}
	return '';
}

// Runs an AJAX action like admin-ajax.php does and returns the decoded JSON response.
function admin_ajax( $action, $post, $user = 1 ) {
	reset_request();
	wp_set_current_user( $user );
	$_POST    = $post;
	$_REQUEST = $post;
	add_filter( 'wp_doing_ajax', '__return_true' );
	ob_start();
	try {
		do_action( 'wp_ajax_' . $action );
	} catch ( LP_Die $e ) {
		// wp_send_json() ends with wp_die().
	}
	$out = ob_get_clean();
	remove_filter( 'wp_doing_ajax', '__return_true' );
	wp_set_current_user( 1 );
	return json_decode( $out, true );
}

// Runs an admin-post handler; returns array( 'redirect' => url ) or array( 'die' => message, 'code' => status ).
function admin_post( $callback, $get, $user = 1 ) {
	reset_request();
	wp_set_current_user( $user );
	$_GET     = $get;
	$_REQUEST = $get;
	$result   = array();
	try {
		call_user_func( $callback );
	} catch ( LP_Redirect $r ) {
		$result = array( 'redirect' => $r->getMessage() );
	} catch ( LP_Die $d ) {
		$result = array( 'die' => $d->getMessage(), 'code' => $d->getCode() );
	}
	wp_set_current_user( 1 );
	return $result;
}

function render_box( $order ) {
	wp_set_current_user( 1 );
	ob_start();
	LP_Missing_Admin_Metabox::render_metabox( wc_get_order( $order->get_id() ) );
	return ob_get_clean();
}

function set_line_status( $order, $item_id, $changes ) {
	$order = wc_get_order( $order->get_id() );
	$item  = $order->get_item( $item_id, false );
	$old   = LP_Missing_Line::get_item_data( $item );
	$new   = array_merge( $old, $changes );
	$item->update_meta_data( '_lp_missing_data', $new );
	$item->save();
	// What the portal and the apply service do after changing a line.
	do_action( 'lp_missing_item_updated', $order, $item_id, $new, $old );
}

function flag( $order, $key ) {
	return wc_get_order( $order->get_id() )->get_meta( $key );
}

function make_user( $role ) {
	$id = wp_insert_user( array( 'user_login' => $role . wp_rand( 1, 999999 ), 'user_pass' => wp_generate_password(), 'user_email' => $role . wp_rand( 1, 999999 ) . '@example.com', 'role' => $role ) );
	return is_wp_error( $id ) ? 0 : $id;
}

function restore_settings( $original ) {
	if ( false === $original ) {
		delete_option( 'lp_missing_settings' );
	} else {
		update_option( 'lp_missing_settings', $original );
	}
	LP_Missing_Settings::flush();
}

function column_html( $order ) {
	ob_start();
	LP_Missing_Admin_Orders_List::render_missing_column( 'lp_missing_status', LP_Missing_Util::hpos_enabled() ? wc_get_order( $order->get_id() ) : $order->get_id() );
	return ob_get_clean();
}

$orig_tz = get_option( 'timezone_string' );
$orig_settings = get_option( 'lp_missing_settings' );
update_option( 'timezone_string', 'Europe/Oslo' );

// ---------- S1: ready flag ----------
echo "\n[S1] Ready flag (customer answered, staff must act)\n";
$o   = make_order( $A, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $B->get_id(), $C->get_id() ) ) ) );
t_eq( '', flag( $o, '_lp_missing_ready' ), 'line waiting for the customer: no ready flag' );
t_eq( 'yes', flag( $o, '_lp_missing_has_open' ), 'order is open' );
t_eq( '_lp_missing_ready', LP_Missing_Plugin::ORDER_META_READY, 'flag key' );

set_line_status( $o, $iid, array( 'status' => 'alt_pending', 'selected_alt_id' => $B->get_id(), 'qty_alt' => 2, 'decision_made_at' => time() ) );
t_eq( 'yes', flag( $o, '_lp_missing_ready' ), 'customer chose an alternative: ready flag set' );
t_ok( LP_Missing_Orders::order_is_ready_for_staff( wc_get_order( $o->get_id() ) ), 'order_is_ready_for_staff() agrees' );

set_line_status( $o, $iid, array( 'status' => 'pending', 'selected_alt_id' => 0, 'qty_alt' => 0 ) );
t_eq( '', flag( $o, '_lp_missing_ready' ), 'back to pending: ready flag removed' );
set_line_status( $o, $iid, array( 'status' => 'delete_pending', 'propose_delete' => true ) );
t_eq( 'yes', flag( $o, '_lp_missing_ready' ), 'customer approved deletion: ready flag set' );
set_line_status( $o, $iid, array( 'status' => 'pending' ) );
set_line_status( $o, $iid, array( 'status' => 'declined' ) );
t_eq( 'yes', flag( $o, '_lp_missing_ready' ), 'customer declined: ready flag set (staff must act)' );
t_ok( LP_Missing_Orders::line_needs_staff_action( array( 'missing' => true, 'status' => 'declined' ) ), 'declined line needs staff' );
t_ok( ! LP_Missing_Orders::line_needs_staff_action( array( 'missing' => true, 'status' => 'alt_applied' ) ), 'applied line does not need staff' );
t_ok( ! LP_Missing_Orders::line_needs_staff_action( array( 'missing' => false, 'status' => 'alt_pending' ) ), 'line not missing does not need staff' );

// Saves only when a flag changes.
$saves = 0;
$count_saves = function () use ( &$saves ) {
	$saves++;
};
add_action( 'woocommerce_after_order_object_save', $count_saves );
LP_Missing_Orders::refresh_order_flags( wc_get_order( $o->get_id() ) );
t_eq( 0, $saves, 'refresh with unchanged flags does not save the order' );
$tmp = wc_get_order( $o->get_id() );
$tmp->delete_meta_data( '_lp_missing_ready' );
$tmp->save();
$saves = 0;
LP_Missing_Orders::refresh_order_flags( wc_get_order( $o->get_id() ) );
t_eq( 1, $saves, 'refresh with a changed flag saves once' );
remove_action( 'woocommerce_after_order_object_save', $count_saves );

// Applying the decision clears the flag.
set_line_status( $o, $iid, array( 'status' => 'pending' ) );
set_line_status( $o, $iid, array( 'status' => 'delete_pending' ) );
$redirect = apply_via_handler( $o->get_id(), $iid, 'delete', 'reduce' );
t_ok( false !== strpos( $redirect, 'lp_missing_apply=success' ), 'staff applied the removal' );
t_eq( '', flag( $o, '_lp_missing_ready' ), 'after applying, the ready flag is gone' );
t_ok( '' !== logged( 'Staff clicked apply.' ) && false !== strpos( logged( 'Staff clicked apply.' ), '"order_id":' . $o->get_id() ), 'apply click is logged with the order' );
t_ok( 0 === strpos( logged( 'Staff clicked apply.' ), 'info ' ) && false !== strpos( logged( 'Staff clicked apply.' ), '"result":"success"' ), 'successful apply logged as info with its result' );
$GLOBALS['lp_logs'] = array();
apply_via_handler( $o->get_id(), $iid, 'delete', 'reduce' );
t_ok( 0 === strpos( logged( 'Staff clicked apply.' ), 'warning ' ), 'failed apply (nothing left to apply) logged as warning' );

$applied_box = render_box( $o );
t_ok( false !== strpos( $applied_box, 'lp-line--done' ) && false !== strpos( $applied_box, 'Removed from the order' ), 'applied line keeps its applied status in the box' );
t_ok( (bool) preg_match( '/class="lp-line__done">.*Removed from the order · \d\d\.\d\d/', $applied_box ), 'applied line says when it was applied' );
t_ok( false === strpos( $applied_box, 'lp-missing-apply ' ), 'no apply buttons on an applied line' );

// Upgrade step backfills the flag on orders saved before it existed.
$o2   = make_order( $A, 1 );
$iid2 = first_item_id( $o2 );
admin_save( $o2, array( $iid2 => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ) ) ) );
$item = wc_get_order( $o2->get_id() )->get_item( $iid2, false );
$d    = LP_Missing_Line::get_item_data( $item );
$d['status'] = 'alt_pending';
$d['selected_alt_id'] = $B->get_id();
$item->update_meta_data( '_lp_missing_data', $d );
$item->save();
t_eq( '', flag( $o2, '_lp_missing_ready' ), 'line changed without the hook: no flag yet' );
LP_Missing_Orders::upgrade_normalize_open_cases();
t_eq( 'yes', flag( $o2, '_lp_missing_ready' ), 'upgrade step backfills the ready flag' );

// ---------- S1: order list ----------
echo "\n[S1] Order list views, filter and column\n";
$ready_ids = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order', 'meta_key' => '_lp_missing_ready', 'meta_value' => 'yes' ) );
$open_ids  = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order', 'meta_key' => '_lp_missing_has_open', 'meta_value' => 'yes' ) );
$all_ids   = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order' ) );
t_ok( in_array( $o2->get_id(), $ready_ids, true ), 'ready order found by meta_key/meta_value' );
t_eq( count( $ready_ids ), LP_Missing_Orders::count_orders_ready_for_staff(), 'ready count equals the ready orders' );
t_ok( count( $ready_ids ) < count( $all_ids ), 'ready count is not the count of all orders' );
t_eq( count( $open_ids ), LP_Missing_Orders::count_orders_with_open_missing(), 'open count still equals the open orders' );

reset_request();
$views = LP_Missing_Admin_Orders_List::add_missing_orders_view( array( 'all' => '<a>All</a>' ) );
t_ok( isset( $views['lp_missing'], $views['lp_missing_ready'] ), 'both views added' );
t_eq( wp_json_encode( array( 'all', 'lp_missing', 'lp_missing_ready' ) ), wp_json_encode( array_slice( array_keys( $views ), 0, 3 ) ), '"Customer answered" comes right after "Missing items"' );
t_ok( false !== strpos( $views['lp_missing_ready'], 'Customer answered (' . count( $ready_ids ) . ')' ), 'ready view label with count: ' . wp_strip_all_tags( $views['lp_missing_ready'] ) );
t_ok( false !== strpos( $views['lp_missing_ready'], 'lp_missing_view=ready' ), 'ready view links to lp_missing_view=ready' );
t_ok( false !== strpos( $views['lp_missing_ready'], $hpos ? 'page=wc-orders' : 'post_type=shop_order' ), 'ready view links to the list of the active storage' );
t_ok( false === strpos( $views['lp_missing_ready'], 'current' ), 'ready view not current by default' );
$_GET['lp_missing_view'] = 'ready';
$views = LP_Missing_Admin_Orders_List::add_missing_orders_view( array( 'all' => '<a href="x" class="current" aria-current="page">All</a>' ) );
t_ok( false !== strpos( $views['lp_missing_ready'], 'class="current"' ) && false === strpos( $views['lp_missing'], 'current' ), 'ready view marked current when requested' );
t_eq( '<a href="x">All</a>', $views['all'], '"All" is no longer marked current in our view' );

if ( $hpos ) {
	$args = LP_Missing_Admin_Orders_List::filter_missing_orders_view_hpos( array( 'type' => 'shop_order', 'limit' => -1, 'return' => 'ids' ) );
	$ids  = wc_get_orders( $args );
} else {
	set_current_screen( 'edit-shop_order' ); // is_admin()
	$vars = LP_Missing_Admin_Orders_List::filter_missing_orders_view( array( 'post_type' => 'shop_order', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ) );
	$ids  = ( new WP_Query( $vars ) )->posts;
	unset( $GLOBALS['current_screen'] );
}
sort( $ids );
$expected = $ready_ids;
sort( $expected );
t_eq( wp_json_encode( $expected ), wp_json_encode( array_map( 'intval', $ids ) ), 'ready view lists exactly the ready orders (' . ( $hpos ? 'HPOS query' : 'WP_Query' ) . ')' );
$_GET['lp_missing_view'] = 'bogus';
t_eq( wp_json_encode( array( 'x' => 1 ) ), wp_json_encode( LP_Missing_Admin_Orders_List::filter_missing_orders_view_hpos( array( 'x' => 1 ) ) ), 'unknown view leaves the query alone' );
reset_request();

$col = column_html( $o2 );
t_ok( false !== strpos( $col, 'Customer answered – ready to apply' ) && false !== strpos( $col, 'lp-missing-state--ready' ), 'column shows the green ready state' );
$o3   = make_order( $A, 1 );
$iid3 = first_item_id( $o3 );
admin_save( $o3, array( $iid3 => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
t_ok( false !== strpos( column_html( $o3 ), 'Waiting for the customer' ), 'waiting order shows "Waiting for the customer"' );
$tmp = wc_get_order( $o2->get_id() );
$tmp->update_meta_data( '_lp_missing_needs_attention', 'yes' );
$tmp->save();
t_ok( false !== strpos( column_html( $o2 ), 'ready to apply' ), 'ready state comes before other states' );
$tmp = wc_get_order( $o3->get_id() );
$tmp->update_meta_data( '_lp_missing_needs_attention', 'yes' );
$tmp->save();
t_ok( false !== strpos( column_html( $o3 ), 'Needs follow-up' ), 'escalated open order shows "Needs follow-up" (was unreachable before)' );
t_eq( '&mdash;', column_html( make_order( $A, 1 ) ), 'order without missing items shows a dash' );
t_ok( false === strpos( $col, 'style=' ), 'column markup has no inline styles' );

// ---------- S4: history line ----------
echo "\n[S4] Notification history\n";
$oslo = function ( $ts, $format ) {
	return ( new DateTime( '@' . $ts ) )->setTimezone( new DateTimeZone( 'Europe/Oslo' ) )->format( $format );
};
$now      = time();
$notified = $now - 2 * DAY_IN_SECONDS;
$next     = $now + DAY_IN_SECONDS + 1234;
$deadline = $now + 3 * DAY_IN_SECONDS;
$data     = array_merge( LP_Missing_Line::default_item_data(), array( 'missing' => true, 'qty_missing' => 1, 'status' => 'pending', 'notified_at' => $notified, 'reminder_count' => 2, 'first_missing_at' => $notified, 'deadline_at' => $deadline ) );

$parts = LP_Missing_Admin_Metabox::get_history_parts( $data, $next );
t_eq( 'emailed ' . $oslo( $notified, 'd.m' ), $parts[0], 'emailed date in the site time zone' );
t_eq( '2/3 reminders', $parts[1], 'reminders sent / maximum' );
t_eq( 'next ' . $oslo( $next, 'd.m H:i' ), $parts[2], 'next reminder with time (site time zone)' );
t_eq( 3, count( $parts ), 'no deadline part while no deadline action is configured' );

update_option( 'lp_missing_settings', array_merge( is_array( $orig_settings ) ? $orig_settings : array(), array( 'deadline_action' => 'refund' ) ) );
LP_Missing_Settings::flush();
$parts = LP_Missing_Admin_Metabox::get_history_parts( $data, $next );
t_eq( 'deadline ' . $oslo( $deadline, 'd.m H:i' ), end( $parts ), 'deadline shown when an automatic action is configured' );
$line = implode( ' · ', $parts );
t_eq( 'emailed ' . $oslo( $notified, 'd.m' ) . ' · 2/3 reminders · next ' . $oslo( $next, 'd.m H:i' ) . ' · deadline ' . $oslo( $deadline, 'd.m H:i' ), $line, 'one history line' );

$chosen = array_merge( $data, array( 'status' => 'alt_pending', 'decision_made_at' => $now - DAY_IN_SECONDS ) );
$parts  = LP_Missing_Admin_Metabox::get_history_parts( $chosen, $next );
t_ok( in_array( 'answered ' . $oslo( $now - DAY_IN_SECONDS, 'd.m' ), $parts, true ), 'customer decision date shown' );
t_ok( ! preg_grep( '/^(next|deadline) /', $parts ), 'no next reminder or deadline once the customer has chosen' );
$parts = LP_Missing_Admin_Metabox::get_history_parts( array_merge( $data, array( 'status' => 'declined', 'decision_made_at' => 0 ) ), $next );
t_ok( in_array( 'answered', $parts, true ), 'declined line without a date' );
$parts = LP_Missing_Admin_Metabox::get_history_parts( array_merge( $data, array( 'notified_at' => 0, 'needs_attention' => true ) ), 0 );
t_eq( 'not emailed yet', $parts[0], 'line without a recorded notification' );
t_ok( ! preg_grep( '/^next /', $parts ), 'escalated line, no next reminder when none is scheduled' );
$parts = LP_Missing_Admin_Metabox::get_history_parts( array_merge( $data, array( 'missing' => false, 'status' => 'alt_applied', 'resolved_at' => $now ) ), $next );
t_ok( in_array( 'applied ' . $oslo( $now, 'd.m' ), $parts, true ) && ! preg_grep( '/^(next|deadline) /', $parts ), 'resolved line shows when it was applied' );
t_eq( wp_json_encode( array() ), wp_json_encode( LP_Missing_Admin_Metabox::get_history_parts( array_merge( $data, array( 'missing' => false, 'status' => 'cleared' ) ), $next ) ), 'no history for lines that are not missing' );
$last_year = $now - 400 * DAY_IN_SECONDS;
t_eq( $oslo( $last_year, 'd.m.Y' ), LP_Missing_Admin_Metabox::format_short_date( $last_year ), 'dates outside the current year carry the year' );

// Rendered in the box, with the next reminder from LP_Missing_Lifecycle::get_next_reminder_timestamp().
$o4   = make_order( $A, 2 );
$iid4 = first_item_id( $o4 );
admin_save( $o4, array( $iid4 => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ) ) ) );
$next4 = LP_Missing_Lifecycle::get_next_reminder_timestamp( wc_get_order( $o4->get_id() ) );
$box   = render_box( $o4 );
t_ok( $next4 > 0, 'a reminder is scheduled for the new case' );
t_ok( false !== strpos( $box, 'class="lp-missing-history lp-line__facts"' ), 'history line rendered for the missing line' );
t_ok( false !== strpos( $box, 'next ' . $oslo( $next4, 'd.m H:i' ) ), 'history shows the scheduled reminder' );
t_ok( false !== strpos( $box, '0/3 reminders' ), 'history shows the reminder count' );
t_eq( 1, substr_count( $box, 'lp-missing-history' ), 'only missing lines get a history line' );
restore_settings( $orig_settings );

// ---------- S5 + S15: box markup ----------
echo "\n[S5/S15] Customer link tools and markup\n";
$order4 = wc_get_order( $o4->get_id() );
$link   = LP_Missing_Magic_Link::get_magic_link_for_order( $order4 );
t_ok( ! preg_match( '/\sstyle=/', $box ), 'box has no inline styles' );
t_ok( false === strpos( $box, '<script' ), 'box has no inline script' );
t_ok( false === stripos( $box, '<form' ) && false === strpos( $box, 'name="action"' ), 'still no nested form or "action" field' );
t_ok( false !== strpos( $box, 'class="button lp-missing-copy-link" data-link="' . esc_attr( $link ) . '"' ), 'copy button carries the customer link' );
t_ok( (bool) preg_match( '/<input type="text" class="lp-missing-link-field[^"]*" readonly="readonly" hidden="hidden"[^>]* value="' . preg_quote( esc_attr( $link ), '/' ) . '"/', $box ), 'hidden fallback field with the link' );
t_ok( (bool) preg_match( '/<a class="button lp-missing-preview" href="([^"]+)" target="_blank" rel="noopener noreferrer">/', $box, $m ), 'view-as-customer link opens in a new tab' );
$preview = isset( $m[1] ) ? html_entity_decode( $m[1] ) : '';
parse_str( (string) wp_parse_url( $preview, PHP_URL_QUERY ), $pq );
t_eq( (string) $o4->get_id(), isset( $pq['oid'] ) ? $pq['oid'] : '', 'preview link is for this order' );
t_eq( LP_Missing_Magic_Link::get_staff_preview_url( $order4 ), $preview, 'preview link is the staff preview URL' );
t_ok( (bool) preg_match( '/<a class="button lp-missing-revoke lp-missing-confirm" href="([^"]+)" data-confirm="[^"]+">Revoke customer links<\/a>/', $box, $m ), 'revoke link with a confirmation' );
parse_str( (string) wp_parse_url( html_entity_decode( isset( $m[1] ) ? $m[1] : '' ), PHP_URL_QUERY ), $rq );
t_eq( 'lp_missing_revoke_links', isset( $rq['action'] ) ? $rq['action'] : '', 'revoke link targets the admin-post action' );
t_ok( isset( $rq['_wpnonce'] ) && wp_verify_nonce( $rq['_wpnonce'], 'lp_missing_revoke_links_' . $o4->get_id() ), 'revoke link carries a nonce for this order' );
t_ok( false !== strpos( $box, 'lp_missing_send_email' ), 'send email button still there' );
t_ok( (bool) preg_match( '/class="lp-missing-metabox" data-order-id="' . $o4->get_id() . '" data-nonce="([a-f0-9]+)"/', $box, $m ) && wp_verify_nonce( $m[1], 'lp_missing_admin_ajax_' . $o4->get_id() ), 'box carries the AJAX nonce for this order' );
t_ok( false !== strpos( $box, 'class="lp-alt-list"' ), 'preview list rendered' );
t_ok( false === strpos( $box, 'lp-missing-variants' ), 'no variant button for a simple product' );
$plain = render_box( make_order( $A, 1 ) );
t_ok( false === strpos( $plain, 'lp-missing-admin-actions' ) && false === strpos( $plain, 'lp-missing-copy-link' ), 'no link tools on an order without missing items' );
t_ok( false !== strpos( $plain, 'class="lp-alt-list"' ), 'empty preview list is rendered for the script to fill' );

// ---------- S5: revoke action ----------
echo "\n[S5] Revoke customer links\n";
$gen_key = defined( 'LP_Missing_Magic_Link::LINK_GENERATION_META' ) ? LP_Missing_Magic_Link::LINK_GENERATION_META : '';
$gen0    = $gen_key ? absint( wc_get_order( $o4->get_id() )->get_meta( $gen_key ) ) : 0;
$res     = admin_post( array( 'LP_Missing_Admin_Actions', 'handle_revoke_links' ), array( 'action' => 'lp_missing_revoke_links', 'order_id' => $o4->get_id(), '_wpnonce' => 'bad' ) );
t_ok( isset( $res['die'] ) && 403 === $res['code'], 'bad nonce is refused' );
$other_nonce = wp_create_nonce( 'lp_missing_revoke_links_' . $o3->get_id() );
$res = admin_post( array( 'LP_Missing_Admin_Actions', 'handle_revoke_links' ), array( 'order_id' => $o4->get_id(), '_wpnonce' => $other_nonce ) );
t_ok( isset( $res['die'] ), 'nonce of another order is refused' );
$customer = make_user( 'customer' );
wp_set_current_user( $customer );
$cust_nonce = wp_create_nonce( 'lp_missing_revoke_links_' . $o4->get_id() );
$res = admin_post( array( 'LP_Missing_Admin_Actions', 'handle_revoke_links' ), array( 'order_id' => $o4->get_id(), '_wpnonce' => $cust_nonce ), $customer );
t_ok( isset( $res['die'] ) && 403 === $res['code'], 'user without order capability is refused' );
if ( $gen_key ) {
	t_eq( $gen0, absint( wc_get_order( $o4->get_id() )->get_meta( $gen_key ) ), 'refused requests revoke nothing' );
}
$GLOBALS['lp_logs'] = array();
wp_set_current_user( 1 );
$notes_before = count( wc_get_order_notes( array( 'order_id' => $o4->get_id() ) ) );
$res = admin_post( array( 'LP_Missing_Admin_Actions', 'handle_revoke_links' ), array( 'order_id' => $o4->get_id(), '_wpnonce' => wp_create_nonce( 'lp_missing_revoke_links_' . $o4->get_id() ) ) );
t_ok( isset( $res['redirect'] ) && false !== strpos( $res['redirect'], 'lp_missing_links_revoked=1' ), 'revoke redirects back with a notice flag' );
t_eq( LP_Missing_Util::get_order_edit_url( $o4->get_id() ), isset( $res['redirect'] ) ? remove_query_arg( 'lp_missing_links_revoked', $res['redirect'] ) : '', 'redirect goes back to the order' );
if ( $gen_key ) {
	t_eq( $gen0 + 1, absint( wc_get_order( $o4->get_id() )->get_meta( $gen_key ) ), 'LP_Missing_Magic_Link::revoke_links() was called' );
}
t_ok( '' !== logged( 'Staff revoked the customer links.' ) && false !== strpos( logged( 'Staff revoked the customer links.' ), '"user_id":1' ), 'revocation logged with the staff user' );
$notes = wc_get_order_notes( array( 'order_id' => $o4->get_id() ) );
t_eq( $notes_before + 1, count( $notes ), 'order note added' );
t_ok( false !== strpos( $notes[0]->content, 'revoked' ), 'order note says the links were revoked' );
set_current_screen( $hpos ? 'woocommerce_page_wc-orders' : 'shop_order' );
$_GET = array( 'lp_missing_links_revoked' => '1' );
ob_start();
LP_Missing_Admin_Actions::admin_notices();
$notice = ob_get_clean();
unset( $GLOBALS['current_screen'] );
t_ok( false !== strpos( $notice, 'Customer links revoked' ) && false !== strpos( $notice, 'notice-success' ), 'success notice shown on the order screen' );

// ---------- S9: preview endpoint ----------
echo "\n[S9] Alternatives preview (price for this order, difference, stock)\n";
$OOS = make_product( 'Tomt lager', '100', 0 );
$LOW = make_product( 'Lite lager', '100', 1 );
$o5   = make_order( $A, 3 );
$iid5 = first_item_id( $o5 );
admin_save( $o5, array( $iid5 => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $C->get_id() ) ) ) );
$order5 = wc_get_order( $o5->get_id() );
$nonce5 = LP_Missing_Admin_Alternatives::create_nonce( $o5->get_id() );
$before = item_data( $o5->get_id(), $iid5 );
$res = admin_ajax( 'lp_missing_preview_alternatives', array( 'nonce' => $nonce5, 'order_id' => $o5->get_id(), 'item_id' => $iid5, 'alt_ids' => array( $B->get_id(), $C->get_id(), $OOS->get_id(), $LOW->get_id() ), 'qty' => 2 ) );
t_ok( ! empty( $res['success'] ), 'preview request succeeds' );
$html = isset( $res['data']['html'] ) ? $res['data']['html'] : '';
t_eq( 3, substr_count( $html, '<li ' ), 'at most 3 alternatives previewed' );
t_eq( 2, isset( $res['data']['qty'] ) ? $res['data']['qty'] : 0, 'difference is for the requested missing quantity' );
$rows = array();
foreach ( array( $B, $C, $OOS ) as $p ) {
	$rows[ $p->get_id() ] = preg_match( '/<li class="([^"]+)" data-product-id="' . $p->get_id() . '">(.*?)<\/li>/s', $html, $m ) ? $m : array( '', '', '' );
}
$price_b = esc_html( LP_Missing_Util::plain_price( 150, $order5 ) );
t_ok( false !== strpos( $rows[ $B->get_id() ][2], $price_b . '/unit incl. VAT' ), 'price for this order incl. VAT: ' . wp_strip_all_tags( $rows[ $B->get_id() ][2] ) );
t_ok( false !== strpos( $rows[ $B->get_id() ][2], '+' . esc_html( LP_Missing_Util::plain_price( 100, $order5 ) ) . ' for 2 (+50%)' ), 'difference for the missing quantity with percentage' );
t_ok( false !== strpos( $rows[ $B->get_id() ][1], 'lp-alt-preview--pricey' ), 'more than +20%: yellow' );
t_ok( false !== strpos( $rows[ $C->get_id() ][2], '−' . esc_html( LP_Missing_Util::plain_price( 100, $order5 ) ) . ' for 2 (−50%)' ), 'cheaper alternative shows a negative difference' );
t_eq( 'lp-alt-preview', $rows[ $C->get_id() ][1], 'cheaper alternative in stock: no highlight' );
t_ok( false !== strpos( $rows[ $OOS->get_id() ][1], 'lp-alt-preview--nostock' ) && false !== strpos( $rows[ $OOS->get_id() ][2], 'Out of stock' ), 'out of stock: red' );
$res  = admin_ajax( 'lp_missing_preview_alternatives', array( 'nonce' => $nonce5, 'order_id' => $o5->get_id(), 'item_id' => $iid5, 'alt_ids' => array( $LOW->get_id(), $A->get_id() ), 'qty' => 2 ) );
$html = isset( $res['data']['html'] ) ? $res['data']['html'] : '';
t_ok( (bool) preg_match( '/<li class="lp-alt-preview lp-alt-preview--nostock" data-product-id="' . $LOW->get_id() . '">.*Not enough stock for 2/s', $html ), 'not enough stock for the missing quantity: red' );
t_ok( false !== strpos( $html, 'Same price' ), 'same-priced alternative says so' );
$res = admin_ajax( 'lp_missing_preview_alternatives', array( 'nonce' => $nonce5, 'order_id' => $o5->get_id(), 'item_id' => $iid5, 'alt_ids' => array( $LOW->get_id() ), 'qty' => 1 ) );
t_ok( false === strpos( $res['data']['html'], 'nostock' ), 'one unit left is enough for a quantity of 1' );
$res = admin_ajax( 'lp_missing_preview_alternatives', array( 'nonce' => $nonce5, 'order_id' => $o5->get_id(), 'item_id' => $iid5, 'alt_ids' => array( $B->get_id() ), 'qty' => 99 ) );
t_eq( 3, $res['data']['qty'], 'quantity capped at the line quantity' );
$res = admin_ajax( 'lp_missing_preview_alternatives', array( 'nonce' => $nonce5, 'order_id' => $o5->get_id(), 'item_id' => $iid5, 'alt_ids' => array() ) );
t_ok( ! empty( $res['success'] ) && '' === $res['data']['html'], 'no alternatives: empty preview' );
t_ok( serialize( $before ) === serialize( item_data( $o5->get_id(), $iid5 ) ), 'previewing saves nothing' );
// Frozen price of the customer's choice.
set_line_status( $o5, $iid5, array( 'status' => 'alt_pending', 'selected_alt_id' => $C->get_id(), 'qty_alt' => 2, 'pricing_snapshot' => LP_Missing_Pricing::get_frozen_pricing_snapshot( wc_get_order( $o5->get_id() ), wc_get_order( $o5->get_id() )->get_item( $iid5 ), array_merge( $before, array( 'selected_alt_id' => $C->get_id() ) ), wc_get_product( $C->get_id() ), 2 ) ) );
$box5 = render_box( $o5 );
t_ok( false !== strpos( $box5, '(agreed with the customer)' ), 'box marks the difference the customer agreed to' );
t_ok( false !== strpos( $box5, 'lp-alt-preview' ) && false !== strpos( $box5, esc_html( LP_Missing_Util::plain_price( 50, $order5 ) ) . '/unit incl. VAT' ), 'saved alternatives are previewed with prices on load' );

// Security.
$res = admin_ajax( 'lp_missing_preview_alternatives', array( 'nonce' => 'bad', 'order_id' => $o5->get_id(), 'item_id' => $iid5, 'alt_ids' => array( $B->get_id() ) ) );
t_ok( isset( $res['success'] ) && false === $res['success'] && empty( $res['data']['html'] ), 'preview: bad nonce refused' );
$res = admin_ajax( 'lp_missing_preview_alternatives', array( 'nonce' => LP_Missing_Admin_Alternatives::create_nonce( $o4->get_id() ), 'order_id' => $o5->get_id(), 'item_id' => $iid5, 'alt_ids' => array( $B->get_id() ) ) );
t_ok( isset( $res['success'] ) && false === $res['success'], 'preview: nonce of another order refused' );
$res = admin_ajax( 'lp_missing_preview_alternatives', array( 'nonce' => $nonce5, 'order_id' => $o5->get_id(), 'item_id' => $iid4, 'alt_ids' => array( $B->get_id() ) ) );
t_ok( isset( $res['success'] ) && false === $res['success'] && false !== strpos( $res['data']['message'], 'not found' ), 'preview: line of another order refused' );
wp_set_current_user( $customer );
$cust_ajax_nonce = LP_Missing_Admin_Alternatives::create_nonce( $o5->get_id() );
$res = admin_ajax( 'lp_missing_preview_alternatives', array( 'nonce' => $cust_ajax_nonce, 'order_id' => $o5->get_id(), 'item_id' => $iid5, 'alt_ids' => array( $B->get_id() ) ), $customer );
t_ok( isset( $res['success'] ) && false === $res['success'] && false !== strpos( $res['data']['message'], 'permission' ), 'preview: customer without capability refused' );
$manager = make_user( 'shop_manager' );
wp_set_current_user( $manager );
$mgr_nonce = LP_Missing_Admin_Alternatives::create_nonce( $o5->get_id() );
$res = admin_ajax( 'lp_missing_preview_alternatives', array( 'nonce' => $mgr_nonce, 'order_id' => $o5->get_id(), 'item_id' => $iid5, 'alt_ids' => array( $B->get_id() ) ), $manager );
t_ok( ! empty( $res['success'] ), 'preview: shop manager allowed' );
t_ok( false === has_action( 'wp_ajax_nopriv_lp_missing_preview_alternatives' ) && false === has_action( 'wp_ajax_nopriv_lp_missing_variant_suggestions' ), 'no endpoints for logged-out visitors' );

// ---------- S8: variant suggestions ----------
echo "\n[S8] Same product, other variant\n";
function make_variable_product( $name, $rows ) {
	$attrs = array();
	foreach ( array( 'Size' => array( 'S', 'M', 'L', 'XL' ), 'Color' => array( 'Red', 'Blue', 'Green' ) ) as $label => $options ) {
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
		list( $size, $color, $price, $stock ) = $row;
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent->get_id() );
		$v->set_attributes( array( 'size' => $size, 'color' => $color ) );
		$v->set_regular_price( $price );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( $stock );
		if ( ! empty( $row[4] ) ) {
			$v->set_backorders( 'yes' );
		}
		if ( ! empty( $row[5] ) ) {
			$v->set_status( $row[5] );
		}
		$v->save();
		$ids[ $key ] = $v->get_id();
	}
	WC_Product_Variable::sync( $parent->get_id() );
	wc_delete_product_transients( $parent->get_id() );
	return $ids;
}
$V = make_variable_product(
	'Body',
	array(
		'M-Red'    => array( 'M', 'Red', '100', 10 ),
		'M-Blue'   => array( 'M', 'Blue', '100', 10 ),   // 1 attribute off, same price.
		'L-Red'    => array( 'L', 'Red', '110', 10 ),    // 1 attribute off, +10%.
		'L-Blue'   => array( 'L', 'Blue', '100', 10 ),   // 2 attributes off.
		'XL-Blue'  => array( 'XL', 'Blue', '150', 10 ),  // 2 attributes off, +50%.
		'S-Red'    => array( 'S', 'Red', '100', 1 ),     // Not enough for 2.
		'XL-Red'   => array( 'XL', 'Red', '100', 0 ),    // Out of stock.
		'S-Blue'   => array( 'S', 'Blue', '100', 0, true ), // On backorder.
		'M-Green'  => array( 'M', 'Green', '100', 10, false, 'private' ),
		'L-Green'  => array( 'L', 'Green', '', 10 ),     // No price: not purchasable.
	)
);
$ov   = make_order( wc_get_product( $V['M-Red'] ), 2 );
$ivid = first_item_id( $ov );
admin_save( $ov, array( $ivid => array( 'missing' => '1', 'qty_missing' => '2' ) ) );
$ov     = wc_get_order( $ov->get_id() );
$item_v = $ov->get_item( $ivid );
$ids_of = function ( $products ) {
	return array_map(
		function ( $p ) {
			return $p->get_id();
		},
		$products
	);
};
t_ok( LP_Missing_Admin_Alternatives::line_has_variants( $item_v ), 'variation line offers other variants' );
t_ok( ! LP_Missing_Admin_Alternatives::line_has_variants( wc_get_order( $o5->get_id() )->get_item( $iid5 ) ), 'simple product line does not' );
t_eq( wp_json_encode( array( $V['M-Blue'], $V['L-Red'], $V['L-Blue'] ) ), wp_json_encode( $ids_of( LP_Missing_Admin_Alternatives::get_variant_suggestions( $ov, $item_v, 2 ) ) ), 'in-stock siblings with enough stock, closest first, max 3' );
t_eq( wp_json_encode( array( $V['L-Red'], $V['L-Blue'], $V['XL-Blue'] ) ), wp_json_encode( $ids_of( LP_Missing_Admin_Alternatives::get_variant_suggestions( $ov, $item_v, 2, array( $V['M-Blue'] ) ) ) ), 'already chosen variants are skipped' );
t_ok( in_array( $V['S-Red'], $ids_of( LP_Missing_Admin_Alternatives::get_variant_suggestions( $ov, $item_v, 1 ) ), true ), 'one unit left is enough for a quantity of 1' );
t_eq( 1, LP_Missing_Admin_Alternatives::attribute_distance( array( 'size' => 'M', 'color' => 'Red' ), array( 'size' => 'm', 'color' => 'Blue' ) ), 'attribute distance ignores case' );
t_eq( 0, LP_Missing_Admin_Alternatives::attribute_distance( array( 'size' => 'M', 'color' => 'Red' ), array( 'size' => 'M', 'color' => '' ) ), '"any" attribute matches' );

$box_v = render_box( $ov );
t_ok( false !== strpos( $box_v, '<button type="button" class="button lp-missing-variants" data-item-id="' . $ivid . '">Find other sizes/variants</button>' ), 'variant button rendered for the variation line' );

$nonce_v = LP_Missing_Admin_Alternatives::create_nonce( $ov->get_id() );
$res     = admin_ajax( 'lp_missing_variant_suggestions', array( 'nonce' => $nonce_v, 'order_id' => $ov->get_id(), 'item_id' => $ivid, 'qty' => 2 ) );
t_ok( ! empty( $res['success'] ), 'suggestion request succeeds' );
t_eq( wp_json_encode( array( $V['M-Blue'], $V['L-Red'], $V['L-Blue'] ) ), wp_json_encode( wp_list_pluck( $res['data']['suggestions'], 'id' ) ), 'endpoint returns the 3 closest variants' );
t_eq( LP_Missing_Admin_Alternatives::get_option_label( wc_get_product( $V['M-Blue'] ) ), $res['data']['suggestions'][0]['text'], 'suggestion text as the product search shows it' );
t_ok( false === strpos( $res['data']['suggestions'][0]['text'], '<' ), 'suggestion text is plain' );
$res = admin_ajax( 'lp_missing_variant_suggestions', array( 'nonce' => $nonce_v, 'order_id' => $ov->get_id(), 'item_id' => $ivid, 'qty' => 2, 'exclude' => array( $V['M-Blue'], $V['L-Red'] ) ) );
t_eq( wp_json_encode( array( $V['L-Blue'], $V['XL-Blue'] ) ), wp_json_encode( wp_list_pluck( $res['data']['suggestions'], 'id' ) ), 'endpoint skips the excluded (already selected) variants' );
$res = admin_ajax( 'lp_missing_variant_suggestions', array( 'nonce' => $nonce_v, 'order_id' => $ov->get_id(), 'item_id' => $ivid, 'qty' => 2, 'exclude' => $V['M-Blue'] . ',' . $V['L-Red'] . ',' . $V['L-Blue'] . ',' . $V['XL-Blue'] ) );
t_ok( ! empty( $res['success'] ) && array() === $res['data']['suggestions'] && '' !== $res['data']['message'], 'nothing left: empty list with a message' );
$res = admin_ajax( 'lp_missing_variant_suggestions', array( 'nonce' => $nonce_v, 'order_id' => $ov->get_id(), 'item_id' => $ivid, 'qty' => 11 ) );
t_ok( 2 === $res['data']['qty'] && 3 === count( $res['data']['suggestions'] ), 'quantity capped at the line quantity (2), not the requested 11 (no variant has 11)' );

// Filter.
$reverse = function ( $suggestions, $item, $order, $qty ) {
	return array_reverse( array_map( function ( $p ) { return $p->get_id(); }, $suggestions ) ); // IDs are accepted too.
};
add_filter( 'lp_missing_variant_suggestions', $reverse, 10, 4 );
$res = admin_ajax( 'lp_missing_variant_suggestions', array( 'nonce' => $nonce_v, 'order_id' => $ov->get_id(), 'item_id' => $ivid, 'qty' => 2 ) );
remove_filter( 'lp_missing_variant_suggestions', $reverse, 10 );
t_eq( wp_json_encode( array( $V['XL-Blue'], $V['L-Blue'], $V['L-Red'] ) ), wp_json_encode( wp_list_pluck( $res['data']['suggestions'], 'id' ) ), 'lp_missing_variant_suggestions filter can re-rank (and return IDs), still max 3' );
$sneaky = function ( $suggestions ) use ( $V ) {
	return array( $V['M-Red'], $V['M-Blue'], $V['M-Blue'], 'x', null );
};
add_filter( 'lp_missing_variant_suggestions', $sneaky );
$res = admin_ajax( 'lp_missing_variant_suggestions', array( 'nonce' => $nonce_v, 'order_id' => $ov->get_id(), 'item_id' => $ivid, 'qty' => 2 ) );
remove_filter( 'lp_missing_variant_suggestions', $sneaky );
t_eq( wp_json_encode( array( $V['M-Blue'] ) ), wp_json_encode( wp_list_pluck( $res['data']['suggestions'], 'id' ) ), 'filtered results drop the ordered variant, duplicates and junk' );

// Security and non-variation lines.
$res = admin_ajax( 'lp_missing_variant_suggestions', array( 'nonce' => 'bad', 'order_id' => $ov->get_id(), 'item_id' => $ivid ) );
t_ok( isset( $res['success'] ) && false === $res['success'] && empty( $res['data']['suggestions'] ), 'suggestions: bad nonce refused' );
$res = admin_ajax( 'lp_missing_variant_suggestions', array( 'nonce' => $nonce5, 'order_id' => $ov->get_id(), 'item_id' => $ivid ) );
t_ok( isset( $res['success'] ) && false === $res['success'], 'suggestions: nonce of another order refused' );
wp_set_current_user( $customer );
$cust_nonce_v = LP_Missing_Admin_Alternatives::create_nonce( $ov->get_id() );
$res = admin_ajax( 'lp_missing_variant_suggestions', array( 'nonce' => $cust_nonce_v, 'order_id' => $ov->get_id(), 'item_id' => $ivid ), $customer );
t_ok( isset( $res['success'] ) && false === $res['success'] && empty( $res['data']['suggestions'] ), 'suggestions: customer without capability refused' );
$res = admin_ajax( 'lp_missing_variant_suggestions', array( 'nonce' => $nonce5, 'order_id' => $o5->get_id(), 'item_id' => $iid5 ) );
t_ok( isset( $res['success'] ) && false === $res['success'] && false !== strpos( $res['data']['message'], 'not a product variation' ), 'suggestions: simple product line refused' );
$res = admin_ajax( 'lp_missing_variant_suggestions', array( 'nonce' => $nonce_v, 'order_id' => $ov->get_id(), 'item_id' => $iid5 ) );
t_ok( isset( $res['success'] ) && false === $res['success'] && false !== strpos( $res['data']['message'], 'not found' ), 'suggestions: line of another order refused' );

// Suggested variants save through the normal box save and preview like any alternative.
admin_save( $ov, array( $ivid => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $V['M-Blue'], $V['L-Red'] ) ) ) );
t_eq( wp_json_encode( array( $V['M-Blue'], $V['L-Red'] ) ), wp_json_encode( item_data( $ov->get_id(), $ivid )['alternatives'] ), 'variants added to the select are saved with the order' );
t_ok( false !== strpos( render_box( $ov ), '+' . esc_html( LP_Missing_Util::plain_price( 20, $ov ) ) . ' for 2 (+10%)' ), 'variant preview shows its difference' );

// ---------- S15: assets ----------
echo "\n[S15] Assets\n";
$reset_assets = function () {
	wp_dequeue_script( 'lp-missing-admin' );
	wp_deregister_script( 'lp-missing-admin' );
	wp_dequeue_style( 'lp-missing-admin' );
	wp_deregister_style( 'lp-missing-admin' );
};
set_current_screen( 'dashboard' );
LP_Missing_Admin_Metabox::enqueue_admin_scripts( 'index.php' );
t_ok( ! wp_script_is( 'lp-missing-admin', 'enqueued' ) && ! wp_style_is( 'lp-missing-admin', 'enqueued' ), 'nothing enqueued outside order screens' );
set_current_screen( $hpos ? 'woocommerce_page_wc-orders' : 'shop_order' );
LP_Missing_Admin_Metabox::enqueue_admin_scripts( 'post.php' );
t_ok( wp_script_is( 'lp-missing-admin', 'enqueued' ) && wp_style_is( 'lp-missing-admin', 'enqueued' ), 'script and style enqueued on the order screen' );
$script = wp_scripts()->registered['lp-missing-admin'];
$style  = wp_styles()->registered['lp-missing-admin'];
t_ok( false !== strpos( $script->src, 'assets/js/admin.js' ) && file_exists( LP_MISSING_DIR . 'assets/js/admin.js' ), 'script is assets/js/admin.js' );
t_ok( false !== strpos( $style->src, 'assets/css/admin.css' ) && file_exists( LP_MISSING_DIR . 'assets/css/admin.css' ), 'style is assets/css/admin.css' );
t_eq( LP_Missing_Plugin::VERSION, $script->ver, 'script versioned with the plugin version' );
t_eq( LP_Missing_Plugin::VERSION, $style->ver, 'style versioned with the plugin version' );
t_ok( false !== strpos( (string) wp_scripts()->get_data( 'lp-missing-admin', 'data' ), 'admin-ajax.php' ), 'script gets the AJAX URL' );
t_ok( empty( wp_scripts()->registered['lp-missing-admin']->extra['after'] ), 'no inline script left' );
$reset_assets();
if ( ! $hpos ) {
	set_current_screen( 'edit-shop_order' );
	LP_Missing_Admin_Metabox::enqueue_admin_scripts( 'edit.php' );
	t_ok( wp_style_is( 'lp-missing-admin', 'enqueued' ) && ! wp_script_is( 'lp-missing-admin', 'enqueued' ), 'legacy order list gets the column styles only' );
	$reset_assets();
}
unset( $GLOBALS['current_screen'] );

update_option( 'timezone_string', $orig_tz );

// ---------- Simple order box ----------
echo "\n[UI] Simple order box\n";
$ou  = make_order( $A, 2 );
$ou->add_product( wc_get_product( $C->get_id() ), 1 );
$ou->calculate_totals( true );
$ou->save();
$uids = array_keys( wc_get_order( $ou->get_id() )->get_items() );
$box  = render_box( $ou );
t_ok( false !== strpos( $box, 'lp-summary__empty' ), 'nothing missing: one short sentence on top' );
t_eq( 2, substr_count( $box, 'class="button lp-mark"' ), 'every line has a «Missing» button' );
t_eq( 2, substr_count( $box, '<div class="lp-new-case" hidden="hidden">' ), 'case fields stay hidden until «Missing» is pressed' );
t_ok( (bool) preg_match( '/name="lp_missing_items\[' . $uids[0] . '\]\[qty_missing\]" value="1"/', $box ), 'a new case starts at 1 missing' );
t_ok( (bool) preg_match( '/name="lp_missing_items\[' . $uids[0] . '\]\[propose_delete\]" value="1"  ?checked/', $box ), 'removal is offered by default' );
t_ok( false !== strpos( $box, 'class="button-link lp-preset" data-text="Utsolgt hos leverandøren."' ), 'one-click customer messages' );
t_ok( false !== strpos( $box, 'lp-savebar' ) && false !== strpos( $box, 'button button-primary lp-save' ), 'save bar with a save button' );
t_ok( false === strpos( $box, 'lp-missing-admin-actions' ), 'no customer link tools before there is a case' );

admin_save( $ou, array( $uids[0] => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ), 'propose_delete' => '1' ), $uids[1] => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
$box = render_box( $ou );
t_ok( false !== strpos( $box, 'lp-badge--waiting' ) && false !== strpos( $box, 'Waiting for the customer' ), 'waiting line has a status badge' );
t_ok( (bool) preg_match( '/<input type="checkbox" class="lp-missing-toggle" name="lp_missing_items\[' . $uids[0] . '\]\[missing\]" value="1" checked="checked" hidden="hidden" \/>/', $box ), 'open case keeps its missing flag (hidden)' );
t_ok( false !== strpos( $box, 'lp-cancel-case' ) && false !== strpos( $box, 'Change or cancel' ), 'change/cancel tucked away' );
t_ok( false !== strpos( $box, 'Missing 1 of 2' ), 'facts line says how many are missing' );
t_ok( false !== strpos( $box, '2 waiting for the customer' ), 'summary counts waiting lines' );

set_line_status( $ou, $uids[0], array( 'status' => 'alt_pending', 'selected_alt_id' => $B->get_id(), 'qty_alt' => 1, 'decision_made_at' => time() ) );
set_line_status( $ou, $uids[1], array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
$box = render_box( $ou );
t_ok( false !== strpos( $box, '1 × ' . $B->get_name() ), 'the chosen replacement is shown' );
t_ok( false !== strpos( $box, 'more – the customer gets a separate invoice for it.' ), 'what it means for the price' );
t_ok( (bool) preg_match( '/class="button button-primary lp-missing-apply lp-missing-confirm" href="[^"]*apply_mode=replace[^"]*" data-confirm="Replace 1 × [^"]*The customer is sent an invoice of[^"]*">Replace the missing item<\/a>/', $box ), 'one primary button, with a confirmation that says what happens' );
t_ok( (bool) preg_match( '/class="button-link lp-missing-apply lp-missing-confirm" href="[^"]*apply_mode=add/', $box ), '«add as a separate line» is a quiet link' );
t_ok( (bool) preg_match( '/apply_mode=reduce[^"]*" data-confirm="[^"]*">Remove from the order \(−[^)]+\)<\/a>/', $box ), 'removal button shows the amount' );
t_ok( (bool) preg_match( '/class="button-link lp-missing-apply lp-missing-confirm" href="[^"]*apply_mode=refund/', $box ), 'refund is a quiet link' );
t_ok( false !== strpos( $box, '2 answered – ready to apply' ), 'summary counts answered lines' );

apply_via_handler( $ou->get_id(), $uids[0], 'alternative', 'replace' );
$box = render_box( $ou );
t_ok( (bool) preg_match( '/class="lp-line__done">.*Replaced with 1 × ' . preg_quote( $B->get_name(), '/' ) . '/', $box ), 'done line says what it was replaced with' );
t_ok( false !== strpos( $box, 'Replacement for ' . $A->get_name() ), 'the new line says what it replaces' );
$note = wc_get_order_notes( array( 'order_id' => $ou->get_id(), 'limit' => 1 ) );
$apply_note = '';
foreach ( wc_get_order_notes( array( 'order_id' => $ou->get_id() ) ) as $n ) {
	if ( false !== strpos( $n->content, 'Replacement applied' ) ) {
		$apply_note = $n->content;
	}
}
t_ok( 0 === strpos( $apply_note, 'Replacement applied (replaced on the line): 1 × ' . $A->get_name() . ' → ' . $B->get_name() . '.' ) && strlen( wp_strip_all_tags( $apply_note ) ) < 260, 'short order note: ' . wp_strip_all_tags( $apply_note ) );

$hidden = apply_filters( 'woocommerce_hidden_order_itemmeta', array() );
t_ok( in_array( '_lp_missing_alt_pricing_source', $hidden, true ) && in_array( '_lp_missing_alt_original_item_id', $hidden, true ) && in_array( '_lp_missing_moved_qty', $hidden, true ), 'internal line fields are hidden on the order screen' );

// Norwegian admin.
$nb = function () {
	return 'nb_NO';
};
unload_textdomain( 'lp-missing' );
add_filter( 'locale', $nb );
add_filter( 'determine_locale', $nb );
t_eq( 'Manglende varer', __( 'Missing items', 'lp-missing' ), 'box title in Norwegian' );
t_eq( '2 har svart – kan utføres', sprintf( _n( '%d answered – ready to apply', '%d answered – ready to apply', 2, 'lp-missing' ), 2 ), 'plural forms in Norwegian' );
t_ok( 0 === strpos( sprintf( _n( 'Completed while %1$d missing item was not settled. %2$s may charge the full amount: settle the item in the Missing items box, and a refund is then sent to the customer.', 'Completed while %1$d missing items were not settled. %2$s may charge the full amount: settle the items in the Missing items box, and refunds are then sent to the customer.', 2, 'lp-missing' ), 2, 'Dintero' ), 'Fullført mens 2 manglende varer ikke var avklart.' ), 'plural form picked for 2' );
$box_nb = render_box( $ou );
t_ok( false !== strpos( $box_nb, 'Hvor mange mangler?' ) && false !== strpos( $box_nb, 'Byttet med' ), 'the box is Norwegian' );
remove_filter( 'locale', $nb );
remove_filter( 'determine_locale', $nb );
unload_textdomain( 'lp-missing' );
t_eq( 'Missing / Problem Items', __( 'Missing / Problem Items', 'lp-missing' ), 'back to English' );

restore_settings( $orig_settings );
t_summary();
