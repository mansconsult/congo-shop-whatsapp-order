<?php
/**
 * Plugin Name: Congo-Shop - Commande WhatsApp
 * Plugin URI:  https://mans-consulting.com/
 * Description: Commandes WooCommerce enregistrées avant ouverture de WhatsApp, synchronisation Google Sheets et espace marchand facultatif avec approbation des vendeurs.
 * Version:     3.0.9
 * Author:      Mans Consulting
 * Text Domain: cswa
 * Requires at least: 5.8
 * Update URI: https://github.com/mansconsult/congo-shop-whatsapp-order
 * Requires PHP: 7.4
 * WC requires at least: 8.2
 *
 * @package CSWA
 */

defined( 'ABSPATH' ) || exit;

define( 'CSWA_VERSION', '3.0.9' );
define( 'CSWA_FILE', __FILE__ );
define( 'CSWA_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSWA_URL', plugin_dir_url( __FILE__ ) );
define( 'CSWA_DB_VERSION', 3 );

// Independent of WooCommerce: update checks must remain available if it is disabled.
require_once CSWA_DIR . 'includes/class-cswa-updates.php';
CSWA_Updates::init();

require_once CSWA_DIR . 'includes/class-cswa-store.php';
require_once CSWA_DIR . 'includes/class-cswa-number.php';
require_once CSWA_DIR . 'includes/class-cswa-service.php';
require_once CSWA_DIR . 'includes/class-cswa-product.php';
require_once CSWA_DIR . 'includes/class-cswa-google.php';
require_once CSWA_DIR . 'includes/class-cswa-rest.php';
require_once CSWA_DIR . 'includes/class-cswa-frontend.php';
require_once CSWA_DIR . 'includes/class-cswa-settings.php';
require_once CSWA_DIR . 'includes/class-cswa-orders.php';
require_once CSWA_DIR . 'includes/class-cswa-vendors.php';

/**
 * Conteneur principal.
 */
final class CSWA_Plugin {

	/**
	 * Instance unique.
	 *
	 * @var CSWA_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Valeurs par defaut des reglages.
	 *
	 * @var array
	 */
	private static $defaults = array(
		'whatsapp_number'  => '243832310808',
		'country_code'     => '243',
		'number_meta_key'  => '',
		'site_mode'        => 'marketplace',
		'vendor_dashboard' => 0,
		'form_mode'        => 'auto',
		'communes'         => "Bandalungwa\nBarumbu\nBumbu\nGombe\nKalamu\nKasa-Vubu\nKimbanseke\nKinshasa\nKintambo\nKisenso\nLemba\nLimete\nLingwala\nMakala\nMaluku\nMasina\nMatete\nMont-Ngafula\nNdjili\nNgaba\nNgaliema\nNgiri-Ngiri\nNsele\nSelembao",
		'vendor_map'       => "Congo Shop = 243840652395\nHappy Baby = 243990194654\nNicky beauty = 243990390553\nIT-COM = 243890017539\nPerluna store = 243848405000\nNAC = 243897070512\nDECATHLON = 243900111558\nAwael bloc = 243810478729, 243855233886\nVision afrika = 243981716115\nLOKEY GROUP = 243970871862\nMADI tanks = 243827862146\nMANY Shop = 243850297619\nTotal = 243999916000\nSo Gaz = 243970000529\nPhone global = 243817366173\nMatala sport = 243817539348\niPhone-Kin = 243832310808\nMF Kabelo = 243898077803\nMoto TVS = 243858698629\nGames Market = 243820756705\nFresh Market = 243818849089\nSOS Shopping Mall = 243843322613\nDev solaire = 243858348424\nPharmans = 243852591175\nEden matelas = 243900007313\nHome & Co = 243835156504\nIntermission = 243999000000\nCIMKO = 243978341082\nKin sport = 243900533333\nBricom = 243895250837",
		'vendor_category'  => 'boutique',
		'site_name'        => '',
		'site_list'        => "Congo Shop\nCongo Shop Pay\nMobile RDC\niPhone Kin\nKinbrique",
		'shared_sheet_id'  => '',
		'admin_only'       => 1,
		'test_mode'        => 0,
		'admin_email'      => '',
		'drive_folder_id'  => '',
		'extra_selectors'  => '',
		'rate_limit'       => 6,
		'privacy_text'     => 'Ces informations sont enregistrées uniquement pour traiter ta demande et sont transmises au marchand via WhatsApp.',
	);

	/**
	 * Recupere l'instance.
	 *
	 * @return CSWA_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructeur.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'cswa_retry_sheet', array( 'CSWA_Google', 'process_queue' ) );
		add_action( 'cswa_sync_request', array( 'CSWA_Google', 'sync_request_by_id' ), 10, 1 );
	}

	/**
	 * Demarre les sous-systemes une fois WooCommerce charge.
	 *
	 * @return void
	 */
	public function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_no_woocommerce' ) );
			return;
		}

		CSWA_Store::maybe_upgrade();
		CSWA_Orders::init();
		CSWA_Vendors::init();
		// La reprise continue même après une mise à jour ou la perte de l'événement WP-Cron.
		if ( ! wp_next_scheduled( 'cswa_retry_sheet' ) ) {
			wp_schedule_event( time() + 300, 'cswa_five_minutes', 'cswa_retry_sheet' );
		}
		CSWA_Rest::init();
		CSWA_Frontend::init();

		if ( is_admin() ) {
			CSWA_Settings::init();
		}
	}

	/**
	 * Avertissement si WooCommerce est absent.
	 *
	 * @return void
	 */
	public function notice_no_woocommerce() {
		echo '<div class="notice notice-error"><p><strong>Congo-Shop - Commande WhatsApp</strong> : WooCommerce est requis.</p></div>';
	}

	/**
	 * Ajoute la frequence de reprise.
	 *
	 * @param array $schedules Frequences.
	 * @return array
	 */
	public function cron_schedules( $schedules ) {
		$schedules['cswa_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'Toutes les 5 minutes (Congo-Shop WhatsApp)',
		);
		return $schedules;
	}

	/**
	 * Lit un reglage.
	 *
	 * @param string $key     Cle.
	 * @param mixed  $default Valeur de repli.
	 * @return mixed
	 */
	public static function get_setting( $key, $default = null ) {
		$settings = get_option( 'cswa_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings = array_merge( self::$defaults, $settings );

		if ( ! array_key_exists( $key, $settings ) ) {
			return $default;
		}
		if ( '' === $settings[ $key ] && null !== $default ) {
			return $default;
		}
		return $settings[ $key ];
	}

	/**
	 * Retourne tous les reglages fusionnes avec les valeurs par defaut.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( 'cswa_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		return array_merge( self::$defaults, $settings );
	}

	/**
	 * Adresse de notification de l'administrateur.
	 *
	 * @return string
	 */
	public static function admin_email() {
		$email = self::get_setting( 'admin_email' );
		if ( ! is_email( $email ) ) {
			$email = get_option( 'admin_email' );
		}
		return $email;
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate() {
		CSWA_Store::install();
		CSWA_Vendors::install_roles();

		if ( ! wp_next_scheduled( 'cswa_retry_sheet' ) ) {
			wp_schedule_event( time() + 300, 'cswa_five_minutes', 'cswa_retry_sheet' );
		}
	}

	/**
	 * Desactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'cswa_retry_sheet' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'cswa_retry_sheet' );
		}
	}
}

register_activation_hook( __FILE__, array( 'CSWA_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CSWA_Plugin', 'deactivate' ) );

CSWA_Plugin::instance();

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CSWA_FILE, true );
	}
} );
