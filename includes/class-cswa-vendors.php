<?php
/** Minimal vendor portal. All authorization is checked again on the server. */
defined( 'ABSPATH' ) || exit;

class CSWA_Vendors {
	public static function enabled() {
		return CSWA_Number::is_marketplace() && (bool) CSWA_Plugin::get_setting( 'vendor_dashboard', 0 );
	}

	public static function install_roles() {
		add_role( 'cswa_pending', 'Marchand — à approuver', array( 'read' => true ) );
		add_role( 'cswa_vendor', 'Marchand', array( 'read' => true ) );
		add_role( 'cswa_suspended', 'Marchand — suspendu', array( 'read' => true ) );
	}

	public static function init() {
		self::install_roles();
		add_action( 'set_user_role', array( __CLASS__, 'notify_approval' ), 10, 3 );
		add_shortcode( 'cswa_vendor_dashboard', array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		// Register after the parent menu (priority 10) to resolve the correct page hook.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_cswa_vendor_register', array( __CLASS__, 'handle_register' ) );
		add_action( 'admin_post_nopriv_cswa_vendor_register', array( __CLASS__, 'handle_register' ) );
		add_action( 'admin_post_cswa_vendor_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_cswa_vendor_admin', array( __CLASS__, 'handle_admin' ) );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'metabox' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_assignment' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
	}

	public static function active( $id ) {
		$user = get_userdata( (int) $id );
		return $user && in_array( 'cswa_vendor', (array) $user->roles, true );
	}

	public static function shop_name( $id ) {
		$name = (string) get_user_meta( $id, '_cswa_shop_name', true );
		$user = get_userdata( $id );
		return $name ?: ( $user ? $user->display_name : 'Marchand supprimé' );
	}

	public static function product_vendor( $id ) {
		$parent = wp_get_post_parent_id( $id );
		return (int) get_post_meta( $parent ?: $id, '_cswa_vendor_id', true );
	}

	/** Null preserves legacy destinations for unassigned products. */
	public static function destination( $id ) {
		if ( ! self::enabled() ) { return null; }
		$vendor = self::product_vendor( $id );
		if ( ! $vendor ) { return null; }
		$number = self::active( $vendor ) ? CSWA_Number::normalize( get_user_meta( $vendor, '_cswa_whatsapp', true ) ) : '';
		return array( 'number' => $number, 'source' => 'compte-marchand', 'vendor_id' => $vendor, 'vendor' => self::shop_name( $vendor ) );
	}

	public static function portal_url() {
		$id = (int) get_option( 'cswa_vendor_page_id' );
		return $id && 'publish' === get_post_status( $id ) ? get_permalink( $id ) : home_url( '/' );
	}

	public static function no_cache() {
		$post = get_post();
		if ( $post && has_shortcode( $post->post_content, 'cswa_vendor_dashboard' ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
			nocache_headers();
		}
	}

	public static function enqueue() {
		$post = get_post();
		if ( self::enabled() && $post && has_shortcode( $post->post_content, 'cswa_vendor_dashboard' ) ) { wp_enqueue_style( 'cswa-vendor', CSWA_URL . 'assets/cswa-vendor.css', array(), CSWA_VERSION ); }
	}

	public static function menu() {
		add_submenu_page( 'cswa', 'Marchands', 'Marchands', 'manage_options', 'cswa-vendors', array( __CLASS__, 'admin_page' ) );
	}

	public static function register( $data ) {
		if ( ! self::enabled() ) { return new WP_Error( 'disabled', 'Les inscriptions marchands sont fermées.' ); }
		if ( ! empty( $data['website'] ) ) { return new WP_Error( 'spam', 'Inscription refusée.' ); }
		$email = trim( (string) ( $data['email'] ?? '' ) );
		// Email is the public sign-in identifier. Keep the internal login short
		// and opaque (WordPress user_login is limited to 60 characters).
		$login = 'cswa_' . str_replace( '-', '', wp_generate_uuid4() );
		$owner = sanitize_text_field( $data['owner_name'] ?? '' );
		$activity = sanitize_textarea_field( $data['activity'] ?? '' );
		$shop = sanitize_text_field( $data['shop'] ?? '' );
		$phone = CSWA_Number::normalize( $data['phone'] ?? '' );
		$password = (string) ( $data['password'] ?? '' );
		if ( ! is_email( $email ) || strlen( $email ) > 100 || mb_strlen( $shop ) < 2 || mb_strlen( $shop ) > 100 || mb_strlen( $owner ) < 2 || mb_strlen( $owner ) > 120 || mb_strlen( $activity ) < 2 || mb_strlen( $activity ) > 1000 || ! $phone || strlen( $password ) < 12 || strlen( $password ) > 200 ) {
			return new WP_Error( 'fields', 'Vérifie le nom de la boutique, l’adresse e-mail (100 caractères maximum), le nom du propriétaire, l’activité ou les produits vendus, le numéro WhatsApp international et le mot de passe d’au moins 12 caractères.' );
		}
		$details = self::validate_shop_details( $data );
		if ( is_wp_error( $details ) ) { return $details; }
		$photos = self::prepare_shop_photos();
		if ( is_wp_error( $photos ) ) { return $photos; }
		$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => $password, 'display_name' => $shop, 'role' => 'cswa_pending' ) );
		if ( is_wp_error( $id ) ) { self::discard_shop_photos( $photos ); return new WP_Error( 'account', 'Inscription impossible. Vérifie ton adresse e-mail, ou connecte-toi si tu as déjà un compte.' ); }
		update_user_meta( $id, '_cswa_shop_name', $shop );
		update_user_meta( $id, '_cswa_owner_name', $owner );
		update_user_meta( $id, '_cswa_activity', $activity );
		update_user_meta( $id, '_cswa_whatsapp', $phone );
		self::save_shop_details( $id, $details, $photos );
		self::notify_new_vendor( $id );
		return $id;
	}


