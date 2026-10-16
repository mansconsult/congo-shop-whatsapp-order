<?php
/** WooCommerce is the order register; the CSWA table tracks delivery to Sheets. */
defined( 'ABSPATH' ) || exit;

class CSWA_Orders {
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'statuses' ) );
		add_filter( 'woocommerce_order_query_args', array( __CLASS__, 'query_args' ) );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( __CLASS__, 'legacy_query' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_details' ) );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'admin_columns' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'admin_column' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'admin_column' ), 10, 2 );
	}

	public static function labels() {
		return array( 'cswa-new' => 'WhatsApp — à confirmer', 'cswa-confirmed' => 'WhatsApp — confirmée', 'cswa-ready' => 'WhatsApp — prête', 'cswa-delivered' => 'WhatsApp — livrée', 'cswa-cancelled' => 'WhatsApp — annulée' );
	}

	public static function register_statuses() {
		foreach ( self::labels() as $key => $label ) {
			register_post_status( 'wc-' . $key, array( 'label' => $label, 'public' => true, 'exclude_from_search' => false, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true, 'label_count' => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'cswa' ) ) );
		}
	}

	public static function statuses( $statuses ) {
		foreach ( self::labels() as $key => $label ) { $statuses[ 'wc-' . $key ] = $label; }
		return $statuses;
	}

	private static function meta_query( $args ) {
		$clauses = array();
		foreach ( array( 'cswa_vendor_id', 'cswa_request_id' ) as $key ) {
			if ( isset( $args[ $key ] ) ) { $clauses[] = array( 'key' => '_' . $key, 'value' => (int) $args[ $key ], 'compare' => '=' ); }
		}
		if ( isset( $args['cswa_vendor_id'] ) ) { $clauses[] = array( 'key' => '_cswa_ready', 'value' => 'yes', 'compare' => '=' ); }
		return $clauses;
	}

	public static function query_args( $args ) {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$clauses = self::meta_query( $args );
			if ( $clauses ) { $args['meta_query'] = array_merge( array( 'relation' => 'AND' ), isset( $args['meta_query'] ) ? array( $args['meta_query'] ) : array(), $clauses ); }
		}
		return $args;
	}

	public static function legacy_query( $query, $args ) {
		$clauses = self::meta_query( $args );
		if ( $clauses ) { $query['meta_query'] = array_merge( array( 'relation' => 'AND' ), isset( $query['meta_query'] ) ? array( $query['meta_query'] ) : array(), $clauses ); }
		return $query;
	}

	/** Caller holds the submission lock. Recovery reuses an interrupted order. */
	public static function ensure( $row ) {
		require_once __DIR__ . '/class-cswa-wc-order.php';
		if ( ! empty( $row['order_id'] ) ) {
			$order = wc_get_order( (int) $row['order_id'] );
			if ( ! $order || 'trash' === $order->get_status() ) { return new WP_Error( 'cswa_order_deleted', 'Cette commande a été supprimée. Contacte la boutique.', array( 'status' => 409 ) ); }
		} else {
			$matches = wc_get_orders( array( 'cswa_request_id' => (int) $row['id'], 'limit' => 1, 'status' => array_keys( wc_get_order_statuses() ) ) );
			$order = $matches ? reset( $matches ) : null;
		}
		try {
			wc_transaction_query( 'start' );
			$payload = json_decode( $row['payload'], true );
			if ( ! is_array( $payload ) ) { throw new Exception( 'Données de demande illisibles.' ); }
			$p = $payload['product']; $c = $payload['customer'];
			if ( ! $order ) {
				$order = new CSWA_WC_Order();
				$order->set_status( 'cswa-new' );
				$order->set_created_via( 'cswa' );
				$order->set_currency( ! empty( $p['currency'] ) ? $p['currency'] : get_woocommerce_currency() );
				$order->update_meta_data( '_cswa_request_id', (int) $row['id'] );
				$order->update_meta_data( '_cswa_ref', $row['ref'] );
				$order->update_meta_data( '_cswa_vendor_id', (int) ( $p['vendor_id'] ?? 0 ) );
				$order->update_meta_data( '_cswa_vendor_name', $p['vendor'] ?? '' );
				$order->update_meta_data( '_cswa_whatsapp', $payload['destination']['number'] );
				$order->update_meta_data( '_cswa_payload', $payload );
				$order->update_meta_data( '_cswa_test_mode', ! empty( $payload['test_mode'] ) ? 'yes' : 'no' );
				$order->save();
			}
			if ( ! $order->get_id() ) { throw new Exception( 'Commande non enregistrée.' ); }
			if ( ! CSWA_Store::link_order( (int) $row['id'], $order->get_id() ) ) { throw new Exception( 'Lien de commande non enregistré.' ); }
			if ( ! $order->get_meta( '_cswa_ready' ) ) {
				// A previous worker may have saved some line items before stopping.
				foreach ( $order->get_items() as $old_item ) { $old_item->delete(); }
				$order = new CSWA_WC_Order( $order->get_id() );
				$product = wc_get_product( ! empty( $p['variation_id'] ) ? $p['variation_id'] : $p['product_id'] );
				if ( ! $product ) { throw new Exception( 'Produit introuvable.' ); }
				$item = new WC_Order_Item_Product();
				$item->set_product( $product );
				$item->set_name( $p['name'] );
				$qty = ! empty( $p['is_service'] ) ? 1 : max( 1, (int) $p['qty'] );
				$item->set_quantity( $qty );
				// Preserve the quoted amount, without inventing delivery fees or tax splits.
				$total = ! empty( $p['is_service'] ) ? (float) $p['unit_price'] : (float) $p['items_total'];
				$item->set_subtotal( $total ); $item->set_total( $total );
				$item->add_meta_data( 'Prix indiqué', 'Montant affiché sur la fiche. Livraison et ventilation fiscale à confirmer.' );
				$order->add_item( $item );
				$address = array( 'first_name' => $c['full_name'], 'last_name' => '', 'phone' => $c['phone'], 'email' => $c['email'], 'address_1' => $c['address'], 'address_2' => $c['commune'], 'city' => $c['city'] );
				$order->set_address( $address, 'billing' );
				if ( 'delivery' === $c['mode'] || 'service' === $c['mode'] ) { $order->set_address( $address, 'shipping' ); }
				$order->set_customer_note( trim( ( $c['mode_label'] ?? '' ) . "\n" . ( $c['reference'] ?? '' ) . "\n" . ( $c['need_description'] ?? '' ) ) );
				$order->set_prices_include_tax( false );
				$order->calculate_totals( false );
				$order->update_meta_data( '_cswa_ready', 'yes' );
				$order->save();
				$verified = new CSWA_WC_Order( $order->get_id() );
				if ( 'yes' !== $verified->get_meta( '_cswa_ready' ) || (int) $verified->get_meta( '_cswa_request_id' ) !== (int) $row['id'] || 1 !== count( $verified->get_items() ) ) { throw new Exception( 'Commande incomplète après sauvegarde.' ); }
			}
			wc_transaction_query( 'commit' );
			return $order;
		} catch ( Throwable $e ) {
			wc_transaction_query( 'rollback' );
			if ( $order && $order->get_id() ) {
				$id = $order->get_id();
				clean_post_cache( $id );
				wp_cache_delete( 'order-items-' . $id, 'orders' );
				wc_delete_shop_order_transients( $id );
				$store = $order->get_data_store();
				if ( method_exists( $store->get_current_class_name(), 'clear_cached_data' ) ) { $store->clear_cached_data( array( $id ) ); }
				if ( class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) { wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id ); }
			}
			wc_get_logger()->error( 'Création commande CSWA : ' . $e->getMessage(), array( 'source' => 'cswa' ) );
			return new WP_Error( 'cswa_order', 'La commande n’est pas encore confirmée. Réessaie pour reprendre son enregistrement.', array( 'status' => 503 ) );
		}
	}

	public static function admin_details( $order ) {
		if ( 'cswa' !== $order->get_created_via() ) { return; }
		$vendor = (int) $order->get_meta( '_cswa_vendor_id' );
		echo '<p><strong>Demande WhatsApp :</strong> ' . esc_html( $order->get_meta( '_cswa_ref' ) ) . '<br><strong>Marchand :</strong> ' . esc_html( $vendor ? CSWA_Vendors::shop_name( $vendor ) : 'Gestion directe par le site' ) . '<br>Le message envoyé et le paiement ne sont pas vérifiés automatiquement. Les frais de livraison et les taxes sont à confirmer avant facturation.</p>';
		if ( 'yes' === $order->get_meta( '_cswa_test_mode' ) ) { echo '<p><strong>Commande de test</strong></p>'; }
	}

	public static function admin_columns( $columns ) {
		$columns['cswa_vendor'] = 'Marchand WhatsApp';
		return $columns;
	}

	public static function admin_column( $column, $order ) {
		if ( 'cswa_vendor' !== $column ) { return; }
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || 'cswa' !== $order->get_created_via() ) { echo '—'; return; }
		$id = (int) $order->get_meta( '_cswa_vendor_id' );
		echo esc_html( $id ? CSWA_Vendors::shop_name( $id ) : 'Gestion directe' );
	}
}
