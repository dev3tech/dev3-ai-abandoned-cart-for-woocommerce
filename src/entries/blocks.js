import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import '../storefront/storefront.css';

registerBlockType( 'dev3ac/save-cart', {
	apiVersion: 2,
	title: __( 'Save my cart', 'dev3-ai-abandoned-cart-for-woocommerce' ),
	description: __( 'Lets shoppers email themselves a link to their cart (shown only when the cart has items).', 'dev3-ai-abandoned-cart-for-woocommerce' ),
	category: 'woocommerce',
	icon: 'email-alt',
	attributes: {
		title: { type: 'string', default: '' },
		text: { type: 'string', default: '' },
		button: { type: 'string', default: '' },
	},
	supports: { html: false, align: [ 'wide', 'full' ] },
	edit: ( { attributes, setAttributes } ) => {
		const props = useBlockProps();
		return (
			<div { ...props }>
				<InspectorControls>
					<PanelBody title={ __( 'Text', 'dev3-ai-abandoned-cart-for-woocommerce' ) }>
						<TextControl label={ __( 'Title', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ attributes.title } onChange={ ( title ) => setAttributes( { title } ) } placeholder={ __( 'Not ready to check out?', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						<TextControl label={ __( 'Description', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ attributes.text } onChange={ ( text ) => setAttributes( { text } ) } />
						<TextControl label={ __( 'Button', 'dev3-ai-abandoned-cart-for-woocommerce' ) } value={ attributes.button } onChange={ ( button ) => setAttributes( { button } ) } placeholder={ __( 'Email me my cart', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
					</PanelBody>
				</InspectorControls>
				<div className="dev3ac-save">
					<p className="dev3ac-save__title">{ attributes.title || __( 'Not ready to check out?', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</p>
					<p className="dev3ac-save__text">{ attributes.text || __( 'We\'ll email you a link to this cart so you can finish later on any device.', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</p>
					<div className="dev3ac-save__row">
						<input className="dev3ac-save__input" type="email" disabled placeholder="you@example.com" aria-label={ __( 'Email address', 'dev3-ai-abandoned-cart-for-woocommerce' ) } />
						<button className="dev3ac-save__btn" type="button" disabled>{ attributes.button || __( 'Email me my cart', 'dev3-ai-abandoned-cart-for-woocommerce' ) }</button>
					</div>
				</div>
			</div>
		);
	},
	save: () => null,
} );
