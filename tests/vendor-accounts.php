<?php
// Isolated account lifecycle checks. Does not contact or mutate any real WordPress site.
define('ABSPATH', __DIR__);
class WP_Error { public $code; function __construct($code,$message=''){$this->code=$code;} }
function is_wp_error($x){return $x instanceof WP_Error;}
$users=[];$meta=[];$current=0;$admin=true;$destroyed=[];
class CSWA_Plugin { public static $settings=['country_code'=>'243','site_mode'=>'marketplace','vendor_dashboard'=>1]; static function get_setting($k,$d=''){return self::$settings[$k]??$d;} }
require __DIR__.'/../includes/class-cswa-number.php';
require __DIR__.'/../includes/class-cswa-vendors.php';
function sanitize_text_field($s){return trim(strip_tags($s));}
function absint($n){return abs((int)$n);}
function get_userdata($id){global $users;return $users[$id]??false;}
function user_can($u,$cap){return in_array('administrator',$u->roles,true);}
function current_user_can($cap,$id=0){global $admin;return $admin;}
function get_current_user_id(){global $current;return $current;}
function get_user_by($kind,$value){global $users;foreach($users as $u){if($u->user_login===$value)return $u;}return false;}
function get_users($args){global $users,$meta;$out=[];foreach($users as $id=>$u){if(isset($args['meta_key'])&&($meta[$id][$args['meta_key']]??null)!==$args['meta_value'])continue;$out[]=$id;}return $out;}
function get_user_meta($id,$key,$single=true){global $meta;return $meta[$id][$key]??'';}
function update_user_meta($id,$key,$v){global $meta;$meta[$id][$key]=$v;}
function wp_generate_uuid4(){return '00000000-0000-4000-8000-'.str_pad((string)random_int(1,999999),12,'0',STR_PAD_LEFT);}
function wp_insert_user($args){global $users;if(get_user_by('login',$args['user_login']))return new WP_Error('duplicate');$id=count($users)+1;$users[$id]=(object)['ID'=>$id,'user_login'=>$args['user_login'],'user_email'=>$args['user_email'],'user_pass'=>password_hash($args['user_pass'],PASSWORD_DEFAULT),'roles'=>[$args['role']],'display_name'=>$args['display_name']];return $id;}
function delete_transient($key){}
function wp_check_password($p,$hash,$id){return password_verify($p,$hash);}
function wp_set_password($p,$id){global $users;$users[$id]->user_pass=password_hash($p,PASSWORD_DEFAULT);}
function wp_authenticate_username_password($u,$login,$p){$u=get_user_by('login',$login);return $u&&wp_check_password($p,$u->user_pass,$u->ID)?$u:new WP_Error('password');}
class WP_Session_Tokens {private $id;static function get_instance($id){$x=new self();$x->id=$id;return $x;}function destroy_all(){global $destroyed;$destroyed[]=$this->id;}}
function expect($ok,$label){if(!$ok)throw new Exception($label);echo "PASS $label\n";}
CSWA_Plugin::$settings['vendor_map']="Boutique Test = +243 810 000 001, 0810000002\nAutre Test = 243810000003\n# commentaire\nmalformed";
$rows=CSWA_Vendors::legacy_merchants();expect(count($rows)===2,'parse legacy lines without invented accounts');$key=array_key_first($rows);$row=$rows[$key];expect($row['numbers']===['243810000001','243810000002'],'normalize secondary numbers');
$data=['legacy_key'=>$key,'primary_phone'=>$row['numbers'][0],'initial_password'=>'Test-only-secret-123'];
$admin=false;expect(is_wp_error(CSWA_Vendors::provision_legacy($data)),'non-admin denied');$admin=true;
$bad=$data;$bad['primary_phone']='243810000099';expect(is_wp_error(CSWA_Vendors::provision_legacy($bad)),'unconfigured primary denied');
$bad=$data;$bad['initial_password']='short';expect(is_wp_error(CSWA_Vendors::provision_legacy($bad)),'short password denied');
$id=CSWA_Vendors::provision_legacy($data);expect(is_int($id),'create approved legacy account');expect($users[$id]->roles===['cswa_vendor']&&$users[$id]->user_email==='','no invented email, approved role');expect($users[$id]->user_pass!==$data['initial_password'],'no plain password storage');
expect(is_wp_error(CSWA_Vendors::provision_legacy($data))&&count($users)===1,'repeated import cannot duplicate account');
$auth=CSWA_Vendors::authenticate_phone(null,'+243 810 000 001','Test-only-secret-123');expect($auth->ID===$id,'phone login with password');expect(is_wp_error(CSWA_Vendors::authenticate_phone(null,'0810000001','wrong')),'bad password denied');
$error=new WP_Error('security-plugin');expect(CSWA_Vendors::authenticate_phone($error,'0810000001','Test-only-secret-123')===$error,'prior security error preserved');expect(CSWA_Vendors::authenticate_phone(null,'ordinary@example.test','pass')===null,'email authentication left to WordPress');
// Changed legacy spelling can be linked explicitly, never resets credentials or grants approval.
CSWA_Plugin::$settings['vendor_map']='Boutique Test renommée = 243810000001';$key2=array_key_first(CSWA_Vendors::legacy_merchants());$data2=['legacy_key'=>$key2,'primary_phone'=>'243810000001','initial_password'=>'Another-secret-456'];$hash=$users[$id]->user_pass;$users[$id]->roles=['cswa_pending'];
expect(is_wp_error(CSWA_Vendors::provision_legacy($data2)),'existing account requires explicit selection');$data2['existing_id']=$id;expect(CSWA_Vendors::provision_legacy($data2)===$id,'explicit matching account reused');expect($users[$id]->user_pass===$hash&&$users[$id]->roles===['cswa_pending'],'reuse preserves password and pending status');$users[$id]->roles=['cswa_vendor'];
$second=wp_insert_user(['user_login'=>'another','user_pass'=>'Other-secret-123','user_email'=>'','display_name'=>'Other','role'=>'cswa_vendor']);update_user_meta($second,'_cswa_whatsapp','243810000001');expect(is_wp_error(CSWA_Vendors::authenticate_phone(null,'0810000001','Test-only-secret-123')),'ambiguous phone alias refused');expect(is_wp_error(CSWA_Vendors::guard_phone_login($users[$id])),'core callbacks cannot override ambiguous alias denial');unset($meta[$second]['_cswa_whatsapp']);
$current=$second;expect(is_wp_error(CSWA_Vendors::change_own_password($id,['current_password'=>'Test-only-secret-123','new_password'=>'New-secret-456','confirm_password'=>'New-secret-456'])),'cannot change another account password');
$current=$id;expect(is_wp_error(CSWA_Vendors::change_own_password($id,['current_password'=>'wrong','new_password'=>'New-secret-456','confirm_password'=>'New-secret-456'])),'current password required');
expect(CSWA_Vendors::change_own_password($id,['current_password'=>'Test-only-secret-123','new_password'=>'New-secret-456','confirm_password'=>'New-secret-456'])===true,'change own password');expect(in_array($id,$destroyed,true)&&!wp_check_password('Test-only-secret-123',$users[$id]->user_pass,$id),'sessions revoked and old password rejected');
echo "Vendor account checks passed\n";
