import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Columns, ErrorNotice, Notice, PageHeader, ResponsiveTable, SkeletonCard, Stat, StatGrid, Tabs, useToast, formatNumber } from '../core3/ui';
import RecoveryChart from './RecoveryChart';
import { money, relTime, fmtDate } from './util';

const PERIODS = [
	{ id: '7', label: __( '7 days', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: '30', label: __( '30 days', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: '90', label: __( '90 days', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
];

/**
 * Recovered-revenue dashboard.
 *
 * @param {Object} props Props.
 * @return {Element} Screen.
 */
export default function Dashboard( { boot, patch, navigate, onUsage } ) {
	const toast = useToast();
	const [ days, setDays ] = useState( '30' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ running, setRunning ] = useState( false );
	const [ insights, setInsights ] = useState( null );
	const [ thinking, setThinking ] = useState( false );
	const [ aiError, setAiError ] = useState( null );

	const load = () => {
		setError( null );
		request( `dashboard?days=${ days }` ).then( setData ).catch( setError );
	};
	useEffect( load, [ days ] );

	const sweep = () => {
		setRunning( true );
		request( 'sweep', { method: 'POST' } )
			.then( ( res ) => {
				patch( { counts: res.counts, review_count: res.review_count, cron: { ...boot.cron, last: res.last } } );
				const s = res.stats;
				/* translators: 1: abandoned, 2: sent, 3: AI drafts */
				toast.success( sprintf( __( 'Scheduler ran: %1$d newly abandoned, %2$d sent, %3$d AI drafts.', 'dev3-ai-abandoned-cart-for-woocommerce' ), s.abandoned, s.sent, s.generated ) );
				load();
				onUsage();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setRunning( false ) );
	};

	const explain = () => {
		setThinking( true );
		setAiError( null );
		request( 'ai/insights', { method: 'POST', data: { days } } )
			.then( setInsights )
			.catch( setAiError )
			.finally( () => {
				setThinking( false );
				onUsage();
			} );
	};

	const k = data ? data.kpis : null;
	const cur = data ? data.currency : boot.store.currency;

	return (
		<>
			<PageHeader
				title={ __( 'Recovered revenue', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				description={ __( 'Carts captured at checkout, the reminders that went out, and the orders they brought back.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				actions={
					<>
						<Tabs tabs={ PERIODS } value={ days } onChange={ setDays } variant="pills" label={ __( 'Period', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						<Button icon="refresh" onClick={ sweep } loading={ running }>
							{ __( 'Run scheduler now', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
					</>
				}
			/>

			{ ! boot.woo && <Notice tone="error" title={ __( 'WooCommerce is not active', 'dev3-ai-abandoned-cart-for-woocommerce' ) }>{ __( 'Activate WooCommerce to capture carts.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Notice> }
			{ ! boot.settings.api_key_set && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI (optional)', 'dev3-ai-abandoned-cart-for-woocommerce' ) } actions={ <Button size="sm" variant="primary" href="#/api">{ __( 'Add your API key', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Button> }>
					{ __( 'Recovery emails already work with your approved templates. Add a Google Gemini key to personalise each email for the shopper\'s cart, with every AI draft reviewed by you first.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				</Notice>
			) }
			{ boot.review_count > 0 && (
				<Notice tone="warning" title={ sprintf( /* translators: %d: count */ __( '%d AI drafts are waiting for your review', 'dev3-ai-abandoned-cart-for-woocommerce' ), boot.review_count ) } actions={ <Button size="sm" onClick={ () => navigate( 'review' ) }>{ __( 'Open review queue', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Button> }>
					{ boot.settings.review_fallback === 'hold'
						? __( 'They wait for you (up to the hold time), then the approved template copy is sent instead.', 'dev3-ai-abandoned-cart-for-woocommerce' )
						: __( 'If you do not review them before their send time, the approved template copy is sent instead.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				</Notice>
			) }
			<ErrorNotice error={ error } onRetry={ load } />

			{ ! data && ! error && <SkeletonCard lines={ 4 } /> }
			{ data && (
				<>
					<StatGrid min={ 240 }>
						<Stat label={ __( 'Recovered revenue', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ money( k.recovered_value, cur ) } icon="bolt" tone="success" hint={ sprintf( /* translators: %d: orders */ __( '%d recovered orders', 'dev3-ai-abandoned-cart-for-woocommerce' ), k.recovered ) } />
						<Stat label={ __( 'Recovery rate', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ `${ k.recovery_rate }%` } icon="chart" tone="brand" hint={ sprintf( /* translators: %d: carts */ __( 'of %d abandoned carts', 'dev3-ai-abandoned-cart-for-woocommerce' ), k.abandoned ) } />
						<Stat label={ __( 'Still recoverable', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ money( k.recoverable_value, cur ) } icon="box" tone="warning" hint={ sprintf( /* translators: %d: carts */ __( '%d carts in a sequence', 'dev3-ai-abandoned-cart-for-woocommerce' ), k.recoverable_count ) } />
						<Stat label={ __( 'Carts captured', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ formatNumber( k.captured ) } icon="users" hint={ sprintf( /* translators: %s: value */ __( '%s abandoned in total', 'dev3-ai-abandoned-cart-for-woocommerce' ), money( k.abandoned_value, cur ) ) } />
						<Stat label={ __( 'Messages sent', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ formatNumber( k.messages_sent ) } icon="inbox" hint={ sprintf( /* translators: 1: open rate, 2: click rate */ __( '%1$s%% opened · %2$s%% clicked', 'dev3-ai-abandoned-cart-for-woocommerce' ), k.open_rate, k.click_rate ) } />
						<Stat label={ __( 'Coupons redeemed', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ formatNumber( k.coupons_redeemed ) } icon="sparkles" hint={ sprintf( /* translators: %d: AI messages */ __( '%d messages used AI copy', 'dev3-ai-abandoned-cart-for-woocommerce' ), k.ai_messages ) } />
					</StatGrid>

					<Card title={ __( 'Abandoned vs recovered', 'dev3-ai-abandoned-cart-for-woocommerce' ) } description={ __( 'Daily value of carts that were abandoned and of orders brought back by reminders.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }>
						<RecoveryChart series={ data.series } currency={ cur } />
					</Card>

					<Columns min={ 360 }>
						<Card title={ __( 'Step funnel', 'dev3-ai-abandoned-cart-for-woocommerce' ) } description={ __( 'Sent → opened → clicked → recovered, per sequence step.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } flush>
							<ResponsiveTable
								caption={ __( 'Step funnel', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
								rowKey="key"
								rows={ data.funnel }
								empty={ { icon: 'inbox', title: __( 'No messages sent yet', 'dev3-ai-abandoned-cart-for-woocommerce' ), description: __( 'Numbers appear once the first reminders go out.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } }
								columns={ [
									{ key: 'step', label: __( 'Step', 'dev3-ai-abandoned-cart-for-woocommerce' ), primary: true, render: ( r ) => `${ r.sequence } · ${ r.step } (${ r.channel === 'sms' ? 'SMS' : __( 'email', 'dev3-ai-abandoned-cart-for-woocommerce' ) })` },
									{ key: 'sent', label: __( 'Sent', 'dev3-ai-abandoned-cart-for-woocommerce' ), align: 'end' },
									{ key: 'opened', label: __( 'Opened', 'dev3-ai-abandoned-cart-for-woocommerce' ), align: 'end', render: ( r ) => ( r.channel === 'sms' ? '—' : r.opened ) },
									{ key: 'clicked', label: __( 'Clicked', 'dev3-ai-abandoned-cart-for-woocommerce' ), align: 'end' },
									{ key: 'revenue', label: __( 'Recovered', 'dev3-ai-abandoned-cart-for-woocommerce' ), align: 'end', nowrap: true, render: ( r ) => `${ money( r.revenue, cur ) } (${ r.recovered })` },
								] }
							/>
						</Card>
						<Card title={ __( 'Most abandoned products', 'dev3-ai-abandoned-cart-for-woocommerce' ) } flush>
							<ResponsiveTable
								caption={ __( 'Most abandoned products', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
								rowKey="product_id"
								rows={ data.top_products.slice( 0, 6 ) }
								empty={ { icon: 'box', title: __( 'Nothing abandoned yet', 'dev3-ai-abandoned-cart-for-woocommerce' ) } }
								columns={ [
									{ key: 'name', label: __( 'Product', 'dev3-ai-abandoned-cart-for-woocommerce' ), primary: true },
									{ key: 'carts', label: __( 'Carts', 'dev3-ai-abandoned-cart-for-woocommerce' ), align: 'end' },
									{ key: 'value', label: __( 'Value', 'dev3-ai-abandoned-cart-for-woocommerce' ), align: 'end', nowrap: true, render: ( r ) => money( r.value, cur ) },
								] }
							/>
						</Card>
					</Columns>

					<Columns min={ 360 }>
						<Card title={ __( 'Recent recoveries', 'dev3-ai-abandoned-cart-for-woocommerce' ) } flush>
							<ResponsiveTable
								caption={ __( 'Recent recoveries', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
								rows={ data.recent }
								onRowClick={ ( r ) => navigate( `carts/${ r.id }` ) }
								empty={ { icon: 'bolt', title: __( 'No recoveries yet', 'dev3-ai-abandoned-cart-for-woocommerce' ), description: __( 'Recovered orders show up here with the step that won them back.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } }
								columns={ [
									{ key: 'email', label: __( 'Customer', 'dev3-ai-abandoned-cart-for-woocommerce' ), primary: true, render: ( r ) => ( r.first_name ? `${ r.first_name } ${ r.last_name }`.trim() : r.email ) },
									{ key: 'kind', label: __( 'Type', 'dev3-ai-abandoned-cart-for-woocommerce' ), render: ( r ) => <Badge tone={ r.kind === 'winback' ? 'info' : 'brand' }>{ r.kind === 'winback' ? __( 'Win-back', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Cart', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Badge> },
									{ key: 'recovered_step', label: __( 'Step', 'dev3-ai-abandoned-cart-for-woocommerce' ), align: 'end', render: ( r ) => ( r.recovered_step >= 0 ? r.recovered_step + 1 : '—' ) },
									{ key: 'recovered_total', label: __( 'Order', 'dev3-ai-abandoned-cart-for-woocommerce' ), align: 'end', nowrap: true, render: ( r ) => money( r.recovered_total, r.currency || cur ) },
									{ key: 'recovered_at', label: __( 'When', 'dev3-ai-abandoned-cart-for-woocommerce' ), align: 'end', nowrap: true, hideOnMobile: true, render: ( r ) => relTime( r.recovered_at ) },
								] }
							/>
						</Card>
						<Card
							tone="ai"
							title={ __( 'Recovery insights by DEV3 AI', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
							description={ __( 'A plain-language read of these numbers with suggestions. It changes nothing on its own.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						>
							{ ! insights && <AiButton onClick={ explain } loading={ thinking }>{ __( 'Explain my numbers with DEV3 AI', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</AiButton> }
							<ErrorNotice error={ aiError } onRetry={ explain } onDismiss={ () => setAiError( null ) } />
							{ insights && (
								<div className="dev3ac-insights">
									<p className="dev3ac-insights__headline">{ insights.headline }</p>
									{ insights.findings.length > 0 && (
										<>
											<h4>{ __( 'What we see', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</h4>
											<ul>
												{ insights.findings.map( ( f, i ) => (
													<li key={ i }>{ f }</li>
												) ) }
											</ul>
										</>
									) }
									{ insights.actions.length > 0 && (
										<>
											<h4>{ __( 'Try next', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</h4>
											<ul>
												{ insights.actions.map( ( f, i ) => (
													<li key={ i }>{ f }</li>
												) ) }
											</ul>
										</>
									) }
									<Button size="sm" variant="ghost" icon="refresh" onClick={ explain } loading={ thinking }>
										{ __( 'Refresh', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
									</Button>
								</div>
							) }
						</Card>
					</Columns>
					<p className="dev3ac-muted">
						{ data.last_sweep
							? sprintf( /* translators: 1: time, 2: time */ __( 'Scheduler last ran %1$s · next run %2$s (WP-Cron, every 5 minutes).', 'dev3-ai-abandoned-cart-for-woocommerce' ), fmtDate( new Date( data.last_sweep * 1000 ).toISOString().slice( 0, 19 ).replace( 'T', ' ' ) ), data.next_sweep && data.next_sweep * 1000 > Date.now() ? relTime( new Date( data.next_sweep * 1000 ).toISOString().slice( 0, 19 ).replace( 'T', ' ' ) ) : __( 'due now', 'dev3-ai-abandoned-cart-for-woocommerce' ) )
							: __( 'The scheduler has not run yet. It runs every 5 minutes through WP-Cron.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					</p>
				</>
			) }
		</>
	);
}
