<?php
/** Account provisioning is explicit, administrator-only and never sends credentials. */
defined( 'ABSPATH' ) || exit;
trait CSWA_Vendor_Accounts {
	private static $phone_login_error = null;
	private static function merchant_account( $user ) {
		return $user && ! user_can( $user, 'manage_options' ) && array_intersect( array( 'cswa_pending', 'cswa_vendor', 'cswa_suspended' ), (array) $user->roles );
	}

	/** Keep each configured line separate; a shop name alone never identifies an account. */
	public static function legacy_merchants() {
		$result = array();
		foreach ( preg_split( '/[\r\n]+/', (string) CSWA_Plugin::get_setting( 'vendor_map', '' ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) { continue; }
			$parts = preg_split( '/\s*[=:|]\s*/', $line, 2 );
			if ( count( $parts ) !== 2 ) { continue; }
			$reverse = (bool) preg_match( '/^[+0-9 ().,-]+$/', $parts[0] );
			$name = sanitize_text_field( $parts[ $reverse ? 1 : 0 ] );
			$numbers = array();
			foreach ( explode( ',', $parts[ $reverse ? 0 : 1 ] ) as $raw ) {
				$number = CSWA_Number::normalize( $raw );
				if ( $number ) { $numbers[] = $number; }
			}
			$numbers = array_values( array_unique( $numbers ) );
			if ( ! $name || ! $numbers ) { continue; }
			$key = hash( 'sha256', $name . '|' . implode( ',', $numbers ) );
			$result[ $key ] = array( 'name' => $name, 'numbers' => $numbers );
		}
		return $result;
	}

	/** Include non-merchants too: never take over an existing login or phone. */
	public static function phone_accounts( $phone ) {
		$ids = array();
		$login = get_user_by( 'login', $phone );
		if ( $login ) { $ids[] = (int) $login->ID; }
		foreach ( array( '_cswa_login_phone', '_cswa_whatsapp' ) as $key ) {
			foreach ( get_users( array( 'meta_key' => $key, 'meta_value' => $phone, 'fields' => 'ID' ) ) as $id ) { $ids[] = (int) $id; }
		}
		return array_values( array_unique( $ids ) );
	}

	/** Map phone aliases into WordPress' normal password authentication and filters. */
	public static function authenticate_phone( $user, $login, $password ) {
		self::$phone_login_error = null;
		if ( null !== $user || ! self::enabled() || ! is_string( $login ) || ! preg_match( '/^\+?[0-9 () .-]+$/', $login ) ) { return $user; }
		$phone = CSWA_Number::normalize( $login );
		if ( ! $phone ) { return $user; }
		$matches = get_users( array( 'meta_key' => '_cswa_login_phone', 'meta_value' => $phone, 'fields' => 'ID' ) );
		if ( ! $matches ) { return $user; }
		$ids = self::phone_accounts( $phone );
		if ( count( $matches ) !== 1 || count( $ids ) !== 1 || (int) $matches[0] !== $ids[0] ) { return self::$phone_login_error = new WP_Error( 'cswa_login', 'Connexion impossible avec ce numéro. Contacte l’administrateur.' ); }
		$account = get_userdata( $ids[0] );
		if ( ! self::merchant_account( $account ) ) { return self::$phone_login_error = new WP_Error( 'cswa_login', 'Connexion impossible avec ce numéro.' ); }
		return wp_authenticate_username_password( null, $account->user_login, $password );
	}

	/** Core login callbacks must not override a rejected ambiguous alias. */
	public static function guard_phone_login( $user ) {
		return self::$phone_login_error ?: $user;
	}

	public static function account_link( $id ) {
		return add_query_arg( 'cswa_shop', (int) $id, self::portal_url() );
	}

	public static function account_label( $user ) {
		$phone = get_user_meta( $user->ID, '_cswa_whatsapp', true );
		return self::shop_name( $user->ID ) . ' — ' . $phone . ' — #' . $user->ID . ( in_array( 'cswa_suspended', $user->roles, true ) ? ' (suspendu)' : '' );
	}

	/** POST-only entry point with a global lock to prevent simultaneous imports. */
	public static function handle_account_admin() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'create_users' ) || ! self::enabled() ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'cswa_account_admin' );
		if ( ! add_option( 'cswa_account_import_lock', time(), '', false ) ) { wp_die( 'Une intégration est déjà en cours. Réessaie plus tard.' ); }
		try { $result = self::provision_legacy( wp_unslash( $_POST ) ); }
		finally { delete_option( 'cswa_account_import_lock' ); }
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=cswa-vendors&edit_vendor=' . (int) $result ) ); exit;
	}

	public static function provision_legacy( $data ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'create_users' ) || ! self::enabled() ) { return new WP_Error( 'denied', 'Accès refusé.' ); }
		$rows = self::legacy_merchants();
		$key = is_string( $data['legacy_key'] ?? null ) ? $data['legacy_key'] : '';
		if ( ! isset( $rows[ $key ] ) ) { return new WP_Error( 'legacy', 'Cette ligne a changé. Recharge la liste des marchands.' ); }
		$row = $rows[ $key ];
		$phone = is_string( $data['primary_phone'] ?? null ) ? CSWA_Number::normalize( $data['primary_phone'] ) : '';
		if ( ! in_array( $phone, $row['numbers'], true ) ) { return new WP_Error( 'phone', 'Choisis un numéro principal dans cette ligne.' ); }
		$linked = get_users( array( 'meta_key' => '_cswa_legacy_key', 'meta_value' => $key, 'fields' => 'ID' ) );
		if ( $linked ) { return new WP_Error( 'linked', 'Cette boutique est déjà rattachée. Modifie sa fiche existante.' ); }
		$matches = array();
		foreach ( $row['numbers'] as $number ) { $matches = array_merge( $matches, self::phone_accounts( $number ) ); }
		$matches = array_values( array_unique( $matches ) );
		$existing = absint( $data['existing_id'] ?? 0 );
		if ( $matches ) {
			if ( count( $matches ) !== 1 || $existing !== $matches[0] ) { return new WP_Error( 'collision', 'Numéro déjà utilisé : sélectionne le compte correspondant. Plusieurs comptes doivent être vérifiés séparément ; aucune fusion automatique.' ); }
			$user = get_userdata( $existing );
			if ( ! self::merchant_account( $user ) || ! current_user_can( 'edit_user', $existing ) ) { return new WP_Error( 'collision', 'Ce numéro appartient à un compte qui ne peut pas être rattaché ici.' ); }
			$alias = get_user_meta( $existing, '_cswa_login_phone', true );
			if ( $alias && $alias !== $phone ) { return new WP_Error( 'alias', 'Ce compte possède déjà un autre numéro de connexion. Conserve son accès existant.' ); }
			$id = $existing; // Preserve password, role, profile, products and historical orders.
		} else {
			if ( $existing ) { return new WP_Error( 'collision', 'Le compte choisi ne correspond pas au numéro.' ); }
			$password = $data['initial_password'] ?? '';
			if ( ! is_string( $password ) || strlen( $password ) < 12 || strlen( $password ) > 200 ) { return new WP_Error( 'password', 'Choisis un mot de passe initial personnel de 12 à 200 caractères.' ); }
			$id = wp_insert_user( array( 'user_login' => $phone, 'user_pass' => $password, 'user_email' => '', 'user_nicename' => 'boutique-' . wp_generate_uuid4(), 'display_name' => $row['name'], 'role' => 'cswa_vendor' ) );
			if ( is_wp_error( $id ) ) { return $id; }
			update_user_meta( $id, '_cswa_shop_name', $row['name'] );
			update_user_meta( $id, '_cswa_whatsapp', $phone );
		}
		update_user_meta( $id, '_cswa_login_phone', $phone );
		update_user_meta( $id, '_cswa_legacy_key', $key );
		update_user_meta( $id, '_cswa_legacy_numbers', $row['numbers'] );
		CSWA_Number::flush();
		return $id;
	}

	public static function legacy_admin_panel() {
		if ( ! self::enabled() ) { return; }
		echo '<h2>Marchands des réglages à intégrer</h2><p>Choisis le numéro principal de connexion et un mot de passe initial différent pour chaque nouveau compte. Note-le pour le transmettre au marchand : il ne sera pas réaffiché. Les comptes existants conservent leur mot de passe et leur état. Les autres numéros restent dans les réglages pour le routage historique.</p>';
		foreach ( self::legacy_merchants() as $key => $row ) {
			$linked = get_users( array( 'meta_key' => '_cswa_legacy_key', 'meta_value' => $key, 'fields' => 'ID' ) );
			if ( $linked ) { continue; }
			$matches = array();
			foreach ( $row['numbers'] as $number ) { $matches = array_merge( $matches, self::phone_accounts( $number ) ); }
			$matches = array_values( array_unique( $matches ) );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="padding:16px;background:#fff;border:1px solid #ddd;margin:12px 0"><strong>' . esc_html( $row['name'] ) . '</strong> — ' . esc_html( implode( ', ', $row['numbers'] ) );
			wp_nonce_field( 'cswa_account_admin' );
			echo '<input type="hidden" name="action" value="cswa_account_admin"><input type="hidden" name="legacy_key" value="' . esc_attr( $key ) . '"><p><label>Numéro principal <select name="primary_phone">';
			foreach ( $row['numbers'] as $number ) { echo '<option value="' . esc_attr( $number ) . '">' . esc_html( $number ) . '</option>'; }
			echo '</select></label></p>';
			if ( $matches ) {
				echo '<p><label>Compte à rattacher <select name="existing_id"><option value="0">Choisir après vérification</option>';
				foreach ( $matches as $id ) { $u = get_userdata( $id ); if ( self::merchant_account( $u ) ) { echo '<option value="' . (int) $id . '">' . esc_html( self::account_label( $u ) ) . '</option>'; } }
				echo '</select></label></p><p>Si plusieurs comptes utilisent ces numéros, corrige leurs coordonnées avant de rattacher cette boutique.</p>';
			} else { echo '<p><label>Mot de passe initial <input type="password" name="initial_password" required minlength="12" maxlength="200" autocomplete="new-password"></label></p>'; }
			submit_button( $matches ? 'Rattacher le compte existant' : 'Créer le compte approuvé', 'secondary', 'submit', false );
			echo '</form>';
		}
	}

	public static function account_edit_page( $id ) {
		$user = get_userdata( $id );
		if ( ! self::merchant_account( $user ) || ! current_user_can( 'edit_user', $id ) ) { wp_die( 'Compte non modifiable.' ); }
		echo '<div class="wrap"><h1>Modifier le marchand</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=cswa-vendors' ) ) . '">Retour aux marchands</a></p><p>Lien à transmettre : <a href="' . esc_url( self::account_link( $id ) ) . '">' . esc_html( self::account_link( $id ) ) . '</a></p><p>Identifiant de connexion : <strong>' . esc_html( get_user_meta( $id, '_cswa_login_phone', true ) ?: ( $user->user_email ?: $user->user_login ) ) . '</strong>. Le numéro de connexion reste stable si tu modifies le numéro de réception WhatsApp.</p><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cswa_account_edit_' . $id );
		echo '<input type="hidden" name="action" value="cswa_account_edit"><input type="hidden" name="vendor_id" value="' . (int) $id . '"><table class="form-table">';
		$fields = array( 'shop' => 'Boutique', 'owner_name' => 'Propriétaire', 'phone' => 'Numéro WhatsApp international', 'email' => 'Adresse e-mail', 'activity' => 'Activité ou produits vendus', 'about' => 'Présentation', 'facebook' => 'Facebook', 'instagram' => 'Instagram' );
		foreach ( $fields as $key => $label ) {
			$value = 'shop' === $key ? self::shop_name( $id ) : ( 'email' === $key ? $user->user_email : get_user_meta( $id, 'phone' === $key ? '_cswa_whatsapp' : '_cswa_' . $key, true ) );
			echo '<tr><th><label for="cswa-edit-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( in_array( $key, array( 'activity', 'about' ), true ) ) { echo '<textarea class="large-text" rows="4" id="cswa-edit-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>'; }
			else { echo '<input class="regular-text" id="cswa-edit-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . ( 'email' === $key ? 'email' : 'text' ) . '" value="' . esc_attr( $value ) . '">'; }
			echo '</td></tr>';
		}
		echo '<tr><th>Photos</th><td>';
		foreach ( self::shop_photo_ids( $id ) as $photo ) { echo wp_get_attachment_image( $photo, 'thumbnail' ); }
		echo '<p><input type="file" name="shop_photos[]" multiple accept="image/jpeg,image/png,image/webp"></p><p>Sans sélection, les photos sont conservées. Une nouvelle sélection remplace la galerie : 1 à 5 photos, 5 Mo par photo.</p></td></tr><tr><th>Nouveau mot de passe</th><td><input type="password" name="initial_password" minlength="12" maxlength="200" autocomplete="new-password"><p>Laisse vide pour conserver le mot de passe. Un changement déconnecte le marchand ; transmets-lui le nouveau mot de passe.</p></td></tr></table>';
		submit_button( 'Enregistrer le marchand' ); echo '</form></div>';
	}

	public static function handle_account_edit() {
		$id = absint( $_POST['vendor_id'] ?? 0 ); $user = get_userdata( $id );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $id ) || ! self::merchant_account( $user ) ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'cswa_account_edit_' . $id );
		$data = wp_unslash( $_POST );
		foreach ( array( 'shop', 'owner_name', 'phone', 'email', 'activity', 'about', 'facebook', 'instagram', 'initial_password' ) as $key ) { if ( ! isset( $data[$key] ) || ! is_string( $data[$key] ) ) { wp_die( 'Informations invalides.' ); } }
		$name = sanitize_text_field( $data['shop'] ); $phone = CSWA_Number::normalize( $data['phone'] );
		$email = trim( $data['email'] ); $password = $data['initial_password'];
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 100 || ! $phone || mb_strlen( $data['owner_name'] ) > 120 || mb_strlen( $data['activity'] ) > 1000 || ( '' !== $email && ! is_email( $email ) ) || ( '' !== $password && ( strlen( $password ) < 12 || strlen( $password ) > 200 ) ) ) { wp_die( 'Vérifie les coordonnées et le mot de passe (12 à 200 caractères).', '', array( 'back_link' => true ) ); }
		$owners = self::phone_accounts( $phone );
		if ( array_diff( $owners, array( $id ) ) ) { wp_die( 'Ce numéro appartient déjà à un autre compte. Aucun changement enregistré.' ); }
		// Existing legacy dossiers may be incomplete; do not invent mandatory values.
		$blank = '' === trim( $data['about'] );
		$check = $data; if ( $blank ) { $check['about'] = 'Validation'; }
		$details = self::validate_shop_details( $check );
		if ( is_wp_error( $details ) ) { wp_die( esc_html( $details->get_error_message() ), '', array( 'back_link' => true ) ); }
		if ( $blank ) { $details['about'] = ''; }
		if ( $email && email_exists( $email ) && (int) email_exists( $email ) !== $id ) { wp_die( 'Cette adresse e-mail est déjà utilisée.' ); }
		$photos = array();
		if ( ! empty( $_FILES['shop_photos']['name'][0] ) ) { $photos = self::prepare_shop_photos( $id ); if ( is_wp_error( $photos ) ) { wp_die( esc_html( $photos->get_error_message() ) ); } }
		$args = array( 'ID' => $id, 'user_email' => $email, 'display_name' => $name );
		if ( '' !== $password ) { $args['user_pass'] = $password; }
		$result = wp_update_user( $args );
		if ( is_wp_error( $result ) ) { self::discard_shop_photos( $photos ); wp_die( esc_html( $result->get_error_message() ) ); }
		if ( '' !== $password ) { WP_Session_Tokens::get_instance( $id )->destroy_all(); }
		update_user_meta( $id, '_cswa_shop_name', $name ); update_user_meta( $id, '_cswa_whatsapp', $phone );
		update_user_meta( $id, '_cswa_owner_name', sanitize_text_field( $data['owner_name'] ) );
		update_user_meta( $id, '_cswa_activity', sanitize_textarea_field( $data['activity'] ) );
		self::save_shop_details( $id, $details, $photos ); CSWA_Number::flush();
		wp_safe_redirect( admin_url( 'admin.php?page=cswa-vendors&edit_vendor=' . $id ) ); exit;
	}

	public static function change_own_password( $id, $data ) {
		$user = get_userdata( $id );
		if ( $id !== get_current_user_id() || ! self::active( $id ) || ! $user ) { return new WP_Error( 'denied', 'Accès refusé.' ); }
		$old = $data['current_password'] ?? null; $new = $data['new_password'] ?? null; $confirm = $data['confirm_password'] ?? null;
		if ( ! is_string( $old ) || ! is_string( $new ) || ! is_string( $confirm ) || ! wp_check_password( $old, $user->user_pass, $id ) || strlen( $new ) < 12 || strlen( $new ) > 200 || $new !== $confirm ) { return new WP_Error( 'password', 'Vérifie ton mot de passe actuel et les deux saisies du nouveau mot de passe (12 caractères minimum).' ); }
		wp_set_password( $new, $id ); WP_Session_Tokens::get_instance( $id )->destroy_all();
		return true;
	}

	public static function password_form() {
		echo '<div class="csv-card"><h2>Changer mon mot de passe</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cswa_vendor_save"><input type="hidden" name="kind" value="password">';
		wp_nonce_field( 'cswa_vendor_save' );
		echo '<label>Mot de passe actuel<input type="password" name="current_password" required autocomplete="current-password"></label><label>Nouveau mot de passe<input type="password" name="new_password" required minlength="12" maxlength="200" autocomplete="new-password"></label><label>Confirmer le nouveau mot de passe<input type="password" name="confirm_password" required minlength="12" maxlength="200" autocomplete="new-password"></label><p>Tu devras te reconnecter après le changement.</p><button class="csv-button">Changer mon mot de passe</button></form></div>';
	}
}
