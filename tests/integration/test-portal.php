<?php
// Customer portal tests: links v1/v2 (expiry, revocation, key rotation), session cookie, email confirmation,
// staff preview, decisions (PRG, change, hooks), rate limit, stock, texts, headers, accessibility markup, page setup.
// Run with: wp eval-file tests/integration/test-portal.php (see tests/README.md).
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/portal-helpers.php';

echo 'HPOS: ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . "\n";

$tp_settings_backup = get_option( 'lp_missing_settings', array() );
$tp_settings        = LP_Missing_Settings::get_settings( true );
$tp_settings['magic_link_ttl_days'] = 30;
$tp_settings['deadline_action']     = 'none';
$tp_settings['alt_price_handling']  = 'charge_customer';
$tp_settings['store_covers_below']  = 0;
$tp_settings['enable_logging']      = 'yes';
LP_Missing_Settings::update_settings( $tp_settings );
$tp_weight_unit = get_option( 'woocommerce_weight_unit' );
update_option( 'woocommerce_weight_unit', 'kg' );

function tp_set( $changes ) {
	LP_Missing_Settings::update_settings( array_merge( LP_Missing_Settings::get_settings( true ), $changes ) );
}
function tp_order( $lines, $customer_id = 0, $email = 'kunde@example.com' ) {
	$order = wc_create_order( array( 'customer_id' => $customer_id ) );
	$order->set_billing_first_name( 'Kari' );
	$order->set_billing_email( $email );
	$order->set_billing_country( 'NO' );
	$order->set_shipping_country( 'NO' );
	foreach ( $lines as $line ) {
		$order->add_product( wc_get_product( $line[0]->get_id() ), $line[1] );
	}
	$order->calculate_totals( true );
	$order->set_status( 'processing' );
	$order->save();
	return wc_get_order( $order->get_id() );
}
function tp_item_ids( $order ) {
	return array_keys( wc_get_order( $order->get_id() )->get_items( 'line_item' ) );
}
function tp_token( $link ) {
	parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $q );
	return isset( $q['lpk'] ) ? $q['lpk'] : '';
}
function tp_log_files() {
	return glob( trailingslashit( \Automattic\WooCommerce\Utilities\LoggingUtil::get_log_directory() ) . 'lp-missing-*.log' ) ?: array();
}
function tp_log_size() {
	$sizes = array();
	foreach ( tp_log_files() as $file ) {
		clearstatcache( true, $file );
		$sizes[ $file ] = filesize( $file );
	}
	return $sizes;
}
function tp_log_since( $sizes ) {
	$out = '';
	foreach ( tp_log_files() as $file ) {
		clearstatcache( true, $file );
		$from = isset( $sizes[ $file ] ) ? $sizes[ $file ] : 0;
		$out .= (string) file_get_contents( $file, false, null, $from );
	}
	return $out;
}
$tp_log_start = tp_log_size();
$tp_hooks     = array( 'decision' => array(), 'updated' => 0 );
add_action( 'lp_missing_customer_decision', function ( $order, $item_id, $new, $old ) use ( &$tp_hooks ) {
	$tp_hooks['decision'][] = array( 'order' => $order, 'item_id' => $item_id, 'new' => $new, 'old' => $old );
}, 10, 4 );
add_action( 'lp_missing_item_updated', function () use ( &$tp_hooks ) {
	$tp_hooks['updated']++;
}, 10, 0 );

// ---------------------------------------------------------------------------------------------------------------
echo "\n[L6] v2 link, session cookie and email confirmation\n";
portal_jar_reset();
$o   = tp_order( array( array( $A, 5 ) ) );
$iid = first_item_id( $o );
admin_save( $o, array( $iid => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $B->get_id(), $C->get_id() ) ) ) );
$o    = wc_get_order( $o->get_id() );
$link = call( 'get_magic_link_for_order', $o );
$lpk  = tp_token( $link );
$parts = explode( '.', $lpk );
t_ok( false !== strpos( $link, 'oid=' . $o->get_id() ) && '' !== $lpk && false === strpos( $link, 'key=' ), 'v2 link carries oid + lpk' );
t_eq( 4, count( $parts ), 'token has key id, generation, expiry and signature' );
t_eq( LP_Missing_Magic_Link::get_current_key_id(), $parts[0], 'token names the current signing key' );
t_ok( abs( (int) $parts[2] - ( time() + 30 * DAY_IN_SECONDS ) ) < 5, 'link expires after magic_link_ttl_days' );

$GLOBALS['lp_cookie_log'] = array();
$res = portal_http( $link );
t_eq( 302, $res['status'], 'valid link redirects' );
t_ok( false === strpos( $res['redirect'], 'lpk=' ) && false !== strpos( $res['redirect'], 'oid=' . $o->get_id() ), 'redirect goes to a clean portal URL without the key' );
$cookie_name = 'lp_missing_portal_' . $o->get_id();
$cookie      = end( $GLOBALS['lp_cookie_log'] );
t_ok( $cookie && $cookie['name'] === $cookie_name, 'link exchanged for a per-order session cookie' );
t_ok( $cookie && true === $cookie['params']['httponly'] && 'Lax' === $cookie['params']['samesite'] && is_ssl() === $cookie['params']['secure'], 'cookie is HttpOnly, SameSite=Lax, Secure only on SSL' );
t_ok( $cookie && $cookie['expires'] > time() && $cookie['expires'] <= time() + 2 * DAY_IN_SECONDS + 5, 'session cookie expires (capped at the session TTL)' );
t_ok( $cookie && false === strpos( $cookie['value'], $parts[3] ), 'cookie does not contain the link key' );
t_ok( ! empty( $GLOBALS['lp_private_headers'] ), 'private headers sent on the link request' );

