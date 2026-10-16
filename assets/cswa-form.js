/* Congo-Shop - Commande WhatsApp
 *
 * Le script n'ecrase aucun balisage existant : il intercepte le clic sur le
 * bouton WhatsApp deja present dans le widget Elementor, ouvre le formulaire,
 * puis laisse le client ouvrir WhatsApp lui-meme.
 */
( function () {
	'use strict';

	var D = window.CSWA_DATA;
	if ( ! D ) {
		return;
	}

	var overlay, sheet, form, globalError, recapBox, waLink, closeBtn,
		modeHint, communeSel, communeOther;

	var state = {
		open: false,
		submitting: false,
		idemKey: null,
		openedAt: 0,
		lastFocus: null,
		nonce: D.nonce,
		quote: null,
		buttonNumber: '',
		ref: '',
		sent: false
	};

	/* ------------------------------------------------------------------ */
	/* Numero porte par le bouton                                          */
	/* ------------------------------------------------------------------ */

	/* Sur une marketplace, chaque produit peut viser un marchand different.
	 * Le bouton rendu par OneClick Chat to Order porte deja le bon numero :
	 * on le lit ici, le serveur le verifie ensuite contre les numeros
	 * reellement enregistres sur le site. */
	// Keep the two custom Mobile RDC purchase links aligned with the server.
	// Their Elementor scripts can rewrite href after quantity changes/hover.
	var customPurchaseSelector = '#mrp-buy-wa, #mrp-bar-wa';
	function syncPurchaseLink( node ) {
		var destination = D.destination;
		if ( ! destination || ! /^(site|filtre|produit:)/.test( destination.source || '' ) ) { return; }
		var oldHref = node.getAttribute( 'href' ) || '';
		var newHref = '#';
		if ( destination.number ) {
			try {
				var url = new URL( oldHref, window.location.href );
				newHref = 'https://wa.me/' + destination.number + '?text=' + encodeURIComponent( url.searchParams.get( 'text' ) || '' );
			} catch ( e ) { newHref = 'https://wa.me/' + destination.number; }
		}
		if ( oldHref !== newHref ) { node.setAttribute( 'href', newHref ); }
	}
	function syncPurchaseLinks() {
		Array.prototype.forEach.call( document.querySelectorAll( customPurchaseSelector ), syncPurchaseLink );
	}
	function watchPurchaseLinks() {
		syncPurchaseLinks();
		new MutationObserver( function ( changes ) {
			changes.forEach( function ( change ) {
				if ( change.type === 'attributes' ) {
					if ( change.target.matches( customPurchaseSelector ) ) { syncPurchaseLink( change.target ); }
				} else if ( change.addedNodes.length ) { syncPurchaseLinks(); }
			} );
		} ).observe( document.body, { subtree: true, childList: true, attributes: true, attributeFilter: [ 'href' ] } );
	}

	function readButtonNumber( node ) {
		if ( ! node ) {
			return '';
		}

		var candidates = [];
		var href = node.getAttribute && node.getAttribute( 'href' );

		if ( href ) {
			candidates.push( href );
		}

		[ 'data-phone', 'data-number', 'data-wa-number', 'data-whatsapp' ].forEach( function ( attr ) {
			var value = node.getAttribute && node.getAttribute( attr );
			if ( value ) {
				candidates.push( value );
			}
		} );

		// Le bouton peut envelopper le lien, ou l'inverse.
		var inner = node.querySelector && node.querySelector( 'a[href*="wa.me"], a[href*="whatsapp"]' );
		if ( inner && inner.getAttribute( 'href' ) ) {
			candidates.push( inner.getAttribute( 'href' ) );
		}

		for ( var i = 0; i < candidates.length; i++ ) {
			var value = candidates[ i ];
			var match = value.match( /(?:wa\.me\/|phone=|send\/?\?phone=)\+?(\d{8,15})/ );

			if ( match ) {
				return match[ 1 ];
			}

			if ( /^\+?[\d\s().-]{8,22}$/.test( value ) ) {
				return value.replace( /\D/g, '' );
			}
		}

		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Utilitaires                                                         */
	/* ------------------------------------------------------------------ */

	function uuid() {
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		var s = '';
		for ( var i = 0; i < 32; i++ ) {
			s += Math.floor( Math.random() * 16 ).toString( 16 );
		}
		return s + Date.now().toString( 16 );
	}

	function esc( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value === null || value === undefined ? '' : String( value );
		return div.innerHTML;
	}

	function api( path, options ) {
		options = options || {};
		var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': state.nonce };

		return fetch( D.restUrl + path, {
			method: options.method || 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: options.body ? JSON.stringify( options.body ) : undefined
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! res.ok ) {
					var err = new Error( data && data.message ? data.message : D.i18n.genericError );
					err.code = data && data.code ? data.code : 'http_' + res.status;
					err.status = res.status;
					throw err;
				}
				return data;
			} );
		} );
	}

	function refreshNonce() {
		return fetch( D.restUrl + 'session', { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data && data.nonce ) {
					state.nonce = data.nonce;
				}
			} )
			.catch( function () {} );
	}

	/* ------------------------------------------------------------------ */
	/* Lecture du produit affiche                                          */
	/* ------------------------------------------------------------------ */

	function readQty() {
		var input = document.querySelector( 'form.cart input.qty, form.cart input[name="quantity"], input[name="quantity"]' );
		var customQty = document.getElementById( 'mrp-qval' );
		var qty = parseInt( input ? input.value : ( customQty ? customQty.textContent : '1' ), 10 );
		return ( isNaN( qty ) || qty < 1 ) ? 1 : qty;
	}

	function readVariationId() {
		var input = document.querySelector( 'form.variations_form input[name="variation_id"], input.variation_id' );
		if ( ! input ) {
			return 0;
		}
		var id = parseInt( input.value, 10 );
		return isNaN( id ) ? 0 : id;
	}

	function readAttributes() {
		var attrs = {};
		var fields = document.querySelectorAll( 'form.variations_form [name^="attribute_"]' );

		Array.prototype.forEach.call( fields, function ( field ) {
			if ( field.type === 'radio' && ! field.checked ) {
				return;
			}
			if ( field.value ) {
				attrs[ field.name ] = field.value;
			}
		} );

		return attrs;
	}

	function currentSelection() {
		return {
			product_id: D.productId,
			variation_id: readVariationId(),
			attributes: readAttributes(),
			qty: readQty(),
			button_number: state.buttonNumber
		};
	}

	/* ------------------------------------------------------------------ */
	/* Modal                                                               */
	/* ------------------------------------------------------------------ */

	function focusables() {
		return sheet.querySelectorAll(
			'a[href], button:not([disabled]), input:not([type="hidden"]), textarea, select, [tabindex]:not([tabindex="-1"])'
		);
	}

	function trapFocus( event ) {
		if ( event.key !== 'Tab' ) {
			return;
		}
		var items = Array.prototype.filter.call( focusables(), function ( el ) {
			return el.offsetParent !== null;
		} );
		if ( ! items.length ) {
			return;
		}
		var first = items[ 0 ];
		var last = items[ items.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	function onKeydown( event ) {
		if ( ! state.open ) {
			return;
		}
		if ( event.key === 'Escape' ) {
			closeModal();
			return;
		}
		trapFocus( event );
	}

	/* Il n'y a plus qu'une étape : le client remplit, puis part sur WhatsApp.
	 * Reste la remise à zéro de l'affichage à chaque ouverture. */
	function resetStep() {
		var shown = sheet.querySelector( '.cswa-step[data-step="form"]' );
		if ( shown ) {
			shown.hidden = false;
			shown.classList.remove( 'cswa-enter' );
			void shown.offsetWidth;
			shown.classList.add( 'cswa-enter' );
		}
		sheet.querySelector( '.cswa-body' ).scrollTop = 0;
	}

	function openModal() {
		state.lastFocus = document.activeElement;
		state.open = true;
		state.openedAt = Math.floor( Date.now() / 1000 );
		state.sent = false;

		if ( ! state.idemKey ) {
			state.idemKey = uuid();
		}

		overlay.hidden = false;
		document.body.classList.add( 'cswa-locked' );

		// Laisse le navigateur peindre avant l'animation.
		window.requestAnimationFrame( function () {
			overlay.classList.add( 'is-open' );
		} );

		if ( pendingSave || saveInFlight ) { return; }
		savedResponse = null; lockFields( false );
		waLink.textContent = 'Enregistrer et ouvrir WhatsApp';
		refreshLink();
		form.reset();
		var notice = document.getElementById( 'cswa-save-status' );
		if ( notice ) { notice.textContent = 'Ta commande sera enregistrée avant l’ouverture de WhatsApp. La boutique devra ensuite confirmer sa prise en charge.'; }
		var preview = document.getElementById( 'cswa-preview' );
		if ( preview && preview.parentNode ) { preview.parentNode.removeChild( preview ); }
		state.quote = null;
		if ( communeOther ) { communeOther.hidden = true; }
		resetStep();
		updateRequirements();
		loadQuote();

		window.setTimeout( function () {
			var first = form.querySelector( D.isService ? 'input[name="full_name"]' : 'input[name="mode"]:checked' );
			if ( first ) {
				first.focus();
			}
		}, 220 );
	}

	function closeModal() {
		state.open = false;
		overlay.classList.remove( 'is-open' );
		document.body.classList.remove( 'cswa-locked' );

		window.setTimeout( function () {
			overlay.hidden = true;
		}, 240 );

		if ( state.lastFocus && state.lastFocus.focus ) {
			state.lastFocus.focus();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Recapitulatif produit                                               */
	/* ------------------------------------------------------------------ */

	function applyQuote( data ) {
		state.quote = data.product;
		if ( state.quote.whatsapp_number ) {
			D.destination = { number: state.quote.whatsapp_number, source: 'produit:quote' };
			syncPurchaseLinks();
		}
		// Référence réservée dès l'ouverture : elle doit figurer dans le
		// message, qui est construit avant le clic.
		state.ref = data.ref || '';
		renderQuote( data.product );
		refreshLink();
	}

	function loadQuote() {
		recapBox.innerHTML = '<p class="cswa-recap-meta">Vérification du produit...</p>';

		api( 'quote', { body: currentSelection() } )
			.then( applyQuote )
			.catch( function ( err ) {
				state.quote = null;
				if ( err.status === 403 ) {
					return refreshNonce().then( function () {
						return api( 'quote', { body: currentSelection() } ).then( applyQuote );
					} );
				}
				throw err;
			} )
			.catch( function ( err ) {
				recapBox.innerHTML = '<p class="cswa-recap-meta">' + esc( err.message ) + '</p>';
				refreshLink();
			} );
	}

	function renderQuote( p ) {
		var html = '<p class="cswa-recap-name">' + esc( p.name ) + '</p>';
		if ( p.is_service ) { recapBox.innerHTML = html + '<p class="cswa-recap-meta">' + esc( p.unit_price_text ) + '</p>'; return; }
		var meta = [];

		if ( p.vendor ) {
			meta.push( 'Vendu par ' + esc( p.vendor ) );
		}

		if ( p.variant_label ) {
			meta.push( esc( p.variant_label ) );
		}
		meta.push( 'Quantité : ' + esc( p.qty ) );
		meta.push( esc( p.unit_price_text ) + ' l\'unité' );

		html += '<p class="cswa-recap-meta">' + meta.join( ' &middot; ' ) + '</p>';
		html += '<p class="cswa-recap-total">Total des articles : ' + esc( p.items_total_text ) +
			'<small>Frais de livraison non inclus, confirmés sur WhatsApp.</small></p>';

		recapBox.innerHTML = html;
	}

	/* ------------------------------------------------------------------ */
	/* Validation                                                          */
	/* ------------------------------------------------------------------ */

	var REQUIRED = [ 'full_name', 'phone', 'city', 'commune', 'address' ];

	var LABELS = {
		need_description: 'Décris ton besoin.',
		full_name: 'Indique ton nom complet.',
		phone: 'Indique un numéro de téléphone joignable.',
		city: 'Indique la ville.',
		commune: 'Indique la commune.',
		address: 'Indique le quartier, l\'avenue et le numéro de parcelle.'
	};

	/* Champs envoyés au serveur, obligatoires et facultatifs confondus. */
	var FIELDS = [
		'full_name', 'phone', 'email', 'country', 'city', 'commune', 'address', 'reference', 'need_description'
	];

	function fieldError( name, message ) {
		var input = form.querySelector( '[name="' + name + '"]' );
		var holder = form.querySelector( '[data-error-for="' + name + '"]' );

		if ( input && input.closest( '.cswa-field' ) ) {
			input.closest( '.cswa-field' ).classList.toggle( 'has-error', !! message );
		}
		if ( holder ) {
			holder.textContent = message || '';
			holder.classList.toggle( 'is-visible', !! message );
		}
	}

	function clearErrors() {
		FIELDS.forEach( function ( name ) {
			fieldError( name, '' );
		} );
		globalError.textContent = '';
		globalError.classList.remove( 'is-visible' );
	}

	function updateRequirements() {
		var delivery = collectCustomer().mode !== 'pickup';
		var customerFields = document.getElementById( 'cswa-address-fields' );
		if ( customerFields ) { customerFields.hidden = ! delivery; customerFields.disabled = ! delivery; }
		if ( modeHint ) { modeHint.textContent = delivery ? 'Cette adresse sera utilisée pour la livraison. Les frais et le délai seront confirmés sur WhatsApp.' : 'Pour le retrait, indique ton nom et ton téléphone. L’e-mail est facultatif.'; }
		REQUIRED.forEach( function ( name ) {
			var input = form.querySelector( '[name="' + name + '"]' );
			if ( ! input ) { return; }
			input.required = delivery || name === 'full_name' || name === 'phone';
			var marker = form.querySelector( 'label[for="' + input.id + '"] span' );
			if ( marker ) { marker.textContent = input.required ? '*' : '(facultatif)'; }
		} );
		clearErrors();
	}

	function validate() {
		var ok = true;
		var firstBad = null;

		( D.isService ? REQUIRED.concat( [ 'need_description' ] ) : ( collectCustomer().mode === 'delivery' ? REQUIRED : [ 'full_name', 'phone' ] ) ).forEach( function ( name ) {
			var input = form.querySelector( '[name="' + name + '"]' );
			var value = input ? input.value.trim() : '';

			// Commune : « Autre » exige le champ libre.
			if ( 'commune' === name && '__autre' === value ) {
				value = communeOther ? communeOther.value.trim() : '';
				if ( ! value ) {
					input = communeOther;
				}
			}

			if ( ! value ) {
				fieldError( name, LABELS[ name ] );
				ok = false;
				if ( ! firstBad ) {
					firstBad = input;
				}
			} else {
				fieldError( name, '' );
			}
		} );

		var phone = form.querySelector( '[name="phone"]' );
		if ( ok && phone && phone.value.trim() !== '' && phone.value.replace( /\D/g, '' ).length < 8 ) {
			fieldError( 'phone', 'Ce numéro semble incomplet.' );
			ok = false;
			firstBad = phone;
		}

		// L'e-mail est facultatif, mais s'il est rempli il doit être plausible.
		var email = form.querySelector( '[name="email"]' );
		if ( email && email.value.trim() !== '' && ! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( email.value.trim() ) ) {
			fieldError( 'email', 'Cette adresse e-mail semble incorrecte.' );
			ok = false;
			if ( ! firstBad ) {
				firstBad = email;
			}
		} else {
			fieldError( 'email', '' );
		}

		if ( firstBad ) {
			firstBad.focus();
		}

		return ok;
	}

	function collectCustomer() {
		var data = {};

		FIELDS.forEach( function ( name ) {
			var input = form.querySelector( '[name="' + name + '"]' );
			data[ name ] = input ? input.value.trim() : '';
		} );

		var mode = form.querySelector( '[name="mode"]:checked' );
		data.mode = D.isService ? 'service' : ( mode ? mode.value : 'pickup' );
		if ( data.mode === 'pickup' ) { [ 'country', 'city', 'commune', 'address', 'reference', 'need_description' ].forEach( function ( name ) { data[ name ] = ''; } ); }

		// « Autre commune » : la valeur utile est dans le champ libre.
		if ( data.commune === '__autre' ) {
			data.commune = communeOther ? communeOther.value.trim() : '';
		}

		return data;
	}

	/* ------------------------------------------------------------------ */
	/* Lien WhatsApp                                                       */
	/* ------------------------------------------------------------------ */

	/* Le lien doit être prêt AVANT le clic : un navigateur bloque l'ouverture
	 * d'une URL posée après une réponse serveur. On le reconstruit donc à
	 * chaque frappe, et le clic n'a plus qu'à suivre un lien normal. */

	function buildMessage() {
		var p = state.quote || {};
		var c = collectCustomer();
		var L = [];

		if ( p.is_service ) {
			L.push( 'Bonjour, je souhaite demander une prestation :', '', 'Service concerné : ' + p.name, 'Lien : ' + p.permalink, 'Description du besoin : ' + c.need_description, '' );
		} else {
		L.push( 'Bonjour' + ( p.vendor ? ' ' + p.vendor : '' ) + ', je souhaite commander :' );
		L.push( '' );
		L.push( 'Produit : ' + ( p.name || '' ) );

		if ( p.variant_label ) {
			L.push( 'Variante : ' + p.variant_label );
		}

		L.push( 'Quantité : ' + ( p.qty || 1 ) );
		L.push( 'Prix unitaire : ' + ( p.unit_price_text || '' ) );
		L.push( 'Total des articles : ' + ( p.items_total_text || '' ) + ' (hors frais de livraison)' );
		L.push( 'Lien : ' + ( p.permalink || D.sourceUrl ) );
		L.push( '' );
		}
		L.push( 'Mes informations :' );
		if ( c.full_name ) { L.push( 'Nom : ' + c.full_name ); }
		if ( c.phone ) { L.push( 'Téléphone : ' + c.phone ); }

		if ( c.email ) {
			L.push( 'E-mail : ' + c.email );
		}

		if ( ! p.is_service ) { L.push( 'Mode : ' + ( c.mode === 'pickup' ? 'Retrait' : 'Livraison' ) ); }

		var address = [
			c.address,
			c.commune ? 'C. ' + c.commune : '',
			c.city,
			c.country
		].filter( Boolean ).join( ', ' );

		if ( address ) { L.push( ( c.mode === 'delivery' ? 'Adresse de livraison : ' : 'Adresse : ' ) + address ); }

		if ( c.reference ) {
			L.push( 'Référence : ' + c.reference );
		}

		L.push( '' );
		L.push( p.is_service ? 'Merci de me confirmer vos disponibilités et votre tarif.' : c.mode === 'delivery'
			? 'Merci de me confirmer les frais et le délai de livraison.'
			: 'Je viendrai retirer la commande, merci de me confirmer le point et les horaires de retrait.' );


		return L.join( '\n' );
	}

	// Until the server acknowledges a real WooCommerce order, no WhatsApp URL exists.
	function refreshLink() {
		if ( waLink ) { waLink.setAttribute( 'href', savedResponse && ! D.testMode ? savedResponse.whatsapp_url : '#' ); }
	}

	var pendingSave = null;
	var saveInFlight = false;
	var savedResponse = null;

	function lockFields( locked ) {
		Array.prototype.forEach.call( form.querySelectorAll( 'input, select, textarea' ), function ( el ) { el.disabled = locked; } );
	}

	function save() {
		if ( ! pendingSave ) {
			var selection = currentSelection();
			pendingSave = {
				idem_key: state.idemKey, ref: state.ref, opened_at: state.openedAt,
				source_url: D.sourceUrl, website: ( form.querySelector( '[name="website"]' ) || {} ).value || '',
				button_number: state.buttonNumber, product_id: selection.product_id,
				variation_id: selection.variation_id, attributes: selection.attributes,
				qty: selection.qty, customer: collectCustomer()
			};
		}
		var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
		var timer = controller ? window.setTimeout( function () { controller.abort(); }, 20000 ) : null;
		return fetch( D.restUrl + 'request', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state.nonce },
			body: JSON.stringify( pendingSave ), signal: controller ? controller.signal : undefined
		} ).then( function ( response ) {
			return response.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! response.ok || ! data.saved || ! data.order_id || ! /^https:\/\/wa\.me\/\d+\?text=/.test( data.whatsapp_url || '' ) ) {
					var error = new Error( data.message || 'Enregistrement non confirmé. Réessaie avec le bouton ci-dessous.' );
					error.status = response.status; throw error;
				}
				return data;
			} );
		} ).then( function ( data ) {
			if ( timer ) { window.clearTimeout( timer ); }
			return data;
		}, function ( error ) {
			if ( timer ) { window.clearTimeout( timer ); }
			throw error;
		} );
	}

	function onWhatsAppClick( event ) {
		// Also prevent double clicks and normal navigation on failed saves.
		event.preventDefault();
		if ( saveInFlight ) { return; }
		if ( savedResponse ) {
			if ( D.testMode ) { showTestMessage(); }
			else { window.location.assign( savedResponse.whatsapp_url ); }
			return;
		}
		clearErrors();
		if ( ! pendingSave && ! validate() ) { return; }
		if ( ! state.quote ) {
			globalError.textContent = 'Le produit est encore en cours de vérification.';
			globalError.classList.add( 'is-visible' ); return;
		}
		saveInFlight = true;
		waLink.textContent = 'Enregistrement de la commande…';
		waLink.setAttribute( 'aria-busy', 'true' );
		// Snapshot values before disabling fields; retries reuse that exact request.
		var promise = save();
		lockFields( true );
		promise.then( function ( data ) {
			savedResponse = data; pendingSave = null; state.sent = true; state.ref = data.ref;
			waLink.textContent = D.testMode ? 'Voir le message de test' : 'Ouvrir WhatsApp';
			refreshLink();
			var notice = document.getElementById( 'cswa-save-status' );
			if ( notice ) { notice.textContent = 'Commande n°' + data.order_id + ' enregistrée. Envoie maintenant ton message dans WhatsApp.'; }
			if ( D.testMode ) { showTestMessage(); }
			else if ( state.open && ! document.hidden ) { window.location.assign( data.whatsapp_url ); }
		} ).catch( function ( error ) {
			if ( error.status === 403 ) { refreshNonce(); }
			// Validation rejection is definite: allow correcting fields. Other failures
			// may have occurred after a write, so keep the immutable request for retry.
			if ( error.status === 400 || error.status === 404 || error.status === 422 ) { pendingSave = null; lockFields( false ); }
			globalError.textContent = error.name === 'AbortError' || ! error.status
				? 'Connexion interrompue. Tes informations restent ici : réessaie pour confirmer l’enregistrement.' : error.message;
			globalError.classList.add( 'is-visible' );
			waLink.textContent = 'Réessayer l’enregistrement';
		} ).then( function () {
			saveInFlight = false; waLink.removeAttribute( 'aria-busy' );
		} );
	}

	function showTestMessage() {
		var pre = document.getElementById( 'cswa-preview' );

		if ( ! pre ) {
			pre = document.createElement( 'pre' );
			pre.id = 'cswa-preview';
			pre.className = 'cswa-message-preview';
			waLink.parentNode.insertBefore( pre, waLink );
		}

		pre.textContent = 'MODE TEST, message non envoyé\n\nDestinataire : '
			+ ( state.quote.whatsapp_number || 'inconnu' ) + '\n\n' + ( savedResponse ? savedResponse.message : buildMessage() );
	}

	/* ------------------------------------------------------------------ */
	/* Interception du bouton existant                                     */
	/* ------------------------------------------------------------------ */

	/* Une fiche produit affiche souvent d'autres boutons WhatsApp, ceux des
	 * produits suggérés. Ils visent un autre produit que celui de la page :
	 * les intercepter ouvrirait le formulaire avec le mauvais article. On les
	 * laisse donc se comporter normalement. */
	var EXCLUDE = [
		'.related', '.upsells', '.cross-sells', '.up-sells',
		'.products', '.product-grid', '.wd-products',
		'.owl-carousel', '.swiper', '.slick-slider', '.wd-carousel',
		'.elementor-widget-image-carousel', '.elementor-widget-slides',
		'.mrdc-vf-grid', '.mrp-sim-grid',
		'.widget', 'aside', 'footer'
	];

	function insideExcluded( node ) {
		for ( var i = 0; i < EXCLUDE.length; i++ ) {
			try {
				if ( node.closest( EXCLUDE[ i ] ) ) {
					return true;
				}
			} catch ( e ) {
				// Sélecteur non supporté : on ignore.
			}
		}
		return false;
	}

	function matchesTrigger( node ) {
		for ( var i = 0; i < D.selectors.length; i++ ) {
			try {
				if ( node.closest( D.selectors[ i ] ) ) {
					return node.closest( D.selectors[ i ] );
				}
			} catch ( e ) {
				// Selecteur invalide saisi dans les reglages : on l'ignore.
			}
		}
		return null;
	}

	function onDocumentClick( event ) {
		if ( state.open ) {
			return;
		}

		var node = event.target;
		if ( ! node || ! node.closest ) {
			return;
		}

		// Un clic a l'interieur du modal ne doit jamais etre intercepte.
		if ( node.closest( '#cswa-overlay' ) ) {
			return;
		}

		var trigger = matchesTrigger( node );
		if ( ! trigger ) {
			return;
		}

		// Bouton d'un produit suggéré : on ne touche à rien.
		if ( insideExcluded( trigger ) && ! ( D.isService && trigger.matches( '.cswa-trigger' ) && trigger.closest( '#coordonnees' ) ) ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();

		state.buttonNumber = readButtonNumber( trigger );
		if ( ! pendingSave && ! saveInFlight ) { state.idemKey = uuid(); }
		openModal();
	}

	/* ------------------------------------------------------------------ */
	/* Demarrage                                                           */
	/* ------------------------------------------------------------------ */

	function bind() {
		watchPurchaseLinks();
		overlay = document.getElementById( 'cswa-overlay' );
		if ( ! overlay ) {
			return;
		}

		sheet = document.getElementById( 'cswa-sheet' );
		form = document.getElementById( 'cswa-form' );
		globalError = document.getElementById( 'cswa-form-error' );
		recapBox = document.getElementById( 'cswa-product-recap' );
		waLink = document.getElementById( 'cswa-whatsapp' );
		closeBtn = document.getElementById( 'cswa-close' );
		modeHint = document.getElementById( 'cswa-mode-hint' );
		communeSel = document.getElementById( 'cswa-commune' );
		communeOther = document.getElementById( 'cswa-commune_other' );

		// Capture : on passe avant les gestionnaires du theme et du plugin WhatsApp.
		document.addEventListener( 'click', onDocumentClick, true );
		document.addEventListener( 'keydown', onKeydown );

		// Le formulaire n'a plus de bouton de soumission : on neutralise
		// la validation par la touche Entrée.
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			waLink.click();
		} );

		waLink.addEventListener( 'click', onWhatsAppClick );

		// Le lien suit la saisie, pour être prêt au moment du clic.
		form.addEventListener( 'input', refreshLink );
		form.addEventListener( 'change', refreshLink );

		// Commune : « Autre » révèle un champ libre.
		if ( communeSel && communeOther ) {
			communeSel.addEventListener( 'change', function () {
				var other = communeSel.value === '__autre';
				communeOther.hidden = ! other;
				if ( other ) {
					communeOther.focus();
				} else {
					communeOther.value = '';
				}
				refreshLink();
			} );
		}

		closeBtn.addEventListener( 'click', closeModal );

		overlay.addEventListener( 'click', function ( event ) {
			if ( event.target === overlay ) {
				closeModal();
			}
		} );

		Array.prototype.forEach.call( form.querySelectorAll( '[name="mode"]' ), function ( radio ) {
			radio.addEventListener( 'change', function () {
				var delivery = ( form.querySelector( '[name="mode"]:checked' ) || {} ).value === 'delivery';
				updateRequirements();
				refreshLink();
				modeHint.textContent = delivery
					? 'Cette adresse sera utilisée comme adresse de livraison. Les frais et le délai de livraison te seront confirmés sur WhatsApp.'
					: 'Pour le retrait, indique ton nom et ton téléphone. L’e-mail est facultatif.';
			} );
		} );

		// Le prix change quand le client choisit une variante.
		document.addEventListener( 'change', function ( event ) {
			if ( ! state.open ) {
				return;
			}
			if ( event.target.closest && event.target.closest( '#cswa-overlay' ) ) {
				return;
			}
			if ( event.target.name && ( event.target.name.indexOf( 'attribute_' ) === 0 || event.target.name === 'quantity' ) ) {
				loadQuote();
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bind );
	} else {
		bind();
	}
} )();
