<?php
/**
 * Loads WooCommerce before the plugin in the test suite and installs its tables.
 *
 * @package Dev3\AiAbandonedCart
 */

tests_add_filter(
	'dev3ac_test_dependencies',
	static function () {
		return array( file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ? 'woocommerce/woocommerce.php' : 'woocommerce.latest-stable/woocommerce.php' );
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reload roles WooCommerce just added.
			wp_roles();
		}
	}
);

// The scaffold job tests assume WP-Cron, not Action Scheduler (bundled with WooCommerce).
tests_add_filter( 'dev3ac_jobs_use_action_scheduler', '__return_false' );
// No cookies in CLI.
tests_add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
tests_add_filter(
	'wp_mail_from',
	static function () {
		return 'shop@example.org';
	}
);
