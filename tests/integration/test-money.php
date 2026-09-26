<?php
// Money and payment tests: payment states (Dintero reserve/capture), rounding of moved and refunded shares, coupons on
// replacement lines, stock of units moved to a replacement line, refunds made in WooCommerce during an open case,
// collected customer notes. Run with: wp eval-file tests/integration/test-money.php (see tests/README.md).
defined( 'ABSPATH' ) || exit; // Runs inside WordPress (wp eval-file), never over HTTP.
require __DIR__ . '/bootstrap.php';

echo "HPOS: " . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . "\n";

$money_settings_before = get_option( 'lp_missing_settings', array() );
LP_Missing_Settings::update_settings( array_merge( LP_Missing_Settings::get_settings( true ), array( 'alt_price_handling' => 'charge_customer', 'store_covers_below' => 0, 'deadline_action' => 'none' ) ) );

function money_decide( $order_id, $item_id, $changes, $alt = null ) {
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
function money_gross( $share ) {
	return round( (float) $share['total'] + array_sum( array_map( 'floatval', $share['taxes']['total'] ) ), 2 );
}
function money_line_gross( $item ) {
	return round( (float) $item->get_total() + (float) $item->get_total_tax(), 2 );
}
function money_notes( $order_id, $type ) {
	return array_map( function ( $n ) { return $n->content; }, wc_get_order_notes( array( 'order_id' => $order_id, 'type' => $type ) ) );
}
function money_notes_contain( $notes, $needle ) {
	foreach ( $notes as $note ) {
		if ( false !== strpos( $note, $needle ) ) {
			return true;
		}
	}
	return false;
}
function money_pending( $hook, $args ) {
	return as_get_scheduled_actions( array( 'hook' => $hook, 'args' => $args, 'group' => 'lp-missing', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50 ), 'ids' );
}
function money_stock( $product ) {
	return (int) wc_get_product( $product->get_id() )->get_stock_quantity();
}
function money_refund_mails() {
	return array_values( array_filter( $GLOBALS['lp_mails'], function ( $m ) { return false !== stripos( $m['subject'], 'refunded' ); } ) );
}

// ---------- Rounding ----------
echo "\n[Money] Shares are split on what the customer paid (2 decimals)\n";
$P  = make_product( 'Smokk 19,99', '19.99' );
$P2 = make_product( 'Smokk natt 19,99', '19.99' );
$o  = make_order( $P, 3 );
$iid = first_item_id( $o );
t_eq( 59.97, (float) $o->get_total(), 'order of 3 x 19.99' );
t_eq( 19.99, money_gross( LP_Missing_Pricing::get_item_share( $o->get_item( $iid, false ), 1 ) ), 'one of three units is 19.99' );
t_eq( 39.98, money_gross( LP_Missing_Pricing::get_item_share( $o->get_item( $iid, false ), 2 ) ), 'two of three units are 39.98' );
t_eq( 59.97, money_gross( LP_Missing_Pricing::get_item_share( $o->get_item( $iid, false ), 3 ) ), 'the whole line is exactly the line' );

admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $P2->get_id() ) ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 1 ), $P2 );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
$o     = wc_get_order( $o->get_id() );
$lines = array_values( $o->get_items() );
t_eq( 59.97, (float) $o->get_total(), 'replacing one unit keeps the paid total' );
t_eq( 39.98, money_line_gross( $lines[0] ), 'original line keeps 39.98' );
t_eq( 19.99, money_line_gross( $lines[1] ), 'replacement line gets 19.99' );

// Three single-unit refunds add up to the line, so the order ends up fully refunded.
$o   = make_order( $P, 3 );
$iid = first_item_id( $o );
for ( $i = 0; $i < 3; $i++ ) {
	$item  = wc_get_order( $o->get_id() )->get_item( $iid, false );
	$share = LP_Missing_Pricing::get_item_share( $item, 1, true );
	wc_create_refund( array( 'amount' => money_gross( $share ), 'order_id' => $o->get_id(), 'line_items' => array( $iid => array( 'qty' => 1, 'refund_total' => $share['total'], 'refund_tax' => $share['taxes']['total'] ) ) ) );
}
$o = wc_get_order( $o->get_id() );
t_eq( 59.97, (float) $o->get_total_refunded(), 'three single-unit refunds add up to the line' );
t_eq( 'refunded', $o->get_status(), 'order is fully refunded' );

