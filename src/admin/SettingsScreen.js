import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, Checkbox, ErrorNotice, Field, FormGrid, Modal, Notice, NumberField, PageHeader, Select, Stack, Tabs, Textarea, TextField, Toggle, useToast } from '../core3/ui';

const SECTIONS = [
	{ id: 'capture', label: __( 'Capture & consent', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: 'sending', label: __( 'Sending & timing', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: 'coupons', label: __( 'Coupons', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: 'sms', label: 'SMS' },
	{ id: 'winback', label: __( 'Win-back', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ id: 'data', label: __( 'Data & privacy', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
];

const KEYS = [
	'tracking_enabled', 'capture_classic', 'capture_block', 'capture_logged_in', 'cutoff_minutes', 'min_cart_total', 'exclude_roles', 'consent_mode', 'consent_text', 'consent_label', 'sms_consent_label',
	'sending_enabled', 'from_name', 'from_email', 'review_fallback', 'review_hold_hours', 'send_time_tuning', 'quiet_start', 'quiet_end', 'max_shift_hours', 'best_hours', 'batch_size', 'ai_batch_size', 'lost_after_days', 'redirect_to', 'brand_tone', 'brand_notes',
	'coupon_prefix', 'coupon_cleanup', 'sms_enabled', 'sms_base_url', 'sms_account_sid', 'sms_auth_token', 'sms_from', 'sms_messaging_service', 'winback_enabled', 'winback_days', 'winback_window_days', 'retention_days', 'save_cart_widget', 'delete_on_uninstall',
];

const HOURS = Array.from( { length: 24 }, ( _, h ) => ( { value: String( h ), label: `${ String( h ).padStart( 2, '0' ) }:00` } ) );
const SEND_FIELDS = [
	{ key: 'best_hours', label: __( 'Best hours', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ key: 'quiet_hours', label: __( 'Quiet hours', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
	{ key: 'max_shift_hours', label: __( 'Max shift (hours)', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
];

/**
 * Small per-hour histogram (clicks) for the send-time card.
 *
 * @param {Object} props       Props.
 * @param {Object} props.hours { opens, clicks, orders }.
 * @return {Element} Bars.
 */
function HourBars( { hours } ) {
	const values = hours.opens.map( ( o, h ) => o + hours.clicks[ h ] );
	const max = Math.max( 1, ...values );
	const total = values.reduce( ( a, b ) => a + b, 0 );
	return (
		<figure className="dev3ac-hours">
			<div className="dev3ac-hours__plot" role="img" aria-label={ __( 'Opens and clicks by hour of day', 'dev3-ai-abandoned-cart-for-woocommerce' ) }>
				{ values.map( ( v, h ) => (
					<span key={ h } className="dev3ac-hours__bar" style={ { height: `${ Math.max( 2, ( v / max ) * 100 ) }%` } } title={ `${ String( h ).padStart( 2, '0' ) }:00 · ${ v }` } />
				) ) }
			</div>
			<div className="dev3ac-hours__axis" aria-hidden="true">
				<span>00</span>
				<span>06</span>
				<span>12</span>
				<span>18</span>
				<span>23</span>
			</div>
			<figcaption className="dev3ac-muted">{ sprintf( /* translators: %d: events */ __( 'Opens + clicks by shopper-local hour, last 90 days (%d events).', 'dev3-ai-abandoned-cart-for-woocommerce' ), total ) }</figcaption>
		</figure>
	);
}

/**
 * Settings screen.
 *
 * @param {Object} props Props.
 * @return {Element} Screen.
 */
export default function SettingsScreen( { boot, save, onUsage } ) {
	const toast = useToast();
	const [ section, setSection ] = useState( 'capture' );
	const [ form, setForm ] = useState( boot.settings );
	const [ busy, setBusy ] = useState( false );
	const [ smsTo, setSmsTo ] = useState( '' );
	const [ smsOpen, setSmsOpen ] = useState( false );
	const [ smsBusy, setSmsBusy ] = useState( false );
	const [ tuning, setTuning ] = useState( null );
	const [ tuningBusy, setTuningBusy ] = useState( false );
	const [ tuningError, setTuningError ] = useState( null );
	const set = ( key ) => ( value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );
	const num = ( key, fallback ) => ( value ) => set( key )( value === '' ? fallback : value );
	const dirty = KEYS.some( ( k ) => JSON.stringify( form[ k ] ) !== JSON.stringify( boot.settings[ k ] ) );

	const submit = ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		setBusy( true );
		const values = Object.fromEntries( KEYS.map( ( k ) => [ k, form[ k ] ] ) );
		save( values )
			.then( ( next ) => {
				setForm( next );
				toast.success( __( 'Settings saved.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	const testSms = () => {
		setSmsBusy( true );
		request( 'sms/test', { method: 'POST', data: { to: smsTo } } )
			.then( () => {
				toast.success( __( 'Test SMS sent.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
				setSmsOpen( false );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setSmsBusy( false ) );
	};

	const suggest = () => {
		setTuningBusy( true );
		setTuningError( null );
		request( 'ai/send-time', { method: 'POST' } )
			.then( setTuning )
			.catch( setTuningError )
			.finally( () => {
				setTuningBusy( false );
				onUsage();
			} );
	};
	const asFields = ( s ) => ( {
		best_hours: ( s.best_hours || [] ).map( ( h ) => `${ String( h ).padStart( 2, '0' ) }:00` ).join( ', ' ),
		quiet_hours: `${ String( s.quiet_start ).padStart( 2, '0' ) }:00 – ${ String( s.quiet_end ).padStart( 2, '0' ) }:00`,
		max_shift_hours: String( s.max_shift_hours ),
	} );
	const acceptTuning = ( values ) => {
		const hours = ( values.best_hours.match( /\d{1,2}/g ) || [] ).filter( ( _, i ) => i % 2 === 0 ).map( Number );
		const quiet = values.quiet_hours.match( /(\d{1,2}):\d{2}\D+(\d{1,2}):\d{2}/ );
		const next = {
			best_hours: hours.length ? hours : tuning.draft.best_hours,
			quiet_start: quiet ? Number( quiet[ 1 ] ) : tuning.draft.quiet_start,
			quiet_end: quiet ? Number( quiet[ 2 ] ) : tuning.draft.quiet_end,
			max_shift_hours: Number( values.max_shift_hours ) || 0,
			send_time_tuning: true,
		};
		setBusy( true );
		save( next )
			.then( ( s ) => {
				setForm( s );
				setTuning( null );
				toast.success( __( 'Send-time settings updated.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	const footer = (
		<Button type="submit" variant="primary" loading={ busy } disabled={ ! dirty }>
			{ __( 'Save settings', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
		</Button>
	);

	return (
		<form onSubmit={ submit }>
			<PageHeader title={ __( 'Settings', 'dev3-ai-abandoned-cart-for-woocommerce' ) } description={ __( 'How carts are captured, when reminders go out and how shoppers\' consent is handled.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } actions={ footer } />
			<Tabs tabs={ SECTIONS } value={ section } onChange={ setSection } label={ __( 'Settings sections', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
			{ dirty && <Notice tone="warning">{ __( 'You have unsaved changes.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Notice> }
			<Stack gap={ 16 }>
				{ section === 'capture' && (
					<>
						<Card title={ __( 'Cart capture', 'dev3-ai-abandoned-cart-for-woocommerce' ) } footer={ footer }>
							<Toggle label={ __( 'Capture abandoned carts', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.tracking_enabled } onChange={ set( 'tracking_enabled' ) } />
							<FormGrid>
								<Toggle label={ __( 'Classic checkout', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.capture_classic } onChange={ set( 'capture_classic' ) } />
								<Toggle label={ __( 'Block checkout', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.capture_block } onChange={ set( 'capture_block' ) } />
								<Toggle label={ __( 'Logged-in customers', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.capture_logged_in } onChange={ set( 'capture_logged_in' ) } help={ __( 'Link carts to the account email without waiting for checkout.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
							</FormGrid>
							<FormGrid>
								<NumberField label={ __( 'Abandoned after (minutes)', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.cutoff_minutes } min={ 5 } max={ 1440 } onChange={ num( 'cutoff_minutes', 15 ) } help={ __( 'Minutes without cart activity before a cart counts as abandoned.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
								<NumberField label={ __( 'Minimum cart value', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.min_cart_total } min={ 0 } step={ 1 } onChange={ num( 'min_cart_total', 0 ) } suffix={ boot.store.symbol } />
							</FormGrid>
							<Field label={ __( 'Never capture carts of these roles', 'dev3-ai-abandoned-cart-for-woocommerce' ) } id="dev3ac-roles">
								<div className="dev3ac-checkgrid" id="dev3ac-roles">
									{ boot.roles.map( ( r ) => (
										<Checkbox key={ r.value } label={ r.label } checked={ ( form.exclude_roles || [] ).includes( r.value ) } onChange={ ( on ) => set( 'exclude_roles' )( on ? [ ...( form.exclude_roles || [] ), r.value ] : ( form.exclude_roles || [] ).filter( ( x ) => x !== r.value ) ) } />
									) ) }
								</div>
							</Field>
						</Card>
						<Card title={ __( 'Consent (GDPR)', 'dev3-ai-abandoned-cart-for-woocommerce' ) } description={ __( 'Shown under the email field on the classic and block checkout.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } footer={ footer }>
							<Select
								label={ __( 'Consent mode', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
								value={ form.consent_mode }
								onChange={ set( 'consent_mode' ) }
								options={ [
									{ value: 'notice', label: __( 'Notice: tell shoppers their cart is saved (default)', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
									{ value: 'opt_in', label: __( 'Opt-in: capture only after the shopper ticks a box', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
									{ value: 'none', label: __( 'None: no notice (outside GDPR regions only)', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
								] }
								help={ form.consent_mode === 'opt_in' ? __( 'Nothing personal is stored until the box is ticked. Win-back only reaches customers who opted in.', 'dev3-ai-abandoned-cart-for-woocommerce' ) : '' }
							/>
							{ form.consent_mode === 'notice' && <Textarea label={ __( 'Notice text', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.consent_text } onChange={ set( 'consent_text' ) } rows={ 3 } maxLength={ 400 } /> }
							{ form.consent_mode === 'opt_in' && <TextField label={ __( 'Checkbox label', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.consent_label } onChange={ set( 'consent_label' ) } maxLength={ 200 } /> }
							<TextField label={ __( 'SMS opt-in checkbox label', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.sms_consent_label } onChange={ set( 'sms_consent_label' ) } maxLength={ 200 } help={ __( 'Shown when SMS is on. SMS is only ever sent to shoppers who tick it.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						</Card>
					</>
				) }
				{ section === 'sending' && (
					<>
						<Card title={ __( 'Sending', 'dev3-ai-abandoned-cart-for-woocommerce' ) } footer={ footer }>
							<Toggle label={ __( 'Send reminders', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.sending_enabled } onChange={ set( 'sending_enabled' ) } help={ __( 'Turn off to pause all sending; carts are still captured.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
							<FormGrid>
								<TextField label={ __( 'From name', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.from_name } onChange={ set( 'from_name' ) } placeholder={ boot.store.name } help={ __( 'Empty = WooCommerce email settings.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
								<TextField label={ __( 'From address', 'dev3-ai-abandoned-cart-for-woocommerce' ) } type="email" value={ form.from_email } onChange={ set( 'from_email' ) } />
								<Select label={ __( 'Recovery link opens', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.redirect_to } onChange={ set( 'redirect_to' ) } options={ [ { value: 'checkout', label: __( 'Checkout', 'dev3-ai-abandoned-cart-for-woocommerce' ) }, { value: 'cart', label: __( 'Cart', 'dev3-ai-abandoned-cart-for-woocommerce' ) } ] } />
								<NumberField label={ __( 'Mark lost after (days)', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.lost_after_days } min={ 1 } max={ 60 } onChange={ num( 'lost_after_days', 7 ) } help={ __( 'Days after the last step with no order.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
							</FormGrid>
						</Card>
						<Card title={ __( 'AI review rules', 'dev3-ai-abandoned-cart-for-woocommerce' ) } tone="ai" footer={ footer }>
							<Select
								label={ __( 'If an AI draft is not reviewed in time', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
								value={ form.review_fallback }
								onChange={ set( 'review_fallback' ) }
								options={ [
									{ value: 'template', label: __( 'Send the approved template copy at the scheduled time', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
									{ value: 'hold', label: __( 'Hold the message, then send the template copy', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
								] }
								help={ __( 'Unreviewed AI text is never sent (unless a template has auto-approve on).', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
							/>
							{ form.review_fallback === 'hold' && <NumberField label={ __( 'Hold for up to (hours)', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.review_hold_hours } min={ 1 } max={ 72 } onChange={ num( 'review_hold_hours', 6 ) } /> }
							<FormGrid>
								<Select label={ __( 'Brand tone', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.brand_tone } onChange={ set( 'brand_tone' ) } options={ [ 'friendly', 'professional', 'playful', 'luxury', 'minimal' ].map( ( v ) => ( { value: v, label: v.charAt( 0 ).toUpperCase() + v.slice( 1 ) } ) ) } />
								<NumberField label={ __( 'AI drafts per scheduler run', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.ai_batch_size } min={ 0 } max={ 50 } onChange={ num( 'ai_batch_size', 5 ) } help={ __( '0 turns off per-cart AI copy.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
							</FormGrid>
							<Textarea label={ __( 'Brand notes for DEV3 AI', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.brand_notes } onChange={ set( 'brand_notes' ) } rows={ 3 } maxLength={ 600 } placeholder={ __( 'e.g. We are a family bakery. Never pushy, sign off as "the Crumb team".', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						</Card>
						<Card title={ __( 'Send-time tuning', 'dev3-ai-abandoned-cart-for-woocommerce' ) } description={ __( 'Moves each send forward (never earlier) to the shopper\'s best hour, outside quiet hours in their own timezone.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } footer={ footer }>
							<Toggle label={ __( 'Tune send times per shopper', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.send_time_tuning } onChange={ set( 'send_time_tuning' ) } />
							<FormGrid>
								<Select label={ __( 'Quiet hours start', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ String( form.quiet_start ) } onChange={ ( v ) => set( 'quiet_start' )( Number( v ) ) } options={ HOURS } />
								<Select label={ __( 'Quiet hours end', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ String( form.quiet_end ) } onChange={ ( v ) => set( 'quiet_end' )( Number( v ) ) } options={ HOURS } />
								<NumberField label={ __( 'Max shift (hours)', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.max_shift_hours } min={ 0 } max={ 24 } onChange={ num( 'max_shift_hours', 6 ) } />
							</FormGrid>
							<Field label={ __( 'Preferred hours', 'dev3-ai-abandoned-cart-for-woocommerce' ) } id="dev3ac-best" help={ __( 'Used until your store has its own engagement data.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }>
								<div className="dev3ac-hourpick" id="dev3ac-best">
									{ HOURS.map( ( h ) => {
										const on = ( form.best_hours || [] ).includes( Number( h.value ) );
										return (
											<button key={ h.value } type="button" className={ `dev3ac-hourpick__h${ on ? ' is-on' : '' }` } aria-pressed={ on } onClick={ () => set( 'best_hours' )( on ? form.best_hours.filter( ( x ) => x !== Number( h.value ) ) : [ ...( form.best_hours || [] ), Number( h.value ) ].sort( ( a, b ) => a - b ) ) }>
												{ h.value.padStart( 2, '0' ) }
											</button>
										);
									} ) }
								</div>
							</Field>
						</Card>
						<Card tone="ai" title={ __( 'Send-time insights by DEV3 AI', 'dev3-ai-abandoned-cart-for-woocommerce' ) } description={ __( 'DEV3 AI reads your open, click and order hours and suggests settings. Nothing changes until you accept.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }>
							{ tuning && tuning.hours && <HourBars hours={ tuning.hours } /> }
							{ ! tuning && <AiButton onClick={ suggest } loading={ tuningBusy }>{ __( 'Suggest send times with DEV3 AI', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</AiButton> }
							<ErrorNotice error={ tuningError } onRetry={ suggest } onDismiss={ () => setTuningError( null ) } />
							{ tuning && (
								<>
									{ tuning.explanation && <p>{ tuning.explanation }</p> }
									<AiDraftReview fields={ SEND_FIELDS } original={ asFields( form ) } draft={ asFields( tuning.draft ) } meta={ { model: tuning.model, tokens: tuning.tokens } } acceptLabel={ __( 'Apply & save', 'dev3-ai-abandoned-cart-for-woocommerce' ) } onAccept={ acceptTuning } onReject={ () => setTuning( null ) } onRegenerate={ suggest } regenerating={ tuningBusy } accepting={ busy } />
								</>
							) }
						</Card>
					</>
				) }
				{ section === 'coupons' && (
					<Card title={ __( 'Recovery coupons', 'dev3-ai-abandoned-cart-for-woocommerce' ) } description={ __( 'Each step can create a unique, single-use coupon locked to the shopper\'s email. Set the discount per step in Sequences.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } footer={ footer }>
						<FormGrid>
							<TextField label={ __( 'Code prefix', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.coupon_prefix } onChange={ set( 'coupon_prefix' ) } maxLength={ 12 } help={ sprintf( /* translators: %s: example code */ __( 'Codes look like %s.', 'dev3-ai-abandoned-cart-for-woocommerce' ), `${ ( form.coupon_prefix || 'COMEBACK' ).toUpperCase() }-X7K2QM` ) } />
						</FormGrid>
						<Toggle label={ __( 'Delete unused coupons after they expire', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.coupon_cleanup } onChange={ set( 'coupon_cleanup' ) } />
					</Card>
				) }
				{ section === 'sms' && (
					<Card
						title={ __( 'SMS provider', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						description={ __( 'Any Twilio-compatible API (Twilio, SignalWire or a compatible gateway). Only shoppers who ticked the SMS box receive texts.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						actions={ <Badge tone={ boot.sms_ready ? 'success' : 'neutral' } dot>{ boot.sms_ready ? __( 'Ready', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Not configured', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Badge> }
						footer={
							<>
								<Button variant="ghost" onClick={ () => setSmsOpen( true ) } disabled={ ! boot.sms_ready }>
									{ __( 'Send test SMS', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
								</Button>
								{ footer }
							</>
						}
					>
						<Toggle label={ __( 'Enable SMS', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.sms_enabled } onChange={ set( 'sms_enabled' ) } />
						<FormGrid>
							<TextField label={ __( 'API base URL', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.sms_base_url } onChange={ set( 'sms_base_url' ) } help={ __( 'Twilio: https://api.twilio.com/2010-04-01', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
							<TextField label={ __( 'Account SID', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.sms_account_sid } onChange={ set( 'sms_account_sid' ) } autoComplete="off" />
							<TextField label={ __( 'Auth token', 'dev3-ai-abandoned-cart-for-woocommerce' ) } type="password" value={ form.sms_auth_token } onChange={ set( 'sms_auth_token' ) } autoComplete="new-password" help={ form.sms_token_set ? __( 'Saved (encrypted). Type a new one to replace it.', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Stored encrypted; never sent to the browser.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
							<TextField label={ __( 'From number', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.sms_from } onChange={ set( 'sms_from' ) } placeholder="+15550100" />
							<TextField label={ __( 'Messaging service SID (optional)', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.sms_messaging_service } onChange={ set( 'sms_messaging_service' ) } help={ __( 'Used instead of the From number when set.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						</FormGrid>
					</Card>
				) }
				{ section === 'winback' && (
					<Card title={ __( 'Win-back', 'dev3-ai-abandoned-cart-for-woocommerce' ) } description={ __( 'Past customers who have not ordered for a while get the win-back sequence, with their last order ready to buy again.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } footer={ footer }>
						<Toggle label={ __( 'Enrol lapsed customers', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.winback_enabled } onChange={ set( 'winback_enabled' ) } />
						<FormGrid>
							<NumberField label={ __( 'Inactive for (days)', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.winback_days } min={ 7 } max={ 730 } onChange={ num( 'winback_days', 60 ) } />
							<NumberField label={ __( 'Look-back window (days)', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.winback_window_days } min={ 1 } max={ 365 } onChange={ num( 'winback_window_days', 30 ) } help={ __( 'Customers whose last order falls in this window after the inactivity period are enrolled once.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						</FormGrid>
					</Card>
				) }
				{ section === 'data' && (
					<Card title={ __( 'Data & privacy', 'dev3-ai-abandoned-cart-for-woocommerce' ) } footer={ footer }>
						<FormGrid>
							<NumberField label={ __( 'Delete carts after (days)', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.retention_days } min={ 7 } max={ 3650 } onChange={ num( 'retention_days', 90 ) } help={ __( 'Finished carts and their messages are removed daily.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						</FormGrid>
						<Toggle label={ __( '"Save my cart" widget', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.save_cart_widget } onChange={ set( 'save_cart_widget' ) } help={ __( 'Block "Save my cart" and shortcode [dev3ac_save_cart] for the cart page.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						<Toggle label={ __( 'Delete all plugin data on uninstall', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } />
						<p className="dev3ac-muted">
							{ sprintf( /* translators: %d: count */ __( '%d unsubscribed addresses/numbers are suppressed (stored as hashes). Personal data export and erasure: Tools → Export / Erase Personal Data.', 'dev3-ai-abandoned-cart-for-woocommerce' ), boot.suppressed ) }
						</p>
					</Card>
				) }
			</Stack>
			<Modal
				open={ smsOpen }
				onClose={ () => setSmsOpen( false ) }
				size="sm"
				title={ __( 'Send a test SMS', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				footer={
					<Button variant="primary" onClick={ testSms } loading={ smsBusy } disabled={ ! smsTo.trim() }>
						{ __( 'Send', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					</Button>
				}
			>
				<TextField label={ __( 'Phone number', 'dev3-ai-abandoned-cart-for-woocommerce' ) } type="tel" value={ smsTo } onChange={ setSmsTo } placeholder="+15550100" data-autofocus />
			</Modal>
		</form>
	);
}