$res = portal_follow( $res );
t_ok( false !== strpos( $res['html'], 'lp_missing_verify_email' ), 'guest must confirm the billing email first' );
t_ok( false === strpos( $res['html'], 'Kari' ) && false === strpos( $res['html'], 'lp-portal-item' ), 'nothing about the order is shown before confirmation' );
$token = extract_field( $res['html'], 'lp_missing_token' );
$bad   = portal_http( portal_clean_url( $o ), array( 'lp_oid' => $o->get_id(), 'lp_missing_portal_action' => 'verify', 'lp_missing_token' => $token, 'lp_missing_verify_email' => 'noen@example.com' ) );
t_ok( '' === $bad['redirect'] && false !== strpos( $bad['html'], 'E-postadressen stemmer ikke' ) && false !== strpos( $bad['html'], 'role="alert"' ), 'wrong email is refused with an alert' );
$res = portal_http( portal_clean_url( $o ), array( 'lp_oid' => $o->get_id(), 'lp_missing_portal_action' => 'verify', 'lp_missing_token' => $token, 'lp_missing_verify_email' => ' Kunde@Example.com ' ) );
t_eq( 303, $res['status'], 'confirmation redirects (PRG)' );
t_ok( false !== strpos( $res['redirect'], 'lp_msg=verified' ), 'confirmation message travels as a code' );
t_eq( '1', explode( '.', $GLOBALS['lp_jar'][ $cookie_name ] )[3], 'confirmed state is stored in the signed cookie' );
$res = portal_follow( $res );
t_ok( false !== strpos( $res['html'], 'lp-portal-item' ) && false !== strpos( $res['html'], 'E-postadressen er bekreftet' ), 'portal shown after confirmation' );
$res = portal_http( portal_clean_url( $o ) );
t_ok( false !== strpos( $res['html'], 'lp-portal-item' ), 'confirmation is asked once: next visit goes straight to the portal' );
$res = portal_follow( portal_http( call( 'get_magic_link_for_order', $o ) ) );
t_ok( false !== strpos( $res['html'], 'lp-portal-item' ), 'opening a new link in the same browser keeps the confirmation' );
$saved_jar = $GLOBALS['lp_jar'];
portal_jar_reset();
$res = portal_follow( portal_http( $link ) );
t_ok( false !== strpos( $res['html'], 'lp_missing_verify_email' ), 'another browser has to confirm again' );
$res = portal_http( portal_clean_url( $o ) );
portal_jar_reset();
$res = portal_http( portal_clean_url( $o ) );
t_ok( false !== strpos( $res['html'], 'Åpne lenken i e-posten' ), 'clean URL without a session asks for the email link' );
$forged = LP_Missing_Magic_Link::create_session_value( $o, true, time() + 3600, str_repeat( 'a', 32 ) );
$GLOBALS['lp_jar'][ $cookie_name ] = substr( $forged, 0, -3 ) . 'xyz';
$res = portal_http( portal_clean_url( $o ) );
t_ok( false !== strpos( $res['html'], 'Åpne lenken i e-posten' ) && ! isset( $GLOBALS['lp_jar'][ $cookie_name ] ), 'tampered cookie is rejected and removed' );
$GLOBALS['lp_jar'][ $cookie_name ] = LP_Missing_Magic_Link::create_session_value( $o, true, time() - 10, str_repeat( 'b', 32 ) );
$res = portal_http( portal_clean_url( $o ) );
t_ok( false !== strpos( $res['html'], 'Åpne lenken i e-posten' ), 'expired session cookie is rejected' );
$GLOBALS['lp_jar'] = $saved_jar;

// ---------------------------------------------------------------------------------------------------------------
echo "\n[L6] Invalid, expired and revoked links\n";
portal_jar_reset();
$res = portal_http( substr( $link, 0, -4 ) . 'abcd' );
t_ok( '' === $res['redirect'] && false !== strpos( $res['html'], 'Lenken er ugyldig' ) && empty( $GLOBALS['lp_jar'] ), 'tampered signature: invalid, no session' );
$o2 = tp_order( array( array( $A, 1 ) ) );
$res = portal_http( add_query_arg( array( 'oid' => $o2->get_id(), 'lpk' => $lpk ), portal_clean_url( $o2 ) ) );
t_ok( false !== strpos( $res['html'], 'Lenken er ugyldig' ), 'token of another order is invalid' );
$expired = add_query_arg( array( 'oid' => $o->get_id(), 'lpk' => LP_Missing_Magic_Link::create_link_token( $o, time() - 31 * DAY_IN_SECONDS ) ), portal_clean_url( $o ) );
$res = portal_http( $expired );
t_ok( false !== strpos( $res['html'], 'Lenken er utløpt' ) && empty( $GLOBALS['lp_jar'] ), 'expired link is refused' );
$res = portal_http( add_query_arg( array( 'oid' => 999999999, 'lpk' => $lpk ), portal_clean_url( $o ) ) );
t_ok( false !== strpos( $res['html'], 'Lenken er ugyldig' ), 'unknown order is invalid' );
// Revocation ends earlier links and sessions.
$old_link = call( 'get_magic_link_for_order', $o );
portal_login( $o );
$session_jar = $GLOBALS['lp_jar'];
LP_Missing_Magic_Link::revoke_links( wc_get_order( $o->get_id() ) );
$o = wc_get_order( $o->get_id() );
t_eq( 1, LP_Missing_Magic_Link::get_generation( $o ), 'revoke_links bumps the generation' );
$res = portal_http( portal_clean_url( $o ) );
t_ok( false !== strpos( $res['html'], 'Åpne lenken i e-posten' ), 'revocation ends the existing session' );
portal_jar_reset();
$res = portal_http( $old_link );
t_ok( false !== strpos( $res['html'], 'ikke lenger i bruk' ), 'revoked link is refused' );
$res = portal_follow( portal_http( call( 'get_magic_link_for_order', $o ) ) );
t_ok( false !== strpos( $res['html'], 'lp_missing_verify_email' ), 'a new link works after revocation' );
// The billing email is part of the signature.
$o3   = tp_order( array( array( $A, 1 ) ) );
$l3   = call( 'get_magic_link_for_order', $o3 );
$o3->set_billing_email( 'endret@example.com' );
$o3->save();
portal_jar_reset();
$res = portal_http( $l3 );
t_ok( false !== strpos( $res['html'], 'Lenken er ugyldig' ), 'changing the billing email invalidates earlier links' );

