<?php
/**
 * The sequence engine: abandonment, scheduling, AI copy, sending, win-back enrolment.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Engine;

use Dev3\AiAbandonedCart\Ai\Copywriter;
use Dev3\AiAbandonedCart\Data\Carts;
use Dev3\AiAbandonedCart\Data\Messages;
use Dev3\AiAbandonedCart\Data\Sequences;
use Dev3\AiAbandonedCart\Data\Suppressions;
use Dev3\AiAbandonedCart\Data\Templates;
use Dev3\AiAbandonedCart\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs every 5 minutes from WP-Cron (and on demand from the admin).
 *
 * Cart step_index = how many steps were already sent/skipped. Messages carry the index of their step.
 * Unreviewed AI copy never sends: a message in `review` sends the approved template copy when its time is up
 * (or waits up to review_hold_hours when review_fallback = hold).
 */
class Scheduler {

	const CRON_HOOK = 'dev3ac_sweep';
	const LOCK      = 'dev3ac_sweep_lock';

	/**
	 * Time override for tests.
	 *
	 * @var int|null
	 */
	public static $now = null;

	/**
	 * Current timestamp.
	 *
	 * @return int
	 */
	public static function time() {
		return null === self::$now ? time() : (int) self::$now;
	}

	/**
	 * Runs one sweep.
	 *
	 * @param bool $force Ignore the lock (admin "Run now").
	 * @return array Counters.
	 */
	public static function sweep( $force = false ) {
		$stats = array(
			'abandoned' => 0,
			'generated' => 0,
			'sent'      => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'lost'      => 0,
			'winback'   => 0,
			'locked'    => false,
		);
		if ( ! self::lock( $force ) ) {
			$stats['locked'] = true;
			return $stats;
		}
		try {
			$now                = self::time();
			$stats['abandoned'] = self::detect_abandoned( $now );
			if ( Settings::get( 'winback_enabled' ) && ( $force || (int) get_option( 'dev3ac_winback_last', 0 ) < $now - HOUR_IN_SECONDS ) ) {
				update_option( 'dev3ac_winback_last', $now, false );
				$stats['winback'] = self::enrol_winback( $now );
			}
			$stats['generated'] = self::prepare_ai( (int) Settings::get( 'ai_batch_size' ) );
			if ( Settings::get( 'sending_enabled' ) ) {
				foreach ( Messages::due( Carts::now( $now ), (int) apply_filters( 'dev3ac_sweep_batch', (int) Settings::get( 'batch_size' ) ) ) as $msg ) {
					$result = self::send_message( $msg );
					if ( isset( $stats[ $result ] ) ) {
						++$stats[ $result ];
					}
				}
			}
			$stats['lost'] = self::mark_lost( $now );
		} finally {
			delete_option( self::LOCK );
		}
		update_option( 'dev3ac_last_sweep', self::time(), false );
		return $stats;
	}

	/**
	 * Atomic lock (stale after 5 minutes).
	 *
	 * @param bool $force Force.
	 * @return bool
	 */
	private static function lock( $force ) {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( self::LOCK, 0 );
		if ( $force || $since < time() - 5 * MINUTE_IN_SECONDS ) {
			update_option( self::LOCK, time(), false );
			return true;
		}
		return false;
	}

	/**
	 * Active carts idle past the cut-off become abandoned.
	 *
	 * @param int $now Now.
	 * @return int
	 */
	public static function detect_abandoned( $now ) {
		$cutoff = (int) Settings::get( 'cutoff_minutes' ) * MINUTE_IN_SECONDS;
		$min    = (float) Settings::get( 'min_cart_total' );
		$count  = 0;
		foreach ( Carts::idle( Carts::now( $now - $cutoff ), 100 ) as $cart ) {
			if ( $min > 0 && (float) $cart['total'] < $min ) {
				continue;
			}
			self::abandon( $cart, $now );
			++$count;
		}
		return $count;
	}

