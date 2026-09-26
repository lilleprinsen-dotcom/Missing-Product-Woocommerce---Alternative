<?php
// Notifications, scheduling and automatic actions. Run with: wp eval-file tests/integration/test-notify.php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/portal-helpers.php';

echo "HPOS: " . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . "\n";

// ---------- Helpers ----------
$lp_settings_before = get_option( 'lp_missing_settings', array() );
$lp_tz_before       = get_option( 'timezone_string' );
$lp_offset_before   = get_option( 'gmt_offset' );

function lp_set( $changes ) {
	LP_Missing_Settings::update_settings( array_merge( LP_Missing_Settings::get_settings( true ), $changes ) );
}
function pending_ids( $hook, $args ) {
	return as_get_scheduled_actions( array( 'hook' => $hook, 'args' => $args, 'group' => 'lp-missing', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50 ), 'ids' );
}
function run_pending( $hook, $args ) {
	$ids = pending_ids( $hook, $args );
	foreach ( $ids as $id ) {
		ActionScheduler::runner()->process_action( $id, 'lp-test' );
	}
	return count( $ids );
}
function mails_to( $address ) {
	return array_values( array_filter( $GLOBALS['lp_mails'], function ( $m ) use ( $address ) {
		return false !== strpos( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'], $address );
	} ) );
}
function mails_with_subject( $needle ) {
	return array_values( array_filter( $GLOBALS['lp_mails'], function ( $m ) use ( $needle ) { return false !== strpos( $m['subject'], $needle ); } ) );
}
function customer_notes( $order_id ) {
	return array_map( function ( $n ) { return $n->content; }, wc_get_order_notes( array( 'order_id' => $order_id, 'type' => 'customer' ) ) );
}
function internal_notes( $order_id ) {
	return array_map( function ( $n ) { return $n->content; }, wc_get_order_notes( array( 'order_id' => $order_id, 'type' => 'internal' ) ) );
}
function notes_contain( $notes, $needle ) {
	foreach ( $notes as $note ) {
		if ( false !== strpos( $note, $needle ) ) {
			return true;
		}
	}
	return false;
}
// Like admin_save(), but through the real order-screen hook, so the lifecycle batches the whole save.
function admin_save_batched( $order, $fields_by_item ) {
	reset_request();
	wp_set_current_user( 1 );
	$_POST['lp_missing_nonce'] = wp_create_nonce( 'lp_missing_metabox' );
	$_POST['lp_missing_items'] = array();
	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		$_POST['lp_missing_items'][ $item_id ] = array_merge( array( 'qty_missing' => '0', 'notes' => '', 'internal_notes' => '' ), isset( $fields_by_item[ $item_id ] ) ? $fields_by_item[ $item_id ] : array() );
	}
	do_action( 'woocommerce_process_shop_order_meta', $order->get_id(), wc_get_order( $order->get_id() ) );
}
// What the portal does when the customer answers: store the decision and fire lp_missing_item_updated.
function simulate_decision( $order_id, $item_id, $changes, $alt = null ) {
	$order = wc_get_order( $order_id );
	$item  = $order->get_item( $item_id, false );
	$old   = call( 'get_item_data', $item );
	$new   = array_merge( $old, $changes, array( 'last_updated' => time() ) );
	if ( $alt ) {
		$new['selected_alt_id']  = $alt->get_id();
		$new['qty_alt']          = isset( $changes['qty_alt'] ) ? $changes['qty_alt'] : $old['qty_missing'];
		$new['pricing_snapshot'] = LP_Missing_Pricing::get_frozen_pricing_snapshot( $order, $item, $new, $alt, $new['qty_alt'] );
		$new['decision_made_at'] = time();
	}
	$item->update_meta_data( '_lp_missing_data', $new );
	$item->save();
	do_action( 'lp_missing_item_updated', $order, $item_id, $new, $old );
}
function set_line( $order_id, $item_id, $changes ) {
	$item = wc_get_order( $order_id )->get_item( $item_id, false );
	$item->update_meta_data( '_lp_missing_data', array_merge( call( 'get_item_data', $item ), $changes ) );
	$item->save();
}
function paid_order( $product, $qty ) {
	return make_order( $product, $qty ); // processing = paid
}
function unpaid_order( $product, $qty ) {
	$o = wc_create_order();
	$o->set_billing_first_name( 'Kari' );
	$o->set_billing_email( 'kunde@example.com' );
	$o->set_billing_country( 'NO' );
	$o->add_product( wc_get_product( $product->get_id() ), $qty );
	$o->calculate_totals( true );
	$o->set_status( 'on-hold' );
	$o->save();
	return wc_get_order( $o->get_id() );
}

$GLOBALS['lp_hooks'] = array();
foreach ( array( 'lp_missing_customer_notified' => 2, 'lp_missing_before_apply_decision' => 6, 'lp_missing_after_apply_decision' => 6, 'lp_missing_surcharge_order_created' => 3, 'lp_missing_deadline_action' => 3 ) as $lp_hook => $lp_n ) {
	add_action( $lp_hook, function ( ...$args ) use ( $lp_hook ) { $GLOBALS['lp_hooks'][] = array( $lp_hook, $args ); }, 10, $lp_n );
}
function hooks_named( $hook ) {
	return array_values( array_filter( $GLOBALS['lp_hooks'], function ( $h ) use ( $hook ) { return $h[0] === $hook; } ) );
}

$staff = 'lager@example.com';
lp_set( array(
	'reminder_delay_days'      => 3,
	'reminder_max_count'       => 3,
	'reminder_max_age_days'    => 7,
	'reminder_window_start'    => 0,
	'reminder_window_end'      => 24,
	'deadline_action'          => 'none',
	'decision_deadline_days'   => 5,
	'deadline_hour'            => 12,
	'notify_staff_on_decision' => 'yes',
	'staff_notification_email' => $staff,
	'alt_price_handling'       => 'charge_customer',
	'store_covers_below'       => 0,
) );
update_option( 'woocommerce_lp_missing_staff_decision_settings', array( 'enabled' => 'yes' ) );
update_option( 'woocommerce_lp_missing_staff_deadline_settings', array( 'enabled' => 'yes' ) );
update_option( 'woocommerce_customer_note_settings', array( 'enabled' => 'yes' ) );

