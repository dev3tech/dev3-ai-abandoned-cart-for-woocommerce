<?php
/**
 * REST permissions and admin behaviour.
 *
 * @package Dev3\AiAbandonedCart
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiAbandonedCart\Data\Carts;
use Dev3\AiAbandonedCart\Data\Messages;
use Dev3\AiAbandonedCart\Data\Templates;
use Dev3\AiAbandonedCart\Engine\Quality;
use Dev3\AiAbandonedCart\Engine\Scheduler;
use Dev3\AiAbandonedCart\Settings;

class RestTest extends Dev3ac_Base_Case {

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	private function call( $method, $route, array $params = array(), $nonce = true, array $headers = array() ) {
		$request = new WP_REST_Request( $method, '/dev3ac/v1/' . ltrim( $route, '/' ) );
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		foreach ( $headers as $k => $v ) {
			$request->set_header( $k, $v );
		}
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return rest_do_request( $request );
	}

	private function admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_admin_routes_need_manage_options_and_nonce() {
		$routes = array( array( 'GET', 'bootstrap' ), array( 'POST', 'config' ), array( 'GET', 'dashboard' ), array( 'GET', 'carts' ), array( 'GET', 'messages' ), array( 'GET', 'review' ), array( 'POST', 'sequences' ), array( 'POST', 'templates' ), array( 'POST', 'templates/preview' ), array( 'POST', 'sweep' ), array( 'POST', 'ai/template' ), array( 'POST', 'ai/insights' ), array( 'POST', 'sms/test' ) );
		wp_set_current_user( 0 );
		foreach ( $routes as $r ) {
			$this->assertContains( $this->call( $r[0], $r[1] )->get_status(), array( 401, 403 ), "anon {$r[1]}" );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		foreach ( $routes as $r ) {
			$this->assertContains( $this->call( $r[0], $r[1] )->get_status(), array( 401, 403 ), "shop manager {$r[1]}" );
		}
		$this->admin();
		$this->assertSame( 403, $this->call( 'GET', 'bootstrap', array(), false )->get_status(), 'Nonce is required.' );
		$this->assertSame( 200, $this->call( 'GET', 'bootstrap' )->get_status() );
	}

	public function test_bootstrap_and_config_never_expose_secrets() {
		$this->admin();
		$res = $this->call(
			'POST',
			'config',
			array(
				'sms_auth_token' => 'super-secret-token',
				'api_key'        => 'AIzaSECRETKEY123456789',
				'cutoff_minutes' => 2,
				'consent_mode'   => 'bogus',
			)
		);
		$json = wp_json_encode( $res->get_data() );
		$this->assertStringNotContainsString( 'super-secret-token', $json );
		$this->assertStringNotContainsString( 'AIzaSECRETKEY123456789', $json );
		$this->assertTrue( $res->get_data()['sms_token_set'] );
		$this->assertSame( 5, $res->get_data()['cutoff_minutes'], 'Clamped.' );
		$this->assertSame( 'notice', $res->get_data()['consent_mode'] );
		$this->assertSame( 'super-secret-token', Settings::sms_token() );
		$boot = wp_json_encode( $this->call( 'GET', 'bootstrap' )->get_data() );
		$this->assertStringNotContainsString( 'super-secret-token', $boot );
		// The masked value round-trips without overwriting.
		$this->call( 'POST', 'config', array( 'sms_auth_token' => '••••••••oken' ) );
		$this->assertSame( 'super-secret-token', Settings::sms_token() );
	}

	public function test_carts_list_detail_and_actions() {
		$this->admin();
		$cart = $this->cart();
		$list = $this->call( 'GET', 'carts', array( 'status' => 'active' ) )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( 'Ann Lee', $list['rows'][0]['name'] );
		$this->assertSame( 0, $this->call( 'GET', 'carts', array( 'search' => 'nobody' ) )->get_data()['total'] );
		$res = $this->call( 'POST', "carts/{$cart['id']}/action", array( 'action' => 'abandon' ) );
		$this->assertSame( 'abandoned', $res->get_data()['status'] );
		$res = $this->call( 'POST', "carts/{$cart['id']}/action", array( 'action' => 'send_now' ) );
		$this->assertSame( 'sent', $res->get_data()['result'] );
		$detail = $this->call( 'GET', "carts/{$cart['id']}" )->get_data();
		$this->assertSame( 'sent', $detail['messages'][0]['status'] );
		$this->assertStringContainsString( 'dev3ac_recover=', $detail['link'] );
		$msg = $this->call( 'GET', 'messages/' . $detail['messages'][0]['id'] )->get_data();
		$this->assertStringNotContainsString( 't/o', $msg['body'], 'Admin preview strips the open pixel.' );
		$this->assertSame( 400, $this->call( 'POST', "carts/{$cart['id']}/action", array( 'action' => 'explode' ) )->get_status() );
		$this->assertTrue( $this->call( 'POST', "carts/{$cart['id']}/action", array( 'action' => 'delete' ) )->get_data()['deleted'] );
		$this->assertSame( 404, $this->call( 'GET', "carts/{$cart['id']}" )->get_status() );
	}

	public function test_templates_save_approve_delete_and_preview() {
		$this->admin();
		$res = $this->call( 'POST', 'templates', array( 'template' => array( 'name' => 'Promo', 'subject' => '', 'body' => 'Hi', 'approve' => true ) ) );
		$this->assertSame( 400, $res->get_status(), 'Cannot approve a template with errors.' );
		$res = $this->call( 'POST', 'templates', array( 'template' => array( 'name' => 'Promo', 'subject' => 'FREE MONEY!!!', 'body' => '<p>Hi {first_name}</p><script>x</script>' ) ) );
		$tpl = $res->get_data()['template'];
		$this->assertSame( 'draft', $tpl['status'] );
		$this->assertStringNotContainsString( '<script>', $tpl['body'] );
		$codes = wp_list_pluck( $res->get_data()['quality'], 'code' );
		$this->assertContains( 'caps', $codes );
		$this->assertContains( 'punctuation', $codes );
		$this->assertContains( 'spam_words', $codes );
		$this->assertSame( 'approved', $this->call( 'POST', 'templates', array( 'template' => array( 'id' => $tpl['id'], 'approve' => true ) ) )->get_data()['template']['status'] );

		$prev = $this->call( 'POST', 'templates/preview', array( 'template' => Templates::get( 'tpl_offer' ), 'coupon' => true ) )->get_data();
		$this->assertSame( 'email', $prev['channel'] );
		$this->assertStringContainsString( 'COMEBACK-PREVIEW', $prev['html'] );
		$sms = $this->call( 'POST', 'templates/preview', array( 'template' => Templates::get( 'tpl_sms' ) ) )->get_data();
		$this->assertStringContainsString( 'dev3ac_recover=', $sms['sms'] );

		$this->assertSame( 409, $this->call( 'DELETE', 'templates/tpl_reminder' )->get_status(), 'In use by a sequence.' );
		$this->assertSame( 200, $this->call( 'DELETE', 'templates/' . $tpl['id'] )->get_status() );
		$this->assertNull( Templates::get( $tpl['id'] ) );
	}

	public function test_sequences_validation() {
		$this->admin();
		$bad = array(
			array(
				'id'      => 'seq_cart',
				'name'    => 'X',
				'type'    => 'cart',
				'enabled' => true,
				'steps'   => array( array( 'channel' => 'sms', 'template_id' => 'tpl_reminder' ) ),
			),
		);
		$this->assertSame( 400, $this->call( 'POST', 'sequences', array( 'sequences' => $bad ) )->get_status() );
		$two = array(
			array( 'id' => 'a', 'name' => 'A', 'type' => 'cart', 'enabled' => true, 'steps' => array( array( 'template_id' => 'tpl_reminder', 'delay_value' => 5000 ) ) ),
			array( 'id' => 'b', 'name' => 'B', 'type' => 'cart', 'enabled' => true, 'steps' => array( array( 'template_id' => 'tpl_last' ) ) ),
		);
		$out = $this->call( 'POST', 'sequences', array( 'sequences' => $two ) )->get_data()['sequences'];
		$this->assertTrue( $out[0]['enabled'] );
		$this->assertFalse( $out[1]['enabled'], 'Only one enabled sequence per type.' );
		$this->assertSame( 1000, $out[0]['steps'][0]['delay_value'] );
	}

	public function test_review_queue_and_ai_routes() {
		$this->admin();
		$this->ai_on();
		$msg = $this->due_message( $this->cart() );
		Scheduler::prepare_ai( 5 );
		$queue = $this->call( 'GET', 'review' )->get_data();
		$this->assertCount( 1, $queue['rows'] );
		$this->assertSame( 'Cart · Friendly reminder', $queue['rows'][0]['template']['name'] );
		$this->assertSame( 'Ann, your Linen Shirt misses you', $queue['rows'][0]['draft']['subject'] );
		$this->assertSame( 400, $this->call( 'POST', 'review/' . $msg['id'], array( 'decision' => 'maybe' ) )->get_status() );
		$regen = $this->call( 'POST', 'review/' . $msg['id'] . '/regenerate' )->get_data();
		$this->assertSame( 'review', $regen['ai_state'] );
		$this->assertSame( 200, $this->call( 'POST', 'review/' . $msg['id'], array( 'decision' => 'approve' ) )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', 'review/' . $msg['id'], array( 'decision' => 'approve' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', 'ai/template', array( 'goal' => '' ) )->get_status() );
		$this->http['generativelanguage'] = $this->gemini(
			array(
				'headline' => 'One cart is waiting.',
				'findings' => array( 'Linen Shirt is the most abandoned product.' ),
				'actions'  => array( 'Add a coupon to step 2.' ),
			)
		);
		$insights                         = $this->call( 'POST', 'ai/insights', array( 'days' => 7 ) )->get_data();
		$this->assertSame( 'One cart is waiting.', $insights['headline'] );
		$this->assertSame( array( 'Add a coupon to step 2.' ), $insights['actions'] );
	}

	public function test_dashboard_numbers_match_orders() {
		$this->admin();
		$a = $this->cart( array( 'total' => 100 ) );
		$b = $this->cart(
			array(
				'total' => 50,
				'email' => 'bob@example.com',
			)
		);
		Scheduler::abandon( $a, time() );
		Scheduler::abandon( $b, time() );
		Scheduler::send_now( Carts::get( $a['id'] ) );
		$order = wc_create_order();
		$order->set_billing_email( 'ann@example.com' );
		$order->set_total( 95 );
		$order->update_meta_data( '_dev3ac_cart_id', $a['id'] );
		$order->save();
		$order->update_status( 'completed' );
		$d = $this->call( 'GET', 'dashboard', array( 'days' => 30 ) )->get_data();
		$this->assertSame( 2, $d['kpis']['abandoned'] );
		$this->assertSame( 1, $d['kpis']['recovered'] );
		$this->assertEquals( 95, $d['kpis']['recovered_value'] );
		$this->assertEquals( 50.0, $d['kpis']['recovery_rate'] );
		$this->assertEquals( 50, $d['kpis']['recoverable_value'], 'Cart B is still in its sequence.' );
		$this->assertSame( 1, $d['kpis']['messages_sent'] );
		$this->assertCount( 30, $d['series'] );
		$this->assertEquals( 95, array_sum( wp_list_pluck( $d['series'], 'recovered_value' ) ) );
		$this->assertSame( 1, $d['funnel'][0]['recovered'] );
		$this->assertSame( 'Linen Shirt', $d['top_products'][0]['name'] );
	}

	public function test_public_capture_is_same_origin_and_leaks_nothing() {
		wp_set_current_user( 0 );
		$res = $this->call( 'POST', 'capture', array( 'email' => 'x@example.com' ), false, array( 'origin' => 'https://evil.example' ) );
		$this->assertSame( 403, $res->get_status() );
		$res = $this->call( 'POST', 'capture', array( 'email' => 'x@example.com' ), false );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'captured' => false ), $res->get_data(), 'Empty cart: nothing captured, nothing returned.' );
		$res = $this->call( 'POST', 'save-cart', array( 'email' => 'nope' ), false );
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_quality_checks_sms() {
		$issues = wp_list_pluck( Quality::check( array( 'channel' => 'sms', 'sms' => str_repeat( 'a', 170 ) ) ), 'code' );
		$this->assertContains( 'sms_link', $issues );
		$this->assertContains( 'sms_long', $issues );
		$this->assertSame( array(), Quality::check( Templates::get( 'tpl_sms' ) ) );
	}
}
