<?php
/**
 * Chargement des ressources front et rendu du modal.
 *
 * @package CSWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Couche front-end.
 */
class CSWA_Frontend {

	/**
	 * Accroche les hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_modal' ), 99 );
		add_shortcode( 'cswa_button', array( __CLASS__, 'shortcode_button' ) );
		add_filter( 'do_shortcode_tag', array( 'CSWA_Service', 'filter_shortcode' ), 30, 2 );
	}

	/**
	 * Determine si la fonctionnalite doit etre chargee.
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( is_admin() ) {
			return false;
		}

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}

		if ( CSWA_Service::enabled() && ! CSWA_Service::matches( get_queried_object_id() ) ) { return false; }

		// Phase de rodage sur un site en production : le formulaire n'est
		// injecte que pour les administrateurs. Les clients gardent le bouton
		// WhatsApp d'origine, qui continue de fonctionner comme avant.
		if ( CSWA_Plugin::get_setting( 'admin_only', 0 ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Communes proposees dans le selecteur.
	 *
	 * @return string[]
	 */
	public static function communes() {
		$raw  = (string) CSWA_Plugin::get_setting( 'communes', '' );
		$list = array();

		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$list[] = $line;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Selecteurs des boutons a intercepter.
	 *
	 * @return string[]
	 */
	public static function selectors() {
		$default = array(
			'.cswa-trigger',
			'[data-cswa-trigger]',
			'a[href*="wa.me/"]',
			'a[href*="api.whatsapp.com/send"]',
			'a[href*="web.whatsapp.com/send"]',
			'a[href^="whatsapp://"]',
			'.ocwa-btn',
			'.wa-order-class',
			// Widget maison de Mobile RDC.
			'.mrp-buy-wa',
			'.mrp-bar-wa',
			'.wa-order-button',
			'.wa-order-btn',
			'.wa-shop-button',
			'.whatsapp-order-button',
		);

		$extra = CSWA_Plugin::get_setting( 'extra_selectors', '' );
		if ( $extra ) {
			foreach ( preg_split( '/[\r\n,]+/', $extra ) as $selector ) {
				$selector = trim( $selector );
				if ( '' !== $selector ) {
					$default[] = $selector;
				}
			}
		}

		return array_values( array_unique( $default ) );
	}

	/**
	 * Charge CSS et JS sur les fiches produit.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::is_active() ) {
			return;
		}

		global $product;

		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! $product ) {
			return;
		}

		wp_enqueue_style( 'cswa-form', CSWA_URL . 'assets/cswa-form.css', array(), CSWA_VERSION );
		wp_enqueue_script( 'cswa-form', CSWA_URL . 'assets/cswa-form.js', array(), CSWA_VERSION, true );

		wp_localize_script(
			'cswa-form',
			'CSWA_DATA',
			array(
				'restUrl'     => esc_url_raw( rest_url( 'cswa/v1/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'destination' => CSWA_Number::for_product( $product->get_id() ),
				'productId'   => (int) $product->get_id(),
				'isService'   => CSWA_Service::matches( $product->get_id() ),
				'isVariable'  => $product->is_type( 'variable' ),
				'sourceUrl'   => esc_url_raw( get_permalink( $product->get_id() ) ),
				'selectors'   => self::selectors(),
				'communes'    => self::communes(),
				'privacyText' => wp_strip_all_tags( CSWA_Plugin::get_setting( 'privacy_text' ) ),
				'testMode'    => (bool) CSWA_Plugin::get_setting( 'test_mode', 0 ),
				'i18n'        => array(
					'genericError' => 'Une erreur est survenue. Réessaie dans un instant.',
					'network'      => 'Connexion interrompue. Vérifie ton réseau puis réessaie.',
				),
			)
		);
	}

	/**
	 * Bouton optionnel a poser dans un widget Elementor.
	 *
	 * Usage : [cswa_button label="Achetez via WhatsApp"]
	 *
	 * @param array $atts Attributs.
	 * @return string
	 */
	public static function shortcode_button( $atts ) {
		if ( ! self::is_active() ) { return ''; }
		$atts = shortcode_atts(
			array(
				'label' => 'Achetez via WhatsApp',
				'class' => '',
			),
			$atts,
			'cswa_button'
		);

		return sprintf(
			'<button type="button" class="cswa-trigger cswa-button %1$s">%2$s</button>',
			esc_attr( $atts['class'] ),
			esc_html( $atts['label'] )
		);
	}

	/**
	 * Squelette du modal, injecte une seule fois en pied de page.
	 *
	 * @return void
	 */
	public static function render_modal() {
		if ( ! self::is_active() ) {
			return;
		}

		$service = CSWA_Service::matches( get_queried_object_id() );
		$privacy = wp_strip_all_tags( CSWA_Plugin::get_setting( 'privacy_text' ) );
		?>
		<div class="cswa-overlay" id="cswa-overlay" hidden>
			<div class="cswa-sheet" role="dialog" aria-modal="true" aria-labelledby="cswa-title" id="cswa-sheet">

				<header class="cswa-head">
					<div class="cswa-head-text">
						<h2 class="cswa-title" id="cswa-title"><?php echo $service ? 'Demander une prestation' : 'Commander via WhatsApp'; ?></h2>
					</div>
					<button type="button" class="cswa-close" id="cswa-close" aria-label="Fermer">&times;</button>
				</header>

				<div class="cswa-body" id="cswa-body">

					<!-- Etape 1 : formulaire -->
					<section class="cswa-step" data-step="form">
						<div class="cswa-recap" id="cswa-product-recap" aria-live="polite"></div>

						<form id="cswa-form" novalidate>
<?php if ( ! $service ) : ?>
							<fieldset class="cswa-field cswa-modes">
								<legend>Mode de réception <span aria-hidden="true">*</span></legend>
								<div class="cswa-mode-grid">
									<label class="cswa-mode">
										<input type="radio" name="mode" value="pickup" checked>
										<span class="cswa-mode-box">
											<strong>Retrait</strong>
											<small>Tu viens chercher la commande</small>
										</span>
									</label>
									<label class="cswa-mode">
										<input type="radio" name="mode" value="delivery">
										<span class="cswa-mode-box">
											<strong>Livraison</strong>
											<small>On te livre à l'adresse indiquée</small>
										</span>
									</label>
								</div>
								<p class="cswa-hint" id="cswa-mode-hint">
									Pour le retrait, indique ton nom et ton téléphone. L’e-mail est facultatif.
								</p>
							</fieldset>
<?php endif; ?>
<fieldset id="cswa-customer-fields">
<legend class="screen-reader-text">Informations du client</legend>
							<div class="cswa-field">
								<label for="cswa-full_name">Nom complet <span aria-hidden="true">*</span></label>
								<input type="text" id="cswa-full_name" name="full_name" autocomplete="name" required>
								<p class="cswa-error" data-error-for="full_name"></p>
							</div>

							<div class="cswa-field">
								<label for="cswa-phone">Téléphone <span aria-hidden="true">*</span></label>
								<input type="tel" id="cswa-phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="Ex. 0812 345 678" required>
								<p class="cswa-error" data-error-for="phone"></p>
							</div>

							<div class="cswa-field">
								<label for="cswa-email">E-mail <small>(facultatif)</small></label>
								<input type="email" id="cswa-email" name="email" inputmode="email" autocomplete="email" placeholder="Ex. nom@exemple.com">
								<p class="cswa-error" data-error-for="email"></p>
							</div>



							<fieldset id="cswa-address-fields" <?php if ( ! $service ) { echo 'hidden disabled'; } ?>>
<legend class="cswa-section-label">Adresse physique complète</legend>

							<div class="cswa-grid">
								<div class="cswa-field">
									<label for="cswa-country">Pays</label>
									<input type="text" id="cswa-country" name="country" value="RD Congo" autocomplete="country-name">
								</div>
								<div class="cswa-field">
									<label for="cswa-city">Ville <span aria-hidden="true">*</span></label>
									<input type="text" id="cswa-city" name="city" value="Kinshasa" autocomplete="address-level2" required>
									<p class="cswa-error" data-error-for="city"></p>
								</div>
							</div>

							<div class="cswa-field">
								<label for="cswa-commune">Commune <span aria-hidden="true">*</span></label>
								<select id="cswa-commune" name="commune" required>
									<option value="">Choisis ta commune</option>
									<?php foreach ( self::communes() as $commune ) : ?>
										<option value="<?php echo esc_attr( $commune ); ?>"><?php echo esc_html( $commune ); ?></option>
									<?php endforeach; ?>
									<option value="__autre">Autre commune</option>
								</select>
								<input type="text" id="cswa-commune_other" name="commune_other" placeholder="Précise la commune" hidden>
								<p class="cswa-error" data-error-for="commune"></p>
							</div>

							<div class="cswa-field">
								<label for="cswa-address">Adresse exacte <span aria-hidden="true">*</span></label>
								<input type="text" id="cswa-address" name="address" autocomplete="address-line1" placeholder="Quartier, avenue ou rue, numéro de parcelle" required>
								<p class="cswa-error" data-error-for="address"></p>
							</div>

							<div class="cswa-field">
								<label for="cswa-reference">Référence <small>(facultatif)</small></label>
								<input type="text" id="cswa-reference" name="reference" placeholder="Point de repère, horaire de passage, étage, contact sur place...">
							</div>

</fieldset>
<?php if ( $service ) : ?>
<div class="cswa-field">
 <label for="cswa-need_description">Description du besoin <span aria-hidden="true">*</span></label>
 <textarea id="cswa-need_description" name="need_description" rows="4" maxlength="3000" required></textarea>
 <p class="cswa-error" data-error-for="need_description"></p>
</div>
<?php endif; ?>
</fieldset>
							<div class="cswa-hp" aria-hidden="true">
								<label>Site web<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
							</div>

							<p class="cswa-privacy"><?php echo esc_html( $privacy ); ?></p>

							<p class="cswa-notice" id="cswa-save-status" aria-live="polite">
								Ta commande sera enregistrée avant l’ouverture de WhatsApp. La boutique devra ensuite confirmer sa prise en charge.
							</p>

							<p class="cswa-error cswa-error-global" id="cswa-form-error" role="alert"></p>

							<a class="cswa-whatsapp" id="cswa-whatsapp" href="#" target="_blank" rel="noopener">
								Enregistrer et ouvrir WhatsApp
							</a>
						</form>
					</section>

					
				</div>
			</div>
		</div>
		<?php
	}
}
