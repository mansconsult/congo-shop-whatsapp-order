<?php
// Real WordPress persistence and locks, simulated Google transport; no external data sent.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '/tmp/cswa-wp/' !== ABSPATH ) { throw new RuntimeException( 'CI only' ); }
function ms_assert( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } WP_CLI::log( 'PASS ' . $label ); }
add_filter( 'pre_wp_mail', '__return_true' );
set_transient( 'cswa_access_token', 'fake-ci-token', 3600 );
$settings = get_option( 'cswa_settings' );
$settings['vendor_map'] = "Alpha = 243810000001\nBeta = 243810000002\nGamma = 243810000003\nDelta = 243810000004";
update_option( 'cswa_settings', $settings );
update_option( 'cswa_sheet_id', 'central-untouched' );
$books = array(); $create_count = 0; $lost_write = false; $lost_create = false; $reject_create = false;
$transport = function( $pre, $args, $url ) use ( &$books, &$create_count, &$lost_write, &$lost_create, &$reject_create ) {
 if ( false !== $pre ) { return $pre; }
 $ok = static function( $body, $code = 200 ) { return array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $body ), 'headers' => array() ); };
 $body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : array();
 if ( strpos( $url, CSWA_Google::DRIVE_API ) === 0 ) {
  if ( $args['method'] === 'GET' ) {
   parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $q ); preg_match( "/value='([a-f0-9]+)'/", $q['q'], $m ); $files = array();
   foreach ( $books as $id => $book ) { if ( $book['key'] === $m[1] ) { $files[] = array( 'id' => $id ); } }
   return $ok( array( 'files' => $files ) );
  }
  if ( $reject_create ) { $reject_create = false; return $ok( array( 'error' => array() ), 403 ); }
  ms_assert( empty( $body['parents'] ) && empty( $body['permissions'] ), 'no shared folder or public access inherited by request' );
  $id = 'merchant-file-' . ++$create_count;
  $books[$id] = array( 'key' => $body['appProperties']['cswa_merchant'], 'tabs' => array(), 'rows' => array(), 'markers' => array(), 'headers' => array() );
  if ( $lost_create ) { $lost_create = false; return new WP_Error( 'timeout', 'lost create response' ); }
  return $ok( array( 'id' => $id ) );
 }
 if ( strpos( $url, CSWA_Google::SHEETS_API . '/' ) !== 0 ) { throw new RuntimeException( 'Unexpected external URL' ); }
 preg_match( '~/spreadsheets/([^/? :]+)~', $url, $m ); $id = $m[1];
 if ( ! isset( $books[$id] ) ) { return $ok( array(), 404 ); }
 $book =& $books[$id];
 if ( strpos( $url, ':batchUpdate' ) !== false ) {
  $replies = array();
  foreach ( $body['requests'] as $r ) {
   if ( isset( $r['addSheet'] ) ) { $p = $r['addSheet']['properties']; $p['sheetId'] = count( $book['tabs'] ) + 10; $book['tabs'][$p['title']] = $p; $replies[] = array( 'addSheet' => array( 'properties' => $p ) ); }
   if ( isset( $r['updateCells'] ) ) { $book['headers'] = array_column( array_column( $r['updateCells']['rows'][0]['values'], 'userEnteredValue' ), 'stringValue' ); }
   if ( isset( $r['appendCells'] ) ) {
    $values = array_column( array_column( $r['appendCells']['rows'][0]['values'], 'userEnteredValue' ), 'stringValue' );
    if ( $r['appendCells']['sheetId'] === $book['tabs']['Commandes']['sheetId'] ) { $book['rows'][] = $values; } else { $book['markers'][] = $values; }
   }
  }
  if ( $lost_write && isset( $body['requests'][0]['appendCells'] ) ) { $lost_write = false; return new WP_Error( 'timeout', 'lost write response' ); }
  return $ok( array( 'replies' => $replies ) );
 }
 if ( strpos( $url, '/values/' ) !== false ) {
  return $ok( array( 'values' => strpos( rawurldecode( $url ), '_CSWA_SYNC' ) !== false ? $book['markers'] : ( $book['headers'] ? array( $book['headers'] ) : array() ) ) );
 }
 return $ok( array( 'sheets' => array_map( static function( $p ) { return array( 'properties' => $p ); }, array_values( $book['tabs'] ) ) ) );
};
add_filter( 'pre_http_request', $transport, 10, 3 );
$payload = array( 'marketplace' => true, 'product' => array( 'name' => '=FORMULA()', 'vendor' => 'Alpha' ), 'destination' => array( 'number' => '243810000001' ), 'customer' => array( 'full_name' => 'Only Alpha' ) );
$row = CSWA_Google::build_row( $payload, 'ref-a' );
ms_assert( CSWA_Merchant_Sheets::sync( $payload, 'ref-a', $row ) === true, 'first merchant file created and written' );
ms_assert( CSWA_Merchant_Sheets::sync( $payload, 'ref-a', $row ) === true && count( $books['merchant-file-1']['rows'] ) === 1 && $create_count === 1, 'repeat creates neither duplicate file nor row' );
foreach ( array( 'Site', 'Marchand', 'Numéro marchand', 'ID commande', 'ID produit', 'ID demande' ) as $label ) { ms_assert( ! in_array( $label, $books['merchant-file-1']['headers'], true ), 'excluded ' . $label ); }
$lost_write = true;
ms_assert( is_wp_error( CSWA_Merchant_Sheets::sync( $payload, 'ref-a2', $row ) ), 'lost append response reported' );
ms_assert( CSWA_Merchant_Sheets::sync( $payload, 'ref-a2', $row ) === true && count( $books['merchant-file-1']['rows'] ) === 2, 'atomic marker prevents retry duplicate' );
$beta = $payload; $beta['product']['vendor'] = 'Beta'; $beta['destination']['number'] = '243810000002'; $beta['customer']['full_name'] = 'Only Beta'; $lost_create = true;
ms_assert( is_wp_error( CSWA_Merchant_Sheets::sync( $beta, 'ref-b', CSWA_Google::build_row( $beta, 'ref-b' ) ) ), 'lost creation response reported' );
ms_assert( CSWA_Merchant_Sheets::sync( $beta, 'ref-b', CSWA_Google::build_row( $beta, 'ref-b' ) ) === true && $create_count === 2, 'lost creation recovered by private marker without new file' );
ms_assert( strpos( wp_json_encode( $books['merchant-file-1']['rows'] ), 'Only Beta' ) === false && strpos( wp_json_encode( $books['merchant-file-2']['rows'] ), 'Only Alpha' ) === false, 'merchant customer data isolated' );
$single = $payload; $single['marketplace'] = false;
ms_assert( CSWA_Merchant_Sheets::sync( $single, 'single', $row ) === true && $create_count === 2, 'single-shop mode unchanged' );
$gamma = $payload; $gamma['product']['vendor'] = 'Gamma'; $gamma['destination']['number'] = '243810000003'; $reject_create = true;
ms_assert( is_wp_error( CSWA_Merchant_Sheets::sync( $gamma, 'ref-g', $row ) ), 'permission error retained' );
ms_assert( CSWA_Merchant_Sheets::sync( $gamma, 'ref-g', $row ) === true && $create_count === 3, 'confirmed rejection can retry after permission restored' );
unset( $books['merchant-file-1']['tabs']['_CSWA_SYNC'] );
ms_assert( is_wp_error( CSWA_Merchant_Sheets::sync( $payload, 'ref-a3', $row ) ), 'deleted synchronization tab blocks unsafe rewrite' );
ms_assert( get_option( 'cswa_sheet_id' ) === 'central-untouched' && get_option( 'cswa_settings' ) === $settings, 'central file and settings unchanged' );
$central_rows = array();
add_filter( 'pre_http_request', function( $pre, $args, $url ) use ( &$central_rows ) {
 if ( strpos( $url, '/spreadsheets/central-untouched' ) === false ) { return $pre; }
 $data = array();
 if ( strpos( $url, ':append' ) !== false ) { $body = json_decode( $args['body'], true ); $central_rows[] = $body['values'][0]; }
 elseif ( strpos( $url, ':batchUpdate' ) !== false ) { $data = array( 'replies' => array() ); }
 elseif ( strpos( $url, '/values/' ) !== false ) {
  $data = array( 'values' => strpos( rawurldecode( $url ), '!1:1' ) !== false ? array( CSWA_Google::headers() ) : array_map( static function( $r ) { return array( $r[ CSWA_Google::column_index( 'ref' ) ] ); }, $central_rows ) );
 } else { $data = array( 'sheets' => array( array( 'properties' => array( 'sheetId' => 0, 'title' => CSWA_Google::tab_name() ) ) ) ); }
 return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $data ) );
}, 9, 3 );
$delta = $payload; $delta['product']['vendor'] = 'Delta'; $delta['destination']['number'] = '243810000004';
$delta['merchant_sheet_identity'] = CSWA_Merchant_Sheets::identity( $delta );
$saved = CSWA_Store::insert_request( wp_generate_uuid4(), $delta, '127.0.0.1' );
ms_assert( ! is_wp_error( $saved ), 'request persisted in real WordPress database' );
$reject_create = true;
ms_assert( CSWA_Google::sync_request( $saved['row'] ) === false && count( $central_rows ) === 1, 'central success survives merchant error' );
ms_assert( CSWA_Google::sync_request( $saved['row'] ) === true && count( $central_rows ) === 1, 'retry completes merchant without duplicating central row' );
ms_assert( CSWA_Store::get_by_id( $saved['row']['id'] )['sheet_status'] === 'ok', 'both destinations confirmed before marking synchronized' );
WP_CLI::success( 'Merchant Sheets transport scenarios passed; real Google test still required.' );
