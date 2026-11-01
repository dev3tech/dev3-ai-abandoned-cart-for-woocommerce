import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { fitFrame } from './frame';
import { AiDraftReview, Badge, Button, Card, EmptyState, ErrorNotice, Inline, Modal, Notice, PageHeader, SkeletonCard, Stack, useToast } from '../core3/ui';
import { fmtDate, money, relTime } from './util';

export const EMAIL_FIELDS = [
	{ key: 'subject', label: __( 'Subject', 'dev3-ai-abandoned-cart-for-woocommerce' ), maxLength: 90 },
	{ key: 'preheader', label: __( 'Preheader', 'dev3-ai-abandoned-cart-for-woocommerce' ), maxLength: 140 },
	{ key: 'heading', label: __( 'Heading', 'dev3-ai-abandoned-cart-for-woocommerce' ), maxLength: 80 },
	{ key: 'intro', label: __( 'Message', 'dev3-ai-abandoned-cart-for-woocommerce' ), multiline: true, maxLength: 600 },
	{ key: 'button', label: __( 'Button', 'dev3-ai-abandoned-cart-for-woocommerce' ), maxLength: 40 },
];
export const SMS_FIELDS = [ { key: 'sms', label: __( 'SMS text', 'dev3-ai-abandoned-cart-for-woocommerce' ), multiline: true, maxLength: 300 } ];

/**
 * One queue item.
 *
 * @param {Object} props Props.
 * @return {Element} Item.
 */
