import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { fitFrame } from './frame';
import { AiButton, AiDraftReview, Badge, Button, Card, Columns, ErrorNotice, Inline, Modal, Notice, PageHeader, ResponsiveTable, Select, Stack, Textarea, TextField, Toggle, useToast } from '../core3/ui';
import { fmtDate } from './util';

const BLANK = { id: '', name: '', channel: 'email', subject: '', preheader: '', heading: '', body: '', button: '', sms: '', show_cart: true, ai_personalise: false, auto_approve: false, status: 'draft' };
const COPY_KEYS = [ 'name', 'channel', 'subject', 'preheader', 'heading', 'body', 'button', 'sms', 'show_cart', 'ai_personalise', 'auto_approve' ];

const AI_EMAIL_FIELDS = [
	{ key: 'subject', label: __( 'Subject', 'dev3-ai-abandoned-cart-for-woocommerce' ), maxLength: 150 },
	{ key: 'preheader', label: __( 'Preheader', 'dev3-ai-abandoned-cart-for-woocommerce' ), maxLength: 200 },
	{ key: 'heading', label: __( 'Heading', 'dev3-ai-abandoned-cart-for-woocommerce' ), maxLength: 120 },
	{ key: 'body', label: __( 'Body', 'dev3-ai-abandoned-cart-for-woocommerce' ), multiline: true, maxLength: 1200 },
	{ key: 'button', label: __( 'Button', 'dev3-ai-abandoned-cart-for-woocommerce' ), maxLength: 60 },
];
const AI_SMS_FIELDS = [ { key: 'sms', label: __( 'SMS text', 'dev3-ai-abandoned-cart-for-woocommerce' ), multiline: true, maxLength: 480 } ];

/**
 * Template editor with live preview and the DEV3 AI writer.
 *
 * @param {Object} props Props.
 * @return {Element} Editor.
 */
