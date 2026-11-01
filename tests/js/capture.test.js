import { buildPayload, readConsent, startCapture, startSaveForms, placeNotice, EMAIL_RE } from '../../src/storefront/capture';

const cfg = { restUrl: '/wp-json/dev3ac/v1/', nonce: '', checkout: true, classic: true, block: true, mode: 'notice', notice: 'We save your cart.' };

const tick = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

beforeEach( () => {
	document.body.innerHTML = '';
	window.fetch = jest.fn( () => Promise.resolve( { ok: true, json: () => Promise.resolve( { captured: true } ) } ) );
} );

describe( 'buildPayload', () => {
	test( 'classic checkout fields', () => {
		document.body.innerHTML = '<form class="checkout"><input id="billing_email" value="ann@example.com"><input id="billing_first_name" value="Ann"><input id="billing_phone" value="+44 7700 900123"><select id="billing_country"><option value="GB" selected>UK</option></select></form>';
		const p = buildPayload( document, cfg );
		expect( p ).toMatchObject( { email: 'ann@example.com', first_name: 'Ann', country: 'GB', consent: true, sms_consent: false, source: 'classic' } );
		expect( typeof p.tz_offset ).toBe( 'number' );
	} );

	test( 'block checkout fields and additional consent fields', () => {
		document.body.innerHTML = '<div class="wc-block-checkout"><input id="email" value="bo@example.com"><input id="billing-phone" value="5550100"><input type="checkbox" id="contact-dev3ac-consent" checked><input type="checkbox" id="contact-dev3ac-sms-consent" checked></div>';
		const p = buildPayload( document, { ...cfg, mode: 'opt_in' } );
		expect( p ).toMatchObject( { email: 'bo@example.com', phone: '5550100', consent: true, sms_consent: true, source: 'block' } );
	} );

	test( 'nothing to send without a valid email or SMS opt-in', () => {
		document.body.innerHTML = '<input id="billing_email" value="not-an-email"><input id="billing_phone" value="123">';
		expect( buildPayload( document, cfg ) ).toBeNull();
	} );

	test( 'opt-in mode with the box unticked sends an explicit withdrawal only', () => {
		document.body.innerHTML = '<input id="billing_email" value="c@example.com"><input type="checkbox" id="dev3ac_consent">';
		expect( buildPayload( document, { ...cfg, mode: 'opt_in' } ) ).toEqual( { email: 'c@example.com', consent: false, source: 'classic' } );
	} );

	test( 'readConsent distinguishes marketing and SMS boxes', () => {
		document.body.innerHTML = '<input type="checkbox" id="dev3ac_consent"><input type="checkbox" id="dev3ac_sms_consent" checked>';
		expect( readConsent( document, false ) ).toBe( false );
		expect( readConsent( document, true ) ).toBe( true );
		document.body.innerHTML = '';
		expect( readConsent( document, false ) ).toBeNull();
	} );

	test( 'email pattern', () => {
		expect( EMAIL_RE.test( 'a@b.co' ) ).toBe( true );
		expect( EMAIL_RE.test( 'a@b' ) ).toBe( false );
	} );
} );

describe( 'startCapture', () => {
	test( 'posts once per distinct payload, with credentials and no nonce for guests', async () => {
		document.body.innerHTML = '<input id="billing_email" value="ann@example.com">';
		const flush = startCapture( document, cfg );
		await flush();
		await flush();
		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		const [ url, opts ] = window.fetch.mock.calls[ 0 ];
		expect( url ).toBe( '/wp-json/dev3ac/v1/capture' );
		expect( opts.credentials ).toBe( 'same-origin' );
		expect( opts.headers[ 'X-WP-Nonce' ] ).toBeUndefined();
		document.getElementById( 'billing_email' ).value = 'ann2@example.com';
		await flush();
		expect( window.fetch ).toHaveBeenCalledTimes( 2 );
	} );

	test( 'respects the per-checkout switches', async () => {
		document.body.innerHTML = '<div class="wc-block-checkout"><input id="email" value="ann@example.com"></div>';
		const flush = startCapture( document, { ...cfg, block: false } );
		await flush();
		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	test( 'notice is placed after the block email field once', () => {
		document.body.innerHTML = '<div class="wc-block-checkout"><div class="wc-block-components-text-input"><input id="email"></div></div>';
		placeNotice( document, cfg );
		placeNotice( document, cfg );
		const notes = document.querySelectorAll( '.dev3ac-consent-note--block' );
		expect( notes ).toHaveLength( 1 );
		expect( notes[ 0 ].textContent ).toBe( 'We save your cart.' );
	} );
} );

describe( 'save my cart form', () => {
	const markup = '<div data-dev3ac-save><form><input type="email" name="email"><button type="submit">Go</button><p class="dev3ac-save__status"></p></form></div>';

	test( 'invalid email is flagged without a request', () => {
		document.body.innerHTML = markup;
		startSaveForms( document, cfg );
		document.querySelector( 'form' ).dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
		expect( document.querySelector( 'input' ).getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	test( 'valid email posts and shows the server message', async () => {
		window.fetch = jest.fn( () => Promise.resolve( { ok: true, json: () => Promise.resolve( { saved: true, message: 'Check your inbox' } ) } ) );
		document.body.innerHTML = markup;
		startSaveForms( document, cfg );
		document.querySelector( 'input' ).value = 'dee@example.com';
		document.querySelector( 'form' ).dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
		await tick( 0 );
		await tick( 0 );
		expect( window.fetch.mock.calls[ 0 ][ 0 ] ).toBe( '/wp-json/dev3ac/v1/save-cart' );
		expect( JSON.parse( window.fetch.mock.calls[ 0 ][ 1 ].body ).email ).toBe( 'dee@example.com' );
		expect( document.querySelector( '.dev3ac-save__status' ).textContent ).toBe( 'Check your inbox' );
		expect( document.querySelector( 'form' ).classList.contains( 'is-done' ) ).toBe( true );
	} );
} );