// ---------- Scheduler: Action Scheduler ----------
echo "\n[Scheduler] Action Scheduler instead of WP-Cron\n";
t_ok( LP_Missing_Scheduler::uses_action_scheduler(), 'Action Scheduler is used' );
$o   = make_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $B->get_id() ) ) ) );
$args = array( $o->get_id() );
t_eq( 1, count( pending_ids( 'lp_missing_order_reminder', $args ) ), 'one pending order reminder in group lp-missing' );
t_ok( ! wp_next_scheduled( 'lp_missing_order_reminder', $args ) && ! wp_next_scheduled( 'lp_missing_send_reminder', array( $o->get_id(), $iid ) ), 'nothing scheduled in WP-Cron' );
$next = call( 'get_next_reminder_timestamp', $o );
t_eq( LP_Missing_Scheduler::next( 'lp_missing_order_reminder', $args ), $next, 'get_next_reminder_timestamp() returns the pending action time' );
t_ok( abs( $next - ( time() + 3 * DAY_IN_SECONDS ) ) < 120, 'first reminder 3 days after the email (window 0-24)' );
t_eq( $next, item_data( $o->get_id(), $iid )['reminder_scheduled_for'], 'line shows the order reminder time' );
LP_Missing_Lifecycle::sync_order_schedule( wc_get_order( $o->get_id() ) );
LP_Missing_Lifecycle::sync_order_schedule( wc_get_order( $o->get_id() ), true );
t_eq( 1, count( pending_ids( 'lp_missing_order_reminder', $args ) ), 're-syncing never adds a second pending reminder' );
LP_Missing_Scheduler::schedule_single( time() + 100, 'lp_missing_order_reminder', $args );
LP_Missing_Scheduler::schedule_single( time() + 200, 'lp_missing_order_reminder', $args );
t_eq( 1, count( pending_ids( 'lp_missing_order_reminder', $args ) ), 'schedule_single replaces the pending action' );
t_ok( abs( LP_Missing_Scheduler::next( 'lp_missing_order_reminder', $args ) - ( time() + 200 ) ) < 5, 'replaced with the new time' );
// The reminder job re-schedules itself while it runs (the unique check sees the running action).
LP_Missing_Scheduler::schedule_single( time() - 10, 'lp_missing_order_reminder', $args );
$GLOBALS['lp_mails'] = array();
t_eq( 1, run_pending( 'lp_missing_order_reminder', $args ), 'due reminder action executed by the Action Scheduler runner' );
t_eq( 1, count( mails_with_subject( 'Påminnelse' ) ), 'reminder email sent by the action' );
t_eq( 1, count( pending_ids( 'lp_missing_order_reminder', $args ) ), 'next reminder scheduled from inside the running action' );
t_eq( 1, item_data( $o->get_id(), $iid )['reminder_count'], 'reminder counted on the line' );
// Cancelled order: no jobs left.
wc_get_order( $o->get_id() )->update_status( 'cancelled' );
t_eq( 0, count( pending_ids( 'lp_missing_order_reminder', $args ) ), 'cancelling the order cancels the reminder' );
t_eq( 0, call( 'get_next_reminder_timestamp', $o ), 'no next reminder for a cancelled order' );

