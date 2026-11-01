<?php
/**
 * Unique, single-use recovery coupons.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Engine;

use Dev3\AiAbandonedCart\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Creates one WC_Coupon per cart + step (reused when the same step is re-sent) and cleans up expired ones.
 */
class Coupons {

	/**
	 * Creates (or returns the existing) coupon for a cart step.
	 *
	 * @param array $cart       Cart.
	 * @param array $step       Step (with coupon config).
	 * @param int   $step_index Step index.
	 * @return array|null { code, amount_label, expiry_label, expires } or null when off.
	 */
	public static function for_step( array $cart, array $step, $step_index ) {
		if ( empty( $step['coupon']['enabled'] ) || ! class_exists( 'WC_Coupon' ) ) {
			return null;
		}
		$existing = self::find( (int) $cart['id'], (int) $step_index );
		if ( $existing ) {
			return self::describe( $existing );
		}
		$cfg  = $step['coupon'];
		$args = array(
			'code'          => self::unique_code(),
			'discount_type' => 'free_shipping' === $cfg['type'] ? 'fixed_cart' : $cfg['type'],
			'amount'        => 'free_shipping' === $cfg['type'] ? 0 : (float) $cfg['amount'],
			'free_shipping' => 'free_shipping' === $cfg['type'],
			'expires'       => time() + (int) $cfg['expiry_days'] * DAY_IN_SECONDS,
			'min_spend'     => (float) $cfg['min_spend'],
			'email'         => (string) $cart['email'],
		);
		/**
		 * Filters a recovery coupon before it is created.
		 *
		 * @param array $args       Coupon args.
		 * @param array $cart       Cart.
		 * @param array $step       Step.
		 */
		$args   = apply_filters( 'dev3ac_coupon_args', $args, $cart, $step );
		$coupon = new \WC_Coupon();
		$coupon->set_code( $args['code'] );
		$coupon->set_discount_type( $args['discount_type'] );
		$coupon->set_amount( $args['amount'] );
		$coupon->set_free_shipping( (bool) $args['free_shipping'] );
		$coupon->set_date_expires( (int) $args['expires'] );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_individual_use( true );
		if ( $args['min_spend'] > 0 ) {
			$coupon->set_minimum_amount( $args['min_spend'] );
		}
		if ( '' !== $args['email'] ) {
			$coupon->set_email_restrictions( array( $args['email'] ) );
		}
		/* translators: %d: cart id */
		$coupon->set_description( sprintf( __( 'DEV3 AI recovery coupon for cart #%d', 'dev3-ai-abandoned-cart-for-woocommerce' ), (int) $cart['id'] ) );
		$coupon->update_meta_data( '_dev3ac_cart_id', (int) $cart['id'] );
		$coupon->update_meta_data( '_dev3ac_step', (int) $step_index );
		$coupon->save();
		return self::describe( $coupon );
	}

	/**
	 * Existing coupon for a cart step.
	 *
	 * @param int $cart_id    Cart.
	 * @param int $step_index Step.
	 * @return \WC_Coupon|null
	 */
	public static function find( $cart_id, $step_index ) {
		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, indexed by meta_key.
				'meta_query'     => array(
					array(
						'key'   => '_dev3ac_cart_id',
						'value' => (int) $cart_id,
					),
					array(
						'key'   => '_dev3ac_step',
						'value' => (int) $step_index,
					),
				),
			)
		);
		return $ids ? new \WC_Coupon( (int) $ids[0] ) : null;
	}

	/**
	 * Latest still-valid coupon code of a cart (used by steps without their own coupon).
	 *
	 * @param array $cart Cart.
	 * @return array|null
	 */
	public static function latest_valid( array $cart ) {
		foreach ( array_reverse( (array) $cart['coupon_codes'] ) as $code ) {
			$id = wc_get_coupon_id_by_code( $code );
			if ( ! $id ) {
				continue;
			}
			$coupon  = new \WC_Coupon( $id );
			$expires = $coupon->get_date_expires();
			if ( ( ! $expires || $expires->getTimestamp() > time() ) && $coupon->get_usage_count() < max( 1, (int) $coupon->get_usage_limit() ) ) {
				return self::describe( $coupon );
			}
		}
		return null;
	}

	/**
	 * Merge-tag friendly description.
	 *
	 * @param \WC_Coupon $coupon Coupon.
	 * @return array
	 */
	public static function describe( \WC_Coupon $coupon ) {
		$expires = $coupon->get_date_expires();
		if ( $coupon->get_free_shipping() && (float) $coupon->get_amount() <= 0 ) {
			$label = __( 'free shipping', 'dev3-ai-abandoned-cart-for-woocommerce' );
		} elseif ( 'percent' === $coupon->get_discount_type() ) {
			$label = wc_format_decimal( $coupon->get_amount(), 0 ) . '%';
		} else {
			$label = wp_strip_all_tags( wc_price( $coupon->get_amount() ) );
		}
		return array(
			'code'         => strtoupper( $coupon->get_code() ),
			'amount_label' => html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ),
			'expiry_label' => $expires ? wc_format_datetime( $expires ) : '',
			'expires'      => $expires ? $expires->getTimestamp() : 0,
		);
	}

	/**
	 * Preview coupon for template previews (not saved).
	 *
	 * @param array $step Step or null.
	 * @return array
	 */
	public static function sample( $step = null ) {
		$cfg = $step && ! empty( $step['coupon'] ) ? $step['coupon'] : array(
			'type'        => 'percent',
			'amount'      => 10,
			'expiry_days' => 3,
		);
		if ( 'free_shipping' === $cfg['type'] ) {
			$label = __( 'free shipping', 'dev3-ai-abandoned-cart-for-woocommerce' );
		} elseif ( 'percent' === $cfg['type'] ) {
			$label = wc_format_decimal( $cfg['amount'], 0 ) . '%';
		} else {
			$label = html_entity_decode( wp_strip_all_tags( wc_price( $cfg['amount'] ) ), ENT_QUOTES, 'UTF-8' );
		}
		return array(
			'code'         => Settings::get( 'coupon_prefix' ) . '-PREVIEW',
			'amount_label' => $label,
			'expiry_label' => date_i18n( get_option( 'date_format' ), time() + (int) $cfg['expiry_days'] * DAY_IN_SECONDS ),
			'expires'      => time() + (int) $cfg['expiry_days'] * DAY_IN_SECONDS,
		);
	}

	/**
	 * Unused code.
	 *
	 * @return string
	 */
	private static function unique_code() {
		$prefix = (string) Settings::get( 'coupon_prefix' );
		do {
			$code = strtolower( $prefix . '-' . wp_generate_password( 6, false, false ) );
		} while ( wc_get_coupon_id_by_code( $code ) );
		return $code;
	}

	/**
	 * Deletes unused recovery coupons that expired (daily).
	 *
	 * @return int Deleted.
	 */
	public static function cleanup() {
		if ( ! Settings::get( 'coupon_cleanup' ) || ! class_exists( 'WC_Coupon' ) ) {
			return 0;
		}
		$ids     = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily cleanup.
				'meta_query'     => array(
					array(
						'key'     => '_dev3ac_cart_id',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'date_expires',
						'value'   => time(),
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$deleted = 0;
		foreach ( $ids as $id ) {
			$coupon = new \WC_Coupon( $id );
			if ( 0 === (int) $coupon->get_usage_count() ) {
				wp_delete_post( $id, true );
				++$deleted;
			}
		}
		return $deleted;
	}
}
