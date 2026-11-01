import { toDate, relTime, couponLabel, delayLabel, messageState } from '../../src/admin/util';
import { niceMax } from '../../src/admin/RecoveryChart';

test( 'toDate parses UTC MySQL datetimes', () => {
	expect( toDate( '2026-10-25 10:00:00' ).toISOString() ).toBe( '2026-10-25T10:00:00.000Z' );
	expect( toDate( '' ) ).toBeNull();
} );

test( 'relTime', () => {
	const now = Date.parse( '2026-10-25T10:00:00Z' );
	expect( relTime( '2026-10-25 12:00:00', now ) ).toBe( 'in 2 h' );
	expect( relTime( '2026-10-25 09:55:00', now ) ).toBe( '5 min ago' );
	expect( relTime( '2026-10-20 10:00:00', now ) ).toBe( '5 days ago' );
} );

test( 'couponLabel and delayLabel', () => {
	expect( couponLabel( { enabled: false } ) ).toBe( 'No coupon' );
	expect( couponLabel( { enabled: true, type: 'percent', amount: 10, expiry_days: 3 } ) ).toBe( '10% off · 3 days' );
	expect( couponLabel( { enabled: true, type: 'free_shipping', amount: 0, expiry_days: 3 } ) ).toBe( 'Free shipping' );
	expect( delayLabel( { delay_value: 2, delay_unit: 'days' } ) ).toBe( '2 days' );
} );

test( 'messageState folds review/opened/clicked', () => {
	expect( messageState( { status: 'scheduled', ai_state: 'review' } ).label ).toBe( 'In review' );
	expect( messageState( { status: 'sent', opened_at: 'x' } ).label ).toBe( 'Opened' );
	expect( messageState( { status: 'sent', opened_at: 'x', clicked_at: 'y' } ).label ).toBe( 'Clicked' );
	expect( messageState( { status: 'failed' } ).tone ).toBe( 'danger' );
} );

test( 'niceMax rounds the axis up', () => {
	expect( niceMax( 0 ) ).toBe( 10 );
	expect( niceMax( 87 ) ).toBe( 100 );
	expect( niceMax( 130 ) ).toBe( 200 );
	expect( niceMax( 4100 ) ).toBe( 5000 );
} );
