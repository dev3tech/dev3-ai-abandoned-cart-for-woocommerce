<?php
/**
 * Send-time tuning.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Engine;

use Dev3\AiAbandonedCart\Install;
use Dev3\AiAbandonedCart\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Moves a send forward (never back) to the shopper's best hour within the allowed shift, outside quiet hours.
 *
 * Score per local hour = 3 × own past order hours + 2 × own open/click hours + 1 × store-wide click hours (normalised)
 * + 0.5 for the configured best hours.
 */
class Send_Time {

	/**
	 * Store-wide histogram memo.
	 *
	 * @var array|null
	 */
	private static $store = null;

	/**
	 * Store timezone offset in minutes east of UTC.
	 *
	 * @return int
	 */
	public static function store_offset() {
		return (int) round( wp_timezone()->getOffset( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) ) / 60 );
	}

	/**
	 * Whether a local hour is inside quiet hours.
	 *
	 * @param int $hour  Hour 0-23.
	 * @param int $start Quiet start.
	 * @param int $end   Quiet end.
	 * @return bool
	 */
	public static function is_quiet( $hour, $start, $end ) {
		$hour  = (int) $hour;
		$start = (int) $start;
		$end   = (int) $end;
		if ( $start === $end ) {
			return false;
		}
		return $start > $end ? ( $hour >= $start || $hour < $end ) : ( $hour >= $start && $hour < $end );
	}

	/**
	 * Local hour of a UTC timestamp.
	 *
	 * @param int $ts     Timestamp.
	 * @param int $offset Minutes east of UTC.
	 * @return int
	 */
	public static function local_hour( $ts, $offset ) {
		return (int) gmdate( 'G', (int) $ts + (int) $offset * 60 );
	}

	/**
	 * Best send timestamp.
	 *
	 * @param int   $earliest   Earliest UTC timestamp (from the step delay).
	 * @param array $cart       Cart.
	 * @param array $step       Step.
	 * @param int   $step_index Step index.
	 * @param array $scores     Optional precomputed hour scores (tests).
	 * @return int
	 */
	public static function best( $earliest, array $cart, array $step, $step_index, $scores = null ) {
		$earliest = (int) $earliest;
		if ( ! Settings::get( 'send_time_tuning' ) ) {
			return (int) apply_filters( 'dev3ac_best_send_time', $earliest, $cart, $step );
		}
		$offset    = ! empty( $cart['tz_known'] ) ? (int) $cart['tz_offset'] : self::store_offset();
		$max_shift = (int) Settings::get( 'max_shift_hours' );
		// Keep the first cart reminder fresh: at most one hour of shift when it is due within 2 hours.
		if ( 0 === (int) $step_index && 'cart' === ( isset( $cart['kind'] ) ? $cart['kind'] : 'cart' ) && \Dev3\AiAbandonedCart\Data\Sequences::delay_seconds( $step ) <= 2 * HOUR_IN_SECONDS ) {
			$max_shift = min( $max_shift, 1 );
		}
		$quiet_start = (int) Settings::get( 'quiet_start' );
		$quiet_end   = (int) Settings::get( 'quiet_end' );
		$scores      = is_array( $scores ) ? $scores : self::scores( $cart );

		$candidates = array( $earliest );
		$next_hour  = (int) ( ceil( ( $earliest + 1 ) / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );
		for ( $ts = $next_hour; $ts <= $earliest + $max_shift * HOUR_IN_SECONDS; $ts += HOUR_IN_SECONDS ) {
			$candidates[] = $ts;
		}
		$best       = null;
		$best_score = -1.0;
		foreach ( $candidates as $ts ) {
			$hour = self::local_hour( $ts, $offset );
			if ( self::is_quiet( $hour, $quiet_start, $quiet_end ) ) {
				continue;
			}
			$score = isset( $scores[ $hour ] ) ? (float) $scores[ $hour ] : 0.0;
			if ( $score > $best_score + 0.0001 ) {
				$best       = $ts;
				$best_score = $score;
			}
		}
		if ( null === $best ) {
			// Every candidate is in quiet hours: wait until quiet hours end (local time).
			$best = $earliest;
			for ( $i = 0; $i < 25; $i++ ) {
				$ts = $next_hour + $i * HOUR_IN_SECONDS;
				if ( ! self::is_quiet( self::local_hour( $ts, $offset ), $quiet_start, $quiet_end ) ) {
					$best = $ts;
					break;
				}
			}
		}
		/**
		 * Filters the tuned send time.
		 *
		 * @param int   $best Timestamp.
		 * @param array $cart Cart.
		 * @param array $step Step.
		 */
		return (int) apply_filters( 'dev3ac_best_send_time', max( $earliest, (int) $best ), $cart, $step );
	}

	/**
	 * Hour scores (0-23, shopper-local) for a cart.
	 *
	 * @param array $cart Cart.
	 * @return array
	 */
	public static function scores( array $cart ) {
		$offset = ! empty( $cart['tz_known'] ) ? (int) $cart['tz_offset'] : self::store_offset();
		$scores = array_fill( 0, 24, 0.0 );
		foreach ( (array) Settings::get( 'best_hours' ) as $h ) {
			if ( isset( $scores[ (int) $h ] ) ) {
				$scores[ (int) $h ] += 0.5;
			}
		}
		$store = self::store_histogram();
		$max   = max( 1, max( $store['clicks'] ) );
		foreach ( $store['clicks'] as $h => $n ) {
			$scores[ $h ] += $n / $max;
		}
		if ( ! empty( $cart['email'] ) && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'billing_email' => $cart['email'],
					'limit'         => 20,
					'status'        => array( 'wc-processing', 'wc-completed' ),
					'return'        => 'objects',
				)
			);
			foreach ( $orders as $order ) {
				$created = $order->get_date_created();
				if ( $created ) {
					$scores[ self::local_hour( $created->getTimestamp(), $offset ) ] += 3;
				}
			}
			global $wpdb;
			$m = Install::messages_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT COALESCE(clicked_at, opened_at) FROM {$m} WHERE recipient = %s AND (clicked_at IS NOT NULL OR opened_at IS NOT NULL) ORDER BY id DESC LIMIT 30", strtolower( (string) $cart['email'] ) ) );
			foreach ( $rows ? $rows : array() as $when ) {
				$scores[ self::local_hour( strtotime( $when . ' UTC' ), $offset ) ] += 2;
			}
		}
		return $scores;
	}

	/**
	 * Store-wide hourly histograms (shopper-local hours) for the last 90 days.
	 *
	 * @return array { opens[24], clicks[24], orders[24] }
	 */
	public static function store_histogram() {
		if ( null !== self::$store ) {
			return self::$store;
		}
		$cached = get_transient( 'dev3ac_hour_histogram' );
		if ( is_array( $cached ) && isset( $cached['clicks'] ) ) {
			self::$store = $cached;
			return $cached;
		}
		global $wpdb;
		$m     = Install::messages_table();
		$c     = Install::carts_table();
		$since = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		$out   = array(
			'opens'  => array_fill( 0, 24, 0 ),
			'clicks' => array_fill( 0, 24, 0 ),
			'orders' => array_fill( 0, 24, 0 ),
		);
		$map   = array(
			'opens'  => 'm.opened_at',
			'clicks' => 'm.clicked_at',
		);
		foreach ( $map as $key => $col ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables, fixed column names.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT HOUR(DATE_ADD({$col}, INTERVAL c.tz_offset MINUTE)) AS h, COUNT(*) AS n FROM {$m} m JOIN {$c} c ON c.id = m.cart_id WHERE {$col} IS NOT NULL AND {$col} >= %s GROUP BY h", $since ), ARRAY_A );
			foreach ( $rows ? $rows : array() as $r ) {
				$out[ $key ][ (int) $r['h'] ] = (int) $r['n'];
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT HOUR(DATE_ADD(recovered_at, INTERVAL tz_offset MINUTE)) AS h, COUNT(*) AS n FROM {$c} WHERE recovered_at IS NOT NULL AND recovered_at >= %s GROUP BY h", $since ), ARRAY_A );
		foreach ( $rows ? $rows : array() as $r ) {
			$out['orders'][ (int) $r['h'] ] = (int) $r['n'];
		}
		set_transient( 'dev3ac_hour_histogram', $out, HOUR_IN_SECONDS );
		self::$store = $out;
		return $out;
	}

	/**
	 * Clears memo + transient.
	 */
	public static function flush() {
		self::$store = null;
		delete_transient( 'dev3ac_hour_histogram' );
	}
}