	/**
	 * Marks one cart abandoned and schedules step 1.
	 *
	 * @param array $cart Cart.
	 * @param int   $now  Now.
	 * @return array Updated cart.
	 */
	public static function abandon( array $cart, $now ) {
		$seq = Sequences::active( 'cart' );
		Carts::update(
			$cart['id'],
			array(
				'status'       => 'abandoned',
				'abandoned_at' => Carts::now( $now ),
				'sequence_id'  => $seq ? $seq['id'] : '',
				'step_index'   => 0,
			)
		);
		$cart = Carts::get( $cart['id'] );
		/**
		 * Fires when a cart is marked abandoned.
		 *
		 * @param array $cart Cart.
		 */
		do_action( 'dev3ac_cart_abandoned', $cart );
		if ( $seq ) {
			// Step 1's delay counts from the shopper's last activity.
			self::schedule_next( $cart, strtotime( $cart['updated_at'] . ' UTC' ) );
		}
		return Carts::get( $cart['id'] );
	}

	/**
	 * Schedules the cart's next step (skipping steps that cannot be sent).
	 *
	 * @param array $cart    Cart.
	 * @param int   $base_ts Time the delay counts from.
	 * @return int Message id, or 0 when the sequence is finished.
	 */
	public static function schedule_next( array $cart, $base_ts ) {
		$seq = Sequences::get( $cart['sequence_id'] );
		if ( ! $seq ) {
			return 0;
		}
		$index = (int) $cart['step_index'];
		$now   = self::time();
		while ( isset( $seq['steps'][ $index ] ) ) {
			$step   = $seq['steps'][ $index ];
			$tpl    = Templates::get( $step['template_id'] );
			$reason = self::cannot_send( $cart, $step, $tpl );
			if ( '' !== $reason ) {
				Messages::insert(
					array(
						'cart_id'      => $cart['id'],
						'sequence_id'  => $seq['id'],
						'step_index'   => $index,
						'channel'      => $step['channel'],
						'template_id'  => $step['template_id'],
						'status'       => 'skipped',
						'recipient'    => 'sms' === $step['channel'] ? (string) $cart['phone'] : (string) $cart['email'],
						'error'        => $reason,
						'scheduled_at' => Carts::now( $now ),
					)
				);
				++$index;
				continue;
			}
			$earliest = max( $now, (int) $base_ts + Sequences::delay_seconds( $step ) );
			$when     = Send_Time::best( $earliest, $cart, $step, $index );
			$ai       = $tpl['ai_personalise'] && Copywriter::available( 'cron' );
			$id       = Messages::insert(
				array(
					'cart_id'      => $cart['id'],
					'sequence_id'  => $seq['id'],
					'step_index'   => $index,
					'channel'      => $step['channel'],
					'template_id'  => $step['template_id'],
					'status'       => 'scheduled',
					'ai_state'     => $ai ? 'pending' : '',
					'recipient'    => 'sms' === $step['channel'] ? Sms::normalize( $cart['phone'], $cart['country'] ) : (string) $cart['email'],
					'scheduled_at' => Carts::now( $when ),
				)
			);
			Carts::update(
				$cart['id'],
				array(
					'step_index'   => $index,
					'next_send_at' => Carts::now( $when ),
				)
			);
			return $id;
		}
		Carts::update(
			$cart['id'],
			array(
				'step_index'   => $index,
				'next_send_at' => null,
			)
		);
		return 0;
	}

