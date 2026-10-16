<?php
/** Provider forms, using the existing KinPresta/OneClick assignments. */
defined( 'ABSPATH' ) || exit;
class CSWA_Service {
 public static function enabled() {
  $mode = CSWA_Plugin::get_setting( 'form_mode', 'auto' );
  return 'service' === $mode || ( 'auto' === $mode && function_exists( 'kpwa_number_for' ) );
 }
 public static function matches( $id ) {
  return self::enabled() && has_term( 'artisant', 'product_cat', $id );
 }
 public static function destination( $id ) {
  // The saved OneClick record ID is authoritative. Never use another shop's fallback.
  if ( function_exists( 'kpwa_number_for' ) ) {
   $record = absint( kpwa_number_for( $id ) );
   $number = '';
   if ( $record && 'wa-order-numbers' === get_post_type( $record ) && 'publish' === get_post_status( $record ) ) {
    $number = CSWA_Number::normalize( get_post_meta( $record, 'wa_order_phone_number_input', true ) );
   }
   return array( 'number' => $number, 'source' => 'produit:kinpresta' );
  }
  return CSWA_Number::oneclick_assignment( $id, 0 ) ?: array( 'number' => '', 'source' => 'produit:kinpresta' );
 }
 public static function filter_shortcode( $output, $tag ) {
  if ( ! self::enabled() || is_admin() || ! is_string( $output ) ) { return $output; }
  if ( ! in_array( $tag, array( 'kp_cartes', 'kp_annuaire', 'kp_fiche_similaires', 'kp_fiche_contact', 'kp_fiche_entete' ), true ) ) { return $output; }
  // Remove the old direct links from cards and profile sections.
  $output = preg_replace( '~<div\b[^>]*class="kpwa-wrap"[^>]*>.*?</div>~s', '', $output );
  if ( 'kp_fiche_contact' === $tag && CSWA_Frontend::is_active() ) {
   $button = CSWA_Frontend::shortcode_button( array( 'label' => 'Contacter sur WhatsApp' ) );
   $output = str_replace( '<div class="kp-contact-actions">', '<div class="kp-contact-actions">' . $button, $output );
  }
  return $output;
 }
}