// ---------------------------------------------------------------------------------------------------------------
echo "\n[L6] Legacy v1 links during the transition\n";
$o4   = tp_order( array( array( $A, 2 ) ) );
$v1   = add_query_arg( array( 'oid' => $o4->get_id(), 'key' => LP_Missing_Magic_Link::generate_signature( $o4->get_id(), 'kunde@example.com' ) ), portal_clean_url( $o4 ) );
$cutoff_backup = get_option( LP_Missing_Magic_Link::OPTION_V1_CUTOFF );
delete_option( LP_Missing_Magic_Link::OPTION_V1_CUTOFF );
portal_jar_reset();
$res = portal_http( $v1 );
t_ok( 302 === $res['status'] && false === strpos( $res['redirect'], 'key=' ) && isset( $GLOBALS['lp_jar'][ 'lp_missing_portal_' . $o4->get_id() ] ), 'v1 link accepted and exchanged for a session' );
t_ok( absint( get_option( LP_Missing_Magic_Link::OPTION_V1_CUTOFF ) ) > time() - 60, 'missing cutoff is recorded on first use' );
update_option( LP_Missing_Magic_Link::OPTION_V1_CUTOFF, time() - 31 * DAY_IN_SECONDS );
portal_jar_reset();
$res = portal_http( $v1 );
t_ok( false !== strpos( $res['html'], 'Lenken er utløpt' ), 'v1 link refused after cutoff + TTL' );
update_option( LP_Missing_Magic_Link::OPTION_V1_CUTOFF, time() );
LP_Missing_Magic_Link::revoke_links( wc_get_order( $o4->get_id() ) );
$res = portal_http( $v1 );
t_ok( false !== strpos( $res['html'], 'ikke lenger i bruk' ), 'v1 link refused once the order links were revoked' );
$res = portal_http( add_query_arg( array( 'oid' => $o4->get_id(), 'key' => str_repeat( 'a', 64 ) ), portal_clean_url( $o4 ) ) );
t_ok( false !== strpos( $res['html'], 'Lenken er ugyldig' ), 'forged v1 key is invalid' );
if ( $cutoff_backup ) {
	update_option( LP_Missing_Magic_Link::OPTION_V1_CUTOFF, $cutoff_backup );
}

// ---------------------------------------------------------------------------------------------------------------
echo "\n[L6] Key rotation\n";
$o5      = tp_order( array( array( $A, 1 ) ) );
$old_kid = LP_Missing_Magic_Link::get_current_key_id();
$l_old   = call( 'get_magic_link_for_order', $o5 );
portal_jar_reset();
portal_login( $o5 );
$old_session = $GLOBALS['lp_jar'];
$new_kid = LP_Missing_Magic_Link::rotate_key();
t_ok( $new_kid !== $old_kid && LP_Missing_Magic_Link::get_current_key_id() === $new_kid, 'rotation switches to a new key' );
t_eq( $new_kid, explode( '.', tp_token( call( 'get_magic_link_for_order', $o5 ) ) )[0], 'new links use the new key' );
portal_jar_reset();
$res = portal_http( $l_old );
t_eq( 302, $res['status'], 'links signed with the previous key keep working' );
$GLOBALS['lp_jar'] = $old_session;
$res = portal_http( portal_clean_url( $o5 ) );
t_ok( false !== strpos( $res['html'], 'Alt er i orden' ) || false !== strpos( $res['html'], 'lp-missing-portal' ), 'sessions signed with the previous key keep working' );
$ring = get_option( LP_Missing_Magic_Link::OPTION_KEYS );
$ring['keys'][ $old_kid ]['retired'] = time() - 40 * DAY_IN_SECONDS;
update_option( LP_Missing_Magic_Link::OPTION_KEYS, $ring, false );
LP_Missing_Magic_Link::rotate_key();
$ring = get_option( LP_Missing_Magic_Link::OPTION_KEYS );
t_ok( ! isset( $ring['keys'][ $old_kid ] ) && isset( $ring['keys'][ $new_kid ] ), 'keys retired longer than the TTL are pruned, recent ones kept' );
portal_jar_reset();
$res = portal_http( $l_old );
t_ok( false !== strpos( $res['html'], 'Lenken er ugyldig' ), 'links of a pruned key are invalid' );
$l5 = call( 'get_magic_link_for_order', $o5 );
add_filter( 'salt', $tp_salt = function ( $salt, $scheme ) { return 'auth' === $scheme ? 'another-salt' : $salt; }, 10, 2 );
$res = portal_http( $l5 );
remove_filter( 'salt', $tp_salt, 10 );
t_ok( false !== strpos( $res['html'], 'Lenken er ugyldig' ), "the HMAC key depends on wp_salt( 'auth' )" );
$res = portal_http( $l5 );
t_eq( 302, $res['status'], 'same link valid again with the real salt' );

// ---------------------------------------------------------------------------------------------------------------
echo "\n[L8] One form for all lines, PRG, changing a decision, hooks\n";
$D  = make_product( 'Melk 1 liter', '20' );
$W  = make_product( 'Kaffe 500 g', '80' );
$E  = make_product( 'Bleier eco 2', '50' );
$W->set_weight( '0.5' );
$W->save();
tp_set( array( 'deadline_action' => 'refund' ) );
$o6 = tp_order( array( array( $A, 3 ), array( $D, 2 ), array( $C, 1 ) ) );
list( $l1, $l2, $l3 ) = tp_item_ids( $o6 );
admin_save( $o6, array(
	$l1 => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $B->get_id(), $C->get_id(), $W->get_id() ), 'notes' => 'Vi fikk ikke nok inn.' ),
	$l2 => array( 'missing' => '1', 'qty_missing' => '2', 'propose_delete' => '1' ),
	$l3 => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $B->get_id(), $E->get_id() ) ),
) );
$o6 = wc_get_order( $o6->get_id() );
t_ok( (bool) wp_next_scheduled( 'lp_missing_send_reminder', array( $o6->get_id(), $l1 ) ), 'reminder scheduled while waiting' );
portal_jar_reset();
$page = portal_login( $o6 );
$html = $page['html'];
t_eq( 3, substr_count( $html, '<fieldset' ), 'one fieldset per missing line' );
t_eq( 3, substr_count( $html, '<legend' ), 'each line has a legend' );
t_eq( 1, substr_count( $html, '<form' ), 'one form covers all lines' );
t_ok( false === strpos( $html, '<select' ), 'alternatives are radio cards, not a select' );
t_ok( false !== strpos( $html, 'type="radio"' ) && false !== strpos( $html, 'value="alt:' . $B->get_id() . '"' ), 'alternatives offered as radios' );
t_ok( false !== strpos( $html, 'class="lp-option__img"' ) && false !== strpos( $html, 'class="lp-portal-item__img"' ), 'cards show product images' );
t_ok( false !== strpos( $html, 'kr150.00 per stk' ) || false !== strpos( $html, '150,00' ), 'unit price shown' );
t_ok( false !== strpos( $html, '/kg' ) && false !== strpos( $html, '160.00/kg' ), 'price per kg for products with a weight (80 kr / 0.5 kg)' );
t_ok( 1 === substr_count( $html, '/kg' ), 'no price per kg without a weight' );
t_ok( false !== strpos( $html, 'Mellomlegget faktureres' ) && false !== strpos( $html, 'Ordren beholder opprinnelig pris' ) && false !== strpos( $html, 'Samme pris som original vare' ), 'honest price texts (surcharge, cheaper, same)' );
t_ok( false !== strpos( $html, 'value="decline"' ) && false !== strpos( $html, 'value="delete"' ), 'Nei takk and Fjern offered' );
t_eq( 1, substr_count( $html, 'value="delete"' ), 'Fjern only where staff offered it' );
t_ok( false !== strpos( $html, 'Hvis vi ikke hører fra deg innen' ), 'decision deadline sentence shown' );
t_ok( false !== strpos( $html, 'Vi fikk ikke nok inn.' ), 'staff note shown' );
t_ok( false !== strpos( $html, 'inputmode="numeric"' ) && false !== strpos( $html, 'name="lp_qty[' . $l1 . ']"' ), 'quantity field with numeric keyboard' );
t_ok( false === strpos( $html, 'name="lp_qty[' . $l3 . ']"' ), 'no quantity field for a single missing unit' );
t_ok( false !== strpos( $html, 'aria-live="polite"' ), 'live region for the summary' );
t_ok( false === stripos( $html, '<style' ) && false === stripos( $html, '<script' ), 'no inline style or script blocks' );
t_ok( wp_style_is( 'lp-missing-portal', 'enqueued' ) && wp_script_is( 'lp-missing-portal', 'enqueued' ), 'portal CSS/JS enqueued when the portal renders' );
preg_match_all( '/(👋|💛|💚|❌|🗑️|🎉|⏰|⚠️)/u', $html, $all_emoji );
preg_match_all( '/aria-hidden="true">\s*(👋|💛|💚|❌|🗑️|🎉|⏰|⚠️)/u', $html, $hidden_emoji );
t_ok( count( $all_emoji[0] ) > 0 && count( $all_emoji[0] ) === count( $hidden_emoji[0] ), 'every emoji is wrapped in aria-hidden' );
t_ok( false !== strpos( $html, 'Ikke valgt ennå' ), 'summary lists unanswered lines' );

