<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart;

use Dev3\AiAbandonedCart\Core3\Core\Assets;
use Dev3\AiAbandonedCart\Core3\Core\Privacy;
use Dev3\AiAbandonedCart\Core3\Core\Schema;
use Dev3\AiAbandonedCart\Engine\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3ac_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Assets registered guard.
	 *
	 * @var bool
	 */
	private static $assets = false;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3AC_DB_VERSION );
		Install::seed_defaults();
		self::schedule();
	}

	/**
	 * Cron events.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( Scheduler::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'dev3ac_five_minutes', Scheduler::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Scheduler::CRON_HOOK );
		wp_clear_scheduled_hook( 'dev3ac_license_check' ); // Licence check from builds before licensing was removed.
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( has_filter( 'dev3ac_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			return;
		}
		add_filter( 'dev3ac_settings_defaults', array( Settings::class, 'defaults' ) );
		add_filter( 'dev3ac_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
		add_filter( 'dev3ac_schema_sql', array( Install::class, 'schema' ), 10, 2 );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 minutes is intended.
	}

	/**
	 * Adds the 5-minute schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['dev3ac_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (DEV3 AI Abandoned Cart)', 'dev3-ai-abandoned-cart-for-woocommerce' ),
		);
		return $schedules;
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		add_action( 'init', array( $this, 'textdomain' ), 1 );
		self::register_schema();
		add_action( 'before_woocommerce_init', array( $this, 'declare_compat' ) );
		add_action(
			'admin_init',
			static function () {
				Schema::maybe_install( DEV3AC_DB_VERSION );
				Install::seed_defaults();
				self::schedule();
			}
		);
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( self::CRON_HOOK, array( Privacy_Data::class, 'daily' ) );
		add_action( Scheduler::CRON_HOOK, array( Scheduler::class, 'sweep' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', array( Ai\Copywriter::class, 'register_prompts' ), 5 );
		add_action( 'init', array( Privacy_Data::class, 'register' ) );
		Privacy::init();
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'needs_woo' ) );
			return;
		}
		( new Capture\Tracker() )->register();
		( new Frontend\Checkout() )->register();
		( new Frontend\Recovery() )->register();
		( new Frontend\Save_Cart() )->register();
	}

	/**
	 * Translations (on init: earlier triggers WP 6.7's "loaded too early" notice).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-abandoned-cart-for-woocommerce', false, dirname( plugin_basename( DEV3AC_FILE ) ) . '/languages' );
	}

	/**
	 * Registers bundles once (Assets::register adds the boot script on every call).
	 */
	public static function register_assets() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		Assets::register( array( 'admin', 'storefront', 'blocks' ) );
	}

	/**
	 * HPOS + Cart/Checkout blocks compatibility.
	 */
	public function declare_compat() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3AC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3AC_FILE, true );
		}
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function needs_woo() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'DEV3 AI Abandoned Cart & Win-back Emails needs WooCommerce to be active.', 'dev3-ai-abandoned-cart-for-woocommerce' ) . '</p></div>';
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Admin_Controller() )->register_routes();
		if ( class_exists( 'WooCommerce' ) ) {
			( new Rest\Public_Controller() )->register_routes();
		}
	}
}