	/**
	 * Why a step cannot be sent to this cart ('' = it can).
	 *
	 * @param array      $cart Cart.
	 * @param array      $step Step.
	 * @param array|null $tpl  Template.
	 * @return string
	 */
	public static function cannot_send( array $cart, array $step, $tpl ) {
		if ( ! $tpl ) {
			return __( 'Template not found.', 'dev3-ai-abandoned-cart-for-woocommerce' );
		}
		if ( 'approved' !== $tpl['status'] ) {
			return __( 'Template is not approved.', 'dev3-ai-abandoned-cart-for-woocommerce' );
		}
		if ( $tpl['channel'] !== $step['channel'] ) {
			return __( 'Template channel does not match the step.', 'dev3-ai-abandoned-cart-for-woocommerce' );
		}
		if ( (float) $step['min_total'] > 0 && (float) $cart['total'] < (float) $step['min_total'] ) {
			return __( 'Cart total is below the step minimum.', 'dev3-ai-abandoned-cart-for-woocommerce' );
		}
		if ( 'sms' === $step['channel'] ) {
			if ( ! Sms::ready() ) {
				return __( 'SMS is not configured.', 'dev3-ai-abandoned-cart-for-woocommerce' );
			}
			if ( empty( $cart['sms_consent'] ) ) {
				return __( 'No SMS consent.', 'dev3-ai-abandoned-cart-for-woocommerce' );
			}
			if ( '' === Sms::normalize( $cart['phone'], $cart['country'] ) ) {
				return __( 'No valid phone number.', 'dev3-ai-abandoned-cart-for-woocommerce' );
			}
		} elseif ( ! is_email( $cart['email'] ) ) {
			return __( 'No email address.', 'dev3-ai-abandoned-cart-for-woocommerce' );
		}
		return '';
	}

