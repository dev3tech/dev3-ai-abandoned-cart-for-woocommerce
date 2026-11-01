<?php
/**
 * SMS through a Twilio-compatible REST API.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Engine;

use Dev3\AiAbandonedCart\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * POST {base}/Accounts/{sid}/Messages.json with Basic auth (Twilio, SignalWire and other compatible gateways).
 */
class Sms {

	/**
	 * Whether SMS is configured and on.
	 *
	 * @return bool
	 */
	public static function ready() {
		return (bool) Settings::get( 'sms_enabled' )
			&& '' !== (string) Settings::get( 'sms_account_sid' )
			&& '' !== Settings::sms_token()
			&& ( '' !== (string) Settings::get( 'sms_from' ) || '' !== (string) Settings::get( 'sms_messaging_service' ) );
	}

	/**
	 * Normalises a phone number to E.164 using the billing country's calling code.
	 *
	 * @param string $phone   Raw phone.
	 * @param string $country ISO country.
	 * @return string '' when it cannot be normalised.
	 */
	public static function normalize( $phone, $country = '' ) {
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return '';
		}
		$plus   = 0 === strpos( $phone, '+' ) || 0 === strpos( $phone, '00' );
		$digits = preg_replace( '/\D/', '', $phone );
		if ( 0 === strpos( $phone, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( ! $plus ) {
			$code = '';
			if ( $country && function_exists( 'WC' ) && WC()->countries ) {
				$code = (string) WC()->countries->get_country_calling_code( strtoupper( $country ) );
			}
			$code = preg_replace( '/\D/', '', is_array( $code ) ? (string) reset( $code ) : $code );
			if ( '' === $code ) {
				return '';
			}
			$digits = $code . ltrim( $digits, '0' );
		}
		return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) ? '+' . $digits : '';
	}

	/**
	 * Sends one SMS.
	 *
	 * @param string $to   E.164 number.
	 * @param string $body Text.
	 * @return string|\WP_Error Provider message id.
	 */
	public static function send( $to, $body ) {
		if ( ! self::ready() ) {
			return new \WP_Error( 'dev3ac_sms_off', __( 'SMS is not configured. Add your provider details under Settings → SMS.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		}
		if ( ! preg_match( '/^\+\d{8,15}$/', (string) $to ) ) {
			return new \WP_Error( 'dev3ac_sms_bad_number', __( 'The phone number is not a valid international number.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		}
		$sid     = (string) Settings::get( 'sms_account_sid' );
		$service = (string) Settings::get( 'sms_messaging_service' );
		$fields  = array(
			'To'   => $to,
			'Body' => mb_substr( (string) $body, 0, 1600 ),
		);
		if ( '' !== $service ) {
			$fields['MessagingServiceSid'] = $service;
		} else {
			$fields['From'] = (string) Settings::get( 'sms_from' );
		}
		$request = array(
			'url'  => trailingslashit( (string) Settings::get( 'sms_base_url' ) ) . 'Accounts/' . rawurlencode( $sid ) . '/Messages.json',
			'args' => array(
				'timeout' => 20,
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
					'Authorization' => 'Basic ' . base64_encode( $sid . ':' . Settings::sms_token() ),
				),
				'body'    => $fields,
			),
		);
		/**
		 * Filters the SMS HTTP request (URL + wp_remote_post args).
		 *
		 * @param array  $request { url, args }.
		 * @param string $to      Recipient.
		 */
		$request  = apply_filters( 'dev3ac_sms_request', $request, $to );
		$response = wp_remote_post( $request['url'], $request['args'] );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'dev3ac_sms_http', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : sprintf( 'HTTP %d', $code );
			/* translators: %s: provider error */
			return new \WP_Error( 'dev3ac_sms_failed', sprintf( __( 'The SMS provider refused the message: %s', 'dev3-ai-abandoned-cart-for-woocommerce' ), mb_substr( $msg, 0, 200 ) ) );
		}
		return is_array( $data ) && ! empty( $data['sid'] ) ? sanitize_text_field( (string) $data['sid'] ) : 'sent';
	}
}