$tp_hooks = array( 'decision' => array(), 'updated' => 0 );
$token    = extract_field( $html, 'lp_missing_token' );
$res      = portal_save( $o6, array( $l1 => 'alt:' . $B->get_id(), $l2 => 'delete', $l3 => 'decline' ), array( $l1 => 1 ), $token );
t_eq( 303, $res['status'], 'saving redirects with 303 (PRG)' );
t_ok( false !== strpos( $res['redirect'], 'lp_msg=saved' ) && false === strpos( $res['redirect'], 'lpk=' ), 'redirect target is the clean URL with a saved code' );
$d1 = item_data( $o6->get_id(), $l1 );
$d2 = item_data( $o6->get_id(), $l2 );
$d3 = item_data( $o6->get_id(), $l3 );
t_ok( 'alt_pending' === $d1['status'] && $B->get_id() === $d1['selected_alt_id'] && 1 === $d1['qty_alt'], 'line 1: alternative and quantity saved' );
t_eq( '50.00', $d1['pricing_snapshot']['delta_total_incl'], 'line 1: price frozen for 1 unit' );
t_eq( 'delete_pending', $d2['status'], 'line 2: removal saved' );
t_eq( 'declined', $d3['status'], 'line 3: no thanks saved' );
t_eq( 3, count( $tp_hooks['decision'] ), 'lp_missing_customer_decision fired per line' );
t_eq( 3, $tp_hooks['updated'], 'lp_missing_item_updated still fired per line' );
$first = $tp_hooks['decision'][0];
t_ok( $first['order'] instanceof WC_Order && $first['item_id'] === $l1 && 'pending' === $first['old']['status'] && 'alt_pending' === $first['new']['status'], 'decision hook passes order, item, new and old data' );
t_ok( ! wp_next_scheduled( 'lp_missing_send_reminder', array( $o6->get_id(), $l1 ) ), 'lifecycle cancelled the reminder of the answered line' );
t_ok( $d1['decision_made_at'] > 0 && ! $d1['needs_attention'], 'decision time recorded' );

$page = portal_follow( $res );
$html = $page['html'];
t_ok( false !== strpos( $html, 'Takk! Valget ditt er lagret' ) && false !== strpos( $html, 'role="status"' ), 'saved message shown as a status message' );
t_ok( false !== strpos( $html, 'Ditt valg nå:' ) && false !== strpos( $html, 'Pris låst ved valg' ), 'current decision and locked price shown' );
t_ok( 1 === preg_match( '/value="alt:' . $B->get_id() . '"[^>]*checked/s', $html ), 'saved choice is pre-selected' );
t_ok( false !== strpos( $html, 'Du kan endre valget ditt' ), 'customer is told the choice can be changed' );
t_ok( false !== strpos( $html, 'Resten (1 stk)' ) && false !== strpos( $html, 'Mellomlegg som faktureres i en egen ordre' ), 'summary explains partial quantity and the surcharge' );
t_ok( false === strpos( $html, 'Hvis vi ikke hører fra deg innen' ), 'no deadline sentence once every line is answered' );

$tp_hooks = array( 'decision' => array(), 'updated' => 0 );
$res      = portal_save( $o6, array( $l1 => 'alt:' . $B->get_id(), $l2 => 'delete', $l3 => 'decline' ), array( $l1 => 1 ), $token );
t_ok( false !== strpos( $res['redirect'], 'lp_msg=nochange' ) && 0 === count( $tp_hooks['decision'] ) && 0 === $tp_hooks['updated'], 'resubmitting the same answers changes nothing and fires nothing' );

