import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Dashboard from './Dashboard';
import CartsScreen from './CartsScreen';
import ReviewScreen from './ReviewScreen';
import SequencesScreen from './SequencesScreen';
import TemplatesScreen from './TemplatesScreen';
import MessagesScreen from './MessagesScreen';
import SettingsScreen from './SettingsScreen';
import './admin.css';

const IDS = [ 'dashboard', 'carts', 'review', 'sequences', 'templates', 'messages', 'settings', 'api', 'usage' ];

export default function App() {
	const [ route, navigate ] = useHashRoute( IDS, 'dashboard' );
	const [ boot, setBoot ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );

	const load = () => {
		setError( null );
		return request( 'bootstrap' ).then( setBoot ).catch( setError );
	};
	useEffect( () => {
		load();
	}, [] );

	const patch = ( p ) => setBoot( ( b ) => ( { ...b, ...p } ) );
	const save = ( values ) =>
		request( 'config', { method: 'POST', data: values } ).then( ( settings ) => {
			patch( { settings } );
			setMeter( ( n ) => n + 1 );
			return settings;
		} );
	const onUsage = () => setMeter( ( n ) => n + 1 );

	const review = boot ? boot.review_count : 0;
	const nav = [
		{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-abandoned-cart-for-woocommerce' ), icon: 'dashboard' },
		{ id: 'carts', label: __( 'Carts', 'dev3-ai-abandoned-cart-for-woocommerce' ), icon: 'box', badge: boot && boot.counts.abandoned ? String( boot.counts.abandoned ) : undefined },
		{ id: 'review', label: __( 'Review queue', 'dev3-ai-abandoned-cart-for-woocommerce' ), icon: 'sparkles', badge: review ? String( review ) : undefined },
		{ id: 'sequences', label: __( 'Sequences', 'dev3-ai-abandoned-cart-for-woocommerce' ), icon: 'clock' },
		{ id: 'templates', label: __( 'Templates', 'dev3-ai-abandoned-cart-for-woocommerce' ), icon: 'file' },
		{ id: 'messages', label: __( 'Messages', 'dev3-ai-abandoned-cart-for-woocommerce' ), icon: 'inbox' },
		{ id: 'settings', label: __( 'Settings', 'dev3-ai-abandoned-cart-for-woocommerce' ), icon: 'settings' },
		{ id: 'api', label: __( 'API Config', 'dev3-ai-abandoned-cart-for-woocommerce' ), icon: 'key' },
		{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-abandoned-cart-for-woocommerce' ), icon: 'chart' },
	];

	const props = { boot, patch, save, reload: load, navigate, onUsage, params: route.params };
	const screens = {
		dashboard: () => <Dashboard { ...props } />,
		carts: () => <CartsScreen { ...props } />,
		review: () => <ReviewScreen { ...props } />,
		sequences: () => <SequencesScreen { ...props } />,
		templates: () => <TemplatesScreen { ...props } />,
		messages: () => <MessagesScreen { ...props } />,
		settings: () => <SettingsScreen { ...props } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-abandoned-cart-for-woocommerce' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget. Cart recovery works without a key, using your approved templates.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
				<ApiTab settings={ boot.settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-abandoned-cart-for-woocommerce' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Abandoned Cart & Win-back Emails for WooCommerce' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ nav }
			active={ route.id }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! boot && ! error && <SkeletonCard lines={ 6 } /> }
			{ boot && screens[ route.id ]() }
		</AppShell>
	);
}