	/** Notify the administrator only after a new pending merchant is saved. */
	private static function notify_new_vendor( $id ) {
		$user = get_userdata( $id );
		if ( ! $user || ! in_array( 'cswa_pending', (array) $user->roles, true ) ) { return false; }
		$submitted = false;
		try {
			$recipient = CSWA_Plugin::admin_email();
			$site = sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
			$message = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:20px;background:#f6f8f5;color:#182c29;font-family:Arial,sans-serif;line-height:1.6"><div style="max-width:640px;margin:auto;padding:24px;background:#ffffff"><h1 style="font-size:24px">Nouveau marchand à approuver</h1><p>Bonjour,</p><p>Un nouveau marchand vient de créer son compte sur <strong>' . esc_html( $site ) . '</strong>. Ce compte est en attente de votre approbation.</p>';
			$fields = array(
				'Nom de la boutique' => self::shop_name( $id ),
				'Nom du propriétaire' => get_user_meta( $id, '_cswa_owner_name', true ),
				'Activité ou produits vendus' => get_user_meta( $id, '_cswa_activity', true ),
				'Présentation de l’activité' => get_user_meta( $id, '_cswa_about', true ),
				'Adresse e-mail' => $user->user_email,
				'Numéro WhatsApp' => '+' . get_user_meta( $id, '_cswa_whatsapp', true ),
			);
			foreach ( $fields as $label => $value ) {
				$message .= '<p style="overflow-wrap:anywhere"><strong>' . esc_html( $label ) . ' :</strong><br>' . nl2br( esc_html( $value ) ) . '</p>';
			}
			foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram' ) as $field => $label ) {
				$url = get_user_meta( $id, '_cswa_' . $field, true );
				$message .= '<p style="overflow-wrap:anywhere"><strong>' . esc_html( $label ) . ' :</strong><br>' . ( $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>' : 'Non renseigné' ) . '</p>';
			}
			$message .= '<h2 style="font-size:20px">Photos extérieures et intérieures de la boutique</h2><p>Cliquez sur une photo pour l’ouvrir en grand. Si les images sont masquées par votre messagerie, utilisez les liens sous les photos.</p>';
			$photos = self::shop_photo_ids( $id );
			foreach ( $photos as $index => $photo ) {
				$url = wp_get_attachment_url( $photo );
				$preview = wp_get_attachment_image_url( $photo, 'medium_large' );
				$label = 'Photo de la boutique ' . ( $index + 1 );
				if ( ! $url ) { $message .= '<p>' . esc_html( $label ) . ' : image indisponible.</p>'; continue; }
				$message .= '<p style="margin:20px 0"><a href="' . esc_url( $url ) . '"><img src="' . esc_url( $preview ?: $url ) . '" alt="' . esc_attr( $label ) . '" width="480" style="display:block;width:100%;max-width:480px;height:auto;border:0"></a><a href="' . esc_url( $url ) . '">Ouvrir la photo ' . ( $index + 1 ) . ' en grand</a></p>';
			}
			if ( ! $photos ) { $message .= '<p>Aucune photo disponible dans le dossier.</p>'; }
			$message .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=cswa-vendors' ) ) . '" style="display:inline-block;padding:12px 20px;background:#12694b;color:#ffffff;text-decoration:none;border-radius:6px">Consulter et approuver le marchand</a></p><p>Connectez-vous avec votre compte administrateur pour approuver le marchand.</p><p>Congo-Shop — Commande WhatsApp</p></div></body></html>';

			if ( is_email( $recipient ) ) {
				$submitted = (bool) wp_mail( $recipient, '[' . $site . '] Nouveau marchand à approuver', $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
			}
		} catch ( Throwable $e ) {
			// A mail transport failure must not invalidate an already-created account.
			$submitted = false;
		}
		// "submitted" means accepted by wp_mail, not confirmed inbox delivery.
		update_user_meta( $id, '_cswa_signup_mail_status', $submitted ? 'submitted' : 'failed' );
		return $submitted;
	}


	/** Require a presentation and sanitize optional social links; do not fetch remote URLs. */
	private static function validate_shop_details( $data ) {
		$values = array();
		foreach ( array( 'about', 'facebook', 'instagram' ) as $key ) {
			if ( isset( $data[ $key ] ) && ! is_string( $data[ $key ] ) ) { return new WP_Error( 'details', 'Vérifie les informations de la boutique.' ); }
			$values[ $key ] = trim( $data[ $key ] ?? '' );
		}
		$values['about'] = sanitize_textarea_field( $values['about'] );
		if ( '' === trim( $values['about'] ) || mb_strlen( $values['about'] ) > 3000 ) { return new WP_Error( 'about', 'Présente ton activité : ce champ est obligatoire (3000 caractères maximum).' ); }
		foreach ( array( 'facebook' => array( 'facebook.com', 'fb.com' ), 'instagram' => array( 'instagram.com' ) ) as $key => $domains ) {
			$url = $values[ $key ];
			if ( '' === $url ) { continue; }
			$parts = wp_parse_url( $url );
			$host = strtolower( $parts['host'] ?? '' );
			$allowed = false;
			foreach ( $domains as $domain ) {
				if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) { $allowed = true; }
			}
			if ( strlen( $url ) > 2048 || preg_match( '/[\s<>]/', $url ) || ! $allowed || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
				return new WP_Error( 'social', 'Indique un lien complet et valide vers ' . ucfirst( $key ) . ' (https://…), ou laisse ce champ vide.' );
			}
			$values[ $key ] = esc_url_raw( $url, array( 'https', 'http' ) );
		}
		return $values;
	}