// The plugin's own refund of one unit.
$o   = make_order( $P, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
apply_via_handler( $o->get_id(), $iid, 'delete', 'refund' );
t_eq( 19.99, (float) wc_get_order( $o->get_id() )->get_total_refunded(), 'refund of one missing unit is 19.99' );

echo "\n[Money] Shares with 0 price decimals\n";
update_option( 'woocommerce_price_num_decimals', 0 );
$R  = make_product( 'Body 99', '99' );
$R2 = make_product( 'Body ull 99', '99' );
$o  = make_order( $R, 3 );
$iid = first_item_id( $o );
$paid = (float) $o->get_total();
t_eq( 297.0, $paid, 'order of 3 x 99' );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
apply_via_handler( $o->get_id(), $iid, 'delete', 'refund' );
t_eq( 99.0, (float) wc_get_order( $o->get_id() )->get_total_refunded(), 'refund of one unit is 99 (not 98)' );
$o   = make_order( $R, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $R2->get_id() ) ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 1 ), $R2 );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
t_eq( 297.0, (float) wc_get_order( $o->get_id() )->get_total(), 'replacing one unit keeps 297 (not 296)' );
update_option( 'woocommerce_price_num_decimals', 2 );

// ---------- Payment states ----------
echo "\n[Money] Dintero: reserved until the order is completed, then charged\n";
class LP_Test_Dintero_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'dintero_checkout';
		$this->title              = 'Dintero';
		$this->method_title       = 'Dintero';
		$this->enabled            = 'yes';
		$this->supports           = array( 'products', 'refunds' );
	}
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$GLOBALS['lp_gateway_refunds'][] = array( $order_id, (float) $amount );
		return true;
	}
}
$GLOBALS['lp_gateway_refunds'] = array();
add_filter( 'woocommerce_payment_gateways', function ( $gateways ) { $gateways[] = 'LP_Test_Dintero_Gateway'; return $gateways; } );
WC()->payment_gateways()->init();

function money_dintero_order( $product, $qty ) {
	$o = make_order( $product, $qty );
	$o->set_payment_method( 'dintero_checkout' );
	$o->set_payment_method_title( 'Dintero' );
	foreach ( $o->get_items() as $item ) {
		$item->update_meta_data( '_dintero_checkout_line_id', 'line-' . $item->get_product_id() );
		$item->save();
	}
	$o->save();
	return wc_get_order( $o->get_id() );
}

$o = make_order( $A, 1 );
t_eq( 'paid', LP_Missing_Payment::get_state( $o ), 'processing order of another gateway: paid' );
$o->set_status( 'pending' );
$o->save();
t_eq( 'unpaid', LP_Missing_Payment::get_state( wc_get_order( $o->get_id() ) ), 'set back to pending payment: unpaid' );
$o = make_order( $A, 1 );
$o->update_status( 'refunded' );
t_eq( 'paid', LP_Missing_Payment::get_state( wc_get_order( $o->get_id() ) ), 'refunded after payment: still paid (money to account for)' );

$o = money_dintero_order( $A, 2 );
t_eq( 'reserved', LP_Missing_Payment::get_state( $o ), 'paid by Dintero, not captured: reserved' );
t_eq( 'reduce', LP_Missing_Payment::get_removal_mode( $o, 'refund' ), 'reserved: removals lower the amount charged' );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
apply_via_handler( $o->get_id(), $iid, 'delete', 'refund' );
$o = wc_get_order( $o->get_id() );
t_eq( 100.0, (float) $o->get_total(), 'reserved: removal lowers the order total' );
t_eq( 0.0, (float) $o->get_total_refunded(), 'reserved: nothing refunded (nothing was charged)' );
t_eq( 0, count( $GLOBALS['lp_gateway_refunds'] ), 'reserved: no refund sent to Dintero' );
t_ok( money_notes_contain( money_notes( $o->get_id(), 'customer' ), 'Du blir bare belastet for varene vi sender.' ), 'reserved: the customer is told they are only charged for what is sent' );

