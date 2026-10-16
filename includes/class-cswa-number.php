<?php
/**
 * Resolution du numero WhatsApp destinataire.
 *
 * Congo-Shop est une marketplace : chaque produit peut avoir son propre
 * numero, gere par OneClick Chat to Order. Le plugin ne suppose donc jamais
 * un numero unique.
 *
 * Les attributions explicites du produit (dont OneClick) sont prioritaires.
 * A defaut, les integrations existantes du vendeur et du bouton restent lues.
 *
 * @package CSWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Numero destinataire par produit.
 */
class CSWA_Number {

	const CACHE_KEYS  = 'cswa_meta_keys';
	const CACHE_WHITE = 'cswa_whitelist';

	/**
	 * Normalise un numero au format international sans signe plus.
	 *
	 * Les numeros de la RDC saisis localement commencent par 0 et comptent
	 * 10 chiffres : le 0 est alors remplace par l'indicatif 243.
	 *
	 * @param string $number Numero brut.
	 * @return string Numero normalise, ou chaine vide si invalide.
	 */
	public static function normalize( $number ) {
		$digits = preg_replace( '/\D+/', '', (string) $number );

		if ( '' === $digits ) {
			return '';
		}

		// 00243... ecrit en prefixe international long.
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}

		$default_cc = (string) CSWA_Plugin::get_setting( 'country_code', '243' );

		// Numero local RDC : 0 suivi de 9 chiffres.
		if ( 0 === strpos( $digits, '0' ) && 10 === strlen( $digits ) ) {
			$digits = $default_cc . substr( $digits, 1 );
		}

		// Numero national sans indicatif ni zero initial.
		if ( 9 === strlen( $digits ) && '0' !== substr( $digits, 0, 1 ) ) {
			$digits = $default_cc . $digits;
		}

		if ( strlen( $digits ) < 10 || strlen( $digits ) > 15 ) {
			return '';
		}

