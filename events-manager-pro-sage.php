<?php
/*
Plugin Name: Events Manager Pro - Sage Pay Form Gateway
Plugin URI: http://wp-events-plugin.com
Description: Sage Pay Form gateway plugin for Events Manager Pro
Version: 1.5.7 (splitpay special)
Depends: Events Manager Pro
Author: Andy Place
Author URI: http://www.andyplace.co.uk
*/

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

class EM_Pro_Sage {

	function EM_Pro_Sage() {
		global $wpdb;
		//Set when to run the plugin : after EM is loaded.
		add_action( 'plugins_loaded', array(&$this,'init'), 100 );
	}

	function init() {
		if( is_plugin_active('events-manager/events-manager.php') && is_plugin_active('events-manager-pro/events-manager-pro.php') ) {
			//add-ons
			include('add-ons/gateways/gateway.sagepay.form.php');
		}else{
			add_action( 'admin_notices', array(&$this,'not_activated_error_notice') );
		}
	}

	function not_activated_error_notice() {
		$class = "error";
		$message = __('Please ensure both Events Manager and Events Manager Pro are enabled for the SagePay Gateway to work.', 'em-pro');
		echo '<div class="'.$class.'"> <p>'.$message.'</p></div>';
	}

}

// Start plugin
global $EM_Pro_Sage;
$EM_Pro_Sage = new EM_Pro_Sage();