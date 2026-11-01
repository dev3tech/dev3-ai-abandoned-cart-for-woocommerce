import apiFetch from '@wordpress/api-fetch';
import { render, click, change, act } from './render';
import { ToastProvider } from '../../src/core3/ui';
import ReviewScreen from '../../src/admin/ReviewScreen';
import TemplatesScreen from '../../src/admin/TemplatesScreen';
import SequencesScreen from '../../src/admin/SequencesScreen';
import CartsScreen from '../../src/admin/CartsScreen';

const tpl = ( over ) => ( { id: 'tpl_reminder', name: 'Cart · Friendly reminder', channel: 'email', subject: 'You left something', preheader: 'p', heading: 'h', body: 'Hi {first_name}', button: 'Return', sms: '', show_cart: true, ai_personalise: true, auto_approve: false, status: 'approved', ...over } );
const boot = {
	settings: { brand_tone: 'friendly', winback_days: 60, cutoff_minutes: 15, review_fallback: 'template' },
	templates: [ tpl(), tpl( { id: 'tpl_sms', name: 'SMS', channel: 'sms', sms: 'Hi {recovery_url}', ai_personalise: false } ) ],
	sequences: [ { id: 'seq_cart', name: 'Cart recovery', type: 'cart', enabled: true, steps: [ { id: 'st1', delay_value: 1, delay_unit: 'hours', channel: 'email', template_id: 'tpl_reminder', min_total: 0, coupon: { enabled: false, type: 'percent', amount: 10, expiry_days: 3, min_spend: 0 } } ] } ],
	counts: { all: 3, active: 1, abandoned: 2, recovering: 0, recovered: 0, converted: 0, lost: 0, unsubscribed: 0, stopped: 0 },
	merge_tags: [ 'first_name', 'recovery_url' ],
	store: { currency: 'USD', symbol: '$', name: 'Shop' },
	sms_ready: false,
	review_count: 1,
};
const wrap = ( el ) => <ToastProvider>{ el }</ToastProvider>;
const noop = () => {};

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
	window.location.hash = '';
} );

test( 'review queue shows the AI draft against the template and approves the edited copy', async () => {
	const row = { id: 9, channel: 'email', step: 1, scheduled_at: '2030-01-01 10:00:00', template: { id: 'tpl_reminder', name: 'Cart · Friendly reminder', subject: 'You left something', preheader: 'p', heading: 'h', intro: 'Hi', button: 'Return', sms: '' }, draft: { subject: 'Ann, your Linen Shirt is waiting', preheader: 'p2', heading: 'h2', intro: 'Hi Ann', button: 'Back to cart', sms: '' }, model: 'gemini-2.5-flash', tokens: 120, cart: { id: 3, kind: 'cart', name: 'Ann Lee', email: 'ann@example.com', total: 40, currency: 'USD', item_count: 1, items: [ '1× Linen Shirt' ] } };
	apiFetch.mockImplementation( ( { url, data } ) => {
		if ( url.endsWith( '/review' ) ) {
			return Promise.resolve( { rows: [ row ], fallback: 'template', hold: 6 } );
		}
		if ( url.endsWith( '/review/9' ) ) {
			return Promise.resolve( { id: 9, ai_state: data.decision === 'approve' ? 'approved' : 'rejected', review_count: 0 } );
		}
		return new Promise( () => {} );
	} );
	const patch = jest.fn();
	const view = await render( wrap( <ReviewScreen boot={ boot } patch={ patch } navigate={ noop } onUsage={ noop } /> ) );
	await view.flush();
	expect( view.queryByText( /Ann Lee · step 1/ ) ).not.toBeNull();
	expect( view.container.textContent ).toContain( 'Linen Shirt is waiting' );
	await click( view.getByText( 'Approve & schedule' ) );
	await view.flush();
	const call = apiFetch.mock.calls.find( ( c ) => c[ 0 ].url.endsWith( '/review/9' ) );
	expect( call[ 0 ].data.decision ).toBe( 'approve' );
	expect( call[ 0 ].data.copy.subject ).toBe( 'Ann, your Linen Shirt is waiting' );
	expect( view.queryByText( 'All caught up' ) ).not.toBeNull();
	expect( patch ).toHaveBeenLastCalledWith( { review_count: 0 } );
	view.unmount();
} );

test( 'empty review queue', async () => {
	apiFetch.mockResolvedValueOnce( { rows: [], fallback: 'hold', hold: 6 } );
	const view = await render( wrap( <ReviewScreen boot={ boot } patch={ noop } navigate={ noop } onUsage={ noop } /> ) );
	await view.flush();
	expect( view.queryByText( 'All caught up' ) ).not.toBeNull();
	view.unmount();
} );

