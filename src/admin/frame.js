/**
 * Sizes an email preview iframe to its content (same-origin srcDoc, scripts stay disabled by the sandbox).
 *
 * @param {Event} e Load event.
 */
export function fitFrame( e ) {
	const frame = e.target;
	try {
		const doc = frame.contentDocument;
		if ( doc && doc.documentElement ) {
			frame.style.height = `${ Math.min( 2400, Math.max( 300, doc.documentElement.scrollHeight + 4 ) ) }px`;
		}
	} catch ( err ) {
		// Cross-origin: keep the CSS height.
	}
}
