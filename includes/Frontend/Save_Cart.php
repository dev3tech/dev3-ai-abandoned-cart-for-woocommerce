<?php
/**
 * "Save my cart" widget: block + shortcode.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Frontend;

use Dev3\AiAbandonedCart\Core3\Core\Assets;
use Dev3\AiAbandonedCart\Data\Carts;
use Dev3\AiAbandonedCart\Engine\Mailer;
use Dev3\AiAbandonedCart\Engine\Links;
use Dev3\AiAbandonedCart\Engine\Renderer;
use Dev3\AiAbandonedCart\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a shopper email themselves a link to the current cart.
 */
class Save_Cart {

	/**
	 * Instance counter for unique ids.
	 *
	 * @var int
	 */
	private static $n = 0;

	/**
	 * Hooks.
	 */
	public function register() {
		add_shortcode( 'dev3ac_save_cart', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ), 20 );
	}

	/**
	 * Dynamic block.
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			'dev3ac/save-cart',
			array(
				'api_version'     => 2,
				'title'           => __( 'Save my cart', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'category'        => 'woocommerce',
				'editor_script'   => Assets::handle( 'blocks' ),
				'editor_style'    => Assets::handle( 'blocks' ),
				'render_callback' => array( __CLASS__, 'render' ),
				'attributes'      => array(
					'title'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'text'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'button' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
			)
		);
	}

	/**
	 * Shortcode.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		return self::render(
			shortcode_atts(
				array(
					'title'  => '',
					'text'   => '',
					'button' => '',
				),
				(array) $atts,
				'dev3ac_save_cart'
			)
		);
	}

	/**
	 * Markup (empty when the cart is empty or the widget is off).
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		$atts = (array) $atts;
		if ( ! Settings::get( 'save_cart_widget' ) || ! Settings::get( 'tracking_enabled' ) || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '';
		}
		Assets::enqueue( 'storefront' );
		++self::$n;
		$id     = 'dev3ac-save-' . self::$n;
		$title  = ! empty( $atts['title'] ) ? $atts['title'] : __( 'Not ready to check out?', 'dev3-ai-abandoned-cart-for-woocommerce' );
		$text   = ! empty( $atts['text'] ) ? $atts['text'] : __( 'We\'ll email you a link to this cart so you can finish later on any device.', 'dev3-ai-abandoned-cart-for-woocommerce' );
		$button = ! empty( $atts['button'] ) ? $atts['button'] : __( 'Email me my cart', 'dev3-ai-abandoned-cart-for-woocommerce' );
		$mode   = (string) Settings::get( 'consent_mode' );
		$extra  = '';
		if ( 'opt_in' === $mode ) {
			$extra = '<label class="dev3ac-save__consent"><input type="checkbox" name="consent" value="1" required /> <span>' . esc_html( (string) Settings::get( 'consent_label' ) ) . '</span></label>';
		} elseif ( 'notice' === $mode ) {
			$extra = '<p class="dev3ac-save__note">' . esc_html( (string) Settings::get( 'consent_text' ) ) . '</p>';
		}
		return '<div class="dev3ac-save" data-dev3ac-save><form class="dev3ac-save__form" novalidate>'
			. '<p class="dev3ac-save__title">' . esc_html( $title ) . '</p>'
			. '<p class="dev3ac-save__text">' . esc_html( $text ) . '</p>'
			. '<div class="dev3ac-save__row"><label class="dev3ac-save__label" for="' . esc_attr( $id ) . '">' . esc_html__( 'Email address', 'dev3-ai-abandoned-cart-for-woocommerce' ) . '</label>'
			. '<input class="dev3ac-save__input" type="email" id="' . esc_attr( $id ) . '" name="email" autocomplete="email" required placeholder="' . esc_attr__( 'you@example.com', 'dev3-ai-abandoned-cart-for-woocommerce' ) . '" />'
			. '<button class="dev3ac-save__btn" type="submit">' . esc_html( $button ) . '</button></div>'
			. $extra
			. '<p class="dev3ac-save__status" role="status" aria-live="polite"></p>'
			. '</form></div>';
	}

	/**
	 * Sends the "your saved cart" email now (max once per 10 minutes per cart).
	 *
	 * @param int $cart_id Cart.
	 * @return bool|\WP_Error
	 */
	public static function send_link( $cart_id ) {
		$cart = Carts::get( $cart_id );
		if ( ! $cart || ! is_email( $cart['email'] ) ) {
			return false;
		}
		$key = 'dev3ac_saved_' . (int) $cart_id;
		if ( get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		$tpl   = array(
			'show_cart' => true,
		);
		$copy  = array(
			'subject'   => __( 'Your saved cart at {store_name}', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			'preheader' => __( 'Pick up where you left off, on any device.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			'heading'   => __( 'Your cart is saved', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			'body'      => __( 'Here is the link to the cart you saved. It keeps your items and quantities.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			'button'    => __( 'Open my cart', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			'sms'       => '',
			'is_ai'     => false,
		);
		$email = Renderer::email( $cart, $tpl, $copy, 0, null );
		return Mailer::send( $cart['email'], $email['subject'], $email['html'], Links::unsubscribe( $cart ) );
	}
}
