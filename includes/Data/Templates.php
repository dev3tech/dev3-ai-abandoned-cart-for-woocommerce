<?php
/**
 * Email and SMS templates (stored in one option; a store has a handful of them).
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Template store. Only approved templates send.
 */
class Templates {

	const OPTION = 'dev3ac_templates';

	/**
	 * Memo.
	 *
	 * @var array|null
	 */
	private static $memo = null;

	/**
	 * All templates keyed by id.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$memo ) {
			$stored     = get_option( self::OPTION, array() );
			self::$memo = array();
			foreach ( is_array( $stored ) ? $stored : array() as $tpl ) {
				if ( is_array( $tpl ) && ! empty( $tpl['id'] ) ) {
					self::$memo[ $tpl['id'] ] = self::normalize( $tpl );
				}
			}
		}
		return self::$memo;
	}

	/**
	 * One template.
	 *
	 * @param string $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Replaces every template.
	 *
	 * @param array $templates Templates.
	 */
	public static function save_all( array $templates ) {
		$clean = array();
		foreach ( $templates as $tpl ) {
			$tpl                 = self::sanitize( (array) $tpl );
			$clean[ $tpl['id'] ] = $tpl;
		}
		update_option( self::OPTION, array_values( $clean ), false );
		self::$memo = null;
	}

