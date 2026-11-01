<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart;

use Dev3\AiAbandonedCart\Core3\Core\Encryption;
use Dev3\AiAbandonedCart\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3ac_settings_defaults / dev3ac_settings_sanitize.
 */
class Settings {

	/**
	 * Option holding the encrypted SMS auth token (kept out of the settings array so it never reaches the browser).
	 */
	const SMS_TOKEN_OPTION = 'dev3ac_sms_token';

	/**
	 * Plugin defaults.
	 *
	 * @return array
	 */
	public static function plugin_defaults() {
		return array(
			// Capture & consent.
			'tracking_enabled'      => true,
			'capture_classic'       => true,
			'capture_block'         => true,
			'capture_logged_in'     => true,
			'cutoff_minutes'        => 15,
			'min_cart_total'        => 0,
			'exclude_roles'         => array(),
			'consent_mode'          => 'notice',
			'consent_text'          => __( 'We save your email and cart so we can remind you about it if you don\'t finish your order. You can unsubscribe at any time.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			'consent_label'         => __( 'Email me a reminder about my cart and occasional offers.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			'sms_consent_label'     => __( 'Text me a reminder about my cart (msg & data rates may apply, reply STOP to opt out).', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			// Sending.
			'sending_enabled'       => true,
			'from_name'             => '',
			'from_email'            => '',
			'review_fallback'       => 'template',
			'review_hold_hours'     => 6,
			'send_time_tuning'      => true,
			'quiet_start'           => 21,
			'quiet_end'             => 8,
			'max_shift_hours'       => 6,
			'best_hours'            => array( 10, 19 ),
			'batch_size'            => 25,
			'ai_batch_size'         => 5,
			'lost_after_days'       => 7,
			'redirect_to'           => 'checkout',
			'brand_tone'            => 'friendly',
			'brand_notes'           => '',
			// Coupons.
			'coupon_prefix'         => 'COMEBACK',
			'coupon_cleanup'        => true,
			// SMS.
			'sms_enabled'           => false,
			'sms_base_url'          => 'https://api.twilio.com/2010-04-01',
			'sms_account_sid'       => '',
			'sms_from'              => '',
			'sms_messaging_service' => '',
			// Win-back.
			'winback_enabled'       => true,
			'winback_days'          => 60,
			'winback_window_days'   => 30,
			// Data.
			'retention_days'        => 90,
			'save_cart_widget'      => true,
		);
	}

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge( $defaults, self::plugin_defaults() );
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		$flags = array( 'tracking_enabled', 'capture_classic', 'capture_block', 'capture_logged_in', 'sending_enabled', 'send_time_tuning', 'coupon_cleanup', 'sms_enabled', 'winback_enabled', 'save_cart_widget' );
		foreach ( $flags as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		$ints = array(
			'cutoff_minutes'      => array( 5, 1440 ),
			'review_hold_hours'   => array( 1, 72 ),
			'quiet_start'         => array( 0, 23 ),
			'quiet_end'           => array( 0, 23 ),
			'max_shift_hours'     => array( 0, 24 ),
			'batch_size'          => array( 1, 200 ),
			'ai_batch_size'       => array( 0, 50 ),
			'lost_after_days'     => array( 1, 60 ),
			'winback_days'        => array( 7, 730 ),
			'winback_window_days' => array( 1, 365 ),
			'retention_days'      => array( 7, 3650 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		if ( isset( $input['min_cart_total'] ) ) {
			$out['min_cart_total'] = round( min( 1000000, max( 0, (float) $input['min_cart_total'] ) ), 2 );
		}
		if ( isset( $input['exclude_roles'] ) && is_array( $input['exclude_roles'] ) ) {
			$roles                = array_keys( wp_roles()->get_names() );
			$out['exclude_roles'] = array_values( array_intersect( array_map( 'sanitize_key', $input['exclude_roles'] ), $roles ) );
		}
		$enums = array(
			'consent_mode'    => array( 'notice', 'opt_in', 'none' ),
			'review_fallback' => array( 'template', 'hold' ),
			'redirect_to'     => array( 'checkout', 'cart' ),
			'brand_tone'      => array( 'friendly', 'professional', 'playful', 'luxury', 'minimal' ),
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) {
				$out[ $key ] = $input[ $key ];
			}
		}
		foreach ( array(
			'consent_text'      => 400,
			'consent_label'     => 200,
			'sms_consent_label' => 200,
			'brand_notes'       => 600,
		) as $key => $max ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input[ $key ] ) ), 0, $max );
			}
		}
		if ( isset( $input['from_name'] ) ) {
			$out['from_name'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['from_name'] ) ), 0, 80 );
		}
		if ( isset( $input['from_email'] ) ) {
			$email             = sanitize_email( (string) $input['from_email'] );
			$out['from_email'] = is_email( $email ) ? $email : '';
		}
		if ( isset( $input['coupon_prefix'] ) ) {
			$prefix               = strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $input['coupon_prefix'] ) );
			$out['coupon_prefix'] = '' === $prefix ? 'COMEBACK' : substr( $prefix, 0, 12 );
		}
		if ( isset( $input['best_hours'] ) && is_array( $input['best_hours'] ) ) {
			$hours = array();
			foreach ( $input['best_hours'] as $h ) {
				$h = (int) $h;
				if ( $h >= 0 && $h <= 23 ) {
					$hours[] = $h;
				}
			}
			$hours             = array_values( array_unique( $hours ) );
			$out['best_hours'] = $hours ? array_slice( $hours, 0, 6 ) : array( 10, 19 );
		}
		if ( isset( $input['sms_base_url'] ) ) {
			$url                 = esc_url_raw( trim( (string) $input['sms_base_url'] ) );
			$out['sms_base_url'] = ( $url && 0 === strpos( $url, 'https://' ) ) ? untrailingslashit( $url ) : 'https://api.twilio.com/2010-04-01';
		}
		if ( isset( $input['sms_account_sid'] ) ) {
			$out['sms_account_sid'] = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $input['sms_account_sid'] ), 0, 64 );
		}
		if ( isset( $input['sms_messaging_service'] ) ) {
			$out['sms_messaging_service'] = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $input['sms_messaging_service'] ), 0, 64 );
		}
		if ( isset( $input['sms_from'] ) ) {
			$out['sms_from'] = substr( preg_replace( '/[^0-9+A-Za-z ]/', '', (string) $input['sms_from'] ), 0, 20 );
		}
		if ( array_key_exists( 'sms_auth_token', $input ) ) {
			$token = trim( sanitize_text_field( wp_unslash( (string) $input['sms_auth_token'] ) ) );
			if ( false === strpos( $token, '•' ) ) {
				update_option( self::SMS_TOKEN_OPTION, '' === $token ? '' : Encryption::encrypt( $token ), false );
			}
		}
		unset( $out['sms_auth_token'] );
		return $out;
	}

	/**
	 * Decrypted SMS auth token (server only).
	 *
	 * @return string
	 */
	public static function sms_token() {
		return Encryption::decrypt( (string) get_option( self::SMS_TOKEN_OPTION, '' ) );
	}

	/**
	 * A setting with the plugin default as fallback.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$defaults = self::plugin_defaults();
		return Settings_Store::get( $key, isset( $defaults[ $key ] ) ? $defaults[ $key ] : null );
	}

	/**
	 * Browser-safe settings plus SMS token state.
	 *
	 * @return array
	 */
	public static function for_admin() {
		$all                   = Settings_Store::public_settings();
		$token                 = self::sms_token();
		$all['sms_token_set']  = '' !== $token;
		$all['sms_auth_token'] = '' === $token ? '' : str_repeat( '•', 8 ) . substr( $token, -4 );
		return $all;
	}
}
