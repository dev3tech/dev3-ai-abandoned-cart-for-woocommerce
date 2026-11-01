import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, ErrorNotice, IconButton, Inline, Modal, Notice, NumberField, PageHeader, Select, Stack, TextField, Toggle, useToast } from '../core3/ui';
import { couponLabel, delayLabel } from './util';

let uid = 0;
const newStep = ( channel = 'email', templates = [] ) => {
	uid += 1;
	const tpl = templates.find( ( t ) => t.channel === channel && t.status === 'approved' ) || templates.find( ( t ) => t.channel === channel );
	return {
		id: `st_new${ Date.now().toString( 36 ) }${ uid }`,
		delay_value: 1,
		delay_unit: 'days',
		channel,
		template_id: tpl ? tpl.id : '',
		min_total: 0,
		coupon: { enabled: false, type: 'percent', amount: 10, expiry_days: 3, min_spend: 0 },
	};
};

/**
 * Step editor row.
 *
 * @param {Object} props Props.
 * @return {Element} Row.
 */
function StepRow( { step, index, count, type, templates, currency, smsReady, onChange, onMove, onRemove } ) {
	const set = ( key ) => ( value ) => onChange( { ...step, [ key ]: value } );
	const setCoupon = ( key ) => ( value ) => onChange( { ...step, coupon: { ...step.coupon, [ key ]: value } } );
	const options = templates
		.filter( ( t ) => t.channel === step.channel )
		.map( ( t ) => ( { value: t.id, label: t.status === 'approved' ? t.name : `${ t.name } (${ __( 'draft', 'dev3-ai-abandoned-cart-for-woocommerce' ) })` } ) );
	const tpl = templates.find( ( t ) => t.id === step.template_id );
	return (
		<li className="dev3ac-step">
			<div className="dev3ac-step__head">
				<span className="dev3ac-step__num" aria-hidden="true">{ index + 1 }</span>
				<strong>{ sprintf( /* translators: 1: step, 2: delay */ __( 'Step %1$d · after %2$s', 'dev3-ai-abandoned-cart-for-woocommerce' ), index + 1, delayLabel( step ) ) }</strong>
				<Inline gap={ 4 }>
					{ tpl && tpl.status !== 'approved' && <Badge tone="warning">{ __( 'Template not approved: step is skipped', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Badge> }
					{ step.channel === 'sms' && ! smsReady && <Badge tone="neutral">{ __( 'SMS not configured: skipped', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Badge> }
					<IconButton icon="sort" label={ __( 'Move up', 'dev3-ai-abandoned-cart-for-woocommerce' ) } onClick={ () => onMove( -1 ) } disabled={ index === 0 } />
					<IconButton icon="trash" label={ __( 'Remove step', 'dev3-ai-abandoned-cart-for-woocommerce' ) } onClick={ onRemove } disabled={ count <= 1 } />
				</Inline>
			</div>
			<div className="dev3ac-step__grid">
				<NumberField label={ index === 0 ? ( type === 'winback' ? __( 'Wait after enrolment', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Wait after abandonment', 'dev3-ai-abandoned-cart-for-woocommerce' ) ) : __( 'Wait after previous step', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ step.delay_value } min={ 0 } max={ 1000 } onChange={ ( v ) => set( 'delay_value' )( v === '' ? 0 : v ) } />
				<Select
					label={ __( 'Unit', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					value={ step.delay_unit }
					onChange={ set( 'delay_unit' ) }
					options={ [
						{ value: 'minutes', label: __( 'Minutes', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
						{ value: 'hours', label: __( 'Hours', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
						{ value: 'days', label: __( 'Days', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
					] }
				/>
				<Select
					label={ __( 'Channel', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					value={ step.channel }
					onChange={ ( channel ) => {
						const first = templates.find( ( t ) => t.channel === channel );
						onChange( { ...step, channel, template_id: first ? first.id : '' } );
					} }
					options={ [
						{ value: 'email', label: __( 'Email', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
						{ value: 'sms', label: 'SMS' },
					] }
				/>
				<Select label={ __( 'Template', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ step.template_id } onChange={ set( 'template_id' ) } options={ options } placeholder={ __( 'Choose a template', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
			</div>
			<div className="dev3ac-step__coupon">
				<Toggle label={ __( 'Unique coupon in this step', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ step.coupon.enabled } onChange={ setCoupon( 'enabled' ) } help={ step.coupon.enabled ? couponLabel( step.coupon, currency ) : __( 'A single-use code locked to the shopper\'s email.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
				{ step.coupon.enabled && (
					<div className="dev3ac-step__grid">
						<Select
							label={ __( 'Discount', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
							value={ step.coupon.type }
							onChange={ setCoupon( 'type' ) }
							options={ [
								{ value: 'percent', label: __( 'Percent off', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
								{ value: 'fixed_cart', label: __( 'Fixed amount off', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
								{ value: 'free_shipping', label: __( 'Free shipping', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
							] }
						/>
						{ step.coupon.type !== 'free_shipping' && <NumberField label={ step.coupon.type === 'percent' ? __( 'Percent', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Amount', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ step.coupon.amount } min={ 0 } max={ step.coupon.type === 'percent' ? 100 : 100000 } step={ 0.5 } onChange={ ( v ) => setCoupon( 'amount' )( v === '' ? 0 : v ) } /> }
						<NumberField label={ __( 'Valid for (days)', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ step.coupon.expiry_days } min={ 1 } max={ 365 } onChange={ ( v ) => setCoupon( 'expiry_days' )( v === '' ? 1 : v ) } />
						<NumberField label={ __( 'Minimum spend', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ step.coupon.min_spend } min={ 0 } step={ 1 } onChange={ ( v ) => setCoupon( 'min_spend' )( v === '' ? 0 : v ) } />
					</div>
				) }
			</div>
		</li>
	);
}

/**
 * Sequences screen.
 *
 * @param {Object} props Props.
 * @return {Element} Screen.
 */
export default function SequencesScreen( { boot, patch, onUsage } ) {
	const toast = useToast();
	const [ list, setList ] = useState( boot.sequences );
	const [ saving, setSaving ] = useState( false );
	const [ ai, setAi ] = useState( null );
	const [ aiBusy, setAiBusy ] = useState( false );
	const [ aiError, setAiError ] = useState( null );
	const dirty = JSON.stringify( list ) !== JSON.stringify( boot.sequences );
	const currency = boot.store.currency;

	const update = ( id, next ) => setList( ( cur ) => cur.map( ( s ) => ( ( s.id || s._key ) === id ? next : s ) ) );
	const save = () => {
		setSaving( true );
		request( 'sequences', { method: 'POST', data: { sequences: list } } )
			.then( ( res ) => {
				setList( res.sequences );
				patch( { sequences: res.sequences } );
				toast.success( __( 'Sequences saved.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSaving( false ) );
	};
	const build = ( seq ) => {
		setAi( { seq, draft: null } );
		setAiBusy( true );
		setAiError( null );
		request( 'ai/sequence', { method: 'POST', data: { type: seq.type } } )
			.then( ( draft ) => setAi( { seq, draft } ) )
			.catch( setAiError )
			.finally( () => {
				setAiBusy( false );
				onUsage();
			} );
	};
	const acceptAi = () => {
		const key = ai.seq.id || ai.seq._key;
		update( key, { ...list.find( ( s ) => ( s.id || s._key ) === key ), steps: ai.draft.steps.map( ( { reason, ...step } ) => step ) } );
		setAi( null );
		toast.info( __( 'Draft steps applied. Review them and click Save sequences.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
	};
	const addSequence = ( type ) => {
		uid += 1;
		setList( [ ...list, { id: '', _key: `new${ uid }`, name: type === 'winback' ? __( 'New win-back', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'New cart sequence', 'dev3-ai-abandoned-cart-for-woocommerce' ), type, enabled: false, steps: [ newStep( 'email', boot.templates ) ] } ] );
	};
	const tplName = ( id ) => ( boot.templates.find( ( t ) => t.id === id ) || {} ).name || id;

	return (
		<>
			<PageHeader
				title={ __( 'Sequences', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				description={ __( 'Multi-step reminders. Cart recovery starts when a cart is abandoned; win-back starts when a customer has not ordered for a while. Purchases and unsubscribes stop a sequence.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				actions={
					<>
						<Button variant="ghost" icon="plus" onClick={ () => addSequence( 'cart' ) }>
							{ __( 'Add sequence', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
						<Button variant="primary" icon="check" onClick={ save } loading={ saving } disabled={ ! dirty }>
							{ __( 'Save sequences', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
					</>
				}
			/>
			{ dirty && <Notice tone="warning">{ __( 'You have unsaved changes.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Notice> }
			<Stack gap={ 16 }>
				{ list.map( ( seq ) => (
					<Card
						key={ seq.id || seq._key }
						title={ seq.name || __( 'Untitled sequence', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						description={ seq.type === 'winback' ? sprintf( /* translators: %d: days */ __( 'Win-back: customers whose last order is %d+ days old.', 'dev3-ai-abandoned-cart-for-woocommerce' ), boot.settings.winback_days ) : sprintf( /* translators: %d: minutes */ __( 'Cart recovery: starts %d minutes after the last cart activity.', 'dev3-ai-abandoned-cart-for-woocommerce' ), boot.settings.cutoff_minutes ) }
						actions={
							<Inline gap={ 8 }>
								<Badge tone={ seq.enabled ? 'success' : 'neutral' } dot>{ seq.enabled ? __( 'Running', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Off', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Badge>
								<AiButton size="sm" onClick={ () => build( seq ) } loading={ aiBusy && ai && ( ai.seq.id || ai.seq._key ) === ( seq.id || seq._key ) }>{ __( 'Build with DEV3 AI', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</AiButton>
							</Inline>
						}
					>
						<div className="dev3ac-seqhead">
							<TextField label={ __( 'Name', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ seq.name } onChange={ ( name ) => update( seq.id || seq._key, { ...seq, name } ) } />
							<Select
								label={ __( 'Type', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
								value={ seq.type }
								onChange={ ( type ) => update( seq.id || seq._key, { ...seq, type } ) }
								options={ [
									{ value: 'cart', label: __( 'Cart recovery', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
									{ value: 'winback', label: __( 'Win-back', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
								] }
							/>
							<Toggle label={ __( 'Enabled', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ seq.enabled } onChange={ ( enabled ) => update( seq.id || seq._key, { ...seq, enabled } ) } help={ __( 'One sequence per type runs at a time.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						</div>
						<ol className="dev3ac-steps">
							{ seq.steps.map( ( step, i ) => (
								<StepRow
									key={ step.id }
									step={ step }
									index={ i }
									count={ seq.steps.length }
									type={ seq.type }
									templates={ boot.templates }
									currency={ currency }
									smsReady={ boot.sms_ready }
									onChange={ ( next ) => update( seq.id || seq._key, { ...seq, steps: seq.steps.map( ( s, j ) => ( j === i ? next : s ) ) } ) }
									onRemove={ () => update( seq.id || seq._key, { ...seq, steps: seq.steps.filter( ( s, j ) => j !== i ) } ) }
									onMove={ ( dir ) => {
										const steps = [ ...seq.steps ];
										const [ moved ] = steps.splice( i, 1 );
										steps.splice( i + dir, 0, moved );
										update( seq.id || seq._key, { ...seq, steps } );
									} }
								/>
							) ) }
						</ol>
						<Inline gap={ 8 }>
							<Button size="sm" icon="plus" onClick={ () => update( seq.id || seq._key, { ...seq, steps: [ ...seq.steps, newStep( 'email', boot.templates ) ] } ) } disabled={ seq.steps.length >= 10 }>
								{ __( 'Add email step', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
							</Button>
							<Button size="sm" variant="ghost" icon="plus" onClick={ () => update( seq.id || seq._key, { ...seq, steps: [ ...seq.steps, newStep( 'sms', boot.templates ) ] } ) } disabled={ seq.steps.length >= 10 }>
								{ __( 'Add SMS step', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
							</Button>
						</Inline>
					</Card>
				) ) }
			</Stack>
			<Modal
				open={ !! ai }
				onClose={ () => setAi( null ) }
				size="lg"
				title={ __( 'DEV3 AI · Suggested sequence', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				description={ __( 'Based on your store\'s recovery numbers. Nothing changes until you apply and save it.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setAi( null ) }>
							{ __( 'Discard', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
						{ ai && ai.seq && (
							<Button variant="ghost" icon="refresh" onClick={ () => build( ai.seq ) } loading={ aiBusy }>
								{ __( 'Regenerate', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
							</Button>
						) }
						<Button variant="primary" onClick={ acceptAi } disabled={ ! ai || ! ai.draft }>
							{ __( 'Apply these steps', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
					</>
				}
			>
				<ErrorNotice error={ aiError } onRetry={ () => ai && build( ai.seq ) } />
				{ aiBusy && <p className="dev3ac-muted">{ __( 'DEV3 AI is designing your sequence…', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</p> }
				{ ai && ai.draft && (
					<>
						<p>{ ai.draft.summary }</p>
						<ol className="dev3ac-aisteps">
							{ ai.draft.steps.map( ( s, i ) => (
								<li key={ i }>
									<strong>{ `${ __( 'After', 'dev3-ai-abandoned-cart-for-woocommerce' ) } ${ delayLabel( s ) } · ${ s.channel === 'sms' ? 'SMS' : __( 'Email', 'dev3-ai-abandoned-cart-for-woocommerce' ) } · ${ tplName( s.template_id ) }` }</strong>
									<span className="dev3ac-muted">{ couponLabel( s.coupon, currency ) }</span>
									<span>{ s.reason }</span>
								</li>
							) ) }
						</ol>
					</>
				) }
			</Modal>
		</>
	);
}