	/**
	 * Creates or updates one template. Editing the copy of an approved template sets it back to draft
	 * unless the caller approves it in the same save.
	 *
	 * @param array $input Raw template.
	 * @return array Saved template.
	 */
	public static function upsert( array $input ) {
		$all      = self::all();
		$id       = isset( $input['id'] ) ? sanitize_key( (string) $input['id'] ) : '';
		$existing = ( $id && isset( $all[ $id ] ) ) ? $all[ $id ] : null;
		$tpl      = self::sanitize( array_merge( $existing ? $existing : array(), $input ) );
		if ( ! $existing ) {
			$tpl['id'] = 'tpl_' . strtolower( wp_generate_password( 8, false ) );
		}
		$copy_changed = $existing && self::copy_hash( $existing ) !== self::copy_hash( $tpl );
		$approve      = ! empty( $input['approve'] );
		if ( $approve ) {
			$tpl['status']      = 'approved';
			$tpl['approved_at'] = gmdate( 'Y-m-d H:i:s' );
			$tpl['approved_by'] = get_current_user_id();
		} elseif ( ! $existing || $copy_changed ) {
			// New or edited copy always needs a fresh approval.
			$tpl['status'] = 'draft';
		} else {
			// Unchanged copy keeps its status unless the caller explicitly moves it back to draft.
			$tpl['status'] = ( isset( $input['status'] ) && 'draft' === $input['status'] ) ? 'draft' : $existing['status'];
		}
		$tpl['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$all[ $tpl['id'] ] = $tpl;
		self::save_all( $all );
		return self::get( $tpl['id'] );
	}

	/**
	 * Deletes a template.
	 *
	 * @param string $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		unset( $all[ $id ] );
		self::save_all( $all );
		return true;
	}

	/**
	 * Hash of the customer-visible copy.
	 *
	 * @param array $tpl Template.
	 * @return string
	 */
	public static function copy_hash( array $tpl ) {
		return md5( implode( '|', array( $tpl['subject'], $tpl['preheader'], $tpl['heading'], $tpl['body'], $tpl['button'], $tpl['sms'] ) ) );
	}

	/**
	 * Fills missing keys.
	 *
	 * @param array $tpl Template.
	 * @return array
	 */
	public static function normalize( array $tpl ) {
		return array_merge(
			array(
				'id'             => '',
				'name'           => '',
				'channel'        => 'email',
				'subject'        => '',
				'preheader'      => '',
				'heading'        => '',
				'body'           => '',
				'button'         => '',
				'sms'            => '',
				'show_cart'      => true,
				'ai_personalise' => false,
				'auto_approve'   => false,
				'status'         => 'draft',
				'approved_at'    => '',
				'approved_by'    => 0,
				'updated_at'     => '',
			),
			$tpl
		);
	}

	/**
	 * Sanitizes a template.
	 *
	 * @param array $tpl Raw.
	 * @return array
	 */
	public static function sanitize( array $tpl ) {
		$tpl = self::normalize( $tpl );
		return array(
			'id'             => sanitize_key( (string) $tpl['id'] ),
			'name'           => mb_substr( sanitize_text_field( (string) $tpl['name'] ), 0, 80 ),
			'channel'        => 'sms' === $tpl['channel'] ? 'sms' : 'email',
			'subject'        => mb_substr( sanitize_text_field( (string) $tpl['subject'] ), 0, 150 ),
			'preheader'      => mb_substr( sanitize_text_field( (string) $tpl['preheader'] ), 0, 200 ),
			'heading'        => mb_substr( sanitize_text_field( (string) $tpl['heading'] ), 0, 120 ),
			'body'           => mb_substr( wp_kses_post( (string) $tpl['body'] ), 0, 6000 ),
			'button'         => mb_substr( sanitize_text_field( (string) $tpl['button'] ), 0, 60 ),
			'sms'            => mb_substr( sanitize_textarea_field( (string) $tpl['sms'] ), 0, 480 ),
			'show_cart'      => (bool) $tpl['show_cart'],
			'ai_personalise' => (bool) $tpl['ai_personalise'],
			'auto_approve'   => (bool) $tpl['auto_approve'],
			'status'         => 'approved' === $tpl['status'] ? 'approved' : 'draft',
			'approved_at'    => sanitize_text_field( (string) $tpl['approved_at'] ),
			'approved_by'    => (int) $tpl['approved_by'],
			'updated_at'     => sanitize_text_field( (string) $tpl['updated_at'] ),
		);
	}

	/**
	 * Clears the memo (tests).
	 */
	public static function flush() {
		self::$memo = null;
	}

	/**
	 * First-run templates (approved, written by DEV3).
	 *
	 * @return array
	 */
	public static function defaults() {
		$now = gmdate( 'Y-m-d H:i:s' );
		$out = array(
			array(
				'id'             => 'tpl_reminder',
				'name'           => __( 'Cart · Friendly reminder', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'subject'        => __( '{first_name}, you left something in your cart', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'preheader'      => __( 'Your {top_product} is still waiting for you.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'heading'        => __( 'Still thinking it over?', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'body'           => __( "Hi {first_name},\n\nWe saved your cart at {store_name} so you can pick up right where you left off. Your items are below.", 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'button'         => __( 'Return to my cart', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'ai_personalise' => true,
			),
			array(
				'id'             => 'tpl_offer',
				'name'           => __( 'Cart · Coupon offer', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'subject'        => __( 'A little something to help you decide: {coupon_amount} off', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'preheader'      => __( 'Use {coupon_code} before {coupon_expiry}.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'heading'        => __( 'Here\'s {coupon_amount} off your cart', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'body'           => __( "Hi {first_name},\n\nYour cart is still saved. Complete your order with the code below and we'll take {coupon_amount} off. It's yours until {coupon_expiry}.", 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'button'         => __( 'Claim my discount', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'ai_personalise' => true,
			),
			array(
				'id'        => 'tpl_last',
				'name'      => __( 'Cart · Last chance', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'subject'   => __( 'Last chance: your cart expires soon', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'preheader' => __( 'We can only hold your items a little longer.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'heading'   => __( 'Your cart is about to expire', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'body'      => __( "Hi {first_name},\n\nThis is our last reminder. Your cart with {item_count} item(s) ({cart_total}) will be cleared soon. If you still want it, it's one click away.", 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'button'    => __( 'Complete my order', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			),
			array(
				'id'      => 'tpl_sms',
				'name'    => __( 'Cart · SMS reminder', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'channel' => 'sms',
				'sms'     => __( '{store_name}: Hi {first_name}, your cart is saved. Finish your order here: {recovery_url} Reply STOP to opt out.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			),
			array(
				'id'             => 'tpl_winback',
				'name'           => __( 'Win-back · We miss you', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'subject'        => __( 'We miss you, {first_name}! Here\'s {coupon_amount} off', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'preheader'      => __( 'New arrivals and a welcome-back gift inside.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'heading'        => __( 'It\'s been a while', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'body'           => __( "Hi {first_name},\n\nIt's been a while since your last order at {store_name}. Come back and save {coupon_amount} with code {coupon_code}. Your favourites are just a click away.", 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'button'         => __( 'Shop again', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'ai_personalise' => true,
			),
			array(
				'id'        => 'tpl_winback_last',
				'name'      => __( 'Win-back · Final offer', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'subject'   => __( 'Your welcome-back offer ends soon', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'preheader' => __( '{coupon_code} expires {coupon_expiry}.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'heading'   => __( 'One last reminder', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'body'      => __( "Hi {first_name},\n\nJust a quick note: your {coupon_amount} welcome-back code {coupon_code} expires on {coupon_expiry}.", 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'button'    => __( 'Use my code', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			),
		);
		foreach ( $out as &$tpl ) {
			$tpl['status']      = 'approved';
			$tpl['approved_at'] = $now;
			$tpl['updated_at']  = $now;
		}
		unset( $tpl );
		return $out;
	}
}
