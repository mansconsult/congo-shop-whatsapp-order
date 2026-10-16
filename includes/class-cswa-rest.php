<?php
/**
 * Points d'entree REST.
 *
 * @package CSWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * API du formulaire.
 */
class CSWA_Rest {

	const NS = 'cswa/v1';

	/**
	 * Enregistre les routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Declaration des routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/session',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'session' ),
			)
		);

		register_rest_route(
			self::NS,
			'/quote',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'quote' ),
			)
		);

		register_rest_route(
			self::NS,
			'/request',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'submit' ),
			)
		);
	}

	/**
	 * Fournit un nonce frais, meme si la page est servie depuis un cache.
	 *
	 * @return WP_REST_Response
	 */
	public static function session() {
		$response = new WP_REST_Response(
			array(
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'server_now' => time(),
			)
		);

		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	/**
	 * Verifie le nonce d'une requete.
	 *
	 * @param WP_REST_Request $request Requete.
	 * @return true|WP_Error
	 */
	private static function check_nonce( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( 'nonce' );
		}

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'cswa_nonce', 'Session expirée, recharge la page.', array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Recalcule le produit et son total a partir de WooCommerce.
	 *
	 * @param WP_REST_Request $request Requete.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function quote( $request ) {
		$check = self::check_nonce( $request );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$data = self::resolve_product_from_request( $request );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$destination = isset( $data['destination'] ) ? $data['destination'] : array( 'number' => CSWA_Number::fallback() );

		// Le navigateur a besoin du numero pour preparer le lien WhatsApp
		// avant le clic, sinon le navigateur bloquerait l'ouverture.
		$data['whatsapp_number'] = $destination['number'];

		// Sur un site a numero unique, aucun marchand n'est nomme.
		if ( ! CSWA_Number::is_marketplace() ) {
			$data['vendor'] = '';
		}

		unset( $data['destination'] );

		return new WP_REST_Response(
			array(
				'product' => $data,
				// Reference reservee des l'ouverture : elle figure dans le
				// message, qui est construit avant l'envoi.
				'ref'     => CSWA_Store::generate_ref(),
			)
		);
	}

	/**
	 * Extrait et resout le produit.
	 *
	 * @param WP_REST_Request $request Requete.
	 * @return array|WP_Error
	 */
	private static function resolve_product_from_request( $request ) {
		$product_id   = absint( $request->get_param( 'product_id' ) );
		$variation_id = absint( $request->get_param( 'variation_id' ) );
		$qty          = absint( $request->get_param( 'qty' ) );
		$attributes   = $request->get_param( 'attributes' );

		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}

		if ( ! $product_id ) {
			return new WP_Error( 'cswa_product', 'Produit introuvable.', array( 'status' => 400 ) );
		}

		return CSWA_Product::resolve(
			$product_id,
			$variation_id,
			$attributes,
			$qty ? $qty : 1,
			(string) $request->get_param( 'button_number' )
		);
	}