echo "\n[Scheduler] Daily cleanup and cleanup jobs\n";
LP_Missing_Scheduler::unschedule( 'lp_missing_daily_cleanup' );
delete_option( LP_Missing_Scheduler::MAINTENANCE_OPTION );
LP_Missing_Scheduler::maybe_maintain();
t_ok( as_has_scheduled_action( 'lp_missing_daily_cleanup', array(), 'lp-missing' ), 'daily cleanup is a recurring Action Scheduler action' );
$ids = as_get_scheduled_actions( array( 'hook' => 'lp_missing_daily_cleanup', 'group' => 'lp-missing', 'status' => ActionScheduler_Store::STATUS_PENDING ), 'objects' );
$first = reset( $ids );
t_ok( $first && $first->get_schedule()->is_recurring(), 'daily cleanup action is recurring' );
LP_Missing_Scheduler::maybe_maintain();
LP_Missing_Scheduler::ensure_daily_cleanup();
t_eq( 1, count( as_get_scheduled_actions( array( 'hook' => 'lp_missing_daily_cleanup', 'group' => 'lp-missing', 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) ), 'never two daily cleanups' );
$o   = make_order( $A, 1 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
admin_save( wc_get_order( $o->get_id() ), array() ); // cleared -> resolved
$cleanup_at = LP_Missing_Scheduler::next( 'lp_missing_cleanup_order', array( $o->get_id() ) );
t_ok( $cleanup_at > time() + 25 * DAY_IN_SECONDS, 'cleanup of a resolved order scheduled in Action Scheduler (30 days)' );
t_ok( ! wp_next_scheduled( 'lp_missing_cleanup_order', array( $o->get_id() ) ), 'cleanup not in WP-Cron' );

echo "\n[Scheduler] Migration from WP-Cron (upgrade step)\n";
$o   = make_order( $A, 3 );
$o->add_product( wc_get_product( $C->get_id() ), 2 );
$o->calculate_totals( true );
$ids = array_keys( wc_get_order( $o->get_id() )->get_items() );
admin_save( wc_get_order( $o->get_id() ), array( $ids[0] => array( 'missing' => '1', 'qty_missing' => '1' ), $ids[1] => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
LP_Missing_Scheduler::unschedule( 'lp_missing_order_reminder', array( $o->get_id() ) );
LP_Missing_Scheduler::unschedule( 'lp_missing_daily_cleanup' );
// What 1.1 left in WP-Cron: per-line reminders, an order cleanup and the daily event.
$t1 = time() + 2 * DAY_IN_SECONDS;
$t2 = time() + 3 * DAY_IN_SECONDS;
wp_schedule_single_event( $t2, 'lp_missing_send_reminder', array( $o->get_id(), $ids[0] ) );
wp_schedule_single_event( $t1, 'lp_missing_send_reminder', array( $o->get_id(), $ids[1] ) );
wp_schedule_single_event( $t2, 'lp_missing_cleanup_order', array( 999999 ) );
wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lp_missing_daily_cleanup' );
t_ok( (bool) wp_next_scheduled( 'lp_missing_send_reminder', array( $o->get_id(), $ids[1] ) ), 'legacy per-line reminder in WP-Cron' );
t_eq( $t1, call( 'get_next_reminder_timestamp', $o ), 'get_next_reminder_timestamp() still sees an unmigrated legacy reminder' );
update_option( 'lp_missing_db_version', '1.1.0' );
delete_transient( 'lp_missing_upgrading' );
LP_Missing_Plugin::maybe_upgrade();
t_eq( LP_Missing_Plugin::VERSION, get_option( 'lp_missing_db_version' ), 'upgrade ran to the current version' );
t_ok( ! wp_next_scheduled( 'lp_missing_send_reminder', array( $o->get_id(), $ids[0] ) ) && ! wp_next_scheduled( 'lp_missing_send_reminder', array( $o->get_id(), $ids[1] ) ), 'legacy reminders cleared from WP-Cron' );
t_ok( ! wp_next_scheduled( 'lp_missing_cleanup_order', array( 999999 ) ) && ! wp_next_scheduled( 'lp_missing_daily_cleanup' ), 'cleanup events cleared from WP-Cron' );
t_eq( 1, count( pending_ids( 'lp_missing_order_reminder', array( $o->get_id() ) ) ), 'two per-line reminders became one order reminder' );
t_eq( $t1, LP_Missing_Scheduler::next( 'lp_missing_order_reminder', array( $o->get_id() ) ), 'order reminder at the earliest line reminder (window 0-24)' );
t_eq( $t2, LP_Missing_Scheduler::next( 'lp_missing_cleanup_order', array( 999999 ) ), 'order cleanup moved with its time' );
t_ok( as_has_scheduled_action( 'lp_missing_daily_cleanup', array(), 'lp-missing' ), 'daily cleanup moved to Action Scheduler' );
LP_Missing_Scheduler::unschedule( 'lp_missing_cleanup_order', array( 999999 ) );
// An old event that still fires is handled as the order reminder.
wp_schedule_single_event( time() + 60, 'lp_missing_send_reminder', array( $o->get_id(), $ids[0] ) );
wp_schedule_single_event( time() + 90, 'lp_missing_send_reminder', array( $o->get_id(), $ids[1] ) );
$GLOBALS['lp_mails'] = array();
do_action( 'lp_missing_send_reminder', $o->get_id(), $ids[0] );
t_eq( 1, count( mails_with_subject( 'Påminnelse' ) ), 'old lp_missing_send_reminder event still sends the (order) reminder' );
t_ok( ! wp_next_scheduled( 'lp_missing_send_reminder', array( $o->get_id(), $ids[1] ) ), 'the other old events of the order are dropped' );
t_eq( 1, count( pending_ids( 'lp_missing_order_reminder', array( $o->get_id() ) ) ), 'next order reminder scheduled in Action Scheduler' );
// Self-check also moves leftovers (e.g. an event added by an old copy during the upgrade).
wp_schedule_single_event( time() + 500, 'lp_missing_send_reminder', array( $o->get_id(), $ids[0] ) );
delete_option( LP_Missing_Scheduler::MAINTENANCE_OPTION );
LP_Missing_Scheduler::maybe_maintain();
t_ok( ! wp_next_scheduled( 'lp_missing_send_reminder', array( $o->get_id(), $ids[0] ) ), 'throttled self-check migrates WP-Cron leftovers' );

echo "\n[Scheduler] WP-Cron fallback without Action Scheduler\n";
add_filter( 'lp_missing_use_action_scheduler', '__return_false' );
$o   = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$cron_ts = wp_next_scheduled( 'lp_missing_order_reminder', array( $o->get_id() ) );
t_ok( $cron_ts > time(), 'fallback schedules the order reminder in WP-Cron' );
t_eq( $cron_ts, call( 'get_next_reminder_timestamp', $o ), 'get_next_reminder_timestamp() reads the fallback' );
remove_filter( 'lp_missing_use_action_scheduler', '__return_false' );
delete_option( LP_Missing_Scheduler::MAINTENANCE_OPTION );
LP_Missing_Scheduler::maybe_maintain();
t_ok( ! wp_next_scheduled( 'lp_missing_order_reminder', array( $o->get_id() ) ), 'back on Action Scheduler: WP-Cron job moved away' );
t_eq( $cron_ts, LP_Missing_Scheduler::next( 'lp_missing_order_reminder', array( $o->get_id() ) ), '... to Action Scheduler with the same time' );

echo "\n[Scheduler] Deactivation\n";
do_action( 'deactivate_' . plugin_basename( LP_MISSING_FILE ) );
t_eq( 0, count( as_get_scheduled_actions( array( 'group' => 'lp-missing', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ), 'ids' ) ), 'deactivation cancels every pending action of the group' );
t_ok( ! wp_next_scheduled( 'lp_missing_daily_cleanup' ), 'no WP-Cron leftovers' );
delete_option( 'lp_missing_resync_offset' );
LP_Missing_Lifecycle::resync_open_orders();
t_ok( LP_Missing_Scheduler::next( 'lp_missing_order_reminder', array( $o->get_id() ) ) > time(), 'daily resync re-arms reminders of open cases' );
LP_Missing_Scheduler::maybe_maintain();
t_ok( as_has_scheduled_action( 'lp_missing_daily_cleanup', array(), 'lp-missing' ), 'daily cleanup re-created after reactivation' );

// ---------- Reminder window ----------
echo "\n[Reminders] Window in the site time zone\n";
update_option( 'timezone_string', 'Europe/Oslo' );
lp_set( array( 'reminder_window_start' => 9, 'reminder_window_end' => 20 ) );
$tz  = wp_timezone();
$day = new DateTimeImmutable( '2026-10-05 00:00:00', $tz ); // Monday, CEST
$at  = function ( $hm ) use ( $day ) { list( $h, $m ) = explode( ':', $hm ); return $day->setTime( (int) $h, (int) $m )->getTimestamp(); };
t_eq( $at( '09:00' ), LP_Missing_Lifecycle::push_into_reminder_window( $at( '07:30' ) ), '07:30 moves to 09:00 the same day' );
t_eq( $at( '12:15' ), LP_Missing_Lifecycle::push_into_reminder_window( $at( '12:15' ) ), '12:15 stays' );
t_eq( $day->modify( '+1 day' )->setTime( 9, 0 )->getTimestamp(), LP_Missing_Lifecycle::push_into_reminder_window( $at( '20:00' ) ), '20:00 (window end) moves to 09:00 next day' );
t_eq( $day->modify( '+1 day' )->setTime( 9, 0 )->getTimestamp(), LP_Missing_Lifecycle::push_into_reminder_window( $at( '23:59' ) ), '23:59 moves to 09:00 next day' );
t_eq( $at( '09:00' ) + 3 * DAY_IN_SECONDS, LP_Missing_Lifecycle::get_reminder_time( $at( '06:00' ) ), 'reminder = 3 days later, pushed into the window' );
$o   = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$local_hour = (int) wp_date( 'G', call( 'get_next_reminder_timestamp', $o ) );
t_ok( $local_hour >= 9 && $local_hour < 20, "scheduled reminder is inside the window (local hour $local_hour)" );
// A job that runs outside the window waits for the window instead of sending.
$h = (int) wp_date( 'G' );
if ( $h < 23 ) {
	lp_set( array( 'reminder_window_start' => $h + 1, 'reminder_window_end' => 24 ) );
} else {
	lp_set( array( 'reminder_window_start' => 0, 'reminder_window_end' => 23 ) );
}
$GLOBALS['lp_mails'] = array();
call( 'handle_order_reminder', $o->get_id() );
t_eq( 0, count( $GLOBALS['lp_mails'] ), 'reminder job outside the window sends nothing' );
$moved = LP_Missing_Scheduler::next( 'lp_missing_order_reminder', array( $o->get_id() ) );
t_eq( LP_Missing_Lifecycle::push_into_reminder_window( time() ), $moved, 'and is moved to when the window opens' );
t_ok( LP_Missing_Lifecycle::is_in_reminder_window( $moved ), 'new time is inside the window' );
lp_set( array( 'reminder_window_start' => 0, 'reminder_window_end' => 24 ) );
call( 'handle_order_reminder', $o->get_id() );
t_eq( 1, count( mails_with_subject( 'Påminnelse' ) ), 'inside the window the job sends the reminder' );
update_option( 'timezone_string', $lp_tz_before );
update_option( 'gmt_offset', $lp_offset_before );

// ---------- One email / one reminder per order ----------
echo "\n[Reminders] One email and one reminder per order\n";
$o = make_order( $A, 5 );
$o->add_product( wc_get_product( $C->get_id() ), 2 );
$o->add_product( wc_get_product( $B->get_id() ), 1 );
$o->calculate_totals( true );
$o   = wc_get_order( $o->get_id() );
$ids = array_keys( $o->get_items() );
$GLOBALS['lp_mails'] = array();
$GLOBALS['lp_hooks'] = array();
admin_save_batched( $o, array(
	$ids[0] => array( 'missing' => '1', 'qty_missing' => '2', 'notes' => 'Kommer igjen om to uker', 'alternatives' => array( $B->get_id() ) ),
	$ids[1] => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ),
) );
$portal_mails = mails_with_subject( 'Velg erstatning' );
t_eq( 1, count( $portal_mails ), 'saving two missing lines sends one customer email' );
$m = $portal_mails ? $portal_mails[0] : array( 'subject' => '', 'message' => '', 'to' => '' );
t_eq( 'Velg erstatning for 2 vare(r) i ordre #' . $o->get_order_number(), $m['subject'], 'action-oriented subject with count and order number' );
t_ok( false !== strpos( $m['message'], 'Bleier str 4' ) && false !== strpos( $m['message'], 'Bleier eco' ), 'email lists both missing items' );
t_ok( false === strpos( $m['message'], 'Bleier str 5</strong>' ), 'lines that are not missing are not listed' );
t_ok( false !== strpos( $m['message'], '2 av 5 stk' ) && false !== strpos( $m['message'], '1 av 2 stk' ), 'missing quantities shown' );
t_ok( false !== strpos( $m['message'], 'Kommer igjen om to uker' ), 'staff note visible to the customer is shown' );
t_ok( false !== strpos( $m['message'], 'Forslag til erstatning: Bleier str 5' ), 'suggested alternatives named' );
t_ok( false !== strpos( $m['message'], 'fjerne varen fra ordren' ), 'removal offer mentioned' );
t_ok( false !== strpos( $m['message'], 'oid=' . $o->get_id() ) && false !== strpos( $m['message'], 'lpk=' ), 'magic link included' );
t_ok( false !== strpos( $m['message'], 'Har du spørsmål' ), 'default additional content shown' );
t_ok( false === strpos( $m['message'], 'Hvis vi ikke hører fra deg' ), 'no deadline sentence without a deadline' );
t_eq( 'kunde@example.com', $m['to'], 'sent to the billing email' );
$d0 = item_data( $o->get_id(), $ids[0] );
$d1 = item_data( $o->get_id(), $ids[1] );
t_ok( $d0['notified_at'] >= time() - 60 && $d1['notified_at'] >= time() - 60, 'notified_at set on both lines' );
t_eq( 0, item_data( $o->get_id(), $ids[2] )['notified_at'], 'line that is not missing has no notified_at' );
t_eq( 1, count( hooks_named( 'lp_missing_customer_notified' ) ), 'lp_missing_customer_notified fired once' );
t_eq( 'initial', hooks_named( 'lp_missing_customer_notified' )[0][1][1], '... with type initial' );
t_eq( 1, count( pending_ids( 'lp_missing_order_reminder', array( $o->get_id() ) ) ), 'one reminder job for the order' );
t_eq( $d0['reminder_scheduled_for'], $d1['reminder_scheduled_for'], 'both lines share the order reminder time' );
// The reminder lists all waiting lines; a line with a decision drops out.
$GLOBALS['lp_mails'] = array();
$GLOBALS['lp_hooks'] = array();
call( 'send_order_reminder', wc_get_order( $o->get_id() ) );
$rem = mails_with_subject( 'Påminnelse' );
t_eq( 1, count( $rem ), 'one reminder for two waiting lines' );
t_eq( 'Påminnelse: velg erstatning for 2 vare(r) i ordre #' . $o->get_order_number(), $rem ? $rem[0]['subject'] : '', 'reminder subject' );
t_ok( $rem && false !== strpos( $rem[0]['message'], 'Bleier str 4' ) && false !== strpos( $rem[0]['message'], 'Bleier eco' ), 'reminder lists both lines' );
t_eq( 'reminder', hooks_named( 'lp_missing_customer_notified' ) ? hooks_named( 'lp_missing_customer_notified' )[0][1][1] : '', 'lp_missing_customer_notified fired with type reminder' );
t_eq( 1, item_data( $o->get_id(), $ids[0] )['reminder_count'], 'line 1 counted' );
t_eq( 1, item_data( $o->get_id(), $ids[1] )['reminder_count'], 'line 2 counted' );
$GLOBALS['lp_mails'] = array();
call( 'send_order_reminder', wc_get_order( $o->get_id() ) );
t_eq( 0, count( $GLOBALS['lp_mails'] ), 'no second reminder within the hour' );
simulate_decision( $o->get_id(), $ids[1], array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
set_line( $o->get_id(), $ids[0], array( 'last_reminder_at' => time() - 3 * DAY_IN_SECONDS ) );
$GLOBALS['lp_mails'] = array();
call( 'send_order_reminder', wc_get_order( $o->get_id() ) );
$rem = mails_with_subject( 'Påminnelse' );
t_ok( $rem && false !== strpos( $rem[0]['subject'], '1 vare(r)' ) && false === strpos( $rem[0]['message'], 'Bleier eco' ), 'decided line is no longer in the reminder' );
t_eq( 1, item_data( $o->get_id(), $ids[1] )['reminder_count'], 'decided line is not counted again' );

echo "\n[Reminders] Escalation\n";
lp_set( array( 'reminder_max_count' => 2 ) );
$o   = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
call( 'send_order_reminder', wc_get_order( $o->get_id() ) );
t_ok( ! item_data( $o->get_id(), $iid )['needs_attention'], 'not escalated after 1 of 2 reminders' );
set_line( $o->get_id(), $iid, array( 'last_reminder_at' => time() - 3 * DAY_IN_SECONDS ) );
call( 'send_order_reminder', wc_get_order( $o->get_id() ) );
$d = item_data( $o->get_id(), $iid );
t_eq( 2, $d['reminder_count'], 'two reminders counted' );
t_ok( $d['needs_attention'], 'escalated after the maximum number of reminders' );
t_eq( 'yes', wc_get_order( $o->get_id() )->get_meta( '_lp_missing_needs_attention' ), 'order flagged for follow-up' );
t_ok( notes_contain( internal_notes( $o->get_id() ), 'Manual follow-up needed' ), 'staff note about the follow-up' );
t_eq( 0, call( 'get_next_reminder_timestamp', $o ), 'no more reminders for an escalated line' );
lp_set( array( 'reminder_max_count' => 3 ) );
// The email cannot be sent: nothing is counted, but an old line still escalates.
update_option( 'woocommerce_lp_missing_customer_reminder_settings', array( 'enabled' => 'no' ) );
WC()->mailer()->get_emails()['lp_missing_customer_reminder']->enabled = 'no';
$o   = make_order( $A, 1 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
call( 'send_order_reminder', wc_get_order( $o->get_id() ) );
t_eq( 0, item_data( $o->get_id(), $iid )['reminder_count'], 'unsent reminder is not counted' );
t_ok( ! item_data( $o->get_id(), $iid )['needs_attention'], 'young line not escalated' );
t_ok( call( 'get_next_reminder_timestamp', $o ) > time(), 'next attempt scheduled' );
set_line( $o->get_id(), $iid, array( 'first_missing_at' => time() - 8 * DAY_IN_SECONDS ) );
call( 'send_order_reminder', wc_get_order( $o->get_id() ) );
t_ok( item_data( $o->get_id(), $iid )['needs_attention'], 'line older than the maximum age escalates without the email' );
update_option( 'woocommerce_lp_missing_customer_reminder_settings', array( 'enabled' => 'yes' ) );
WC()->mailer()->get_emails()['lp_missing_customer_reminder']->enabled = 'yes';

// ---------- Staff email (S1) ----------
echo "\n[S1] Staff email when the customer decides\n";
$o   = make_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $B->get_id(), $C->get_id() ) ) ) );
$GLOBALS['lp_mails'] = array();
portal_jar_reset();
portal_login( $o );
portal_save( $o, array( $iid => 'alt:' . $B->get_id() ), array( $iid => 2 ) );
t_eq( 'alt_pending', item_data( $o->get_id(), $iid )['status'], 'customer chose in the portal' );
$sm = mails_to( $staff );
t_eq( 1, count( $sm ), 'staff email sent to the staff address' );
$sm = $sm ? $sm[0] : array( 'subject' => '', 'message' => '' );
t_ok( false !== strpos( $sm['subject'], '#' . $o->get_order_number() ) && false !== strpos( $sm['subject'], 'Customer decided' ), 'staff subject names the order' );
t_ok( false !== strpos( $sm['message'], 'Alternative: Bleier str 5 × 2' ), 'staff email shows the choice' );
t_ok( false !== strpos( $sm['message'], '100,00' ) || false !== strpos( $sm['message'], '100.00' ), 'staff email shows the frozen price difference (2 x 50)' );
t_ok( false !== strpos( $sm['message'], 'surcharge order' ), 'staff email explains the surcharge' );
t_ok( false !== strpos( $sm['message'], 'action=edit' ) && ( false !== strpos( $sm['message'], 'post=' . $o->get_id() ) || false !== strpos( $sm['message'], 'id=' . $o->get_id() ) ), 'staff email links to the order screen' );
t_eq( 0, count( mails_with_subject( 'Velg erstatning' ) ), 'no customer email for a decision' );
t_eq( 0, call( 'get_next_reminder_timestamp', $o ), 'reminder cancelled once the customer answered' );
// Changing the decision notifies again; the same decision does not.
$GLOBALS['lp_mails'] = array();
simulate_decision( $o->get_id(), $iid, array( 'status' => 'alt_pending' ), $C );
$sm = mails_to( $staff );
t_eq( 1, count( $sm ), 'changed decision notifies staff' );
t_ok( $sm && false !== strpos( $sm[0]['message'], 'Changed from: Alternative: Bleier str 5' ) && false !== strpos( $sm[0]['message'], 'Bleier eco' ), 'staff email shows old and new choice' );
t_ok( $sm && false !== strpos( $sm[0]['message'], 'less incl. VAT' ), 'cheaper alternative explained' );
$GLOBALS['lp_mails'] = array();
simulate_decision( $o->get_id(), $iid, array( 'notes' => 'x' ) );
t_eq( 0, count( mails_to( $staff ) ), 'other changes do not notify staff' );
simulate_decision( $o->get_id(), $iid, array( 'status' => 'declined', 'selected_alt_id' => 0, 'qty_alt' => 0, 'pricing_snapshot' => array() ) );
$sm = mails_to( $staff );
t_ok( $sm && false !== strpos( $sm[0]['message'], 'Declined all suggested alternatives' ), 'decline notifies staff' );
$o2   = make_order( $A, 2 );
$iid2 = first_item_id( $o2 );
admin_save( $o2, array( $iid2 => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
$GLOBALS['lp_mails'] = array();
simulate_decision( $o2->get_id(), $iid2, array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
$sm = mails_to( $staff );
t_ok( $sm && false !== strpos( $sm[0]['message'], 'Remove the missing quantity' ), 'approved removal notifies staff' );
$GLOBALS['lp_mails'] = array();
apply_via_handler( $o2->get_id(), $iid2, 'delete', 'reduce' );
t_eq( 0, count( mails_to( $staff ) ), 'applying a decision does not send the staff decision email' );
// Switches.
$o3   = make_order( $A, 2 );
$iid3 = first_item_id( $o3 );
admin_save( $o3, array( $iid3 => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
lp_set( array( 'notify_staff_on_decision' => 'no' ) );
$GLOBALS['lp_mails'] = array();
simulate_decision( $o3->get_id(), $iid3, array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
t_eq( 0, count( mails_to( $staff ) ), 'no staff email when "Email staff" is off' );
lp_set( array( 'notify_staff_on_decision' => 'yes', 'staff_notification_email' => '' ) );
simulate_decision( $o3->get_id(), $iid3, array( 'status' => 'declined' ) );
t_eq( 1, count( mails_to( get_option( 'admin_email' ) ) ), 'without a staff address the admin email gets it' );
lp_set( array( 'staff_notification_email' => $staff ) );
WC()->mailer()->get_emails()['lp_missing_staff_decision']->enabled = 'no';
$GLOBALS['lp_mails'] = array();
simulate_decision( $o3->get_id(), $iid3, array( 'status' => 'delete_pending' ) );
t_eq( 0, count( mails_to( $staff ) ), 'WooCommerce email setting can switch it off' );
WC()->mailer()->get_emails()['lp_missing_staff_decision']->enabled = 'yes';

// ---------- Customer notes (S2) and apply hooks ----------
echo "\n[S2] Customer-visible notes after applying\n";
$o   = make_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $B->get_id() ) ) ) );
simulate_decision( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 2 ), $B );
$GLOBALS['lp_hooks'] = array();
$GLOBALS['lp_mails'] = array();
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
$notes = customer_notes( $o->get_id() );
t_ok( notes_contain( $notes, 'Vi har byttet Bleier str 4 med Bleier str 5 (2 stk).' ), 'customer note describes the swap' );
t_ok( notes_contain( $notes, 'Mellomlegg på' ) && notes_contain( $notes, 'faktureres i en egen ordre' ), 'customer note mentions the surcharge order' );
t_ok( notes_contain( internal_notes( $o->get_id() ), 'Replacement applied' ), 'internal note kept' );
t_eq( 1, count( array_filter( mails_to( 'kunde@example.com' ), function ( $m ) { return false !== strpos( $m['message'], 'Vi har byttet' ); } ) ), 'customer note reaches the customer by email' );
$before = hooks_named( 'lp_missing_before_apply_decision' );
$after  = hooks_named( 'lp_missing_after_apply_decision' );
t_eq( 1, count( $before ), 'lp_missing_before_apply_decision fired' );
t_eq( 1, count( $after ), 'lp_missing_after_apply_decision fired' );
t_ok( $after && 'alternative' === $after[0][1][2] && 'replace' === $after[0][1][3] && 'success' === $after[0][1][4]['status'] && 'manual' === $after[0][1][5], 'after hook gets type, mode, result and context' );
t_ok( $after && $after[0][1][0] instanceof WC_Order && $iid === $after[0][1][1], 'after hook gets the order and item id' );
$sur = hooks_named( 'lp_missing_surcharge_order_created' );
t_eq( 1, count( $sur ), 'lp_missing_surcharge_order_created fired' );
t_ok( $sur && $sur[0][1][0] instanceof WC_Order && $sur[0][1][0]->get_parent_id() === $o->get_id() && $sur[0][1][1]->get_id() === $o->get_id() && isset( $sur[0][1][2]['delta_total_incl'] ), 'surcharge hook gets surcharge order, order and snapshot' );

// lp_missing_price_delta can change the surcharge.
$o   = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ) ) ) );
simulate_decision( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 1 ), $B );
$zero = function () { return 0; };
add_filter( 'lp_missing_price_delta', $zero );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
remove_filter( 'lp_missing_price_delta', $zero );
t_eq( 0, count( wc_get_orders( array( 'parent' => $o->get_id(), 'type' => 'shop_order', 'limit' => -1 ) ) ), 'lp_missing_price_delta filter can waive the surcharge' );

