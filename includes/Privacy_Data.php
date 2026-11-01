<?php
/**
 * GDPR exporter/eraser for captured carts and messages, retention cleanup and policy text.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart;

use Dev3\AiAbandonedCart\Core3\Core\Privacy;
use Dev3\AiAbandonedCart\Data\Carts;
use Dev3\AiAbandonedCart\Data\Messages;
use Dev3\AiAbandonedCart\Engine\Coupons;

defined( 'ABSPATH' ) || exit;

/**
 * Personal data tools (Tools → Export/Erase Personal Data).
 */
class Privacy_Data {

	/**
	 * Hooks.
	 */
	public static function register() {
		Privacy::add_exporter( __( 'Abandoned carts (DEV3 AI)', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( __CLASS__, 'export' ) );
		Privacy::add_eraser( __( 'Abandoned carts (DEV3 AI)', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( __CLASS__, 'erase' ) );
		add_filter( 'dev3ac_privacy_policy_text', array( __CLASS__, 'policy_text' ) );
	}

	/**
	 * Exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		unset( $page );
		$data = array();
		foreach ( Carts::for_email( $email ) as $cart ) {
			$items  = implode( ', ', array_map( static fn( $i ) => (int) $i['quantity'] . '× ' . $i['name'], (array) $cart['items'] ) );
			$data[] = array(
				'group_id'    => 'dev3ac-carts',
				'group_label' => __( 'Abandoned carts', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'item_id'     => 'dev3ac-cart-' . $cart['id'],
				'data'        => array(
					array(
						'name'  => __( 'Email', 'dev3-ai-abandoned-cart-for-woocommerce' ),
						'value' => $cart['email'],
					),
					array(
						'name'  => __( 'Phone', 'dev3-ai-abandoned-cart-for-woocommerce' ),
						'value' => $cart['phone'],
					),
					array(
						'name'  => __( 'Name', 'dev3-ai-abandoned-cart-for-woocommerce' ),
						'value' => trim( $cart['first_name'] . ' ' . $cart['last_name'] ),
					),
					array(
						'name'  => __( 'Items', 'dev3-ai-abandoned-cart-for-woocommerce' ),
						'value' => $items,
					),
					array(
						'name'  => __( 'Status', 'dev3-ai-abandoned-cart-for-woocommerce' ),
						'value' => $cart['status'],
					),
					array(
						'name'  => __( 'Consent', 'dev3-ai-abandoned-cart-for-woocommerce' ),
						'value' => $cart['consent'] . ( $cart['sms_consent'] ? ' + SMS' : '' ) . ' ' . (string) $cart['consent_at'],
					),
					array(
						'name'  => __( 'Captured', 'dev3-ai-abandoned-cart-for-woocommerce' ),
						'value' => $cart['created_at'],
					),
				),
			);
			foreach ( Messages::for_cart( $cart['id'] ) as $msg ) {
				if ( 'sent' !== $msg['status'] ) {
					continue;
				}
				$data[] = array(
					'group_id'    => 'dev3ac-messages',
					'group_label' => __( 'Cart reminder messages', 'dev3-ai-abandoned-cart-for-woocommerce' ),
					'item_id'     => 'dev3ac-message-' . $msg['id'],
					'data'        => array(
						array(
							'name'  => __( 'Channel', 'dev3-ai-abandoned-cart-for-woocommerce' ),
							'value' => $msg['channel'],
						),
						array(
							'name'  => __( 'Subject', 'dev3-ai-abandoned-cart-for-woocommerce' ),
							'value' => $msg['subject'],
						),
						array(
							'name'  => __( 'Sent', 'dev3-ai-abandoned-cart-for-woocommerce' ),
							'value' => (string) $msg['sent_at'],
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Eraser: deletes carts and messages for the email.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		$removed = false;
		foreach ( Carts::for_email( $email ) as $cart ) {
			Carts::delete( $cart['id'] );
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Daily cleanup: retention + expired coupons.
	 */
	public static function daily() {
		Carts::prune( Carts::now( time() - (int) Settings::get( 'retention_days' ) * DAY_IN_SECONDS ) );
		Coupons::cleanup();
	}

	/**
	 * Adds the abandoned-cart paragraph to the suggested privacy policy.
	 *
	 * @param string $text Core text.
	 * @return string
	 */
	public static function policy_text( $text ) {
		return $text . '<p>' . esc_html__( 'When you enter your email address or phone number at checkout, we store it with the contents of your cart so we can send you a reminder if you do not complete your order. Reminders may include a personalised message written with Google Gemini, based on your first name and the products in your cart (we do not send your email address or phone number to Google). Every message has an unsubscribe link. We delete this data after the retention period set by the store.', 'dev3-ai-abandoned-cart-for-woocommerce' ) . '</p>';
	}
}
