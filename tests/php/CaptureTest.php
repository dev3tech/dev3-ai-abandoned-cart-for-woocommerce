<?php
/**
 * Capture (consent modes, block Store API hook), recovery links, unsubscribe, open pixel, privacy.
 *
 * @package Dev3\AiAbandonedCart
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiAbandonedCart\Capture\Tracker;
use Dev3\AiAbandonedCart\Data\Carts;
use Dev3\AiAbandonedCart\Data\Messages;
use Dev3\AiAbandonedCart\Data\Suppressions;
use Dev3\AiAbandonedCart\Engine\Links;
use Dev3\AiAbandonedCart\Engine\Scheduler;
use Dev3\AiAbandonedCart\Engine\Sms;
use Dev3\AiAbandonedCart\Frontend\Recovery;
use Dev3\AiAbandonedCart\Privacy_Data;
use Dev3\AiAbandonedCart\Rest\Public_Controller;

class CaptureTest extends Dev3ac_Base_Case {

	private function fill_cart( $qty = 2 ) {
		$product = $this->product( '30', 'Wool Scarf' );
		WC()->cart->add_to_cart( $product->get_id(), $qty );
		WC()->cart->calculate_totals();
		return $product;
	}

	public function test_guest_capture_in_notice_mode() {
		$this->fill_cart();
		$id = Tracker::capture(
			array(
				'email'      => 'Guest@Example.com',
				'first_name' => 'Gia',
				'consent'    => true,
				'tz_offset'  => 120,
				'tz_known'   => true,
				'source'     => 'classic',
			)
		);
		$this->assertGreaterThan( 0, $id );
		$cart = Carts::get( $id );
		$this->assertSame( 'guest@example.com', $cart['email'] );
		$this->assertSame( 'Wool Scarf', $cart['items'][0]['name'] );
		$this->assertSame( 2, $cart['item_count'] );
		$this->assertEquals( 60, $cart['total'] );
		$this->assertSame( 'notice', $cart['consent'] );
		$this->assertSame( 120, $cart['tz_offset'] );
		$this->assertSame( 32, strlen( $cart['token'] ) );
		// Second call updates the same row.
		$this->assertSame( $id, Tracker::capture( array( 'email' => 'guest@example.com', 'phone' => '555 0100', 'consent' => true ) ) );
		$this->assertSame( '5550100', Carts::get( $id )['phone'] );
	}

	public function test_opt_in_mode_stores_nothing_without_consent_and_withdraws() {
		$this->settings( array( 'consent_mode' => 'opt_in' ) );
		$this->fill_cart();
		$this->assertSame( 0, Tracker::capture( array( 'email' => 'a@example.com', 'consent' => false ) ) );
		$this->assertSame( 0, Carts::counts()['all'] );
		$id = Tracker::capture( array( 'email' => 'a@example.com', 'consent' => true ) );
		$this->assertSame( 'opt_in', Carts::get( $id )['consent'] );
		Tracker::capture( array( 'email' => 'a@example.com', 'consent' => false ) );
		$this->assertNull( Carts::get( $id ), 'Unticking removes the stored cart.' );
	}

	public function test_empty_cart_suppressed_and_excluded_roles_are_not_captured() {
		$this->assertSame( 0, Tracker::capture( array( 'email' => 'x@example.com', 'consent' => true ) ), 'Empty cart.' );
		$this->fill_cart();
		Suppressions::add( 'x@example.com' );
		$this->assertSame( 0, Tracker::capture( array( 'email' => 'x@example.com', 'consent' => true ) ) );
		$this->settings( array( 'exclude_roles' => array( 'shop_manager' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertSame( 0, Tracker::capture( array( 'email' => 'y@example.com', 'consent' => true ) ) );
	}

	public function test_block_checkout_store_api_hook_captures() {
		$this->fill_cart( 1 );
		$customer = new WC_Customer();
		$customer->set_billing_email( 'block@example.com' );
		$customer->set_billing_first_name( 'Bea' );
		$customer->set_billing_country( 'DE' );
		Tracker::on_store_api_customer( $customer );
		$rows = Carts::for_email( 'block@example.com' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'block', $rows[0]['source'] );
		$this->assertSame( 'DE', $rows[0]['country'] );

		$this->settings( array( 'capture_block' => false ) );
		$customer->set_billing_email( 'other@example.com' );
		Tracker::on_store_api_customer( $customer );
		$this->assertCount( 0, Carts::for_email( 'other@example.com' ) );
	}

	public function test_cart_changes_sync_and_empty_cart_deletes_active_row() {
		$product = $this->fill_cart( 1 );
		$id      = Tracker::capture( array( 'email' => 'sync@example.com', 'consent' => true ) );
		WC()->cart->add_to_cart( $product->get_id(), 2 );
		WC()->cart->calculate_totals();
		Tracker::on_cart_updated();
		$this->assertSame( 3, Carts::get( $id )['item_count'] );
		WC()->cart->empty_cart();
		Tracker::on_cart_updated();
		$this->assertNull( Carts::get( $id ) );
	}

	public function test_order_created_links_cart_and_consent() {
		$this->fill_cart();
		$id    = Tracker::capture( array( 'email' => 'buyer@example.com', 'consent' => true ) );
		$order = wc_create_order();
		Tracker::on_order_created( $order );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( (string) $id, (string) $order->get_meta( '_dev3ac_cart_id' ) );
		$this->assertSame( 'yes', $order->get_meta( '_dev3ac_consent' ) );
		$this->assertSame( $order->get_id(), Carts::get( $id )['order_id'] );
		$this->assertNull( WC()->session->get( 'dev3ac_cart_id' ) );
	}

	public function test_recovery_link_restores_items_and_applies_coupon() {
		$cart = $this->cart();
		Scheduler::abandon( $cart, time() );
		$coupon = new WC_Coupon();
		$coupon->set_code( 'comeback-test1' );
		$coupon->set_amount( 10 );
		$coupon->set_discount_type( 'percent' );
		$coupon->save();
		Carts::update( $cart['id'], array( 'coupon_codes' => array( 'comeback-test1' ) ) );
		$msg = Messages::for_cart( $cart['id'] )[0];

		$this->assertStringContainsString( wc_get_page_permalink( 'shop' ), Recovery::recover( $cart['token'], $msg['id'], 'bad-signature' ) );
		$this->assertTrue( WC()->cart->is_empty() );

		$url = Recovery::recover( $cart['token'], $msg['id'], Links::sign( 'recover', $cart['token'], $msg['id'] ) );
		$this->assertSame( wc_get_checkout_url(), $url );
		$this->assertSame( 2, WC()->cart->get_cart_contents_count() );
		$this->assertTrue( WC()->cart->has_discount( 'comeback-test1' ) );
		$this->assertSame( 'ann@example.com', WC()->customer->get_billing_email() );
		$fresh = Carts::get( $cart['id'] );
		$this->assertSame( 'recovering', $fresh['status'] );
		$this->assertNotEmpty( $fresh['clicked_at'] );
		$this->assertNotEmpty( Messages::get( $msg['id'] )['clicked_at'] );
		$this->assertSame( (int) $cart['id'], (int) WC()->session->get( 'dev3ac_cart_id' ) );
	}

	public function test_unsubscribe_link() {
		$cart = $this->cart();
		Scheduler::abandon( $cart, time() );
		Recovery::unsubscribe( $cart['token'], 'nope' );
		$this->assertFalse( Suppressions::has( 'ann@example.com' ) );
		Recovery::unsubscribe( $cart['token'], Links::sign( 'unsub', $cart['token'] ) );
		$this->assertTrue( Suppressions::has( 'ann@example.com' ) );
		$this->assertTrue( Suppressions::has( '+15555550100' ) );
		$this->assertSame( 'unsubscribed', Carts::get( $cart['id'] )['status'] );
		$this->assertSame( 'cancelled', Messages::for_cart( $cart['id'] )[0]['status'] );
	}

	public function test_open_pixel_requires_a_signature() {
		$cart = $this->cart();
		$msg  = $this->due_message( $cart );
		Scheduler::send_message( $msg );
		$this->assertFalse( Public_Controller::track_open( $msg['id'], 'forged' ) );
		$this->assertNull( Messages::get( $msg['id'] )['opened_at'] );
		$this->assertTrue( Public_Controller::track_open( $msg['id'], Links::sign( 'open', $msg['id'] ) ) );
		$this->assertNotEmpty( Messages::get( $msg['id'] )['opened_at'] );
	}

	public function test_privacy_export_erase_and_retention() {
		$cart = $this->cart();
		Scheduler::send_message( $this->due_message( $cart ) );
		$export = Privacy_Data::export( 'ann@example.com' );
		$groups = wp_list_pluck( $export['data'], 'group_id' );
		$this->assertContains( 'dev3ac-carts', $groups );
		$this->assertContains( 'dev3ac-messages', $groups );
		$erase = Privacy_Data::erase( 'ann@example.com' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertNull( Carts::get( $cart['id'] ) );
		$this->assertSame( array(), Messages::for_cart( $cart['id'] ) );

		$old = $this->cart(
			array(
				'status'     => 'lost',
				'updated_at' => Carts::now( time() - 200 * DAY_IN_SECONDS ),
			)
		);
		$open = $this->cart(
			array(
				'status'     => 'abandoned',
				'updated_at' => Carts::now( time() - 200 * DAY_IN_SECONDS ),
			)
		);
		Privacy_Data::daily();
		$this->assertNull( Carts::get( $old['id'] ) );
		$this->assertNotNull( Carts::get( $open['id'] ), 'Carts in a running sequence are kept.' );
	}

	public function test_phone_normalisation() {
		$this->assertSame( '+15555550100', Sms::normalize( '(555) 555-0100', 'US' ) );
		$this->assertSame( '+447700900123', Sms::normalize( '07700 900123', 'GB' ) );
		$this->assertSame( '+4915112345678', Sms::normalize( '0049 151 12345678', '' ) );
		$this->assertSame( '', Sms::normalize( '12', 'US' ) );
		$this->assertSame( '', Sms::normalize( '5550100', '' ) );
	}
}
