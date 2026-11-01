<?php
/**
 * Recovery and win-back sequences (option-backed).
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Sequence store. A sequence is { id, name, type (cart|winback), enabled, steps[] }.
 * A step is { id, delay_value, delay_unit, channel, template_id, min_total, coupon{ enabled, type, amount, expiry_days, min_spend } }.
 * Delays count from abandonment (step 1) or from the previous step's send.
 */
class Sequences {

	const OPTION = 'dev3ac_sequences';

	/**
	 * Memo.
	 *
	 * @var array|null
	 */
	private static $memo = null;

	/**
	 * All sequences keyed by id.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$memo ) {
			$stored     = get_option( self::OPTION, array() );
			self::$memo = array();
			foreach ( is_array( $stored ) ? $stored : array() as $seq ) {
				if ( is_array( $seq ) && ! empty( $seq['id'] ) ) {
					self::$memo[ $seq['id'] ] = self::sanitize( $seq );
				}
			}
		}
		return self::$memo;
	}

	/**
	 * One sequence.
	 *
	 * @param string $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * The first enabled sequence of a type.
	 *
	 * @param string $type cart|winback.
	 * @return array|null
	 */
	public static function active( $type ) {
		foreach ( self::all() as $seq ) {
			if ( $seq['type'] === $type && $seq['enabled'] && $seq['steps'] ) {
				return $seq;
			}
		}
		return null;
	}

	/**
	 * Saves all sequences.
	 *
	 * @param array $sequences Sequences.
	 * @return array Saved, keyed by id.
	 */
	public static function save_all( array $sequences ) {
		$clean   = array();
		$enabled = array();
		foreach ( $sequences as $seq ) {
			$seq = self::sanitize( (array) $seq );
			if ( '' === $seq['id'] ) {
				$seq['id'] = 'seq_' . strtolower( wp_generate_password( 8, false ) );
			}
			// Only one enabled sequence per type: the first one wins.
			if ( $seq['enabled'] && isset( $enabled[ $seq['type'] ] ) ) {
				$seq['enabled'] = false;
			}
			if ( $seq['enabled'] ) {
				$enabled[ $seq['type'] ] = true;
			}
			$clean[ $seq['id'] ] = $seq;
		}
		update_option( self::OPTION, array_values( $clean ), false );
		self::$memo = null;
		return self::all();
	}

	/**
	 * Sanitizes a sequence.
	 *
	 * @param array $seq Raw.
	 * @return array
	 */
	public static function sanitize( array $seq ) {
		$steps = array();
		foreach ( isset( $seq['steps'] ) && is_array( $seq['steps'] ) ? array_slice( $seq['steps'], 0, 10 ) : array() as $step ) {
			$steps[] = self::sanitize_step( (array) $step );
		}
		return array(
			'id'      => isset( $seq['id'] ) ? sanitize_key( (string) $seq['id'] ) : '',
			'name'    => isset( $seq['name'] ) ? mb_substr( sanitize_text_field( (string) $seq['name'] ), 0, 80 ) : '',
			'type'    => isset( $seq['type'] ) && 'winback' === $seq['type'] ? 'winback' : 'cart',
			'enabled' => ! empty( $seq['enabled'] ),
			'steps'   => $steps,
		);
	}

	/**
	 * Sanitizes a step.
	 *
	 * @param array $step Raw.
	 * @return array
	 */
	public static function sanitize_step( array $step ) {
		$coupon = isset( $step['coupon'] ) && is_array( $step['coupon'] ) ? $step['coupon'] : array();
		$unit   = isset( $step['delay_unit'] ) && in_array( $step['delay_unit'], array( 'minutes', 'hours', 'days' ), true ) ? $step['delay_unit'] : 'hours';
		$type   = isset( $coupon['type'] ) && in_array( $coupon['type'], array( 'percent', 'fixed_cart', 'free_shipping' ), true ) ? $coupon['type'] : 'percent';
		$amount = isset( $coupon['amount'] ) ? (float) $coupon['amount'] : 10;
		$amount = 'percent' === $type ? min( 100, max( 0, $amount ) ) : max( 0, $amount );
		return array(
			'id'          => ! empty( $step['id'] ) ? sanitize_key( (string) $step['id'] ) : 'st_' . strtolower( wp_generate_password( 6, false ) ),
			'delay_value' => isset( $step['delay_value'] ) ? min( 1000, max( 0, (int) $step['delay_value'] ) ) : 1,
			'delay_unit'  => $unit,
			'channel'     => isset( $step['channel'] ) && 'sms' === $step['channel'] ? 'sms' : 'email',
			'template_id' => isset( $step['template_id'] ) ? sanitize_key( (string) $step['template_id'] ) : '',
			'min_total'   => isset( $step['min_total'] ) ? round( max( 0, (float) $step['min_total'] ), 2 ) : 0,
			'coupon'      => array(
				'enabled'     => ! empty( $coupon['enabled'] ),
				'type'        => $type,
				'amount'      => round( $amount, 2 ),
				'expiry_days' => isset( $coupon['expiry_days'] ) ? min( 365, max( 1, (int) $coupon['expiry_days'] ) ) : 3,
				'min_spend'   => isset( $coupon['min_spend'] ) ? round( max( 0, (float) $coupon['min_spend'] ), 2 ) : 0,
			),
		);
	}

	/**
	 * Step delay in seconds.
	 *
	 * @param array $step Step.
	 * @return int
	 */
	public static function delay_seconds( array $step ) {
		$mult = array(
			'minutes' => MINUTE_IN_SECONDS,
			'hours'   => HOUR_IN_SECONDS,
			'days'    => DAY_IN_SECONDS,
		);
		return (int) $step['delay_value'] * $mult[ $step['delay_unit'] ];
	}

	/**
	 * Clears the memo (tests).
	 */
	public static function flush() {
		self::$memo = null;
	}

	/**
	 * First-run sequences.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			array(
				'id'      => 'seq_cart',
				'name'    => __( 'Cart recovery', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'type'    => 'cart',
				'enabled' => true,
				'steps'   => array(
					array(
						'id'          => 'st_cart_1',
						'delay_value' => 1,
						'delay_unit'  => 'hours',
						'template_id' => 'tpl_reminder',
					),
					array(
						'id'          => 'st_cart_2',
						'delay_value' => 23,
						'delay_unit'  => 'hours',
						'template_id' => 'tpl_offer',
						'coupon'      => array(
							'enabled'     => true,
							'type'        => 'percent',
							'amount'      => 10,
							'expiry_days' => 3,
						),
					),
					array(
						'id'          => 'st_cart_3',
						'delay_value' => 2,
						'delay_unit'  => 'days',
						'template_id' => 'tpl_last',
					),
				),
			),
			array(
				'id'      => 'seq_winback',
				'name'    => __( 'Win-back', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'type'    => 'winback',
				'enabled' => true,
				'steps'   => array(
					array(
						'id'          => 'st_wb_1',
						'delay_value' => 0,
						'delay_unit'  => 'hours',
						'template_id' => 'tpl_winback',
						'coupon'      => array(
							'enabled'     => true,
							'type'        => 'percent',
							'amount'      => 15,
							'expiry_days' => 14,
						),
					),
					array(
						'id'          => 'st_wb_2',
						'delay_value' => 10,
						'delay_unit'  => 'days',
						'template_id' => 'tpl_winback_last',
					),
				),
			),
		);
	}
}
