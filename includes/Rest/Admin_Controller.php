<?php
/**
 * Admin REST routes (manage_options + nonce on every route).
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Rest;

use Dev3\AiAbandonedCart\Ai\Copywriter;
use Dev3\AiAbandonedCart\Core3\Api\Ai;
use Dev3\AiAbandonedCart\Core3\Core\Settings_Store;
use Dev3\AiAbandonedCart\Core3\Rest\Base_Controller;
use Dev3\AiAbandonedCart\Data\Carts;
use Dev3\AiAbandonedCart\Data\Messages;
use Dev3\AiAbandonedCart\Data\Sequences;
use Dev3\AiAbandonedCart\Data\Suppressions;
use Dev3\AiAbandonedCart\Data\Templates;
use Dev3\AiAbandonedCart\Engine\Coupons;
use Dev3\AiAbandonedCart\Engine\Links;
use Dev3\AiAbandonedCart\Engine\Mailer;
use Dev3\AiAbandonedCart\Engine\Quality;
use Dev3\AiAbandonedCart\Engine\Renderer;
use Dev3\AiAbandonedCart\Engine\Scheduler;
use Dev3\AiAbandonedCart\Engine\Send_Time;
use Dev3\AiAbandonedCart\Engine\Sms;
use Dev3\AiAbandonedCart\Engine\Stats;
use Dev3\AiAbandonedCart\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3ac/v1.
 */
