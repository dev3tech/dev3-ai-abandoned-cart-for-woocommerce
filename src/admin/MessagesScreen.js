import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { fitFrame } from './frame';
import { Badge, Button, Card, ErrorNotice, Modal, PageHeader, ResponsiveTable, Select, Tabs, useToast } from '../core3/ui';
import { AI_STATE, COPY_SOURCE, fmtDate, messageState } from './util';

const FILTERS = [
	{ id: 'all', label: __( 'All', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: 'scheduled', label: __( 'Scheduled', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: 'review', label: __( 'In review', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: 'sent', label: __( 'Sent', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: 'opened', label: __( 'Opened', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: 'clicked', label: __( 'Clicked', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: 'failed', label: __( 'Failed', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: 'skipped', label: __( 'Skipped', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
];

/**
 * Message log.
 *
 * @param {Object} props Props.
 * @return {Element} Screen.
 */
export default function MessagesScreen( { navigate } ) {
	const toast = useToast();
	const [ status, setStatus ] = useState( 'all' );
	const [ channel, setChannel ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ open, setOpen ] = useState( null );

	const load = () => {
		setError( null );
		const q = new URLSearchParams( { page: String( page ), per_page: '25' } );
		if ( status !== 'all' ) {
			q.set( 'status', status );
		}
		if ( channel ) {
			q.set( 'channel', channel );
		}
		request( `messages?${ q.toString() }` ).then( setData ).catch( setError );
	};
	useEffect( load, [ status, channel, page ] );

	const view = ( row ) => {
		if ( row.status !== 'sent' && row.status !== 'failed' ) {
			navigate( `carts/${ row.cart_id }` );
			return;
		}
		request( `messages/${ row.id }` ).then( setOpen ).catch( ( e ) => toast.error( e ) );
	};

	return (
		<>
			<PageHeader title={ __( 'Messages', 'dev3-ai-abandoned-cart-for-woocommerce' ) } description={ __( 'Every scheduled, sent, skipped and failed email and SMS, with where its copy came from.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
			<Card flush>
				<div className="dev3ac-toolbar">
					<Tabs
						tabs={ FILTERS }
						value={ status }
						onChange={ ( v ) => {
							setStatus( v );
							setPage( 1 );
						} }
						label={ __( 'Message status', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					/>
					<Select
						label={ __( 'Channel', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						hideLabel
						value={ channel }
						onChange={ ( v ) => {
							setChannel( v );
							setPage( 1 );
						} }
						options={ [
							{ value: '', label: __( 'All channels', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
							{ value: 'email', label: __( 'Email', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
							{ value: 'sms', label: 'SMS' },
						] }
					/>
				</div>
				<ErrorNotice error={ error } onRetry={ load } />
				<ResponsiveTable
					caption={ __( 'Messages', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					loading={ ! data }
					rows={ data ? data.rows : [] }
					onRowClick={ view }
					pagination={ data ? { page, perPage: 25, total: data.total, onPageChange: setPage } : undefined }
					empty={ { icon: 'inbox', title: __( 'No messages here', 'dev3-ai-abandoned-cart-for-woocommerce' ), description: __( 'Messages are scheduled when a cart is abandoned.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } }
					columns={ [
						{ key: 'recipient', label: __( 'Recipient', 'dev3-ai-abandoned-cart-for-woocommerce' ), primary: true, render: ( r ) => ( <span className="dev3ac-who"><strong>{ r.name || r.recipient }</strong><small>{ r.subject || r.template }</small></span> ) },
						{ key: 'status', label: __( 'Status', 'dev3-ai-abandoned-cart-for-woocommerce' ), render: ( r ) => { const st = messageState( r ); return <Badge tone={ st.tone }>{ st.label }</Badge>; } },
						{ key: 'step', label: __( 'Step', 'dev3-ai-abandoned-cart-for-woocommerce' ), render: ( r ) => `${ r.kind === 'winback' ? __( 'Win-back', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Cart', 'dev3-ai-abandoned-cart-for-woocommerce' ) } · ${ r.step } · ${ r.channel === 'sms' ? 'SMS' : __( 'Email', 'dev3-ai-abandoned-cart-for-woocommerce' ) }` },
						{ key: 'copy_source', label: __( 'Copy', 'dev3-ai-abandoned-cart-for-woocommerce' ), hideOnMobile: true, render: ( r ) => ( r.status === 'sent' ? COPY_SOURCE[ r.copy_source ] || r.copy_source : AI_STATE[ r.ai_state ] || __( 'Template', 'dev3-ai-abandoned-cart-for-woocommerce' ) ) },
						{ key: 'when', label: __( 'When', 'dev3-ai-abandoned-cart-for-woocommerce' ), align: 'end', nowrap: true, render: ( r ) => fmtDate( r.sent_at || r.scheduled_at ) },
					] }
				/>
			</Card>
			<Modal
				open={ !! open }
				onClose={ () => setOpen( null ) }
				size="lg"
				title={ open ? open.subject || __( 'SMS', 'dev3-ai-abandoned-cart-for-woocommerce' ) : '' }
				description={ open ? `${ open.recipient } · ${ fmtDate( open.sent_at ) }${ open.error ? ` · ${ open.error }` : '' }` : '' }
				footer={
					open && (
						<Button variant="ghost" onClick={ () => navigate( `carts/${ open.cart_id }` ) }>
							{ __( 'Open cart', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
					)
				}
			>
				{ open && open.channel === 'email' && open.body && <iframe className="dev3ac-preview-frame" title={ __( 'Sent email', 'dev3-ai-abandoned-cart-for-woocommerce' ) } srcDoc={ open.body } sandbox="allow-same-origin" onLoad={ fitFrame } /> }
				{ open && open.channel === 'sms' && (
					<div className="dev3ac-sms">
						<div className="dev3ac-sms__bubble">{ open.body }</div>
					</div>
				) }
			</Modal>
		</>
	);
}