$o   = make_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'propose_delete' => '1' ) ) );
simulate_decision( $o->get_id(), $iid, array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
apply_via_handler( $o->get_id(), $iid, 'delete', 'refund' );
t_ok( notes_contain( customer_notes( $o->get_id() ), 'Vi har fjernet Bleier str 4 (2 stk) og refundert' ), 'customer note for a refund' );
$o   = make_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
simulate_decision( $o->get_id(), $iid, array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
apply_via_handler( $o->get_id(), $iid, 'delete', 'reduce' );
t_ok( notes_contain( customer_notes( $o->get_id() ), 'Vi har fjernet Bleier str 4 (1 stk) fra ordren, og ordresummen er redusert med' ), 'customer note for removing from the order' );
$o   = make_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '3', 'alternatives' => array( $C->get_id() ) ) ) );
simulate_decision( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 1 ), $C );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'add' );
$notes = customer_notes( $o->get_id() );
t_ok( notes_contain( $notes, 'Erstatningen er rimeligere' ) && notes_contain( $notes, 'Vi trenger fortsatt valget ditt for 2 stk Bleier str 4.' ), 'partial swap note: cheaper and remaining quantity' );

// ---------- Deadline (L11) ----------
echo "\n[L11] Default action after the deadline\n";
lp_set( array( 'deadline_action' => 'refund', 'decision_deadline_days' => 5, 'deadline_hour' => 12 ) );
$o   = paid_order( $A, 5 );
$iid = first_item_id( $o );
$GLOBALS['lp_mails'] = array();
$t0 = time();
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2' ) ) );
$t1 = time();
$d  = item_data( $o->get_id(), $iid );
t_ok( in_array( $d['deadline_at'], array( LP_Missing_Deadline::calculate( $t0 ), LP_Missing_Deadline::calculate( $t1 ) ), true ), 'deadline_at set when the line starts waiting' );
t_eq( $d['deadline_at'], LP_Missing_Scheduler::next( 'lp_missing_order_deadline', array( $o->get_id() ) ), 'deadline job scheduled at the deadline' );
t_eq( $d['deadline_at'], LP_Missing_Lifecycle::get_order_deadline( wc_get_order( $o->get_id() ) ), 'order deadline = line deadline' );
$cm = mails_with_subject( 'Velg erstatning' );
t_ok( $cm && false !== strpos( $cm[0]['message'], 'Hvis vi ikke hører fra deg innen' ) && false !== strpos( $cm[0]['message'], 'refunderer vi varen' ), 'customer email shows the deadline sentence' );
t_ok( call( 'get_next_reminder_timestamp', $o ) < $d['deadline_at'], 'reminder comes before the deadline' );
// No reminder that would only arrive after the deadline.
set_line( $o->get_id(), $iid, array( 'deadline_at' => time() + DAY_IN_SECONDS ) );
LP_Missing_Lifecycle::sync_order_schedule( wc_get_order( $o->get_id() ), true );
t_eq( 0, call( 'get_next_reminder_timestamp', $o ), 'no reminder planned after the deadline' );
t_ok( LP_Missing_Scheduler::next( 'lp_missing_order_deadline', array( $o->get_id() ) ) > time(), 'deadline job re-armed at the new deadline' );
// The deadline passes.
set_line( $o->get_id(), $iid, array( 'deadline_at' => time() - 60 ) );
LP_Missing_Lifecycle::sync_order_schedule( wc_get_order( $o->get_id() ) );
$GLOBALS['lp_mails'] = array();
$GLOBALS['lp_hooks'] = array();
$total_before = (float) wc_get_order( $o->get_id() )->get_total();
t_eq( 1, run_pending( 'lp_missing_order_deadline', array( $o->get_id() ) ), 'due deadline job executed by the Action Scheduler runner' );
$o = wc_get_order( $o->get_id() );
$d = item_data( $o->get_id(), $iid );
t_eq( 'delete_applied', $d['status'], 'refund: line resolved' );
t_eq( 200.0, (float) $o->get_total_refunded(), 'refund: 2 x 100 recorded' );
t_eq( 2, absint( $o->get_qty_refunded_for_item( $iid ) ), 'refund: attributed to the line' );
t_eq( $total_before, (float) $o->get_total(), 'refund: order total itself unchanged (line kept for accounting)' );
t_ok( notes_contain( customer_notes( $o->get_id() ), 'Vi fikk ikke svar fra deg innen fristen. Vi har fjernet Bleier str 4 (2 stk) og refundert' ), 'customer note explains the automatic refund' );
t_ok( notes_contain( internal_notes( $o->get_id() ), 'Decision deadline passed without an answer' ), 'internal note about the automatic action' );
$dl = hooks_named( 'lp_missing_deadline_action' );
t_eq( 1, count( $dl ), 'lp_missing_deadline_action fired' );
t_ok( $dl && $dl[0][1][0] instanceof WC_Order && $iid === $dl[0][1][1] && 'refund' === $dl[0][1][2], '... with order, item and action' );
$after = hooks_named( 'lp_missing_after_apply_decision' );
t_ok( $after && 'automatic' === $after[0][1][5] && 'delete' === $after[0][1][2] && 'refund' === $after[0][1][3], 'apply hooks report the automatic context' );
$sm = mails_with_subject( 'Decision deadline passed' );
t_eq( 1, count( $sm ), 'staff notified about the automatic action' );
t_ok( $sm && $staff === $sm[0]['to'] && false !== strpos( $sm[0]['message'], 'Bleier str 4' ) && false !== strpos( $sm[0]['message'], 'Refund of' ), 'staff email lists the refund' );
t_eq( 0, count( pending_ids( 'lp_missing_order_deadline', array( $o->get_id() ) ) ), 'no deadline job left' );
// Never twice.
$GLOBALS['lp_hooks'] = array();
call( 'handle_deadline', $o->get_id() );
LP_Missing_Apply_Service::apply_due_deadline_actions( $o->get_id() );
t_eq( 200.0, (float) wc_get_order( $o->get_id() )->get_total_refunded(), 'running the deadline job again refunds nothing more' );
t_eq( 0, count( hooks_named( 'lp_missing_deadline_action' ) ), 'and fires no action' );