$o = money_dintero_order( $A, 1 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
apply_via_handler( $o->get_id(), $iid, 'delete', 'reduce' );
t_ok( money_notes_contain( money_notes( $o->get_id(), 'internal' ), 'Nothing is left to charge: cancel the order' ), 'reserved: staff are told to cancel when nothing is left to charge' );

$o = money_dintero_order( $A, 3 );
$o->update_meta_data( '_wc_dintero_captured', 1 );
$o->save();
t_eq( 'captured', LP_Missing_Payment::get_state( $o ), 'Dintero after capture: captured' );
t_eq( 'refund', LP_Missing_Payment::get_removal_mode( $o, 'reduce' ), 'captured: removals are refunds' );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
$redirect = apply_via_handler( $o->get_id(), $iid, 'delete', 'reduce' );
t_eq( 'delete_pending', item_data( $o->get_id(), $iid )['status'], 'captured: "remove from the order" is refused' );
$GLOBALS['lp_mails'] = array();
apply_via_handler( $o->get_id(), $iid, 'delete', 'refund' );
$o = wc_get_order( $o->get_id() );
t_eq( 'delete_applied', item_data( $o->get_id(), $iid )['status'], 'captured: refund applied' );
t_eq( 1, count( $GLOBALS['lp_gateway_refunds'] ), 'captured: the refund is sent through Dintero' );
t_eq( 100.0, $GLOBALS['lp_gateway_refunds'] ? $GLOBALS['lp_gateway_refunds'][0][1] : 0, 'captured: Dintero refunds 100' );
t_ok( money_notes_contain( money_notes( $o->get_id(), 'internal' ), 'Refunded to the customer through Dintero' ), 'captured: order note says the money went back through Dintero' );
t_ok( money_notes_contain( money_notes( $o->get_id(), 'customer' ), 'og refundert kr' ), 'captured: the customer is told the money is refunded' );
t_eq( 0, count( money_refund_mails() ), 'no WooCommerce refund email for the plugin refund (the plugin note covers it)' );
$other = make_order( $A, 2 );
$GLOBALS['lp_mails'] = array();
wc_create_refund( array( 'amount' => 10, 'order_id' => $other->get_id(), 'reason' => 'Goodwill' ) );
t_eq( 1, count( money_refund_mails() ), 'refunds made by staff still send the WooCommerce refund email' );

echo "\n[Money] Completing an order with open cases\n";
$o = money_dintero_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
$o = wc_get_order( $o->get_id() );
$o->update_status( 'completed' );
t_ok( money_notes_contain( money_notes( $o->get_id(), 'internal' ), 'Completed while 1 missing item was not settled' ), 'completing with an open case leaves a warning note' );

echo "\n[Money] Replacement lines on Dintero orders\n";
$o   = money_dintero_order( $A, 1 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $C->get_id() ) ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 1 ), $C );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
$lines = array_values( wc_get_order( $o->get_id() )->get_items() );
t_eq( 'line-' . $A->get_id(), 1 === count( $lines ) ? $lines[0]->get_meta( '_dintero_checkout_line_id' ) : '', 'whole line replaced: the replacement keeps the line ID the customer authorised' );
$o   = money_dintero_order( $A, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $C->get_id() ) ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 1 ), $C );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
$lines = array_values( wc_get_order( $o->get_id() )->get_items() );
t_ok( 2 === count( $lines ) && 'line-' . $A->get_id() . '-lp' . $lines[1]->get_id() === $lines[1]->get_meta( '_dintero_checkout_line_id' ), 'partial replacement: a line ID of its own' );

echo "\n[Money] Surcharge orders follow their main order\n";
$o   = make_order( $A, 1 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ) ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 1 ), $B );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
$sur = LP_Missing_Payment::get_surcharge_orders( wc_get_order( $o->get_id() ) );
t_eq( 1, count( $sur ), 'surcharge order created' );
wc_get_order( $o->get_id() )->update_status( 'cancelled' );
$sur = LP_Missing_Payment::get_surcharge_orders( wc_get_order( $o->get_id() ) );
t_eq( 'cancelled', $sur ? $sur[0]->get_status() : '', 'cancelling the order cancels its unpaid surcharge' );