// Change: same alternative, new quantity keeps the frozen unit prices even if the price changed since.
$frozen_at = item_data( $o6->get_id(), $l1 )['pricing_snapshot']['frozen_at'];
$B->set_regular_price( '170' );
$B->save();
$res = portal_save( $o6, array( $l1 => 'alt:' . $B->get_id() ), array( $l1 => 2 ), $token );
$d1  = item_data( $o6->get_id(), $l1 );
t_ok( 2 === $d1['qty_alt'] && '100.00' === $d1['pricing_snapshot']['delta_total_incl'] && $frozen_at === $d1['pricing_snapshot']['frozen_at'], 'new quantity of the same alternative keeps the price the customer was shown' );
t_eq( 1, count( $tp_hooks['decision'] ), 'decision hook fired for the change' );
t_ok( 'alt_pending' === $tp_hooks['decision'][0]['old']['status'] && 1 === $tp_hooks['decision'][0]['old']['qty_alt'], 'old data passed to the hook' );
// Change to another alternative: priced again at the new choice.
portal_save( $o6, array( $l1 => 'alt:' . $C->get_id() ), array( $l1 => 2 ), $token );
$d1 = item_data( $o6->get_id(), $l1 );
t_ok( $C->get_id() === $d1['selected_alt_id'] && '-100.00' === $d1['pricing_snapshot']['delta_total_incl'], 'changing the alternative re-freezes the price at the new choice' );
portal_save( $o6, array( $l1 => 'alt:' . $B->get_id() ), array( $l1 => 2 ), $token );
t_eq( '140.00', item_data( $o6->get_id(), $l1 )['pricing_snapshot']['delta_total_incl'], 'back to the first alternative: frozen at its current price' );
$B->set_regular_price( '150' );
$B->save();
portal_save( $o6, array( $l1 => 'decline', $l3 => 'alt:' . $B->get_id() ), array(), $token );
$d1 = item_data( $o6->get_id(), $l1 );
$d3 = item_data( $o6->get_id(), $l3 );
t_ok( 'declined' === $d1['status'] && 0 === $d1['selected_alt_id'] && array() === $d1['pricing_snapshot'], 'alternative changed to no thanks clears the choice and price' );
t_ok( 'alt_pending' === $d3['status'] && 1 === $d3['qty_alt'], 'no thanks changed to an alternative (single unit, no quantity field)' );

// Validation is all-or-nothing and shows errors per line.
$res = portal_save( $o6, array( $l1 => 'alt:' . $B->get_id(), $l3 => 'decline' ), array( $l1 => 3 ), $token );
t_ok( '' === $res['redirect'] && false !== strpos( $res['html'], 'Velg et antall mellom 1 og 2' ) && false !== strpos( $res['html'], 'role="alert"' ), 'invalid quantity is reported on the line' );
t_ok( 'declined' === item_data( $o6->get_id(), $l1 )['status'] && 'alt_pending' === item_data( $o6->get_id(), $l3 )['status'], 'nothing saved when a line is invalid' );
t_ok( 1 === preg_match( '/value="decline"[^>]*checked/s', $res['html'] ) || false !== strpos( $res['html'], 'aria-describedby="lp-err-' . $l1 . '"' ), 'error re-render keeps the posted answers and links the error' );
$res = portal_save( $o6, array( $l1 => 'alt:' . $A->get_id() ), array( $l1 => 1 ), $token );
t_ok( false !== strpos( $res['html'], 'kan ikke velges lenger' ), 'alternative that was not offered is refused' );
$res = portal_save( $o6, array( $l3 => 'delete' ), array(), $token );
t_ok( false !== strpos( $res['html'], 'kan ikke fjernes her' ), 'removal is refused where it was not offered' );
$res = portal_save( $o6, array( $l1 => 'bogus' ), array(), $token );
t_ok( false !== strpos( $res['html'], 'Velg et av svarene' ), 'unknown answer is refused' );

// After staff applied a decision, the line can no longer be changed.
$item3 = wc_get_order( $o6->get_id() )->get_item( $l3, false );
$data3 = call( 'get_item_data', $item3 );
$data3['status'] = 'alt_applied';
$item3->update_meta_data( '_lp_missing_data', $data3 );
$item3->save();
$res = portal_save( $o6, array( $l3 => 'decline' ), array(), $token );
t_ok( false !== strpos( $res['redirect'], 'lp_msg=handled' ) && 'alt_applied' === item_data( $o6->get_id(), $l3 )['status'], 'applied line cannot be changed' );
$page = portal_follow( $res );
t_eq( 2, substr_count( $page['html'], '<fieldset' ), 'applied line is no longer shown' );
t_ok( false !== strpos( $page['html'], 'allerede behandlet' ), 'customer is told it was already handled' );
// A decision cannot be saved while staff is applying one.
call( 'acquire_apply_lock', $o6->get_id() );
$res = portal_save( $o6, array( $l1 => 'alt:' . $C->get_id() ), array( $l1 => 1 ), $token );
call( 'release_apply_lock', $o6->get_id() );
t_ok( false !== strpos( $res['html'], 'oppdaterer ordren' ) && 'declined' === item_data( $o6->get_id(), $l1 )['status'], 'save refused while staff applies a decision' );
// Unconfirmed session cannot save.
portal_jar_reset();
$res   = portal_follow( portal_http( call( 'get_magic_link_for_order', $o6 ) ) );
$token2 = extract_field( $res['html'], 'lp_missing_token' );
$res   = portal_save( $o6, array( $l1 => 'delete' ), array(), $token2 );
t_ok( false !== strpos( $res['html'], 'Bekreft e-postadressen din først' ) && 'declined' === item_data( $o6->get_id(), $l1 )['status'], 'saving requires the email confirmation' );
// Token from another session is refused.
portal_login( $o6 );
$res = portal_save( $o6, array( $l1 => 'delete' ), array(), $token );
t_ok( false !== strpos( $res['html'], 'Sikkerhetssjekken feilet' ), 'form token is bound to the session' );
tp_set( array( 'deadline_action' => 'none' ) );