		return $digits;
	}

	/**
	 * Numero de repli global.
	 *
	 * @return string
	 */
	public static function fallback() {
		return self::normalize( CSWA_Plugin::get_setting( 'whatsapp_number', '243832310808' ) );
	}

	/* ---------------------------------------------------------------------
	 * Detection des champs utilises sur ce site
	 * ------------------------------------------------------------------ */

	/**
	 * Cherche les champs personnalises de produit qui contiennent un numero.
	 *
	 * Le nom exact des champs depend de la version de OneClick Chat to Order
	 * et du plugin multi-vendeur : plutot que de le deviner, on le detecte.
	 *
	 * @param bool $force Ignorer le cache.
	 * @return array Tableau cle => nombre de produits concernes.
	 */
	public static function detect_meta_keys( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEYS );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT pm.meta_key AS k, COUNT(*) AS c
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type IN ('product','product_variation')
			   AND (
				 pm.meta_key LIKE '%whatsapp%'
				 OR pm.meta_key LIKE '%ocwa%'
				 OR pm.meta_key LIKE '%chat_to_order%'
				 OR pm.meta_key LIKE '%wa_number%'
				 OR pm.meta_key LIKE '%wanumber%'
				 OR pm.meta_key LIKE '%_wa_%'
				 OR pm.meta_key LIKE '%phone%'
			   )
			   AND pm.meta_value REGEXP '^[+0-9 ().-]{8,22}$'
			 GROUP BY pm.meta_key
			 ORDER BY c DESC
			 LIMIT 20",
			ARRAY_A
		);

		$keys = array();
		foreach ( (array) $rows as $row ) {
			$keys[ $row['k'] ] = (int) $row['c'];
		}

		set_transient( self::CACHE_KEYS, $keys, 12 * HOUR_IN_SECONDS );

		return $keys;
	}

	/**
	 * Cles a interroger, dans l'ordre de priorite.
	 *
	 * @return string[]
	 */
	public static function meta_keys_in_order() {
		$keys = array();

		// Une cle figee par l'administrateur passe avant tout.
		$forced = trim( (string) CSWA_Plugin::get_setting( 'number_meta_key', '' ) );
		if ( '' !== $forced ) {
			$keys[] = $forced;
		}

		// Champs lus par le widget maison de Mobile RDC, dans son ordre.
		$keys[] = 'whatsapp';
		$keys[] = '_whatsapp';
		$keys[] = 'vendor_whatsapp';
		$keys[] = 'numero_whatsapp';

		foreach ( array_keys( self::detect_meta_keys() ) as $key ) {
			$keys[] = $key;
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Numeros trouves dans les reglages des plugins WhatsApp.
	 *
	 * @return string[]
	 */
	public static function numbers_from_options() {
		global $wpdb;

		$rows = $wpdb->get_col(
			"SELECT option_value FROM {$wpdb->options}
			 WHERE option_name LIKE '%ocwa%'
				OR option_name LIKE '%chat_to_order%'
				OR option_name LIKE '%whatsapp%'
			 LIMIT 50"
		);

		$found = array();

		foreach ( (array) $rows as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			if ( preg_match_all( '/\b\d{9,15}\b/', $value, $matches ) ) {
				foreach ( $matches[0] as $candidate ) {
					$number = self::normalize( $candidate );
					if ( '' !== $number ) {
						$found[ $number ] = true;
					}
				}
			}
		}

		return array_keys( $found );
	}

	/**
	 * Liste blanche des numeros reellement utilises par le site.
	 *
	 * @param bool $force Ignorer le cache.
	 * @return string[]
	 */
	public static function whitelist( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_WHITE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;

		$numbers = array();

		$fallback = self::fallback();
		if ( '' !== $fallback ) {
			$numbers[ $fallback ] = true;
		}

		// Les numeros declares par l'administrateur font foi.
		foreach ( array_keys( self::vendor_map() ) as $number ) {
			$numbers[ $number ] = true;
		}

		foreach ( self::numbers_from_options() as $number ) {
			$numbers[ $number ] = true;
		}

		// Balayage large : tout champ de produit dont la valeur est un numero
		// plausible, quel que soit le nom du champ. Deviner les noms utilises
		// par chaque plugin WhatsApp est perdu d'avance.
		$values = $wpdb->get_col(
			"SELECT DISTINCT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type IN ('product','product_variation')
			   AND pm.meta_value REGEXP '^[+]?[0-9 ().-]{9,22}$'
			 LIMIT 5000"
		);

		foreach ( (array) $values as $value ) {
			$number = self::normalize( $value );
			if ( '' !== $number ) {
				$numbers[ $number ] = true;
			}
		}

		// Champs dont la valeur contient un numero au milieu d'autre texte,
		// par exemple un tableau serialise de reglages par produit.
		$keys = self::meta_keys_in_order();

		if ( $keys ) {
			$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

			$blobs = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
					 WHERE meta_key IN ({$placeholders}) AND meta_value <> '' LIMIT 2000",
					$keys
				)
			);

			foreach ( (array) $blobs as $blob ) {
				foreach ( self::extract_numbers( (string) $blob ) as $number ) {
					$numbers[ $number ] = true;
				}
			}
		}

		// Numeros portes par les comptes vendeurs.
		$user_values = $wpdb->get_col(
			"SELECT DISTINCT meta_value FROM {$wpdb->usermeta}
			 WHERE ( meta_key LIKE '%whatsapp%' OR meta_key LIKE '%phone%' )
			   AND meta_value REGEXP '^[+0-9 ().-]{8,22}$'
			 LIMIT 1000"
		);

		foreach ( (array) $user_values as $value ) {
			$number = self::normalize( $value );
			if ( '' !== $number ) {
				$numbers[ $number ] = true;
			}
		}

		$list = array_keys( $numbers );

		set_transient( self::CACHE_WHITE, $list, 6 * HOUR_IN_SECONDS );

		return $list;
	}

	/**
	 * Indique si un numero fait partie de ceux du site.
	 *
	 * @param string $number Numero normalise.
	 * @return bool
	 */
	public static function is_known( $number ) {
		if ( '' === $number ) {
			return false;
		}

		if ( in_array( $number, array_map( 'strval', self::whitelist() ), true ) ) {
			return true;
		}

		// Un produit publie apres la mise en cache : on rafraichit une fois.
		return in_array( $number, array_map( 'strval', self::whitelist( true ) ), true );
	}

	/* ---------------------------------------------------------------------
	 * Resolution
	 * ------------------------------------------------------------------ */

	/** Resolve OneClick's explicit product assignment (stored as a record title).
	 * null means no assignment; an empty number means a broken assignment.
	 * Never silently send a broken assignment to another merchant.
	 */
	public static function oneclick_assignment( $product_id, $variation_id = 0 ) {
		foreach ( array_filter( array( (int) $variation_id, (int) $product_id ) ) as $id ) {
			if ( 'yes' !== get_post_meta( $id, '_wa_order_phone_number_check', true ) ) {
				continue;
			}
			$title = (string) get_post_meta( $id, '_wa_order_phone_number', true );
			$records = '' === trim( $title ) ? array() : get_posts( array(
				'title' => $title, 'post_type' => 'wa-order-numbers',
				'post_status' => 'publish', 'numberposts' => 2, 'fields' => 'ids',
			) );
			// Duplicate titles are ambiguous: require one published recipient.
			$record_id = 1 === count( $records ) ? (int) $records[0] : 0;
			return array(
				'number' => $record_id ? self::normalize( get_post_meta( $record_id, 'wa_order_phone_number_input', true ) ) : '',
				'source' => 'produit:oneclick',
				'vendor' => $record_id ? sanitize_text_field( get_the_title( $record_id ) ) : '',
			);
		}
		return null;
	}

	/**
	 * Numero attendu pour un produit, calcule cote serveur.
	 *
	 * @param int $product_id   Produit parent.
	 * @param int $variation_id Variation, 0 si aucune.
	 * @return array{number:string,source:string}
	 */
	public static function for_product( $product_id, $variation_id = 0 ) {
		$account = CSWA_Vendors::destination( $product_id );
		if ( null !== $account ) { return $account; }
		if ( CSWA_Service::matches( $product_id ) ) { return CSWA_Service::destination( $product_id ); }
		if ( ! self::is_marketplace() ) {
			return array( 'number' => self::fallback(), 'source' => 'site' );
		}
		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;

		/**
		 * Permet de brancher une logique maison.
		 *
		 * @param string $number     Numero, vide par defaut.
		 * @param int    $product_id Produit.
		 */
		$filtered = self::normalize( apply_filters( 'cswa_whatsapp_number', '', $product_id, $variation_id ) );
		if ( '' !== $filtered ) {
			return array(
				'number' => $filtered,
				'source' => 'filtre',
			);
		}

		$assigned = self::oneclick_assignment( $product_id, $variation_id );
		if ( null !== $assigned ) { return $assigned; }

		$keys = self::meta_keys_in_order();
		$ids  = array_filter( array( $variation_id, $product_id ) );

		foreach ( $ids as $id ) {
			foreach ( $keys as $key ) {
				$number = self::normalize( get_post_meta( $id, $key, true ) );
				if ( '' !== $number ) {
					return array(
						'number' => $number,
						'source' => 'produit:' . $key,
					);
				}
			}
		}

		// Vendeur : l'auteur du produit sur une marketplace.
		$author_id = (int) get_post_field( 'post_author', $product_id );

		if ( $author_id ) {
			$user_keys = array( 'whatsapp', 'whatsapp_number', 'ocwa_number', 'billing_phone', 'phone' );

			foreach ( $user_keys as $key ) {
				$number = self::normalize( get_user_meta( $author_id, $key, true ) );
				if ( '' !== $number ) {
					return array(
						'number' => $number,
						'source' => 'vendeur:' . $key,
					);
				}
			}

			// Dokan et WCFM rangent leurs reglages dans un tableau.
			foreach ( array( 'dokan_profile_settings', 'wcfmmp_profile_settings' ) as $key ) {
				$settings = get_user_meta( $author_id, $key, true );
				if ( is_array( $settings ) ) {
					foreach ( array( 'whatsapp', 'phone', 'store_phone' ) as $field ) {
						if ( ! empty( $settings[ $field ] ) ) {
							$number = self::normalize( $settings[ $field ] );
							if ( '' !== $number ) {
								return array(
									'number' => $number,
									'source' => 'vendeur:' . $key . '.' . $field,
								);
							}
						}
					}
				}
			}
		}

		$fallback = self::fallback();

		return array(
			'number' => $fallback,
			'source' => 'repli',
		);
	}

	/**
	 * Numero final, en tenant compte de celui porte par le bouton rendu.
	 *
	 * @param int    $product_id    Produit.
	 * @param int    $variation_id  Variation.
	 * @param string $button_number Numero lu dans le bouton, non fiable.
	 * @return array{number:string,source:string}
	 */
	public static function resolve( $product_id, $variation_id, $button_number ) {
		$resolved = self::for_product( $product_id, $variation_id );
		if ( 'compte-marchand' === $resolved['source'] ) { return $resolved; }
		if ( ! self::is_marketplace() ) { return $resolved; }
		$button   = self::normalize( $button_number );

		// Un numero explicitement rattache au produit prime sur un bouton global.
		if ( 'produit:kinpresta' === $resolved['source'] || 'produit:oneclick' === $resolved['source'] || ( '' !== $resolved['number'] && ( 'filtre' === $resolved['source'] || 0 === strpos( $resolved['source'], 'produit:' ) ) ) ) {
			return $resolved;
		}

		if ( '' === $button || $button === $resolved['number'] ) {
			return $resolved;
		}

		// Le bouton est genere par le serveur au rendu de la page : il fait
		// autorite des lors que le numero existe bien sur le site.
		if ( self::is_known( $button ) ) {
			return array(
				'number' => $button,
				'source' => 'bouton',
			);
		}

		// Le numero du bouton est inconnu de la base. Cela arrive quand le
		// plugin WhatsApp applique une regle que nous ne savons pas relire,
		// par exemple un numero par categorie. Dans ce cas la resolution
		// serveur n'a rien trouve non plus et se rabat sur le numero de
		// repli : le bouton reste alors la meilleure information disponible,
		// et l'ignorer enverrait la demande au mauvais destinataire.
		if ( 'repli' === $resolved['source'] ) {
			return array(
				'number' => $button,
				'source' => 'bouton (hors liste)',
			);
		}

		return $resolved;
	}

	/* ---------------------------------------------------------------------
	 * Marchands declares
	 * ------------------------------------------------------------------ */

	/**
	 * Indique si ce site est une marketplace a plusieurs marchands.
	 *
	 * @return bool
	 */
	public static function is_marketplace() {
		return ( 'single' !== CSWA_Plugin::get_setting( 'site_mode', 'marketplace' ) );
	}

	/**
	 * Correspondance numero => nom de marchand, saisie par l'administrateur.
	 *
	 * Format attendu, une ligne par marchand :
	 *   Bricom = 243895250837
	 *
	 * L'ordre des deux membres est libre, le separateur peut etre =, : ou |.
	 * Plusieurs numeros pour un meme marchand sont acceptes, separes par des
	 * virgules.
	 *
	 * @return array
	 */
	public static function vendor_map() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = array();
		$raw   = (string) CSWA_Plugin::get_setting( 'vendor_map', '' );

		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			$parts = preg_split( '/\s*[=:|]\s*/', $line, 2 );

			if ( count( $parts ) < 2 ) {
				continue;
			}

			// Le membre qui contient des chiffres est le ou les numeros.
			$digits_first = (bool) preg_match( '/^[+0-9 ().,-]+$/', $parts[0] );
			$name         = sanitize_text_field( $digits_first ? $parts[1] : $parts[0] );
			$numbers      = $digits_first ? $parts[0] : $parts[1];

			if ( '' === $name ) {
				continue;
			}

			foreach ( explode( ',', $numbers ) as $candidate ) {
				$number = self::normalize( $candidate );
				if ( '' !== $number ) {
					$cache[ $number ] = $name;
				}
			}
		}

		return $cache;
	}

	/**
	 * Nom lisible du marchand d'un produit.
	 *
	 * @param int    $product_id Produit.
	 * @param string $number     Numero destinataire deja resolu, si connu.
	 * @return string
	 */
	public static function vendor_name( $product_id, $number = '', $variation_id = 0 ) {
		$account = CSWA_Vendors::destination( $product_id );
		if ( null !== $account ) { return $account['vendor']; }
		// Site a numero unique : il n'y a pas de marchand a nommer.
		if ( ! self::is_marketplace() ) {
			return '';
		}

		$assigned = self::oneclick_assignment( $product_id, $variation_id );
		if ( $assigned && '' !== $assigned['number'] && $assigned['number'] === self::normalize( $number ) ) {
			return $assigned['vendor'];
		}

		// Correspondance manuelle pour les autres integrations.
		$number = self::normalize( $number );

		if ( '' !== $number ) {
			$map = self::vendor_map();
			if ( isset( $map[ $number ] ) ) {
				return $map[ $number ];
			}
		}

		return self::guess_vendor_name( $product_id );
	}

	/**
	 * Deduit le nom du marchand depuis la structure du site.
	 *
	 * @param int $product_id Produit.
	 * @return string
	 */
	public static function guess_vendor_name( $product_id ) {
		/**
		 * Permet de designer le marchand autrement, par exemple depuis une
		 * taxonomie maison.
		 *
		 * @param string $name       Nom, vide par defaut.
		 * @param int    $product_id Produit.
		 */
		$filtered = trim( (string) apply_filters( 'cswa_vendor_name', '', $product_id ) );
		if ( '' !== $filtered ) {
			return sanitize_text_field( $filtered );
		}

		// Cas le plus frequent ici : les boutiques sont des sous-categories
		// de produit rangees sous une categorie parente, « Boutique » par
		// defaut. Le marchand est alors la sous-categorie du produit.
		$parent_slug = trim( (string) CSWA_Plugin::get_setting( 'vendor_category', 'boutique' ) );

		if ( '' !== $parent_slug && taxonomy_exists( 'product_cat' ) ) {
			$parent = get_term_by( 'slug', sanitize_title( $parent_slug ), 'product_cat' );

			if ( ! $parent ) {
				$parent = get_term_by( 'name', $parent_slug, 'product_cat' );
			}

			if ( $parent && ! is_wp_error( $parent ) ) {
				$terms = get_the_terms( $product_id, 'product_cat' );

				if ( $terms && ! is_wp_error( $terms ) ) {
					// Descendant le plus profond sous la categorie parente.
					$best  = null;
					$depth = -1;

					foreach ( $terms as $term ) {
						$level  = 0;
						$cursor = $term;

						while ( $cursor && $cursor->parent ) {
							$cursor = get_term( $cursor->parent, 'product_cat' );
							if ( ! $cursor || is_wp_error( $cursor ) ) {
								break;
							}
							$level++;
							if ( (int) $cursor->term_id === (int) $parent->term_id && $level > $depth ) {
								$best  = $term;
								$depth = $level;
							}
						}
					}

					if ( $best ) {
						return sanitize_text_field( $best->name );
					}
				}
			}
		}

		// Taxonomies dediees, quand le site en utilise une.
		foreach ( array( 'store', 'boutique', 'marchand', 'vendor', 'brand', 'product_brand', 'pwb-brand' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $product_id, $taxonomy );
			if ( $terms && ! is_wp_error( $terms ) ) {
				return sanitize_text_field( $terms[0]->name );
			}
		}

		$author_id = (int) get_post_field( 'post_author', $product_id );

		if ( ! $author_id ) {
			return '';
		}

		if ( function_exists( 'dokan_get_store_info' ) ) {
			$store = dokan_get_store_info( $author_id );
			if ( ! empty( $store['store_name'] ) ) {
				return sanitize_text_field( $store['store_name'] );
			}
		}

		$settings = get_user_meta( $author_id, 'dokan_profile_settings', true );
		if ( is_array( $settings ) && ! empty( $settings['store_name'] ) ) {
			return sanitize_text_field( $settings['store_name'] );
		}

		$user = get_userdata( $author_id );

		if ( ! $user ) {
			return '';
		}

		// Jamais d'adresse e-mail devant un client, et pas de nom quand le
		// produit appartient a l'administrateur du site : ce n'est pas un
		// marchand tiers, la mention « Vendu par » n'a alors aucun sens.
		if ( is_email( $user->display_name ) || user_can( $author_id, 'manage_options' ) ) {
			return '';
		}

		return sanitize_text_field( $user->display_name );
	}

	/**
	 * Vide les caches de detection.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEYS );
		delete_transient( self::CACHE_WHITE );
	}

	/* ---------------------------------------------------------------------
	 * Diagnostic
	 * ------------------------------------------------------------------ */

	/**
	 * Retrouve un produit a partir d'un identifiant ou d'une URL.
	 *
	 * @param string $value Saisie de l'administrateur.
	 * @return int
	 */
	public static function resolve_product_input( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return 0;
		}

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		if ( 0 === strpos( $value, 'http' ) ) {
			$id = url_to_postid( $value );
			if ( $id ) {
				return (int) $id;
			}

			// Permalien non resolu : on tente par le slug final.
			$path = trim( (string) wp_parse_url( $value, PHP_URL_PATH ), '/' );
			$slug = substr( strrchr( '/' . $path, '/' ), 1 );

			if ( $slug ) {
				$post = get_page_by_path( $slug, OBJECT, array( 'product' ) );
				if ( $post ) {
					return (int) $post->ID;
				}
			}
		}

		return 0;
	}

	/**
	 * Inventaire complet de ce que le site sait d'un produit.
	 *
	 * Sert a localiser ou OneClick Chat to Order range ses numeros, sans
	 * avoir a deviner le nom des champs.
	 *
	 * @param int $product_id Produit.
	 * @return array|WP_Error
	 */
	public static function diagnose( $product_id ) {
		$product_id = (int) $product_id;
		$post       = get_post( $product_id );

		if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return new WP_Error( 'cswa_no_product', 'Aucun produit trouvé pour cette saisie.' );
		}

		$out = array(
			'id'        => $product_id,
			'title'     => get_the_title( $product_id ),
			'permalink' => get_permalink( $product_id ),
			'author'    => array(),
			'meta'      => array(),
			'options'   => array(),
			'resolved'  => self::for_product( $product_id ),
		);

		// Tous les champs du produit dont la valeur ressemble a un numero.
		foreach ( (array) get_post_meta( $product_id ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				if ( ! is_string( $value ) ) {
					continue;
				}
				$hits = self::extract_numbers( $value );
				if ( $hits ) {
					$out['meta'][] = array(
						'key'     => $key,
						'value'   => ( strlen( $value ) > 200 ) ? substr( $value, 0, 200 ) . '...' : $value,
						'numbers' => $hits,
					);
				}
			}
		}

		// Auteur du produit et ses champs.
		$author_id = (int) $post->post_author;
		if ( $author_id ) {
			$user = get_userdata( $author_id );

			$out['author'] = array(
				'id'           => $author_id,
				'login'        => $user ? $user->user_login : '',
				'display_name' => $user ? $user->display_name : '',
				'is_admin'     => user_can( $author_id, 'manage_options' ),
				'meta'         => array(),
			);

			foreach ( (array) get_user_meta( $author_id ) as $key => $values ) {
				foreach ( (array) $values as $value ) {
					if ( ! is_string( $value ) ) {
						continue;
					}
					$hits = self::extract_numbers( $value );
					if ( $hits ) {
						$out['author']['meta'][] = array(
							'key'     => $key,
							'value'   => ( strlen( $value ) > 200 ) ? substr( $value, 0, 200 ) . '...' : $value,
							'numbers' => $hits,
						);
					}
				}
			}
		}

		// Reglages des plugins WhatsApp.
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			 WHERE option_name LIKE '%ocwa%'
				OR option_name LIKE '%chat_to_order%'
				OR option_name LIKE '%whatsapp%'
			 LIMIT 50",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$hits = self::extract_numbers( (string) $row['option_value'] );
			if ( $hits ) {
				$out['options'][] = array(
					'key'     => $row['option_name'],
					'value'   => ( strlen( $row['option_value'] ) > 300 ) ? substr( $row['option_value'], 0, 300 ) . '...' : $row['option_value'],
					'numbers' => $hits,
				);
			}
		}

		return $out;
	}

	/**
	 * Extrait les suites de chiffres qui ressemblent a un numero.
	 *
	 * @param string $value Texte a analyser.
	 * @return string[] Numeros normalises.
	 */
	public static function extract_numbers( $value ) {
		$found = array();

		if ( preg_match_all( '/\+?\d[\d\s().-]{7,20}\d/', $value, $matches ) ) {
			foreach ( $matches[0] as $candidate ) {
				$number = self::normalize( $candidate );
				if ( '' !== $number ) {
					$found[ $number ] = true;
				}
			}
		}

		return array_keys( $found );
	}
}
