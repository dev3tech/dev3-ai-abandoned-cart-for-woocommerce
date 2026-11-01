<?php
/**
 * Email delivery through the WooCommerce mailer.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Sends HTML email with List-Unsubscribe headers and optional custom sender.
 */
class Mailer {

	/**
	 * Last wp_mail error message.
	 *
	 * @var string
	 */
	private static $error = '';

	/**
	 * Sends an email.
	 *
	 * @param string $to          Recipient.
	 * @param string $subject     Subject.
	 * @param string $html        HTML.
	 * @param string $unsubscribe Unsubscribe URL ('' = none).
	 * @return true|\WP_Error
	 */
	public static function send( $to, $subject, $html, $unsubscribe = '' ) {
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'dev3ac_bad_email', __( 'The recipient email address is not valid.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $unsubscribe ) {
			$headers[] = 'List-Unsubscribe: <' . esc_url_raw( $unsubscribe ) . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}
		$sender = Renderer::sender();
		$name   = static function ( $value ) use ( $sender ) {
			return '' !== $sender['name'] ? $sender['name'] : $value;
		};
		$email  = static function ( $value ) use ( $sender ) {
			return '' !== $sender['email'] ? $sender['email'] : $value;
		};
		$args   = array(
			'to'      => $to,
			'subject' => $subject,
			'html'    => $html,
			'headers' => $headers,
		);
		/**
		 * Filters an outgoing email before it is sent.
		 *
		 * @param array $args { to, subject, html, headers }.
		 */
		$args        = apply_filters( 'dev3ac_email_args', $args );
		self::$error = '';
		$catch       = static function ( $error ) {
			self::$error = $error instanceof \WP_Error ? $error->get_error_message() : '';
		};
		add_filter( 'woocommerce_email_from_name', $name, 99 );
		add_filter( 'woocommerce_email_from_address', $email, 99 );
		add_filter( 'wp_mail_from_name', $name, 99 );
		add_filter( 'wp_mail_from', $email, 99 );
		add_action( 'wp_mail_failed', $catch );
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$ok = WC()->mailer()->send( $args['to'], $args['subject'], $args['html'], implode( "\r\n", $args['headers'] ) . "\r\n" );
		} else {
			$ok = wp_mail( $args['to'], $args['subject'], $args['html'], $args['headers'] );
		}
		remove_filter( 'woocommerce_email_from_name', $name, 99 );
		remove_filter( 'woocommerce_email_from_address', $email, 99 );
		remove_filter( 'wp_mail_from_name', $name, 99 );
		remove_filter( 'wp_mail_from', $email, 99 );
		remove_action( 'wp_mail_failed', $catch );
		if ( ! $ok ) {
			return new \WP_Error( 'dev3ac_mail_failed', '' !== self::$error ? self::$error : __( 'WordPress could not send the email. Check your mail/SMTP setup.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		}
		return true;
	}
}