	/**
	 * Enregistre la demande puis prepare le message WhatsApp.
	 *
	 * @param WP_REST_Request $request Requete.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function submit( $request ) {
		$key = (string) $request->get_param( 'idem_key' );
		if ( strlen( $key ) < 12 || strlen( $key ) > 64 ) { return new WP_Error( 'cswa_idem', 'Requête invalide.', array( 'status' => 400 ) ); }
		$lock = 'cswa_submit_' . hash( 'sha256', $key );
		if ( ! CSWA_Store::acquire_lock( $lock, 300 ) ) { return new WP_Error( 'cswa_busy', 'Enregistrement en cours. Réessaie dans quelques secondes.', array( 'status' => 409 ) ); }
		try { return self::submit_locked( $request ); }
		finally { CSWA_Store::release_lock( $lock ); }
	}

	private static function submit_locked( $request ) {
		$check = self::check_nonce( $request );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		// Piege a robots : champ invisible qui doit rester vide.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new WP_Error( 'cswa_spam', 'Demande refusée.', array( 'status' => 400 ) );
		}

		// Delai minimal de remplissage.
		$opened = absint( $request->get_param( 'opened_at' ) );
		if ( $opened && ( time() - $opened ) < 3 && 'pickup' !== ( ( (array) $request->get_param( 'customer' ) )['mode'] ?? 'delivery' ) ) {
			return new WP_Error( 'cswa_speed', 'Prends le temps de vérifier tes informations.', array( 'status' => 429 ) );
		}

		$idem_key = sanitize_text_field( (string) $request->get_param( 'idem_key' ) );
		if ( strlen( $idem_key ) < 12 || strlen( $idem_key ) > 64 ) {
			return new WP_Error( 'cswa_idem', 'Requete invalide.', array( 'status' => 400 ) );
		}

		// Double clic ou renvoi : on rejoue la reponse sans rien dupliquer.
		$existing = CSWA_Store::get_by_idem( $idem_key );
		if ( $existing ) {
			return self::finish( $existing );
		}

		if ( ! CSWA_Store::rate_limit_ok( (int) CSWA_Plugin::get_setting( 'rate_limit', 6 ) ) ) {
			return new WP_Error( 'cswa_rate', 'Trop de demandes envoyées. Réessaie dans quelques minutes.', array( 'status' => 429 ) );
		}

		$product = self::resolve_product_from_request( $request );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$customer = self::sanitize_customer( $request->get_param( 'customer' ), ! empty( $product['is_service'] ) );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		// Destinataire deja resolu pendant la lecture du produit.
		$destination = isset( $product['destination'] ) ? $product['destination'] : array(
			'number' => CSWA_Number::fallback(),
			'source' => 'repli',
		);

		if ( '' === $destination['number'] ) {
			return new WP_Error(
				'cswa_no_number',
				"Aucun numéro WhatsApp n'est configuré pour ce produit.",
				array( 'status' => 500 )
			);
		}

		$payload = array(
			'woo_order_required' => true,
			'ref_placeholder' => '',
			'site'            => CSWA_Google::site_name(),
			'datetime_kin'    => wp_date( 'd/m/Y H:i', null, new DateTimeZone( 'Africa/Kinshasa' ) ),
			'datetime_utc'    => gmdate( 'c' ),
			'customer'        => $customer,
			'product'         => $product,
			'destination'     => $destination,
			'marketplace'     => CSWA_Number::is_marketplace(),
			'status'          => CSWA_Google::initial_status(),
			'source_url'      => esc_url_raw( (string) $request->get_param( 'source_url' ) ),
			'test_mode'       => (bool) CSWA_Plugin::get_setting( 'test_mode', 0 ),
		);

		$saved = CSWA_Store::insert_request(
			$idem_key,
			$payload,
			CSWA_Store::client_ip(),
			(string) $request->get_param( 'ref' )
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return self::finish( $saved['row'] );
	}

	private static function finish( $row ) {
		$order = CSWA_Orders::ensure( $row );
		if ( is_wp_error( $order ) ) { return $order; }
		$row['order_id'] = $order->get_id();

		// Le client ne doit JAMAIS attendre les appels Google (OAuth, Sheets, Drive).
		// Le stockage local est déjà confirmé ; WooCommerce traite la sync en arrière-plan.
		// WP-Cron reste le filet de sécurité pour toute action perdue ou en échec.
		if ( 'ok' !== $row['sheet_status'] && CSWA_Google::is_connected() ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'cswa_sync_request', array( 'request_id' => (int) $row['id'] ), 'cswa', true );
			} else {
				wp_schedule_single_event( time() + 30, 'cswa_retry_sheet' );
			}
		}

		return new WP_REST_Response( self::response_from_row( $row ) );
	}

	/**
	 * Construit la reponse renvoyee au navigateur.
	 *
	 * @param array $row Ligne de la table.
	 * @return array
	 */
	private static function response_from_row( $row ) {
		$payload = json_decode( $row['payload'], true );
		$message = self::build_message( $payload, $row['ref'] );

		$number = isset( $payload['destination']['number'] ) ? $payload['destination']['number'] : '';

		if ( '' === $number ) {
			$number = CSWA_Number::fallback();
		}

		return array(
			'order_id'     => (int) ( $row['order_id'] ?? 0 ),
			'ref'          => $row['ref'],
			'saved'        => true,
			'journal'      => ( 'ok' === $row['sheet_status'] ),
			'test_mode'    => ! empty( $payload['test_mode'] ),
			'message'      => $message,
			'whatsapp_url' => 'https://wa.me/' . $number . '?text=' . rawurlencode( $message ),
			'product'      => isset( $payload['product'] ) ? $payload['product'] : array(),
			'customer'     => isset( $payload['customer'] ) ? $payload['customer'] : array(),
		);
	}

