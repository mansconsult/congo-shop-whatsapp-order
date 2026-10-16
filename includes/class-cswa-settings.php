<?php
/**
 * Page de reglages et connexion Google.
 *
 * @package CSWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Administration du plugin.
 */
class CSWA_Settings {

	const CAP  = 'manage_options';
	const SLUG = 'cswa';

	/**
	 * Accroche les hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_cswa_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_cswa_oauth_start', array( __CLASS__, 'handle_oauth_start' ) );
		add_action( 'admin_post_cswa_oauth_callback', array( __CLASS__, 'handle_oauth_callback' ) );
		add_action( 'admin_post_cswa_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_cswa_retry', array( __CLASS__, 'handle_retry' ) );
		add_action( 'admin_post_cswa_scan', array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_cswa_repair', array( __CLASS__, 'handle_repair' ) );
		add_action( 'admin_notices', array( __CLASS__, 'sheet_error_notice' ) );
	}

	/**
	 * Entree de menu.
	 *
	 * Menu de premier niveau plutot qu'un sous-menu de WooCommerce : le
	 * sous-menu disparait entierement si le compte n'a pas la capacite du
	 * parent, ce qui rend la page introuvable sans message clair.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			'Commande WhatsApp',
			'Commande WhatsApp',
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-format-chat',
			56
		);
	}

	/**
	 * Avertissement persistant si le classeur est inaccessible.
	 *
	 * @return void
	 */
	public static function sheet_error_notice() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$error = get_option( 'cswa_sheet_error' );
		if ( ! $error ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>Congo-Shop - Commande WhatsApp :</strong> %1$s <a href="%2$s">Ouvrir les reglages</a></p></div>',
			esc_html( $error ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) )
		);
	}

	/**
	 * URL de retour vers la page de reglages.
	 *
	 * @param string $notice Code de message.
	 * @return string
	 */
	private static function back_url( $notice = '' ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		return $notice ? add_query_arg( 'cswa_notice', rawurlencode( $notice ), $url ) : $url;
	}

	/**
	 * Enregistre les reglages.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'cswa_save' ) ) {
			wp_die( 'Action non autorisee.' );
		}

		$post = wp_unslash( $_POST );

		$before = CSWA_Plugin::get_settings();

		$settings = array(
			'whatsapp_number' => preg_replace( '/\D+/', '', (string) ( $post['whatsapp_number'] ?? '' ) ),
			'country_code'    => preg_replace( '/\D+/', '', (string) ( $post['country_code'] ?? '243' ) ),
			'number_meta_key' => sanitize_text_field( $post['number_meta_key'] ?? '' ),
			'form_mode'       => in_array( ( $post['form_mode'] ?? 'auto' ), array( 'auto', 'commerce', 'service' ), true ) ? ( $post['form_mode'] ?? 'auto' ) : 'auto',
			'site_mode'       => ( 'single' === ( $post['site_mode'] ?? '' ) ) ? 'single' : 'marketplace',
			'vendor_dashboard' => empty( $post['vendor_dashboard'] ) ? 0 : 1,
			'vendor_map'      => sanitize_textarea_field( $post['vendor_map'] ?? '' ),
			'communes'        => sanitize_textarea_field( $post['communes'] ?? '' ),
			'vendor_category' => sanitize_text_field( $post['vendor_category'] ?? '' ),
			'site_name'       => sanitize_text_field( $post['site_name'] ?? '' ),
			'site_list'       => sanitize_textarea_field( $post['site_list'] ?? '' ),
			'shared_sheet_id' => CSWA_Google::clean_sheet_id( $post['shared_sheet_id'] ?? '' ),
			'admin_only'      => empty( $post['admin_only'] ) ? 0 : 1,
			'test_mode'       => empty( $post['test_mode'] ) ? 0 : 1,
			'admin_email'     => sanitize_email( $post['admin_email'] ?? '' ),
			'drive_folder_id' => sanitize_text_field( $post['drive_folder_id'] ?? '' ),
			'extra_selectors' => sanitize_textarea_field( $post['extra_selectors'] ?? '' ),
			'rate_limit'      => max( 1, min( 60, absint( $post['rate_limit'] ?? 6 ) ) ),
			'privacy_text'    => sanitize_textarea_field( $post['privacy_text'] ?? '' ),
		);

		update_option( 'cswa_settings', $settings, false );
		CSWA_Number::flush();

		// Le classeur a change : les feuilles connues ne le sont plus.
		if ( $before['shared_sheet_id'] !== $settings['shared_sheet_id'] ) {
			delete_option( 'cswa_known_tabs' );
			delete_option( 'cswa_sheet_error' );
		}

		// La liste des sites a change : les menus deroulants doivent suivre.
		if ( $before['site_list'] !== $settings['site_list'] || $before['site_name'] !== $settings['site_name'] ) {
			if ( CSWA_Google::is_connected() ) {
				CSWA_Google::refresh_validations();
			}
		}

		// Identifiants Google : le secret n'est jamais reaffiche en clair.
		$google     = CSWA_Google::config();
		$client_id  = sanitize_text_field( $post['client_id'] ?? '' );
		$new_secret = trim( (string) ( $post['client_secret'] ?? '' ) );

		$google['client_id'] = $client_id;

		if ( '' !== $new_secret ) {
			$google['client_secret'] = CSWA_Google::encrypt( $new_secret );
		}

		CSWA_Google::save_config( $google );

		wp_safe_redirect( self::back_url( 'saved' ) );
		exit;
	}

	/**
	 * Lance le consentement Google.
	 *
	 * @return void
	 */
	public static function handle_oauth_start() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'cswa_oauth_start' ) ) {
			wp_die( 'Action non autorisee.' );
		}

		$conf = CSWA_Google::config();

		if ( '' === $conf['client_id'] || '' === CSWA_Google::decrypt( $conf['client_secret'] ) ) {
			wp_safe_redirect( self::back_url( 'missing_credentials' ) );
			exit;
		}

		wp_redirect( CSWA_Google::auth_url() );
		exit;
	}

	/**
	 * Recoit le code d'autorisation.
	 *
	 * @return void
	 */
	public static function handle_oauth_callback() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Action non autorisee.' );
		}

		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected = get_transient( 'cswa_oauth_state' );

		if ( ! $state || ! $expected || ! hash_equals( (string) $expected, $state ) ) {
			wp_safe_redirect( self::back_url( 'bad_state' ) );
			exit;
		}

		delete_transient( 'cswa_oauth_state' );

		if ( ! empty( $_GET['error'] ) ) {
			wp_safe_redirect( self::back_url( 'denied' ) );
			exit;
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		if ( ! $code ) {
			wp_safe_redirect( self::back_url( 'denied' ) );
			exit;
		}

		$result = CSWA_Google::exchange_code( $code );

		if ( is_wp_error( $result ) ) {
			set_transient( 'cswa_last_error', $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::back_url( 'oauth_failed' ) );
			exit;
		}

		wp_safe_redirect( self::back_url( 'connected' ) );
		exit;
	}

	/**
	 * Coupe la connexion Google.
	 *
	 * @return void
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'cswa_disconnect' ) ) {
			wp_die( 'Action non autorisee.' );
		}

		$conf                  = CSWA_Google::config();
		$conf['refresh_token'] = '';
		$conf['account']       = '';

		CSWA_Google::save_config( $conf );
		delete_transient( 'cswa_access_token' );

		wp_safe_redirect( self::back_url( 'disconnected' ) );
		exit;
	}

	/**
	 * Relance la file d'attente manuellement.
	 *
	 * @return void
	 */
	public static function handle_retry() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'cswa_retry' ) ) {
			wp_die( 'Action non autorisee.' );
		}

		delete_option( 'cswa_sheet_error' );
		delete_transient( 'cswa_admin_notified' );

		CSWA_Google::process_queue();

		wp_safe_redirect( self::back_url( 'retried' ) );
		exit;
	}

	/**
	 * Cree la feuille du mois si besoin et repose les menus deroulants.
	 *
	 * @return void
	 */
	public static function handle_repair() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'cswa_repair' ) ) {
			wp_die( 'Action non autorisee.' );
		}

		delete_option( 'cswa_known_tabs' );
		delete_option( 'cswa_sheet_error' );
		delete_transient( 'cswa_admin_notified' );

		$result = CSWA_Google::refresh_validations( true );

		if ( is_wp_error( $result ) ) {
			set_transient( 'cswa_last_error', $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::back_url( 'repair_failed' ) );
			exit;
		}

		// Les demandes restees en attente peuvent maintenant partir.
		CSWA_Google::process_queue();

		wp_safe_redirect( self::back_url( 'repaired' ) );
		exit;
	}

	/**
	 * Relance la detection des numeros.
	 *
	 * @return void
	 */
	public static function handle_scan() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'cswa_scan' ) ) {
			wp_die( 'Action non autorisee.' );
		}

		CSWA_Number::flush();
		CSWA_Number::detect_meta_keys( true );
		CSWA_Number::whitelist( true );

		wp_safe_redirect( self::back_url( 'scanned' ) );
		exit;
	}

	/**
	 * Affiche la page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$settings  = CSWA_Plugin::get_settings();
		$google    = CSWA_Google::config();
		$connected = CSWA_Google::is_connected();
		$sheet_id  = CSWA_Google::sheet_id();
		$counts     = CSWA_Store::counts();
		$notice     = isset( $_GET['cswa_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['cswa_notice'] ) ) : '';
		$diag_input = isset( $_GET['cswa_diag'] ) ? sanitize_text_field( wp_unslash( $_GET['cswa_diag'] ) ) : '';

		$messages = array(
			'saved'               => array( 'success', 'Reglages enregistres.' ),
			'connected'           => array( 'success', 'Compte Google connecte.' ),
			'disconnected'        => array( 'warning', 'Compte Google deconnecte. Les demandes continuent d etre enregistrees sur le site.' ),
			'retried'             => array( 'success', 'File d attente relancee.' ),
			'scanned'             => array( 'success', 'Detection des numeros relancee.' ),
			'repaired'            => array( 'success', 'Colonne Origine du numéro supprimée et menus déroulants rétablis.' ),
			'repair_failed'       => array( 'error', 'Reparation impossible : ' . (string) get_transient( 'cswa_last_error' ) ),
			'missing_credentials' => array( 'error', 'Renseigne d abord l identifiant et le secret du client OAuth, puis enregistre.' ),
			'bad_state'           => array( 'error', 'Session de connexion expiree. Relance la connexion.' ),
			'denied'              => array( 'error', 'Autorisation refusee cote Google.' ),
			'oauth_failed'        => array( 'error', 'Echec de la connexion : ' . (string) get_transient( 'cswa_last_error' ) ),
		);
		?>
		<div class="wrap">
			<h1>Congo-Shop - Commande WhatsApp</h1>

			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $messages[ $notice ][0] ); ?> is-dismissible">
					<p><?php echo esc_html( $messages[ $notice ][1] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $settings['admin_only'] ) ) : ?>
				<div class="notice notice-info inline" style="margin:16px 0">
					<p>
						<strong>Mode restreint actif.</strong> Le formulaire ne s'affiche que pour les
						administrateurs connectés. Tes clients voient le bouton WhatsApp habituel et
						peuvent commander normalement. Décoche « Visibilité » plus bas pour l'ouvrir à tous.
					</p>
				</div>
			<?php endif; ?>

			<h2 class="title">Connexion Google</h2>

			<table class="widefat" style="max-width:900px;margin-bottom:20px">
				<tbody>
					<tr>
						<td style="width:240px"><strong>Statut</strong></td>
						<td>
							<?php if ( $connected ) : ?>
								<span style="color:#1a7f37">Connecte</span>
								<?php if ( $google['account'] ) : ?>
									(<?php echo esc_html( $google['account'] ); ?>)
								<?php endif; ?>
							<?php else : ?>
								<span style="color:#b32d2e">Non connecte</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong>URI de redirection autorisee</strong><br><small>A coller dans la console Google</small></td>
						<td><code><?php echo esc_html( CSWA_Google::redirect_uri() ); ?></code></td>
					</tr>
					<tr>
						<td><strong>Portee demandee</strong></td>
						<td><code><?php echo esc_html( CSWA_Google::SCOPE ); ?></code><br>
							<small>Portee non sensible : elle ne donne acces qu aux fichiers crees par ce site.</small></td>
					</tr>
					<tr>
						<td><strong>Classeur</strong></td>
						<td>
							<?php if ( $sheet_id ) : ?>
								<a href="https://docs.google.com/spreadsheets/d/<?php echo esc_attr( $sheet_id ); ?>/edit" target="_blank" rel="noopener">
									<?php echo esc_html( CSWA_Google::SHEET_TITLE ); ?>
								</a>
								<br><small>ID : <code><?php echo esc_html( $sheet_id ); ?></code></small>
								<br><small>Feuille du mois : <code><?php echo esc_html( CSWA_Google::tab_name() ); ?></code>
								<?php if ( CSWA_Plugin::get_setting( 'shared_sheet_id', '' ) ) : ?>
									&middot; classeur partagé, renseigné dans les réglages
								<?php else : ?>
									&middot; classeur créé par ce site. Recopie l'identifiant ci-dessus dans les autres
									sites pour qu'ils écrivent dans le même fichier.
								<?php endif; ?>
								</small>
							<?php else : ?>
								Pas encore créé. Il sera créé automatiquement à la première demande valide.
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong>Demandes</strong></td>
						<td>
							<?php
							printf(
								'%1$d enregistrées dans le classeur, %2$d en attente, %3$d en échec.',
								(int) $counts['ok'],
								(int) $counts['pending'],
								(int) $counts['failed']
							);
							?>
						</td>
					</tr>
					<?php
					// Le dernier echec explique a lui seul la plupart des
					// problemes : il est remonte ici plutot que d'obliger a
					// descendre dans le tableau des demandes.
					$last_error = '';
					foreach ( CSWA_Store::latest( 20 ) as $row ) {
						if ( ! empty( $row['last_error'] ) ) {
							$last_error = $row['ref'] . ' : ' . $row['last_error'];
							break;
						}
					}
					?>
					<?php if ( $last_error ) : ?>
						<tr>
							<td><strong>Dernière erreur</strong></td>
							<td style="color:#b32d2e"><?php echo esc_html( $last_error ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p>
				<?php if ( ! $connected ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="cswa_oauth_start">
						<?php wp_nonce_field( 'cswa_oauth_start' ); ?>
						<button type="submit" class="button button-primary">Connecter le compte Google</button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="cswa_disconnect">
						<?php wp_nonce_field( 'cswa_disconnect' ); ?>
						<button type="submit" class="button">Deconnecter</button>
					</form>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="cswa_retry">
					<?php wp_nonce_field( 'cswa_retry' ); ?>
					<button type="submit" class="button">Relancer la synchronisation</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="cswa_repair">
					<?php wp_nonce_field( 'cswa_repair' ); ?>
					<button type="submit" class="button">Réparer le classeur</button>
				</form>
			</p>

			<hr>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cswa_save">
				<?php wp_nonce_field( 'cswa_save' ); ?>

				<h2 class="title">Identifiants OAuth</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="client_id">ID client</label></th>
						<td><input type="text" class="regular-text" id="client_id" name="client_id" value="<?php echo esc_attr( $google['client_id'] ); ?>" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="client_secret">Secret client</label></th>
						<td>
							<input type="password" class="regular-text" id="client_secret" name="client_secret" value="" autocomplete="new-password" placeholder="<?php echo CSWA_Google::decrypt( $google['client_secret'] ) ? 'Deja enregistre, laisse vide pour conserver' : ''; ?>">
							<p class="description">Stocke chiffre en base. Jamais expose au visiteur.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Classeur partagé</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="site_name">Nom de ce site</label></th>
						<td>
							<input type="text" class="regular-text" id="site_name" name="site_name" value="<?php echo esc_attr( $settings['site_name'] ); ?>" placeholder="<?php echo esc_attr( CSWA_Google::site_name() ); ?>">
							<p class="description">Valeur écrite dans la colonne Site. Doit figurer dans la liste ci-dessous.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="site_list">Liste des sites</label></th>
						<td>
							<textarea id="site_list" name="site_list" rows="5" class="regular-text"><?php echo esc_textarea( $settings['site_list'] ); ?></textarea>
							<p class="description">Un site par ligne. Alimente le menu déroulant de la colonne Site.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="shared_sheet_id">Classeur partagé</label></th>
						<td>
							<input type="text" class="large-text" id="shared_sheet_id" name="shared_sheet_id" value="<?php echo esc_attr( $settings['shared_sheet_id'] ); ?>" placeholder="laisse vide pour créer un classeur propre à ce site">
							<p class="description">
								Identifiant ou URL du classeur déjà créé par un autre site, pour que tous écrivent
								dans le même fichier. Laisse vide sur le premier site, puis recopie ici l'identifiant
								affiché plus haut.
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Formulaire</h2>
<p><label for="form_mode">Parcours du formulaire</label>
<select id="form_mode" name="form_mode">
<?php foreach ( array( 'auto' => 'Automatique (KinPresta si son intégration est présente)', 'commerce' => 'Produits : retrait / livraison', 'service' => 'Prestataires : catégorie Artisant uniquement' ) as $value => $label ) : ?>
<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['form_mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
<?php endforeach; ?>
</select></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="whatsapp_number">Numero de repli</label></th>
						<td>
							<input type="text" class="regular-text" id="whatsapp_number" name="whatsapp_number" value="<?php echo esc_attr( $settings['whatsapp_number'] ); ?>">
							<p class="description">Utilise seulement quand le produit n a pas de numero de marchand. Format international sans le signe plus.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="country_code">Indicatif pays</label></th>
						<td>
							<input type="text" class="small-text" id="country_code" name="country_code" value="<?php echo esc_attr( $settings['country_code'] ); ?>">
							<p class="description">Ajoute aux numeros saisis au format local, par exemple 0812345678 devient 243812345678.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="number_meta_key">Champ du numero</label></th>
						<td>
							<input type="text" class="regular-text" id="number_meta_key" name="number_meta_key" value="<?php echo esc_attr( $settings['number_meta_key'] ); ?>" placeholder="detection automatique">
							<p class="description">Laisse vide pour la detection automatique. Renseigne une cle du tableau ci-dessus pour la figer.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Type de site</th>
						<td>
							<label style="display:block;margin-bottom:5px">
								<input type="radio" name="site_mode" value="marketplace" <?php checked( $settings['site_mode'], 'marketplace' ); ?>>
								<strong>Marketplace</strong> : plusieurs marchands, chacun son numéro
							</label>
							<label style="display:block">
								<input type="radio" name="site_mode" value="single" <?php checked( $settings['site_mode'], 'single' ); ?>>
								<strong>Boutique unique</strong> : un seul numéro pour tout le site
							</label>
							<p class="description">
								En boutique unique, les colonnes Marchand et Numéro marchand du classeur
								affichent le nom de ce site et son numéro configuré.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="communes">Communes</label></th>
						<td>
							<textarea id="communes" name="communes" rows="6" class="regular-text"><?php echo esc_textarea( $settings['communes'] ); ?></textarea>
							<p class="description">
								Une commune par ligne. Alimente le sélecteur du formulaire. Le client garde
								la possibilité de saisir une commune absente de la liste.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vendor_map">Marchands</label></th>
						<td>
							<textarea id="vendor_map" name="vendor_map" rows="6" class="large-text" placeholder="Bricom = 243895250837&#10;Cimko = 243900000000&#10;Congo Nouveau = 243900000001, 243900000002"><?php echo esc_textarea( $settings['vendor_map'] ); ?></textarea>
							<p class="description">
								Un marchand par ligne, au format <code>Nom = numéro</code>. Plusieurs numéros
								pour un même marchand se séparent par des virgules. C'est la source de vérité :
								dès qu'une demande part vers l'un de ces numéros, le nom correspondant est écrit
								dans la colonne Marchand. Inutile sur un site à boutique unique.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vendor_category">Catégorie des boutiques</label></th>
						<td>
							<input type="text" class="regular-text" id="vendor_category" name="vendor_category" value="<?php echo esc_attr( $settings['vendor_category'] ); ?>" placeholder="boutique">
							<p class="description">
								Nom ou identifiant de la catégorie de produit qui regroupe les boutiques.
								La sous-catégorie du produit devient alors le nom du marchand. Vide pour désactiver.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Espace marchand</th>
						<td><label><input type="checkbox" name="vendor_dashboard" value="1" <?php checked( $settings['vendor_dashboard'], 1 ); ?>> Activer les comptes marchands et leur tableau de bord</label>
						<p class="description">Uniquement en mode multimarchand. Les inscriptions doivent être approuvées dans Commande WhatsApp → Marchands. En boutique unique, cette option est ignorée.</p></td>
					</tr>
					<tr>
						<th scope="row">Visibilité</th>
						<td>
							<label><input type="checkbox" name="admin_only" value="1" <?php checked( $settings['admin_only'], 1 ); ?>> Afficher le formulaire uniquement pour les administrateurs connectés</label>
							<p class="description">
								Recommandé pendant la mise au point sur un site en production. Les visiteurs
								gardent le bouton WhatsApp d'origine, qui fonctionne comme avant.
								Décoche quand tu veux ouvrir le formulaire à tes clients.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Mode test</th>
						<td>
							<label><input type="checkbox" name="test_mode" value="1" <?php checked( $settings['test_mode'], 1 ); ?>> Ne pas ouvrir WhatsApp, afficher seulement le message</label>
							<p class="description">A activer pendant les tests de recette.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="admin_email">E-mail de notification</label></th>
						<td><input type="email" class="regular-text" id="admin_email" name="admin_email" value="<?php echo esc_attr( $settings['admin_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><p class="description">Reçoit les nouvelles inscriptions marchands et les alertes de synchronisation Google. Si ce champ est vide, l’adresse d’administration de WordPress est utilisée.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="drive_folder_id">Dossier Drive</label></th>
						<td>
							<input type="text" class="regular-text" id="drive_folder_id" name="drive_folder_id" value="<?php echo esc_attr( $settings['drive_folder_id'] ); ?>">
							<p class="description">Facultatif. Identifiant du dossier ou ranger le classeur. Vide : il reste dans Mon Drive.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rate_limit">Limite par IP</label></th>
						<td><input type="number" min="1" max="60" id="rate_limit" name="rate_limit" value="<?php echo esc_attr( $settings['rate_limit'] ); ?>"> demandes par tranche de 10 minutes</td>
					</tr>
					<tr>
						<th scope="row"><label for="extra_selectors">Selecteurs supplementaires</label></th>
						<td>
							<textarea id="extra_selectors" name="extra_selectors" rows="3" class="large-text" placeholder=".mon-bouton-whatsapp"><?php echo esc_textarea( $settings['extra_selectors'] ); ?></textarea>
							<p class="description">Un selecteur CSS par ligne. Les boutons WhatsApp classiques sont deja detectes automatiquement.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="privacy_text">Mention de confidentialite</label></th>
						<td><textarea id="privacy_text" name="privacy_text" rows="2" class="large-text"><?php echo esc_textarea( $settings['privacy_text'] ); ?></textarea></td>
					</tr>
				</table>

				<?php submit_button( 'Enregistrer' ); ?>
			</form>

			<h2 class="title">Numeros WhatsApp detectes</h2>

			<?php
			$meta_keys = CSWA_Number::detect_meta_keys();
			$whitelist = CSWA_Number::whitelist();
			?>

			<p>
				Congo-Shop est une marketplace : le plugin lit le numero du marchand sur chaque
				produit et n utilise le numero de repli que si le produit n en a aucun.
			</p>

			<table class="widefat" style="max-width:900px;margin-bottom:12px">
				<thead>
					<tr><th style="width:55%">Champ trouve sur les produits</th><th>Produits concernes</th></tr>
				</thead>
				<tbody>
				<?php if ( ! $meta_keys ) : ?>
					<tr><td colspan="2">Aucun champ numero trouve sur les produits. Tous les produits utiliseront le numero de repli.</td></tr>
				<?php else : ?>
					<?php foreach ( $meta_keys as $key => $count ) : ?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td><?php echo (int) $count; ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<p>
				<strong><?php echo count( $whitelist ); ?></strong> numero(s) distinct(s) reconnus sur le site.
				Un numero absent de cette liste ne sera jamais utilise, meme s il apparait dans un bouton.
			</p>

			<h3>Verification sur 5 produits</h3>
			<table class="widefat striped" style="max-width:900px">
				<thead>
					<tr><th>Produit</th><th>Marchand</th><th>Numero retenu</th><th>Origine</th></tr>
				</thead>
				<tbody>
				<?php
				$sample = get_posts(
					array(
						'post_type'      => 'product',
						'posts_per_page' => 5,
						'post_status'    => 'publish',
						'orderby'        => 'rand',
						'fields'         => 'ids',
					)
				);

				if ( ! $sample ) :
					?>
					<tr><td colspan="4">Aucun produit publie.</td></tr>
					<?php
				else :
					foreach ( $sample as $pid ) :
						$resolved = CSWA_Number::for_product( $pid );
						?>
						<tr>
							<td><a href="<?php echo esc_url( get_permalink( $pid ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $pid ) ); ?></a></td>
							<td><?php echo esc_html( CSWA_Number::vendor_name( $pid ) ); ?></td>
							<td><code><?php echo esc_html( $resolved['number'] ); ?></code></td>
							<td>
								<?php if ( 'repli' === $resolved['source'] ) : ?>
									<span style="color:#996800">repli</span>
								<?php else : ?>
									<?php echo esc_html( $resolved['source'] ); ?>
								<?php endif; ?>
							</td>
						</tr>
						<?php
					endforeach;
				endif;
				?>
				</tbody>
			</table>

			<p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cswa_scan">
					<?php wp_nonce_field( 'cswa_scan' ); ?>
					<button type="submit" class="button">Relancer la detection</button>
				</form>
			</p>

			<h3>Diagnostic d'un produit</h3>
			<p>
				Colle l'identifiant ou l'URL d'une fiche produit dont tu connais le numéro du marchand.
				L'outil liste tous les champs contenant un numéro, ce qui permet de trouver où le plugin
				WhatsApp range ses données.
			</p>

			<form method="get" action="">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="text" class="regular-text" name="cswa_diag" value="<?php echo esc_attr( $diag_input ); ?>" placeholder="12506 ou https://...">
				<button type="submit" class="button">Analyser</button>
			</form>

			<?php if ( '' !== $diag_input ) : ?>
				<?php
				$product_id = CSWA_Number::resolve_product_input( $diag_input );
				$diag       = $product_id ? CSWA_Number::diagnose( $product_id ) : new WP_Error( 'cswa_no_product', 'Aucun produit trouvé pour cette saisie.' );
				?>

				<?php if ( is_wp_error( $diag ) ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $diag->get_error_message() ); ?></p></div>
				<?php else : ?>
					<table class="widefat" style="max-width:1100px;margin-top:12px">
						<tbody>
							<tr>
								<td style="width:220px"><strong>Produit</strong></td>
								<td>
									<?php echo esc_html( $diag['title'] ); ?>
									(ID <?php echo (int) $diag['id']; ?>)
									&middot; <a href="<?php echo esc_url( $diag['permalink'] ); ?>" target="_blank" rel="noopener">voir la fiche</a>
								</td>
							</tr>
							<tr>
								<td><strong>Numéro retenu</strong></td>
								<td>
									<code><?php echo esc_html( $diag['resolved']['number'] ); ?></code>
									&middot; origine : <?php echo esc_html( $diag['resolved']['source'] ); ?>
								</td>
							</tr>
							<tr>
								<td><strong>Auteur du produit</strong></td>
								<td>
									<?php if ( $diag['author'] ) : ?>
										<?php echo esc_html( $diag['author']['display_name'] ); ?>
										(<?php echo esc_html( $diag['author']['login'] ); ?>)
										<?php echo $diag['author']['is_admin'] ? ' &middot; administrateur du site' : ' &middot; compte non administrateur'; ?>
									<?php else : ?>
										aucun
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>

					<h4>Champs du produit contenant un numéro</h4>
					<table class="widefat striped" style="max-width:1100px">
						<thead><tr><th style="width:260px">Champ</th><th>Valeur</th><th style="width:180px">Numéros trouvés</th></tr></thead>
						<tbody>
						<?php if ( ! $diag['meta'] ) : ?>
							<tr><td colspan="3">Aucun. Le numéro du marchand n'est pas stocké sur le produit.</td></tr>
						<?php else : ?>
							<?php foreach ( $diag['meta'] as $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( $row['key'] ); ?></code></td>
									<td><?php echo esc_html( $row['value'] ); ?></td>
									<td><?php echo esc_html( implode( ', ', $row['numbers'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>

					<h4>Champs de l'auteur contenant un numéro</h4>
					<table class="widefat striped" style="max-width:1100px">
						<thead><tr><th style="width:260px">Champ</th><th>Valeur</th><th style="width:180px">Numéros trouvés</th></tr></thead>
						<tbody>
						<?php if ( empty( $diag['author']['meta'] ) ) : ?>
							<tr><td colspan="3">Aucun.</td></tr>
						<?php else : ?>
							<?php foreach ( $diag['author']['meta'] as $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( $row['key'] ); ?></code></td>
									<td><?php echo esc_html( $row['value'] ); ?></td>
									<td><?php echo esc_html( implode( ', ', $row['numbers'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>

					<h4>Réglages des plugins WhatsApp</h4>
					<table class="widefat striped" style="max-width:1100px">
						<thead><tr><th style="width:260px">Réglage</th><th>Valeur</th><th style="width:180px">Numéros trouvés</th></tr></thead>
						<tbody>
						<?php if ( ! $diag['options'] ) : ?>
							<tr><td colspan="3">Aucun.</td></tr>
						<?php else : ?>
							<?php foreach ( $diag['options'] as $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( $row['key'] ); ?></code></td>
									<td style="word-break:break-all"><?php echo esc_html( $row['value'] ); ?></td>
									<td><?php echo esc_html( implode( ', ', $row['numbers'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>

			<hr>

			<h2 class="title">20 dernieres demandes</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Reference</th>
						<th>Date (UTC)</th>
						<th>Client</th>
						<th>Produit</th>
						<th>Journal</th>
					</tr>
				</thead>
				<tbody>
				<?php
				$rows = CSWA_Store::latest( 20 );
				if ( ! $rows ) :
					?>
					<tr><td colspan="5">Aucune demande pour le moment.</td></tr>
					<?php
				else :
					foreach ( $rows as $row ) :
						$payload = json_decode( $row['payload'], true );
						$status  = $row['sheet_status'];
						$label   = array(
							'ok'      => 'Enregistree',
							'pending' => 'En attente',
							'failed'  => 'Echec',
						);
						?>
						<tr>
							<td><code><?php echo esc_html( $row['ref'] ); ?></code><?php
							$order = ! empty( $row['order_id'] ) ? wc_get_order( (int) $row['order_id'] ) : false;
							if ( $order ) { echo '<br><a href="' . esc_url( $order->get_edit_order_url() ) . '">Commande #' . esc_html( $order->get_order_number() ) . '</a>'; }
							?></td>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td>
								<?php echo esc_html( $payload['customer']['full_name'] ?? '' ); ?><br>
								<small><?php echo esc_html( $payload['customer']['phone'] ?? '' ); ?></small>
							</td>
							<td>
								<?php echo esc_html( $payload['product']['name'] ?? '' ); ?>
								<?php if ( ! empty( $payload['product']['variant_label'] ) ) : ?>
									<br><small><?php echo esc_html( $payload['product']['variant_label'] ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( $label[ $status ] ?? $status ); ?>
								<?php if ( ! empty( $row['last_error'] ) ) : ?>
									<br><small style="color:#b32d2e"><?php echo esc_html( $row['last_error'] ); ?></small>
								<?php endif; ?>
							</td>
						</tr>
						<?php
					endforeach;
				endif;
				?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