class Admin_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$routes = array(
			array( '/bootstrap', 'GET', 'bootstrap' ),
			array( '/config', 'POST', 'save_config' ),
			array( '/dashboard', 'GET', 'dashboard' ),
			array( '/carts', 'GET', 'carts' ),
			array( '/carts/(?P<id>\d+)', 'GET', 'cart' ),
			array( '/carts/(?P<id>\d+)/action', 'POST', 'cart_action' ),
			array( '/messages', 'GET', 'messages' ),
			array( '/messages/(?P<id>\d+)', 'GET', 'message' ),
			array( '/review', 'GET', 'review' ),
			array( '/review/(?P<id>\d+)', 'POST', 'review_decision' ),
			array( '/review/(?P<id>\d+)/regenerate', 'POST', 'review_regenerate' ),
			array( '/sequences', 'POST', 'save_sequences' ),
			array( '/templates', 'POST', 'save_template' ),
			array( '/templates/(?P<id>[a-z0-9_]+)', 'DELETE', 'delete_template' ),
			array( '/templates/preview', 'POST', 'preview' ),
			array( '/templates/test', 'POST', 'test_send' ),
			array( '/sms/test', 'POST', 'sms_test' ),
			array( '/sweep', 'POST', 'sweep' ),
			array( '/ai/template', 'POST', 'ai_template' ),
			array( '/ai/sequence', 'POST', 'ai_sequence' ),
			array( '/ai/send-time', 'POST', 'ai_send_time' ),
			array( '/ai/insights', 'POST', 'ai_insights' ),
		);
		foreach ( $routes as $r ) {
			register_rest_route(
				self::NS,
				$r[0],
				array(
					'methods'             => $r[1],
					'callback'            => array( $this, $r[2] ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
	}

	/**
	 * GET bootstrap.
	 *
	 * @return \WP_REST_Response
	 */
	public function bootstrap() {
		$roles = array();
		foreach ( wp_roles()->get_names() as $key => $label ) {
			$roles[] = array(
				'value' => $key,
				'label' => translate_user_role( $label ),
			);
		}
		return $this->respond(
			array(
				'settings'     => Settings::for_admin(),
				'sequences'    => array_values( Sequences::all() ),
				'templates'    => array_values( Templates::all() ),
				'counts'       => Carts::counts(),
				'review_count' => Messages::review_count(),
				'merge_tags'   => Renderer::TAGS,
				'roles'        => $roles,
				'sms_ready'    => Sms::ready(),
				'ai'           => Ai::status(),
				'suppressed'   => Suppressions::count(),
				'woo'          => class_exists( 'WooCommerce' ),
				'store'        => array(
					'name'     => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
					'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
					'symbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '$',
					'tz'       => wp_timezone_string(),
					'offset'   => Send_Time::store_offset(),
				),
				'cron'         => array(
					'last' => (int) get_option( 'dev3ac_last_sweep', 0 ),
					'next' => (int) wp_next_scheduled( Scheduler::CRON_HOOK ),
				),
			)
		);
	}

	/**
	 * POST config: saves settings (shared + plugin) and returns browser-safe settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_config( \WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		if ( ! $params ) {
			$params = (array) $request->get_body_params();
		}
		Settings_Store::update( $params );
		Send_Time::flush();
		return $this->respond( Settings::for_admin() );
	}

	/**
	 * GET dashboard.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function dashboard( \WP_REST_Request $request ) {
		return $this->respond( Stats::dashboard( (int) $request->get_param( 'days' ) ) );
	}

	/**
	 * GET carts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function carts( \WP_REST_Request $request ) {
		$result = Carts::query(
			array(
				'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
				'kind'     => sanitize_key( (string) $request->get_param( 'kind' ) ),
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
			)
		);
		return $this->respond(
			array(
				'rows'   => array_map( array( __CLASS__, 'cart_out' ), $result['rows'] ),
				'total'  => $result['total'],
				'counts' => Carts::counts(),
			)
		);
	}

	/**
	 * GET carts/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function cart( \WP_REST_Request $request ) {
		$cart = Carts::get( (int) $request['id'] );
		if ( ! $cart ) {
			return $this->not_found();
		}
		$out             = self::cart_out( $cart );
		$out['messages'] = array_map( array( __CLASS__, 'message_out' ), Messages::for_cart( $cart['id'] ) );
		$out['link']     = Links::recovery( $cart, 0 );
		return $this->respond( $out );
	}

	/**
	 * POST carts/<id>/action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function cart_action( \WP_REST_Request $request ) {
		$cart = Carts::get( (int) $request['id'] );
		if ( ! $cart ) {
			return $this->not_found();
		}
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		switch ( $action ) {
			case 'send_now':
				$result = Scheduler::send_now( $cart );
				if ( is_wp_error( $result ) ) {
					return $this->respond( $result );
				}
				break;
			case 'stop':
				Scheduler::stop( $cart );
				$result = 'stopped';
				break;
			case 'restart':
				Scheduler::restart( $cart );
				$result = 'restarted';
				break;
			case 'abandon':
				if ( 'active' !== $cart['status'] ) {
					return $this->respond( new \WP_Error( 'dev3ac_not_active', __( 'Only active carts can be marked abandoned.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) ) );
				}
				Scheduler::abandon( $cart, Scheduler::time() );
				$result = 'abandoned';
				break;
			case 'delete':
				Carts::delete( $cart['id'] );
				return $this->respond(
					array(
						'deleted' => true,
						'counts'  => Carts::counts(),
					)
				);
			default:
				return $this->respond( new \WP_Error( 'dev3ac_bad_action', __( 'Unknown action.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$fresh             = Carts::get( $cart['id'] );
		$out               = self::cart_out( $fresh );
		$out['messages']   = array_map( array( __CLASS__, 'message_out' ), Messages::for_cart( $cart['id'] ) );
		$out['link']       = Links::recovery( $fresh, 0 );
		$out['result']     = $result;
		$out['counts']     = Carts::counts();
		$out['reviewNext'] = Messages::review_count();
		return $this->respond( $out );
	}

	/**
	 * GET messages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function messages( \WP_REST_Request $request ) {
		$result = Messages::query(
			array(
				'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
				'channel'  => sanitize_key( (string) $request->get_param( 'channel' ) ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
			)
		);
		return $this->respond(
			array(
				'rows'  => array_map(
					static function ( $m ) {
						$out = self::message_out( $m );
						unset( $out['body'] );
						$out['name'] = trim( (string) ( isset( $m['first_name'] ) ? $m['first_name'] : '' ) . ' ' . (string) ( isset( $m['last_name'] ) ? $m['last_name'] : '' ) );
						$out['kind'] = isset( $m['kind'] ) ? (string) $m['kind'] : 'cart';
						return $out;
					},
					$result['rows']
				),
				'total' => $result['total'],
			)
		);
	}

	/**
	 * GET messages/<id> (with the sent body).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function message( \WP_REST_Request $request ) {
		$msg = Messages::get( (int) $request['id'] );
		return $msg ? $this->respond( self::message_out( $msg ) ) : $this->not_found();
	}

	/**
	 * GET review: AI drafts waiting for a decision, with the template copy to compare.
	 *
	 * @return \WP_REST_Response
	 */
	public function review() {
		$rows = array();
		foreach ( Messages::review_queue() as $msg ) {
			$rows[] = self::review_out( $msg );
		}
		return $this->respond(
			array(
				'rows'     => array_values( array_filter( $rows ) ),
				'fallback' => Settings::get( 'review_fallback' ),
				'hold'     => (int) Settings::get( 'review_hold_hours' ),
			)
		);
	}

	/**
	 * POST review/<id>: approve (optionally edited) or reject (send the template copy).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function review_decision( \WP_REST_Request $request ) {
		$msg = Messages::get( (int) $request['id'] );
		if ( ! $msg || 'scheduled' !== $msg['status'] || 'review' !== $msg['ai_state'] ) {
			return $this->respond( new \WP_Error( 'dev3ac_not_in_review', __( 'This message is no longer waiting for review.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 409 ) ) );
		}
		$decision = sanitize_key( (string) $request->get_param( 'decision' ) );
		$draft    = (array) $msg['ai_draft'];
		if ( 'approve' === $decision ) {
			$copy           = $request->get_param( 'copy' );
			$draft['final'] = Copywriter::clean_copy( is_array( $copy ) ? array_merge( (array) $draft['draft'], $copy ) : (array) $draft['draft'] );
			Messages::update(
				$msg['id'],
				array(
					'ai_state'    => 'approved',
					'ai_draft'    => $draft,
					'reviewed_by' => get_current_user_id(),
				)
			);
		} elseif ( 'reject' === $decision ) {
			Messages::update(
				$msg['id'],
				array(
					'ai_state'    => 'rejected',
					'reviewed_by' => get_current_user_id(),
				)
			);
		} else {
			return $this->respond( new \WP_Error( 'dev3ac_bad_decision', __( 'Choose approve or reject.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		return $this->respond(
			array(
				'id'           => (int) $msg['id'],
				'ai_state'     => 'approve' === $decision ? 'approved' : 'rejected',
				'review_count' => Messages::review_count(),
			)
		);
	}

	/**
	 * POST review/<id>/regenerate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function review_regenerate( \WP_REST_Request $request ) {
		$msg = Messages::get( (int) $request['id'] );
		if ( ! $msg || 'scheduled' !== $msg['status'] ) {
			return $this->not_found();
		}
		$result = Scheduler::generate( $msg, 'admin' );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		$fresh = Messages::get( $msg['id'] );
		if ( 'auto' === $fresh['ai_state'] ) {
			// Regenerating from the queue always asks for a fresh review.
			Messages::update( $fresh['id'], array( 'ai_state' => 'review' ) );
			$fresh = Messages::get( $msg['id'] );
		}
		return $this->respond( self::review_out( $fresh ) );
	}

	/**
	 * POST sequences.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_sequences( \WP_REST_Request $request ) {
		$list = $request->get_param( 'sequences' );
		if ( ! is_array( $list ) ) {
			return $this->respond( new \WP_Error( 'dev3ac_bad_sequences', __( 'Sequences are missing.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		foreach ( $list as $seq ) {
			foreach ( isset( $seq['steps'] ) && is_array( $seq['steps'] ) ? $seq['steps'] : array() as $step ) {
				$tpl = Templates::get( isset( $step['template_id'] ) ? sanitize_key( (string) $step['template_id'] ) : '' );
				if ( ! $tpl ) {
					return $this->respond( new \WP_Error( 'dev3ac_bad_template', __( 'Every step needs a template.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) ) );
				}
				$channel = isset( $step['channel'] ) && 'sms' === $step['channel'] ? 'sms' : 'email';
				if ( $channel !== $tpl['channel'] ) {
					/* translators: %s: template name */
					return $this->respond( new \WP_Error( 'dev3ac_channel_mismatch', sprintf( __( 'The template "%s" does not match the step channel.', 'dev3-ai-abandoned-cart-for-woocommerce' ), $tpl['name'] ), array( 'status' => 400 ) ) );
				}
			}
		}
		return $this->respond( array( 'sequences' => array_values( Sequences::save_all( $list ) ) ) );
	}

	/**
	 * POST templates (create/update; `approve: true` approves).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_template( \WP_REST_Request $request ) {
		$input = $request->get_param( 'template' );
		if ( ! is_array( $input ) ) {
			return $this->respond( new \WP_Error( 'dev3ac_bad_template', __( 'Template data is missing.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		if ( ! empty( $input['approve'] ) ) {
			$existing = ! empty( $input['id'] ) ? Templates::get( sanitize_key( (string) $input['id'] ) ) : null;
			$errors   = array_filter( Quality::check( Templates::sanitize( array_merge( $existing ? $existing : array(), $input ) ) ), static fn( $i ) => 'error' === $i['level'] );
			if ( $errors ) {
				return $this->respond( new \WP_Error( 'dev3ac_template_invalid', reset( $errors )['message'], array( 'status' => 400 ) ) );
			}
		}
		$tpl = Templates::upsert( $input );
		return $this->respond(
			array(
				'template'  => $tpl,
				'quality'   => self::quality( $tpl ),
				'templates' => array_values( Templates::all() ),
			)
		);
	}

	/**
	 * DELETE templates/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_template( \WP_REST_Request $request ) {
		$id = sanitize_key( (string) $request['id'] );
		foreach ( Sequences::all() as $seq ) {
			foreach ( $seq['steps'] as $step ) {
				if ( $step['template_id'] === $id ) {
					/* translators: %s: sequence name */
					return $this->respond( new \WP_Error( 'dev3ac_template_in_use', sprintf( __( 'This template is used by the "%s" sequence. Change that step first.', 'dev3-ai-abandoned-cart-for-woocommerce' ), $seq['name'] ), array( 'status' => 409 ) ) );
				}
			}
		}
		if ( ! Templates::delete( $id ) ) {
			return $this->not_found();
		}
		return $this->respond( array( 'templates' => array_values( Templates::all() ) ) );
	}

	/**
	 * POST templates/preview: renders unsaved template fields with a demo (or real) cart.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preview( \WP_REST_Request $request ) {
		$tpl  = Templates::sanitize( (array) $request->get_param( 'template' ) );
		$cart = $request->get_param( 'cart_id' ) ? Carts::get( (int) $request->get_param( 'cart_id' ) ) : null;
		$cart = $cart ? $cart : Renderer::demo_cart();
		$copy = Renderer::template_copy( $tpl );
		$ai   = $request->get_param( 'ai' );
		if ( is_array( $ai ) ) {
			$copy = Renderer::ai_copy( $tpl, Copywriter::clean_copy( $ai ) );
		}
		$coupon = $request->get_param( 'coupon' ) ? Coupons::sample() : null;
		if ( 'sms' === $tpl['channel'] ) {
			$text = Renderer::sms( $cart, $copy, 0, $coupon );
			return $this->respond(
				array(
					'channel' => 'sms',
					'sms'     => $text,
					'length'  => mb_strlen( $text ),
					'quality' => self::quality( $tpl ),
				)
			);
		}
		$email = Renderer::email( $cart, $tpl, $copy, 0, $coupon );
		return $this->respond(
			array(
				'channel'   => 'email',
				'subject'   => $email['subject'],
				'preheader' => $email['preheader'],
				'html'      => $email['html'],
				'quality'   => self::quality( $tpl ),
			)
		);
	}

	/**
	 * POST templates/test: sends the (unsaved) template to an address or phone.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function test_send( \WP_REST_Request $request ) {
		$tpl  = Templates::sanitize( (array) $request->get_param( 'template' ) );
		$to   = sanitize_text_field( (string) $request->get_param( 'to' ) );
		$cart = Renderer::demo_cart();
		$copy = Renderer::template_copy( $tpl );
		if ( 'sms' === $tpl['channel'] ) {
			$phone = Sms::normalize( $to, (string) get_option( 'woocommerce_default_country', '' ) );
			$res   = Sms::send( $phone, '[TEST] ' . Renderer::sms( $cart, $copy, 0, Coupons::sample() ) );
		} else {
			$email = Renderer::email( $cart, $tpl, $copy, 0, Coupons::sample() );
			$res   = Mailer::send( sanitize_email( $to ), '[TEST] ' . $email['subject'], $email['html'] );
		}
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 400 ) );
			return $this->respond( $res );
		}
		return $this->respond( array( 'sent' => true ) );
	}

	/**
	 * POST sms/test.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function sms_test( \WP_REST_Request $request ) {
		$phone = Sms::normalize( sanitize_text_field( (string) $request->get_param( 'to' ) ), (string) get_option( 'woocommerce_default_country', '' ) );
		/* translators: %s: store name */
		$res = Sms::send( $phone, sprintf( __( '%s: test message from your cart reminders. It works!', 'dev3-ai-abandoned-cart-for-woocommerce' ), html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) ) );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 400 ) );
			return $this->respond( $res );
		}
		return $this->respond(
			array(
				'sent' => true,
				'id'   => $res,
			)
		);
	}

	/**
	 * POST sweep: runs the scheduler now.
	 *
	 * @return \WP_REST_Response
	 */
	public function sweep() {
		$stats = Scheduler::sweep( true );
		return $this->respond(
			array(
				'stats'        => $stats,
				'counts'       => Carts::counts(),
				'review_count' => Messages::review_count(),
				'last'         => (int) get_option( 'dev3ac_last_sweep', 0 ),
			)
		);
	}

	/**
	 * POST ai/template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_template( \WP_REST_Request $request ) {
		$goal = sanitize_textarea_field( (string) $request->get_param( 'goal' ) );
		if ( '' === trim( $goal ) ) {
			return $this->respond( new \WP_Error( 'dev3ac_empty_goal', __( 'Describe what this message should do.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		return $this->respond(
			Copywriter::template(
				array(
					'goal'    => $goal,
					'tone'    => sanitize_key( (string) $request->get_param( 'tone' ) ),
					'offer'   => sanitize_text_field( (string) $request->get_param( 'offer' ) ),
					'channel' => 'sms' === $request->get_param( 'channel' ) ? 'sms' : 'email',
				)
			)
		);
	}

	/**
	 * POST ai/sequence.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_sequence( \WP_REST_Request $request ) {
		$type  = 'winback' === $request->get_param( 'type' ) ? 'winback' : 'cart';
		$dash  = Stats::dashboard( 90 );
		$stats = array(
			'abandoned_carts_90d'     => $dash['kpis']['abandoned'],
			'average_cart_value'      => $dash['kpis']['abandoned'] ? round( $dash['kpis']['abandoned_value'] / $dash['kpis']['abandoned'], 2 ) : 0,
			'recovery_rate_percent'   => $dash['kpis']['recovery_rate'],
			'open_rate_percent'       => $dash['kpis']['open_rate'],
			'click_rate_percent'      => $dash['kpis']['click_rate'],
			'recovered_by_step'       => $dash['funnel'],
			'currency'                => $dash['currency'],
			'top_abandoned_products'  => array_slice( wp_list_pluck( $dash['top_products'], 'name' ), 0, 5 ),
			'winback_inactivity_days' => (int) Settings::get( 'winback_days' ),
		);
		return $this->respond( Copywriter::sequence( $type, $stats, Sms::ready() ) );
	}

	/**
	 * POST ai/send-time.
	 *
	 * @return \WP_REST_Response
	 */
	public function ai_send_time() {
		Send_Time::flush();
		$hist   = Send_Time::store_histogram();
		$result = Copywriter::send_time( $hist );
		$out    = is_wp_error( $result ) ? $result : array_merge( $result, array( 'hours' => $hist ) );
		return $this->respond( $out );
	}

	/**
	 * POST ai/insights.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_insights( \WP_REST_Request $request ) {
		$dash = Stats::dashboard( (int) $request->get_param( 'days' ) );
		if ( ! $dash['kpis']['abandoned'] && ! $dash['kpis']['messages_sent'] ) {
			return $this->respond( new \WP_Error( 'dev3ac_no_data', __( 'There is no cart data for this period yet.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		return $this->respond( Copywriter::insights( $dash ) );
	}

	/**
	 * Quality checks, aware of whether a sequence gives this template a coupon (its step or an earlier one).
	 *
	 * @param array $tpl Template.
	 * @return array
	 */
	private static function quality( array $tpl ) {
		foreach ( Sequences::all() as $seq ) {
			$coupon = false;
			foreach ( $seq['steps'] as $step ) {
				$coupon = $coupon || ! empty( $step['coupon']['enabled'] );
				if ( $step['template_id'] === $tpl['id'] && $coupon ) {
					$tpl['_has_coupon'] = true;
				}
			}
		}
		return Quality::check( $tpl );
	}

	/**
	 * 404.
	 *
	 * @return \WP_REST_Response
	 */
	private function not_found() {
		return $this->respond( new \WP_Error( 'dev3ac_not_found', __( 'Not found.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 404 ) ) );
	}

	/**
	 * Cart for the admin.
	 *
	 * @param array $cart Cart.
	 * @return array
	 */
	public static function cart_out( array $cart ) {
		$order_url = '';
		if ( $cart['order_id'] && function_exists( 'wc_get_order' ) ) {
			$order     = wc_get_order( $cart['order_id'] );
			$order_url = $order ? $order->get_edit_order_url() : '';
		}
		$seq = Sequences::get( $cart['sequence_id'] );
		return array(
			'id'              => (int) $cart['id'],
			'kind'            => $cart['kind'],
			'email'           => $cart['email'],
			'phone'           => $cart['phone'],
			'name'            => trim( $cart['first_name'] . ' ' . $cart['last_name'] ),
			'first_name'      => $cart['first_name'],
			'country'         => $cart['country'],
			'status'          => $cart['status'],
			'items'           => $cart['items'],
			'item_count'      => (int) $cart['item_count'],
			'total'           => (float) $cart['total'],
			'currency'        => $cart['currency'],
			'source'          => $cart['source'],
			'consent'         => $cart['consent'],
			'sms_consent'     => (bool) $cart['sms_consent'],
			'consent_at'      => $cart['consent_at'],
			'tz_offset'       => (int) $cart['tz_offset'],
			'tz_known'        => (bool) $cart['tz_known'],
			'sequence'        => $seq ? $seq['name'] : '',
			'step_index'      => (int) $cart['step_index'],
			'step_count'      => $seq ? count( $seq['steps'] ) : 0,
			'next_send_at'    => $cart['next_send_at'],
			'last_sent_at'    => $cart['last_sent_at'],
			'clicked_at'      => $cart['clicked_at'],
			'coupon_codes'    => $cart['coupon_codes'],
			'order_id'        => (int) $cart['order_id'],
			'order_url'       => $order_url,
			'recovered_total' => (float) $cart['recovered_total'],
			'recovered_step'  => (int) $cart['recovered_step'],
			'coupon_used'     => (bool) $cart['coupon_used'],
			'created_at'      => $cart['created_at'],
			'updated_at'      => $cart['updated_at'],
			'abandoned_at'    => $cart['abandoned_at'],
			'recovered_at'    => $cart['recovered_at'],
		);
	}

	/**
	 * Message for the admin.
	 *
	 * @param array $msg Message.
	 * @return array
	 */
	public static function message_out( array $msg ) {
		$tpl = Templates::get( $msg['template_id'] );
		return array(
			'id'           => (int) $msg['id'],
			'cart_id'      => (int) $msg['cart_id'],
			'step'         => (int) $msg['step_index'] + 1,
			'channel'      => $msg['channel'],
			'template'     => $tpl ? $tpl['name'] : $msg['template_id'],
			'status'       => $msg['status'],
			'ai_state'     => $msg['ai_state'],
			'copy_source'  => $msg['copy_source'],
			'recipient'    => $msg['recipient'],
			'subject'      => $msg['subject'],
			// The admin view must not trigger the open pixel.
			'body'         => (string) preg_replace( '#<img[^>]+(?:/t/o|%2Ft%2Fo)[^>]*>#i', '', (string) $msg['body'] ),
			'coupon_code'  => $msg['coupon_code'],
			'error'        => $msg['error'],
			'scheduled_at' => $msg['scheduled_at'],
			'sent_at'      => $msg['sent_at'],
			'opened_at'    => $msg['opened_at'],
			'clicked_at'   => $msg['clicked_at'],
		);
	}

	/**
	 * Review-queue row.
	 *
	 * @param array $msg Message.
	 * @return array|null
	 */
	public static function review_out( array $msg ) {
		$cart = Carts::get( $msg['cart_id'] );
		$tpl  = Templates::get( $msg['template_id'] );
		if ( ! $cart || ! $tpl || empty( $msg['ai_draft']['draft'] ) ) {
			return null;
		}
		// Compare against the template as this shopper would get it (merge tags filled in).
		$copy = Renderer::template_copy( $tpl );
		$tags = Renderer::tags( $cart, (int) $msg['id'], Coupons::latest_valid( $cart ), 'text' );
		foreach ( array( 'subject', 'preheader', 'heading', 'body', 'button', 'sms' ) as $key ) {
			$copy[ $key ] = Renderer::merge( $copy[ $key ], $tags );
		}
		return array(
			'id'           => (int) $msg['id'],
			'channel'      => $msg['channel'],
			'step'         => (int) $msg['step_index'] + 1,
			'scheduled_at' => $msg['scheduled_at'],
			'template'     => array(
				'id'        => $tpl['id'],
				'name'      => $tpl['name'],
				'subject'   => $copy['subject'],
				'preheader' => $copy['preheader'],
				'heading'   => $copy['heading'],
				'intro'     => wp_strip_all_tags( $copy['body'] ),
				'button'    => $copy['button'],
				'sms'       => $copy['sms'],
			),
			'draft'        => $msg['ai_draft']['draft'],
			'model'        => isset( $msg['ai_draft']['model'] ) ? $msg['ai_draft']['model'] : '',
			'tokens'       => isset( $msg['ai_draft']['tokens'] ) ? (int) $msg['ai_draft']['tokens'] : 0,
			'ai_state'     => $msg['ai_state'],
			'cart'         => array(
				'id'         => (int) $cart['id'],
				'kind'       => $cart['kind'],
				'name'       => trim( $cart['first_name'] . ' ' . $cart['last_name'] ),
				'email'      => $cart['email'],
				'total'      => (float) $cart['total'],
				'currency'   => $cart['currency'],
				'item_count' => (int) $cart['item_count'],
				'items'      => array_map( static fn( $i ) => (int) $i['quantity'] . '× ' . $i['name'], (array) $cart['items'] ),
			),
		);
	}
}