echo "\n[L11] Reduce, unpaid orders, answers before the deadline\n";
lp_set( array( 'deadline_action' => 'reduce' ) );
$o   = paid_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2' ) ) );
$cm = mails_with_subject( 'Velg erstatning' );
set_line( $o->get_id(), $iid, array( 'deadline_at' => time() - 60 ) );
LP_Missing_Lifecycle::sync_order_schedule( wc_get_order( $o->get_id() ) );
$GLOBALS['lp_hooks'] = array();
run_pending( 'lp_missing_order_deadline', array( $o->get_id() ) );
$o = wc_get_order( $o->get_id() );
t_eq( 3, $o->get_item( $iid )->get_quantity(), 'reduce: line quantity 5 -> 3' );
t_eq( 300.0, (float) $o->get_total(), 'reduce: order total reduced by 2 x 100' );
t_eq( 60.0, order_tax_lines_total( $o ), 'reduce: VAT reduced' );
t_eq( 0.0, (float) $o->get_total_refunded(), 'reduce: no refund' );
t_ok( notes_contain( customer_notes( $o->get_id() ), 'ordresummen er redusert med' ), 'reduce: customer note' );
t_eq( 'reduce', hooks_named( 'lp_missing_deadline_action' ) ? hooks_named( 'lp_missing_deadline_action' )[0][1][2] : '', 'reduce: hook action' );

