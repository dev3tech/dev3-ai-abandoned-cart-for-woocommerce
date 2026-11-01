/**
 * Checkout capture (classic + block) and the "Save my cart" form. Vanilla JS, no dependencies.
 * Shopper-facing text comes from the server (neutral, no branding).
 */

export const FIELDS = {
	email: [ '#billing_email', '#email', 'input[name="billing_email"]', '.wc-block-checkout input[type="email"]' ],
	phone: [ '#billing_phone', '#billing-phone', '#shipping-phone', 'input[name="billing_phone"]' ],
	first_name: [ '#billing_first_name', '#billing-first_name', '#shipping-first_name' ],
	last_name: [ '#billing_last_name', '#billing-last_name', '#shipping-last_name' ],
	country: [ '#billing_country', '#billing-country', '#shipping-country' ],
};

export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

/**
 * First matching element's value.
 *
 * @param {Document} doc       Document.
 * @param {Array}    selectors Selectors.
 * @return {string} Value.
 */
export function readField( doc, selectors ) {
	for ( const sel of selectors ) {
		const el = doc.querySelector( sel );
		if ( el && typeof el.value === 'string' && el.value.trim() !== '' ) {
			return el.value.trim();
		}
	}
	return '';
}

/**
 * Consent checkbox state (classic id, or block additional field ids containing "dev3ac").
 *
 * @param {Document} doc Document.
 * @param {boolean}  sms SMS checkbox.
 * @return {boolean|null} Checked, or null when no checkbox exists.
 */
export function readConsent( doc, sms ) {
	const boxes = Array.from( doc.querySelectorAll( 'input[type="checkbox"]' ) ).filter( ( el ) => {
		const id = ( el.id || '' ) + ' ' + ( el.name || '' );
		return id.indexOf( 'dev3ac' ) !== -1 && id.indexOf( 'consent' ) !== -1 && ( sms ? id.indexOf( 'sms' ) !== -1 : id.indexOf( 'sms' ) === -1 );
	} );
	return boxes.length ? boxes.some( ( el ) => el.checked ) : null;
}

/**
 * Builds the capture payload, or null when there is nothing to send.
 *
 * @param {Document} doc Document.
 * @param {Object}   cfg Config.
 * @return {Object|null} Payload.
 */
export function buildPayload( doc, cfg ) {
	const email = readField( doc, FIELDS.email );
	const phone = readField( doc, FIELDS.phone );
	const consent = readConsent( doc, false );
	const sms = readConsent( doc, true );
	if ( ! EMAIL_RE.test( email ) && ! ( phone && sms ) ) {
		return null;
	}
	if ( cfg.mode === 'opt_in' && consent === false && ! sms ) {
		// Explicit "no": tell the server so it forgets anything stored earlier.
		return { email: EMAIL_RE.test( email ) ? email : '', consent: false, source: doc.querySelector( '.wc-block-checkout' ) ? 'block' : 'classic' };
	}
	return {
		email: EMAIL_RE.test( email ) ? email : '',
		phone,
		first_name: readField( doc, FIELDS.first_name ),
		last_name: readField( doc, FIELDS.last_name ),
		country: readField( doc, FIELDS.country ).slice( 0, 2 ),
		consent: consent === null ? cfg.mode !== 'opt_in' : consent,
		sms_consent: !! sms,
		tz_offset: -new Date().getTimezoneOffset(),
		locale: ( doc.documentElement.lang || '' ).replace( '-', '_' ),
		source: doc.querySelector( '.wc-block-checkout' ) ? 'block' : 'classic',
	};
}

/**
 * POSTs JSON to a dev3ac route (same-origin cookies; nonce only for logged-in users).
 *
 * @param {Object} cfg  Config.
 * @param {string} path Route.
 * @param {Object} data Body.
 * @return {Promise<Object>} JSON.
 */
