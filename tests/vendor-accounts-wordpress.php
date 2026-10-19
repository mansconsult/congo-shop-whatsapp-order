<?php
// Runs only in the disposable CI WordPress installation through wp eval-file.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'cswa.test' !== wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) || '/tmp/cswa-wp/' !== ABSPATH ) { throw new RuntimeException( 'Disposable CI site required.' ); }
function cswa_assert( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( $label ); } WP_CLI::log( 'PASS ' . $label ); }
add_filter( 'pre_wp_mail', '__return_true' );
$admin = get_user_by( 'login', 'ci-admin' ); wp_set_current_user( $admin->ID );
$settings = array( 'site_mode' => 'marketplace', 'vendor_dashboard' => 1, 'country_code' => '243', 'vendor_map' => 'CI Shop = 243810000001', 'sheet_id' => 'preserved-ci-setting' );
update_option( 'cswa_settings', $settings );
$rows = CSWA_Vendors::legacy_merchants(); $key = array_key_first( $rows );
$data = array( 'legacy_key' => $key, 'primary_phone' => '243810000001', 'initial_password' => 'CI-initial-pass-123' );
$id = CSWA_Vendors::provision_legacy( $data );
cswa_assert( is_int( $id ) && CSWA_Vendors::active( $id ), 'create approved real WordPress user' );
cswa_assert( is_wp_error( CSWA_Vendors::provision_legacy( $data ) ), 'repeat import rejected' );
cswa_assert( wp_check_password( $data['initial_password'], get_userdata( $id )->user_pass, $id ), 'WordPress password hash verifies' );
foreach ( array( '243810000001', '+243 810 000 001', '0810000001' ) as $login ) {
 $auth = wp_authenticate( $login, $data['initial_password'] );
 cswa_assert( $auth instanceof WP_User && $auth->ID === $id, 'native authentication ' . $login );
}
cswa_assert( is_wp_error( wp_authenticate( '0810000001', 'incorrect' ) ), 'native wrong password rejected' );
$deny = function( $user ) { return new WP_Error( 'ci_security', 'Security policy' ); };
add_filter( 'wp_authenticate_user', $deny, 10 );
cswa_assert( is_wp_error( wp_authenticate( '0810000001', $data['initial_password'] ) ), 'WordPress security filter respected' );
remove_filter( 'wp_authenticate_user', $deny, 10 );
$other = wp_insert_user( array( 'user_login' => 'ci-other', 'user_pass' => 'CI-other-password', 'role' => 'cswa_vendor' ) );
update_user_meta( $other, '_cswa_whatsapp', '243810000001' );
cswa_assert( is_wp_error( wp_authenticate( '243810000001', $data['initial_password'] ) ), 'core cannot bypass ambiguous phone guard' );
delete_user_meta( $other, '_cswa_whatsapp' );
wp_set_current_user( $id );
cswa_assert( is_wp_error( CSWA_Vendors::provision_legacy( $data ) ), 'merchant cannot provision users' );
$token = WP_Session_Tokens::get_instance( $id )->create( time() + 3600 );
cswa_assert( CSWA_Vendors::change_own_password( $id, array( 'current_password' => $data['initial_password'], 'new_password' => 'CI-new-password-456', 'confirm_password' => 'CI-new-password-456' ) ) === true, 'merchant password change' );
cswa_assert( ! WP_Session_Tokens::get_instance( $id )->verify( $token ), 'existing sessions revoked' );
cswa_assert( is_wp_error( wp_authenticate( '0810000001', $data['initial_password'] ) ), 'old password rejected' );
cswa_assert( wp_authenticate( '0810000001', 'CI-new-password-456' ) instanceof WP_User, 'new password accepted' );
wp_set_current_user( $admin->ID );
$product = new WC_Product_Simple(); $product->set_name( 'CI existing product' ); $product->set_regular_price( '10' ); $pid = $product->save();
$_POST = array( 'cswa_assign_nonce' => wp_create_nonce( 'cswa_assign_vendor' ), 'cswa_vendor_id' => $id );
CSWA_Vendors::save_assignment( $pid );
cswa_assert( CSWA_Vendors::product_vendor( $pid ) === $id && CSWA_Vendors::destination( $pid )['number'] === '243810000001', 'existing WooCommerce product assigned and routed' );
wp_set_current_user( $other ); $_POST['cswa_vendor_id'] = $other; CSWA_Vendors::save_assignment( $pid );
cswa_assert( CSWA_Vendors::product_vendor( $pid ) === $id, 'merchant cannot reassign product' );
cswa_assert( get_option( 'cswa_settings' ) === $settings, 'settings unchanged' );
WP_CLI::success( 'Real WordPress merchant checks passed. Live-site update still requires testing.' );