lp_set( array( 'deadline_action' => 'refund' ) );
$o   = unpaid_order( $A, 4 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
set_line( $o->get_id(), $iid, array( 'deadline_at' => time() - 60 ) );
LP_Missing_Lifecycle::sync_order_schedule( wc_get_order( $o->get_id() ) );
$GLOBALS['lp_hooks'] = array();
run_pending( 'lp_missing_order_deadline', array( $o->get_id() ) );
$o = wc_get_order( $o->get_id() );
t_eq( 0.0, (float) $o->get_total_refunded(), 'unpaid order: nothing refunded' );
t_eq( 300.0, (float) $o->get_total(), 'unpaid order: missing quantity removed instead (400 -> 300)' );
t_eq( 'reduce', hooks_named( 'lp_missing_deadline_action' ) ? hooks_named( 'lp_missing_deadline_action' )[0][1][2] : '', 'unpaid order: action is reduce' );

$o   = paid_order( $A, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $C->get_id() ) ) ) );
t_ok( LP_Missing_Scheduler::next( 'lp_missing_order_deadline', array( $o->get_id() ) ) > 0, 'deadline armed' );
simulate_decision( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 1 ), $C );
t_eq( 0, LP_Missing_Scheduler::next( 'lp_missing_order_deadline', array( $o->get_id() ) ), 'answer before the deadline cancels the job' );
set_line( $o->get_id(), $iid, array( 'deadline_at' => time() - 60 ) );
call( 'handle_deadline', $o->get_id() );
$o = wc_get_order( $o->get_id() );
t_eq( 'alt_pending', item_data( $o->get_id(), $iid )['status'], 'a late job leaves the answered line alone' );
t_eq( 0.0, (float) $o->get_total_refunded(), 'and refunds nothing' );

