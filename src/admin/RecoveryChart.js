import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '../core3/ui';
import { money } from './util';

const SERIES = [
	{ key: 'abandoned_value', label: __( 'Abandoned value', 'dev3-ai-abandoned-cart-for-woocommerce' ), className: 'is-abandoned' },
	{ key: 'recovered_value', label: __( 'Recovered revenue', 'dev3-ai-abandoned-cart-for-woocommerce' ), className: 'is-recovered' },
];

/**
 * "Nice" axis maximum.
 *
 * @param {number} max Max value.
 * @return {number} Rounded.
 */
export function niceMax( max ) {
	if ( max <= 0 ) {
		return 10;
	}
	const pow = Math.pow( 10, Math.floor( Math.log10( max ) ) );
	const n = max / pow;
	const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
	return step * pow;
}

/**
 * Daily abandoned vs recovered value: paired bars, one axis (both are money), hover tooltip, table view.
 *
 * @param {Object} props          Props.
 * @param {Array}  props.series   [{ date, abandoned_value, recovered_value, abandoned, recovered }].
 * @param {string} props.currency Currency.
 * @return {Element} Chart.
 */
export default function RecoveryChart( { series, currency } ) {
	const wrap = useRef( null );
	const [ width, setWidth ] = useState( 640 );
	const [ hover, setHover ] = useState( null );
	const [ table, setTable ] = useState( false );

	useEffect( () => {
		const el = wrap.current;
		if ( ! el ) {
			return undefined;
		}
		const measure = () => setWidth( Math.max( 260, el.clientWidth ) );
		measure();
		if ( typeof window.ResizeObserver === 'undefined' ) {
			window.addEventListener( 'resize', measure );
			return () => window.removeEventListener( 'resize', measure );
		}
		const ro = new window.ResizeObserver( measure );
		ro.observe( el );
		return () => ro.disconnect();
	}, [ table ] );

	const height = 220;
	const pad = { top: 12, right: 8, bottom: 26, left: 56 };
	const innerW = width - pad.left - pad.right;
	const innerH = height - pad.top - pad.bottom;
	const max = niceMax( Math.max( 0, ...series.map( ( d ) => Math.max( d.abandoned_value, d.recovered_value ) ) ) );
	const slot = innerW / Math.max( 1, series.length );
	const gap = slot > 14 ? 2 : 1;
	const bar = Math.max( 1, Math.min( 18, ( slot - gap * 3 ) / 2 ) );
	const y = ( v ) => pad.top + innerH - ( v / max ) * innerH;
	const ticks = [ 0, max / 2, max ];
	const labelEvery = Math.ceil( series.length / Math.max( 2, Math.floor( innerW / 64 ) ) );
	const fmtDay = ( s ) => new Date( s + 'T12:00:00' ).toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
	const empty = series.every( ( d ) => ! d.abandoned_value && ! d.recovered_value );

	return (
		<div className="dev3ac-chart">
			<div className="dev3ac-chart__head">
				<ul className="dev3ac-chart__legend" aria-label={ __( 'Legend', 'dev3-ai-abandoned-cart-for-woocommerce' ) }>
					{ SERIES.map( ( s ) => (
						<li key={ s.key }>
							<span className={ `dev3ac-chart__swatch ${ s.className }` } aria-hidden="true" />
							{ s.label }
						</li>
					) ) }
				</ul>
				<Button size="sm" variant="ghost" icon={ table ? 'chart' : 'list' } onClick={ () => setTable( ! table ) } aria-pressed={ table }>
					{ table ? __( 'Show chart', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Show table', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				</Button>
			</div>
			{ table ? (
				<div className="dev3ac-chart__table">
					<table>
						<caption className="screen-reader-text">{ __( 'Abandoned and recovered value per day', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</caption>
						<thead>
							<tr>
								<th scope="col">{ __( 'Day', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</th>
								<th scope="col">{ __( 'Abandoned', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</th>
								<th scope="col">{ __( 'Recovered', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ series
								.filter( ( d ) => d.abandoned || d.recovered )
								.map( ( d ) => (
									<tr key={ d.date }>
										<th scope="row">{ fmtDay( d.date ) }</th>
										<td>{ `${ money( d.abandoned_value, currency ) } (${ d.abandoned })` }</td>
										<td>{ `${ money( d.recovered_value, currency ) } (${ d.recovered })` }</td>
									</tr>
								) ) }
						</tbody>
					</table>
				</div>
			) : (
				<div className="dev3ac-chart__plot" ref={ wrap } onMouseLeave={ () => setHover( null ) }>
					<svg width={ width } height={ height } role="img" aria-label={ __( 'Bar chart of abandoned value and recovered revenue per day', 'dev3-ai-abandoned-cart-for-woocommerce' ) }>
						{ ticks.map( ( t ) => (
							<g key={ t }>
								<line x1={ pad.left } x2={ width - pad.right } y1={ y( t ) } y2={ y( t ) } className="dev3ac-chart__grid" />
								<text x={ pad.left - 8 } y={ y( t ) + 4 } textAnchor="end" className="dev3ac-chart__tick">
									{ money( t, currency ).replace( /\.00$/, '' ) }
								</text>
							</g>
						) ) }
						{ series.map( ( d, i ) => {
							const x0 = pad.left + i * slot + ( slot - ( bar * 2 + gap ) ) / 2;
							return (
								<g key={ d.date }>
									{ SERIES.map( ( s, j ) => {
										const v = d[ s.key ];
										const h = v > 0 ? Math.max( 2, innerH - ( y( v ) - pad.top ) ) : 0;
										const r = Math.min( 4, bar / 2 );
										return h > 0 ? <rect key={ s.key } x={ x0 + j * ( bar + gap ) } y={ pad.top + innerH - h } width={ bar } height={ h } rx={ r } className={ `dev3ac-chart__bar ${ s.className }${ hover === i ? ' is-hover' : '' }` } /> : null;
									} ) }
									{ i % labelEvery === 0 && (
										<text x={ pad.left + i * slot + slot / 2 } y={ height - 6 } textAnchor="middle" className="dev3ac-chart__tick">
											{ fmtDay( d.date ) }
										</text>
									) }
									<rect x={ pad.left + i * slot } y={ pad.top } width={ slot } height={ innerH } fill="transparent" onMouseEnter={ () => setHover( i ) } onFocus={ () => setHover( i ) } />
								</g>
							);
						} ) }
						<line x1={ pad.left } x2={ width - pad.right } y1={ pad.top + innerH } y2={ pad.top + innerH } className="dev3ac-chart__axis" />
					</svg>
					{ empty && <p className="dev3ac-chart__empty">{ __( 'No abandoned carts in this period yet.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</p> }
					{ hover !== null && series[ hover ] && (
						<div className="dev3ac-chart__tip" style={ { left: Math.min( width - 190, Math.max( 0, pad.left + hover * slot + slot / 2 - 90 ) ) } } role="status">
							<strong>{ fmtDay( series[ hover ].date ) }</strong>
							<span>
								<i className="dev3ac-chart__swatch is-abandoned" aria-hidden="true" />
								{ `${ __( 'Abandoned', 'dev3-ai-abandoned-cart-for-woocommerce' ) }: ${ money( series[ hover ].abandoned_value, currency ) } (${ series[ hover ].abandoned })` }
							</span>
							<span>
								<i className="dev3ac-chart__swatch is-recovered" aria-hidden="true" />
								{ `${ __( 'Recovered', 'dev3-ai-abandoned-cart-for-woocommerce' ) }: ${ money( series[ hover ].recovered_value, currency ) } (${ series[ hover ].recovered })` }
							</span>
						</div>
					) }
				</div>
			) }
		</div>
	);
}