export function post( cfg, path, data ) {
	const headers = { 'Content-Type': 'application/json' };
	if ( cfg.nonce ) {
		headers[ 'X-WP-Nonce' ] = cfg.nonce;
	}
	return window
		.fetch( cfg.restUrl + path, { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify( data ) } )
		.then( ( r ) => r.json().then( ( body ) => ( r.ok ? body : Promise.reject( body ) ) ) );
}

/**
 * Inserts the consent notice next to the block checkout email field (notice mode).
 *
 * @param {Document} doc Document.
 * @param {Object}   cfg Config.
 */
export function placeNotice( doc, cfg ) {
	if ( ! cfg.notice ) {
		return;
	}
	const email = doc.querySelector( '.wc-block-checkout #email' );
	if ( ! email || doc.querySelector( '.dev3ac-consent-note--block' ) ) {
		return;
	}
	const wrap = email.closest( '.wc-block-components-text-input' ) || email.parentNode;
	const note = doc.createElement( 'p' );
	note.className = 'dev3ac-consent-note dev3ac-consent-note--block';
	note.textContent = cfg.notice;
	wrap.parentNode.insertBefore( note, wrap.nextSibling );
}

/**
 * Starts checkout capture.
 *
 * @param {Document} doc Document.
 * @param {Object}   cfg Config.
 * @return {Function} Flush (for tests).
 */
export function startCapture( doc, cfg ) {
	let timer = null;
	let last = '';
	const flush = () => {
		const payload = buildPayload( doc, cfg );
		if ( ! payload ) {
			return Promise.resolve( null );
		}
		const key = JSON.stringify( payload );
		if ( key === last ) {
			return Promise.resolve( null );
		}
		if ( ( payload.source === 'block' && ! cfg.block ) || ( payload.source === 'classic' && ! cfg.classic ) ) {
			return Promise.resolve( null );
		}
		last = key;
		return post( cfg, 'capture', payload ).catch( () => {
			last = '';
		} );
	};
	const schedule = ( e ) => {
		const t = e && e.target;
		if ( t && t.matches && ! t.matches( 'input, select' ) ) {
			return;
		}
		clearTimeout( timer );
		timer = setTimeout( flush, 1200 );
	};
	doc.addEventListener( 'input', schedule, true );
	doc.addEventListener( 'change', schedule, true );
	doc.addEventListener( 'focusout', schedule, true );
	if ( cfg.mode === 'notice' && typeof window.MutationObserver !== 'undefined' ) {
		placeNotice( doc, cfg );
		new window.MutationObserver( () => placeNotice( doc, cfg ) ).observe( doc.body, { childList: true, subtree: true } );
	}
	setTimeout( flush, 1500 ); // Autofilled fields.
	return flush;
}

/**
 * Wires every "Save my cart" form.
 *
 * @param {Document} doc Document.
 * @param {Object}   cfg Config.
 */
export function startSaveForms( doc, cfg ) {
	doc.querySelectorAll( '[data-dev3ac-save] form' ).forEach( ( form ) => {
		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			const status = form.querySelector( '.dev3ac-save__status' );
			const input = form.querySelector( 'input[type="email"]' );
			const box = form.querySelector( 'input[name="consent"]' );
			const btn = form.querySelector( 'button' );
			const email = input ? input.value.trim() : '';
			if ( ! EMAIL_RE.test( email ) ) {
				input.setAttribute( 'aria-invalid', 'true' );
				input.focus();
				return;
			}
			input.removeAttribute( 'aria-invalid' );
			btn.disabled = true;
			form.classList.add( 'is-busy' );
			post( cfg, 'save-cart', { email, consent: box ? box.checked : true, tz_offset: -new Date().getTimezoneOffset(), locale: ( doc.documentElement.lang || '' ).replace( '-', '_' ) } )
				.then( ( res ) => {
					status.textContent = res.message || '';
					form.classList.add( 'is-done' );
				} )
				.catch( ( err ) => {
					status.textContent = ( err && err.message ) || '';
					btn.disabled = false;
				} )
				.finally( () => form.classList.remove( 'is-busy' ) );
		} );
	} );
}