function ReviewItem( { row, boot, onDone, onUsage, onReplace } ) {
	const toast = useToast();
	const [ busy, setBusy ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const fields = row.channel === 'sms' ? SMS_FIELDS : EMAIL_FIELDS;
	const pick = ( obj ) => Object.fromEntries( fields.map( ( f ) => [ f.key, obj[ f.key ] || '' ] ) );

	const decide = ( decision, copy ) => {
		setBusy( decision );
		request( `review/${ row.id }`, { method: 'POST', data: { decision, copy } } )
			.then( ( res ) => {
				toast.success( decision === 'approve' ? __( 'Approved. It will send at its scheduled time.', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Rejected. The approved template copy will be sent instead.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
				onDone( row.id, res.review_count );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};
	const regenerate = () => {
		setBusy( 'regen' );
		request( `review/${ row.id }/regenerate`, { method: 'POST' } )
			.then( onReplace )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};
	const showPreview = () => {
		const tpl = boot.templates.find( ( t ) => t.id === row.template.id );
		if ( ! tpl ) {
			return;
		}
		request( 'templates/preview', { method: 'POST', data: { template: tpl, cart_id: row.cart.id, ai: row.draft } } )
			.then( setPreview )
			.catch( ( e ) => toast.error( e ) );
	};

	return (
		<Card
			title={ sprintf( /* translators: 1: name, 2: step */ __( '%1$s · step %2$d', 'dev3-ai-abandoned-cart-for-woocommerce' ), row.cart.name || row.cart.email, row.step ) }
			description={ `${ row.cart.items.join( ', ' ) } · ${ money( row.cart.total, row.cart.currency ) }` }
			actions={
				<Inline gap={ 8 }>
					<Badge tone={ row.cart.kind === 'winback' ? 'info' : 'brand' }>{ row.cart.kind === 'winback' ? __( 'Win-back', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Cart', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Badge>
					<Badge tone="warning">{ sprintf( /* translators: %s: relative time */ __( 'Sends %s', 'dev3-ai-abandoned-cart-for-woocommerce' ), relTime( row.scheduled_at ) ) }</Badge>
				</Inline>
			}
		>
			<AiDraftReview
				title={ sprintf( /* translators: %s: template */ __( 'DEV3 AI draft vs "%s"', 'dev3-ai-abandoned-cart-for-woocommerce' ), row.template.name ) }
				fields={ fields }
				original={ pick( row.template ) }
				draft={ pick( row.draft ) }
				meta={ { model: row.model, tokens: row.tokens } }
				acceptLabel={ __( 'Approve & schedule', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				onAccept={ ( values ) => decide( 'approve', values ) }
				onReject={ () => decide( 'reject' ) }
				onRegenerate={ regenerate }
				regenerating={ busy === 'regen' }
				accepting={ busy === 'approve' }
			/>
			{ row.channel === 'email' && (
				<div>
					<Button size="sm" variant="ghost" icon="external" onClick={ showPreview }>
						{ __( 'Preview full email', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					</Button>
				</div>
			) }
			<Modal open={ !! preview } onClose={ () => setPreview( null ) } size="lg" title={ preview ? preview.subject : '' } description={ preview ? preview.preheader : '' }>
				{ preview && <iframe className="dev3ac-preview-frame" title={ __( 'Email preview', 'dev3-ai-abandoned-cart-for-woocommerce' ) } srcDoc={ preview.html } sandbox="allow-same-origin" onLoad={ fitFrame } /> }
			</Modal>
		</Card>
	);
}

/**
 * Review queue: AI drafts that need a human decision before they can be sent.
 *
 * @param {Object} props Props.
 * @return {Element} Screen.
 */
export default function ReviewScreen( { boot, patch, navigate, onUsage } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const load = () => {
		setError( null );
		request( 'review' )
			.then( ( res ) => {
				setData( res );
				patch( { review_count: res.rows.length } );
			} )
			.catch( setError );
	};
	useEffect( load, [] );

	const done = ( id, count ) => {
		setData( ( d ) => ( { ...d, rows: d.rows.filter( ( r ) => r.id !== id ) } ) );
		patch( { review_count: count } );
	};
	const replace = ( row ) => setData( ( d ) => ( { ...d, rows: d.rows.map( ( r ) => ( r.id === row.id ? row : r ) ) } ) );

	return (
		<>
			<PageHeader
				title={ __( 'Review queue', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				description={ __( 'DEV3 AI personalises reminders for each cart. Nothing AI-written is sent until you approve it, unless you turned on auto-approve for that template.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				actions={ <Button icon="refresh" onClick={ load }>{ __( 'Refresh', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Button> }
			/>
			{ data && (
				<Notice tone="info">
					{ data.fallback === 'hold'
						? sprintf( /* translators: %d: hours */ __( 'Unreviewed drafts hold their message for up to %d hours after the send time; then the approved template copy is sent.', 'dev3-ai-abandoned-cart-for-woocommerce' ), data.hold )
						: __( 'Unreviewed drafts are skipped at send time: the approved template copy is sent instead.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }{ ' ' }
					<a href="#/settings">{ __( 'Change in Settings', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</a>
				</Notice>
			) }
			<ErrorNotice error={ error } onRetry={ load } />
			{ ! data && ! error && <SkeletonCard lines={ 6 } /> }
			{ data && data.rows.length === 0 && (
				<Card>
					<EmptyState
						icon="check"
						title={ __( 'All caught up', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						description={ __( 'New AI drafts appear here when a cart reaches a step whose template has AI personalisation on.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						action={ <Button onClick={ () => navigate( 'templates' ) }>{ __( 'Manage templates', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Button> }
					/>
				</Card>
			) }
			{ data && data.rows.length > 0 && (
				<Stack gap={ 16 }>
					{ data.rows.map( ( row ) => (
						<ReviewItem key={ row.id + ( row.tokens || 0 ) + row.draft.subject } row={ row } boot={ boot } onDone={ done } onUsage={ onUsage } onReplace={ replace } />
					) ) }
					<p className="dev3ac-muted">{ sprintf( /* translators: %s: date */ __( 'Oldest scheduled: %s', 'dev3-ai-abandoned-cart-for-woocommerce' ), fmtDate( data.rows[ 0 ].scheduled_at ) ) }</p>
				</Stack>
			) }
		</>
	);
}
