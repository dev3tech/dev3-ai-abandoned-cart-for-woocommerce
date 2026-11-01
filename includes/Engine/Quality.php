<?php
/**
 * Rule-based copy checks (no AI).
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Flags common spam triggers and missing essentials in a template or copy.
 */
class Quality {

	const SPAM_WORDS = array( 'free money', 'act now', '100% free', 'risk-free', 'winner', 'guaranteed', 'cash bonus', 'urgent', 'click here', 'buy now!!!', 'limited time only', 'no obligation', '$$$', 'congratulations' );

	/**
	 * Checks a template.
	 *
	 * @param array $tpl Template (or copy) fields.
	 * @return array List of { level: error|warning, code, message }.
	 */
	public static function check( array $tpl ) {
		$out = array();
		if ( 'sms' === ( isset( $tpl['channel'] ) ? $tpl['channel'] : 'email' ) ) {
			$sms = (string) ( isset( $tpl['sms'] ) ? $tpl['sms'] : '' );
			if ( '' === trim( $sms ) ) {
				$out[] = self::issue( 'error', 'sms_empty', __( 'The SMS text is empty.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
			}
			if ( false === strpos( $sms, '{recovery_url}' ) ) {
				$out[] = self::issue( 'warning', 'sms_link', __( 'Add {recovery_url} so the shopper can return to the cart (it is appended automatically otherwise).', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
			}
			if ( mb_strlen( $sms ) > 160 ) {
				/* translators: %d: number of SMS segments */
				$out[] = self::issue( 'warning', 'sms_long', sprintf( __( 'Longer than 160 characters: it will be sent as about %d SMS segments (links count as about 90 characters).', 'dev3-ai-abandoned-cart-for-woocommerce' ), (int) ceil( ( mb_strlen( $sms ) + 70 ) / 153 ) ) );
			}
			$out = array_merge( $out, self::spam( $sms ) );
			return $out;
		}
		$subject = (string) ( isset( $tpl['subject'] ) ? $tpl['subject'] : '' );
		$body    = wp_strip_all_tags( (string) ( isset( $tpl['body'] ) ? $tpl['body'] : '' ) );
		if ( '' === trim( $subject ) ) {
			$out[] = self::issue( 'error', 'subject_empty', __( 'The subject line is empty.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		} elseif ( mb_strlen( $subject ) > 70 ) {
			$out[] = self::issue( 'warning', 'subject_long', __( 'Subject lines over 70 characters get cut off on phones.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		}
		if ( '' === trim( $body ) ) {
			$out[] = self::issue( 'error', 'body_empty', __( 'The email body is empty.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		}
		if ( '' === trim( (string) ( isset( $tpl['preheader'] ) ? $tpl['preheader'] : '' ) ) ) {
			$out[] = self::issue( 'warning', 'preheader', __( 'Add a preheader: it is the preview line inboxes show after the subject.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		}
		$letters = preg_replace( '/[^A-Za-z]/', '', $subject );
		if ( strlen( $letters ) >= 8 && strlen( preg_replace( '/[^A-Z]/', '', $letters ) ) / strlen( $letters ) > 0.6 ) {
			$out[] = self::issue( 'warning', 'caps', __( 'The subject is mostly capital letters, which spam filters dislike.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		}
		if ( preg_match( '/!{2,}|\?{3,}/', $subject . ' ' . $body ) ) {
			$out[] = self::issue( 'warning', 'punctuation', __( 'Repeated "!!" or "???" looks spammy.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		}
		$out = array_merge( $out, self::spam( $subject . ' ' . $body ) );
		if ( preg_match( '/\{coupon_(code|amount|expiry)\}/', $subject . ' ' . $body . ' ' . ( isset( $tpl['preheader'] ) ? $tpl['preheader'] : '' ) ) && empty( $tpl['_has_coupon'] ) ) {
			$out[] = self::issue( 'warning', 'coupon_tag', __( 'Uses a coupon tag: make sure the sequence step that sends it has a coupon (or an earlier step does).', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		}
		return $out;
	}

	/**
	 * Spam-word scan.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	private static function spam( $text ) {
		$found = array();
		$lower = strtolower( $text );
		foreach ( self::SPAM_WORDS as $word ) {
			if ( false !== strpos( $lower, $word ) ) {
				$found[] = $word;
			}
		}
		if ( ! $found ) {
			return array();
		}
		/* translators: %s: list of words */
		return array( self::issue( 'warning', 'spam_words', sprintf( __( 'Spam-trigger phrases: %s', 'dev3-ai-abandoned-cart-for-woocommerce' ), implode( ', ', $found ) ) ) );
	}

	/**
	 * Issue row.
	 *
	 * @param string $level   Level.
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	private static function issue( $level, $code, $message ) {
		return array(
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
		);
	}
}