	/**
	 * Redige le message prerempli.
	 *
	 * @param array  $payload Donnees.
	 * @param string $ref     Reference.
	 * @return string
	 */
	public static function build_message( $payload, $ref ) {
		$p = $payload['product'];
		$c = $payload['customer'];

		$lines   = array();
		$service = ! empty( $p['is_service'] );
		if ( $service ) {
			$lines = array( 'Bonjour, je souhaite demander une prestation :', '', 'Service concerné : ' . $p['name'], 'Lien : ' . $p['permalink'], 'Description du besoin : ' . $c['need_description'], '' );
		} else {
		$lines[] = 'Bonjour' . ( ! empty( $p['vendor'] ) ? ' ' . $p['vendor'] : '' ) . ', je souhaite commander :';
		$lines[] = '';
		$lines[] = 'Produit : ' . $p['name'];

		if ( ! empty( $payload['marketplace'] ) && ! empty( $p['vendor'] ) ) {
			$lines[] = 'Vendeur : ' . $p['vendor'];
		}

		if ( ! empty( $p['variant_label'] ) ) {
			$lines[] = 'Variante : ' . $p['variant_label'];
		}

		$lines[] = 'Quantité : ' . $p['qty'];
		$lines[] = 'Prix unitaire : ' . $p['unit_price_text'];
		$lines[] = 'Total des articles : ' . $p['items_total_text'] . ' (hors frais de livraison)';
		$lines[] = 'Lien : ' . $p['permalink'];
		$lines[] = '';
		}
		$lines[] = 'Mes informations :';
		if ( '' !== $c['full_name'] ) { $lines[] = 'Nom : ' . $c['full_name']; }
		if ( '' !== $c['phone'] ) { $lines[] = 'Téléphone : ' . $c['phone']; }

		if ( ! empty( $c['email'] ) ) {
			$lines[] = 'E-mail : ' . $c['email'];
		}

		if ( ! $service ) { $lines[] = 'Mode : ' . $c['mode_label']; }

		$address = array_filter(
			array(
				$c['address'],
				$c['commune'] ? 'C. ' . $c['commune'] : '',
				$c['city'],
				$c['country'],
			)
		);

		if ( $address ) { $lines[] = ( 'delivery' === $c['mode'] ? 'Adresse de livraison : ' : 'Adresse : ' ) . implode( ', ', $address ); }

		if ( ! empty( $c['reference'] ) ) {
			$lines[] = 'Référence : ' . $c['reference'];
		}

		$lines[] = '';

		if ( $service ) {
			$lines[] = 'Merci de me confirmer vos disponibilités et votre tarif.';
		} elseif ( 'delivery' === $c['mode'] ) {
			$lines[] = 'Merci de me confirmer les frais et le délai de livraison.';
		} else {
			$lines[] = 'Je viendrai retirer la commande, merci de me confirmer le point et les horaires de retrait.';
		}



		return implode( "\n", $lines );
	}

