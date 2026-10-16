<?php
/**
 * Resolution et verification cote serveur des donnees produit.
 *
 * @package CSWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lecture fiable du produit consulte.
 */
class CSWA_Product {

	/**
	 * Resout un produit, sa variante et son prix.
	 *
	 * Le prix n'est jamais lu depuis la requete : il est toujours recalcule
	 * a partir de WooCommerce.
	 *
	 * @param int   $product_id   Identifiant du produit parent.
	 * @param int   $variation_id Identifiant de variation, 0 si inconnu.
	 * @param array $attributes   Attributs choisis, format attribute_pa_x => valeur.
	 * @param int   $qty          Quantite demandee.
	 * @return array|WP_Error
	 */
	public static function resolve( $product_id, $variation_id, $attributes, $qty, $button_number = '' ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
			return new WP_Error( 'cswa_product', "Ce produit n'est plus disponible.", array( 'status' => 404 ) );
		}

		if ( CSWA_Service::enabled() && ! CSWA_Service::matches( $product_id ) ) {
			return new WP_Error( 'cswa_service_scope', 'Cette fiche ne concerne pas un prestataire.', array( 'status' => 400 ) );
		}
		if ( CSWA_Service::matches( $product_id ) ) {
			$destination = CSWA_Vendors::destination( $product_id );
			if ( null === $destination ) { $destination = CSWA_Service::destination( $product_id ); }
			if ( '' === $destination['number'] ) {
				return new WP_Error( 'cswa_no_number', 'Le numéro WhatsApp de ce prestataire doit être configuré.', array( 'status' => 400 ) );
			}
			$price = $product->get_price();
			return array(
				'vendor_id' => (int) ( $destination['vendor_id'] ?? 0 ),
				'product_id' => (int) $product_id, 'variation_id' => 0, 'is_service' => true,
				'name' => wp_strip_all_tags( $product->get_name() ), 'vendor' => wp_strip_all_tags( $product->get_name() ),
				'sku' => $product->get_sku(), 'permalink' => $product->get_permalink(), 'variant_label' => '',
				'destination' => $destination, 'qty' => '', 'currency' => '',
				'unit_price' => '' === $price ? '' : (float) wc_get_price_to_display( $product ),
				'unit_price_text' => '' === $price ? 'Tarif à convenir avec le prestataire' : self::price_text( wc_get_price_to_display( $product ) ),
				'items_total' => '', 'items_total_text' => '', 'in_stock' => true,
			);
		}
		$target    = $product;
		$variation = null;

		if ( $product->is_type( 'variable' ) ) {
			$variation_id = (int) $variation_id;

			if ( $variation_id > 0 ) {
				$candidate = wc_get_product( $variation_id );
				if ( ! $candidate || ! $candidate->is_type( 'variation' ) || (int) $candidate->get_parent_id() !== (int) $product->get_id() ) {
					return new WP_Error( 'cswa_variation', 'La variante choisie ne correspond pas à ce produit.', array( 'status' => 400 ) );
				}
				$variation = $candidate;
			} elseif ( ! empty( $attributes ) ) {
				$clean = array();
				foreach ( (array) $attributes as $key => $value ) {
					$key = sanitize_text_field( (string) $key );
					if ( 0 !== strpos( $key, 'attribute_' ) ) {
						$key = 'attribute_' . $key;
					}
					$clean[ $key ] = sanitize_text_field( (string) $value );
				}

				$data_store = WC_Data_Store::load( 'product' );
				$match      = $data_store->find_matching_product_variation( $product, $clean );

				if ( $match ) {
					$variation = wc_get_product( $match );
				}
			}

			if ( ! $variation ) {
				return new WP_Error(
					'cswa_variation_missing',
					"Choisis d'abord toutes les options du produit.",
					array( 'status' => 400 )
				);
			}

			$target = $variation;
		}

