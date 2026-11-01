import { __, sprintf } from '@wordpress/i18n';
import { formatMoney } from '../core3/ui';



/**
 * Parses a UTC MySQL datetime ("Y-m-d H:i:s").
 *
 * @param {string} value Datetime.
 * @return {Date|null} Date.
 */
export function toDate( value ) {
	if ( ! value ) {
		return null;
	}
	const d = new Date( String( value ).replace( ' ', 'T' ) + 'Z' );
	return isNaN( d.getTime() ) ? null : d;
}

/**
 * Short local date + time.
 *
 * @param {string} value UTC datetime.
 * @return {string} Text.
 */
export function fmtDate( value ) {
	const d = toDate( value );
	return d ? d.toLocaleString( undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' } ) : '—';
}

/**
 * Relative time ("in 2 h", "5 min ago").
 *
 * @param {string} value UTC datetime.
 * @param {number} now   Now (ms), for tests.
 * @return {string} Text.
 */
export function relTime( value, now = Date.now() ) {
	const d = toDate( value );
	if ( ! d ) {
		return '—';
	}
	const diff = Math.round( ( d.getTime() - now ) / 60000 );
	const abs = Math.abs( diff );
	let text;
	if ( abs < 1 ) {
		return __( 'just now', 'dev3-ai-abandoned-cart-for-woocommerce' );
	} else if ( abs < 60 ) {
		/* translators: %d: minutes */
		text = sprintf( __( '%d min', 'dev3-ai-abandoned-cart-for-woocommerce' ), abs );
	} else if ( abs < 48 * 60 ) {
		/* translators: %d: hours */
		text = sprintf( __( '%d h', 'dev3-ai-abandoned-cart-for-woocommerce' ), Math.round( abs / 60 ) );
	} else {
		/* translators: %d: days */
		text = sprintf( __( '%d days', 'dev3-ai-abandoned-cart-for-woocommerce' ), Math.round( abs / 1440 ) );
	}
	/* translators: %s: duration */
	return diff > 0 ? sprintf( __( 'in %s', 'dev3-ai-abandoned-cart-for-woocommerce' ), text ) : sprintf( __( '%s ago', 'dev3-ai-abandoned-cart-for-woocommerce' ), text );
}

/**
 * Money in a currency (falls back to the store currency).
 *
 * @param {number} n        Amount.
 * @param {string} currency Currency.
 * @return {string} Text.
 */
export const money = ( n, currency ) => formatMoney( n, currency || 'USD' );

export const CART_STATUS = {
	active: { label: __( 'Active', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'info' },
	abandoned: { label: __( 'Abandoned', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'warning' },
	recovering: { label: __( 'Clicked', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'brand' },
	recovered: { label: __( 'Recovered', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'success' },
	converted: { label: __( 'Bought anyway', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'neutral' },
	lost: { label: __( 'Lost', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'danger' },
	unsubscribed: { label: __( 'Unsubscribed', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'neutral' },
	stopped: { label: __( 'Stopped', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'neutral' },
};

export const MESSAGE_STATUS = {
	scheduled: { label: __( 'Scheduled', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'info' },
	sent: { label: __( 'Sent', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'success' },
	failed: { label: __( 'Failed', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'danger' },
	skipped: { label: __( 'Skipped', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'neutral' },
	cancelled: { label: __( 'Cancelled', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'neutral' },
};

export const COPY_SOURCE = {
	template: __( 'Template', 'dev3-ai-abandoned-cart-for-woocommerce' ),
	ai: __( 'AI · reviewed', 'dev3-ai-abandoned-cart-for-woocommerce' ),
	ai_auto: __( 'AI · auto-approved', 'dev3-ai-abandoned-cart-for-woocommerce' ),
};

export const AI_STATE = {
	pending: __( 'AI copy queued', 'dev3-ai-abandoned-cart-for-woocommerce' ),
	review: __( 'Waiting for review', 'dev3-ai-abandoned-cart-for-woocommerce' ),
	approved: __( 'AI approved', 'dev3-ai-abandoned-cart-for-woocommerce' ),
	auto: __( 'AI auto-approved', 'dev3-ai-abandoned-cart-for-woocommerce' ),
	rejected: __( 'AI rejected', 'dev3-ai-abandoned-cart-for-woocommerce' ),
	expired: __( 'Review timed out', 'dev3-ai-abandoned-cart-for-woocommerce' ),
	failed: __( 'AI failed', 'dev3-ai-abandoned-cart-for-woocommerce' ),
};

/**
 * Message status, with the review state folded in.
 *
 * @param {Object} m Message.
 * @return {Object} { label, tone }.
 */
export function messageState( m ) {
	if ( m.status === 'scheduled' && m.ai_state === 'review' ) {
		return { label: __( 'In review', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'warning' };
	}
	if ( m.status === 'sent' && m.clicked_at ) {
		return { label: __( 'Clicked', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'brand' };
	}
	if ( m.status === 'sent' && m.opened_at ) {
		return { label: __( 'Opened', 'dev3-ai-abandoned-cart-for-woocommerce' ), tone: 'success' };
	}
	return MESSAGE_STATUS[ m.status ] || { label: m.status, tone: 'neutral' };
}

/**
 * Human step delay.
 *
 * @param {Object} step Step.
 * @return {string} Text.
 */
export function delayLabel( step ) {
	const units = { minutes: __( 'min', 'dev3-ai-abandoned-cart-for-woocommerce' ), hours: __( 'h', 'dev3-ai-abandoned-cart-for-woocommerce' ), days: __( 'days', 'dev3-ai-abandoned-cart-for-woocommerce' ) };
	return `${ step.delay_value } ${ units[ step.delay_unit ] || step.delay_unit }`;
}

/**
 * Coupon summary.
 *
 * @param {Object} c        Coupon config.
 * @param {string} currency Currency.
 * @return {string} Text.
 */
export function couponLabel( c, currency ) {
	if ( ! c || ! c.enabled ) {
		return __( 'No coupon', 'dev3-ai-abandoned-cart-for-woocommerce' );
	}
	if ( c.type === 'free_shipping' ) {
		return __( 'Free shipping', 'dev3-ai-abandoned-cart-for-woocommerce' );
	}
	const amount = c.type === 'percent' ? `${ c.amount }%` : money( c.amount, currency );
	/* translators: 1: amount, 2: days */
	return sprintf( __( '%1$s off · %2$d days', 'dev3-ai-abandoned-cart-for-woocommerce' ), amount, c.expiry_days );
}


