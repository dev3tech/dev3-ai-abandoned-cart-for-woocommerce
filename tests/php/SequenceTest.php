<?php
/**
 * Abandonment, scheduling, sending, coupons, attribution.
 *
 * @package Dev3\AiAbandonedCart
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiAbandonedCart\Capture\Tracker;
use Dev3\AiAbandonedCart\Data\Carts;
use Dev3\AiAbandonedCart\Data\Messages;
use Dev3\AiAbandonedCart\Data\Sequences;
use Dev3\AiAbandonedCart\Data\Suppressions;
use Dev3\AiAbandonedCart\Data\Templates;
use Dev3\AiAbandonedCart\Engine\Scheduler;

class SequenceTest extends Dev3ac_Base_Case {

	public function test_defaults_are_seeded_and_approved() {
		$this->assertCount( 6, Templates::all() );
		foreach ( Templates::all() as $tpl ) {
			$this->assertSame( 'approved', $tpl['status'] );
		}
		$this->assertSame( 'seq_cart', Sequences::active( 'cart' )['id'] );
		$this->assertSame( 'seq_winback', Sequences::active( 'winback' )['id'] );
		$this->assertCount( 3, Sequences::get( 'seq_cart' )['steps'] );
	}

	public function test_idle_carts_become_abandoned_after_the_cutoff_only() {
		$old   = $this->cart( array( 'updated_at' => Carts::now( time() - 20 * MINUTE_IN_SECONDS ) ) );
		$fresh = $this->cart( array( 'updated_at' => Carts::now( time() - 5 * MINUTE_IN_SECONDS ) ) );
		$anon  = $this->cart(
			array(
				'email'      => '',
				'phone'      => '',
				'updated_at' => Carts::now( time() - DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( 1, Scheduler::detect_abandoned( time() ) );
		$this->assertSame( 'abandoned', Carts::get( $old['id'] )['status'] );
		$this->assertSame( 'active', Carts::get( $fresh['id'] )['status'] );
		$this->assertSame( 'active', Carts::get( $anon['id'] )['status'] );
		$msgs = Messages::for_cart( $old['id'] );
		$this->assertCount( 1, $msgs );
		$this->assertSame( 'scheduled', $msgs[0]['status'] );
		// Step 1 = 1 hour after the last activity (20 min ago) => ~40 minutes from now.
		$this->assertEqualsWithDelta( time() + 40 * MINUTE_IN_SECONDS, strtotime( $msgs[0]['scheduled_at'] . ' UTC' ), 5 );
	}

	public function test_min_cart_total_is_respected() {
		$this->settings( array( 'min_cart_total' => 100 ) );
		$cart = $this->cart( array( 'updated_at' => Carts::now( time() - DAY_IN_SECONDS ) ) );
		$this->assertSame( 0, Scheduler::detect_abandoned( time() ) );
		$this->assertSame( 'active', Carts::get( $cart['id'] )['status'] );
	}

	public function test_steps_send_in_order_with_coupon_and_unsubscribe() {
		$cart = $this->cart();
		$msg  = $this->due_message( $cart );
		$this->assertSame( 'sent', Scheduler::send_message( $msg ) );
		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'ann@example.com', $mails[0]['to'][0][0] );
		$this->assertSame( 'Ann, you left something in your cart', $mails[0]['subject'] );
		$this->assertStringContainsString( 'dev3ac_recover=' . $cart['token'], $mails[0]['body'] );
		$this->assertStringContainsString( 'dev3ac_unsub=' . $cart['token'], $mails[0]['body'] );
		$this->assertStringContainsString( 'List-Unsubscribe:', $mails[0]['header'] );
		$this->assertStringContainsString( 'Linen Shirt', $mails[0]['body'] );

		$fresh = Carts::get( $cart['id'] );
		$this->assertSame( 1, $fresh['step_index'] );
		$next = Messages::for_cart( $cart['id'] );
		$this->assertCount( 2, $next );
		$this->assertSame( 'scheduled', $next[1]['status'] );
		$this->assertSame( 1, $next[1]['step_index'] );
		$this->assertEqualsWithDelta( time() + 23 * HOUR_IN_SECONDS, strtotime( $next[1]['scheduled_at'] . ' UTC' ), 5 );

		// Step 2 creates a unique single-use coupon locked to the email.
		Messages::update( $next[1]['id'], array( 'scheduled_at' => Carts::now( time() - 1 ) ) );
		reset_phpmailer_instance();
		$this->assertSame( 'sent', Scheduler::send_message( Messages::get( $next[1]['id'] ) ) );
		$sent = Messages::get( $next[1]['id'] );
		$this->assertNotEmpty( $sent['coupon_code'] );
		$coupon = new WC_Coupon( wc_get_coupon_id_by_code( $sent['coupon_code'] ) );
		$this->assertSame( 'percent', $coupon->get_discount_type() );
		$this->assertEquals( 10, $coupon->get_amount() );
		$this->assertSame( 1, $coupon->get_usage_limit() );
		$this->assertTrue( $coupon->get_individual_use() );
		$this->assertSame( array( 'ann@example.com' ), $coupon->get_email_restrictions() );
		$this->assertStringContainsString( $sent['coupon_code'], $this->mails()[0]['body'] );
		$this->assertStringContainsString( '10% off', $this->mails()[0]['subject'] );
		$this->assertSame( array( $sent['coupon_code'] ), Carts::get( $cart['id'] )['coupon_codes'] );
	}

	public function test_unapproved_template_steps_are_skipped() {
		$tpl = Templates::get( 'tpl_reminder' );
		Templates::upsert( array_merge( $tpl, array( 'subject' => 'Edited subject' ) ) );
		$this->assertSame( 'draft', Templates::get( 'tpl_reminder' )['status'], 'Editing approved copy needs a new approval.' );
		$cart = $this->cart();
		Scheduler::abandon( $cart, time() );
		$msgs = Messages::for_cart( $cart['id'] );
		$this->assertSame( 'skipped', $msgs[0]['status'] );
		$this->assertSame( 'Template is not approved.', $msgs[0]['error'] );
		$this->assertSame( 'scheduled', $msgs[1]['status'] );
		$this->assertSame( 1, $msgs[1]['step_index'] );
	}

	public function test_template_toggles_keep_approval() {
		$tpl = Templates::get( 'tpl_last' );
		Templates::upsert( array_merge( $tpl, array( 'ai_personalise' => true ) ) );
		$this->assertSame( 'approved', Templates::get( 'tpl_last' )['status'] );
		$new = Templates::upsert( array( 'name' => 'New', 'subject' => 'Hi', 'body' => 'x', 'status' => 'approved' ) );
		$this->assertSame( 'draft', $new['status'], 'New templates start as drafts.' );
		$this->assertSame( 'approved', Templates::upsert( array( 'id' => $new['id'], 'approve' => true ) )['status'] );
	}

	public function test_sms_steps_need_consent_and_provider() {
		Sequences::save_all(
			array(
				array(
					'id'      => 'seq_sms',
					'name'    => 'SMS',
					'type'    => 'cart',
					'enabled' => true,
					'steps'   => array(
						array(
							'delay_value' => 0,
							'delay_unit'  => 'minutes',
							'channel'     => 'sms',
							'template_id' => 'tpl_sms',
						),
					),
				),
			)
		);
		$cart = $this->cart();
		Scheduler::abandon( $cart, time() );
		$this->assertSame( 'SMS is not configured.', Messages::for_cart( $cart['id'] )[0]['error'] );

		$this->settings(
			array(
				'sms_enabled'     => true,
				'sms_account_sid' => 'AC123',
				'sms_auth_token'  => 'secret-token',
				'sms_from'        => '+15550001111',
			)
		);
		$no_consent = $this->cart();
		Scheduler::abandon( $no_consent, time() );
		$this->assertSame( 'No SMS consent.', Messages::for_cart( $no_consent['id'] )[0]['error'] );

		$this->http['api.twilio.com'] = array( 201, array( 'sid' => 'SM42' ) );
		$ok                           = $this->cart( array( 'sms_consent' => 1 ) );
		$msg                          = $this->due_message( $ok );
		$this->assertSame( 'sent', Scheduler::send_message( $msg ) );
		$req = end( $this->requests );
		$this->assertStringContainsString( '/Accounts/AC123/Messages.json', $req['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'AC123:secret-token' ), $req['args']['headers']['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertSame( '+15555550100', $req['body']['To'] );
		$this->assertStringContainsString( 'dev3ac_recover=', $req['body']['Body'] );
		$this->assertStringContainsString( 'STOP', $req['body']['Body'] );
		$this->assertSame( 'SM42', Messages::get( $msg['id'] )['provider_id'] );
	}

	public function test_paid_order_marks_recovered_with_step_and_stops_sequence() {
		$cart = $this->cart();
		$msg  = $this->due_message( $cart );
		Scheduler::send_message( $msg );
		$order = wc_create_order();
		$order->set_billing_email( 'ann@example.com' );
		$order->set_total( 80 );
		$order->update_meta_data( '_dev3ac_cart_id', $cart['id'] );
		$order->save();
		$order->update_status( 'processing' );
		$fresh = Carts::get( $cart['id'] );
		$this->assertSame( 'recovered', $fresh['status'] );
		$this->assertEquals( 80, $fresh['recovered_total'] );
		$this->assertSame( 0, $fresh['recovered_step'] );
		$this->assertSame( $order->get_id(), $fresh['order_id'] );
		$this->assertSame( 0, count( array_filter( Messages::for_cart( $cart['id'] ), static fn( $m ) => 'scheduled' === $m['status'] ) ) );
		$this->assertSame( (string) $cart['id'], (string) wc_get_order( $order->get_id() )->get_meta( '_dev3ac_recovered' ) );
	}

	public function test_order_without_any_reminder_is_converted_not_recovered() {
		$cart  = $this->cart();
		$order = wc_create_order();
		$order->set_billing_email( 'ann@example.com' );
		$order->update_meta_data( '_dev3ac_cart_id', $cart['id'] );
		$order->save();
		Tracker::on_order_paid( $order->get_id() );
		$this->assertSame( 'converted', Carts::get( $cart['id'] )['status'] );
	}

	public function test_order_by_email_stops_other_open_sequences() {
		$a = $this->cart();
		Scheduler::abandon( $a, time() );
		$order = wc_create_order();
		$order->set_billing_email( 'ANN@example.com' );
		$order->save();
		Tracker::on_order_paid( $order->get_id() );
		$this->assertSame( 'converted', Carts::get( $a['id'] )['status'], 'Abandoned but never messaged: bought on their own.' );
	}

	public function test_suppressed_recipients_are_never_sent() {
		$cart = $this->cart();
		$msg  = $this->due_message( $cart );
		Suppressions::add( 'Ann@Example.com' );
		$this->assertSame( 'cancelled', Scheduler::send_message( $msg ) );
		$this->assertSame( 'unsubscribed', Carts::get( $cart['id'] )['status'] );
		$this->assertCount( 0, $this->mails() );
	}

	public function test_lost_after_last_step_and_stop_restart() {
		$cart = $this->cart(
			array(
				'status'       => 'abandoned',
				'abandoned_at' => Carts::now( time() - 30 * DAY_IN_SECONDS ),
				'last_sent_at' => Carts::now( time() - 10 * DAY_IN_SECONDS ),
				'sequence_id'  => 'seq_cart',
				'step_index'   => 3,
			)
		);
		$this->assertSame( 1, Scheduler::mark_lost( time() ) );
		$this->assertSame( 'lost', Carts::get( $cart['id'] )['status'] );
		Scheduler::restart( Carts::get( $cart['id'] ) );
		$fresh = Carts::get( $cart['id'] );
		$this->assertSame( 'abandoned', $fresh['status'] );
		$this->assertSame( 0, $fresh['step_index'] );
		Scheduler::stop( $fresh );
		$this->assertSame( 'stopped', Carts::get( $cart['id'] )['status'] );
		$all = Messages::for_cart( $cart['id'] );
		$this->assertSame( 'cancelled', end( $all )['status'] );
	}

	public function test_send_now_sends_immediately() {
		$cart = $this->cart();
		Scheduler::abandon( $cart, time() );
		$this->assertSame( 'sent', Scheduler::send_now( Carts::get( $cart['id'] ) ) );
		$this->assertCount( 1, $this->mails() );
		$this->assertInstanceOf( WP_Error::class, Scheduler::send_now( $this->cart() ), 'Active carts cannot be messaged.' );
	}

	public function test_sweep_runs_end_to_end_and_locks() {
		$this->cart( array( 'updated_at' => Carts::now( time() - 2 * HOUR_IN_SECONDS ) ) );
		$stats = Scheduler::sweep( true );
		$this->assertSame( 1, $stats['abandoned'] );
		$this->assertSame( 1, $stats['sent'], 'Step 1 (1 h after last activity) is already due.' );
		add_option( Scheduler::LOCK, time() );
		$this->assertTrue( Scheduler::sweep()['locked'] );
		delete_option( Scheduler::LOCK );
	}

	public function test_winback_enrols_lapsed_customers_once() {
		$product = $this->product( '25', 'Coffee Beans' );
		$order   = wc_create_order();
		$order->add_product( $product, 2 );
		$order->set_billing_email( 'old@example.com' );
		$order->set_billing_first_name( 'Olga' );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->set_date_created( time() - 70 * DAY_IN_SECONDS );
		$order->save();
		$this->assertSame( 1, Scheduler::enrol_winback( time() ) );
		$rows = Carts::for_email( 'old@example.com' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'winback', $rows[0]['kind'] );
		$this->assertSame( 'Coffee Beans', $rows[0]['items'][0]['name'] );
		$this->assertSame( 2, $rows[0]['items'][0]['quantity'] );
		$this->assertSame( 'scheduled', Messages::for_cart( $rows[0]['id'] )[0]['status'] );
		$this->assertSame( 0, Scheduler::enrol_winback( time() ), 'Not enrolled twice.' );

		$this->settings( array( 'consent_mode' => 'opt_in' ) );
		$order2 = wc_create_order();
		$order2->set_billing_email( 'noconsent@example.com' );
		$order2->set_status( 'completed' );
		$order2->set_date_created( time() - 65 * DAY_IN_SECONDS );
		$order2->save();
		$this->assertSame( 0, Scheduler::enrol_winback( time() ), 'Opt-in mode needs recorded consent.' );
	}
}