// ---------------------------------------------------------------------------------------------------------------
echo "\n[L8] Rate limit counts changes only\n";
$lines = array();
$prods = array();
for ( $i = 0; $i < 8; $i++ ) {
	$prods[] = make_product( 'Vare ' . $i, '10' );
	$lines[] = array( $prods[ $i ], 1 );
}
$o7  = tp_order( $lines );
$ids = tp_item_ids( $o7 );
$fields = array();
foreach ( $ids as $n => $id ) {
	$fields[ $id ] = array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $C->get_id() ) );
}
admin_save( $o7, $fields );
delete_transient( 'lp_missing_portal_rl_' . $o7->get_id() );
portal_jar_reset();
$page  = portal_login( $o7 );
$token = extract_field( $page['html'], 'lp_missing_token' );
for ( $i = 0; $i < 12; $i++ ) {
	portal_save( $o7, array(), array(), $token );
}
$answers = array();
foreach ( $ids as $id ) {
	$answers[ $id ] = 'alt:' . $C->get_id();
}
$res = portal_save( $o7, $answers, array(), $token );
t_ok( false !== strpos( $res['redirect'], 'lp_msg=saved' ), 'answering 8 lines in one go after many empty posts is not blocked' );
$ok = 0;
foreach ( $ids as $id ) {
	$ok += 'alt_pending' === item_data( $o7->get_id(), $id )['status'] ? 1 : 0;
}
t_eq( 8, $ok, 'all 8 lines saved' );
add_filter( 'lp_missing_portal_rate_limit', $tp_limit = function () { return array( 'max' => 3, 'window' => 600 ); } );
$state = get_transient( 'lp_missing_portal_rl_' . $o7->get_id() );
t_eq( 1, $state['count'], 'one save counts once' );
portal_save( $o7, array( $ids[0] => 'decline' ), array(), $token );
portal_save( $o7, array( $ids[0] => 'decline' ), array(), $token ); // No change: not counted.
portal_save( $o7, array( $ids[1] => 'decline' ), array(), $token );
$start = get_transient( 'lp_missing_portal_rl_' . $o7->get_id() )['start'];
$res   = portal_save( $o7, array( $ids[2] => 'decline' ), array(), $token );
t_ok( false !== strpos( $res['html'], 'mange endringer' ) && 'alt_pending' === item_data( $o7->get_id(), $ids[2] )['status'], 'change beyond the limit is blocked' );
$res = portal_save( $o7, array( $ids[0] => 'decline' ), array(), $token );
t_ok( false !== strpos( $res['redirect'], 'lp_msg=nochange' ), 'posts without changes are never blocked' );
$after = get_transient( 'lp_missing_portal_rl_' . $o7->get_id() );
t_ok( 3 === $after['count'] && $start === $after['start'], 'blocked attempts do not count or extend the window' );
remove_filter( 'lp_missing_portal_rate_limit', $tp_limit );

// ---------------------------------------------------------------------------------------------------------------
echo "\n[S7] Stock: out-of-stock alternatives hidden, low stock marked\n";
$OUT = make_product( 'Utsolgt vare', '100', 0 );
$LOW = make_product( 'Nesten tom', '100', 1 );
$OK  = make_product( 'Mye igjen', '100', 40 );
$o8  = tp_order( array( array( $A, 3 ), array( $D, 1 ) ) );
list( $s1, $s2 ) = tp_item_ids( $o8 );
admin_save( $o8, array(
	$s1 => array( 'missing' => '1', 'qty_missing' => '3', 'alternatives' => array( $OUT->get_id(), $LOW->get_id(), $OK->get_id() ) ),
	$s2 => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $OUT->get_id() ), 'propose_delete' => '1' ),
) );
portal_jar_reset();
$page  = portal_login( $o8 );
$html  = $page['html'];
$token = extract_field( $html, 'lp_missing_token' );
t_ok( false === strpos( $html, 'value="alt:' . $OUT->get_id() . '"' ), 'out-of-stock alternative is not offered' );
t_ok( false !== strpos( $html, 'value="alt:' . $LOW->get_id() . '"' ) && false !== strpos( $html, 'Få igjen' ) && false !== strpos( $html, 'Kun 1 igjen på lager' ), 'low stock alternative is marked "Få igjen"' );
t_ok( 1 === preg_match( '/value="alt:' . $LOW->get_id() . '"[^>]*data-max-qty="1"/s', $html ), 'low stock caps the quantity' );
preg_match( '/<fieldset[^>]*data-lp-item="' . $s2 . '".*?<\/fieldset>/s', $html, $fs2 );
t_ok( ! empty( $fs2[0] ) && false === strpos( $fs2[0], 'value="alt:' ) && false !== strpos( $fs2[0], 'value="decline"' ) && false !== strpos( $fs2[0], 'value="delete"' ), 'no available alternative: only Nei takk / Fjern' );
t_ok( ! empty( $fs2[0] ) && false !== strpos( $fs2[0], 'dessverre utsolgt' ), 'customer is told the suggestions are sold out' );
$res = portal_save( $o8, array( $s1 => 'alt:' . $OUT->get_id() ), array( $s1 => 1 ), $token );
t_ok( false !== strpos( $res['html'], 'utsolgt nå' ), 'choosing a sold-out alternative is refused' );
$res = portal_save( $o8, array( $s1 => 'alt:' . $LOW->get_id() ), array( $s1 => 2 ), $token );
t_ok( false !== strpos( $res['html'], 'Vi har bare 1 stk' ), 'more than in stock is refused (has_enough_stock)' );
$res = portal_save( $o8, array( $s1 => 'alt:' . $LOW->get_id() ), array( $s1 => 1 ), $token );
t_ok( 'alt_pending' === item_data( $o8->get_id(), $s1 )['status'], 'available quantity is accepted' );
$LOW->set_stock_quantity( 0 );
$LOW->save();
$page = portal_http( portal_clean_url( $o8 ) );
t_ok( false !== strpos( $page['html'], 'Alternativet du valgte er dessverre utsolgt' ), 'chosen alternative that sold out since is flagged' );

// ---------------------------------------------------------------------------------------------------------------
echo "\n[L8] Store covers the difference\n";
tp_set( array( 'store_covers_below' => 60 ) );
$o9  = tp_order( array( array( $A, 2 ) ) );
$n1  = first_item_id( $o9 );
admin_save( $o9, array( $n1 => array( 'missing' => '1', 'qty_missing' => '2', 'alternatives' => array( $B->get_id() ) ) ) );
portal_jar_reset();
$page = portal_login( $o9 );
t_ok( 1 === preg_match( '/data-covers="10"/', $page['html'] ), 'per-quantity "store covers" flags for the live summary (50 covered, 100 charged)' );
$res  = portal_save( $o9, array( $n1 => 'alt:' . $B->get_id() ), array( $n1 => 1 ), extract_field( $page['html'], 'lp_missing_token' ) );
$page = portal_follow( $res );
t_ok( false !== strpos( $page['html'], 'som butikken dekker' ) && false !== strpos( $page['html'], 'Ingen ekstra kostnad for deg' ), 'covered difference explained, nothing to pay' );
tp_set( array( 'store_covers_below' => 0, 'alt_price_handling' => 'store_covers' ) );
$page = portal_http( portal_clean_url( $o9 ) );
t_ok( false !== strpos( $page['html'], 'butikken dekker mellomlegget' ), 'store-covers mode explained' );
tp_set( array( 'alt_price_handling' => 'charge_customer' ) );

