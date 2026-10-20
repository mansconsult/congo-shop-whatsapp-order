<?php
/**
 * Connexion Google (OAuth 2.0), classeur partage et feuilles mensuelles.
 *
 * Portee utilisee : https://www.googleapis.com/auth/drive.file
 * Cette portee est classee non sensible par Google : elle ne donne acces
 * qu'aux fichiers crees par l'application, ce qui suffit ici et evite la
 * procedure de verification OAuth.
 *
 * @package CSWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Client Google minimal, sans dependance externe.
 */
class CSWA_Google {

	const SCOPE       = 'https://www.googleapis.com/auth/drive.file';
	const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
	const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
	const SHEETS_API  = 'https://sheets.googleapis.com/v4/spreadsheets';
	const DRIVE_API   = 'https://www.googleapis.com/drive/v3/files';
	const SHEET_TITLE = 'Congo-Shop - Demandes WhatsApp';
	const LOCK_NAME   = 'cswa_sheet_creation_lock';
	const TAB_LOCK    = 'cswa_tab_creation_lock';
	// Hauteur de chaque feuille mensuelle, et borne des menus deroulants.
	const GRID_ROWS   = 5000;

	/* ---------------------------------------------------------------------
	 * Structure du classeur
	 * ------------------------------------------------------------------ */

	/**
	 * Colonnes du classeur, dans l'ordre d'affichage.
	 *
	 * Cle interne => libelle affiche. Une seule definition sert a la fois
	 * pour la ligne d'en-tete et pour la construction des lignes, ce qui
	 * rend impossible un decalage entre les deux.
	 *
	 * @return array
	 */
	public static function columns() {
		return array(
			'status'        => 'Statut',
			'site'          => 'Site',
			'datetime'      => 'Date et heure (Africa/Kinshasa)',
			'product'       => 'Produit',
			'variant'       => 'Variante',
			'vendor'        => 'Marchand',
			'unit_price'    => 'Prix unitaire',
			'qty'           => 'Quantité',
			'currency'      => 'Devise',
			'total'         => 'Total',
			'customer'      => 'Nom client',
			'phone'         => 'Téléphone',
			'email'         => 'E-mail',
			'link'          => 'Lien du produit',
			'mode'          => 'Mode',
			'commune'       => 'Commune',
			'address'       => 'Adresse exacte',
			'reference'     => 'Référence',
			'city'          => 'Ville',
			'country'       => 'Pays',
			'ref'           => 'ID demande',
			'vendor_number' => 'Numéro marchand',
			'product_id'    => 'ID produit',
			'service'       => 'Service concerné',
			'need_description' => 'Description du besoin',
		);
	}

	/**
	 * Libelles de la ligne d'en-tete.
	 *
	 * @return string[]
	 */
	public static function headers() {
		return array_values( self::columns() );
	}

	/**
	 * Index de colonne, a partir de zero.
	 *
	 * @param string $key Cle de colonne.
	 * @return int
	 */
	public static function column_index( $key ) {
		$keys = array_keys( self::columns() );
		$pos  = array_search( $key, $keys, true );
		return ( false === $pos ) ? 0 : (int) $pos;
	}

	/**
	 * Statuts proposes dans le menu deroulant.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array(
			'En attente',
			'Enregistrée',
			'Contact établi',
			'Confirmée',
			'Vendue',
			'Livrée',
			'Annulée',
		);
	}

	/**
	 * Statut pose par le plugin a l'enregistrement.
	 *
	 * @return string
	 */
	public static function initial_status() {
		return 'En attente';
	}

