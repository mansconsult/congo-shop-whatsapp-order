<?php
/**
 * Stockage durable des demandes, idempotence et verrous.
 *
 * @package CSWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Couche de persistance.
 */
class CSWA_Store {
	private static $locks = array();

	/**
	 * Nom de la table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'cswa_requests';
	}

	/**
	 * Cree ou met a jour la table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ref VARCHAR(32) NOT NULL,
			idem_key VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			payload LONGTEXT NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sheet_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_try DATETIME NULL,
			last_error TEXT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY idem_key (idem_key),
			UNIQUE KEY ref (ref),
			KEY sheet_status (sheet_status)
		) {$collate};";

		dbDelta( $sql );

		update_option( 'cswa_db_version', CSWA_DB_VERSION, false );
	}

	/**
	 * Met a jour le schema si necessaire.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'cswa_db_version', 0 ) !== CSWA_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Lecture d'une option sans passer par le cache objet.
	 *
	 * Indispensable pour les verrous : un cache persistant partage entre
	 * processus PHP peut renvoyer une valeur perimee pendant une course.
	 *
	 * @param string $name Nom de l'option.
	 * @return string|null
	 */
	public static function raw_option( $name ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name )
		);

		return ( null === $value ) ? null : (string) $value;
	}

	/**
	 * Pose un verrou atomique.
	 *
	 * Repose sur l'index unique de wp_options.option_name : deux processus
	 * simultanes ne peuvent pas inserer la meme ligne, un seul obtient true.
	 *
	 * @param string $name Nom du verrou.
	 * @param int    $ttl  Duree de vie en secondes.
	 * @return bool
	 */
	public static function acquire_lock( $name, $ttl = 60 ) {
		global $wpdb;

		$now = time();

		// Purge d'un verrou expire (processus mort en cours de route).
		$existing = self::raw_option( $name );
		if ( null !== $existing && (int) $existing < ( $now - $ttl ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $existing ) );
			wp_cache_delete( $name, 'options' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$token = $now . ':' . wp_generate_uuid4();
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				$token
			)
		);

		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		if ( 1 === (int) $inserted ) { self::$locks[ $name ] = $token; return true; }
		return false;
	}

	/**
	 * Libere un verrou.
	 *
	 * @param string $name Nom du verrou.
	 * @return void
	 */
	public static function release_lock( $name ) {
		global $wpdb;

		if ( ! isset( self::$locks[ $name ] ) ) { return; }
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, self::$locks[ $name ] ) );
		unset( self::$locks[ $name ] );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Genere une reference lisible.
	 *
	 * @return string
	 */
	public static function generate_ref() {
		$date = wp_date( 'Ymd', null, new DateTimeZone( 'Africa/Kinshasa' ) );
		$rand = strtoupper( substr( str_replace( array( '0', 'O', 'I', '1', 'l' ), 'X', wp_generate_password( 20, false, false ) ), 0, 12 ) );
		$site = strtoupper( substr( hash( 'sha256', home_url( '/' ) ), 0, 4 ) );
		return 'CS-' . $date . '-' . $site . $rand;
	}

	/**
	 * Enregistre une demande de maniere idempotente.
	 *
	 * @param string $idem_key Cle d'idempotence fournie par le client.
	 * @param array  $payload  Donnees completes de la demande.
	 * @param string $ip       Adresse IP.
	 * @return array{row:array,created:bool}|WP_Error
	 */
	public static function insert_request( $idem_key, $payload, $ip, $ref = '' ) {
		global $wpdb;

		$existing = self::get_by_idem( $idem_key );
		if ( $existing ) {
			return array(
				'row'     => $existing,
				'created' => false,
			);
		}

		// Le navigateur a pu reserver une reference a l'ouverture du
		// formulaire, pour la faire figurer dans le message WhatsApp. On la
		// reprend telle quelle si elle a le bon format.
		$ref = preg_match( '/^CS-\d{8}-(?:[A-Z0-9]{6}|[A-Z0-9]{16})$/', (string) $ref ) ? $ref : self::generate_ref();

		$attempt = 0;

		do {
			$inserted = $wpdb->insert(
				self::table(),
				array(
					'ref'          => $ref,
					'idem_key'     => $idem_key,
					'created_at'   => gmdate( 'Y-m-d H:i:s' ),
					'payload'      => wp_json_encode( $payload ),
					'sheet_status' => 'pending',
					'attempts'     => 0,
					'next_try'     => gmdate( 'Y-m-d H:i:s' ),
					'ip'           => $ip,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);

			if ( false !== $inserted ) {
				break;
			}

			// Collision possible sur ref ou sur idem_key.
			$existing = self::get_by_idem( $idem_key );
			if ( $existing ) {
				return array(
					'row'     => $existing,
					'created' => false,
				);
			}

			$ref = self::generate_ref();
			$attempt++;
		} while ( $attempt < 3 );

		if ( false === $inserted ) {
			return new WP_Error( 'cswa_db', "La demande n'a pas pu etre enregistree." );
		}

		$row = self::get_by_id( (int) $wpdb->insert_id );

		return array(
			'row'     => $row,
			'created' => true,
		);
	}

	/**
	 * Recupere une demande par cle d'idempotence.
	 *
	 * @param string $idem_key Cle.
	 * @return array|null
	 */
	public static function get_by_idem( $idem_key ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE idem_key = %s LIMIT 1", $idem_key ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Recupere une demande par identifiant.
	 *
	 * @param int $id Identifiant.
	 * @return array|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/** The table remains a delivery journal, WooCommerce owns the order. */
	public static function link_order( $id, $order_id ) {
		global $wpdb;
		return false !== $wpdb->update( self::table(), array( 'order_id' => (int) $order_id ), array( 'id' => (int) $id ) );
	}

	/**
	 * Met a jour le statut de synchronisation.
	 *
	 * @param int    $id       Identifiant.
	 * @param string $status   Statut : pending, ok, failed.
	 * @param string $error    Message d'erreur eventuel.
	 * @param int    $delay    Delai avant nouvelle tentative, en secondes.
	 * @return void
	 */
	public static function set_sheet_status( $id, $status, $error = '', $delay = 300 ) {
		global $wpdb;

		$row      = self::get_by_id( $id );
		$attempts = $row ? (int) $row['attempts'] : 0;

		$data = array(
			'sheet_status' => $status,
			'last_error'   => $error ? substr( $error, 0, 900 ) : null,
		);

		if ( 'ok' === $status ) {
			$data['next_try'] = null;
		} else {
			$data['attempts'] = $attempts + 1;
			$data['next_try'] = gmdate( 'Y-m-d H:i:s', time() + $delay );
		}

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Demandes en attente de synchronisation.
	 *
	 * @param int $limit Nombre maximum.
	 * @return array[]
	 */
	public static function pending( $limit = 20 ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE sheet_status IN ('pending','failed')
				 AND ( next_try IS NULL OR next_try <= %s )
				 ORDER BY id ASC LIMIT %d",
				gmdate( 'Y-m-d H:i:s' ),
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Compte les demandes par statut.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT sheet_status, COUNT(*) AS total FROM {$table} GROUP BY sheet_status", ARRAY_A );

		$out = array(
			'ok'      => 0,
			'pending' => 0,
			'failed'  => 0,
		);

		foreach ( (array) $rows as $row ) {
			$out[ $row['sheet_status'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Dernieres demandes.
	 *
	 * @param int $limit Nombre.
	 * @return array[]
	 */
	public static function latest( $limit = 20 ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
	}

	/**
	 * Adresse IP du visiteur, tronquee et validee.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$parts = explode( ',', $value );
			$ip    = trim( $parts[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '';
	}

	/**
	 * Limitation de debit par IP.
	 *
	 * @param int $max Nombre de soumissions autorisees par fenetre de 10 minutes.
	 * @return bool True si la requete est autorisee.
	 */
	public static function rate_limit_ok( $max = 6 ) {
		$ip = self::client_ip();
		if ( '' === $ip ) {
			return true;
		}

		$key   = 'cswa_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}
}
