import { startCapture, startSaveForms } from './capture';
import './storefront.css';

const boot = () => {
	const cfg = window.dev3acCapture;
	if ( ! cfg ) {
		return;
	}
	if ( cfg.checkout ) {
		startCapture( document, cfg );
	}
	startSaveForms( document, cfg );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
