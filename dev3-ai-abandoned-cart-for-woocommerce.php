<?php
/**
 * Plugin Name:       DEV3 AI Abandoned Cart & Win-back Emails for WooCommerce
 * Plugin URI:        https://dev3.tech/item/dev3-ai-abandoned-cart-for-woocommerce
 * Description:       Capture abandoned carts, send AI-personalised recovery and win-back emails and SMS, and track recovered revenue.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:  10.3
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-abandoned-cart-for-woocommerce
 * Domain Path:       /languages
 *
 * @package Dev3\AiAbandonedCart
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3AC_VERSION', '1.0.0' );
define( 'DEV3AC_FILE', __FILE__ );
define( 'DEV3AC_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3AC_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3AC_SLUG', 'dev3-ai-abandoned-cart-for-woocommerce' );
define( 'DEV3AC_NAME', 'DEV3 AI Abandoned Cart & Win-back Emails for WooCommerce' );
define( 'DEV3AC_MIN_PHP', '7.4' );
define( 'DEV3AC_MIN_WP', '6.0' );
define( 'DEV3AC_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3AC_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiAbandonedCart\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3AC_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiAbandonedCart\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiAbandonedCart\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiAbandonedCart\Plugin', 'instance' ) );