// ---------- Coupons ----------
echo "\n[Money] Coupons on replacement lines\n";
LP_Missing_Settings::update_settings( array_merge( LP_Missing_Settings::get_settings( true ), array( 'alt_price_handling' => 'store_covers' ) ) );
$coupon = new WC_Coupon();
$coupon->set_code( 'lp10-' . strtolower( wp_generate_password( 6, false ) ) );
$coupon->set_discount_type( 'percent' );
$coupon->set_amount( 10 );
$coupon->set_product_ids( array( $A->get_id() ) );
$coupon->save();
$o = wc_create_order();
$o->set_billing_email( 'kunde@example.com' );
$o->set_billing_country( 'NO' );
$o->add_product( wc_get_product( $A->get_id() ), 2 );
$o->calculate_totals( true );
$o->set_status( 'on-hold' );
$o->save();
$o->apply_coupon( $coupon->get_code() );
$o   = wc_get_order( $o->get_id() );
$iid = first_item_id( $o );
t_eq( 180.0, (float) $o->get_total(), '2 x 100 with 10% off' );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id() ) ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 1 ), $B );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'replace' );
t_eq( 180.0, (float) wc_get_order( $o->get_id() )->get_total(), 'replacement keeps the discounted price' );
$o = wc_get_order( $o->get_id() );
$o->recalculate_coupons();
t_eq( 180.0, (float) wc_get_order( $o->get_id() )->get_total(), 'recalculating the coupons keeps the total (the replacement counts as the original product)' );
LP_Missing_Settings::update_settings( array_merge( LP_Missing_Settings::get_settings( true ), array( 'alt_price_handling' => 'charge_customer' ) ) );

// ---------- Stock of moved units ----------
echo "\n[Money] Stock when a replacement is added as a separate line\n";
if ( ! function_exists( 'wc_maybe_adjust_line_item_product_stock' ) ) {
	include_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
}
$S1 = make_product( 'Lager original', '100', 10 );
$S2 = make_product( 'Lager erstatning', '100', 10 );
$o   = make_order( $S1, 4 );
$iid = first_item_id( $o );
t_eq( 6, money_stock( $S1 ), 'paid order took 4 from stock' );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $S2->get_id() ) ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 2 ), $S2 );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'add' );
$item = wc_get_order( $o->get_id() )->get_item( $iid, false );
t_eq( 6, money_stock( $S1 ), 'missing units stay out of stock' );
t_eq( 8, money_stock( $S2 ), 'replacement taken from stock' );
t_eq( 2, (int) $item->get_meta( '_reduced_stock', true ), 'original line records only the units really picked' );
wc_maybe_adjust_line_item_product_stock( $item );
t_eq( 6, money_stock( $S1 ), "WooCommerce's stock sync on save does not take the moved units again" );
wc_get_order( $o->get_id() )->update_status( 'cancelled' );
t_eq( 8, money_stock( $S1 ), 'cancelling gives back only the 2 picked units' );
t_eq( 10, money_stock( $S2 ), 'cancelling gives back the replacement' );

echo "\n[Money] A line with nothing left cannot be marked missing again\n";
$o   = make_order( $A, 1 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $C->get_id() ) ) ) );
money_decide( $o->get_id(), $iid, array( 'status' => 'alt_pending', 'qty_alt' => 1 ), $C );
apply_via_handler( $o->get_id(), $iid, 'alternative', 'add' );
t_eq( 0, LP_Missing_Line::get_item_available_qty( wc_get_order( $o->get_id() )->get_item( $iid, false ) ), 'all units moved: nothing left on the line' );
admin_save( wc_get_order( $o->get_id() ), array( $iid => array( 'missing' => '1', 'qty_missing' => '1' ) ) );
t_eq( 'alt_applied', item_data( $o->get_id(), $iid )['status'], 'marking it missing again is ignored' );
ob_start();
LP_Missing_Admin_Metabox::render_metabox( wc_get_order( $o->get_id() ) );
$box = ob_get_clean();
$line_html = preg_match( '/data-item-id="' . $iid . '"(.*?)(?=<div class="lp-missing-item lp-line|$)/s', $box, $m ) ? $m[1] : '';
t_ok( '' !== $line_html && false === strpos( $line_html, 'lp-mark' ) && false !== strpos( $line_html, 'lp-line__done' ), 'no "Missing again" button on that line' );

