<?php
/**
 * Send-time tuning.
 *
 * @package Dev3\AiAbandonedCart
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiAbandonedCart\Engine\Send_Time;

class SendTimeTest extends Dev3ac_Base_Case {

	private function step( $value, $unit ) {
		return array(
			'delay_value' => $value,
			'delay_unit'  => $unit,
		);
	}

	public function set_up() {
		parent::set_up();
		$this->settings(
			array(
				'send_time_tuning' => true,
				'quiet_start'      => 21,
				'quiet_end'        => 8,
				'max_shift_hours'  => 6,
			)
		);
	}

	public function test_quiet_hours() {
		$this->assertTrue( Send_Time::is_quiet( 23, 21, 8 ) );
		$this->assertTrue( Send_Time::is_quiet( 3, 21, 8 ) );
		$this->assertFalse( Send_Time::is_quiet( 8, 21, 8 ) );
		$this->assertTrue( Send_Time::is_quiet( 13, 12, 14 ) );
		$this->assertFalse( Send_Time::is_quiet( 5, 5, 5 ) );
	}

	public function test_moves_forward_to_the_best_hour_within_the_shift() {
		$cart     = array( 'kind' => 'cart', 'tz_known' => 1, 'tz_offset' => 0 );
		$earliest = gmmktime( 13, 20, 0, 6, 1, 2030 );
		$scores   = array_fill( 0, 24, 0 );
		$scores[17] = 5;
		$scores[22] = 9; // Quiet: ignored.
		$best = Send_Time::best( $earliest, $cart, $this->step( 1, 'days' ), 1, $scores );
		$this->assertSame( gmmktime( 17, 0, 0, 6, 1, 2030 ), $best );
	}

	public function test_never_earlier_and_ties_keep_the_earliest() {
		$cart     = array( 'kind' => 'cart', 'tz_known' => 1, 'tz_offset' => 0 );
		$earliest = gmmktime( 10, 5, 0, 6, 1, 2030 );
		$this->assertSame( $earliest, Send_Time::best( $earliest, $cart, $this->step( 1, 'days' ), 1, array_fill( 0, 24, 0 ) ) );
	}

	public function test_quiet_hours_use_the_shopper_timezone() {
		// 02:00 UTC is 21:00 in UTC-5: quiet for that shopper until 08:00 local = 13:00 UTC.
		$cart     = array( 'kind' => 'cart', 'tz_known' => 1, 'tz_offset' => -300 );
		$earliest = gmmktime( 2, 0, 0, 6, 1, 2030 );
		$this->settings( array( 'max_shift_hours' => 2 ) );
		$best = Send_Time::best( $earliest, $cart, $this->step( 1, 'days' ), 1, array_fill( 0, 24, 0 ) );
		$this->assertSame( gmmktime( 13, 0, 0, 6, 1, 2030 ), $best );
	}

	public function test_first_reminder_stays_fresh() {
		$cart     = array( 'kind' => 'cart', 'tz_known' => 1, 'tz_offset' => 0 );
		$earliest = gmmktime( 11, 10, 0, 6, 1, 2030 );
		$scores   = array_fill( 0, 24, 0 );
		$scores[16] = 10;
		$scores[12] = 1;
		$this->assertSame( gmmktime( 12, 0, 0, 6, 1, 2030 ), Send_Time::best( $earliest, $cart, $this->step( 1, 'hours' ), 0, $scores ) );
	}

	public function test_scores_use_own_order_hours() {
		$order = wc_create_order();
		$order->set_billing_email( 'ann@example.com' );
		$order->set_status( 'completed' );
		$order->set_date_created( gmmktime( 19, 30, 0, 5, 1, 2030 ) );
		$order->save();
		$scores = Send_Time::scores( array( 'email' => 'ann@example.com', 'tz_known' => 1, 'tz_offset' => 0 ) );
		$this->assertSame( 19, array_search( max( $scores ), $scores, true ) );
	}

	public function test_off_returns_earliest() {
		$this->settings( array( 'send_time_tuning' => false ) );
		$cart = array( 'kind' => 'cart', 'tz_known' => 1, 'tz_offset' => 0 );
		$ts   = gmmktime( 23, 0, 0, 6, 1, 2030 );
		$this->assertSame( $ts, Send_Time::best( $ts, $cart, $this->step( 1, 'days' ), 1 ) );
	}
}
