<?php
/**
 * Storefront REST routes: capture, save-my-cart and the open pixel.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Rest;

use Dev3\AiAbandonedCart\Capture\Tracker;
use Dev3\AiAbandonedCart\Core3\Rest\Base_Controller;
use Dev3\AiAbandonedCart\Data\Messages;
use Dev3\AiAbandonedCart\Engine\Links;
use Dev3\AiAbandonedCart\Frontend\Save_Cart;
use Dev3\AiAbandonedCart\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Public routes never return personal data. No AI runs here.
 */
class Public_Controller extends Base_Controller {

	const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/capture',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'capture' ),
				'permission_callback' => fn( $r ) => $this->public_permission( $r, 'capture', 30 ),
			)
		);
		register_rest_route(
			self::NS,
			'/save-cart',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_cart' ),
				'permission_callback' => fn( $r ) => $this->public_permission( $r, 'save_cart', 6 ),
			)
		);
		register_rest_route(
			self::NS,
			'/t/o',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'open' ),
				'permission_callback' => '__return_true', // Signed per message (HMAC), returns only a 1×1 image.
			)
		);
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_pixel' ), 10, 4 );
	}

	/**
	 * Loads the WooCommerce session + cart in REST context.
	 *
	 * @return bool
	 */
	private function load_cart() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		if ( ( null === WC()->cart || null === WC()->session ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		return (bool) WC()->cart;
	}

	/**
	 * Contact fields from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function contact( \WP_REST_Request $request ) {
		$tz = $request->get_param( 'tz_offset' );
		return array(
			'email'       => sanitize_email( (string) $request->get_param( 'email' ) ),
			'phone'       => sanitize_text_field( (string) $request->get_param( 'phone' ) ),
			'first_name'  => sanitize_text_field( (string) $request->get_param( 'first_name' ) ),
			'last_name'   => sanitize_text_field( (string) $request->get_param( 'last_name' ) ),
			'country'     => sanitize_text_field( (string) $request->get_param( 'country' ) ),
			'locale'      => sanitize_text_field( (string) $request->get_param( 'locale' ) ),
			'consent'     => rest_sanitize_boolean( $request->get_param( 'consent' ) ),
			'sms_consent' => rest_sanitize_boolean( $request->get_param( 'sms_consent' ) ),
			'tz_offset'   => is_numeric( $tz ) ? (int) $tz : 0,
			'tz_known'    => is_numeric( $tz ),
		);
	}

	/**
	 * POST capture (checkout fields changed).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function capture( \WP_REST_Request $request ) {
		if ( ! $this->load_cart() ) {
			return $this->respond( array( 'captured' => false ) );
		}
		$source = 'block' === $request->get_param( 'source' ) ? 'block' : 'classic';
		if ( ! Settings::get( 'block' === $source ? 'capture_block' : 'capture_classic' ) ) {
			return $this->respond( array( 'captured' => false ) );
		}
		$data           = $this->contact( $request );
		$data['source'] = $source;
		if ( 'opt_in' !== Settings::get( 'consent_mode' ) ) {
			$data['consent'] = true;
		}
		$id = Tracker::capture( $data );
		return $this->respond( array( 'captured' => $id > 0 ) );
	}

	/**
	 * POST save-cart (save-my-cart widget).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_cart( \WP_REST_Request $request ) {
		$data = $this->contact( $request );
		if ( ! is_email( $data['email'] ) ) {
			return $this->respond( new \WP_Error( 'dev3ac_bad_email', __( 'Please enter a valid email address.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		if ( 'opt_in' === Settings::get( 'consent_mode' ) && ! $data['consent'] ) {
			return $this->respond( new \WP_Error( 'dev3ac_consent_required', __( 'Please tick the box so we can email you.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		if ( 'opt_in' !== Settings::get( 'consent_mode' ) ) {
			$data['consent'] = true;
		}
		if ( ! $this->load_cart() || WC()->cart->is_empty() ) {
			return $this->respond( new \WP_Error( 'dev3ac_empty_cart', __( 'Your cart is empty.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$data['source'] = 'save_form';
		$id             = Tracker::capture( $data );
		if ( ! $id ) {
			return $this->respond( new \WP_Error( 'dev3ac_not_saved', __( 'We could not save your cart for this email address.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$sent = Save_Cart::send_link( $id );
		return $this->respond(
			array(
				'saved'   => true,
				'emailed' => true === $sent,
				'message' => __( 'Done! Check your inbox for a link to your cart.', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			)
		);
	}

	/**
	 * Records an open for a signed message id.
	 *
	 * @param int    $id  Message id.
	 * @param string $sig Signature.
	 * @return bool
	 */
	public static function track_open( $id, $sig ) {
		$id = (int) $id;
		if ( $id <= 0 || ! Links::verify( (string) $sig, 'open', $id ) ) {
			return false;
		}
		$msg = Messages::get( $id );
		if ( ! $msg || 'sent' !== $msg['status'] ) {
			return false;
		}
		if ( empty( $msg['opened_at'] ) ) {
			Messages::update( $id, array( 'opened_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
		return true;
	}

	/**
	 * GET t/o.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function open( \WP_REST_Request $request ) {
		self::track_open( (int) $request->get_param( 'm' ), sanitize_text_field( (string) $request->get_param( 's' ) ) );
		return new \WP_REST_Response( array( 'pixel' => true ), 200 );
	}

	/**
	 * Serves the open pixel as image/gif instead of JSON.
	 *
	 * @param bool             $served  Served.
	 * @param mixed            $result  Result.
	 * @param \WP_REST_Request $request Request.
	 * @param \WP_REST_Server  $server  Server.
	 * @return bool
	 */
	public function serve_pixel( $served, $result, $request, $server = null ) {
		unset( $result, $server );
		if ( $served || ! $request instanceof \WP_REST_Request || '/' . self::NS . '/t/o' !== $request->get_route() ) {
			return $served;
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: image/gif' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		}
		echo base64_decode( self::PIXEL ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- static 1×1 GIF.
		return true;
	}
}
