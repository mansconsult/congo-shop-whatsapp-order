<?php
/** Private per-merchant journals. Central journal remains authoritative. */
defined( 'ABSPATH' ) || exit;
class CSWA_Merchant_Sheets {
 const OPTION = 'cswa_merchant_sheets';
 const LOCK = 'cswa_merchant_sheets_lock';
 public static function columns() {
  return array_diff_key( CSWA_Google::columns(), array_flip( array( 'site', 'vendor', 'vendor_number', 'product_id', 'order_id', 'ref' ) ) );
 }
 /** Use the recorded vendor, never the current assignment of a product. */
 public static function identity( $payload ) {
  if ( empty( $payload['marketplace'] ) || ! empty( $payload['product']['is_service'] ) ) { return null; }
  $id = (int) ( $payload['product']['vendor_id'] ?? 0 );
  if ( $id ) {
   $user = get_userdata( $id );
   if ( ! $user || ! array_intersect( array( 'cswa_vendor', 'cswa_pending', 'cswa_suspended' ), (array) $user->roles ) || user_can( $user, 'manage_options' ) ) { return new WP_Error( 'merchant', 'Le compte marchand de cette demande est introuvable.' ); }
   $legacy = get_user_meta( $id, '_cswa_legacy_key', true );
   return array( 'key' => $legacy ? 'legacy:' . $legacy : 'user:' . $id, 'name' => CSWA_Vendors::shop_name( $id ) );
  }
  $phone = CSWA_Number::normalize( $payload['destination']['number'] ?? '' );
  $name = (string) ( $payload['product']['vendor'] ?? '' );
  $matches = array();
  foreach ( CSWA_Vendors::legacy_merchants() as $key => $row ) {
   if ( in_array( $phone, $row['numbers'], true ) ) { $matches[$key] = $row; }
  }
  if ( count( $matches ) === 1 ) { $key = key( $matches ); return array( 'key' => 'legacy:' . $key, 'name' => $matches[$key]['name'] ); }
  if ( count( $matches ) > 1 ) { return new WP_Error( 'merchant', 'Plusieurs marchands utilisent ce numéro : vérifie les réglages Marchands.' ); }
  // Unassigned fallback traffic stays exclusively in the central file.
  if ( ! $name ) { return null; }
  return new WP_Error( 'merchant', 'Marchand non identifié : attribue le produit à un compte ou renseigne sa ligne dans les réglages Marchands.' );
 }
 private static function api( $method, $url, $token, $body = null ) {
  $args = array( 'method' => $method, 'timeout' => 25, 'redirection' => 0, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ) );
  if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); }
  $r = wp_remote_request( $url, $args );
  if ( is_wp_error( $r ) ) { return new WP_Error( 'merchant_network', 'Google Sheets marchand : réponse réseau incertaine ; reprise différée.' ); }
  $code = wp_remote_retrieve_response_code( $r ); $data = json_decode( wp_remote_retrieve_body( $r ), true );
  if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) { return new WP_Error( 'merchant_api', 'Google Sheets marchand : erreur HTTP ' . $code . '. Vérifie la connexion Google et les droits du fichier.', array( 'status' => $code ) ); }
  return $data;
 }
 private static function save_registry( $registry ) { update_option( self::OPTION, $registry, false ); }
 /** Caller holds the shared lock. Never repeat an ambiguous creation blindly. */
 private static function ensure_file( $key, $name, $token, &$registry ) {
  if ( ! empty( $registry[$key]['id'] ) ) { return $registry[$key]['id']; }
  $q = "trashed = false and appProperties has { key='cswa_merchant' and value='" . $key . "' }";
  $found = self::api( 'GET', CSWA_Google::DRIVE_API . '?' . http_build_query( array( 'q' => $q, 'fields' => 'files(id),nextPageToken', 'pageSize' => 2 ) ), $token );
  if ( is_wp_error( $found ) ) { return $found; }
  $files = $found['files'] ?? array();
  if ( count( $files ) > 1 || ! empty( $found['nextPageToken'] ) ) { return new WP_Error( 'merchant_duplicate', 'Plusieurs fichiers correspondent à ce marchand. Vérification administrateur nécessaire.' ); }
  if ( count( $files ) === 1 ) {
   $registry[$key] = array( 'name' => $name, 'id' => $files[0]['id'] ); self::save_registry( $registry ); return $files[0]['id'];
  }
  if ( ! empty( $registry[$key]['creating'] ) ) { return new WP_Error( 'merchant_uncertain', 'Création du fichier marchand non confirmée. Recherche automatique en cours ; aucune seconde création pour éviter un doublon.' ); }
  $registry[$key] = array( 'name' => $name, 'creating' => gmdate( 'c' ) ); self::save_registry( $registry );
  // Root, not the shared central folder: avoid inheriting its sharing permissions.
  $created = self::api( 'POST', CSWA_Google::DRIVE_API . '?fields=id&ignoreDefaultVisibility=true', $token, array( 'name' => 'Congo-Shop — Commandes — ' . $name, 'mimeType' => 'application/vnd.google-apps.spreadsheet', 'appProperties' => array( 'cswa_merchant' => $key ) ) );
  if ( is_wp_error( $created ) ) {
   $status = (int) ( $created->get_error_data()['status'] ?? 0 );
   if ( in_array( $status, array( 400, 401, 403, 404, 429 ), true ) ) { unset( $registry[$key]['creating'] ); self::save_registry( $registry ); }
   return $created;
  }
  if ( empty( $created['id'] ) ) { return new WP_Error( 'merchant_uncertain', 'Google n’a pas confirmé l’identifiant du fichier marchand.' ); }
  $registry[$key] = array( 'name' => $name, 'id' => $created['id'] ); self::save_registry( $registry ); return $created['id'];
 }
 public static function sync( $payload, $ref, $row ) {
  $merchant = array_key_exists( 'merchant_sheet_identity', $payload ) ? $payload['merchant_sheet_identity'] : self::identity( $payload );
  if ( is_array( $merchant ) && isset( $merchant['error'] ) ) { return new WP_Error( 'merchant', $merchant['error'] ); }
  if ( null === $merchant ) { return true; }
  if ( is_wp_error( $merchant ) ) { return $merchant; }
  if ( ! CSWA_Store::acquire_lock( self::LOCK, 900 ) ) { return new WP_Error( 'merchant_busy', 'Synchronisation marchand en cours, reprise différée.' ); }
  try {
   $seed = get_option( 'cswa_merchant_sheet_namespace', '' );
   if ( ! $seed ) { $seed = wp_generate_uuid4(); add_option( 'cswa_merchant_sheet_namespace', $seed, '', false ); $seed = get_option( 'cswa_merchant_sheet_namespace' ); }
   $key = hash( 'sha256', $seed . '|' . $merchant['key'] );
   $registry = get_option( self::OPTION, array() );
   $token = CSWA_Google::access_token(); if ( is_wp_error( $token ) ) { return $token; }
   $id = self::ensure_file( $key, $merchant['name'], $token, $registry );
   $result = is_wp_error( $id ) ? $id : self::write( $id, $token, $ref, $row, ! empty( $registry[$key]['initialized'] ) );
   if ( isset( $registry[$key] ) ) { if ( true === $result ) { $registry[$key]['initialized'] = true; } $registry[$key]['error'] = is_wp_error( $result ) ? $result->get_error_message() : ''; self::save_registry( $registry ); }
   return $result;
  } finally { CSWA_Store::release_lock( self::LOCK ); }
 }
 private static function cells( $values ) {
  return array_map( static function( $v ) { return array( 'userEnteredValue' => array( 'stringValue' => (string) $v ) ); }, array_values( $values ) );
 }
 private static function write( $id, $token, $ref, $row, $initialized ) {
  $base = CSWA_Google::SHEETS_API . '/' . rawurlencode( $id );
  $marker = hash( 'sha256', $ref );
  $book = self::api( 'GET', $base . '?fields=sheets.properties', $token );
  if ( is_wp_error( $book ) ) { return $book; }
  $tabs = array();
  foreach ( $book['sheets'] ?? array() as $sheet ) { $tabs[$sheet['properties']['title']] = $sheet['properties']; }
  foreach ( array( 'Commandes', '_CSWA_SYNC' ) as $title ) {
   if ( isset( $tabs[$title] ) ) { continue; }
   if ( $initialized ) { return new WP_Error( 'merchant_missing_tab', 'Un onglet du fichier marchand a été supprimé ou renommé. Restaure-le pour reprendre sans doublon.' ); }
   $added = self::api( 'POST', $base . ':batchUpdate', $token, array( 'requests' => array( array( 'addSheet' => array( 'properties' => array( 'title' => $title, 'hidden' => '_CSWA_SYNC' === $title, 'gridProperties' => array( 'rowCount' => 1000, 'columnCount' => '_CSWA_SYNC' === $title ? 1 : count( self::columns() ), 'frozenRowCount' => 1 ) ) ) ) ) ) );
   if ( is_wp_error( $added ) ) { return $added; }
   $tabs[$title] = $added['replies'][0]['addSheet']['properties'] ?? null;
   if ( null === $tabs[$title] ) { return new WP_Error( 'merchant_tab', 'Onglet marchand non confirmé.' ); }
  }
  $tab = $tabs['Commandes'];
  $prior = self::api( 'GET', $base . '/values/' . rawurlencode( "'_CSWA_SYNC'!A:A" ), $token );
  if ( is_wp_error( $prior ) ) { return $prior; }
  foreach ( $prior['values'] ?? array() as $value ) { if ( ( $value[0] ?? '' ) === $marker ) { return true; } }
  $tab_id = (int) $tab['sheetId'];
  $head = self::api( 'GET', $base . '/values/' . rawurlencode( "'Commandes'!1:1" ), $token );
  if ( is_wp_error( $head ) ) { return $head; }
  $headers = array_values( self::columns() ); $requests = array();
  if ( empty( $head['values'] ) ) {
   $requests[] = array( 'updateCells' => array( 'start' => array( 'sheetId' => $tab_id, 'rowIndex' => 0, 'columnIndex' => 0 ), 'rows' => array( array( 'values' => self::cells( $headers ) ) ), 'fields' => 'userEnteredValue' ) );
  } elseif ( $head['values'][0] !== $headers ) { return new WP_Error( 'merchant_columns', 'Les colonnes du fichier marchand ont été modifiées. Rétablis les en-têtes avant de reprendre la synchronisation.' ); }
  $data = array_combine( array_keys( CSWA_Google::columns() ), $row ); $values = array();
  foreach ( self::columns() as $k => $label ) { $values[] = $data[$k] ?? ''; }
  $requests[] = array( 'appendCells' => array( 'sheetId' => $tab_id, 'rows' => array( array( 'values' => self::cells( $values ) ) ), 'fields' => 'userEnteredValue' ) );
  $requests[] = array( 'appendCells' => array( 'sheetId' => (int) $tabs['_CSWA_SYNC']['sheetId'], 'rows' => array( array( 'values' => self::cells( array( $marker ) ) ) ), 'fields' => 'userEnteredValue' ) );
  $requests[] = array( 'setDataValidation' => array( 'range' => array( 'sheetId' => $tab_id, 'startRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => 1 ), 'rule' => array( 'condition' => array( 'type' => 'ONE_OF_LIST', 'values' => array_map( static function( $v ) { return array( 'userEnteredValue' => $v ); }, CSWA_Google::statuses() ) ), 'strict' => false, 'showCustomUi' => true ) ) );
  // The row and its deduplication marker are committed atomically by Sheets.
  $result = self::api( 'POST', $base . ':batchUpdate', $token, array( 'requests' => $requests ) );
  return is_wp_error( $result ) ? $result : true;
 }
 public static function admin_panel() {
  if ( ! current_user_can( 'manage_options' ) ) { return; }
  echo '<h2>Fichiers Google Sheets des marchands</h2><p>Un fichier privé par marchand est créé à sa première nouvelle commande. Le fichier central reste conservé. Aucun partage ni e-mail automatique. Les anciennes demandes déjà synchronisées ne sont pas recopiées.</p>';
  foreach ( get_option( self::OPTION, array() ) as $entry ) {
   echo '<p><strong>' . esc_html( $entry['name'] ?? 'Marchand' ) . '</strong> : ';
   if ( ! empty( $entry['id'] ) ) { echo '<a target="_blank" rel="noopener" href="https://docs.google.com/spreadsheets/d/' . esc_attr( $entry['id'] ) . '/edit">Ouvrir le fichier</a>'; }
   else { echo 'Création à confirmer'; }
   if ( ! empty( $entry['error'] ) ) { echo ' — ' . esc_html( $entry['error'] ); }
   echo '</p>';
  }
 }
}
