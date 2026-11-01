<?php
/**
 * Captured carts repository.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Data;

use Dev3\AiAbandonedCart\Install;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, names from $wpdb->prefix.

/**
 * CRUD + queries for {prefix}dev3ac_carts.
 */
class Carts {

	const STATUSES = array( 'active', 'abandoned', 'recovering', 'recovered', 'converted', 'lost', 'unsubscribed', 'stopped' );

	/**
	 * Statuses in which a sequence may still send.
	 */
	const OPEN = array( 'abandoned', 'recovering' );

	/**
	 * Current UTC time as MySQL datetime.
	 *
	 * @param int|null $ts Timestamp.
	 * @return string
	 */
	public static function now( $ts = null ) {
		return gmdate( 'Y-m-d H:i:s', null === $ts ? time() : (int) $ts );
	}

	/**
	 * New random token for recovery links.
	 *
	 * @return string
	 */
	public static function new_token() {
		return strtolower( wp_generate_password( 32, false, false ) );
	}

	/**
	 * Inserts a cart.
	 *
	 * @param array $data Columns.
	 * @return int Id.
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$now  = self::now();
		$data = array_merge(
			array(
				'kind'         => 'cart',
				'token'        => self::new_token(),
				'status'       => 'active',
				'items'        => array(),
				'coupon_codes' => array(),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			$data
		);
		$wpdb->insert( Install::carts_table(), self::encode( $data ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a cart.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		return false !== $wpdb->update( Install::carts_table(), self::encode( $data ), array( 'id' => (int) $id ) );
	}

	/**
	 * Deletes a cart and its messages.
	 *
	 * @param int $id Id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Install::carts_table(), array( 'id' => (int) $id ) );
		$wpdb->delete( Install::messages_table(), array( 'cart_id' => (int) $id ) );
	}

	/**
	 * One cart.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t   = Install::carts_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Cart by recovery token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function by_token( $token ) {
		global $wpdb;
		$token = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $token ) );
		if ( 32 !== strlen( $token ) ) {
			return null;
		}
		$t   = Install::carts_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token = %s", $token ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Latest cart for a WooCommerce session key that is still active.
	 *
	 * @param string $session_key Session key.
	 * @return array|null
	 */
	public static function active_for_session( $session_key ) {
		global $wpdb;
		if ( '' === (string) $session_key ) {
			return null;
		}
		$t   = Install::carts_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_key = %s AND kind = 'cart' AND status IN ('active','abandoned','recovering') ORDER BY id DESC LIMIT 1", (string) $session_key ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Open carts for an email (newest first).
	 *
	 * @param string $email    Email.
	 * @param array  $statuses Statuses.
	 * @param string $kind     cart|winback|'' (any).
	 * @return array
	 */
	public static function for_email( $email, array $statuses = array(), $kind = '' ) {
		global $wpdb;
		$t    = Install::carts_table();
		$sql  = $wpdb->prepare( "SELECT * FROM {$t} WHERE email = %s", strtolower( (string) $email ) );
		$sql .= self::status_clause( $statuses );
		if ( '' !== $kind ) {
			$sql .= $wpdb->prepare( ' AND kind = %s', $kind );
		}
		$rows = $wpdb->get_results( $sql . ' ORDER BY id DESC LIMIT 50', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from prepared parts.
		return array_map( array( __CLASS__, 'decode' ), $rows ? $rows : array() );
	}

	/**
	 * Active carts idle since before $before (abandonment candidates).
	 *
	 * @param string $before Datetime.
	 * @param int    $limit  Limit.
	 * @return array
	 */
	public static function idle( $before, $limit = 100 ) {
		global $wpdb;
		$t    = Install::carts_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE kind = 'cart' AND status = 'active' AND order_id = 0 AND updated_at <= %s AND item_count > 0 AND (email <> '' OR (phone <> '' AND sms_consent = 1)) ORDER BY updated_at ASC LIMIT %d", $before, (int) $limit ), ARRAY_A );
		return array_map( array( __CLASS__, 'decode' ), $rows ? $rows : array() );
	}

	/**
	 * Open carts whose last message went out before $before and have nothing scheduled.
	 *
	 * @param string $before Datetime.
	 * @param int    $limit  Limit.
	 * @return array
	 */
	public static function stale_open( $before, $limit = 200 ) {
		global $wpdb;
		$t    = Install::carts_table();
		$m    = Install::messages_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.* FROM {$t} c WHERE c.status IN ('abandoned','recovering') AND COALESCE(c.last_sent_at, c.abandoned_at) <= %s AND NOT EXISTS (SELECT 1 FROM {$m} m WHERE m.cart_id = c.id AND m.status = 'scheduled') LIMIT %d", $before, (int) $limit ), ARRAY_A );
		return array_map( array( __CLASS__, 'decode' ), $rows ? $rows : array() );
	}

	/**
	 * Paged list for the admin.
	 *
	 * @param array $args { status, kind, search, page, per_page }.
	 * @return array { rows, total }
	 */
	public static function query( array $args ) {
		global $wpdb;
		$t        = Install::carts_table();
		$where    = ' WHERE 1=1';
		$per_page = isset( $args['per_page'] ) ? min( 100, max( 1, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where .= $wpdb->prepare( ' AND status = %s', $args['status'] );
		}
		if ( ! empty( $args['kind'] ) && in_array( $args['kind'], array( 'cart', 'winback' ), true ) ) {
			$where .= $wpdb->prepare( ' AND kind = %s', $args['kind'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like   = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where .= $wpdb->prepare( ' AND (email LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s)', $like, $like, $like, $like );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $where is built from prepared parts.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}{$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t}{$where} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'rows'  => array_map( array( __CLASS__, 'decode' ), $rows ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Counts per status.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$t    = Install::carts_table();
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$t} GROUP BY status", ARRAY_A );
		$out  = array_fill_keys( self::STATUSES, 0 );
		foreach ( $rows ? $rows : array() as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		$out['all'] = array_sum( $out );
		return $out;
	}

	/**
	 * Deletes carts (and messages) last touched before $before.
	 *
	 * @param string $before Datetime.
	 * @return int Deleted carts.
	 */
	public static function prune( $before ) {
		global $wpdb;
		$t   = Install::carts_table();
		$m   = Install::messages_table();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE updated_at < %s AND status NOT IN ('abandoned','recovering') LIMIT 1000", $before ) );
		if ( ! $ids ) {
			return 0;
		}
		$ids  = array_map( 'intval', $ids );
		$list = implode( ',', $ids );
		$wpdb->query( "DELETE FROM {$m} WHERE cart_id IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integers only.
		$wpdb->query( "DELETE FROM {$t} WHERE id IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integers only.
		return count( $ids );
	}

	/**
	 * SQL status filter.
	 *
	 * @param array $statuses Statuses.
	 * @return string
	 */
	private static function status_clause( array $statuses ) {
		$statuses = array_values( array_intersect( $statuses, self::STATUSES ) );
		if ( ! $statuses ) {
			return '';
		}
		return " AND status IN ('" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "')";
	}

	/**
	 * Encodes JSON columns.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private static function encode( array $data ) {
		foreach ( array( 'items', 'coupon_codes' ) as $key ) {
			if ( array_key_exists( $key, $data ) && is_array( $data[ $key ] ) ) {
				$data[ $key ] = (string) wp_json_encode( array_values( $data[ $key ] ) );
			}
		}
		if ( isset( $data['email'] ) ) {
			$data['email'] = strtolower( (string) $data['email'] );
		}
		return $data;
	}

	/**
	 * Decodes a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function decode( array $row ) {
		foreach ( array( 'items', 'coupon_codes' ) as $key ) {
			$decoded     = isset( $row[ $key ] ) ? json_decode( (string) $row[ $key ], true ) : array();
			$row[ $key ] = is_array( $decoded ) ? $decoded : array();
		}
		foreach ( array( 'id', 'user_id', 'tz_offset', 'tz_known', 'sms_consent', 'item_count', 'step_index', 'order_id', 'recovered_step', 'coupon_used' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		foreach ( array( 'total', 'recovered_total' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (float) $row[ $key ];
			}
		}
		return $row;
	}
}