// ---------------------------------------------------------------------------------------------------------------
echo "\n[L6] Logged-in owner, shortcode attributes and staff preview\n";
$uid = wp_insert_user( array( 'user_login' => 'eier' . wp_rand( 1, 999999 ), 'user_pass' => 'x', 'user_email' => 'eier' . wp_rand( 1, 999999 ) . '@example.com', 'role' => 'customer' ) );
$o10 = tp_order( array( array( $A, 2 ) ), $uid );
$m1  = first_item_id( $o10 );
admin_save( $o10, array( $m1 => array( 'missing' => '1', 'qty_missing' => '1', 'alternatives' => array( $C->get_id() ) ) ) );
portal_jar_reset();
$page = portal_http( portal_clean_url( $o10 ), array(), $uid );
t_ok( false !== strpos( $page['html'], 'lp-portal-item' ) && false === strpos( $page['html'], 'lp_missing_verify_email' ), 'order owner (logged in) skips the email confirmation' );
$res = portal_save( $o10, array( $m1 => 'decline' ), array(), extract_field( $page['html'], 'lp_missing_token' ), $uid );
t_ok( false !== strpos( $res['redirect'], 'lp_msg=saved' ) && 'declined' === item_data( $o10->get_id(), $m1 )['status'], 'owner can save without a session cookie' );
$other = wp_insert_user( array( 'user_login' => 'annen' . wp_rand( 1, 999999 ), 'user_pass' => 'x', 'user_email' => 'kunde@example.com', 'role' => 'customer' ) );
if ( is_wp_error( $other ) ) {
	$other = wp_insert_user( array( 'user_login' => 'annen' . wp_rand( 1, 999999 ), 'user_pass' => 'x', 'user_email' => 'annen' . wp_rand( 1, 999999 ) . '@example.com', 'role' => 'customer' ) );
}
$page = portal_http( portal_clean_url( $o10 ), array(), $other );
t_ok( false !== strpos( $page['html'], 'Åpne lenken i e-posten' ), 'another logged-in customer has no access' );
$page = portal_http( get_permalink( wc_get_page_id( 'myaccount' ) ), array(), $uid, array( 'order_id' => $o10->get_id(), 'email' => 'kunde@example.com' ) );
t_ok( false !== strpos( $page['html'], '<form' ) && false !== strpos( $page['html'], 'name="lp_oid"' ), 'owner gets the interactive portal through shortcode attributes' );
$page = portal_http( get_permalink( wc_get_page_id( 'myaccount' ) ), array(), 1, array( 'order_id' => $o10->get_id(), 'email' => 'kunde@example.com' ) );
t_ok( false !== strpos( $page['html'], 'Staff preview' ) && false === strpos( $page['html'], '<form' ), 'staff get a read-only preview through shortcode attributes' );

wp_set_current_user( 1 );
$preview = LP_Missing_Magic_Link::get_staff_preview_url( $o6 );
portal_jar_reset();
$GLOBALS['lp_cookie_log'] = array();
$page = portal_http( $preview, array(), 1 );
t_ok( '' === $page['redirect'] && false !== strpos( $page['html'], 'Staff preview' ) && false !== strpos( $page['html'], 'lp-portal-item' ), 'staff preview shows the portal' );
t_ok( false === strpos( $page['html'], '<form' ) && false === strpos( $page['html'], 'lp_missing_token' ) && false !== strpos( $page['html'], ' disabled' ), 'preview is read-only (no form, disabled inputs)' );
t_ok( empty( $GLOBALS['lp_cookie_log'] ) && ! empty( $GLOBALS['lp_private_headers'] ), 'preview sets no customer session and sends private headers' );
$before = item_data( $o6->get_id(), $l1 )['status'];
$res    = portal_http( $preview, array( 'lp_oid' => $o6->get_id(), 'lp_missing_portal_action' => 'save', 'lp_missing_token' => 'x', 'lp_choice' => array( $l1 => 'delete' ) ), 1 );
t_eq( $before, item_data( $o6->get_id(), $l1 )['status'], 'posts in preview mode are ignored' );
$page = portal_http( $preview, array(), 0 );
t_ok( false !== strpos( $page['html'], 'preview link is invalid' ), 'preview nonce needs the staff user' );
$shop_customer = wp_insert_user( array( 'user_login' => 'kunde' . wp_rand( 1, 999999 ), 'user_pass' => 'x', 'user_email' => 'k' . wp_rand( 1, 999999 ) . '@example.com', 'role' => 'customer' ) );
wp_set_current_user( $shop_customer );
$customer_preview = LP_Missing_Magic_Link::get_staff_preview_url( $o6 );
$page = portal_http( $customer_preview, array(), $shop_customer );
t_ok( false !== strpos( $page['html'], 'preview link is invalid' ), 'preview needs the edit_shop_orders capability' );
wp_set_current_user( 1 );

// ---------------------------------------------------------------------------------------------------------------
echo "\n[S12] Private headers, noindex\n";
$page = portal_http( portal_clean_url( $o6 ) );
t_eq( 'no-referrer', isset( $GLOBALS['lp_private_headers']['Referrer-Policy'] ) ? $GLOBALS['lp_private_headers']['Referrer-Policy'] : '', 'Referrer-Policy: no-referrer' );
t_ok( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'DONOTCACHEPAGE defined' );
$robots = apply_filters( 'wp_robots', array( 'max-image-preview' => 'large' ) );
t_ok( ! empty( $robots['noindex'] ) && ! empty( $robots['nofollow'] ) && ! isset( $robots['max-image-preview'] ), 'wp_robots: noindex, nofollow' );
$res = portal_http( home_url( '/?p=1' ) );
t_ok( empty( $GLOBALS['lp_private_headers'] ) && '' === $res['redirect'], 'requests without an order are left alone' );
t_ok( is_callable( array( 'LP_Missing_Portal', 'on_template_redirect' ) ) && has_action( 'template_redirect', array( 'LP_Missing_Portal', 'on_template_redirect' ) ), 'handler runs on template_redirect (before output)' );

