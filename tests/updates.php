<?php
// Focused contract checks; WordPress integration must still be tested separately.
define( 'ABSPATH', __DIR__ );
define( 'CSWA_FILE', 'congo-shop-whatsapp-order/congo-shop-whatsapp-order.php' );
define( 'CSWA_VERSION', '3.0.8' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
$cache = array(); $responses = array(); $requests = 0;
function get_site_transient( $key ) { global $cache; return $cache[$key] ?? false; }
function set_site_transient( $key, $value, $ttl ) { global $cache; $cache[$key] = $value; }
function delete_site_transient( $key ) { global $cache; unset($cache[$key]); }
function wp_remote_get( $url, $args ) { global $responses, $requests; $requests++; return $responses[$url] ?? array('status'=>404, 'body'=>''); }
function is_wp_error( $value ) { return $value instanceof Exception; }
function wp_remote_retrieve_response_code( $value ) { return $value['status']; }
function wp_remote_retrieve_body( $value ) { return $value['body']; }
function plugin_basename( $file ) { return $file; }
function esc_html( $text ) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
require __DIR__ . '/../includes/class-cswa-updates.php';
function expect( $condition, $message ) { if (!$condition) { throw new Exception($message); } }
function scenario( $release, $manifest = null ) {
    global $responses, $cache, $requests;
    $cache = array(); $requests = 0;
    $responses = array(CSWA_Updates::API => array('status'=>200, 'body'=>json_encode($release)));
    if ($manifest !== null) { $responses[CSWA_Updates::REPO . '/releases/download/v3.0.9/update.json'] = array('status'=>200, 'body'=>json_encode($manifest)); }
}
$base = CSWA_Updates::REPO . '/releases/download/v3.0.9/';
$release = array('tag_name'=>'v3.0.9', 'draft'=>false, 'prerelease'=>false, 'body'=>'<script>alert(1)</script>', 'assets'=>array(
    array('name'=>'congo-shop-whatsapp-order-3.0.9.zip', 'size'=>1024, 'state'=>'uploaded', 'browser_download_url'=>$base.'congo-shop-whatsapp-order-3.0.9.zip'),
    array('name'=>'update.json', 'size'=>100, 'state'=>'uploaded', 'browser_download_url'=>$base.'update.json')
));
$manifest = array('slug'=>CSWA_Updates::SLUG, 'version'=>'3.0.9', 'requires'=>'5.8', 'requires_php'=>'7.4');
scenario($release, $manifest);
$result = CSWA_Updates::update(false, array(), CSWA_FILE, array());
expect($result['version']==='3.0.9' && $result['package']===$base.'congo-shop-whatsapp-order-3.0.9.zip', 'Valid release not offered');
expect(!isset($result['autoupdate']), 'Must not force unattended updates');
expect(CSWA_Updates::update('untouched', array(), 'another/plugin.php', array())==='untouched', 'Other plugin altered');
$details = CSWA_Updates::details(false, 'plugin_information', (object)array('slug'=>CSWA_Updates::SLUG));
expect(strpos($details->sections['changelog'], '<script>')===false, 'Unsafe release notes');
CSWA_Updates::release(); expect($requests===2, 'Positive cache not reused');
foreach (array('draft','prerelease') as $flag) { $bad=$release; $bad[$flag]=true; scenario($bad,$manifest); expect(CSWA_Updates::release()===false, 'Unstable release accepted'); }
$bad=$release; $bad['assets']=array(); scenario($bad,$manifest); expect(CSWA_Updates::release()===false,'Missing ZIP accepted');
$bad=$release; $bad['assets'][0]['browser_download_url']='https://evil.example/plugin.zip'; scenario($bad,$manifest); expect(CSWA_Updates::release()===false,'Foreign download accepted');
$bad=$manifest; $bad['version']='3.1.0'; scenario($release,$bad); expect(CSWA_Updates::release()===false,'Mismatched manifest accepted');
$bad=$manifest; $bad['requires_php']=array('8.0'); scenario($release,$bad); expect(CSWA_Updates::release()===false,'Malformed requirement accepted');
$bad=$release; $bad['tag_name']='v3.0.9-beta'; scenario($bad,$manifest); expect(CSWA_Updates::release()===false,'Beta tag accepted');
scenario(null); expect(CSWA_Updates::release()===false,'Null response accepted'); CSWA_Updates::release(); expect($requests===1,'Negative cache not reused');
scenario($release); $responses[CSWA_Updates::API]=new Exception('timeout'); expect(CSWA_Updates::release()===false,'Network error not handled');
echo "Update contract tests passed\n";