echo "\n[L11] Re-arming, locking and failures\n";
$o = paid_order( $A, 4 );
$o->add_product( wc_get_product( $C->get_id() ), 2 );
$o->calculate_totals( true );
$o   = wc_get_order( $o->get_id() );
$ids = array_keys( $o->get_items() );
admin_save( $o, array( $ids[0] => array( 'missing' => '1', 'qty_missing' => '1' ), $ids[1] => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$later = time() + 2 * DAY_IN_SECONDS;
set_line( $o->get_id(), $ids[0], array( 'deadline_at' => time() - 60 ) );
set_line( $o->get_id(), $ids[1], array( 'deadline_at' => $later ) );
LP_Missing_Lifecycle::sync_order_schedule( wc_get_order( $o->get_id() ) );
t_ok( LP_Missing_Scheduler::next( 'lp_missing_order_deadline', array( $o->get_id() ) ) <= time(), 'job at the earliest deadline' );
call( 'acquire_apply_lock', $o->get_id() );
call( 'handle_deadline', $o->get_id() );
t_eq( 'pending', item_data( $o->get_id(), $ids[0] )['status'], 'locked order: nothing applied' );
$retry = LP_Missing_Scheduler::next( 'lp_missing_order_deadline', array( $o->get_id() ) );
t_ok( $retry > time() && $retry <= time() + 600, 'locked order: job retried a few minutes later' );
call( 'release_apply_lock', $o->get_id() );
call( 'handle_deadline', $o->get_id() );
t_eq( 'delete_applied', item_data( $o->get_id(), $ids[0] )['status'], 'first line handled once the lock is free' );
t_eq( 'pending', item_data( $o->get_id(), $ids[1] )['status'], 'line with a later deadline still waiting' );
t_eq( $later, LP_Missing_Scheduler::next( 'lp_missing_order_deadline', array( $o->get_id() ) ), 're-armed at the next line deadline' );
admin_save( wc_get_order( $o->get_id() ), array( $ids[1] => array( 'missing' => '' ) ) ); // staff untick the line
t_eq( 0, LP_Missing_Scheduler::next( 'lp_missing_order_deadline', array( $o->get_id() ) ), 'job cancelled when no line waits any more' );
// Partial remainder: new round with a new deadline.
$o   = paid_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '3', 'alternatives' => array( $C->get_id() ) ) ) );
set_line( $o->get_id(), $iid, array( 'deadline_at' => time() + HOUR_IN_SECONDS ) );
simulate_decision( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 1 ), $C );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'add' );
$d = item_data( $o->get_id(), $iid );
t_eq( 'pending', $d['status'], 'remaining quantity waits for the customer again' );
t_ok( $d['deadline_at'] > time() + 3 * DAY_IN_SECONDS, 'remaining quantity gets a fresh deadline' );
t_eq( $d['deadline_at'], LP_Missing_Scheduler::next( 'lp_missing_order_deadline', array( $o->get_id() ) ), 'job re-armed for the remainder' );
// Failure: the refund cannot be created -> staff handle it; no automatic retries.
$o   = paid_order( $A, 5 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2' ) ) );
wc_create_refund( array( 'amount' => 400, 'order_id' => $o->get_id(), 'refund_payment' => false ) ); // only 100 left
set_line( $o->get_id(), $iid, array( 'deadline_at' => time() - 60 ) );
LP_Missing_Lifecycle::sync_order_schedule( wc_get_order( $o->get_id() ) );
$GLOBALS['lp_mails'] = array();
run_pending( 'lp_missing_order_deadline', array( $o->get_id() ) );
$d = item_data( $o->get_id(), $iid );
t_eq( 'pending', $d['status'], 'failed refund leaves the line open' );
t_ok( $d['needs_attention'] && $d['auto_action_failed_at'] > 0, 'failed automatic action flags the line for staff' );
t_ok( notes_contain( internal_notes( $o->get_id() ), 'automatic action for Bleier str 4 failed' ), 'internal note about the failure' );
t_eq( 0, LP_Missing_Scheduler::next( 'lp_missing_order_deadline', array( $o->get_id() ) ), 'no automatic retry loop' );
$sm = mails_with_subject( 'Decision deadline passed' );
t_ok( $sm && false !== strpos( $sm[0]['message'], 'Failed' ), 'staff email reports the failure' );
t_eq( 400.0, (float) wc_get_order( $o->get_id() )->get_total_refunded(), 'nothing refunded by the failed attempt' );
echo "\n[L11] Several lines at once, unpaid orders in the email\n";
$o = paid_order( $A, 3 );
$o->add_product( wc_get_product( $C->get_id() ), 2 );
$o->calculate_totals( true );
$o   = wc_get_order( $o->get_id() );
$ids = array_keys( $o->get_items() );
admin_save( $o, array( $ids[0] => array( 'missing' => '1', 'qty_missing' => '1' ), $ids[1] => array( 'missing' => '1', 'qty_missing' => '2' ) ) );
set_line( $o->get_id(), $ids[0], array( 'deadline_at' => time() - 120 ) );
set_line( $o->get_id(), $ids[1], array( 'deadline_at' => time() - 60 ) );
LP_Missing_Lifecycle::sync_order_schedule( wc_get_order( $o->get_id() ) );
$notes_before = count( customer_notes( $o->get_id() ) );
$GLOBALS['lp_mails'] = array();
run_pending( 'lp_missing_order_deadline', array( $o->get_id() ) );
$notes = customer_notes( $o->get_id() );
t_eq( $notes_before + 1, count( $notes ), 'one customer note for all lines handled in one run' );
$combined = array_values( array_filter( $notes, function ( $n ) { return false !== strpos( $n, 'Bleier str 4 (1 stk)' ) && false !== strpos( $n, 'Bleier eco (2 stk)' ); } ) );
t_eq( 1, count( $combined ), 'the note names both lines' );
t_ok( $combined && 1 === substr_count( $combined[0], 'innen fristen' ), 'deadline explained once' );
t_eq( 200.0, (float) wc_get_order( $o->get_id() )->get_total_refunded(), 'both lines refunded (100 + 2 x 50)' );
$sm = mails_with_subject( 'Decision deadline passed' );
t_ok( 1 === count( $sm ) && false !== strpos( $sm[0]['message'], 'Bleier str 4' ) && false !== strpos( $sm[0]['message'], 'Bleier eco' ), 'one staff email listing both lines' );
$o   = unpaid_order( $A, 2 );
$iid = first_item_id( $o );
$GLOBALS['lp_mails'] = array();
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$cm = mails_with_subject( 'Velg erstatning' );
t_ok( $cm && false !== strpos( $cm[0]['message'], 'fjerner vi varen fra ordren' ) && false === strpos( $cm[0]['message'], 'refunderer' ), 'unpaid order: email promises removal, not a refund' );

// Deadline switched off: jobs are dropped and nothing happens.
$o   = paid_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
lp_set( array( 'deadline_action' => 'none' ) );
set_line( $o->get_id(), $iid, array( 'deadline_at' => time() - 60 ) );
call( 'handle_deadline', $o->get_id() );
t_eq( 'pending', item_data( $o->get_id(), $iid )['status'], 'no automatic action once the setting is off' );
t_eq( 0, LP_Missing_Scheduler::next( 'lp_missing_order_deadline', array( $o->get_id() ) ), 'and no job left' );

// ---------- Email templates ----------
echo "\n[S6] Templates, plain text, additional content, overrides\n";
lp_set( array( 'deadline_action' => 'reduce' ) );
$email = WC()->mailer()->get_emails()['lp_missing_customer_email'];
$email->settings['additional_content'] = 'Hilsen oss på {site_title} – ordre {order_number}';
$o   = make_order( $A, 3 );
$iid = first_item_id( $o );
$GLOBALS['lp_mails'] = array();
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'notes' => 'Utsolgt hos leverandør' ) ) );
$m = mails_with_subject( 'Velg erstatning' );
t_ok( $m && false !== strpos( $m[0]['message'], 'Hilsen oss på ' . get_bloginfo( 'name' ) . ' – ordre ' . $o->get_order_number() ), 'additional content with placeholders' );
t_ok( $m && false !== strpos( $m[0]['message'], 'fjerner vi varen fra ordren' ), 'deadline sentence follows the configured action' );
unset( $email->settings['additional_content'] );
$email->settings['email_type'] = 'plain';
$email->email_type             = 'plain';
$GLOBALS['lp_mails'] = array();
call( 'send_customer_email', wc_get_order( $o->get_id() ) );
$m = mails_with_subject( 'Velg erstatning' );
$plain = $m ? $m[0]['message'] : '';
t_ok( false !== strpos( $plain, '- Bleier str 4: mangler 1 av 3 stk' ) && false !== strpos( $plain, 'Utsolgt hos leverandør' ), 'plain text template lists the items' );
t_ok( false === strpos( $plain, '<' ) && false !== strpos( $plain, 'oid=' . $o->get_id() . '&lpk=' ), 'plain text has no markup and a raw link' );
$email->settings['email_type'] = 'html';
$email->email_type             = 'html';
t_eq( 'lp-missing', LP_Missing_Notifier::template_directory( 'woocommerce', 'emails/lp-missing-customer.php' ), 'theme override directory is lp-missing/' );
t_eq( 'woocommerce', LP_Missing_Notifier::template_directory( 'woocommerce', 'emails/customer-note.php' ), 'other templates untouched' );
t_eq( get_stylesheet_directory() . '/lp-missing/emails/lp-missing-customer.php', $email->get_theme_template_file( 'emails/lp-missing-customer.php' ), 'WooCommerce "copy to theme" points at yourtheme/lp-missing/' );
$override = get_stylesheet_directory() . '/lp-missing/emails/lp-missing-customer.php';
wp_mkdir_p( dirname( $override ) );
file_put_contents( $override, "<?php defined( 'ABSPATH' ) || exit; echo 'THEME-OVERRIDE ' . count( \$lines );" );
wp_cache_flush();
$GLOBALS['lp_mails'] = array();
call( 'send_customer_email', wc_get_order( $o->get_id() ) );
$m = mails_with_subject( 'Velg erstatning' );
t_ok( $m && false !== strpos( $m[0]['message'], 'THEME-OVERRIDE 1' ), 'theme template in yourtheme/lp-missing/emails/ is used' );
unlink( $override );
@rmdir( dirname( $override ) );
@rmdir( dirname( dirname( $override ) ) );
wp_cache_flush();
$staff_email = WC()->mailer()->get_emails()['lp_missing_staff_decision'];
t_ok( ! $staff_email->is_customer_email() && 'lp_missing_staff_decision' === $staff_email->id, 'staff email registered with WooCommerce' );
t_ok( isset( WC()->mailer()->get_emails()['lp_missing_staff_deadline'] ), 'deadline staff email registered with WooCommerce' );
t_ok( in_array( '{count}', array_keys( $email->placeholders ), true ) && in_array( '{deadline}', array_keys( $email->placeholders ), true ), 'placeholders documented in the email settings' );