export function TemplateEditor( { boot, patch, template, onClose, onUsage } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( { ...BLANK, ...template } );
	const [ preview, setPreview ] = useState( null );
	const [ coupon, setCoupon ] = useState( true );
	const [ saving, setSaving ] = useState( '' );
	const [ quality, setQuality ] = useState( [] );
	const [ testOpen, setTestOpen ] = useState( false );
	const [ testTo, setTestTo ] = useState( '' );
	const [ testing, setTesting ] = useState( false );
	const [ brief, setBrief ] = useState( { goal: '', offer: '', tone: boot.settings.brand_tone } );
	const [ draft, setDraft ] = useState( null );
	const [ writing, setWriting ] = useState( false );
	const [ aiError, setAiError ] = useState( null );
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const timer = useRef( null );
	const set = ( key ) => ( value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );
	const isSms = form.channel === 'sms';
	const dirty = COPY_KEYS.some( ( k ) => form[ k ] !== ( { ...BLANK, ...template } )[ k ] );
	const copyChanged = [ 'subject', 'preheader', 'heading', 'body', 'button', 'sms' ].some( ( k ) => form[ k ] !== ( { ...BLANK, ...template } )[ k ] );

	useEffect( () => {
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			request( 'templates/preview', { method: 'POST', data: { template: form, coupon } } )
				.then( ( res ) => {
					setPreview( res );
					setQuality( res.quality || [] );
				} )
				.catch( () => {} );
		}, 450 );
		return () => clearTimeout( timer.current );
	}, [ form, coupon ] );

	const save = ( approve ) => {
		setSaving( approve ? 'approve' : 'save' );
		request( 'templates', { method: 'POST', data: { template: { ...form, approve } } } )
			.then( ( res ) => {
				setForm( { ...BLANK, ...res.template } );
				patch( { templates: res.templates } );
				toast.success( approve ? __( 'Template approved. Sequences can send it now.', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Saved as draft. Approve it before it can be sent.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
				if ( ! template.id ) {
					onClose( res.template.id );
				}
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSaving( '' ) );
	};
	const remove = () => {
		request( `templates/${ form.id }`, { method: 'DELETE' } )
			.then( ( res ) => {
				patch( { templates: res.templates } );
				toast.success( __( 'Template deleted.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
				onClose();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setConfirmDelete( false ) );
	};
	const test = () => {
		setTesting( true );
		request( 'templates/test', { method: 'POST', data: { template: form, to: testTo } } )
			.then( () => {
				toast.success( __( 'Test sent.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
				setTestOpen( false );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setTesting( false ) );
	};
	const write = () => {
		setWriting( true );
		setAiError( null );
		request( 'ai/template', { method: 'POST', data: { ...brief, channel: form.channel } } )
			.then( setDraft )
			.catch( setAiError )
			.finally( () => {
				setWriting( false );
				onUsage();
			} );
	};
	const applyDraft = ( values ) => {
		setForm( ( f ) => ( { ...f, ...values } ) );
		setDraft( null );
		toast.info( __( 'Draft applied to the editor. Save it, then approve it when you are happy.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
	};
	const insertTag = ( tag ) => {
		const key = isSms ? 'sms' : 'body';
		setForm( ( f ) => ( { ...f, [ key ]: `${ f[ key ] }${ f[ key ] && ! f[ key ].endsWith( ' ' ) ? ' ' : '' }{${ tag }}` } ) );
	};
	const smsLen = isSms && preview && preview.length ? preview.length : ( form.sms || '' ).length;

	return (
		<>
			<PageHeader
				title={ form.id ? form.name || __( 'Template', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'New template', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				back={ { href: '#/templates', label: __( 'All templates', 'dev3-ai-abandoned-cart-for-woocommerce' ) } }
				description={ form.status === 'approved' && ! copyChanged ? sprintf( /* translators: %s: date */ __( 'Approved %s. Editing the copy moves it back to draft until you approve it again.', 'dev3-ai-abandoned-cart-for-woocommerce' ), fmtDate( form.approved_at ) ) : __( 'Drafts never send. Approve the template to use it in a sequence.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				actions={
					<>
						<Button variant="ghost" icon="inbox" onClick={ () => setTestOpen( true ) }>
							{ __( 'Send test', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
						<Button onClick={ () => save( false ) } loading={ saving === 'save' } disabled={ ! dirty && !! form.id }>
							{ __( 'Save draft', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
						<Button variant="primary" icon="check" onClick={ () => save( true ) } loading={ saving === 'approve' } disabled={ form.status === 'approved' && ! dirty }>
							{ __( 'Save & approve', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
					</>
				}
			/>
			<Columns min={ 380 }>
				<Stack gap={ 16 }>
					<Card title={ __( 'Content', 'dev3-ai-abandoned-cart-for-woocommerce' ) } actions={ <Badge tone={ form.status === 'approved' && ! copyChanged ? 'success' : 'warning' } dot>{ form.status === 'approved' && ! copyChanged ? __( 'Approved', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Draft', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Badge> }>
						<div className="dev3ac-form2">
							<TextField label={ __( 'Template name', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.name } onChange={ set( 'name' ) } required />
							<Select
								label={ __( 'Channel', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
								value={ form.channel }
								onChange={ set( 'channel' ) }
								disabled={ !! form.id }
								options={ [
									{ value: 'email', label: __( 'Email', 'dev3-ai-abandoned-cart-for-woocommerce' ) },
									{ value: 'sms', label: 'SMS' },
								] }
							/>
						</div>
						{ isSms ? (
							<Textarea label={ __( 'SMS text', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.sms } onChange={ set( 'sms' ) } rows={ 4 } maxLength={ 480 } help={ sprintf( /* translators: %d: characters */ __( 'About %d characters after merge tags. 160 fits in one SMS.', 'dev3-ai-abandoned-cart-for-woocommerce' ), smsLen ) } />
						) : (
							<>
								<TextField label={ __( 'Subject', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.subject } onChange={ set( 'subject' ) } maxLength={ 150 } required />
								<TextField label={ __( 'Preheader', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.preheader } onChange={ set( 'preheader' ) } maxLength={ 200 } help={ __( 'The preview line inboxes show after the subject.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
								<TextField label={ __( 'Heading', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.heading } onChange={ set( 'heading' ) } maxLength={ 120 } />
								<Textarea label={ __( 'Body', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.body } onChange={ set( 'body' ) } rows={ 7 } maxLength={ 6000 } help={ __( 'Plain text or simple HTML. Blank lines start new paragraphs. The cart table, coupon box, button and unsubscribe link are added automatically.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
								<div className="dev3ac-form2">
									<TextField label={ __( 'Button text', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ form.button } onChange={ set( 'button' ) } maxLength={ 60 } />
									<Toggle label={ __( 'Show the cart items table', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.show_cart } onChange={ set( 'show_cart' ) } />
								</div>
							</>
						) }
						<div className="dev3ac-tags" aria-label={ __( 'Merge tags', 'dev3-ai-abandoned-cart-for-woocommerce' ) }>
							<span className="dev3ac-muted">{ __( 'Insert:', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</span>
							{ boot.merge_tags.map( ( t ) => (
								<button key={ t } type="button" className="dev3ac-tag" onClick={ () => insertTag( t ) }>{ `{${ t }}` }</button>
							) ) }
						</div>
					</Card>
					<Card title={ __( 'DEV3 AI personalisation', 'dev3-ai-abandoned-cart-for-woocommerce' ) } tone="ai">
						<Toggle label={ __( 'Personalise each message with DEV3 AI', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.ai_personalise } onChange={ set( 'ai_personalise' ) } help={ __( 'DEV3 AI rewrites the subject, heading and message for each shopper\'s cart (first name and products only), keeping this template\'s intent.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						<Toggle label={ __( 'Auto-approve AI copy for this template', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ form.auto_approve } onChange={ set( 'auto_approve' ) } disabled={ ! form.ai_personalise } help={ __( 'Off (recommended): every AI draft waits in the Review queue. On: AI copy is sent without review.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						{ form.ai_personalise && form.auto_approve && <Notice tone="warning">{ __( 'AI-written emails from this template will be sent without a human review.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Notice> }
					</Card>
					<Card title={ __( 'Write with DEV3 AI', 'dev3-ai-abandoned-cart-for-woocommerce' ) } tone="ai" description={ __( 'Describe the message; DEV3 AI drafts the copy. You review it before anything changes.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }>
						<Textarea label={ __( 'What should this message do?', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ brief.goal } onChange={ ( goal ) => setBrief( { ...brief, goal } ) } rows={ 3 } maxLength={ 400 } placeholder={ __( 'e.g. Second reminder for a cart of skincare products; mention the 10% code and that stock is limited.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						<div className="dev3ac-form2">
							<TextField label={ __( 'Offer (optional)', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ brief.offer } onChange={ ( offer ) => setBrief( { ...brief, offer } ) } placeholder={ __( '10% off for 3 days', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
							<Select
								label={ __( 'Tone', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
								value={ brief.tone }
								onChange={ ( tone ) => setBrief( { ...brief, tone } ) }
								options={ [ 'friendly', 'professional', 'playful', 'luxury', 'minimal' ].map( ( v ) => ( { value: v, label: v.charAt( 0 ).toUpperCase() + v.slice( 1 ) } ) ) }
							/>
						</div>
						<div>
							<AiButton onClick={ write } loading={ writing && ! draft } disabled={ ! brief.goal.trim() || writing } />
						</div>
						<ErrorNotice error={ aiError } onRetry={ write } onDismiss={ () => setAiError( null ) } />
						{ draft && (
							<>
								<AiDraftReview
									fields={ isSms ? AI_SMS_FIELDS : AI_EMAIL_FIELDS }
									original={ Object.fromEntries( ( isSms ? AI_SMS_FIELDS : AI_EMAIL_FIELDS ).map( ( f ) => [ f.key, form[ f.key ] || '' ] ) ) }
									draft={ Object.fromEntries( ( isSms ? AI_SMS_FIELDS : AI_EMAIL_FIELDS ).map( ( f ) => [ f.key, draft.draft[ f.key ] || '' ] ) ) }
									meta={ { model: draft.model, tokens: draft.tokens, cached: draft.cached } }
									acceptLabel={ __( 'Use this draft', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
									onAccept={ applyDraft }
									onReject={ () => setDraft( null ) }
									onRegenerate={ write }
									regenerating={ writing }
								/>
								{ ! isSms && draft.ideas && draft.ideas.length > 0 && (
									<div className="dev3ac-ideas">
										<span className="dev3ac-muted">{ __( 'More subject ideas:', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</span>
										{ draft.ideas.map( ( idea ) => (
											<button key={ idea } type="button" className="dev3ac-tag" onClick={ () => set( 'subject' )( idea ) }>{ idea }</button>
										) ) }
									</div>
								) }
							</>
						) }
					</Card>
				</Stack>
				<Stack gap={ 16 }>
					<Card
						title={ __( 'Preview', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						description={ __( 'With a sample cart from your store.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						actions={ <Toggle label={ __( 'With coupon', 'dev3-ai-abandoned-cart-for-woocommerce' ) } checked={ coupon } onChange={ setCoupon } /> }
					>
						{ preview && preview.channel === 'email' && (
							<>
								<div className="dev3ac-inbox">
									<strong>{ preview.subject || __( '(no subject)', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</strong>
									<span>{ preview.preheader }</span>
								</div>
								<iframe className="dev3ac-preview-frame" title={ __( 'Email preview', 'dev3-ai-abandoned-cart-for-woocommerce' ) } srcDoc={ preview.html } sandbox="allow-same-origin" onLoad={ fitFrame } />
							</>
						) }
						{ preview && preview.channel === 'sms' && (
							<div className="dev3ac-sms">
								<div className="dev3ac-sms__bubble">{ preview.sms }</div>
								<span className="dev3ac-muted">{ sprintf( /* translators: %d: characters */ __( '%d characters', 'dev3-ai-abandoned-cart-for-woocommerce' ), preview.length ) }</span>
							</div>
						) }
					</Card>
					<Card title={ __( 'Quality checks', 'dev3-ai-abandoned-cart-for-woocommerce' ) }>
						{ quality.length === 0 ? (
							<p className="dev3ac-ok">{ __( 'No problems found.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</p>
						) : (
							<ul className="dev3ac-checks">
								{ quality.map( ( q ) => (
									<li key={ q.code } className={ `is-${ q.level }` }>
										<Badge tone={ q.level === 'error' ? 'danger' : 'warning' }>{ q.level === 'error' ? __( 'Fix', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Tip', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Badge>
										<span>{ q.message }</span>
									</li>
								) ) }
							</ul>
						) }
					</Card>
					{ form.id && (
						<div>
							<Button variant="danger" icon="trash" onClick={ () => setConfirmDelete( true ) }>
								{ __( 'Delete template', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
							</Button>
						</div>
					) }
				</Stack>
			</Columns>
			<Modal
				open={ testOpen }
				onClose={ () => setTestOpen( false ) }
				size="sm"
				title={ isSms ? __( 'Send a test SMS', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Send a test email', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				description={ __( 'Uses a sample cart and a preview coupon. Unsaved changes are included.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setTestOpen( false ) }>
							{ __( 'Cancel', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
						<Button variant="primary" onClick={ test } loading={ testing } disabled={ ! testTo.trim() }>
							{ __( 'Send test', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
					</>
				}
			>
				<TextField label={ isSms ? __( 'Phone number', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Email address', 'dev3-ai-abandoned-cart-for-woocommerce' ) } type={ isSms ? 'tel' : 'email' } value={ testTo } onChange={ setTestTo } data-autofocus />
			</Modal>
			<Modal
				open={ confirmDelete }
				onClose={ () => setConfirmDelete( false ) }
				size="sm"
				title={ __( 'Delete this template?', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirmDelete( false ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
						<Button variant="danger" onClick={ remove }>
							{ __( 'Delete', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
					</>
				}
			/>
		</>
	);
}

/**
 * Templates list (+ editor at #/templates/<id> or #/templates/new-email).
 *
 * @param {Object} props Props.
 * @return {Element} Screen.
 */
export default function TemplatesScreen( props ) {
	const { boot, navigate, params } = props;
	const id = params && params[ 0 ];
	if ( id ) {
		const tpl = id.startsWith( 'new-' ) ? { ...BLANK, channel: id === 'new-sms' ? 'sms' : 'email' } : boot.templates.find( ( t ) => t.id === id );
		if ( tpl ) {
			return <TemplateEditor key={ id } { ...props } template={ tpl } onClose={ ( next ) => navigate( next ? `templates/${ next }` : 'templates' ) } />;
		}
	}
	const used = {};
	boot.sequences.forEach( ( s ) => s.steps.forEach( ( st ) => ( used[ st.template_id ] = ( used[ st.template_id ] || [] ).concat( s.name ) ) ) );
	return (
		<>
			<PageHeader
				title={ __( 'Templates', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				description={ __( 'Approved copy for every reminder. With DEV3 AI personalisation on, each send is tailored to the cart and reviewed by you first.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
				actions={
					<>
						<Button variant="ghost" icon="plus" onClick={ () => navigate( 'templates/new-sms' ) }>
							{ __( 'New SMS', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
						<Button variant="primary" icon="plus" onClick={ () => navigate( 'templates/new-email' ) }>
							{ __( 'New email', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
						</Button>
					</>
				}
			/>
			<Card flush>
				<ResponsiveTable
					caption={ __( 'Templates', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
					rows={ boot.templates }
					onRowClick={ ( r ) => navigate( `templates/${ r.id }` ) }
					empty={ { icon: 'file', title: __( 'No templates yet', 'dev3-ai-abandoned-cart-for-woocommerce' ), action: <Button onClick={ () => navigate( 'templates/new-email' ) }>{ __( 'Create a template', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Button> } }
					columns={ [
						{ key: 'name', label: __( 'Template', 'dev3-ai-abandoned-cart-for-woocommerce' ), primary: true, sortable: true, render: ( r ) => ( <span className="dev3ac-who"><strong>{ r.name }</strong><small>{ r.channel === 'sms' ? r.sms : r.subject }</small></span> ) },
						{ key: 'channel', label: __( 'Channel', 'dev3-ai-abandoned-cart-for-woocommerce' ), render: ( r ) => ( r.channel === 'sms' ? 'SMS' : __( 'Email', 'dev3-ai-abandoned-cart-for-woocommerce' ) ) },
						{ key: 'status', label: __( 'Status', 'dev3-ai-abandoned-cart-for-woocommerce' ), render: ( r ) => <Badge tone={ r.status === 'approved' ? 'success' : 'warning' } dot>{ r.status === 'approved' ? __( 'Approved', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Draft', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Badge> },
						{ key: 'ai', label: __( 'DEV3 AI', 'dev3-ai-abandoned-cart-for-woocommerce' ), render: ( r ) => ( r.ai_personalise ? <Badge tone={ r.auto_approve ? 'warning' : 'brand' }>{ r.auto_approve ? __( 'Auto-approve', 'dev3-ai-abandoned-cart-for-woocommerce' ) : __( 'Reviewed', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</Badge> : '—' ) },
						{ key: 'used', label: __( 'Used in', 'dev3-ai-abandoned-cart-for-woocommerce' ), hideOnMobile: true, render: ( r ) => ( used[ r.id ] ? [ ...new Set( used[ r.id ] ) ].join( ', ' ) : '—' ) },
					] }
				/>
			</Card>
		</>
	);
}