	/**
	 * Generates AI copy for messages waiting for it.
	 *
	 * @param int $limit Max AI calls.
	 * @return int Generated.
	 */
	public static function prepare_ai( $limit ) {
		if ( $limit <= 0 || ! Copywriter::available( 'cron' ) ) {
			return 0;
		}
		$count = 0;
		foreach ( Messages::ai_pending( $limit ) as $msg ) {
			if ( self::generate( $msg, 'cron' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Generates AI copy for one message and stores it as a draft (review) or auto-approved copy.
	 *
	 * @param array  $msg     Message.
	 * @param string $context Usage context.
	 * @return bool|\WP_Error True on success.
	 */
	public static function generate( array $msg, $context = 'cron' ) {
		$cart = Carts::get( $msg['cart_id'] );
		$tpl  = Templates::get( $msg['template_id'] );
		$seq  = Sequences::get( $msg['sequence_id'] );
		if ( ! $cart || ! $tpl || ! $seq || ! isset( $seq['steps'][ $msg['step_index'] ] ) ) {
			Messages::update( $msg['id'], array( 'ai_state' => '' ) );
			return false;
		}
		$step   = $seq['steps'][ $msg['step_index'] ];
		$coupon = ! empty( $step['coupon']['enabled'] ) ? Coupons::sample( $step ) : Coupons::latest_valid( $cart );
		$result = Copywriter::cart_copy(
			$cart,
			$tpl,
			array(
				'step_number' => (int) $msg['step_index'] + 1,
				'step_count'  => count( $seq['steps'] ),
				'coupon'      => $coupon,
			),
			$context
		);
		if ( is_wp_error( $result ) ) {
			Messages::update(
				$msg['id'],
				array(
					'ai_state' => 'failed',
					'ai_draft' => array( 'error' => mb_substr( $result->get_error_message(), 0, 250 ) ),
				)
			);
			return $result;
		}
		Messages::update(
			$msg['id'],
			array(
				'ai_state' => $tpl['auto_approve'] ? 'auto' : 'review',
				'ai_draft' => array(
					'draft'        => $result['draft'],
					'model'        => $result['model'],
					'tokens'       => $result['tokens'],
					'generated_at' => Carts::now(),
				),
			)
		);
		return true;
	}

	/**
	 * Sends one due message.
	 *
	 * @param array $msg   Message.
	 * @param bool  $force Send now even when held for review.
	 * @return string sent|failed|skipped|held|cancelled
	 */
	public static function send_message( array $msg, $force = false ) {
		$cart = Carts::get( $msg['cart_id'] );
		$seq  = $cart ? Sequences::get( $msg['sequence_id'] ) : null;
		if ( ! $cart || ! in_array( $cart['status'], Carts::OPEN, true ) || ! $seq || ! isset( $seq['steps'][ $msg['step_index'] ] ) ) {
			Messages::update(
				$msg['id'],
				array(
					'status' => 'cancelled',
					'error'  => __( 'The cart is no longer open.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				)
			);
			return 'cancelled';
		}
		$step   = $seq['steps'][ $msg['step_index'] ];
		$tpl    = Templates::get( $msg['template_id'] );
		$reason = self::cannot_send( $cart, $step, $tpl );
		if ( '' !== $reason ) {
			self::finish( $msg, $cart, 'skipped', array( 'error' => $reason ) );
			return 'skipped';
		}
		$recipient = 'sms' === $msg['channel'] ? Sms::normalize( $cart['phone'], $cart['country'] ) : (string) $cart['email'];
		if ( Suppressions::has( $cart['email'] ) || ( 'sms' === $msg['channel'] && Suppressions::has( $recipient ) ) ) {
			Carts::update( $cart['id'], array( 'status' => 'unsubscribed' ) );
			Messages::update(
				$msg['id'],
				array(
					'status' => 'cancelled',
					'error'  => __( 'Recipient unsubscribed.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				)
			);
			Messages::cancel_for_cart( $cart['id'], __( 'Recipient unsubscribed.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
			return 'cancelled';
		}

		// Decide the copy. Unreviewed AI text is never used.
		if ( 'pending' === $msg['ai_state'] && Copywriter::available( 'cron' ) ) {
			self::generate( $msg, 'cron' );
			$msg = Messages::get( $msg['id'] );
		}
		$source = 'template';
		$copy   = Renderer::template_copy( $tpl );
		$state  = $msg['ai_state'];
		if ( 'review' === $state ) {
			$hold_until = strtotime( $msg['scheduled_at'] . ' UTC' ) + (int) Settings::get( 'review_hold_hours' ) * HOUR_IN_SECONDS;
			if ( ! $force && 'hold' === Settings::get( 'review_fallback' ) && self::time() < $hold_until ) {
				return 'held';
			}
			$state = 'expired';
		} elseif ( in_array( $state, array( 'approved', 'auto' ), true ) && ! empty( $msg['ai_draft']['draft'] ) ) {
			$draft  = ! empty( $msg['ai_draft']['final'] ) ? $msg['ai_draft']['final'] : $msg['ai_draft']['draft'];
			$copy   = Renderer::ai_copy( $tpl, (array) $draft );
			$source = 'auto' === $state ? 'ai_auto' : 'ai';
		}

		$coupon = Coupons::for_step( $cart, $step, (int) $msg['step_index'] );
		$codes  = (array) $cart['coupon_codes'];
		if ( $coupon && ! in_array( $coupon['code'], $codes, true ) ) {
			$codes[] = $coupon['code'];
		}
		if ( ! $coupon ) {
			$coupon = Coupons::latest_valid( $cart );
		}
		if ( 'sms' === $msg['channel'] ) {
			$body    = Renderer::sms( $cart, $copy, (int) $msg['id'], $coupon );
			$subject = '';
			$result  = Sms::send( $recipient, $body );
		} else {
			$email   = Renderer::email( $cart, $tpl, $copy, (int) $msg['id'], $coupon );
			$body    = $email['html'];
			$subject = $email['subject'];
			$result  = Mailer::send( $recipient, $subject, $body, Links::unsubscribe( $cart ) );
		}
		$fields = array(
			'recipient'   => $recipient,
			'subject'     => mb_substr( $subject, 0, 250 ),
			'body'        => $body,
			'copy_source' => $source,
			'ai_state'    => $state,
			'coupon_code' => $coupon ? $coupon['code'] : '',
		);
		Carts::update( $cart['id'], array( 'coupon_codes' => $codes ) );
		$cart['coupon_codes'] = $codes;
		if ( is_wp_error( $result ) ) {
			$fields['error'] = mb_substr( $result->get_error_message(), 0, 250 );
			self::finish( $msg, $cart, 'failed', $fields );
			return 'failed';
		}
		$fields['provider_id'] = is_string( $result ) ? mb_substr( $result, 0, 100 ) : '';
		$fields['sent_at']     = Carts::now();
		self::finish( $msg, $cart, 'sent', $fields );
		/**
		 * Fires after a recovery/win-back message was sent.
		 *
		 * @param array $msg  Message.
		 * @param array $cart Cart.
		 */
		do_action( 'dev3ac_message_sent', Messages::get( $msg['id'] ), $cart );
		return 'sent';
	}

	/**
	 * Closes a message and schedules the next step.
	 *
	 * @param array  $msg    Message.
	 * @param array  $cart   Cart.
	 * @param string $status Final status.
	 * @param array  $fields Extra message fields.
	 */
	private static function finish( array $msg, array $cart, $status, array $fields ) {
		$fields['status'] = $status;
		Messages::update( $msg['id'], $fields );
		$update = array( 'step_index' => (int) $msg['step_index'] + 1 );
		if ( 'sent' === $status ) {
			$update['last_sent_at'] = Carts::now( self::time() );
		}
		Carts::update( $cart['id'], $update );
		self::schedule_next( Carts::get( $cart['id'] ), self::time() );
	}

	/**
	 * Open carts with nothing left to send become lost after lost_after_days.
	 *
	 * @param int $now Now.
	 * @return int
	 */
	public static function mark_lost( $now ) {
		$count = 0;
		foreach ( Carts::stale_open( Carts::now( $now - (int) Settings::get( 'lost_after_days' ) * DAY_IN_SECONDS ) ) as $cart ) {
			Carts::update(
				$cart['id'],
				array(
					'status'       => 'lost',
					'next_send_at' => null,
				)
			);
			++$count;
		}
		return $count;
	}

	/**
	 * Enrols lapsed customers into the win-back sequence.
	 *
	 * @param int $now Now.
	 * @return int Enrolled.
	 */
	public static function enrol_winback( $now ) {
		$seq = Sequences::active( 'winback' );
		if ( ! $seq || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$days   = (int) apply_filters( 'dev3ac_winback_days', (int) Settings::get( 'winback_days' ) );
		$window = (int) Settings::get( 'winback_window_days' );
		$orders = wc_get_orders(
			array(
				'limit'        => 50,
				'status'       => array( 'wc-processing', 'wc-completed' ),
				'date_created' => ( $now - ( $days + $window ) * DAY_IN_SECONDS ) . '...' . ( $now - $days * DAY_IN_SECONDS ),
				'orderby'      => 'date',
				'order'        => 'DESC',
				'type'         => 'shop_order',
			)
		);
		$seen   = array();
		$count  = 0;
		foreach ( $orders as $order ) {
			$email = strtolower( (string) $order->get_billing_email() );
			if ( ! is_email( $email ) || isset( $seen[ $email ] ) ) {
				continue;
			}
			$seen[ $email ] = true;
			if ( ! self::winback_eligible( $order, $email, $now, $days ) ) {
				continue;
			}
			self::enrol_order( $order, $seq, $now );
			++$count;
		}
		return $count;
	}

	/**
	 * Whether a customer (by their last order) can join win-back.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $email Email.
	 * @param int       $now   Now.
	 * @param int       $days  Inactivity days.
	 * @return bool
	 */
	public static function winback_eligible( $order, $email, $now, $days ) {
		if ( Suppressions::has( $email ) ) {
			return false;
		}
		if ( 'opt_in' === Settings::get( 'consent_mode' ) && 'yes' !== $order->get_meta( '_dev3ac_consent' ) ) {
			return false;
		}
		$newer = wc_get_orders(
			array(
				'limit'         => 1,
				'billing_email' => $email,
				'status'        => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending' ),
				'date_created'  => '>' . ( $order->get_date_created() ? $order->get_date_created()->getTimestamp() : $now ),
				'return'        => 'ids',
			)
		);
		if ( $newer ) {
			return false;
		}
		foreach ( Carts::for_email( $email, array(), 'winback' ) as $existing ) {
			if ( strtotime( $existing['created_at'] . ' UTC' ) > $now - $days * DAY_IN_SECONDS ) {
				return false;
			}
		}
		// Shoppers with an open recovery cart are already being contacted.
		return ! Carts::for_email( $email, Carts::OPEN, 'cart' );
	}

	/**
	 * Creates the win-back record for an order and schedules step 1.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $seq   Sequence.
	 * @param int       $now   Now.
	 * @return int Cart id.
	 */
	public static function enrol_order( $order, array $seq, $now ) {
		$items = array();
		$count = 0;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product || ! $product->is_purchasable() ) {
				continue;
			}
			$items[] = array(
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'quantity'     => max( 1, (int) $item->get_quantity() ),
				'variation'    => $item->get_variation_id() && $product instanceof \WC_Product_Variation ? $product->get_variation_attributes() : array(),
				'name'         => $item->get_name(),
				'line_total'   => (float) $item->get_total(),
			);
			$count  += max( 1, (int) $item->get_quantity() );
		}
		$id = Carts::insert(
			array(
				'kind'         => 'winback',
				'user_id'      => (int) $order->get_customer_id(),
				'email'        => (string) $order->get_billing_email(),
				'phone'        => (string) $order->get_billing_phone(),
				'first_name'   => (string) $order->get_billing_first_name(),
				'last_name'    => (string) $order->get_billing_last_name(),
				'country'      => (string) $order->get_billing_country(),
				'tz_offset'    => Send_Time::store_offset(),
				'consent'      => 'yes' === $order->get_meta( '_dev3ac_consent' ) ? 'opt_in' : (string) Settings::get( 'consent_mode' ),
				'sms_consent'  => 'yes' === $order->get_meta( '_dev3ac_sms_consent' ) ? 1 : 0,
				'status'       => 'abandoned',
				'items'        => $items,
				'item_count'   => $count,
				'total'        => (float) $order->get_total(),
				'currency'     => (string) $order->get_currency(),
				'source'       => 'winback',
				'sequence_id'  => $seq['id'],
				'step_index'   => 0,
				'abandoned_at' => Carts::now( $now ),
				'created_at'   => Carts::now( $now ),
				'updated_at'   => Carts::now( $now ),
			)
		);
		self::schedule_next( Carts::get( $id ), $now );
		return $id;
	}

	/**
	 * Admin action: sends the cart's next step now.
	 *
	 * @param array $cart Cart.
	 * @return string|\WP_Error Result.
	 */
	public static function send_now( array $cart ) {
		if ( ! in_array( $cart['status'], Carts::OPEN, true ) ) {
			return new \WP_Error( 'dev3ac_not_open', __( 'Only abandoned carts can receive reminders.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$scheduled = null;
		foreach ( Messages::for_cart( $cart['id'] ) as $msg ) {
			if ( 'scheduled' === $msg['status'] ) {
				$scheduled = $msg;
			}
		}
		if ( ! $scheduled ) {
			$id        = self::schedule_next( $cart, self::time() - DAY_IN_SECONDS * 400 );
			$scheduled = $id ? Messages::get( $id ) : null;
		}
		if ( ! $scheduled ) {
			return new \WP_Error( 'dev3ac_no_step', __( 'This cart has no steps left to send.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) );
		}
		Messages::update( $scheduled['id'], array( 'scheduled_at' => Carts::now() ) );
		return self::send_message( Messages::get( $scheduled['id'] ), true );
	}

	/**
	 * Admin action: stops a cart's sequence.
	 *
	 * @param array $cart Cart.
	 */
	public static function stop( array $cart ) {
		Messages::cancel_for_cart( $cart['id'], __( 'Stopped by an admin.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		Carts::update(
			$cart['id'],
			array(
				'status'       => 'stopped',
				'next_send_at' => null,
			)
		);
	}

	/**
	 * Admin action: restarts the sequence from step 1.
	 *
	 * @param array $cart Cart.
	 */
	public static function restart( array $cart ) {
		Messages::cancel_for_cart( $cart['id'], __( 'Sequence restarted.', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		$seq = Sequences::active( 'winback' === $cart['kind'] ? 'winback' : 'cart' );
		Carts::update(
			$cart['id'],
			array(
				'status'       => 'abandoned',
				'step_index'   => 0,
				'sequence_id'  => $seq ? $seq['id'] : '',
				'abandoned_at' => Carts::now(),
			)
		);
		if ( $seq ) {
			self::schedule_next( Carts::get( $cart['id'] ), self::time() );
		}
	}
}