// ---------------------------------------------------------------------------------------------------------------
echo "\n[S17] Portal page setup\n";
wp_set_current_user( 1 );
$settings_before = LP_Missing_Settings::get_settings( true );
$existing_pages  = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1, 's' => '[lp_missing_items' ) );
foreach ( $existing_pages as $p ) {
	wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'draft' ) );
}
tp_set( array( 'portal_page_id' => 0, 'portal_base_url' => '' ) );
update_option( 'lp_missing_portal_url', '' );
LP_Missing_Settings::flush();
LP_Missing_Portal_Setup::activate();
$created = absint( LP_Missing_Settings::get_settings( true )['portal_page_id'] );
$page    = $created ? get_post( $created ) : null;
t_ok( $page && 'publish' === $page->post_status && 'Velg erstatning' === $page->post_title, 'activation creates the published page "Velg erstatning"' );
t_ok( $page && 0 === strpos( $page->post_name, 'velg-erstatning' ) && has_shortcode( $page->post_content, 'lp_missing_items' ), 'page has the slug velg-erstatning and the shortcode' );
LP_Missing_Portal_Setup::activate();
t_eq( $created, absint( LP_Missing_Settings::get_settings( true )['portal_page_id'] ), 'activating again keeps the page' );
t_ok( 0 === strpos( call( 'get_magic_link_for_order', $o6 ), get_permalink( $created ) ), 'links point at the new portal page' );
// Upgrade path for existing installs.
tp_set( array( 'portal_page_id' => 0 ) );
delete_option( 'lp_missing_portal_setup_done' );
delete_option( LP_Missing_Magic_Link::OPTION_V1_CUTOFF );
update_option( 'lp_missing_db_version', '1.1.0' );
delete_transient( 'lp_missing_upgrading' );
LP_Missing_Plugin::maybe_upgrade();
LP_Missing_Settings::flush();
t_eq( $created, absint( LP_Missing_Settings::get_settings( true )['portal_page_id'] ), 'upgrade step selects the existing portal page' );
t_ok( absint( get_option( LP_Missing_Magic_Link::OPTION_V1_CUTOFF ) ) >= time() - 60, 'upgrade step records the v1 cutoff' );
t_ok( version_compare( get_option( 'lp_missing_db_version' ), '1.2.0', '>=' ), 'upgrade completed' );
tp_set( array( 'portal_page_id' => 0 ) );
LP_Missing_Portal_Setup::upgrade();
t_eq( 0, absint( LP_Missing_Settings::get_settings( true )['portal_page_id'] ), 're-running the upgrade step leaves a deliberate page choice alone' );
tp_set( array( 'portal_base_url' => 'https://example.com/portal/' ) );
t_eq( 0, LP_Missing_Portal_Setup::ensure_portal_page(), 'no page is created while an external portal URL is configured' );
tp_set( array( 'portal_base_url' => '' ) );
update_option( 'lp_missing_portal_url', '' );
LP_Missing_Settings::flush();
// My Account keeps working without a portal page.
tp_set( array( 'portal_page_id' => 0 ) );
t_ok( 0 === strpos( call( 'get_magic_link_for_order', $o6 ), get_permalink( wc_get_page_id( 'myaccount' ) ) ), 'without a portal page, links point at My Account' );
$_GET = array( 'oid' => $o6->get_id() );
global $wp_query, $wp_the_query;
$wp_query     = new WP_Query( array( 'page_id' => wc_get_page_id( 'myaccount' ) ) );
$wp_the_query = $wp_query;
$wp_query->the_post();
LP_Missing_Portal::reset_request_state();
$content = apply_filters( 'the_content', get_post( wc_get_page_id( 'myaccount' ) )->post_content );
wp_reset_postdata();
$_GET = array();
t_ok( false !== strpos( $content, 'lp-missing-portal' ), 'My Account shows the portal for a portal URL' );
// Admin notice when the page cannot show the portal.
$plain = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Uten kode', 'post_content' => 'Hei' ) );
tp_set( array( 'portal_page_id' => $plain ) );
t_ok( false !== strpos( LP_Missing_Portal_Setup::get_portal_page_problem(), 'does not contain' ), 'problem detected: page without the shortcode' );
set_current_screen( 'woocommerce_page_lp-missing-settings' );
ob_start();
LP_Missing_Portal_Setup::admin_notice();
$notice = ob_get_clean();
t_ok( false !== strpos( $notice, 'notice-warning' ) && false !== strpos( $notice, '[lp_missing_items]' ), 'admin notice on the plugin settings screen' );
set_current_screen( 'dashboard' );
ob_start();
LP_Missing_Portal_Setup::admin_notice();
t_eq( '', ob_get_clean(), 'no notice on unrelated screens' );
wp_update_post( array( 'ID' => $plain, 'post_status' => 'draft' ) );
t_ok( false !== strpos( LP_Missing_Portal_Setup::get_portal_page_problem(), 'not published' ), 'problem detected: page not published' );
tp_set( array( 'portal_page_id' => $created ) );
t_eq( '', LP_Missing_Portal_Setup::get_portal_page_problem(), 'no problem with a valid portal page' );
update_option( LP_Missing_Portal_Setup::OPTION_PENDING, 1 );
tp_set( array( 'portal_page_id' => 0 ) );
LP_Missing_Portal_Setup::maybe_finish_activation();
t_ok( ! get_option( LP_Missing_Portal_Setup::OPTION_PENDING ) && $created === absint( LP_Missing_Settings::get_settings( true )['portal_page_id'] ), 'deferred activation finishes on a later request' );
// Restore the site.
wp_delete_post( $plain, true );
if ( $existing_pages ) {
	wp_delete_post( $created, true );
	foreach ( $existing_pages as $p ) {
		wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'publish' ) );
	}
}
update_option( 'lp_missing_settings', $tp_settings_backup );
update_option( 'woocommerce_weight_unit', $tp_weight_unit );
update_option( 'lp_missing_portal_url', isset( $tp_settings_backup['portal_base_url'] ) ? $tp_settings_backup['portal_base_url'] : '' );
LP_Missing_Settings::flush();

// ---------------------------------------------------------------------------------------------------------------
echo "\n[S13] Logging\n";
$log = tp_log_since( $tp_log_start );
foreach ( array( 'Customer link issued.', 'Customer portal session started from a link.', 'Customer confirmed the billing email', 'Customer email confirmation failed.', 'Customer link invalid.', 'Expired customer link used.', 'Revoked customer link used.', 'Legacy (v1) customer link accepted', 'Customer decision saved in the portal.', 'Customer changed a decision in the portal.', 'Customer links revoked.', 'Customer link signing key rotated.', 'Staff opened the customer portal preview.', 'rate limit' ) as $event ) {
	t_ok( false !== strpos( $log, $event ), 'logged: ' . $event );
}
t_ok( false === strpos( $log, $lpk ) && false === strpos( $log, $parts[3] ), 'link keys are never logged' );
t_ok( false === strpos( $log, 'noen@example.com' ), 'submitted email addresses are not logged' );

t_summary();
