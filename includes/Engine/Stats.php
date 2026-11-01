<?php
/**
 * Recovered-revenue dashboard numbers.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Engine;

use Dev3\AiAbandonedCart\Data\Carts;
use Dev3\AiAbandonedCart\Data\Messages;
use Dev3\AiAbandonedCart\Data\Sequences;
use Dev3\AiAbandonedCart\Install;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables, names from $wpdb->prefix.

/**
 * Aggregates for a period (amounts in the store currency; carts in other currencies are counted at face value).
 */
class Stats {

	/**
	 * Dashboard payload.
	 *
	 * @param int $days Period.
	 * @return array
	 */
	public static function dashboard( $days = 30 ) {
		global $wpdb;
		$days  = in_array( (int) $days, array( 7, 30, 90, 365 ), true ) ? (int) $days : 30;
		$c     = Install::carts_table();
		$m     = Install::messages_table();
		$now   = Scheduler::time();
		$since = Carts::now( $now - $days * DAY_IN_SECONDS );

		$captured  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE kind = 'cart' AND created_at >= %s", $since ) );
		$abandoned = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS v FROM {$c} WHERE kind = 'cart' AND abandoned_at >= %s", $since ), ARRAY_A );
		$open      = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS v FROM {$c} WHERE kind = 'cart' AND status IN ('abandoned','recovering') AND abandoned_at >= %s", $since ), ARRAY_A );
		$recovered = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(recovered_total),0) AS v, COALESCE(SUM(coupon_used),0) AS coupons FROM {$c} WHERE status = 'recovered' AND recovered_at >= %s", $since ), ARRAY_A );
		$rec_cart  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE kind = 'cart' AND status = 'recovered' AND abandoned_at >= %s", $since ) );
		$winback   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(recovered_total),0) AS v FROM {$c} WHERE kind = 'winback' AND status = 'recovered' AND recovered_at >= %s", $since ), ARRAY_A );
		$msgs      = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS sent, SUM(opened_at IS NOT NULL) AS opened, SUM(clicked_at IS NOT NULL) AS clicked, SUM(channel = 'sms') AS sms, SUM(copy_source <> 'template') AS ai FROM {$m} WHERE status = 'sent' AND sent_at >= %s", $since ), ARRAY_A );
		$emails    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE status = 'sent' AND channel = 'email' AND sent_at >= %s", $since ) );
		$sent      = (int) $msgs['sent'];
		$ab_count  = (int) $abandoned['n'];

		$kpis = array(
			'captured'            => $captured,
			'abandoned'           => $ab_count,
			'abandoned_value'     => round( (float) $abandoned['v'], 2 ),
			'recoverable_value'   => round( (float) $open['v'], 2 ),
			'recoverable_count'   => (int) $open['n'],
			'recovered'           => (int) $recovered['n'],
			'recovered_value'     => round( (float) $recovered['v'], 2 ),
			'winback_recovered'   => (int) $winback['n'],
			'winback_value'       => round( (float) $winback['v'], 2 ),
			'recovery_rate'       => $ab_count ? round( 100 * $rec_cart / $ab_count, 1 ) : 0,
			'messages_sent'       => $sent,
			'sms_sent'            => (int) $msgs['sms'],
			'ai_messages'         => (int) $msgs['ai'],
			'open_rate'           => $emails ? round( 100 * (int) $msgs['opened'] / $emails, 1 ) : 0,
			'click_rate'          => $sent ? round( 100 * (int) $msgs['clicked'] / $sent, 1 ) : 0,
			'coupons_redeemed'    => (int) $recovered['coupons'],
			'review_queue'        => Messages::review_count(),
			'scheduled'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE status = 'scheduled'" ),
			'average_order_value' => (int) $recovered['n'] ? round( (float) $recovered['v'] / (int) $recovered['n'], 2 ) : 0,
		);

		return array(
			'days'         => $days,
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'kpis'         => $kpis,
			'series'       => self::series( $days, $now ),
			'funnel'       => self::funnel( $since ),
			'top_products' => self::top_products( $since ),
			'recent'       => self::recent(),
			'hours'        => Send_Time::store_histogram(),
			'last_sweep'   => (int) get_option( 'dev3ac_last_sweep', 0 ),
			'next_sweep'   => (int) wp_next_scheduled( Scheduler::CRON_HOOK ),
		);
	}

	/**
	 * Daily abandoned vs recovered values.
	 *
	 * @param int $days Days.
	 * @param int $now  Now.
	 * @return array
	 */
	public static function series( $days, $now ) {
		global $wpdb;
		$c      = Install::carts_table();
		$offset = Send_Time::store_offset();
		$since  = Carts::now( $now - $days * DAY_IN_SECONDS );
		$out    = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day         = gmdate( 'Y-m-d', $now + $offset * 60 - $i * DAY_IN_SECONDS );
			$out[ $day ] = array(
				'date'            => $day,
				'abandoned'       => 0,
				'abandoned_value' => 0.0,
				'recovered'       => 0,
				'recovered_value' => 0.0,
			);
		}
		$ab = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(DATE_ADD(abandoned_at, INTERVAL %d MINUTE)) AS d, COUNT(*) AS n, SUM(total) AS v FROM {$c} WHERE kind = 'cart' AND abandoned_at >= %s GROUP BY d", $offset, $since ), ARRAY_A );
		foreach ( $ab ? $ab : array() as $r ) {
			if ( isset( $out[ $r['d'] ] ) ) {
				$out[ $r['d'] ]['abandoned']       = (int) $r['n'];
				$out[ $r['d'] ]['abandoned_value'] = round( (float) $r['v'], 2 );
			}
		}
		$rec = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(DATE_ADD(recovered_at, INTERVAL %d MINUTE)) AS d, COUNT(*) AS n, SUM(recovered_total) AS v FROM {$c} WHERE status = 'recovered' AND recovered_at >= %s GROUP BY d", $offset, $since ), ARRAY_A );
		foreach ( $rec ? $rec : array() as $r ) {
			if ( isset( $out[ $r['d'] ] ) ) {
				$out[ $r['d'] ]['recovered']       = (int) $r['n'];
				$out[ $r['d'] ]['recovered_value'] = round( (float) $r['v'], 2 );
			}
		}
		return array_values( $out );
	}

	/**
	 * Per-step funnel.
	 *
	 * @param string $since Since.
	 * @return array
	 */
	public static function funnel( $since ) {
		global $wpdb;
		$c    = Install::carts_table();
		$m    = Install::messages_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT sequence_id, step_index, channel, COUNT(*) AS sent, SUM(opened_at IS NOT NULL) AS opened, SUM(clicked_at IS NOT NULL) AS clicked, SUM(copy_source <> 'template') AS ai FROM {$m} WHERE status = 'sent' AND sent_at >= %s GROUP BY sequence_id, step_index, channel ORDER BY sequence_id, step_index", $since ), ARRAY_A );
		$rec  = $wpdb->get_results( $wpdb->prepare( "SELECT sequence_id, recovered_step, COUNT(*) AS n, SUM(recovered_total) AS v FROM {$c} WHERE status = 'recovered' AND recovered_at >= %s GROUP BY sequence_id, recovered_step", $since ), ARRAY_A );
		$map  = array();
		foreach ( $rec ? $rec : array() as $r ) {
			$map[ $r['sequence_id'] . ':' . $r['recovered_step'] ] = $r;
		}
		$out = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$seq   = Sequences::get( $r['sequence_id'] );
			$key   = $r['sequence_id'] . ':' . $r['step_index'];
			$out[] = array(
				'key'       => $key . ':' . $r['channel'],
				'sequence'  => $seq ? $seq['name'] : $r['sequence_id'],
				'type'      => $seq ? $seq['type'] : 'cart',
				'step'      => (int) $r['step_index'] + 1,
				'channel'   => $r['channel'],
				'sent'      => (int) $r['sent'],
				'opened'    => (int) $r['opened'],
				'clicked'   => (int) $r['clicked'],
				'ai'        => (int) $r['ai'],
				'recovered' => isset( $map[ $key ] ) ? (int) $map[ $key ]['n'] : 0,
				'revenue'   => isset( $map[ $key ] ) ? round( (float) $map[ $key ]['v'], 2 ) : 0,
			);
		}
		return $out;
	}

	/**
	 * Most abandoned products.
	 *
	 * @param string $since Since.
	 * @return array
	 */
	public static function top_products( $since ) {
		global $wpdb;
		$c    = Install::carts_table();
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT items FROM {$c} WHERE kind = 'cart' AND abandoned_at >= %s ORDER BY id DESC LIMIT 2000", $since ) );
		$agg  = array();
		foreach ( $rows ? $rows : array() as $json ) {
			$items = json_decode( (string) $json, true );
			foreach ( is_array( $items ) ? $items : array() as $item ) {
				$id = (int) $item['product_id'];
				if ( ! isset( $agg[ $id ] ) ) {
					$agg[ $id ] = array(
						'product_id' => $id,
						'name'       => (string) $item['name'],
						'carts'      => 0,
						'quantity'   => 0,
						'value'      => 0.0,
					);
				}
				++$agg[ $id ]['carts'];
				$agg[ $id ]['quantity'] += (int) $item['quantity'];
				$agg[ $id ]['value']    += (float) $item['line_total'];
			}
		}
		usort(
			$agg,
			static function ( $a, $b ) {
				return $b['carts'] <=> $a['carts'] ? $b['carts'] <=> $a['carts'] : $b['value'] <=> $a['value'];
			}
		);
		foreach ( $agg as &$row ) {
			$row['value'] = round( $row['value'], 2 );
		}
		unset( $row );
		return array_slice( $agg, 0, 10 );
	}

	/**
	 * Latest recoveries.
	 *
	 * @return array
	 */
	public static function recent() {
		global $wpdb;
		$c    = Install::carts_table();
		$rows = $wpdb->get_results( "SELECT id, kind, email, first_name, last_name, recovered_total, recovered_step, recovered_at, order_id, currency, coupon_used FROM {$c} WHERE status = 'recovered' ORDER BY recovered_at DESC LIMIT 8", ARRAY_A );
		return array_map(
			static function ( $r ) {
				$r['id']              = (int) $r['id'];
				$r['order_id']        = (int) $r['order_id'];
				$r['recovered_total'] = (float) $r['recovered_total'];
				$r['recovered_step']  = (int) $r['recovered_step'];
				$r['coupon_used']     = (int) $r['coupon_used'];
				return $r;
			},
			$rows ? $rows : array()
		);
	}
}
