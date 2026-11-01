<?php
/**
 * Global unsubscribe list (hashed emails and phone numbers).
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Stores sha256 hashes only, so the list itself holds no readable personal data.
 */
class Suppressions {

	const OPTION = 'dev3ac_suppressions';

	/**
	 * Hash for an email or phone.
	 *
	 * @param string $value Email or phone.
	 * @return string
	 */
	public static function hash( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( false === strpos( $value, '@' ) ) {
			$value = preg_replace( '/[^0-9+]/', '', $value );
		}
		return hash( 'sha256', $value );
	}

	/**
	 * Whether a value is suppressed.
	 *
	 * @param string $value Email or phone.
	 * @return bool
	 */
	public static function has( $value ) {
		if ( '' === trim( (string) $value ) ) {
			return false;
		}
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) && isset( $list[ self::hash( $value ) ] );
	}

	/**
	 * Adds a value.
	 *
	 * @param string $value Email or phone.
	 */
	public static function add( $value ) {
		if ( '' === trim( (string) $value ) ) {
			return;
		}
		$list                         = get_option( self::OPTION, array() );
		$list                         = is_array( $list ) ? $list : array();
		$list[ self::hash( $value ) ] = time();
		update_option( self::OPTION, $list, false );
		/**
		 * Fires after an email address or phone number unsubscribed.
		 *
		 * @param string $value Email or phone.
		 */
		do_action( 'dev3ac_unsubscribed', $value );
	}

	/**
	 * Removes a value (re-subscribe, e.g. a fresh opt-in at checkout).
	 *
	 * @param string $value Email or phone.
	 */
	public static function remove( $value ) {
		$list = get_option( self::OPTION, array() );
		if ( is_array( $list ) && isset( $list[ self::hash( $value ) ] ) ) {
			unset( $list[ self::hash( $value ) ] );
			update_option( self::OPTION, $list, false );
		}
	}

	/**
	 * Number of suppressed entries.
	 *
	 * @return int
	 */
	public static function count() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? count( $list ) : 0;
	}
}
