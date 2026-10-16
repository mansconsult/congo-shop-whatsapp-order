<?php
/** Public GitHub Releases only; no credentials and no database migration. */
defined( 'ABSPATH' ) || exit;

final class CSWA_Updates {
	const SLUG = 'congo-shop-whatsapp-order';
	const REPO = 'https://github.com/mansconsult/congo-shop-whatsapp-order';
	const API = 'https://api.github.com/repos/mansconsult/congo-shop-whatsapp-order/releases/latest';
	const CACHE = 'cswa_github_release_v1';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( CSWA_FILE ), array( __CLASS__, 'links' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( CSWA_FILE ), array( __CLASS__, 'links' ) );
		add_action( 'admin_post_cswa_check_updates', array( __CLASS__, 'check_now' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_after_upgrade' ), 10, 2 );
	}

	/** Bounded requests; errors never disable the business features. */
	private static function json( $url ) {
		$response = wp_remote_get( $url, array(
			'timeout' => 10, 'redirection' => 3, 'limit_response_size' => 262144,
			'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'CSWA-Updates/' . CSWA_VERSION ),
		) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : false;
	}

	/** Require an explicit stable release, an exact ZIP, and matching manifest. */
	public static function release() {
		$cached = get_site_transient( self::CACHE );
		if ( false !== $cached ) { return ! empty( $cached['version'] ) ? $cached : false; }
		// Negative cache also limits API retries on shared hosting / rate limits.
		set_site_transient( self::CACHE, array(), 15 * MINUTE_IN_SECONDS );
		$r = self::json( self::API );
		if ( ! $r || ! empty( $r['draft'] ) || ! empty( $r['prerelease'] ) || empty( $r['tag_name'] ) || ! is_string( $r['tag_name'] ) ) { return false; }
		if ( ! preg_match( '/^v?(\d+\.\d+\.\d+)$/D', $r['tag_name'], $match ) ) { return false; }
		$version = $match[1];
		$zip_name = self::SLUG . '-' . $version . '.zip';
		$prefix = self::REPO . '/releases/download/' . rawurlencode( $r['tag_name'] ) . '/';
		$zip = ''; $manifest = '';
		foreach ( isset( $r['assets'] ) && is_array( $r['assets'] ) ? $r['assets'] : array() as $asset ) {
			if ( ! is_array( $asset ) || ( $asset['state'] ?? '' ) !== 'uploaded' || empty( $asset['size'] ) ) { continue; }
			$url = $asset['browser_download_url'] ?? '';
			if ( ( $asset['name'] ?? '' ) === $zip_name && $url === $prefix . $zip_name ) { $zip = $url; }
			if ( ( $asset['name'] ?? '' ) === 'update.json' && $url === $prefix . 'update.json' ) { $manifest = $url; }
		}
		if ( ! $zip || ! $manifest ) { return false; }
		$m = self::json( $manifest );
		if ( ! $m || ( $m['version'] ?? '' ) !== $version || ( $m['slug'] ?? '' ) !== self::SLUG ) { return false; }
		foreach ( array( 'requires', 'requires_php' ) as $field ) {
			if ( empty( $m[ $field ] ) || ! is_string( $m[ $field ] ) || ! preg_match( '/^\d+\.\d+(?:\.\d+)?$/D', $m[ $field ] ) ) { return false; }
		}
		$data = array(
			'id' => self::REPO, 'slug' => self::SLUG, 'version' => $version,
			'url' => self::REPO . '/releases/tag/' . rawurlencode( $r['tag_name'] ),
			'package' => $zip, 'requires' => $m['requires'], 'requires_php' => $m['requires_php'],
			'notes' => is_string( $r['body'] ?? null ) ? $r['body'] : '',
		);
		set_site_transient( self::CACHE, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	public static function update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( plugin_basename( CSWA_FILE ) !== $plugin_file ) { return $update; }
		$r = self::release();
		if ( ! $r ) { return $update; }
		unset( $r['notes'] );
		return $r; // Core compares versions; also supplies no_update for auto-update UI.
	}

	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::SLUG ) { return $result; }
		$r = self::release();
		if ( ! $r ) { return $result; }
		return (object) array(
			'name' => 'Congo-Shop - Commande WhatsApp', 'slug' => self::SLUG,
			'version' => $r['version'], 'author' => 'Mans Consulting', 'homepage' => self::REPO,
			'requires' => $r['requires'], 'requires_php' => $r['requires_php'],
			'download_link' => $r['package'],
			'sections' => array( 'description' => 'Commandes WhatsApp, WooCommerce, Google Sheets et espace marchand.', 'changelog' => '<pre>' . esc_html( $r['notes'] ) . '</pre>' ),
		);
	}

	public static function links( $links ) {
		if ( current_user_can( 'update_plugins' ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=cswa_check_updates' ), 'cswa_check_updates' );
			$links[] = '<a href="' . esc_url( $url ) . '">Vérifier les mises à jour</a>';
		}
		return $links;
	}

	public static function check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'cswa_check_updates' );
		delete_site_transient( self::CACHE );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( network_admin_url( 'plugins.php' ) );
		exit;
	}

	public static function clear_after_upgrade( $upgrader, $options ) {
		if ( 'plugin' === ( $options['type'] ?? '' ) && 'update' === ( $options['action'] ?? '' ) ) {
			delete_site_transient( self::CACHE );
		}
	}
}