	/** Read current gallery, including photos saved with 3.0.5. */
	private static function shop_photo_ids( $id ) {
		$ids = get_user_meta( $id, '_cswa_shop_photos', true );
		if ( ! is_array( $ids ) ) {
			$ids = array( get_user_meta( $id, '_cswa_shop_exterior', true ), get_user_meta( $id, '_cswa_shop_interior', true ) );
		}
		return array_values( array_filter( array_unique( array_map( 'absint', $ids ) ), function ( $photo ) { return $photo && wp_attachment_is_image( $photo ); } ) );
	}

	/** Validate all images before storing any. A new selection replaces the gallery. */
	private static function prepare_shop_photos( $author = 0 ) {
		$files = array();
		if ( isset( $_FILES['shop_photos'] ) ) {
			$upload = $_FILES['shop_photos'];
			foreach ( array( 'name', 'tmp_name', 'error', 'size', 'type' ) as $key ) {
				if ( ! isset( $upload[ $key ] ) || ! is_array( $upload[ $key ] ) ) { return new WP_Error( 'photo', 'Sélectionne de 1 à 5 photos de la boutique.' ); }
			}
			if ( count( $upload['name'] ) > 5 ) { return new WP_Error( 'photo', 'Maximum 5 photos de la boutique.' ); }
			foreach ( array( 'tmp_name', 'error', 'size', 'type' ) as $key ) {
				if ( array_keys( $upload[ $key ] ) !== array_keys( $upload['name'] ) ) { return new WP_Error( 'photo', 'Fichiers photo invalides.' ); }
			}
			foreach ( $upload['name'] as $i => $name ) {
				$file = array();
				foreach ( array( 'name', 'tmp_name', 'error', 'size', 'type' ) as $key ) {
					if ( ! is_scalar( $upload[ $key ][ $i ] ) ) { return new WP_Error( 'photo', 'Fichier photo invalide.' ); }
					$file[ $key ] = $upload[ $key ][ $i ];
				}
				// Browsers submit one empty entry when no new file is selected.
				if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] && 1 === count( $upload['name'] ) ) { continue; }
				if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] < 1 || (int) $file['size'] > 5 * MB_IN_BYTES || ! is_uploaded_file( $file['tmp_name'] ) ) {
					return new WP_Error( 'photo', 'Photo non reçue ou trop volumineuse : maximum 5 Mo par photo.' );
				}
				$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
				$size = wp_getimagesize( $file['tmp_name'] );
				if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! in_array( wp_get_image_mime( $file['tmp_name'] ), array( 'image/jpeg', 'image/png', 'image/webp' ), true ) || ! $size || $size[0] * $size[1] > 25000000 ) {
					return new WP_Error( 'photo', 'Photo refusée : JPEG, PNG ou WebP, maximum 25 mégapixels.' );
				}
				$files[] = $file;
			}
		}
		if ( ! $files ) {
			if ( $author && self::shop_photo_ids( $author ) ) { return array(); }
			return new WP_Error( 'photo', 'Ajoute au moins une photo extérieure ou intérieure de ta boutique (5 maximum).' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ids = array();
		try {
			foreach ( $files as $file ) {
				$_FILES['cswa_shop_photo_upload'] = $file;
				$id = media_handle_upload( 'cswa_shop_photo_upload', 0, array( 'post_author' => (int) $author, 'post_title' => 'Photo de la boutique' ), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
				if ( is_wp_error( $id ) ) { self::discard_shop_photos( $ids ); return new WP_Error( 'photo', 'Les photos n’ont pas pu être enregistrées. Réessaie avec des images plus légères.' ); }
				$ids[] = $id;
			}
		} catch ( Throwable $e ) {
			self::discard_shop_photos( $ids );
			return new WP_Error( 'photo', 'Les photos n’ont pas pu être enregistrées. Réessaie avec des images plus légères.' );
		} finally {
			unset( $_FILES['cswa_shop_photo_upload'] );
		}
		return $ids;
	}

	private static function discard_shop_photos( $photos ) {
		foreach ( $photos as $photo ) { wp_delete_attachment( $photo, true ); }
	}

	private static function save_shop_details( $id, $details, $photos ) {
		foreach ( $details as $key => $value ) { update_user_meta( $id, '_cswa_' . $key, $value ); }
		foreach ( $photos as $photo ) { wp_update_post( array( 'ID' => $photo, 'post_author' => (int) $id ) ); }
		if ( $photos ) { update_user_meta( $id, '_cswa_shop_photos', array_values( array_map( 'absint', $photos ) ) ); }
	}

	/** Shared registration/profile fields. Existing values are restricted to the owner/admin. */
	public static function shop_detail_fields( $id = 0 ) {
		if ( $id && get_current_user_id() !== (int) $id && ! current_user_can( 'manage_options' ) ) { return; }
		$about = $id ? get_user_meta( $id, '_cswa_about', true ) : '';
		echo '<label>Dites-nous en plus sur votre activité (obligatoire)<textarea name="about" required maxlength="3000" rows="5" placeholder="Présentez votre boutique, votre expérience et vos spécialités">' . esc_textarea( $about ) . '</textarea></label>';
		$photos = $id ? self::shop_photo_ids( $id ) : array();
		echo '<label>Photos extérieures et intérieures de la boutique (1 à 5 photos obligatoires)<input type="file" name="shop_photos[]" multiple ' . ( $photos ? '' : 'required ' ) . 'accept="image/jpeg,image/png,image/webp"></label>';
		if ( $photos ) {
			echo '<div class="csv-images">';
			foreach ( $photos as $photo ) { echo wp_get_attachment_image( $photo, 'thumbnail', false, array( 'alt' => 'Photo de la boutique' ) ); }
			echo '</div><p>Sans nouvelle sélection, les photos actuelles sont conservées. Une nouvelle sélection de 1 à 5 photos remplace toutes les photos du dossier.</p>';
		}
		echo '<p>JPEG, PNG ou WebP : 5 Mo et 25 mégapixels maximum par photo. Ajoutez uniquement des photos de votre boutique destinées à être partagées : leurs liens directs peuvent être accessibles.</p>';
		foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram' ) as $field => $label ) {
			$value = $id ? get_user_meta( $id, '_cswa_' . $field, true ) : '';
			echo '<label>Lien ' . esc_html( $label ) . ' de la boutique (facultatif)<input type="url" name="' . esc_attr( $field ) . '" maxlength="2048" placeholder="https://www.' . esc_attr( $field ) . '.com/votre-boutique" value="' . esc_attr( $value ) . '"></label>';
		}
	}

	/** Administrator-only dossier in the merchant list and user profile. */
	private static function shop_details_html( $id ) {
		if ( ! current_user_can( 'manage_options' ) ) { return ''; }
		$html = '<details><summary>Voir le dossier de la boutique</summary><p>' . nl2br( esc_html( get_user_meta( $id, '_cswa_about', true ) ) ) . '</p>';
		foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram' ) as $field => $label ) {
			$url = get_user_meta( $id, '_cswa_' . $field, true );
			if ( $url ) { $html .= '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a></p>'; }
		}
		foreach ( self::shop_photo_ids( $id ) as $index => $photo ) {
			$url = wp_get_attachment_url( $photo );
			$label = 'Photo de la boutique ' . ( $index + 1 );
			if ( $url ) { $html .= '<p>' . esc_html( $label ) . '<br><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . wp_get_attachment_image( $photo, 'thumbnail', false, array( 'alt' => $label ) ) . '</a></p>'; }
		}
		return $html . '</details>';
	}

	/** Send only on a real transition from pending/suspended to active. */
	public static function notify_approval( $id, $role, $old_roles ) {
		if ( 'cswa_vendor' !== $role || in_array( 'cswa_vendor', (array) $old_roles, true ) || ! array_intersect( array( 'cswa_pending', 'cswa_suspended' ), (array) $old_roles ) || ! self::active( $id ) ) { return; }
		$user = get_userdata( $id );
		$submitted = false;
		try {
			$site = sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
			$url = self::portal_url();
			$message = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;color:#182c29;line-height:1.6"><h1>Votre compte marchand est approuvé</h1><p>Bonjour ' . esc_html( get_user_meta( $id, '_cswa_owner_name', true ) ) . ',</p><p>Votre boutique <strong>' . esc_html( self::shop_name( $id ) ) . '</strong> est désormais approuvée sur <strong>' . esc_html( $site ) . '</strong>.</p><p>Vous pouvez publier vos produits et consulter vos commandes depuis votre espace marchand.</p><p>Connectez-vous avec votre adresse e-mail <strong>' . esc_html( $user->user_email ) . '</strong> et le mot de passe choisi lors de l’inscription.</p><p><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 20px;background:#12694b;color:#fff;text-decoration:none;border-radius:6px">Accéder à mon espace marchand</a></p><p>Vous pouvez aussi copier ce lien :<br>' . esc_html( $url ) . '</p><p>À bientôt,<br>' . esc_html( $site ) . '</p></body></html>';
			if ( is_email( $user->user_email ) ) { $submitted = (bool) wp_mail( $user->user_email, '[' . $site . '] Votre compte marchand est approuvé', $message, array( 'Content-Type: text/html; charset=UTF-8' ) ); }
		} catch ( Throwable $e ) { $submitted = false; }
		update_user_meta( $id, '_cswa_approval_mail_status', $submitted ? 'submitted' : 'failed' );
	}

	public static function handle_register() {
		if ( is_user_logged_in() || ! check_admin_referer( 'cswa_vendor_register' ) ) { wp_die( 'Action non autorisée.', '', array( 'response' => 403 ) ); }
		$ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
		$key = 'cswa_signup_' . hash( 'sha256', $ip );
		$count = (int) get_transient( $key );
		if ( $count >= 5 ) { self::redirect_result( new WP_Error( 'limit', 'Trop de tentatives. Réessaie dans une heure.' ) ); }
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		$result = self::register( wp_unslash( $_POST ) );
		self::redirect_result( $result, 'Inscription reçue. Ton compte doit être approuvé avant de pouvoir publier. Un e-mail te sera envoyé lors de l’approbation.' );
	}

	private static function redirect_result( $result, $success = 'Modifications enregistrées.', $tab = '' ) {
		$message = is_wp_error( $result ) ? $result->get_error_message() : $success;
		wp_safe_redirect( add_query_arg( array( 'cswa_message' => $message, 'cswa_error' => is_wp_error( $result ) ? '1' : '0', 'view' => $tab ), self::portal_url() ) );
		exit;
	}

	public static function owns_product( $id, $vendor ) {
		return self::enabled() && self::active( $vendor ) && 'product' === get_post_type( $id ) && (int) $vendor === self::product_vendor( $id );
	}

	public static function owns_order( $order, $vendor ) {
		return self::enabled() && self::active( $vendor ) && $order instanceof WC_Order && 'cswa' === $order->get_created_via() && 'yes' === $order->get_meta( '_cswa_ready' ) && (int) $vendor === (int) $order->get_meta( '_cswa_vendor_id' );
	}

	public static function save_product( $data, $vendor ) {
		if ( ! self::enabled() || ! self::active( $vendor ) ) { return new WP_Error( 'denied', 'Accès marchand non autorisé.' ); }
		$id = absint( $data['product_id'] ?? 0 );
		if ( $id && ! self::owns_product( $id, $vendor ) ) { return new WP_Error( 'denied', 'Ce produit ne t’appartient pas.' ); }
		$product = $id ? wc_get_product( $id ) : new WC_Product_Simple();
		if ( ! $product || ! $product->is_type( 'simple' ) ) { return new WP_Error( 'type', 'Ce produit à variantes doit être modifié par l’administrateur.' ); }
		$name = sanitize_text_field( $data['name'] ?? '' );
		$price_input = str_replace( ',', '.', trim( (string) ( $data['price'] ?? '' ) ) );
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 200 || ! preg_match( '/^\d{1,10}(\.\d{1,4})?$/', $price_input ) ) { return new WP_Error( 'fields', 'Indique un titre et un prix valide, supérieur ou égal à zéro.' ); }
		$status = in_array( $data['status'] ?? '', array( 'draft', 'publish' ), true ) ? $data['status'] : 'draft';
		if ( 'publish' === $status && ! CSWA_Number::normalize( get_user_meta( $vendor, '_cswa_whatsapp', true ) ) ) { return new WP_Error( 'phone', 'Renseigne ton numéro WhatsApp avant de publier.' ); }
		$category = absint( $data['category'] ?? 0 );
		if ( $category && ! term_exists( $category, 'product_cat' ) ) { return new WP_Error( 'category', 'Catégorie inconnue.' ); }
		try {
			$product->set_name( $name );
			$product->set_description( wp_kses_post( $data['description'] ?? '' ) );
			$product->set_short_description( wp_kses_post( $data['short_description'] ?? '' ) );
			$product->set_regular_price( wc_format_decimal( $price_input ) );
			// Preserve administrator-managed promotions and stock quantities.
			$product->set_price( $product->is_on_sale( 'edit' ) ? $product->get_sale_price() : wc_format_decimal( $price_input ) );
			$product->set_status( $status );
			$product->set_stock_status( empty( $data['available'] ) ? 'outofstock' : 'instock' );
			$product->set_category_ids( $category ? array( $category ) : array() );
			$product->update_meta_data( '_cswa_vendor_id', (int) $vendor );
			if ( ! empty( $data['remove_images'] ) ) { $product->set_image_id( 0 ); $product->set_gallery_image_ids( array() ); }
			$id = $product->save();
			wp_update_post( array( 'ID' => $id, 'post_author' => (int) $vendor ) );
			CSWA_Number::flush();
			return $id;
		} catch ( Throwable $e ) { return new WP_Error( 'save', 'Le produit n’a pas pu être enregistré.' ); }
	}

	/** Upload only real JPEG/PNG/WebP images, maximum five files of 5 MB. */
	public static function upload_images( $id, $vendor ) {
		if ( ! self::owns_product( $id, $vendor ) ) { return new WP_Error( 'denied', 'Accès refusé.' ); }
		if ( empty( $_FILES['photos']['name'][0] ) ) { return true; }
		$files = $_FILES['photos'];
		if ( ! is_array( $files['name'] ) || count( $files['name'] ) > 5 ) { return new WP_Error( 'images', 'Maximum 5 images par envoi.' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		foreach ( $files['name'] as $i => $name ) {
			if ( UPLOAD_ERR_OK !== (int) $files['error'][$i] || $files['size'][$i] > 5 * MB_IN_BYTES || ! is_uploaded_file( $files['tmp_name'][$i] ) ) { return new WP_Error( 'image', 'Image refusée : fichier incomplet ou supérieur à 5 Mo.' ); }
			$check = wp_check_filetype_and_ext( $files['tmp_name'][$i], $name, array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
			if ( empty( $check['type'] ) || ! in_array( wp_get_image_mime( $files['tmp_name'][$i] ), array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { return new WP_Error( 'image', 'Formats acceptés : JPEG, PNG et WebP.' ); }
		}
		$product = wc_get_product( $id ); $ids = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
		if ( count( $ids ) + count( $files['name'] ) > 5 ) { return new WP_Error( 'images', 'Maximum 5 images par produit. Retire les anciennes images pour les remplacer.' ); }
		foreach ( $files['name'] as $i => $name ) {
			$_FILES['cswa_photo'] = array( 'name' => $name, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i] );
			$image = media_handle_upload( 'cswa_photo', $id, array( 'post_author' => $vendor ), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
			unset( $_FILES['cswa_photo'] );
			if ( is_wp_error( $image ) ) { return new WP_Error( 'image', 'Produit enregistré, mais une image n’a pas pu être ajoutée.' ); }
			$ids[] = $image;
			$product->set_image_id( $ids[0] ); $product->set_gallery_image_ids( array_slice( $ids, 1 ) ); $product->save();
		}
		return true;
	}

	public static function transitions( $status ) {
		$map = array( 'cswa-new' => array( 'cswa-confirmed', 'cswa-cancelled' ), 'cswa-confirmed' => array( 'cswa-ready', 'cswa-delivered', 'cswa-cancelled' ), 'cswa-ready' => array( 'cswa-delivered', 'cswa-cancelled' ) );
		return $map[ $status ] ?? array();
	}

	public static function update_order( $id, $status, $vendor ) {
		$order = wc_get_order( $id );
		if ( ! self::owns_order( $order, $vendor ) ) { return new WP_Error( 'denied', 'Cette commande ne t’appartient pas.' ); }
		if ( $status === $order->get_status() ) { return true; }
		if ( ! in_array( $status, self::transitions( $order->get_status() ), true ) ) { return new WP_Error( 'status', 'Ce changement de statut n’est pas autorisé.' ); }
		if ( ! $order->update_status( $status, 'Mise à jour par le marchand ' . self::shop_name( $vendor ) . '.' ) ) { return new WP_Error( 'save', 'Le statut n’a pas pu être enregistré. Recharge la page et réessaie.' ); }
		return true;
	}

	public static function handle_save() {
		if ( ! is_user_logged_in() || ! self::enabled() || ! self::active( get_current_user_id() ) || ! check_admin_referer( 'cswa_vendor_save' ) ) { wp_die( 'Accès non autorisé.', '', array( 'response' => 403 ) ); }
		$data = wp_unslash( $_POST ); $vendor = get_current_user_id();
		$kind = sanitize_key( $data['kind'] ?? '' );
		if ( 'product' === $kind ) {
			$result = self::save_product( $data, $vendor );
			if ( ! is_wp_error( $result ) ) {
				$images = self::upload_images( $result, $vendor );
				if ( is_wp_error( $images ) ) { $result = $images; }
			}
			self::redirect_result( $result, 'Produit enregistré.', 'products' );
		} elseif ( 'order' === $kind ) {
			self::redirect_result( self::update_order( absint( $data['order_id'] ?? 0 ), sanitize_key( $data['status'] ?? '' ), $vendor ), 'Statut mis à jour.', 'orders' );
		} elseif ( 'profile' === $kind ) {
			$name = sanitize_text_field( $data['shop'] ?? '' ); $phone = CSWA_Number::normalize( $data['phone'] ?? '' );
			if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 100 || ! $phone ) { self::redirect_result( new WP_Error( 'profile', 'Vérifie le nom de la boutique et le numéro WhatsApp.' ), '', 'profile' ); }
			$owner = sanitize_text_field( $data['owner_name'] ?? get_user_meta( $vendor, '_cswa_owner_name', true ) );
			$activity = sanitize_textarea_field( $data['activity'] ?? get_user_meta( $vendor, '_cswa_activity', true ) );
			if ( mb_strlen( $owner ) > 120 || mb_strlen( $activity ) > 1000 ) { self::redirect_result( new WP_Error( 'profile', 'Nom du propriétaire : 120 caractères maximum. Activité : 1000 caractères maximum.' ), '', 'profile' ); }
			$details = self::validate_shop_details( $data );
			if ( is_wp_error( $details ) ) { self::redirect_result( $details, '', 'profile' ); }
			$photos = self::prepare_shop_photos( $vendor );
			if ( is_wp_error( $photos ) ) { self::redirect_result( $photos, '', 'profile' ); }
			self::save_shop_details( $vendor, $details, $photos );
			update_user_meta( $vendor, '_cswa_owner_name', $owner );
			update_user_meta( $vendor, '_cswa_activity', $activity );
			update_user_meta( $vendor, '_cswa_shop_name', $name ); update_user_meta( $vendor, '_cswa_whatsapp', $phone ); CSWA_Number::flush();
			self::redirect_result( true, 'Boutique mise à jour.', 'profile' );
		}
		wp_die( 'Action inconnue.', '', array( 'response' => 400 ) );
	}

	public static function handle_admin() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cswa_vendor_admin' ) ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		$action = sanitize_key( $_POST['operation'] ?? '' );
		if ( 'page' === $action ) {
			$id = (int) get_option( 'cswa_vendor_page_id' );
			if ( ! $id || 'publish' !== get_post_status( $id ) ) {
				$id = wp_insert_post( array( 'post_title' => 'Espace marchand', 'post_name' => 'espace-marchand', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[cswa_vendor_dashboard]' ), true );
				if ( is_wp_error( $id ) ) { wp_die( esc_html( $id->get_error_message() ) ); }
				update_option( 'cswa_vendor_page_id', $id, false );
			}
		} else {
			$user = get_userdata( absint( $_POST['vendor_id'] ?? 0 ) );
			if ( ! $user || ! array_intersect( array( 'cswa_pending', 'cswa_vendor', 'cswa_suspended' ), $user->roles ) || user_can( $user, 'manage_options' ) ) { wp_die( 'Compte non marchand.' ); }
			if ( 'approve' === $action ) { $user->set_role( 'cswa_vendor' ); }
			elseif ( 'suspend' === $action ) { $user->set_role( 'cswa_suspended' ); }
			CSWA_Number::flush();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=cswa-vendors' ) ); exit;
	}

	public static function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$query = new WP_User_Query( array( 'role__in' => array( 'cswa_pending', 'cswa_vendor', 'cswa_suspended' ), 'number' => 30, 'paged' => $page, 'orderby' => 'registered', 'order' => 'DESC' ) );
		echo '<div class="wrap"><h1>Marchands</h1><p>Les inscriptions restent en attente jusqu’à ton approbation. Seuls les marchands approuvés peuvent publier et consulter leurs commandes.</p>';
		if ( ! self::enabled() ) { echo '<div class="notice notice-warning inline"><p>Espace marchand désactivé. Choisis le mode multimarchand et active l’espace dans les réglages.</p></div>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cswa_vendor_admin"><input type="hidden" name="operation" value="page">'; wp_nonce_field( 'cswa_vendor_admin' );
		submit_button( 'Créer / retrouver la page Espace marchand', 'secondary', 'submit', false ); echo '</form>';
		if ( get_option( 'cswa_vendor_page_id' ) ) { echo '<p><a href="' . esc_url( self::portal_url() ) . '">Ouvrir l’espace marchand</a></p>'; }
		echo '<p>Pour rattacher un produit existant : Produits → Modifier → Marchand WhatsApp. Les anciennes commandes ne sont pas attribuées automatiquement.</p><table class="widefat striped"><thead><tr><th>Boutique</th><th>Propriétaire</th><th>Activité / produits</th><th>Compte</th><th>WhatsApp</th><th>État</th><th>Action</th></tr></thead><tbody>';
		foreach ( $query->get_results() as $user ) {
			$active = self::active( $user->ID );
			$pending = in_array( 'cswa_pending', $user->roles, true );
			echo '<tr><td>' . esc_html( self::shop_name( $user->ID ) ) . self::shop_details_html( $user->ID ) . '</td><td>' . esc_html( get_user_meta( $user->ID, '_cswa_owner_name', true ) ) . '</td><td>' . nl2br( esc_html( get_user_meta( $user->ID, '_cswa_activity', true ) ) ) . '</td><td><a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->user_email ) . '</a></td><td>' . esc_html( get_user_meta( $user->ID, '_cswa_whatsapp', true ) ) . '</td><td>' . esc_html( $active ? 'Approuvé' : ( $pending ? 'À approuver' : 'Suspendu' ) ) . ( $pending && 'failed' === get_user_meta( $user->ID, '_cswa_signup_mail_status', true ) ? '<br><small>Notification e-mail non transmise. Vérifiez l’adresse d’alerte et l’envoi des e-mails du site.</small>' : '' ) . ( $active && 'failed' === get_user_meta( $user->ID, '_cswa_approval_mail_status', true ) ? '<br><small>E-mail d’approbation non transmis. Le compte reste approuvé. Vérifiez l’envoi des e-mails du site.</small>' : '' ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'cswa_vendor_admin' );
			echo '<input type="hidden" name="action" value="cswa_vendor_admin"><input type="hidden" name="vendor_id" value="' . (int) $user->ID . '"><input type="hidden" name="operation" value="' . ( $active ? 'suspend' : 'approve' ) . '"><button class="button">' . ( $active ? 'Suspendre' : 'Approuver' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
		echo wp_kses_post( (string) paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'total' => ceil( $query->get_total() / 30 ), 'current' => $page ) ) );
		echo '<p>Les statuts WhatsApp suivent le traitement de la demande ; ils ne déclenchent ni paiement ni mouvement automatique de stock. Les statistiques de ventes WooCommerce standard restent distinctes.</p></div>';
	}

	public static function profile_fields( $user ) {
		if ( ! current_user_can( 'manage_options' ) || ! array_intersect( array( 'cswa_pending', 'cswa_vendor', 'cswa_suspended' ), $user->roles ) ) { return; }
		echo self::shop_details_html( $user->ID );
		wp_nonce_field( 'cswa_vendor_profile', 'cswa_profile_nonce' );
		echo '<p><label>Nom du propriétaire<br><input name="cswa_owner_name" maxlength="120" value="' . esc_attr( get_user_meta( $user->ID, '_cswa_owner_name', true ) ) . '"></label></p><p><label>Activité ou produits vendus<br><textarea name="cswa_activity" maxlength="1000" rows="3" cols="50">' . esc_textarea( get_user_meta( $user->ID, '_cswa_activity', true ) ) . '</textarea></label></p>';
		echo '<h2>Boutique WhatsApp</h2><table class="form-table"><tr><th>Nom</th><td><input name="cswa_shop" value="' . esc_attr( self::shop_name( $user->ID ) ) . '"></td></tr><tr><th>WhatsApp international</th><td><input name="cswa_phone" value="' . esc_attr( get_user_meta( $user->ID, '_cswa_whatsapp', true ) ) . '"></td></tr></table>';
	}

	public static function save_profile( $id ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $id ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cswa_profile_nonce'] ?? '' ) ), 'cswa_vendor_profile' ) ) { return; }
		if ( isset( $_POST['cswa_owner_name'] ) ) { update_user_meta( $id, '_cswa_owner_name', mb_substr( sanitize_text_field( wp_unslash( $_POST['cswa_owner_name'] ) ), 0, 120 ) ); }
		if ( isset( $_POST['cswa_activity'] ) ) { update_user_meta( $id, '_cswa_activity', mb_substr( sanitize_textarea_field( wp_unslash( $_POST['cswa_activity'] ) ), 0, 1000 ) ); }
		update_user_meta( $id, '_cswa_shop_name', sanitize_text_field( wp_unslash( $_POST['cswa_shop'] ?? '' ) ) );
		update_user_meta( $id, '_cswa_whatsapp', CSWA_Number::normalize( wp_unslash( $_POST['cswa_phone'] ?? '' ) ) ); CSWA_Number::flush();
	}

	public static function metabox() {
		if ( current_user_can( 'manage_options' ) && self::enabled() ) { add_meta_box( 'cswa-vendor', 'Marchand WhatsApp', array( __CLASS__, 'assignment_field' ), 'product', 'side' ); }
	}

	public static function assignment_field( $post ) {
		wp_nonce_field( 'cswa_assign_vendor', 'cswa_assign_nonce' );
		echo '<select name="cswa_vendor_id"><option value="0">Gestion directe par le site</option>';
		foreach ( get_users( array( 'role__in' => array( 'cswa_vendor', 'cswa_suspended' ), 'orderby' => 'display_name' ) ) as $user ) {
			echo '<option value="' . (int) $user->ID . '" ' . selected( self::product_vendor( $post->ID ), $user->ID, false ) . '>' . esc_html( self::shop_name( $user->ID ) ) . '</option>';
		}
		echo '</select><p>Le numéro du compte marchand devient le destinataire WhatsApp. Sans attribution, le routage historique est conservé.</p>';
	}

	public static function save_assignment( $id ) {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cswa_assign_nonce'] ?? '' ) ), 'cswa_assign_vendor' ) ) { return; }
		$vendor = absint( $_POST['cswa_vendor_id'] ?? 0 );
		$user = get_userdata( $vendor );
		if ( $vendor && ( ! $user || ! array_intersect( array( 'cswa_vendor', 'cswa_suspended' ), $user->roles ) ) ) { return; }
		update_post_meta( $id, '_cswa_vendor_id', $vendor ); CSWA_Number::flush();
	}

	public static function render() {
		if ( ! self::enabled() ) { return '<p>L’espace marchand n’est pas activé sur ce site.</p>'; }
		wp_enqueue_style( 'cswa-vendor', CSWA_URL . 'assets/cswa-vendor.css', array(), CSWA_VERSION );
		ob_start();
		include CSWA_DIR . 'templates/vendor-dashboard.php';
		return ob_get_clean();
	}
}