		if ( ! $target->is_purchasable() ) {
			return new WP_Error( 'cswa_purchasable', "Ce produit n'est pas commandable pour le moment.", array( 'status' => 400 ) );
		}
		if ( ! $target->is_in_stock() ) { return new WP_Error( 'cswa_stock', 'Ce produit est actuellement indisponible.', array( 'status' => 400 ) ); }

		$qty = self::clamp_qty( $target, $qty );
		if ( $target->is_sold_individually() ) { $qty = 1; }
		if ( ! $target->has_enough_stock( $qty ) ) { return new WP_Error( 'cswa_stock', 'La quantité demandée n’est pas disponible.', array( 'status' => 400 ) ); }

		// Le marchand se deduit d'abord du numero destinataire, qui est la
		// seule donnee fiable sur les trois sites multi-marchands.
		$destination = CSWA_Number::resolve( $product->get_id(), $variation ? $variation->get_id() : 0, $button_number );

		if ( '' === $destination['number'] ) {
			return new WP_Error( 'cswa_no_number', 'Le numéro WhatsApp de cette boutique est indisponible. Contactez le site.', array( 'status' => 400 ) );
		}

		$unit  = (float) wc_get_price_to_display( $target );
		$total = round( $unit * $qty, wc_get_price_decimals() );

		$variant_label = '';
		if ( $variation ) {
			$parts = array();
			foreach ( $variation->get_variation_attributes() as $name => $value ) {
				$taxonomy = str_replace( 'attribute_', '', $name );
				$label    = wc_attribute_label( $taxonomy, $product );
				$term     = '';

				if ( taxonomy_exists( $taxonomy ) ) {
					$term_obj = get_term_by( 'slug', $value, $taxonomy );
					$term     = $term_obj ? $term_obj->name : $value;
				} else {
					$term = $value;
				}

				if ( '' !== $term ) {
					$parts[] = $label . ' : ' . $term;
				}
			}
			$variant_label = implode( ', ', $parts );
		}

		return array(
			'vendor_id'        => (int) ( $destination['vendor_id'] ?? 0 ),
			'product_id'      => (int) $product->get_id(),
			'variation_id'    => $variation ? (int) $variation->get_id() : 0,
			'name'            => wp_strip_all_tags( $product->get_name() ),
			'sku'             => $target->get_sku(),
			'vendor'          => CSWA_Number::vendor_name( $product->get_id(), $destination['number'], $variation ? $variation->get_id() : 0 ),
			'destination'     => $destination,
			'permalink'       => $variation ? $variation->get_permalink() : $product->get_permalink(),
			'variant_label'   => $variant_label,
			'qty'             => $qty,
			'currency'        => get_woocommerce_currency(),
			'unit_price'      => $unit,
			'unit_price_text' => self::price_text( $unit ),
			'items_total'     => $total,
			'items_total_text' => self::price_text( $total ),
			'in_stock'        => $target->is_in_stock(),
		);
	}

	/**
	 * Borne la quantite selon les reglages du produit.
	 *
	 * @param WC_Product $product Produit ou variation.
	 * @param int        $qty     Quantite demandee.
	 * @return int
	 */
	private static function clamp_qty( $product, $qty ) {
		$qty = (int) $qty;
		if ( $qty < 1 ) {
			$qty = 1;
		}
		if ( $qty > 999 ) {
			$qty = 999;
		}

		if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
			$stock = $product->get_stock_quantity();
			if ( null !== $stock && $stock > 0 && $qty > $stock ) {
				$qty = (int) $stock;
			}
		}

		return $qty;
	}

	/**
	 * Formate un montant en texte simple.
	 *
	 * @param float $amount Montant.
	 * @return string
	 */
	public static function price_text( $amount ) {
		$html = wc_price( $amount, array( 'currency' => get_woocommerce_currency() ) );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		// wc_price insere parfois une espace insecable etroite avant la devise.
		return trim( str_replace( array( "\xc2\xa0", "\xe2\x80\xaf" ), ' ', $text ) );
	}
}