test( 'templates list shows status and AI mode', async () => {
	const view = await render( wrap( <TemplatesScreen boot={ boot } patch={ noop } navigate={ noop } onUsage={ noop } params={ [] } /> ) );
	expect( view.container.textContent ).toContain( 'Cart · Friendly reminder' );
	expect( view.container.textContent ).toContain( 'Reviewed' );
	expect( view.container.textContent ).toContain( 'Cart recovery' );
	view.unmount();
} );

test( 'template editor saves a draft and warns about auto-approve', async () => {
	apiFetch.mockImplementation( ( { url, data } ) => {
		if ( url.endsWith( '/templates/preview' ) ) {
			return Promise.resolve( { channel: 'email', subject: 'S', preheader: 'P', html: '<p>x</p>', quality: [ { level: 'warning', code: 'preheader', message: 'Add a preheader' } ] } );
		}
		if ( url.endsWith( '/templates' ) ) {
			return Promise.resolve( { template: { ...data.template, status: data.template.approve ? 'approved' : 'draft' }, templates: boot.templates, quality: [] } );
		}
		return new Promise( () => {} );
	} );
	const patch = jest.fn();
	const view = await render( wrap( <TemplatesScreen boot={ boot } patch={ patch } navigate={ noop } onUsage={ noop } params={ [ 'tpl_reminder' ] } /> ) );
	const subject = view.$$( 'input' ).find( ( i ) => i.value === 'You left something' );
	await change( subject, 'Still thinking, {first_name}?' );
	const auto = view.$$( '[role="switch"], input[type="checkbox"]' ).find( ( el ) => ( el.closest( 'label' ) || el.parentNode ).textContent.includes( 'Auto-approve' ) || ( el.id && view.$( `label[for="${ el.id }"]` ) && view.$( `label[for="${ el.id }"]` ).textContent.includes( 'Auto-approve' ) ) );
	await click( auto );
	expect( view.container.textContent ).toContain( 'sent without a human review' );
	await act( async () => new Promise( ( r ) => setTimeout( r, 500 ) ) );
	await view.flush();
	expect( view.container.textContent ).toContain( 'Add a preheader' );
	await click( view.getByText( 'Save draft' ) );
	await view.flush();
	const save = apiFetch.mock.calls.find( ( c ) => c[ 0 ].url.endsWith( '/templates' ) );
	expect( save[ 0 ].data.template.subject ).toBe( 'Still thinking, {first_name}?' );
	expect( save[ 0 ].data.template.auto_approve ).toBe( true );
	expect( save[ 0 ].data.template.approve ).toBe( false );
	expect( patch ).toHaveBeenCalled();
	view.unmount();
} );

test( 'sequences: adding a step marks the form dirty and saves all sequences', async () => {
	apiFetch.mockImplementation( ( { data } ) => Promise.resolve( { sequences: data.sequences } ) );
	const patch = jest.fn();
	const view = await render( wrap( <SequencesScreen boot={ boot } patch={ patch } onUsage={ noop } /> ) );
	expect( view.queryByText( 'You have unsaved changes.' ) ).toBeNull();
	await click( view.getByText( 'Add email step' ) );
	expect( view.queryByText( 'You have unsaved changes.' ) ).not.toBeNull();
	await click( view.getByText( 'Save sequences' ) );
	await view.flush();
	const [ args ] = apiFetch.mock.calls[ 0 ];
	expect( args.url ).toContain( '/sequences' );
	expect( args.data.sequences[ 0 ].steps ).toHaveLength( 2 );
	expect( args.data.sequences[ 0 ].steps[ 1 ].template_id ).toBe( 'tpl_reminder' );
	view.unmount();
} );

test( 'carts list renders rows and status counts', async () => {
	apiFetch.mockResolvedValueOnce( {
		rows: [ { id: 3, kind: 'cart', email: 'ann@example.com', name: 'Ann Lee', status: 'abandoned', items: [ { name: 'Linen Shirt', quantity: 1, line_total: 40 } ], total: 40, currency: 'USD', step_index: 1, step_count: 3, updated_at: '2026-10-25 09:00:00', recovered_total: 0 } ],
		total: 1,
		counts: boot.counts,
	} );
	const view = await render( wrap( <CartsScreen boot={ boot } patch={ noop } navigate={ noop } params={ [] } /> ) );
	await view.flush();
	expect( view.container.textContent ).toContain( 'Ann Lee' );
	expect( view.container.textContent ).toContain( 'Linen Shirt' );
	expect( view.container.textContent ).toContain( '1/3' );
	expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toContain( 'carts?page=1' );
	view.unmount();
} );
