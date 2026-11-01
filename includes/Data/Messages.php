<?php
/**
 * Scheduled and sent messages repository.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Data;

use Dev3\AiAbandonedCart\Install;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, names from $wpdb->prefix.

/**
 * CRUD + queries for {prefix}dev3ac_messages.
 *
 * Status: scheduled | sent | failed | skipped | cancelled.
 * AI state: '' (no AI) | pending (to generate) | review (waiting for an admin) | approved | auto | rejected | expired | failed.
 */
class Messages {

	const STATUSES = array( 'scheduled', 'sent', 'failed', 'skipped', 'cancelled' );

	/**
	 * Inserts a message.
	 *
	 * @param array $data Columns.
	 * @return int
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$data = array_merge(
			array(
				'status'     => 'scheduled',
				'body'       => '',
				'ai_draft'   => '',
				'created_at' => Carts::now(),
			),
			$data
		);
		$wpdb->insert( Install::messages_table(), self::encode( $data ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a message.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		return false !== $wpdb->update( Install::messages_table(), self::encode( $data ), array( 'id' => (int) $id ) );
	}

	/**
	 * One message.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t   = Install::messages_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Messages of a cart (oldest first).
	 *
	 * @param int $cart_id Cart.
	 * @return array
	 */
	public static function for_cart( $cart_id ) {
		global $wpdb;
		$t    = Install::messages_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE cart_id = %d ORDER BY id ASC", (int) $cart_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'decode' ), $rows ? $rows : array() );
	}

	/**
	 * Due scheduled messages.
	 *
	 * @param string $now   Datetime.
	 * @param int    $limit Limit.
	 * @return array
	 */
	public static function due( $now, $limit ) {
		global $wpdb;
		$t    = Install::messages_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'scheduled' AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT %d", $now, (int) $limit ), ARRAY_A );
		return array_map( array( __CLASS__, 'decode' ), $rows ? $rows : array() );
	}

	/**
	 * Scheduled messages still waiting for AI copy.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function ai_pending( $limit ) {
		global $wpdb;
		$t    = Install::messages_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'scheduled' AND ai_state = 'pending' ORDER BY scheduled_at ASC LIMIT %d", (int) $limit ), ARRAY_A );
		return array_map( array( __CLASS__, 'decode' ), $rows ? $rows : array() );
	}

	/**
	 * Review queue.
	 *
	 * @return array
	 */
	public static function review_queue() {
		global $wpdb;
		$t    = Install::messages_table();
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE status = 'scheduled' AND ai_state = 'review' ORDER BY scheduled_at ASC LIMIT 100", ARRAY_A );
		return array_map( array( __CLASS__, 'decode' ), $rows ? $rows : array() );
	}

	/**
	 * Review queue size.
	 *
	 * @return int
	 */
	public static function review_count() {
		global $wpdb;
		$t = Install::messages_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'scheduled' AND ai_state = 'review'" );
	}

	/**
	 * Cancels a cart's scheduled messages.
	 *
	 * @param int    $cart_id Cart.
	 * @param string $reason  Reason.
	 * @return int
	 */
	public static function cancel_for_cart( $cart_id, $reason = '' ) {
		global $wpdb;
		$t = Install::messages_table();
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'cancelled', error = %s WHERE cart_id = %d AND status = 'scheduled'", mb_substr( (string) $reason, 0, 250 ), (int) $cart_id ) );
	}

	/**
	 * Paged log for the admin.
	 *
	 * @param array $args { status, channel, page, per_page, cart_id }.
	 * @return array { rows, total }
	 */
	public static function query( array $args ) {
		global $wpdb;
		$t        = Install::messages_table();
		$c        = Install::carts_table();
		$where    = ' WHERE 1=1';
		$per_page = isset( $args['per_page'] ) ? min( 100, max( 1, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		if ( ! empty( $args['status'] ) ) {
			if ( 'review' === $args['status'] ) {
				$where .= " AND m.status = 'scheduled' AND m.ai_state = 'review'";
			} elseif ( 'opened' === $args['status'] ) {
				$where .= ' AND m.opened_at IS NOT NULL';
			} elseif ( 'clicked' === $args['status'] ) {
				$where .= ' AND m.clicked_at IS NOT NULL';
			} elseif ( in_array( $args['status'], self::STATUSES, true ) ) {
				$where .= $wpdb->prepare( ' AND m.status = %s', $args['status'] );
			}
		}
		if ( ! empty( $args['channel'] ) && in_array( $args['channel'], array( 'email', 'sms' ), true ) ) {
			$where .= $wpdb->prepare( ' AND m.channel = %s', $args['channel'] );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $where is built from prepared parts.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} m{$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT m.*, c.first_name, c.last_name, c.kind FROM {$t} m LEFT JOIN {$c} c ON c.id = m.cart_id{$where} ORDER BY COALESCE(m.sent_at, m.scheduled_at) DESC, m.id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'rows'  => array_map( array( __CLASS__, 'decode' ), $rows ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Encodes JSON columns.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private static function encode( array $data ) {
		if ( array_key_exists( 'ai_draft', $data ) && is_array( $data['ai_draft'] ) ) {
			$data['ai_draft'] = (string) wp_json_encode( $data['ai_draft'] );
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
		$draft           = isset( $row['ai_draft'] ) && '' !== $row['ai_draft'] ? json_decode( (string) $row['ai_draft'], true ) : null;
		$row['ai_draft'] = is_array( $draft ) ? $draft : null;
		foreach ( array( 'id', 'cart_id', 'step_index', 'reviewed_by' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		return $row;
	}
}
