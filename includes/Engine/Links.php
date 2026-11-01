<?php
/**
 * Signed shopper links: recovery, unsubscribe and the open pixel.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-signed URLs. The cart token (32 random chars) identifies the cart; the signature binds the message id.
 */
class Links {

	/**
	 * Signature for a purpose + values.
	 *
	 * @param string $purpose recover|unsub|open.
	 * @param string ...$parts Parts.
	 * @return string
	 */
	public static function sign( $purpose, ...$parts ) {
		return substr( hash_hmac( 'sha256', $purpose . '|' . implode( '|', array_map( 'strval', $parts ) ), wp_salt( 'auth' ) . 'dev3ac' ), 0, 20 );
	}

	/**
	 * Constant-time check.
	 *
	 * @param string $sig     Given signature.
	 * @param string $purpose Purpose.
	 * @param string ...$parts Parts.
	 * @return bool
	 */
	public static function verify( $sig, $purpose, ...$parts ) {
		return is_string( $sig ) && '' !== $sig && hash_equals( self::sign( $purpose, ...$parts ), $sig );
	}

	/**
	 * Recovery URL for a cart (and the message it is sent in).
	 *
	 * @param array $cart       Cart.
	 * @param int   $message_id Message id (0 = manual/admin link).
	 * @return string
	 */
	public static function recovery( array $cart, $message_id = 0 ) {
		return add_query_arg(
			array(
				'dev3ac_recover' => $cart['token'],
				'dev3ac_m'       => (int) $message_id,
				'dev3ac_s'       => self::sign( 'recover', $cart['token'], (int) $message_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Unsubscribe URL.
	 *
	 * @param array $cart Cart.
	 * @return string
	 */
	public static function unsubscribe( array $cart ) {
		return add_query_arg(
			array(
				'dev3ac_unsub' => $cart['token'],
				'dev3ac_s'     => self::sign( 'unsub', $cart['token'] ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Open-tracking pixel URL.
	 *
	 * @param int $message_id Message id.
	 * @return string
	 */
	public static function pixel( $message_id ) {
		return add_query_arg(
			array(
				'm' => (int) $message_id,
				's' => self::sign( 'open', (int) $message_id ),
			),
			rest_url( 'dev3ac/v1/t/o' )
		);
	}
}
