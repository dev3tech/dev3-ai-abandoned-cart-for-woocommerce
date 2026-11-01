<?php
/**
 * Shared set-up: clean tables/options, default templates + sequences, a WooCommerce session and fake HTTP.
 *
 * @package Dev3\AiAbandonedCart
 */

use Dev3\AiAbandonedCart\Core3\Api\Ai;
use Dev3\AiAbandonedCart\Core3\Core\Cache;
use Dev3\AiAbandonedCart\Core3\Core\Schema;
use Dev3\AiAbandonedCart\Core3\Core\Settings_Store;
use Dev3\AiAbandonedCart\Core3\Data\Usage_Repository;
use Dev3\AiAbandonedCart\Data\Carts;
use Dev3\AiAbandonedCart\Data\Sequences;
use Dev3\AiAbandonedCart\Data\Templates;
use Dev3\AiAbandonedCart\Engine\Scheduler;
use Dev3\AiAbandonedCart\Engine\Send_Time;
use Dev3\AiAbandonedCart\Install;

abstract class Dev3ac_Base_Case extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Fake responses: url substring => [ status, body ] or callable.
	 *
	 * @var array
	 */
	protected $http = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3AC_DB_VERSION );
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Install::carts_table() ); // phpcs:ignore
		$wpdb->query( 'TRUNCATE TABLE ' . Install::messages_table() ); // phpcs:ignore
		foreach ( array( Templates::OPTION, Sequences::OPTION, 'dev3ac_suppressions', 'dev3ac_sms_token', 'dev3ac_sweep_lock', 'dev3ac_winback_last' ) as $opt ) {
			delete_option( $opt );
		}
		update_option( Settings_Store::OPTION, array( 'send_time_tuning' => false ) );
		Settings_Store::flush();
		Usage_Repository::flush();
		Cache::reset_memo();
		Ai::$client = null;
		Templates::flush();
		Sequences::flush();
		Send_Time::flush();
		Install::seed_defaults();
		Scheduler::$now = null;
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		$this->requests = array();
		$this->http     = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'dev3ac_retry_delay_ms', '__return_zero' );
		reset_phpmailer_instance();
		if ( function_exists( 'WC' ) ) {
			WC()->initialize_session();
			WC()->initialize_cart();
			WC()->cart->empty_cart();
			WC()->session->set( 'dev3ac_cart_id', null );
			WC()->session->set( 'dev3ac_hash', null );
			WC()->mailer()->init();
		}
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		Scheduler::$now = null;
		parent::tear_down();
	}

	/**
	 * Updates settings.
	 *
	 * @param array $values Values.
	 */
	protected function settings( array $values ) {
		Settings_Store::update( $values );
		Settings_Store::flush();
	}

	/**
	 * Gemini-shaped JSON response.
	 *
	 * @param array $data Structured output.
	 * @return array
	 */
	protected function gemini( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 120,
					'candidatesTokenCount' => 80,
					'totalTokenCount'      => 200,
				),
			),
		);
	}

	/**
	 * Turns AI on with a fake key and a fake cart-copy answer.
	 *
	 * @param array $data Response data.
	 */
	protected function ai_on( array $data = array() ) {
		$this->settings( array( 'api_key' => 'AIzaTESTKEY1234567890' ) );
		$this->http['generativelanguage'] = $this->gemini(
			$data ? $data : array(
				'subject'   => 'Ann, your Linen Shirt misses you',
				'preheader' => 'Still in your size',
				'heading'   => 'Come back for it',
				'intro'     => 'Hi Ann, your Linen Shirt is saved. <script>alert(1)</script>',
				'button'    => 'See my shirt',
				'sms'       => 'Ann, your shirt is saved: {recovery_url}',
			)
		);
	}

	/**
	 * pre_http_request handler.
	 *
	 * @param mixed  $pre  Pre.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return array|\WP_Error
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
			'body' => isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : ( isset( $args['body'] ) ? $args['body'] : null ),
		);
		foreach ( $this->http as $needle => $reply ) {
			if ( false !== strpos( $url, $needle ) ) {
				if ( is_callable( $reply ) ) {
					$reply = $reply( $url, $args );
				}
				return array(
					'headers'  => array(),
					'body'     => is_string( $reply[1] ) ? $reply[1] : wp_json_encode( $reply[1] ),
					'response' => array(
						'code'    => $reply[0],
						'message' => 'x',
					),
					'cookies'  => array(),
				);
			}
		}
		return new WP_Error( 'http_blocked', 'No fake for ' . $url );
	}

	/**
	 * Simple product.
	 *
	 * @param string $price Price.
	 * @param string $name  Name.
	 * @return WC_Product_Simple
	 */
	protected function product( $price = '40', $name = 'Linen Shirt' ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( $price );
		$p->save();
		return wc_get_product( $p->get_id() );
	}

	/**
	 * Inserts a cart row directly.
	 *
	 * @param array $over Overrides.
	 * @return array Cart.
	 */
	protected function cart( array $over = array() ) {
		$product = isset( $over['_product'] ) ? $over['_product'] : $this->product();
		unset( $over['_product'] );
		$id = Carts::insert(
			array_merge(
				array(
					'session_key' => 'sess_' . wp_generate_password( 6, false ),
					'email'       => 'ann@example.com',
					'first_name'  => 'Ann',
					'last_name'   => 'Lee',
					'phone'       => '+15555550100',
					'country'     => 'US',
					'items'       => array(
						array(
							'product_id'   => $product->get_id(),
							'variation_id' => 0,
							'quantity'     => 2,
							'variation'    => array(),
							'name'         => $product->get_name(),
							'line_total'   => 80,
						),
					),
					'item_count'  => 2,
					'total'       => 80,
					'currency'    => 'USD',
					'consent'     => 'notice',
					'source'      => 'classic',
					'updated_at'  => Carts::now( time() - HOUR_IN_SECONDS ),
					'created_at'  => Carts::now( time() - HOUR_IN_SECONDS ),
				),
				$over
			)
		);
		return Carts::get( $id );
	}

	/**
	 * Abandons a cart and returns its first scheduled message moved to "due".
	 *
	 * @param array $cart Cart.
	 * @return array Message.
	 */
	protected function due_message( array $cart ) {
		Scheduler::abandon( $cart, time() );
		$msgs = \Dev3\AiAbandonedCart\Data\Messages::for_cart( $cart['id'] );
		$msg  = end( $msgs );
		\Dev3\AiAbandonedCart\Data\Messages::update( $msg['id'], array( 'scheduled_at' => Carts::now( time() - 60 ) ) );
		return \Dev3\AiAbandonedCart\Data\Messages::get( $msg['id'] );
	}

	/**
	 * Mails sent through the mock PHPMailer.
	 *
	 * @return array
	 */
	protected function mails() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}
}
