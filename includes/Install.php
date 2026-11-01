<?php
/**
 * Plugin tables and first-run content.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart;

use Dev3\AiAbandonedCart\Data\Sequences;
use Dev3\AiAbandonedCart\Data\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Schema (hooked into dev3ac_schema_sql) and default sequences/templates.
 */
class Install {

	/**
	 * Carts table name.
	 *
	 * @return string
	 */
	public static function carts_table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3ac_carts';
	}

	/**
	 * Messages table name.
	 *
	 * @return string
	 */
	public static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3ac_messages';
	}

	/**
	 * Adds plugin tables to the core dbDelta run.
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$carts    = self::carts_table();
		$messages = self::messages_table();
		$sql[]    = "CREATE TABLE {$carts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(10) NOT NULL DEFAULT 'cart',
			session_key varchar(64) NOT NULL DEFAULT '',
			token char(32) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(32) NOT NULL DEFAULT '',
			first_name varchar(80) NOT NULL DEFAULT '',
			last_name varchar(80) NOT NULL DEFAULT '',
			country varchar(2) NOT NULL DEFAULT '',
			locale varchar(20) NOT NULL DEFAULT '',
			tz_offset smallint(6) NOT NULL DEFAULT 0,
			tz_known tinyint(1) NOT NULL DEFAULT 0,
			consent varchar(10) NOT NULL DEFAULT '',
			sms_consent tinyint(1) NOT NULL DEFAULT 0,
			consent_at datetime NULL DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			items longtext NOT NULL,
			item_count int(10) unsigned NOT NULL DEFAULT 0,
			total decimal(18,4) NOT NULL DEFAULT 0,
			currency varchar(3) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT '',
			sequence_id varchar(40) NOT NULL DEFAULT '',
			step_index smallint(5) unsigned NOT NULL DEFAULT 0,
			next_send_at datetime NULL DEFAULT NULL,
			last_sent_at datetime NULL DEFAULT NULL,
			clicked_at datetime NULL DEFAULT NULL,
			coupon_codes text NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recovered_total decimal(18,4) NOT NULL DEFAULT 0,
			recovered_step smallint(5) NOT NULL DEFAULT -1,
			coupon_used tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			abandoned_at datetime NULL DEFAULT NULL,
			recovered_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status_next (status,next_send_at),
			KEY email (email),
			KEY session_key (session_key),
			KEY updated (updated_at),
			KEY kind_status (kind,status)
		) {$charset};";
		$sql[]    = "CREATE TABLE {$messages} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			cart_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sequence_id varchar(40) NOT NULL DEFAULT '',
			step_index smallint(5) unsigned NOT NULL DEFAULT 0,
			channel varchar(10) NOT NULL DEFAULT 'email',
			template_id varchar(40) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'scheduled',
			ai_state varchar(20) NOT NULL DEFAULT '',
			recipient varchar(190) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			body longtext NOT NULL,
			ai_draft longtext NOT NULL,
			copy_source varchar(20) NOT NULL DEFAULT 'template',
			coupon_code varchar(60) NOT NULL DEFAULT '',
			provider_id varchar(100) NOT NULL DEFAULT '',
			error varchar(255) NOT NULL DEFAULT '',
			scheduled_at datetime NOT NULL,
			sent_at datetime NULL DEFAULT NULL,
			opened_at datetime NULL DEFAULT NULL,
			clicked_at datetime NULL DEFAULT NULL,
			reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY cart_id (cart_id),
			KEY status_scheduled (status,scheduled_at),
			KEY ai_state (ai_state),
			KEY sent (sent_at)
		) {$charset};";
		return $sql;
	}

	/**
	 * Seeds the default sequences and templates once.
	 */
	public static function seed_defaults() {
		if ( false === get_option( Templates::OPTION, false ) ) {
			Templates::save_all( Templates::defaults() );
		}
		if ( false === get_option( Sequences::OPTION, false ) ) {
			Sequences::save_all( Sequences::defaults() );
		}
	}
}