	/**
	 * Sites proposes dans le menu deroulant.
	 *
	 * @return string[]
	 */
	public static function sites() {
		$raw = (string) CSWA_Plugin::get_setting( 'site_list', '' );

		$list = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$list[] = $line;
			}
		}

		if ( ! $list ) {
			$list = array( 'Congo Shop', 'Congo Shop Pay', 'Mobile RDC', 'iPhone Kin', 'Kinbrique' );
		}

		// Le nom de ce site doit toujours figurer dans le menu, sinon la
		// cellule ecrite serait signalee en erreur par Google Sheets.
		$current = (string) CSWA_Plugin::get_setting( 'site_name', '' );
		if ( '' !== $current && ! in_array( $current, $list, true ) ) {
			$list[] = $current;
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Nom de ce site, tel qu'il apparaitra dans la colonne Site.
	 *
	 * @return string
	 */
	public static function site_name() {
		$name = trim( (string) CSWA_Plugin::get_setting( 'site_name', '' ) );

		if ( '' !== $name ) {
			return $name;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return $host ? $host : get_bloginfo( 'name' );
	}

	/**
	 * Echappe un nom de feuille pour la notation A1.
	 *
	 * Un nom comme 2026-10 peut etre lu comme un calcul par Google Sheets :
	 * les apostrophes levent toute ambiguite.
	 *
	 * @param string $tab Nom de la feuille.
	 * @return string
	 */
	public static function quote_tab( $tab ) {
		return "'" . str_replace( "'", "''", (string) $tab ) . "'";
	}

	/**
	 * Nom de la feuille du mois courant, au format 2026-09.
	 *
	 * @param string $when Date ISO facultative.
	 * @return string
	 */
	public static function tab_name( $when = '' ) {
		$tz = new DateTimeZone( 'Africa/Kinshasa' );

		try {
			$date = ( '' !== $when ) ? new DateTime( $when, new DateTimeZone( 'UTC' ) ) : new DateTime( 'now', $tz );
		} catch ( Exception $e ) {
			$date = new DateTime( 'now', $tz );
		}

		$date->setTimezone( $tz );

		return $date->format( 'Y-m' );
	}

	/* ---------------------------------------------------------------------
	 * Secrets
	 * ------------------------------------------------------------------ */

	/**
	 * Cle de chiffrement derivee des sels WordPress.
	 *
	 * @return string
	 */
	private static function crypt_key() {
		$seed = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		if ( '' === $seed ) {
			$seed = get_option( 'cswa_fallback_seed' );
			if ( ! $seed ) {
				$seed = wp_generate_password( 64, true, true );
				update_option( 'cswa_fallback_seed', $seed, false );
			}
		}
		return hash( 'sha256', 'cswa|' . $seed, true );
	}

	/**
	 * Chiffre une valeur avant stockage.
	 *
	 * @param string $value Valeur en clair.
	 * @return string
	 */
	public static function encrypt( $value ) {
		if ( '' === $value || ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $value, 'aes-256-cbc', self::crypt_key(), OPENSSL_RAW_DATA, $iv );
		return 'v1:' . base64_encode( $iv . $cipher );
	}

	/**
	 * Dechiffre une valeur stockee.
	 *
	 * @param string $value Valeur chiffree.
	 * @return string
	 */
	public static function decrypt( $value ) {
		if ( '' === $value || 0 !== strpos( (string) $value, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return (string) $value;
		}
		$raw = base64_decode( substr( $value, 3 ), true );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$iv    = substr( $raw, 0, 16 );
		$data  = substr( $raw, 16 );
		$plain = openssl_decrypt( $data, 'aes-256-cbc', self::crypt_key(), OPENSSL_RAW_DATA, $iv );
		return ( false === $plain ) ? '' : $plain;
	}

	/**
	 * Lit la configuration Google.
	 *
	 * @return array
	 */
	public static function config() {
		$conf = get_option( 'cswa_google', array() );
		if ( ! is_array( $conf ) ) {
			$conf = array();
		}
		return wp_parse_args(
			$conf,
			array(
				'client_id'     => '',
				'client_secret' => '',
				'refresh_token' => '',
				'account'       => '',
			)
		);
	}

	/**
	 * Enregistre la configuration Google.
	 *
	 * @param array $conf Configuration.
	 * @return void
	 */
	public static function save_config( $conf ) {
		update_option( 'cswa_google', $conf, false );
	}

	/**
	 * Indique si la connexion Google est active.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$conf = self::config();
		return ( '' !== $conf['client_id'] && '' !== self::decrypt( $conf['refresh_token'] ) );
	}

	/**
	 * URL de redirection OAuth a declarer dans la console Google.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=cswa_oauth_callback' );
	}

	/**
	 * Construit l'URL de consentement.
	 *
	 * @return string
	 */
	public static function auth_url() {
		$conf = self::config();

		$state = wp_generate_password( 24, false, false );
		set_transient( 'cswa_oauth_state', $state, 15 * MINUTE_IN_SECONDS );

		return add_query_arg(
			array(
				'client_id'              => rawurlencode( $conf['client_id'] ),
				'redirect_uri'           => rawurlencode( self::redirect_uri() ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( self::SCOPE ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			),
			self::AUTH_URL
		);
	}

	/**
	 * Echange le code d'autorisation contre un jeton de rafraichissement.
	 *
	 * @param string $code Code recu de Google.
	 * @return true|WP_Error
	 */
	public static function exchange_code( $code ) {
		$conf = self::config();

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $conf['client_id'],
					'client_secret' => self::decrypt( $conf['client_secret'] ),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['refresh_token'] ) ) {
			$detail = isset( $body['error_description'] ) ? $body['error_description'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'cswa_oauth', "Google n'a pas renvoyé de refresh token. " . $detail );
		}

		$conf['refresh_token'] = self::encrypt( $body['refresh_token'] );

		if ( ! empty( $body['access_token'] ) ) {
			set_transient( 'cswa_access_token', $body['access_token'], max( 60, (int) $body['expires_in'] - 120 ) );
			$conf['account'] = self::fetch_account_email( $body['access_token'] );
		}

		self::save_config( $conf );
		delete_option( 'cswa_sheet_error' );

		return true;
	}

	/**
	 * Recupere l'adresse du compte connecte, a titre informatif.
	 *
	 * @param string $access_token Jeton d'acces.
	 * @return string
	 */
	private static function fetch_account_email( $access_token ) {
		$response = wp_remote_get(
			'https://www.googleapis.com/drive/v3/about?fields=user(emailAddress)',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return isset( $body['user']['emailAddress'] ) ? sanitize_email( $body['user']['emailAddress'] ) : '';
	}

	/**
	 * Retourne un jeton d'acces valide.
	 *
	 * @return string|WP_Error
	 */
	public static function access_token() {
		$cached = get_transient( 'cswa_access_token' );
		if ( $cached ) {
			return $cached;
		}

		$conf    = self::config();
		$refresh = self::decrypt( $conf['refresh_token'] );

		if ( '' === $conf['client_id'] || '' === $refresh ) {
			return new WP_Error( 'cswa_not_connected', "La connexion Google n'est pas configurée." );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $conf['client_id'],
					'client_secret' => self::decrypt( $conf['client_secret'] ),
					'refresh_token' => $refresh,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$detail = isset( $body['error_description'] ) ? $body['error_description'] : 'réponse inattendue';

			if ( isset( $body['error'] ) && 'invalid_grant' === $body['error'] ) {
				self::flag_problem( 'Le jeton Google a été révoqué ou a expiré. Reconnecte le compte Google depuis les réglages du plugin.' );
			}

			return new WP_Error( 'cswa_token', 'Jeton Google indisponible : ' . $detail );
		}

		set_transient( 'cswa_access_token', $body['access_token'], max( 60, (int) $body['expires_in'] - 120 ) );

		return $body['access_token'];
	}

	/* ---------------------------------------------------------------------
	 * Classeur
	 * ------------------------------------------------------------------ */

	/**
	 * Identifiant du classeur, lu sans cache objet.
	 *
	 * Un identifiant saisi dans les reglages a la priorite : c'est ce qui
	 * permet a plusieurs sites d'ecrire dans un seul et meme fichier.
	 *
	 * @return string
	 */
	public static function sheet_id() {
		$shared = trim( (string) CSWA_Plugin::get_setting( 'shared_sheet_id', '' ) );

		if ( '' !== $shared ) {
			return self::clean_sheet_id( $shared );
		}

		$id = CSWA_Store::raw_option( 'cswa_sheet_id' );

		return $id ? $id : '';
	}

	/**
	 * Accepte aussi bien un identifiant qu'une URL complete de classeur.
	 *
	 * @param string $value Valeur saisie.
	 * @return string
	 */
	public static function clean_sheet_id( $value ) {
		$value = trim( (string) $value );

		if ( preg_match( '#/spreadsheets/d/([a-zA-Z0-9_-]{20,})#', $value, $m ) ) {
			return $m[1];
		}

		return preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );
	}

	/**
	 * Garantit l'existence du classeur et retourne son identifiant.
	 *
	 * @return string|WP_Error
	 */
	public static function ensure_sheet() {
		$existing = self::sheet_id();
		if ( '' !== $existing ) {
			return $existing;
		}

		$blocking_error = get_option( 'cswa_sheet_error' );
		if ( $blocking_error ) {
			return new WP_Error( 'cswa_sheet_blocked', 'Classeur indisponible : ' . $blocking_error );
		}

		if ( ! CSWA_Store::acquire_lock( self::LOCK_NAME, 120 ) ) {
			// Un autre processus cree le fichier : on attend sa publication.
			for ( $i = 0; $i < 20; $i++ ) {
				usleep( 500000 );
				$id = self::sheet_id();
				if ( '' !== $id ) {
					return $id;
				}
			}
			return new WP_Error( 'cswa_sheet_busy', 'Création du classeur en cours, la demande sera synchronisée plus tard.' );
		}

		try {
			$id = self::sheet_id();
			if ( '' !== $id ) {
				return $id;
			}

			$created = self::create_sheet();

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			update_option( 'cswa_sheet_id', $created, false );
			update_option( 'cswa_sheet_created_at', gmdate( 'c' ), false );
			delete_option( 'cswa_known_tabs' );

			return $created;
		} finally {
			CSWA_Store::release_lock( self::LOCK_NAME );
		}
	}

	/**
	 * Cree le classeur avec la feuille du mois courant.
	 *
	 * @return string|WP_Error Identifiant du classeur.
	 */
	private static function create_sheet() {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$payload = array(
			'properties' => array(
				'title'    => self::SHEET_TITLE,
				'locale'   => 'fr_FR',
				'timeZone' => 'Africa/Kinshasa',
			),
			'sheets'     => array(
				array(
					'properties' => array(
						'title'          => self::tab_name(),
						'gridProperties' => array(
							'rowCount'    => self::GRID_ROWS,
							'columnCount' => count( self::headers() ),
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			self::SHEETS_API,
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['spreadsheetId'] ) ) {
			$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : 'code HTTP ' . $code;
			return new WP_Error( 'cswa_create', 'Création du classeur impossible : ' . $detail );
		}

		$sheet_id = $body['spreadsheetId'];

		// Mise en forme de la feuille du mois qui vient d'etre creee.
		$tab_id = isset( $body['sheets'][0]['properties']['sheetId'] ) ? (int) $body['sheets'][0]['properties']['sheetId'] : 0;
		self::setup_tab( $sheet_id, self::tab_name(), $tab_id, $token );

		// Deplacement optionnel dans un dossier Drive choisi par l'administrateur.
		$folder = self::clean_folder_id( CSWA_Plugin::get_setting( 'drive_folder_id', '' ) );
		if ( $folder ) {
			wp_remote_request(
				add_query_arg(
					array(
						'addParents'        => rawurlencode( $folder ),
						'removeParents'     => 'root',
						'supportsAllDrives' => 'true',
						'fields'            => 'id,parents',
					),
					self::DRIVE_API . '/' . rawurlencode( $sheet_id )
				),
				array(
					'method'  => 'PATCH',
					'timeout' => 20,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => '{}',
				)
			);
			// Un echec ici n'est pas bloquant : le fichier reste dans Mon Drive.
		}

		return $sheet_id;
	}

	/**
	 * Accepte un identifiant de dossier Drive ou son URL.
	 *
	 * @param string $value Valeur saisie.
	 * @return string
	 */
	public static function clean_folder_id( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '#/folders/([a-zA-Z0-9_-]{15,})#', $value, $m ) ) {
			return $m[1];
		}

		// Un nom de dossier saisi a la place d'un identifiant ne donnerait
		// qu'un echec silencieux : on prefere l'ignorer.
		if ( ! preg_match( '/^[a-zA-Z0-9_-]{15,}$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/* ---------------------------------------------------------------------
	 * Feuilles mensuelles
	 * ------------------------------------------------------------------ */

	/**
	 * Liste des feuilles du classeur.
	 *
	 * @param string $sheet_id Classeur.
	 * @param string $token    Jeton d'acces.
	 * @return array|WP_Error Tableau titre => identifiant numerique.
	 */
	private static function fetch_tabs( $sheet_id, $token ) {
		$url = add_query_arg(
			array( 'fields' => 'sheets(properties(sheetId,title))' ),
			self::SHEETS_API . '/' . rawurlencode( $sheet_id )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : 'code HTTP ' . $code;

			if ( in_array( $code, array( 403, 404 ), true ) ) {
				self::flag_problem(
					sprintf(
						'Le classeur Google (%1$s) est inaccessible : %2$s. Aucune nouvelle feuille ne sera créée automatiquement. Les demandes restent en attente sur le site.',
						$sheet_id,
						$detail
					)
				);
			}

			return new WP_Error( 'cswa_tabs', 'Lecture du classeur impossible : ' . $detail );
		}

		$tabs = array();
		foreach ( (array) $body['sheets'] as $sheet ) {
			if ( isset( $sheet['properties']['title'] ) ) {
				$tabs[ $sheet['properties']['title'] ] = (int) $sheet['properties']['sheetId'];
			}
		}

		return $tabs;
	}

	/**
	 * Garantit l'existence de la feuille du mois et retourne son nom.
	 *
	 * @param string $sheet_id Classeur.
	 * @param string $tab      Nom de la feuille, format 2026-09.
	 * @param string $token    Jeton d'acces.
	 * @return string|WP_Error
	 */
	private static function ensure_tab( $sheet_id, $tab, $token ) {
		$known = get_option( 'cswa_known_tabs', array() );
		if ( is_array( $known ) && in_array( $tab, $known, true ) ) {
			return $tab;
		}

		$tabs = self::fetch_tabs( $sheet_id, $token );
		if ( is_wp_error( $tabs ) ) {
			return $tabs;
		}

		if ( isset( $tabs[ $tab ] ) ) {
			self::remember_tab( $tab );
			return $tab;
		}

		// Deux sites peuvent atteindre le premier jour du mois en meme temps.
		if ( ! CSWA_Store::acquire_lock( self::TAB_LOCK, 90 ) ) {
			for ( $i = 0; $i < 20; $i++ ) {
				usleep( 500000 );
				$tabs = self::fetch_tabs( $sheet_id, $token );
				if ( ! is_wp_error( $tabs ) && isset( $tabs[ $tab ] ) ) {
					self::remember_tab( $tab );
					return $tab;
				}
			}
			return new WP_Error( 'cswa_tab_busy', 'Création de la feuille du mois en cours.' );
		}

		try {
			// Nouvelle verification apres obtention du verrou.
			$tabs = self::fetch_tabs( $sheet_id, $token );
			if ( is_wp_error( $tabs ) ) {
				return $tabs;
			}
			if ( isset( $tabs[ $tab ] ) ) {
				self::remember_tab( $tab );
				return $tab;
			}

			$created = self::add_tab( $sheet_id, $tab, $token );
			if ( is_wp_error( $created ) ) {
				return $created;
			}

			self::setup_tab( $sheet_id, $tab, $created, $token );
			self::remember_tab( $tab );

			return $tab;
		} finally {
			CSWA_Store::release_lock( self::TAB_LOCK );
		}
	}

	/**
	 * Memorise qu'une feuille existe, pour eviter un appel par demande.
	 *
	 * @param string $tab Nom de la feuille.
	 * @return void
	 */
	private static function remember_tab( $tab ) {
		$known = get_option( 'cswa_known_tabs', array() );
		if ( ! is_array( $known ) ) {
			$known = array();
		}
		if ( ! in_array( $tab, $known, true ) ) {
			$known[] = $tab;
			update_option( 'cswa_known_tabs', $known, false );
		}
	}

	/**
	 * Cree une feuille dans le classeur.
	 *
	 * @param string $sheet_id Classeur.
	 * @param string $tab      Nom de la feuille.
	 * @param string $token    Jeton d'acces.
	 * @return int|WP_Error Identifiant numerique de la feuille.
	 */
	private static function add_tab( $sheet_id, $tab, $token ) {
		$payload = array(
			'requests' => array(
				array(
					'addSheet' => array(
						'properties' => array(
							'title'          => $tab,
							'index'          => 0,
							'gridProperties' => array(
								'rowCount'    => self::GRID_ROWS,
								'columnCount' => count( self::headers() ),
							),
						),
					),
				),
			),
		);

		$body = self::batch_update( $sheet_id, $payload, $token );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( isset( $body['replies'][0]['addSheet']['properties']['sheetId'] ) ) {
			return (int) $body['replies'][0]['addSheet']['properties']['sheetId'];
		}

		return new WP_Error( 'cswa_add_tab', 'La feuille du mois n\'a pas pu être créée.' );
	}

	/**
	 * Pose l'en-tete, le gel, les filtres et les menus deroulants.
	 *
	 * @param string $sheet_id Classeur.
	 * @param string $tab      Nom de la feuille.
	 * @param int    $tab_id   Identifiant numerique de la feuille.
	 * @param string $token    Jeton d'acces.
	 * @return void
	 */
	private static function setup_tab( $sheet_id, $tab, $tab_id, $token ) {
		$headers = self::headers();
		$count   = count( $headers );

		// 1. La ligne d'en-tete, ecrite en clair.
		self::write_values( $sheet_id, self::quote_tab( $tab ) . '!A1', array( $headers ), $token );

		// 2. Mise en forme, filtres et validations.
		$requests = array(
			array(
				'updateSheetProperties' => array(
					'properties' => array(
						'sheetId'        => $tab_id,
						'gridProperties' => array( 'frozenRowCount' => 1 ),
					),
					'fields'     => 'gridProperties.frozenRowCount',
				),
			),
			array(
				'repeatCell' => array(
					'range'  => array(
						'sheetId'       => $tab_id,
						'startRowIndex' => 0,
						'endRowIndex'   => 1,
					),
					'cell'   => array(
						'userEnteredFormat' => array(
							'textFormat'      => array( 'bold' => true ),
							'backgroundColor' => array(
								'red'   => 0.94,
								'green' => 0.95,
								'blue'  => 0.97,
							),
						),
					),
					'fields' => 'userEnteredFormat(textFormat,backgroundColor)',
				),
			),
			// Filtre sur toute la plage : tri et filtrage par marchand,
			// par commune ou par statut a l'interieur du mois.
			array(
				'setBasicFilter' => array(
					'filter' => array(
						'range' => array(
							'sheetId'          => $tab_id,
							'startRowIndex'    => 0,
							'startColumnIndex' => 0,
							'endColumnIndex'   => $count,
						),
					),
				),
			),
			self::validation_request( $tab_id, self::column_index( 'status' ), self::statuses() ),
			self::validation_request( $tab_id, self::column_index( 'site' ), self::sites() ),
		);

		self::batch_update( $sheet_id, array( 'requests' => $requests ), $token );
	}

	/**
	 * Construit une regle de menu deroulant sur une colonne.
	 *
	 * @param int      $tab_id Feuille.
	 * @param int      $column Index de colonne.
	 * @param string[] $values Valeurs proposees.
	 * @return array
	 */
	private static function validation_request( $tab_id, $column, $values ) {
		$list = array();
		foreach ( $values as $value ) {
			$list[] = array( 'userEnteredValue' => $value );
		}

		return array(
			'setDataValidation' => array(
				'range' => array(
					'sheetId'          => $tab_id,
					'startRowIndex'    => 1,
					// Toutes les lignes existantes ; regle reappliquee apres chaque ajout.
					'startColumnIndex' => $column,
					'endColumnIndex'   => $column + 1,
				),
				'rule'  => array(
					'condition'    => array(
						'type'   => 'ONE_OF_LIST',
						'values' => $list,
					),
					'showCustomUi' => true,
					// Une valeur hors liste est signalee mais acceptee : on ne
					// bloque jamais une ecriture a cause d'un menu.
					'strict'       => false,
				),
			),
		);
	}

	/**
	 * Applique de nouveau les menus deroulants a toutes les feuilles connues.
	 *
	 * Utile apres modification de la liste des sites ou des statuts.
	 *
	 * @return true|WP_Error
	 */
	/** Read the actual headers; never assume historical column positions. */
	private static function read_headers( $sheet_id, $tab, $token ) {
		$response = wp_remote_get(
			self::SHEETS_API . '/' . rawurlencode( $sheet_id ) . '/values/' . rawurlencode( self::quote_tab( $tab ) . '!1:1' ),
			array( 'timeout' => 25, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) )
		);
		if ( is_wp_error( $response ) ) { return $response; }
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'cswa_headers', 'Impossible de lire les colonnes du classeur.' );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$headers = isset( $body['values'][0] ) ? $body['values'][0] : array();
		return array_map( 'trim', $headers );
	}

	/** Add only the two approved fields, preserving all existing data and order. */
	private static function ensure_service_columns( $sheet_id, $tab, $token ) {
		$headers = self::read_headers( $sheet_id, $tab, $token );
		if ( is_wp_error( $headers ) ) { return $headers; }
		$missing = array();
		foreach ( array( 'Service concerné', 'Description du besoin' ) as $header ) {
			$count = count( array_keys( $headers, $header, true ) );
			if ( $count > 1 ) { return new WP_Error( 'cswa_schema', 'Colonne ambiguë : ' . $header ); }
			if ( ! $count ) { $missing[] = $header; }
		}
		if ( ! $missing ) { return $headers; }
		// Validate the existing schema before any mutation.
		$old = array_diff( self::headers(), array( 'Service concerné', 'Description du besoin' ) );
		foreach ( $old as $header ) {
			if ( 1 !== count( array_keys( $headers, $header, true ) ) ) {
				return new WP_Error( 'cswa_schema', 'Colonne absente ou ambiguë : ' . $header );
			}
		}
		$response = wp_remote_get( self::SHEETS_API . '/' . rawurlencode( $sheet_id ) . '?fields=sheets.properties', array( 'timeout' => 25, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
		if ( is_wp_error( $response ) ) { return $response; }
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'cswa_schema', 'Lecture de la feuille impossible.' ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$properties = null;
		foreach ( ( $body['sheets'] ?? array() ) as $item ) { if ( $tab === $item['properties']['title'] ) { $properties = $item['properties']; break; } }
		if ( ! $properties ) { return new WP_Error( 'cswa_schema', 'Feuille introuvable.' ); }
		$start = count( $headers );
		$requests = array();
		$width = (int) ( $properties['gridProperties']['columnCount'] ?? 0 );
		if ( $width < $start + count( $missing ) ) {
			$requests[] = array( 'appendDimension' => array( 'sheetId' => $properties['sheetId'], 'dimension' => 'COLUMNS', 'length' => $start + count( $missing ) - $width ) );
		}
		$cells = array();
		foreach ( $missing as $header ) { $cells[] = array( 'userEnteredValue' => array( 'stringValue' => $header ) ); }
		$requests[] = array( 'updateCells' => array( 'start' => array( 'sheetId' => $properties['sheetId'], 'rowIndex' => 0, 'columnIndex' => $start ), 'rows' => array( array( 'values' => $cells ) ), 'fields' => 'userEnteredValue' ) );
		$result = self::batch_update( $sheet_id, array( 'requests' => $requests ), $token );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::read_headers( $sheet_id, $tab, $token );
	}

	/** Match values to the existing monthly sheet, including September's order. */
	public static function align_row( $row, $headers ) {
		$values = array_combine( self::headers(), $row );
		foreach ( self::headers() as $header ) {
			if ( in_array( $header, array( 'Service concerné', 'Description du besoin' ), true ) && ! in_array( $header, $headers, true ) && '' === (string) $values[$header] ) { continue; }
			if ( 1 !== count( array_keys( $headers, $header, true ) ) ) {
				return new WP_Error( 'cswa_schema', 'Colonne absente ou ambiguë : ' . $header );
			}
		}
		$result = array();
		foreach ( $headers as $header ) {
			if ( isset( $values[ $header ] ) ) { $result[] = $values[ $header ]; }
			elseif ( 'Origine du numéro' === $header || '' === $header ) { $result[] = ''; }
			else { return new WP_Error( 'cswa_schema', 'Colonne inconnue : ' . $header ); }
		}
		return $result;
	}

	/** Explicit repair: remove only the requested column and restore dropdowns. */
	public static function refresh_validations( $remove_origin = false ) {
		$sheet_id = self::sheet_id();
		if ( '' === $sheet_id ) { return new WP_Error( 'cswa_no_sheet', 'Aucun classeur à mettre à jour.' ); }
		$token = self::access_token();
		if ( is_wp_error( $token ) ) { return $token; }
		$tabs = self::fetch_tabs( $sheet_id, $token );
		if ( is_wp_error( $tabs ) ) { return $tabs; }
		$requests = array();
		foreach ( $tabs as $title => $tab_id ) {
			if ( ! preg_match( '/^\d{4}-\d{2}$/', $title ) && 'Demandes' !== $title ) { continue; }
			$headers = self::read_headers( $sheet_id, $title, $token );
			if ( is_wp_error( $headers ) ) { return $headers; }
			// Descending indices keep subsequent deletions correctly positioned.
			for ( $i = count( $headers ) - 1; $i >= 0; $i-- ) {
				if ( $remove_origin && 'Origine du numéro' === $headers[ $i ] ) {
					$requests[] = array( 'deleteDimension' => array( 'range' => array(
						'sheetId' => $tab_id, 'dimension' => 'COLUMNS', 'startIndex' => $i, 'endIndex' => $i + 1
					) ) );
					array_splice( $headers, $i, 1 );
				}
			}
			foreach ( array( 'Statut' => self::statuses(), 'Site' => self::sites() ) as $header => $values ) {
				$column = array_search( $header, $headers, true );
				if ( false !== $column ) { $requests[] = self::validation_request( $tab_id, $column, $values ); }
			}
		}
		if ( ! $requests ) { return true; }
		$result = self::batch_update( $sheet_id, array( 'requests' => $requests ), $token );
		return is_wp_error( $result ) ? $result : true;
	}

	/* ---------------------------------------------------------------------
	 * Appels bas niveau
	 * ------------------------------------------------------------------ */

	/**
	 * Envoie une requete batchUpdate.
	 *
	 * @param string $sheet_id Classeur.
	 * @param array  $payload  Corps de la requete.
	 * @param string $token    Jeton d'acces.
	 * @return array|WP_Error
	 */
	private static function batch_update( $sheet_id, $payload, $token ) {
		$response = wp_remote_post(
			self::SHEETS_API . '/' . rawurlencode( $sheet_id ) . ':batchUpdate',
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : 'code HTTP ' . $code;
			return new WP_Error( 'cswa_batch', 'Mise à jour du classeur impossible : ' . $detail );
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Ecrit des valeurs a une position precise.
	 *
	 * @param string $sheet_id Classeur.
	 * @param string $range    Plage, par exemple 2026-09!A1.
	 * @param array  $values   Lignes de valeurs.
	 * @param string $token    Jeton d'acces.
	 * @return true|WP_Error
	 */
	private static function write_values( $sheet_id, $range, $values, $token ) {
		$url = add_query_arg(
			array( 'valueInputOption' => 'RAW' ),
			self::SHEETS_API . '/' . rawurlencode( $sheet_id ) . '/values/' . rawurlencode( $range )
		);

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'values' => $values ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$body   = json_decode( wp_remote_retrieve_body( $response ), true );
			$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : 'code HTTP ' . $code;
			return new WP_Error( 'cswa_write', 'Écriture impossible : ' . $detail );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Ecriture d'une demande
	 * ------------------------------------------------------------------ */

	/**
	 * Neutralise les valeurs interpretables comme des formules.
	 *
	 * @param mixed $value Valeur.
	 * @return string
	 */
	public static function sanitize_cell( $value ) {
		$value = (string) $value;
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value );

		if ( '' !== $value && in_array( substr( $value, 0, 1 ), array( '=', '+', '-', '@', "\t" ), true ) ) {
			$value = "'" . $value;
		}

		return $value;
	}

	/**
	 * Transforme une demande enregistree en ligne de tableur.
	 *
	 * @param array  $payload Donnees de la demande.
	 * @param string $ref     Reference de la demande.
	 * @return array
	 */
	public static function build_row( $payload, $ref ) {
		$c = isset( $payload['customer'] ) ? $payload['customer'] : array();
		$p = isset( $payload['product'] ) ? $payload['product'] : array();
		$d = isset( $payload['destination'] ) ? $payload['destination'] : array();

		$get = static function ( $array, $key ) {
			return isset( $array[ $key ] ) ? $array[ $key ] : '';
		};

		// Boutique unique : conserver le nom du site et son numero.
		// Marketplace : conserver le marchand et la destination de la demande.
		$marketplace   = ! empty( $payload['marketplace'] );
		$vendor        = $marketplace ? $get( $p, 'vendor' ) : $get( $payload, 'site' );
		$vendor_number = $get( $d, 'number' );
		$number_source = $marketplace ? $get( $d, 'source' ) : '';

		$data = array(
			'ref'           => $ref,
			'site'          => $get( $payload, 'site' ),
			'datetime'      => $get( $payload, 'datetime_kin' ),
			'product'       => $get( $p, 'name' ),
			'variant'       => $get( $p, 'variant_label' ),
			'vendor'        => $vendor,
			'unit_price'    => $get( $p, 'unit_price' ),
			'qty'           => $get( $p, 'qty' ),
			'currency'      => ! empty( $p['is_service'] ) ? '' : $get( $p, 'currency' ),
			'total'         => $get( $p, 'items_total' ),
			'customer'      => $get( $c, 'full_name' ),
			'phone'         => $get( $c, 'phone' ),
			'email'         => $get( $c, 'email' ),
			'link'          => $get( $p, 'permalink' ),
			'mode'          => $get( $c, 'mode_label' ),
			'commune'       => $get( $c, 'commune' ),
			'address'       => $get( $c, 'address' ),
			'reference'     => $get( $c, 'reference' ),
			'city'          => $get( $c, 'city' ),
			'country'       => $get( $c, 'country' ),
			'status'        => $get( $payload, 'status' ),
			'vendor_number' => $vendor_number,
			'product_id'    => $get( $p, 'product_id' ),
			'number_source' => $number_source,
			'service' => ! empty( $p['is_service'] ) ? $get( $p, 'name' ) : '',
			'need_description' => ! empty( $p['is_service'] ) ? $get( $c, 'need_description' ) : '',
		);

		if ( ! empty( $p['is_service'] ) ) { $data['product'] = ''; $data['vendor'] = $get( $p, 'name' ); }
		// L'ordre suit toujours celui de columns().
		$row = array();
		foreach ( array_keys( self::columns() ) as $key ) {
			$row[] = self::sanitize_cell( isset( $data[ $key ] ) ? $data[ $key ] : '' );
		}

		return $row;
	}

	/**
	 * Ajoute une ligne dans la feuille du mois.
	 *
	 * @param array  $row  Valeurs de la ligne.
	 * @param string $when Date ISO de la demande, pour choisir le mois.
	 * @return true|WP_Error
	 */
	public static function append_row( $row, $when = '' ) {
		$sheet_id = self::ensure_sheet();
		if ( is_wp_error( $sheet_id ) ) {
			return $sheet_id;
		}

		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$tab = self::ensure_tab( $sheet_id, self::tab_name( $when ), $token );
		if ( is_wp_error( $tab ) ) {
			return $tab;
		}

		$has_service = ! empty( $row[ self::column_index( 'service' ) ] );
		$headers = $has_service ? self::ensure_service_columns( $sheet_id, $tab, $token ) : self::read_headers( $sheet_id, $tab, $token );
		if ( is_wp_error( $headers ) ) { return $headers; }
		$row = self::align_row( $row, $headers );
		if ( is_wp_error( $row ) ) { return $row; }
		$tabs = self::fetch_tabs( $sheet_id, $token );
		if ( is_wp_error( $tabs ) ) { return $tabs; }
		if ( ! isset( $tabs[ $tab ] ) ) { return new WP_Error( 'cswa_tab_missing', 'Feuille mensuelle introuvable.' ); }

		$url = self::SHEETS_API . '/' . rawurlencode( $sheet_id )
			. '/values/' . rawurlencode( self::quote_tab( $tab ) . '!A1' ) . ':append';

		$url = add_query_arg(
			array(
				'valueInputOption'        => 'RAW',
				'insertDataOption'        => 'OVERWRITE',
				'includeValuesInResponse' => 'false',
			),
			$url
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'values' => array( array_values( $row ) ) ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			// Reapply after append so even newly expanded rows have dropdowns.
			// A formatting failure must never re-append an already saved request.
			$rules = array();
			foreach ( array( 'Statut' => self::statuses(), 'Site' => self::sites() ) as $header => $values ) {
				$column = array_search( $header, $headers, true );
				if ( false !== $column ) { $rules[] = self::validation_request( $tabs[ $tab ], $column, $values ); }
			}
			$formatted = self::batch_update( $sheet_id, array( 'requests' => $rules ), $token );
			if ( is_wp_error( $formatted ) ) {
				update_option( 'cswa_sheet_error', 'Demande enregistrée, mais menus non actualisés : ' . $formatted->get_error_message(), false );
			}
			return true;
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : 'code HTTP ' . $code;

		// Fichier supprime, deplace hors de portee ou droits retires :
		// on signale sans jamais recreer un second fichier en silence.
		if ( in_array( $code, array( 403, 404 ), true ) ) {
			self::flag_problem(
				sprintf(
					'Le classeur Google (%1$s) est inaccessible : %2$s. Aucune nouvelle feuille ne sera créée automatiquement. Les demandes restent en attente sur le site.',
					$sheet_id,
					$detail
				)
			);
		}

		return new WP_Error( 'cswa_append', 'Écriture impossible : ' . $detail );
	}

	/**
	 * Enregistre un probleme bloquant et previent l'administrateur.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function flag_problem( $message ) {
		update_option( 'cswa_sheet_error', $message, false );

		if ( get_transient( 'cswa_admin_notified' ) ) {
			return;
		}

		set_transient( 'cswa_admin_notified', 1, 6 * HOUR_IN_SECONDS );

		wp_mail(
			CSWA_Plugin::admin_email(),
			'[Congo-Shop] Problème sur le journal des demandes WhatsApp',
			$message . "\n\n" . 'Réglages : ' . admin_url( 'admin.php?page=cswa' )
		);
	}

	/**
	 * Tente d'ecrire une demande, en enregistrant le resultat.
	 *
	 * @param array $request Ligne de la table des demandes.
	 * @return bool
	 */
	public static function sync_request( $request ) {
		// Empêche un doublon lorsque WP-Cron et Action Scheduler se chevauchent.
		$lock_name = 'cswa_sync_' . (int) $request['id'];
		if ( ! CSWA_Store::acquire_lock( $lock_name, 900 ) ) { return false; }
		try {
			$current = CSWA_Store::get_by_id( (int) $request['id'] );
			if ( ! $current || 'ok' === $current['sheet_status'] ) { return true; }
			return self::sync_request_unlocked( $current );
		} finally {
			CSWA_Store::release_lock( $lock_name );
		}
	}

	/** Synchronise une demande planifiée par l'Action Scheduler WooCommerce. */
	public static function sync_request_by_id( $request_id ) {
		$row = CSWA_Store::get_by_id( (int) $request_id );
		if ( $row ) { self::sync_request( $row ); }
	}

	/** Appelé exclusivement sous le verrou de la demande. */
	private static function sync_request_unlocked( $request ) {
		$payload = json_decode( $request['payload'], true );
		if ( ! is_array( $payload ) ) {
			CSWA_Store::set_sheet_status( (int) $request['id'], 'failed', 'Données illisibles.', DAY_IN_SECONDS );
			return false;
		}
		if ( ! empty( $payload['woo_order_required'] ) ) {
			$order = ! empty( $request['order_id'] ) ? wc_get_order( (int) $request['order_id'] ) : false;
			if ( ! $order || ! $order->get_meta( '_cswa_ready' ) ) {
				CSWA_Store::set_sheet_status( (int) $request['id'], 'failed', 'Commande WooCommerce non finalisée : en attente de reprise du client.', 300 );
				return false;
			}
		}

		$row    = self::build_row( $payload, $request['ref'] );
		$when   = isset( $payload['datetime_utc'] ) ? $payload['datetime_utc'] : $request['created_at'];
		// Always reconcile, including after a worker died before updating attempts.
		{
			$previous = self::request_exists_in_sheet( $request['ref'], $when );
			if ( is_wp_error( $previous ) ) {
				CSWA_Store::set_sheet_status( (int) $request['id'], 'failed', $previous->get_error_message(), 300 );
				return false;
			}
		}
		$result = $previous ? true : self::append_row( $row, $when );
		if ( true === $result ) { $result = CSWA_Merchant_Sheets::sync( $payload, $request['ref'], $row ); }

		if ( true === $result ) {
			CSWA_Store::set_sheet_status( (int) $request['id'], 'ok' );
			return true;
		}

		$attempts = (int) $request['attempts'];
		$delay    = min( 6 * HOUR_IN_SECONDS, 300 * ( $attempts + 1 ) );

		CSWA_Store::set_sheet_status( (int) $request['id'], 'failed', $result->get_error_message(), $delay );

		return false;
	}

	/**
	 * Lecture de contrôle sur réessai : évite une ligne dupliquée si Google a
	 * enregistré la précédente écriture mais la réponse HTTP a été perdue.
	 *
	 * @return bool|WP_Error
	 */
	private static function request_exists_in_sheet( $ref, $when ) {
		$id = self::sheet_id();
		if ( '' === $id ) { return false; }
		$token = self::access_token();
		if ( is_wp_error( $token ) ) { return $token; }
		$tab = self::ensure_tab( $id, self::tab_name( $when ), $token );
		if ( is_wp_error( $tab ) ) { return $tab; }
		$headers = self::read_headers( $id, $tab, $token );
		if ( is_wp_error( $headers ) ) { return $headers; }
		$index = array_search( 'ID demande', $headers, true );
		if ( false === $index ) { return new WP_Error( 'cswa_id_col', 'Colonne ID demande introuvable.' ); }
		$column = '';
		for ( $n = $index + 1; $n > 0; $n = (int) floor( ( $n - 1 ) / 26 ) ) {
			$column = chr( 65 + ( $n - 1 ) % 26 ) . $column;
		}
		$range = self::quote_tab( $tab ) . '!' . $column . '2:' . $column;
		$response = wp_remote_get(
			self::SHEETS_API . '/' . rawurlencode( $id ) . '/values/' . rawurlencode( $range ),
			array( 'timeout' => 25, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) )
		);
		if ( is_wp_error( $response ) ) { return $response; }
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'cswa_retry_read', 'Vérification des doublons impossible (Google Sheets).' );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		foreach ( (array) ( $body['values'] ?? array() ) as $values ) {
			if ( isset( $values[0] ) && $ref === (string) $values[0] ) { return true; }
		}
		return false;
	}

	/**
	 * Traite la file d'attente (tache planifiee).
	 *
	 * @return void
	 */
	public static function process_queue() {
		if ( ! self::is_connected() || get_transient( 'cswa_merchant_quota_pause' ) || get_transient( 'cswa_queue_pause' ) ) {
			return;
		}
		if ( ! CSWA_Store::acquire_lock( 'cswa_queue_worker', 300 ) ) { return; }
		try {
			// Shared-account Google quotas: small batches, no overlapping workers,
			// and a pause between manual/cron runs. Pending requests remain durable.
			set_transient( 'cswa_queue_pause', 1, 90 );
			CSWA_Merchant_Sheets::reconcile_batch();
			$rows = CSWA_Store::pending( 3 );
			$started = microtime( true );
			foreach ( $rows as $row ) {
				$ok = self::sync_request( $row );
				if ( get_transient( 'cswa_merchant_quota_pause' ) || ( ! $ok && get_option( 'cswa_sheet_error' ) ) || microtime( true ) - $started > 15 ) { break; }
			}
		} finally { CSWA_Store::release_lock( 'cswa_queue_worker' ); }
	}
}
