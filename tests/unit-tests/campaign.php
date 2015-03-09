<?php

class Test_Charitable_Campaign extends WP_UnitTestCase {	

	/** 
	 * There are two campaigns. 
	 *
	 * Campaign 1: Goal of $40,000. Expiry 300 days from now. 
	 * Campaign 2: No goal. No end date. 
	 */
	private $post_1;
	private $campaign_1;
	private $end_time_1;

	private $post_2;
	private $campaign_2;		

	function setUp() {
		parent::setUp();

		/* Campaign 1: Goal of $40,000. Expiry 300 days from now. */
		$this->end_time_1 	= strtotime( '+7201 hours');
		$campaign_1_id 		= Charitable_Campaign_Helper::create_campaign( array( 
			'_campaign_goal' 					=> 40000.00,
			'_campaign_end_date' 				=> date( 'Y-m-d H:i:s', $this->end_time_1 ), 
			'_campaign_suggested_donations' 	=> '5|20|50|100|250'
		) );

		$this->post_1 		= get_post( $campaign_1_id );
		$this->campaign_1 	= new Charitable_Campaign( $this->post_1 );

		/* Campaign 2: No goal. No end date. */

		$campaign_2_id 		= Charitable_Campaign_Helper::create_campaign( array(
			'_campaign_suggested_donations' 	=> '5|50|150|500'
		) );

		$this->post_2 		= get_post( $campaign_2_id );
		$this->campaign_2 	= new Charitable_Campaign( $this->post_2 );

		/* Create a few users and donations */

		$user_id_1 = $this->factory->user->create( array( 'display_name' => 'John Henry' ) );
		$user_id_2 = $this->factory->user->create( array( 'display_name' => 'Mike Myers' ) );
		$user_id_3 = $this->factory->user->create( array( 'display_name' => 'Fritz Bolton' ) );

		$donations = array(
			array( 
				'user_id' 				=> $user_id_1, 
				'campaigns'				=> array(
					array(
						'campaign_id'	=> $campaign_1_id,
						'amount' 		=> 10
					)
				),
				'gateway' 				=> 'paypal', 
				'note'					=> 'This is a note'
			),
			array( 
				'user_id' 				=> $user_id_2, 
				'campaigns'				=> array(
					array(
						'campaign_id'	=> $campaign_1_id,
						'amount' 		=> 20
					)
				),
				'gateway' 				=> 'paypal', 
				'note'					=> ''
			),
			array( 
				'user_id' 				=> $user_id_3, 
				'campaigns'				=> array(
					array(
						'campaign_id'	=> $campaign_1_id,
						'amount' 		=> 30
					)
				),
				'gateway' 				=> 'manual', 
				'note'					=> ''
			), 
			array(
				'user_id'				=> $user_id_1, 
				'campaigns'				=> array(
					array( 
						'campaign_id' 	=> $campaign_2_id,
						'amount'		=> 25
					)
				), 
				'status'				=> 'charitable-completed', 
				'gateway'				=> 'paypal'
			)
		);

		foreach ( $donations as $donation ) {
			Charitable_Donation_Helper::create_donation( $donation );		
		}
	}

	function test_get_campaign_id() {
		$this->assertEquals( $this->post_1->ID, $this->campaign_1->get_campaign_id() );
		$this->assertEquals( $this->post_2->ID, $this->campaign_2->get_campaign_id() );
	}	

	function test_get() {
		$this->assertEquals( 40000.00, $this->campaign_1->get('campaign_goal') );
		$this->assertEquals( date( 'Y-m-d H:i:s', $this->end_time_1 ), $this->campaign_1->get('campaign_end_date') );		

		$this->assertEquals( 0, $this->campaign_2->get('campaign_goal') );
		$this->assertEquals( 0, $this->campaign_2->get('campaign_end_date') );
	}

	function test_get_end_time() {
		$this->assertEquals( $this->end_time_1, $this->campaign_1->get_end_time() );
		$this->assertFalse( $this->campaign_2->get_end_time() );
	}

	function test_get_end_date() {
		$this->assertEquals( date('Y-m-d', $this->end_time_1), $this->campaign_1->get_end_date( 'Y-m-d' ) );
		$this->assertFalse( $this->campaign_2->get_end_date() );
	}

	function test_get_seconds_left() {
		$seconds_left = $this->end_time_1 - time();
		$this->assertEquals( $seconds_left , $this->campaign_1->get_seconds_left() );
		$this->assertFalse( $this->campaign_2->get_seconds_left() );
	}

	function test_get_time_left() {	
		$this->assertEquals( '<span class="amount time-left days-left">300</span> Days Left', $this->campaign_1->get_time_left() );
		$this->assertEquals( '', $this->campaign_2->get_time_left() );
	}

	function test_get_goal() {
		$this->assertEquals( 40000.00, $this->campaign_1->get_goal() );
		$this->assertFalse( $this->campaign_2->get_goal() );
	}

	function test_has_goal() {
		$this->assertTrue( $this->campaign_1->has_goal() );
		$this->assertFalse( $this->campaign_2->has_goal() );
	}

	function test_get_monetary_goal() {
		$this->assertEquals( '&#36;40,000.00', $this->campaign_1->get_monetary_goal() );
		$this->assertEquals( '', $this->campaign_2->get_monetary_goal() );
	}

	function test_get_donations() {
		$this->assertCount( 3, $this->campaign_1->get_donations() );
		$this->assertCount( 1, $this->campaign_2->get_donations() );
	}

	function test_get_donated_amount() {
		$this->assertEquals( 60.00, $this->campaign_1->get_donated_amount() );
		$this->assertEquals( 25.00, $this->campaign_2->get_donated_amount() );
	}

	function test_get_percent_donated() {
		$this->assertEquals( '0.15%', $this->campaign_1->get_percent_donated() );
		$this->assertFalse( $this->campaign_2->get_percent_donated() );
	}

	function test_flush_donations_cache() {
		// Test count of donations pre-cache
		$this->assertCount( 3, $this->campaign_1->get_donations() );

		// Create a new donation
		$user_id_4 = $this->factory->user->create( array( 'display_name' => 'Abraham Lincoln' ) );

		$donation_id = Charitable_Donation_Helper::create_donation( array(
			'user_id'			=> $user_id_4, 
			'campaigns'			=> array(
				array(
					'campaign_id' 		=> $this->campaign_1->get_campaign_id(), 
					'campaign_name'		=> 'Test Campaign',
					'amount'			=> 100
				)				
			),		
			'gateway'			=> 'paypal', 
			'status'			=> 'charitable-completed'
		) );

		// Test count of donations again, before flush caching
		$this->assertCount( 3, $this->campaign_1->get_donations() );

		// Flush cache
		$this->campaign_1->flush_donations_cache();	

		// Test count of donations again, should be +1
		$this->assertCount( 4, $this->campaign_1->get_donations() );
	}

	function test_get_donation_form() {
		$this->assertInstanceOf( 'Charitable_Donation_Form', $this->campaign_1->get_donation_form() );
	}	

	function test_get_donor_count() {
		$this->assertEquals( 3, $this->campaign_1->get_donor_count() );
		$this->assertEquals( 1, $this->campaign_2->get_donor_count() );
	}

	function test_get_suggested_amounts() {
		foreach ( array( 5, 20, 50, 100, 250 ) as $suggested_donation ) {
			$this->assertContains( $suggested_donation, $this->campaign_1->get_suggested_amounts() );
		}
	}
}