<?php
/**
 * Checkout consent fields (classic + block) and the capture script.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Frontend;

use Dev3\AiAbandonedCart\Core3\Core\Assets;
use Dev3\AiAbandonedCart\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shopper-facing text here stays neutral (no DEV3 branding).
 */
class Checkout {

	/**
	 * Hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_form_field', array( $this, 'classic_field' ), 10, 4 );
		add_action( 'woocommerce_init', array( $this, 'block_fields' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Appends the consent notice/checkbox under billing email (and SMS opt-in under phone) on the classic checkout.
	 *
	 * @param string $field Field HTML.
	 * @param string $key   Field key.
	 * @param array  $args  Args.
	 * @param mixed  $value Value.
	 * @return string
	 */
	public function classic_field( $field, $key, $args = array(), $value = null ) {
		unset( $args, $value );
		if ( ! Settings::get( 'tracking_enabled' ) || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return $field;
		}
		if ( 'billing_email' === $key ) {
			return $field . self::consent_html();
		}
		if ( 'billing_phone' === $key && Settings::get( 'sms_enabled' ) ) {
			return $field . '<p class="form-row form-row-wide dev3ac-consent dev3ac-consent--sms"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="dev3ac_sms_consent" id="dev3ac_sms_consent" value="1" /> <span>' . esc_html( (string) Settings::get( 'sms_consent_label' ) ) . '</span></label></p>';
		}
		return $field;
	}

	/**
	 * Consent markup for the current mode.
	 *
	 * @return string
	 */
	public static function consent_html() {
		$mode = (string) Settings::get( 'consent_mode' );
		if ( 'opt_in' === $mode ) {
			return '<p class="form-row form-row-wide dev3ac-consent"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="dev3ac_consent" id="dev3ac_consent" value="1" /> <span>' . esc_html( (string) Settings::get( 'consent_label' ) ) . '</span></label></p>';
		}
		if ( 'notice' === $mode ) {
			return '<p class="form-row form-row-wide dev3ac-consent-note"><small>' . esc_html( (string) Settings::get( 'consent_text' ) ) . '</small></p>';
		}
		return '';
	}

	/**
	 * Block checkout: consent checkboxes through the additional checkout fields API (WooCommerce 8.9+).
	 */
	public function block_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! Settings::get( 'tracking_enabled' ) ) {
			return;
		}
		if ( 'opt_in' === Settings::get( 'consent_mode' ) ) {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => 'dev3ac/consent',
					'label'    => (string) Settings::get( 'consent_label' ),
					'location' => 'contact',
					'type'     => 'checkbox',
				)
			);
		}
		if ( Settings::get( 'sms_enabled' ) ) {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => 'dev3ac/sms-consent',
					'label'    => (string) Settings::get( 'sms_consent_label' ),
					'location' => 'contact',
					'type'     => 'checkbox',
				)
			);
		}
	}

	/**
	 * Capture script on the checkout (and cart page for the save-my-cart form).
	 */
	public function enqueue() {
		if ( ! Settings::get( 'tracking_enabled' ) || ! function_exists( 'is_checkout' ) ) {
			return;
		}
		$checkout = is_checkout() && ! is_order_received_page();
		if ( ! $checkout && ! is_cart() && ! self::page_has_widget() ) {
			return;
		}
		Assets::enqueue( 'storefront' );
		$mode = (string) Settings::get( 'consent_mode' );
		wp_add_inline_script(
			Assets::handle( 'storefront' ),
			'window.dev3acCapture = ' . wp_json_encode(
				array(
					'restUrl'  => esc_url_raw( rest_url( 'dev3ac/v1/' ) ),
					'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
					'checkout' => $checkout,
					'classic'  => (bool) Settings::get( 'capture_classic' ),
					'block'    => (bool) Settings::get( 'capture_block' ),
					'mode'     => $mode,
					'notice'   => 'notice' === $mode ? (string) Settings::get( 'consent_text' ) : '',
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Whether the current singular post contains the save-cart widget.
	 *
	 * @return bool
	 */
	private static function page_has_widget() {
		$post = get_post();
		return $post && ( has_shortcode( (string) $post->post_content, 'dev3ac_save_cart' ) || has_block( 'dev3ac/save-cart', $post ) );
	}
}
