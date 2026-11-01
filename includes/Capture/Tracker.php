<?php
/**
 * Cart capture (classic, block, logged-in) and order attribution.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Capture;

use Dev3\AiAbandonedCart\Data\Carts;
use Dev3\AiAbandonedCart\Data\Messages;
use Dev3\AiAbandonedCart\Data\Suppressions;
use Dev3\AiAbandonedCart\Engine\Send_Time;
use Dev3\AiAbandonedCart\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one cart row per WooCommerce session and links orders back to it.
 */
class Tracker {

	const SESSION_ID   = 'dev3ac_cart_id';
	const SESSION_HASH = 'dev3ac_hash';

	/**
	 * Re-entrancy guard.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'on_cart_updated' ), 20 );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( __CLASS__, 'on_store_api_customer' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'classic_consent_meta' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'on_order_created' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order_created' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_paid' ) );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_order_paid' ) );
	}

	/**
	 * WooCommerce session key ('' outside a session).
	 *
	 * @return string
	 */
	public static function session_key() {
		return ( function_exists( 'WC' ) && WC()->session ) ? (string) WC()->session->get_customer_id() : '';
	}

	/**
	 * Snapshot of the current WooCommerce cart.
	 *
	 * @return array { items, item_count, total, currency }
	 */
	public static function snapshot() {
		$items = array();
		$total = 0.0;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $line ) {
				$product = isset( $line['data'] ) ? $line['data'] : null;
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				$qty        = max( 1, (int) $line['quantity'] );
				$line_total = isset( $line['line_total'] ) ? (float) $line['line_total'] + (float) ( isset( $line['line_tax'] ) ? $line['line_tax'] : 0 ) : 0.0;
				if ( $line_total <= 0 ) {
					$line_total = (float) wc_get_price_to_display( $product ) * $qty;
				}
				$variation = array();
				foreach ( isset( $line['variation'] ) && is_array( $line['variation'] ) ? $line['variation'] : array() as $attr => $value ) {
					$variation[ sanitize_title( (string) $attr ) ] = sanitize_text_field( (string) $value );
				}
				$items[] = array(
					'product_id'   => (int) $line['product_id'],
					'variation_id' => (int) ( isset( $line['variation_id'] ) ? $line['variation_id'] : 0 ),
					'quantity'     => $qty,
					'variation'    => $variation,
					'name'         => wp_strip_all_tags( $product->get_name() ),
					'line_total'   => round( $line_total, 4 ),
				);
				$total  += $line_total;
			}
		}
		/**
		 * Filters the captured cart items.
		 *
		 * @param array $items Items.
		 */
		$items = apply_filters( 'dev3ac_cart_items', $items );
		return array(
			'items'      => $items,
			'item_count' => array_sum( wp_list_pluck( $items, 'quantity' ) ),
			'total'      => round( $total, 4 ),
			'currency'   => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
		);
	}

	/**
	 * Whether capture is allowed for the current visitor.
	 *
	 * @param array $data Data.
	 * @return bool
	 */
	public static function allowed( array $data ) {
		$allowed = (bool) Settings::get( 'tracking_enabled' );
		if ( $allowed && is_user_logged_in() ) {
			$roles   = (array) wp_get_current_user()->roles;
			$allowed = ! array_intersect( $roles, (array) Settings::get( 'exclude_roles' ) );
		}
		/**
		 * Filters whether a cart may be captured.
		 *
		 * @param bool  $allowed Allowed.
		 * @param array $data    Contact data.
		 */
		return (bool) apply_filters( 'dev3ac_capture_allowed', $allowed, $data );
	}

	/**
	 * Captures or updates the current session's cart with contact details.
	 *
	 * @param array $data { email, phone, first_name, last_name, country, consent (bool), sms_consent (bool), tz_offset, tz_known, locale, source }.
	 * @return int Cart id (0 = not captured).
	 */
	public static function capture( array $data ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() || ! self::allowed( $data ) ) {
			return 0;
		}
		$mode     = (string) Settings::get( 'consent_mode' );
		$email    = isset( $data['email'] ) ? strtolower( sanitize_email( (string) $data['email'] ) ) : '';
		$email    = is_email( $email ) ? $email : '';
		$consent  = ! empty( $data['consent'] );
		$existing = self::current_row();

		if ( 'opt_in' === $mode && ! $consent ) {
			// No consent: store nothing personal; withdraw what an earlier tick stored.
			if ( $existing && 'active' === $existing['status'] ) {
				Carts::delete( $existing['id'] );
				self::forget();
			}
			return 0;
		}
		if ( '' !== $email && Suppressions::has( $email ) ) {
			if ( 'opt_in' === $mode && $consent ) {
				Suppressions::remove( $email ); // A fresh explicit opt-in re-subscribes.
			} else {
				return 0;
			}
		}
		$phone = isset( $data['phone'] ) ? substr( preg_replace( '/[^0-9+]/', '', (string) $data['phone'] ), 0, 32 ) : '';
		$sms   = ! empty( $data['sms_consent'] ) && '' !== $phone;
		if ( '' === $email && ! $sms && ! $existing ) {
			return 0;
		}
		$row = array_merge(
			self::snapshot(),
			array(
				'session_key' => self::session_key(),
				'user_id'     => get_current_user_id(),
				'updated_at'  => Carts::now(),
			)
		);
		if ( '' !== $email ) {
			$row['email'] = $email;
		}
		if ( '' !== $phone ) {
			$row['phone'] = $phone;
		}
		foreach ( array( 'first_name', 'last_name' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$row[ $key ] = mb_substr( sanitize_text_field( (string) $data[ $key ] ), 0, 80 );
			}
		}
		if ( ! empty( $data['country'] ) && preg_match( '/^[A-Za-z]{2}$/', (string) $data['country'] ) ) {
			$row['country'] = strtoupper( (string) $data['country'] );
		}
		if ( ! empty( $data['locale'] ) ) {
			$row['locale'] = substr( preg_replace( '/[^A-Za-z_-]/', '', (string) $data['locale'] ), 0, 20 );
		}
		if ( isset( $data['tz_offset'] ) && ! empty( $data['tz_known'] ) ) {
			$row['tz_offset'] = min( 840, max( -720, (int) $data['tz_offset'] ) );
			$row['tz_known']  = 1;
		} elseif ( ! $existing ) {
			$row['tz_offset'] = Send_Time::store_offset();
		}
		if ( isset( $data['sms_consent'] ) ) {
			$row['sms_consent'] = $sms ? 1 : 0;
		}
		$row['consent'] = 'opt_in' === $mode ? 'opt_in' : $mode;
		if ( ! $existing || empty( $existing['consent_at'] ) ) {
			$row['consent_at'] = Carts::now();
		}
		if ( ! empty( $data['source'] ) && in_array( $data['source'], array( 'classic', 'block', 'account', 'save_form', 'api' ), true ) ) {
			$row['source'] = $data['source'];
		}
		if ( $existing ) {
			Carts::update( $existing['id'], $row );
			$id = (int) $existing['id'];
		} else {
			$id = Carts::insert( $row );
		}
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_ID, $id );
			WC()->session->set( self::SESSION_HASH, md5( (string) wp_json_encode( $row['items'] ) . $row['total'] ) );
			if ( 'opt_in' === $mode || $consent ) {
				WC()->session->set( 'dev3ac_consent', $consent ? 'yes' : '' );
			}
			if ( isset( $data['sms_consent'] ) ) {
				WC()->session->set( 'dev3ac_sms_consent', $sms ? 'yes' : '' );
			}
		}
		return $id;
	}

	/**
	 * The session's open cart row.
	 *
	 * @return array|null
	 */
	public static function current_row() {
		$key = self::session_key();
		if ( '' === $key ) {
			return null;
		}
		$id = WC()->session ? (int) WC()->session->get( self::SESSION_ID ) : 0;
		if ( $id ) {
			$cart = Carts::get( $id );
			if ( $cart && in_array( $cart['status'], array( 'active', 'abandoned', 'recovering' ), true ) && 0 === (int) $cart['order_id'] ) {
				return $cart;
			}
		}
		$cart = Carts::active_for_session( $key );
		return ( $cart && 0 === (int) $cart['order_id'] ) ? $cart : null;
	}

	/**
	 * Forgets the session link.
	 */
	public static function forget() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_ID, null );
			WC()->session->set( self::SESSION_HASH, null );
		}
	}

	/**
	 * Keeps the cart row in sync with the WooCommerce cart (and links logged-in customers).
	 */
	public static function on_cart_updated() {
		if ( self::$busy || ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session || ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() ) {
			return;
		}
		self::$busy = true;
		try {
			$snap = self::snapshot();
			$hash = md5( (string) wp_json_encode( $snap['items'] ) . $snap['total'] );
			if ( WC()->session->get( self::SESSION_HASH ) === $hash ) {
				return;
			}
			$row = self::current_row();
			if ( $row ) {
				if ( empty( $snap['items'] ) ) {
					if ( 'active' === $row['status'] ) {
						Carts::delete( $row['id'] );
						self::forget();
					}
					return;
				}
				Carts::update( $row['id'], array_merge( $snap, array( 'updated_at' => Carts::now() ) ) );
				WC()->session->set( self::SESSION_HASH, $hash );
				return;
			}
			if ( empty( $snap['items'] ) || ! is_user_logged_in() || ! Settings::get( 'capture_logged_in' ) ) {
				return;
			}
			$user    = wp_get_current_user();
			$consent = 'yes' === get_user_meta( $user->ID, 'dev3ac_consent', true );
			if ( 'opt_in' === Settings::get( 'consent_mode' ) && ! $consent ) {
				return;
			}
			$customer = WC()->customer;
			self::capture(
				array(
					'email'      => $customer && $customer->get_billing_email() ? $customer->get_billing_email() : $user->user_email,
					'first_name' => $customer ? $customer->get_billing_first_name() : $user->first_name,
					'last_name'  => $customer ? $customer->get_billing_last_name() : $user->last_name,
					'country'    => $customer ? $customer->get_billing_country() : '',
					'consent'    => $consent,
					'source'     => 'account',
					'locale'     => get_user_locale( $user ),
				)
			);
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * Block checkout: the Store API pushed the customer's address/email.
	 *
	 * @param \WC_Customer     $customer Customer.
	 * @param \WP_REST_Request $request  Request.
	 */
	public static function on_store_api_customer( $customer, $request = null ) {
		unset( $request );
		if ( ! Settings::get( 'capture_block' ) || ! $customer instanceof \WC_Customer ) {
			return;
		}
		$mode = (string) Settings::get( 'consent_mode' );
		$row  = self::current_row();
		if ( 'opt_in' === $mode && ( ! $row || 'opt_in' !== $row['consent'] ) ) {
			return; // Waits for the consent checkbox (sent by the storefront script).
		}
		self::capture(
			array(
				'email'      => $customer->get_billing_email(),
				'phone'      => $customer->get_billing_phone(),
				'first_name' => $customer->get_billing_first_name(),
				'last_name'  => $customer->get_billing_last_name(),
				'country'    => $customer->get_billing_country(),
				'consent'    => true,
				'source'     => 'block',
			)
		);
	}

	/**
	 * Classic checkout: copies consent checkboxes to the order.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $data  Posted data.
	 */
	public static function classic_consent_meta( $order, $data = array() ) {
		unset( $data );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before this hook.
		$consent = ! empty( $_POST['dev3ac_consent'] );
		$sms     = ! empty( $_POST['dev3ac_sms_consent'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( 'opt_in' !== Settings::get( 'consent_mode' ) ) {
			$consent = true;
		}
		$order->update_meta_data( '_dev3ac_consent', $consent ? 'yes' : 'no' );
		$order->update_meta_data( '_dev3ac_sms_consent', $sms ? 'yes' : 'no' );
		if ( $order->get_customer_id() && $consent ) {
			update_user_meta( $order->get_customer_id(), 'dev3ac_consent', 'yes' );
		}
	}

	/**
	 * Links a new order (classic or block) to the session cart.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function on_order_created( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$row = self::current_row();
		if ( '' === (string) $order->get_meta( '_dev3ac_consent' ) ) {
			// Block checkout: additional checkout fields (WooCommerce 8.9+) or the session flag set by capture.
			$field   = $order->get_meta( '_wc_other/dev3ac/consent' );
			$consent = 'opt_in' !== Settings::get( 'consent_mode' ) || ! empty( $field ) || ( WC()->session && 'yes' === WC()->session->get( 'dev3ac_consent' ) );
			$sms     = ! empty( $order->get_meta( '_wc_other/dev3ac/sms-consent' ) ) || ( WC()->session && 'yes' === WC()->session->get( 'dev3ac_sms_consent' ) );
			$order->update_meta_data( '_dev3ac_consent', $consent ? 'yes' : 'no' );
			$order->update_meta_data( '_dev3ac_sms_consent', $sms ? 'yes' : 'no' );
		}
		if ( $row ) {
			$order->update_meta_data( '_dev3ac_cart_id', (int) $row['id'] );
			Carts::update( $row['id'], array( 'order_id' => $order->get_id() ) );
		}
		$order->save();
		self::forget();
	}

	/**
	 * Paid order: marks the cart recovered (after a message/click) or converted, and stops other open sequences.
	 *
	 * @param int|\WC_Order $order_id Order.
	 */
	public static function on_order_paid( $order_id ) {
		$order = $order_id instanceof \WC_Order ? $order_id : wc_get_order( $order_id );
		if ( ! $order || 'yes' === $order->get_meta( '_dev3ac_handled' ) ) {
			return;
		}
		$email   = strtolower( (string) $order->get_billing_email() );
		$cart    = null;
		$cart_id = (int) $order->get_meta( '_dev3ac_cart_id' );
		if ( $cart_id ) {
			$cart = Carts::get( $cart_id );
		}
		if ( ! $cart && is_email( $email ) ) {
			$open = Carts::for_email( $email, array( 'abandoned', 'recovering' ) );
			$cart = $open ? $open[0] : null;
		}
		$order->update_meta_data( '_dev3ac_handled', 'yes' );
		if ( $cart ) {
			self::attribute( $cart, $order );
		}
		// Any other open sequence for this shopper stops: they just bought.
		if ( is_email( $email ) ) {
			foreach ( Carts::for_email( $email, array( 'active', 'abandoned', 'recovering' ) ) as $other ) {
				if ( $cart && (int) $other['id'] === (int) $cart['id'] ) {
					continue;
				}
				$sent = self::sent_steps( $other['id'] );
				if ( 'winback' === $other['kind'] && $sent ) {
					self::attribute( $other, $order );
					continue;
				}
				Messages::cancel_for_cart( $other['id'], __( 'The shopper placed an order.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
				Carts::update(
					$other['id'],
					array(
						'status'       => 'active' === $other['status'] ? 'converted' : 'stopped',
						'next_send_at' => null,
					)
				);
			}
		}
		$order->save();
	}

	/**
	 * Step indexes of sent messages for a cart.
	 *
	 * @param int $cart_id Cart.
	 * @return array
	 */
	private static function sent_steps( $cart_id ) {
		$out = array();
		foreach ( Messages::for_cart( $cart_id ) as $msg ) {
			if ( 'sent' === $msg['status'] ) {
				$out[] = (int) $msg['step_index'];
			}
		}
		return $out;
	}

	/**
	 * Records the outcome for one cart.
	 *
	 * @param array     $cart  Cart.
	 * @param \WC_Order $order Order.
	 */
	private static function attribute( array $cart, $order ) {
		if ( in_array( $cart['status'], array( 'recovered', 'converted' ), true ) ) {
			return;
		}
		Messages::cancel_for_cart( $cart['id'], __( 'The shopper placed an order.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		$sent      = self::sent_steps( $cart['id'] );
		$clicked   = ! empty( $cart['clicked_at'] );
		$recovered = in_array( $cart['status'], array( 'abandoned', 'recovering', 'lost' ), true ) && ( $sent || $clicked );
		$codes     = array_map( 'strtolower', (array) $cart['coupon_codes'] );
		$used      = (bool) array_intersect( $codes, array_map( 'strtolower', $order->get_coupon_codes() ) );
		if ( ! $recovered ) {
			Carts::update(
				$cart['id'],
				array(
					'status'       => 'converted',
					'order_id'     => $order->get_id(),
					'next_send_at' => null,
				)
			);
			return;
		}
		$step = -1;
		foreach ( Messages::for_cart( $cart['id'] ) as $msg ) {
			if ( ! empty( $msg['clicked_at'] ) ) {
				$step = (int) $msg['step_index'];
			}
		}
		if ( $step < 0 && $sent ) {
			$step = max( $sent );
		}
		Carts::update(
			$cart['id'],
			array(
				'status'          => 'recovered',
				'order_id'        => $order->get_id(),
				'recovered_total' => (float) $order->get_total(),
				'recovered_step'  => $step,
				'recovered_at'    => Carts::now(),
				'coupon_used'     => $used ? 1 : 0,
				'next_send_at'    => null,
			)
		);
		$order->update_meta_data( '_dev3ac_recovered', (int) $cart['id'] );
		$order->add_order_note(
			sprintf(
				/* translators: 1: cart id, 2: step number */
				__( 'Recovered by DEV3 AI Abandoned Cart (cart #%1$d, step %2$d).', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				(int) $cart['id'],
				$step + 1
			)
		);
		Send_Time::flush();
		/**
		 * Fires when an abandoned cart or win-back turns into a paid order.
		 *
		 * @param array     $cart  Cart (before the update).
		 * @param \WC_Order $order Order.
		 */
		do_action( 'dev3ac_cart_recovered', Carts::get( $cart['id'] ), $order );
	}
}