	/**
	 * Valide et nettoie les informations client.
	 *
	 * @param mixed $raw Donnees brutes.
	 * @return array|WP_Error
	 */
	private static function sanitize_customer( $raw, $service = false ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'cswa_form', 'Formulaire incomplet.', array( 'status' => 400 ) );
		}

		$get = static function ( $key ) use ( $raw ) {
			return isset( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $raw[ $key ] ) ) : '';
		};

		$mode = $service ? 'service' : ( 'delivery' === $get( 'mode' ) ? 'delivery' : 'pickup' );
		if ( ! $service && ! in_array( $get( 'mode' ), array( '', 'pickup', 'delivery' ), true ) ) { return new WP_Error( 'cswa_mode', 'Mode invalide.', array( 'status' => 400 ) ); }


		$customer = array(
			'full_name'  => self::limit( $get( 'full_name' ), 120 ),
			'phone'      => self::limit( preg_replace( '/[^0-9+\s().-]/', '', $get( 'phone' ) ), 32 ),
			'email'      => self::limit( sanitize_email( $get( 'email' ) ), 120 ),
			'mode'       => $mode,
			'mode_label' => $service ? '' : ( ( 'pickup' === $mode ) ? 'Retrait' : 'Livraison' ),
			'need_description' => $service ? self::limit( sanitize_textarea_field( (string) ( $raw['need_description'] ?? '' ) ), 3000 ) : '',
			'country'    => self::limit( $get( 'country' ), 80 ),
			'city'       => self::limit( $get( 'city' ), 80 ),
			'commune'    => self::limit( self::pick_commune( $get( 'commune' ), $get( 'commune_other' ) ), 80 ),
			'address'    => self::limit( $get( 'address' ), 300 ),
			'reference'  => self::limit( $get( 'reference' ), 500 ),
		);

		if ( 'pickup' === $mode ) {
			foreach ( array( 'country','city','commune','address','reference','need_description' ) as $key ) { $customer[$key] = ''; }
		}
		// Le pays reste facultatif : on comble seulement pour le message.
		if ( '' === $customer['country'] ) {
			$customer['country'] = '';
		}

		$required = array(
			'full_name' => 'le nom complet',
			'phone'     => 'le téléphone',
			'city'      => 'la ville',
			'commune'   => 'la commune',
			'address'   => "l'adresse exacte",
		);

		if ( 'pickup' === $mode ) { $required = array_intersect_key( $required, array_flip( array( 'full_name', 'phone' ) ) ); }
		if ( $service ) { $required['need_description'] = 'la description du besoin'; }
		$missing = array();
		foreach ( $required as $key => $label ) {
			if ( '' === trim( $customer[ $key ] ) ) {
				$missing[] = $label;
			}
		}

		if ( $missing ) {
			return new WP_Error(
				'cswa_required',
				'Merci de renseigner ' . implode( ', ', $missing ) . '.',
				array(
					'status' => 400,
					'fields' => array_keys( array_intersect_key( $required, array_flip( array_keys( $required ) ) ) ),
				)
			);
		}

		$digits = preg_replace( '/\D+/', '', $customer['phone'] );
		if ( '' !== $customer['phone'] && strlen( $digits ) < 8 ) {
			return new WP_Error( 'cswa_phone', 'Le numéro de téléphone semble incomplet.', array( 'status' => 400 ) );
		}

		if ( '' !== $customer['full_name'] && mb_strlen( $customer['full_name'] ) < 3 ) {
			return new WP_Error( 'cswa_name', "Merci d'indiquer ton nom complet.", array( 'status' => 400 ) );
		}

		// Facultatif, mais s'il est renseigne il doit etre valide.
		$raw_email = trim( (string) $get( 'email' ) );
		if ( '' !== $raw_email && '' === $customer['email'] ) {
			return new WP_Error( 'cswa_email', 'Cette adresse e-mail semble incorrecte.', array( 'status' => 400 ) );
		}

		return $customer;
	}

	/**
	 * Retient la commune choisie, ou celle saisie librement.
	 *
	 * @param string $choice Valeur du selecteur.
	 * @param string $other  Champ libre.
	 * @return string
	 */
	private static function pick_commune( $choice, $other ) {
		if ( '__autre' === $choice || '' === $choice ) {
			return $other;
		}
		return $choice;
	}

	/**
	 * Tronque une chaine.
	 *
	 * @param string $value  Valeur.
	 * @param int    $length Longueur maximale.
	 * @return string
	 */
	private static function limit( $value, $length ) {
		$value = trim( (string) $value );
		return ( mb_strlen( $value ) > $length ) ? mb_substr( $value, 0, $length ) : $value;
	}
}
