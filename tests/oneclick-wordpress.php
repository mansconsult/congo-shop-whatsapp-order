<?php
// Real WP/MySQL + WooCommerce, disposable CI only; no live external traffic.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '/tmp/cswa-wp/' !== ABSPATH || 'cswa.test' !== wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) ) { throw new RuntimeException( 'Disposable CI site required.' ); }
function oc_assert( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } WP_CLI::log( 'PASS ' . $label ); }
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static function() { return new WP_Error( 'ci_offline', 'External calls disabled.' ); } );
wp_set_current_user( get_user_by( 'login', 'ci-admin' )->ID );
$settings = array( 'site_mode' => 'marketplace', 'vendor_dashboard' => 0, 'country_code' => '243', 'whatsapp_number' => '243810000099', 'rate_limit' => 100 );
update_option( 'cswa_settings', $settings );
register_post_type( 'wa-order-numbers', array( 'public' => false ) );
function oc_record( $title, $phone, $status = 'publish' ) {
 $id = wp_insert_post( array( 'post_type' => 'wa-order-numbers', 'post_status' => $status, 'post_title' => $title ) );
 update_post_meta( $id, 'wa_order_phone_number_input', $phone ); return $id;
}
function oc_assign( $id, $title ) { update_post_meta( $id, '_wa_order_phone_number_check', 'yes' ); update_post_meta( $id, '_wa_order_phone_number', $title ); }
$p = new WC_Product_Simple(); $p->set_name( 'OneClick CI product' ); $p->set_status( 'publish' ); $p->set_regular_price( '10' ); $pid = $p->save();
$phone = '243810000011';
$cases = array(
 'unique' => array( array( $phone ), $phone ),
 'duplicates' => array( array( $phone, $phone ), $phone ),
 'formats' => array( array( '+243 810 000 011', '00243810000011', '0810000011', '810000011' ), $phone ),
 'different' => array( array( $phone, '243810000012' ), '' ),
 'empty' => array( array( $phone, '' ), '' ),
 'invalid' => array( array( $phone, '123' ), '' ),
 'third-different' => array( array( $phone, $phone, '243810000012' ), '' ),
 'missing' => array( array(), '' ),
);
foreach ( $cases as $name => $case ) {
 $title = 'CI ' . $name;
 foreach ( $case[0] as $value ) { oc_record( $title, $value ); }
 oc_assign( $pid, $title );
 $r = CSWA_Number::resolve( $pid, 0, '243810000098' );
 oc_assert( $r['number'] === $case[1] && $r['source'] === 'produit:oneclick', $name . ': all matches checked, no button/global fallback' );
 if ( '' === $case[1] ) { oc_assert( is_wp_error( CSWA_Product::resolve( $pid, 0, array(), 1, '243810000098' ) ), $name . ': product/form rejects broken destination' ); }
}
oc_record( 'CI CASE Shop', $phone ); oc_record( 'CI Case Shop', $phone );
oc_record( 'CI Case Shop', '243810000012', 'draft' );
oc_assign( $pid, 'CI Case Shop' );
$r = CSWA_Number::oneclick_assignment( $pid );
oc_assert( $r['number'] === $phone && $r['vendor'] === 'CI Case Shop', 'case-insensitive matches accept same number, exact title wins, drafts excluded' );
// Compare the form response with an actual REST submission and persisted Woo order.
$request = new WP_REST_Request( 'POST', '/cswa/v1/quote' );
foreach ( array( 'nonce' => wp_create_nonce( 'wp_rest' ), 'product_id' => $pid, 'qty' => 1, 'button_number' => '243810000098', 'idem_key' => wp_generate_uuid4(), 'customer' => array( 'mode' => 'pickup', 'full_name' => 'CI Customer', 'phone' => '0810000022' ) ) as $k => $v ) { $request->set_param( $k, $v ); }
$quote = CSWA_Rest::quote( $request );
oc_assert( ! is_wp_error( $quote ), 'quote succeeds for duplicate same-number records' );
$q = $quote->get_data()['product'];
oc_assert( $q['vendor'] === 'CI Case Shop' && $q['whatsapp_number'] === $phone, 'form merchant and recipient agree' );
$submit = CSWA_Rest::submit( $request );
oc_assert( ! is_wp_error( $submit ), 'real submission succeeds' );
$data = $submit->get_data(); $order = wc_get_order( $data['order_id'] );
oc_assert( $order && $order->get_meta( '_cswa_vendor_name' ) === $q['vendor'] && $order->get_meta( '_cswa_whatsapp' ) === $q['whatsapp_number'], 'WooCommerce persisted merchant and number equal form' );
$row = CSWA_Store::get_by_idem( $request->get_param( 'idem_key' ) ); $payload = json_decode( $row['payload'], true );
oc_assert( $payload['product']['vendor'] === $q['vendor'] && $payload['destination']['number'] === $phone && strpos( $data['whatsapp_url'], 'https://wa.me/' . $phone . '?' ) === 0 && $payload['merchant_sheet_identity']['phone'] === $phone, 'request, WhatsApp link and Sheets identity use same recipient' );
// A newly broken assignment must block submission before any request/order is saved.
oc_record( 'CI Case Shop', '' ); $request->set_param( 'idem_key', wp_generate_uuid4() );
oc_assert( is_wp_error( CSWA_Rest::submit( $request ) ) && ! CSWA_Store::get_by_idem( $request->get_param( 'idem_key' ) ), 'broken assignment cannot persist an order or use fallback' );
delete_post_meta( $pid, '_wa_order_phone_number_check' );
oc_assert( null === CSWA_Number::oneclick_assignment( $pid ), 'no OneClick assignment remains null' );
update_post_meta( $pid, 'whatsapp', $phone );
oc_assert( CSWA_Number::resolve( $pid, 0, '' )['number'] === $phone, 'existing product metadata routing preserved without OneClick' );
$parent = new WC_Product_Variable(); $parent->set_name( 'CI variable' ); $parent->set_status( 'publish' ); $parent_id = $parent->save();
$variant = new WC_Product_Variation(); $variant->set_parent_id( $parent_id ); $variant->set_regular_price( '10' ); $variant->set_status( 'publish' ); $vid = $variant->save();
oc_record( 'CI Parent', $phone ); oc_record( 'CI Variant', '243810000012' );
oc_assign( $parent_id, 'CI Parent' ); oc_assign( $vid, 'CI Variant' );
oc_assert( CSWA_Number::resolve( $parent_id, $vid, '' )['number'] === '243810000012', 'variant wins over parent' );
$v = CSWA_Product::resolve( $parent_id, $vid, array(), 1 );
oc_assert( ! is_wp_error( $v ) && $v['vendor'] === 'CI Variant' && $v['destination']['number'] === '243810000012', 'variant product name and recipient coherent' );
oc_assign( $vid, 'CI nonexistent' );
oc_assert( CSWA_Number::resolve( $parent_id, $vid, $phone )['number'] === '', 'broken variant blocks valid parent and button' );
delete_post_meta( $vid, '_wa_order_phone_number_check' );
oc_assert( CSWA_Number::resolve( $parent_id, $vid, '' )['number'] === $phone, 'unassigned variant inherits parent' );
$settings['site_mode'] = 'single'; update_option( 'cswa_settings', $settings );
oc_assign( $parent_id, 'CI nonexistent' );
oc_assert( CSWA_Number::resolve( $parent_id, $vid, $phone )['number'] === $settings['whatsapp_number'] && CSWA_Number::vendor_name( $parent_id, $settings['whatsapp_number'], $vid ) === '', 'single-shop priority and display unchanged' );
$settings['vendor_dashboard'] = 1; update_option( 'cswa_settings', $settings );
$vendor = get_user_by( 'login', '243810000001' );
// Use the approved account created by the account lifecycle CI scenario.
$vendors = get_users( array( 'role' => 'cswa_vendor', 'meta_key' => '_cswa_whatsapp', 'meta_value' => '243810000001' ) );
oc_assert( count( $vendors ) === 1, 'approved account fixture available' );
update_post_meta( $parent_id, '_cswa_vendor_id', $vendors[0]->ID );
foreach ( array( 'single', 'marketplace' ) as $mode ) {
 $settings['site_mode'] = $mode; update_option( 'cswa_settings', $settings );
 $r = CSWA_Number::resolve( $parent_id, $vid, $phone );
 oc_assert( $r['source'] === 'compte-marchand' && $r['number'] === '243810000001', 'merchant account priority preserved in ' . $mode );
}
WP_CLI::success( 'OneClick resolution and real WooCommerce persistence passed; no live Google delivery tested.' );