// ---------- Refunds made in WooCommerce ----------
echo "\n[Money] A refund made in WooCommerce settles the missing units\n";
$o   = make_order( $A, 3 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'propose_delete' => '1' ) ) );
$rate = key( wc_get_order( $o->get_id() )->get_item( $iid, false )->get_taxes()['total'] );
wc_create_refund( array( 'amount' => 100, 'order_id' => $o->get_id(), 'line_items' => array( $iid => array( 'qty' => 1, 'refund_total' => 80, 'refund_tax' => array( $rate => 20 ) ) ) ) );
$d = item_data( $o->get_id(), $iid );
t_eq( 1, $d['qty_missing'], 'one unit is still missing' );
t_ok( ! empty( $d['needs_attention'] ), 'the line is flagged for staff' );
t_ok( money_notes_contain( money_notes( $o->get_id(), 'internal' ), 'was refunded in WooCommerce, so 1 is still missing' ), 'order note explains it' );
money_decide( $o->get_id(), $iid, array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
apply_via_handler( $o->get_id(), $iid, 'delete', 'refund' );
t_eq( 200.0, (float) wc_get_order( $o->get_id() )->get_total_refunded(), 'only the remaining missing unit is refunded by the plugin' );

// ---------- Customer notes ----------
echo "\n[Money] Customer notes about several decisions are sent together\n";
$delay = function () { return 300; };
add_filter( 'lp_missing_customer_note_delay', $delay, 20 );
$o = make_order( $A, 2 );
$o->add_product( wc_get_product( $C->get_id() ), 2 );
$o->calculate_totals( true );
$o->save();
$o   = wc_get_order( $o->get_id() );
$ids = array_keys( $o->get_items() );
admin_save( $o, array( $ids[0] => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ), $ids[1] => array( 'missing' => '1', 'qty_missing' => '1', 'propose_delete' => '1' ) ) );
money_decide( $o->get_id(), $ids[0], array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
money_decide( $o->get_id(), $ids[1], array( 'status' => 'delete_pending', 'decision_made_at' => time() ) );
$before = count( money_notes( $o->get_id(), 'customer' ) );
apply_via_handler( $o->get_id(), $ids[0], 'delete', 'reduce' );
apply_via_handler( $o->get_id(), $ids[1], 'delete', 'reduce' );
t_eq( $before, count( money_notes( $o->get_id(), 'customer' ) ), 'no customer note right away' );
$jobs = money_pending( 'lp_missing_send_customer_notes', array( $o->get_id() ) );
t_eq( 1, count( $jobs ), 'one job collects them' );
foreach ( $jobs as $job ) {
	ActionScheduler::runner()->process_action( $job, 'lp-test' );
}
$notes = money_notes( $o->get_id(), 'customer' );
t_eq( $before + 1, count( $notes ), 'one customer note for both decisions' );
t_ok( money_notes_contain( $notes, 'Bleier str 4 (1 stk)' ) && money_notes_contain( $notes, 'Bleier eco (1 stk)' ), 'the note names both lines' );
remove_filter( 'lp_missing_customer_note_delay', $delay, 20 );

// ---------- Emails ----------
echo "\n[Money] Customer emails and reminders\n";
$Z   = make_product( 'Utsolgt erstatning', '100', 0 );
$o   = make_order( $A, 2 );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $Z->get_id(), $C->get_id() ) ) ) );
$lines = LP_Missing_Notifier::get_customer_lines( wc_get_order( $o->get_id() ) );
t_ok( isset( $lines[ $iid ] ) && array( 'Bleier eco' ) === $lines[ $iid ]['alternatives'], 'sold-out alternatives are not listed in emails' );
LP_Missing_Scheduler::schedule_single( time() + 600, LP_Missing_Scheduler::REMINDER_HOOK, array( $o->get_id() ) );
LP_Missing_Notifier::send_customer_email( wc_get_order( $o->get_id() ) );
t_ok( LP_Missing_Lifecycle::get_next_reminder_timestamp( $o->get_id() ) > time() + HOUR_IN_SECONDS, 'sending the email again moves the next reminder (no reminder minutes later)' );

update_option( 'lp_missing_settings', $money_settings_before );
LP_Missing_Settings::flush();
t_summary();