echo "\n[S6] WooCommerce email preview\n";
$dummy = new WC_Order();
$dummy->set_billing_first_name( 'John' );
$preview_item = new WC_Order_Item_Product();
$preview_item->set_name( 'Preview-vare' );
$preview_item->set_quantity( 2 );
$dummy->add_item( $preview_item );
foreach ( array( 'lp_missing_customer_email' => 'Preview-vare', 'lp_missing_customer_reminder' => 'Preview-vare', 'lp_missing_staff_decision' => 'Sample alternative', 'lp_missing_staff_deadline' => 'Preview-vare' ) as $email_id => $needle ) {
	$preview = clone WC()->mailer()->get_emails()[ $email_id ];
	$preview->set_object( $dummy );
	$preview = apply_filters( 'woocommerce_prepare_email_for_preview', $preview );
	$html    = $preview->get_content_html();
	$plain   = $preview->get_content_plain();
	t_ok( false !== strpos( $html, $needle ) && false !== strpos( $plain, $needle ), "$email_id preview shows sample content" );
}

// ---------- Restore ----------
update_option( 'lp_missing_settings', $lp_settings_before );
LP_Missing_Settings::flush();
update_option( 'timezone_string', $lp_tz_before );
update_option( 'gmt_offset', $lp_offset_before );

t_summary();
