<?php
/*
Plugin Name: Events Manager Pro - Sage Pay Form Gateway
Plugin URI: http://wp-events-plugin.com
Description: Sage Pay Form gateway pluging for Events Manager Pro
Version: 1.5.2
Depends: Events Manager Pro
Author: Andy Place
Author URI: http://www.andyplace.co.uk

1.5.2   Currency filter to enable currency to be modified. Allow clever stuff like currency per ticket / event

1.5.1  	Basket breakdown passed to sagepay, with opional stock reconciliation

1.5		Support for multiple bookings
		Support for coupons

1.4.3 	Fix for 1.4.2 that was missing Liams tweaks from 1.4.1. (Due to Divergence at 1.4 / 1.4.1 as claimed not required)

1.4.2 	Fix for four digit sums that are returned from test server formatted (unformatted from simulator)
		27/03/2013

1.4.1 	Addition of paymentRequired function to perform checks specific to split pay
		02/03/2013

1.4		Fixes from Liam for em_my_bookings_booking_actions
		21/02/2013

1.3.2  	Fix for country code where England, Scotland, Wales, Northern Ireland are selected. Set to UK instead of GB
		Was working originally, so presumably something changed at SagePay, no longer accepting GB
		20/02/2013

1.3.1 	?

1.3 	Workaround for US State code. Required field.

1.2 	Updated to work with changes in EM and specific gateway feilds

1.1 	?

*/

class EM_Pro_Sage {

	function EM_Pro_Sage() {
		global $wpdb;
		//Set when to run the plugin : after EM is loaded.
		add_action( 'plugins_loaded', array(&$this,'init'), 100 );
	}
	
	function init() {
		//add-ons
		include('add-ons/gateways/gateway.sagepay.form.php');
	}

}

// Start plugin
global $EM_Pro_Sage;
$EM_Pro_Sage = new EM_Pro_Sage();