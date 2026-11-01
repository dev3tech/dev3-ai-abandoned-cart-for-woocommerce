<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Admin;

use Dev3\AiAbandonedCart\Core3\Core\Assets;
use Dev3\AiAbandonedCart\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3AC_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$count = \Dev3\AiAbandonedCart\Data\Messages::review_count();
		$title = __( 'DEV3 AI Abandoned Cart', 'dev3-ai-abandoned-cart-for-woocommerce' );
		if ( $count ) {
			$title .= ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>';
		}
		$this->hook = (string) add_menu_page( DEV3AC_NAME, $title, Capabilities::manage(), DEV3AC_SLUG, array( $this, 'render' ), 'dashicons-cart', 56 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3ac-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3AC_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3ac-admin-root" class="dev3ac-root dev3ac-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3AC_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-abandoned-cart-for-woocommerce' ) . '</a>' );
		return $links;
	}
}
