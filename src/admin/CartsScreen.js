import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Drawer, ErrorNotice, Inline, Modal, PageHeader, ResponsiveTable, SkeletonCard, Tabs, TextField, useToast } from '../core3/ui';
import { CART_STATUS, COPY_SOURCE, fmtDate, messageState, money, relTime } from './util';

const TABS = [ 'all', 'active', 'abandoned', 'recovering', 'recovered', 'converted', 'lost', 'unsubscribed' ];

/**
 * Cart detail drawer body.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.cart     Cart.
 * @param {Function} props.onAction Action handler.
 * @param {string}   props.busy     Busy action.
 * @return {Element} Detail.
 */
export function CartDetail( { cart, onAction, busy } ) {
	const toast = useToast();
	const open = [ 'abandoned', 'recovering' ].includes( cart.status );
	const copy = () => {
		if ( window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( cart.link ).then( () => toast.success( __( 'Recovery link copied.', 'dev3-ai-abandoned-cart-for-woocommerce' ) ) );
		}
	};
	return (
		<div className="dev3ac-detail">
			<Inline gap={ 8 }>
				<Badge tone={ ( CART_STATUS[ cart.status ] || {} ).tone }>{ ( CART_STATUS[ cart.status ] || {} ).label || cart.status }</Badge>
				<Badge tone={ cart.kind === 'winback' ? 'info' : 'neutral' }>{ cart.kind === 'winback' ? __( 'Win-back', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Cart', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Badge>
				{ cart.sms_consent && <Badge tone="success">{ __( 'SMS consent', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Badge> }
			</Inline>
			<dl className="dev3ac-dl">
				<dt>{ __( 'Email', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</dt>
				<dd>{ cart.email || '—' }</dd>
				<dt>{ __( 'Phone', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</dt>
				<dd>{ cart.phone || '—' }</dd>
				<dt>{ __( 'Captured', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</dt>
				<dd>{ `${ fmtDate( cart.created_at ) } · ${ cart.source || '—' }` }</dd>
				<dt>{ __( 'Consent', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</dt>
				<dd>{ cart.consent ? `${ cart.consent } · ${ fmtDate( cart.consent_at ) }` : '—' }</dd>
				<dt>{ __( 'Sequence', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</dt>
				<dd>{ cart.sequence ? sprintf( /* translators: 1: name, 2: sent, 3: total */ __( '%1$s · %2$d of %3$d steps done', 'dev3-ai-abandoned-cart-for-woocommerce' ), cart.sequence, Math.min( cart.step_index, cart.step_count ), cart.step_count ) : '—' }</dd>
				{ cart.next_send_at && (
					<>
						<dt>{ __( 'Next message', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</dt>
						<dd>{ `${ fmtDate( cart.next_send_at ) } (${ relTime( cart.next_send_at ) })` }</dd>
					</>
				) }
				{ cart.order_id > 0 && (
					<>
						<dt>{ __( 'Order', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</dt>
						<dd>
							{ cart.order_url ? <a href={ cart.order_url }>{ `#${ cart.order_id }` }</a> : `#${ cart.order_id }` }
							{ cart.recovered_total > 0 && ` · ${ money( cart.recovered_total, cart.currency ) }` }
							{ cart.coupon_used && ` · ${ __( 'coupon used', 'dev3-ai-abandoned-cart-for-woocommerce' ) }` }
						</dd>
					</>
				) }
			</dl>
			<h3 className="dev3ac-h3">{ __( 'Items', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</h3>
			<ul className="dev3ac-items">
				{ cart.items.map( ( item, i ) => (
					<li key={ i }>
						<span>
							{ `${ item.quantity }× ${ item.name }` }
							{ item.variation && Object.keys( item.variation ).length > 0 && <small>{ Object.values( item.variation ).join( ', ' ) }</small> }
						</span>
						<span>{ money( item.line_total, cart.currency ) }</span>
					</li>
				) ) }
				<li className="is-total">
					<span>{ __( 'Total', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</span>
					<span>{ money( cart.total, cart.currency ) }</span>
				</li>
			</ul>
			<h3 className="dev3ac-h3">{ __( 'Timeline', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</h3>
			{ cart.messages.length === 0 ? (
				<p className="dev3ac-muted">{ __( 'No messages yet.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</p>
			) : (
				<ol className="dev3ac-timeline">
					{ cart.messages.map( ( m ) => {
						const st = messageState( m );
						return (
							<li key={ m.id }>
								<div className="dev3ac-timeline__row">
									<strong>{ sprintf( /* translators: 1: step, 2: channel */ __( 'Step %1$d · %2$s', 'dev3-ai-abandoned-cart-for-woocommerce' ), m.step, m.channel === 'sms' ? 'SMS' : __( 'Email', 'dev3-ai-abandoned-cart-for-woocommerce' ) ) }</strong>
									<Badge tone={ st.tone }>{ st.label }</Badge>
								</div>
								<div className="dev3ac-muted">
									{ m.subject || m.template }
									{ m.status === 'sent' && ` · ${ COPY_SOURCE[ m.copy_source ] || m.copy_source }` }
									{ m.coupon_code && ` · ${ m.coupon_code }` }
								</div>
								<div className="dev3ac-muted">{ fmtDate( m.sent_at || m.scheduled_at ) }{ m.error ? ` · ${ m.error }` : '' }</div>
							</li>
						);
					} ) }
				</ol>
			) }
			<div className="dev3ac-detail__actions">
				{ cart.status === 'active' && (
					<Button onClick={ () => onAction( 'abandon' ) } loading={ busy === 'abandon' }>
						{ __( 'Mark abandoned now', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					</Button>
				) }
				{ open && (
					<Button variant="primary" icon="bolt" onClick={ () => onAction( 'send_now' ) } loading={ busy === 'send_now' }>
						{ __( 'Send next step now', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					</Button>
				) }
				{ open && (
					<Button icon="pause" onClick={ () => onAction( 'stop' ) } loading={ busy === 'stop' }>
						{ __( 'Stop sequence', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					</Button>
				) }
				{ [ 'lost', 'stopped' ].includes( cart.status ) && (
					<Button icon="refresh" onClick={ () => onAction( 'restart' ) } loading={ busy === 'restart' }>
						{ __( 'Restart sequence', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					</Button>
				) }
				<Button variant="ghost" icon="copy" onClick={ copy }>
					{ __( 'Copy recovery link', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				</Button>
				<Button variant="danger" icon="trash" onClick={ () => onAction( 'delete' ) } loading={ busy === 'delete' }>
					{ __( 'Delete', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Carts screen.
 *
 * @param {Object} props Props.
 * @return {Element} Screen.
 */
export default function CartsScreen( { boot, patch, navigate, params } ) {
	const toast = useToast();
	const [ status, setStatus ] = useState( 'all' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ detail, setDetail ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ confirm, setConfirm ] = useState( false );
	const openId = params && params[ 0 ] ? parseInt( params[ 0 ], 10 ) : 0;

	const load = () => {
		setError( null );
		const q = new URLSearchParams( { page: String( page ), per_page: '20' } );
		if ( status !== 'all' ) {
			q.set( 'status', status );
		}
		if ( query ) {
			q.set( 'search', query );
		}
		request( `carts?${ q.toString() }` )
			.then( ( res ) => {
				setData( res );
				patch( { counts: res.counts } );
			} )
			.catch( setError );
	};
	useEffect( load, [ status, query, page ] );

	useEffect( () => {
		if ( ! openId ) {
			setDetail( null );
			return;
		}
		request( `carts/${ openId }` ).then( setDetail ).catch( ( e ) => toast.error( e ) );
	}, [ openId ] );

	const act = ( action ) => {
		if ( action === 'delete' && ! confirm ) {
			setConfirm( true );
			return;
		}
		setConfirm( false );
		setBusy( action );
		request( `carts/${ detail.id }/action`, { method: 'POST', data: { action } } )
			.then( ( res ) => {
				if ( action === 'delete' ) {
					toast.success( __( 'Cart deleted.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
					navigate( 'carts' );
				} else {
					setDetail( res );
					const labels = {
						send_now: res.result === 'sent' ? __( 'Message sent.', 'dev3-ai-abandoned-cart-for-woocommerce' ) : sprintf( /* translators: %s: result */ __( 'Result: %s', 'dev3-ai-abandoned-cart-for-woocommerce' ), res.result ),
						stop: __( 'Sequence stopped.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
						restart: __( 'Sequence restarted from step 1.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
						abandon: __( 'Cart marked abandoned. Step 1 is scheduled.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
					};
					toast.success( labels[ action ] );
					patch( { review_count: res.reviewNext } );
				}
				patch( { counts: res.counts } );
				load();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const counts = ( data && data.counts ) || boot.counts;
	const tabs = TABS.map( ( id ) => ( {
		id,
		label: id === 'all' ? __( 'All', 'dev3-ai-abandoned-cart-for-woocommerce' ) : CART_STATUS[ id ].label,
		badge: counts[ id ] ? String( counts[ id ] ) : undefined,
	} ) );

	return (
		<>
			<PageHeader title={ __( 'Carts', 'dev3-ai-abandoned-cart-for-woocommerce' ) } description={ __( 'Every cart captured at checkout, with its consent, sequence progress and outcome.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
			<Card flush>
				<div className="dev3ac-toolbar">
					<Tabs
						tabs={ tabs }
						value={ status }
						onChange={ ( v ) => {
							setStatus( v );
							setPage( 1 );
						} }
						label={ __( 'Cart status', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					/>
					<form
						className="dev3ac-search"
						onSubmit={ ( e ) => {
							e.preventDefault();
							setPage( 1 );
							setQuery( search.trim() );
						} }
					>
						<TextField label={ __( 'Search carts', 'dev3-ai-abandoned-cart-for-woocommerce' ) } hideLabel type="search" value={ search } onChange={ setSearch } placeholder={ __( 'Search email, name or phone', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						<Button type="submit" icon="search" label={ __( 'Search', 'dev3-ai-abandoned-cart-for-woocommerce' ) }>
							{ __( 'Search', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
					</form>
				</div>
				<ErrorNotice error={ error } onRetry={ load } />
				<ResponsiveTable
					caption={ __( 'Captured carts', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					loading={ ! data }
					rows={ data ? data.rows : [] }
					onRowClick={ ( r ) => navigate( `carts/${ r.id }` ) }
					pagination={ data ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
					empty={ {
						icon: 'box',
						title: query ? __( 'No carts match your search', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'No carts here yet', 'dev3-ai-abandoned-cart-for-woocommerce' ),
						description: __( 'Carts appear as soon as a shopper types their email at checkout (classic or block).', 'dev3-ai-abandoned-cart-for-woocommerce' ),
					} }
					columns={ [
						{ key: 'email', label: __( 'Shopper', 'dev3-ai-abandoned-cart-for-woocommerce' ), primary: true, render: ( r ) => ( <span className="dev3ac-who"><strong>{ r.name || r.email || r.phone }</strong>{ r.name && <small>{ r.email }</small> }</span> ) },
						{ key: 'status', label: __( 'Status', 'dev3-ai-abandoned-cart-for-woocommerce' ), render: ( r ) => <Badge tone={ ( CART_STATUS[ r.status ] || {} ).tone }>{ ( CART_STATUS[ r.status ] || {} ).label || r.status }</Badge> },
						{ key: 'items', label: __( 'Items', 'dev3-ai-abandoned-cart-for-woocommerce' ), hideOnMobile: true, render: ( r ) => r.items.map( ( i ) => i.name ).slice( 0, 2 ).join( ', ' ) + ( r.items.length > 2 ? ` +${ r.items.length - 2 }` : '' ) },
						{ key: 'total', label: __( 'Value', 'dev3-ai-abandoned-cart-for-woocommerce' ), align: 'end', nowrap: true, render: ( r ) => money( r.status === 'recovered' ? r.recovered_total : r.total, r.currency ) },
						{ key: 'step', label: __( 'Progress', 'dev3-ai-abandoned-cart-for-woocommerce' ), hideOnMobile: true, render: ( r ) => ( r.step_count ? `${ Math.min( r.step_index, r.step_count ) }/${ r.step_count }` : '—' ) },
						{ key: 'updated_at', label: __( 'Last activity', 'dev3-ai-abandoned-cart-for-woocommerce' ), align: 'end', nowrap: true, render: ( r ) => relTime( r.recovered_at || r.updated_at ) },
					] }
				/>
			</Card>
			<Drawer
				open={ !! openId }
				onClose={ () => navigate( 'carts' ) }
				size="lg"
				title={ detail ? detail.name || detail.email : __( 'Cart', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				description={ detail ? sprintf( /* translators: 1: id, 2: total */ __( 'Cart #%1$d · %2$s', 'dev3-ai-abandoned-cart-for-woocommerce' ), detail.id, money( detail.total, detail.currency ) ) : '' }
			>
				{ detail ? <CartDetail cart={ detail } onAction={ act } busy={ busy } /> : <SkeletonCard lines={ 6 } /> }
			</Drawer>
			<Modal
				open={ confirm }
				onClose={ () => setConfirm( false ) }
				size="sm"
				title={ __( 'Delete this cart?', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				description={ __( 'The cart, its messages and its timeline are removed. This cannot be undone.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( false ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
						<Button variant="danger" onClick={ () => act( 'delete' ) }>
							{ __( 'Delete cart', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
