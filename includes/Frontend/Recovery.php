<?php
/**
 * Recovery links (restore the cart) and unsubscribe links.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Frontend;

use Dev3\AiAbandonedCart\Capture\Tracker;
use Dev3\AiAbandonedCart\Data\Carts;
use Dev3\AiAbandonedCart\Data\Messages;
use Dev3\AiAbandonedCart\Data\Suppressions;
use Dev3\AiAbandonedCart\Engine\Links;
use Dev3\AiAbandonedCart\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Handles ?dev3ac_recover= and ?dev3ac_unsub= on the front end.
 */
class Recovery {

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'wp_loaded', array( $this, 'route' ), 30 );
	}

	/**
	 * Dispatches shopper links.
	 */
	public function route() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- links from emails are verified by HMAC signatures.
		if ( is_admin() || ( ! isset( $_GET['dev3ac_recover'] ) && ! isset( $_GET['dev3ac_unsub'] ) ) ) {
			return;
		}
		$sig = isset( $_GET['dev3ac_s'] ) ? sanitize_text_field( wp_unslash( $_GET['dev3ac_s'] ) ) : '';
		if ( isset( $_GET['dev3ac_unsub'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET['dev3ac_unsub'] ) );
			$url   = self::unsubscribe( $token, $sig );
		} else {
			$token = sanitize_text_field( wp_unslash( $_GET['dev3ac_recover'] ) );
			$mid   = isset( $_GET['dev3ac_m'] ) ? absint( $_GET['dev3ac_m'] ) : 0;
			$url   = self::recover( $token, $mid, $sig );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Restores a cart. Returns the URL to redirect to.
	 *
	 * @param string $token Token.
	 * @param int    $mid   Message id.
	 * @param string $sig   Signature.
	 * @return string
	 */
	public static function recover( $token, $mid, $sig ) {
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		$cart = Carts::by_token( $token );
		if ( ! $cart || ! Links::verify( $sig, 'recover', $cart['token'], (int) $mid ) ) {
			self::notice( __( 'This link is no longer valid, but you can still browse the shop.', 'dev3-ai-abandoned-cart-for-woocommerce' ), 'notice' );
			return $shop;
		}
		if ( ! function_exists( 'WC' ) ) {
			return $shop;
		}
		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! WC()->cart || ! WC()->session ) {
			return $shop;
		}
		WC()->session->set_customer_session_cookie( true );
		// Link the session first so cart-update hooks write into this cart row instead of a new one.
		$early = array( 'session_key' => Tracker::session_key() );
		if ( in_array( $cart['status'], array( 'abandoned', 'lost' ), true ) ) {
			$early['status'] = 'recovering';
		}
		Carts::update( $cart['id'], $early );
		WC()->session->set( Tracker::SESSION_ID, (int) $cart['id'] );

		$restored = 0;
		WC()->cart->empty_cart( false );
		foreach ( (array) $cart['items'] as $item ) {
			$product = wc_get_product( ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'] );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}
			$added = WC()->cart->add_to_cart( (int) $item['product_id'], max( 1, (int) $item['quantity'] ), (int) $item['variation_id'], (array) $item['variation'] );
			if ( $added ) {
				++$restored;
			}
		}
		wc_clear_notices();

		$msg  = $mid ? Messages::get( $mid ) : null;
		$code = $msg && (int) $msg['cart_id'] === (int) $cart['id'] ? (string) $msg['coupon_code'] : '';
		if ( '' === $code && $cart['coupon_codes'] ) {
			$codes = (array) $cart['coupon_codes'];
			$code  = (string) end( $codes );
		}
		if ( '' !== $code && $restored && ! WC()->cart->has_discount( $code ) ) {
			WC()->cart->apply_coupon( $code );
		}
		if ( WC()->customer ) {
			if ( ! WC()->customer->get_billing_email() && is_email( $cart['email'] ) ) {
				WC()->customer->set_billing_email( $cart['email'] );
			}
			if ( ! WC()->customer->get_billing_first_name() && $cart['first_name'] ) {
				WC()->customer->set_billing_first_name( $cart['first_name'] );
			}
			if ( ! WC()->customer->get_billing_last_name() && $cart['last_name'] ) {
				WC()->customer->set_billing_last_name( $cart['last_name'] );
			}
			if ( ! WC()->customer->get_billing_phone() && $cart['phone'] ) {
				WC()->customer->set_billing_phone( $cart['phone'] );
			}
			WC()->customer->save();
		}

		$now = Carts::now();
		Carts::update(
			$cart['id'],
			array(
				'session_key' => Tracker::session_key(),
				'clicked_at'  => $now,
			)
		);
		if ( $msg && (int) $msg['cart_id'] === (int) $cart['id'] && empty( $msg['clicked_at'] ) ) {
			Messages::update(
				$msg['id'],
				array(
					'clicked_at' => $now,
					'opened_at'  => $msg['opened_at'] ? $msg['opened_at'] : $now,
				)
			);
		}
		WC()->session->set( Tracker::SESSION_ID, (int) $cart['id'] );
		WC()->session->set( Tracker::SESSION_HASH, null );

		if ( $restored ) {
			self::notice( __( 'Welcome back! Your cart has been restored.', 'dev3-ai-abandoned-cart-for-woocommerce' ), 'success' );
		} else {
			self::notice( __( 'Sorry, the items from your cart are no longer available.', 'dev3-ai-abandoned-cart-for-woocommerce' ), 'notice' );
		}
		$url = $restored ? ( 'cart' === Settings::get( 'redirect_to' ) ? wc_get_cart_url() : wc_get_checkout_url() ) : $shop;
		/**
		 * Filters where a recovery link sends the shopper.
		 *
		 * @param string $url  URL.
		 * @param array  $cart Cart.
		 */
		return (string) apply_filters( 'dev3ac_recovery_redirect', $url, $cart );
	}

	/**
	 * Unsubscribes the cart's email and phone. Returns the URL to redirect to.
	 *
	 * @param string $token Token.
	 * @param string $sig   Signature.
	 * @return string
	 */
	public static function unsubscribe( $token, $sig ) {
		$cart = Carts::by_token( $token );
		$url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		if ( ! $cart || ! Links::verify( $sig, 'unsub', $cart['token'] ) ) {
			self::notice( __( 'This unsubscribe link is not valid.', 'dev3-ai-abandoned-cart-for-woocommerce' ), 'notice' );
			return $url;
		}
		Suppressions::add( $cart['email'] );
		if ( $cart['phone'] ) {
			Suppressions::add( $cart['phone'] );
		}
		foreach ( Carts::for_email( $cart['email'], array( 'active', 'abandoned', 'recovering' ) ) as $row ) {
			Messages::cancel_for_cart( $row['id'], __( 'Recipient unsubscribed.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
			Carts::update(
				$row['id'],
				array(
					'status'       => 'unsubscribed',
					'next_send_at' => null,
				)
			);
		}
		Messages::cancel_for_cart( $cart['id'], __( 'Recipient unsubscribed.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		if ( in_array( $cart['status'], array( 'active', 'abandoned', 'recovering', 'lost' ), true ) ) {
			Carts::update( $cart['id'], array( 'status' => 'unsubscribed' ) );
		}
		self::notice( __( 'You have been unsubscribed. You will not receive cart reminders from us again.', 'dev3-ai-abandoned-cart-for-woocommerce' ), 'success' );
		return $url;
	}

	/**
	 * Adds a WooCommerce notice when possible.
	 *
	 * @param string $text Text.
	 * @param string $type Type.
	 */
	private static function notice( $text, $type ) {
		if ( function_exists( 'wc_add_notice' ) && function_exists( 'WC' ) && WC()->session ) {
			wc_add_notice( $text, $type );
		}
	}
}
