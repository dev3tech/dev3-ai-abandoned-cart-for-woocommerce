<?php
/**
 * AI copy: review queue, auto-approve, fallbacks, prompts (Gemini mocked).
 *
 * @package Dev3\AiAbandonedCart
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiAbandonedCart\Ai\Copywriter;
use Dev3\AiAbandonedCart\Data\Carts;
use Dev3\AiAbandonedCart\Data\Messages;
use Dev3\AiAbandonedCart\Data\Templates;
use Dev3\AiAbandonedCart\Engine\Scheduler;

class AiReviewTest extends Dev3ac_Base_Case {

	public function test_without_key_messages_use_template_copy() {
		$cart = $this->cart();
		$msg  = $this->due_message( $cart );
		$this->assertSame( '', $msg['ai_state'] );
		Scheduler::send_message( $msg );
		$this->assertSame( 'template', Messages::get( $msg['id'] )['copy_source'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_ai_draft_waits_for_review_and_never_sends_unreviewed() {
		$this->ai_on();
		$cart = $this->cart();
		$msg  = $this->due_message( $cart );
		$this->assertSame( 'pending', $msg['ai_state'] );
		$this->assertSame( 1, Scheduler::prepare_ai( 5 ) );
		$msg = Messages::get( $msg['id'] );
		$this->assertSame( 'review', $msg['ai_state'] );
		$this->assertSame( 'Ann, your Linen Shirt misses you', $msg['ai_draft']['draft']['subject'] );
		$this->assertSame( 1, Messages::review_count() );

		// Only the first name + products go to Gemini, never the email or phone.
		$body = wp_json_encode( $this->requests[0]['body'] );
		$this->assertStringContainsString( 'Linen Shirt', $body );
		$this->assertStringNotContainsString( 'ann@example.com', $body );
		$this->assertStringNotContainsString( '5555550100', $body );

		// Due while still in review => the approved template copy goes out.
		$this->assertSame( 'sent', Scheduler::send_message( $msg ) );
		$sent = Messages::get( $msg['id'] );
		$this->assertSame( 'template', $sent['copy_source'] );
		$this->assertSame( 'expired', $sent['ai_state'] );
		$this->assertStringNotContainsString( 'misses you', $this->mails()[0]['subject'] );
	}

	public function test_hold_fallback_waits_then_sends_template() {
		$this->ai_on();
		$this->settings( array( 'review_fallback' => 'hold', 'review_hold_hours' => 6 ) );
		$msg = $this->due_message( $this->cart() );
		Scheduler::prepare_ai( 5 );
		$this->assertSame( 'held', Scheduler::send_message( Messages::get( $msg['id'] ) ) );
		$this->assertCount( 0, $this->mails() );
		Messages::update( $msg['id'], array( 'scheduled_at' => Carts::now( time() - 7 * HOUR_IN_SECONDS ) ) );
		$this->assertSame( 'sent', Scheduler::send_message( Messages::get( $msg['id'] ) ) );
		$this->assertSame( 'template', Messages::get( $msg['id'] )['copy_source'] );
	}

	public function test_approved_draft_is_sent_escaped_with_admin_edits() {
		$this->ai_on();
		$msg = $this->due_message( $this->cart() );
		Scheduler::prepare_ai( 5 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' );
		$req = new WP_REST_Request( 'POST', '/dev3ac/v1/review/' . $msg['id'] );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$req->set_param( 'decision', 'approve' );
		$req->set_param( 'copy', array( 'subject' => 'Edited by a human, Ann' ) );
		$this->assertSame( 200, rest_do_request( $req )->get_status() );
		$this->assertSame( 'approved', Messages::get( $msg['id'] )['ai_state'] );
		Scheduler::send_message( Messages::get( $msg['id'] ) );
		$mail = $this->mails()[0];
		$this->assertSame( 'Edited by a human, Ann', $mail['subject'] );
		$this->assertStringContainsString( 'your Linen Shirt is saved', $mail['body'] );
		$this->assertStringNotContainsString( '<script>', $mail['body'] );
		$this->assertSame( 'ai', Messages::get( $msg['id'] )['copy_source'] );
	}

	public function test_reject_sends_template() {
		$this->ai_on();
		$msg = $this->due_message( $this->cart() );
		Scheduler::prepare_ai( 5 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' );
		$req = new WP_REST_Request( 'POST', '/dev3ac/v1/review/' . $msg['id'] );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$req->set_param( 'decision', 'reject' );
		rest_do_request( $req );
		Scheduler::send_message( Messages::get( $msg['id'] ) );
		$this->assertSame( 'template', Messages::get( $msg['id'] )['copy_source'] );
		$this->assertSame( 'rejected', Messages::get( $msg['id'] )['ai_state'] );
	}

	public function test_auto_approve_template_sends_ai_copy_without_review() {
		$this->ai_on();
		$tpl = Templates::get( 'tpl_reminder' );
		Templates::upsert( array_merge( $tpl, array( 'auto_approve' => true ) ) );
		$this->assertSame( 'approved', Templates::get( 'tpl_reminder' )['status'] );
		$msg = $this->due_message( $this->cart() );
		$this->assertSame( 'sent', Scheduler::send_message( $msg ) );
		$sent = Messages::get( $msg['id'] );
		$this->assertSame( 'ai_auto', $sent['copy_source'] );
		$this->assertSame( 'Ann, your Linen Shirt misses you', $this->mails()[0]['subject'] );
	}

	public function test_ai_failure_falls_back_to_template() {
		$this->settings( array( 'api_key' => 'AIzaTESTKEY1234567890' ) );
		$this->http['generativelanguage'] = array( 500, array( 'error' => array( 'message' => 'boom' ) ) );
		$msg                              = $this->due_message( $this->cart() );
		Scheduler::prepare_ai( 5 );
		$this->assertSame( 'failed', Messages::get( $msg['id'] )['ai_state'] );
		$this->assertSame( 'sent', Scheduler::send_message( Messages::get( $msg['id'] ) ) );
		$this->assertSame( 'template', Messages::get( $msg['id'] )['copy_source'] );
	}

	public function test_ai_switch_off_skips_generation() {
		$this->ai_on();
		$this->settings( array( 'ai_enabled' => false ) );
		$msg = $this->due_message( $this->cart() );
		$this->assertSame( '', $msg['ai_state'] );
		$this->assertSame( 0, Scheduler::prepare_ai( 5 ) );
	}

	public function test_sequence_builder_filters_unknown_templates_and_caps_discounts() {
		$this->ai_on(
			array(
				'summary' => 'Three steps.',
				'steps'   => array(
					array( 'delay_value' => 1, 'delay_unit' => 'hours', 'channel' => 'email', 'template_id' => 'tpl_reminder', 'coupon_enabled' => false, 'reason' => 'Gentle first nudge' ),
					array( 'delay_value' => 1, 'delay_unit' => 'days', 'channel' => 'email', 'template_id' => 'tpl_offer', 'coupon_enabled' => true, 'coupon_type' => 'percent', 'coupon_amount' => 60, 'coupon_expiry_days' => 2, 'reason' => 'Offer' ),
					array( 'delay_value' => 2, 'delay_unit' => 'days', 'channel' => 'email', 'template_id' => 'tpl_invented', 'coupon_enabled' => false, 'reason' => 'x' ),
					array( 'delay_value' => 3, 'delay_unit' => 'days', 'channel' => 'sms', 'template_id' => 'tpl_sms', 'coupon_enabled' => false, 'reason' => 'SMS' ),
				),
			)
		);
		$out = Copywriter::sequence( 'cart', array( 'abandoned' => 10 ), false );
		$this->assertCount( 2, $out['steps'], 'Unknown ids and SMS (not available) are dropped.' );
		$this->assertEquals( 25, $out['steps'][1]['coupon']['amount'] );
		$this->assertSame( 'Gentle first nudge', $out['steps'][0]['reason'] );
		$prompt = wp_json_encode( $this->requests[0]['body'] );
		$this->assertStringNotContainsString( 'tpl_sms', $prompt, 'SMS templates are not offered when SMS is off.' );
	}

	public function test_send_time_draft_is_clamped() {
		$this->ai_on(
			array(
				'best_hours'      => array( 9, 30, 20 ),
				'quiet_start'     => 22,
				'quiet_end'       => 7,
				'max_shift_hours' => 40,
				'explanation'     => 'Evenings work best.',
			)
		);
		$out = Copywriter::send_time(
			array(
				'opens'  => array_fill( 0, 24, 1 ),
				'clicks' => array_fill( 0, 24, 0 ),
				'orders' => array_fill( 0, 24, 0 ),
			)
		);
		$this->assertSame( array( 9, 20 ), $out['draft']['best_hours'] );
		$this->assertSame( 12, $out['draft']['max_shift_hours'] );
		$this->assertSame( 'Evenings work best.', $out['explanation'] );
	}

	public function test_template_writer_returns_a_draft_only() {
		$this->ai_on(
			array(
				'subject'       => 'Your cart is waiting',
				'subject_ideas' => array( 'A', 'B' ),
				'preheader'     => 'p',
				'heading'       => 'h',
				'body'          => 'Hello {first_name}',
				'button'        => 'Go',
				'sms'           => 'Hi {recovery_url}',
			)
		);
		$before = Templates::all();
		$out    = Copywriter::template( array( 'goal' => 'First reminder' ) );
		$this->assertSame( 'Your cart is waiting', $out['draft']['subject'] );
		$this->assertSame( array( 'A', 'B' ), $out['ideas'] );
		Templates::flush();
		$this->assertSame( $before, Templates::all(), 'Nothing is saved.' );
	}
}
