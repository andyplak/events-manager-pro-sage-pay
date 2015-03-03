<?php
/*
Plugin Name: Events Manager Pro - Sage Pay Form Gateway
Plugin URI: http://wp-events-plugin.com
Description: Sage Pay Form gateway plugin for Events Manager Pro
Version: 1.5.4
Depends: Events Manager Pro
Author: Andy Place
Author URI: http://www.andyplace.co.uk
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