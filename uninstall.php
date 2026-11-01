<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiAbandonedCart
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3ac_settings = get_option( 'dev3ac_settings', array() );
if ( empty( $dev3ac_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3ac_usage', 'dev3ac_core_cache', 'dev3ac_core_jobs', 'dev3ac_core_embeddings', 'dev3ac_carts', 'dev3ac_messages' ) as $dev3ac_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3ac_table}" );
}
foreach ( array( 'dev3ac_settings', 'dev3ac_license', 'dev3ac_db_version', 'dev3ac_templates', 'dev3ac_sequences', 'dev3ac_suppressions', 'dev3ac_sms_token', 'dev3ac_last_sweep', 'dev3ac_winback_last', 'dev3ac_sweep_lock' ) as $dev3ac_option ) {
	delete_option( $dev3ac_option );
}
delete_transient( 'dev3ac_models' );
delete_site_transient( 'dev3ac_update_info' );
wp_unschedule_hook( 'dev3ac_run_job' );
wp_unschedule_hook( 'dev3ac_sweep' );
wp_unschedule_hook( 'dev3ac_daily' );
delete_transient( 'dev3ac_hour_histogram' );
