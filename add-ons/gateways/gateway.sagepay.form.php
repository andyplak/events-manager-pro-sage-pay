<?php

class EM_Gateway_SagePay_Form extends EM_Gateway {
	var $gateway = 'sagepay_form';
	var $title = 'Sage Pay (Form)';
	var $status = 4;
	var $status_txt = 'Awaiting Sage Pay Payment';
	var $button_enabled = true;
	var $payment_return = true;
	var $supports_multiple_bookings = true;


	/**
	 * Sets up gateaway and adds relevant actions/filters
	 */
	function __construct() {
		parent::__construct();
		$this->status_txt = __('Awaiting Sage Pay Payment','em-pro');
		if($this->is_active()) {
			//Booking Interception
			if ( absint(get_option('em_'.$this->gateway.'_booking_timeout')) > 0 ){
				//Modify spaces calculations only if bookings are set to time out, in case pending spaces are set to be reserved.
				add_filter('em_bookings_get_pending_spaces', array(&$this, 'em_bookings_get_pending_spaces'),1,2);
			}
			add_action('em_gateway_js', array(&$this,'em_gateway_js'));
			//Gateway-Specific
			add_action('em_template_my_bookings_header',array(&$this,'say_thanks')); //say thanks on my_bookings page
			add_action('em_template_my_bookings_header',array(&$this,'pay_fail_message')); //display error message back to customer
			add_filter('em_my_bookings_booking_actions', array(&$this,'em_my_bookings_booking_actions'),1,2);
			//set up cron
			$timestamp = wp_next_scheduled('emp_cron_hook');
			if( absint(get_option('em_sagepay_form_booking_timeout')) > 0 && !$timestamp ){
				$result = wp_schedule_event(time(),'em_minute','emp_cron_hook');
			}elseif( !$timestamp ){
				wp_unschedule_event($timestamp, 'emp_cron_hook');
			}
		}else{
			//unschedule the cron
			$timestamp = wp_next_scheduled('emp_cron_hook');
			wp_unschedule_event($timestamp, 'emp_cron_hook');
		}
	}

	/*
	 * --------------------------------------------------
	 * Booking Interception - functions that modify booking object behaviour
	 * --------------------------------------------------
	 */

	/**
	 * Modifies pending spaces calculations to include Sage Pay bookings, but only if Sage Pay bookings are set to time-out (i.e. they'll get deleted after x minutes), therefore can be considered as 'pending' and can be reserved temporarily.
	 * @param integer $count
	 * @param EM_Bookings $EM_Bookings
	 * @return integer
	 */
	function em_bookings_get_pending_spaces($count, $EM_Bookings){
		foreach($EM_Bookings->bookings as $EM_Booking){
			if($EM_Booking->booking_status == $this->status && $this->uses_gateway($EM_Booking)){
				$count += $EM_Booking->get_spaces();
			}
		}
		return $count;
	}

	/**
	 * Intercepts return data after a booking has been made and adds sagepay vars, modifies feedback message.
	 * @param array $return
	 * @param EM_Booking $EM_Booking
	 * @return array
	 */
	function booking_form_feedback( $return, $EM_Booking = false ){
		//Double check $EM_Booking is an EM_Booking object and that we have a booking awaiting payment.
		if( is_object($EM_Booking) && $this->uses_gateway($EM_Booking) ){
			if( !empty($return['result']) && $EM_Booking->get_price() > 0 && $EM_Booking->booking_status == $this->status ){
				$return['message'] = get_option('em_sagepay_form_booking_feedback');
				$sagepay_form_url = $this->get_sagepay_form_url();
				$sagepay_form_vars = $this->get_sagepay_form_vars($EM_Booking);
				$sagepay_form_return = array('sagepay_form_url'=>$sagepay_form_url, 'sagepay_form_vars'=>$sagepay_form_vars);
				$return = array_merge($return, $sagepay_form_return);
			}else{
				//returning a free message
				$return['message'] = get_option('em_sagepay_form_booking_feedback_free');
			}
		}
		return $return;
	}

	/*
	 * --------------------------------------------------
	 * Booking UI - modifications to booking pages and tables containing sage pay bookings
	 * --------------------------------------------------
	 */


	/**
	 * Instead of a simple status string, a resume payment button is added to the status message so user can resume booking from their my-bookings page.
	 * @param string $message
	 * @param EM_Booking $EM_Booking
	 * @return string
	 */
	function em_my_bookings_booking_actions( $message, $EM_Booking){
		//$message = parent::em_my_bookings_booked_message($message, $EM_Booking);
		if($this->uses_gateway($EM_Booking) && $EM_Booking->booking_status == $this->status){
			//user owes money!
			$sagepay_form_vars = $this->get_sagepay_form_vars($EM_Booking);
			$form = '<form action="'.$this->get_sagepay_form_url().'" method="post">';
			foreach($sagepay_form_vars as $key=>$value){
				$form .= '<input type="hidden" name="'.$key.'" value="'.$value.'" />';
			}
			$form .= '<input type="submit" value="'.__('Resume Payment','em-pro').'">';
			$form .= '</form>';
			$message .= $form;
		}
		return $message;
	}

	/**
	 * Outputs extra custom content e.g. the logo by default.
	 */
	function booking_form(){
		echo get_option('em_'.$this->gateway.'_form');
	}

	/**
	 * Outputs some JavaScript during the em_gateway_js action, which is run inside a script html tag, located in gateways/gateway.sageform.js
	 */
	function em_gateway_js(){
		include(dirname(__FILE__).'/gateway.sagepay.form.js');
	}



	/*
	 * ----------------------------------------------------------------------
	 * Sage Pay Form Functions - functions specific to Sage Pay Form payments
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Retreive the sage pay vars needed to send to the gatway to proceed with payment
	 * @param EM_Booking $EM_Booking
	 */
	function get_sagepay_form_vars($EM_Booking){
		global $wp_rewrite, $EM_Notices;

		// The following is lifted straight out of the Sage Pay PHP SDK

		/** First we need to generate a unique VendorTxCode for this transaction **
		*** We're using VendorName, time stamp and a random element.  You can use different methods if you wish **
		*** but the VendorTxCode MUST be unique for each transaction you send to Server **/
		$strTimeStamp = date("ymdHis", time());
		$intRandNum = rand(0,32000)*rand(0,32000);
		$strVendorTxCode= $EM_Booking->booking_id . "-" . $strTimeStamp . "-" . $intRandNum;

		// Now to build the Form crypt field.  For more details see the Form Protocol 2.23
		$strPost="VendorTxCode=" . $strVendorTxCode; /** As generated above **/

		// Optional: If you are a Sage Pay Partner and wish to flag the transactions with your unique partner id, it should be passed here
		if ( strlen( get_option('em_'. $this->gateway . "_partner_id" ) ) > 0) {
				$strPost=$strPost . "&ReferrerID=" . get_option('em_'. $this->gateway . "_partner_id" );
		}

		// Create basket for Sage Reconilation
		$strBasket = '';
		// Basket item seperator expected by sage
		$bask_sep = ':';

		$count = 0;
		foreach( $EM_Booking->get_tickets_bookings()->tickets_bookings as $EM_Ticket_Booking ){

			// divide price by spaces for per-ticket price
			// we divide this way rather than by $EM_Ticket because that can be changed by user in future,
			// yet $EM_Ticket_Booking will change if booking itself is saved.

			$spaces = $EM_Ticket_Booking->get_spaces();
			$price = $EM_Ticket_Booking->get_price() / $spaces;

			if( $price > 0 ){

				// Look for custom attribute that allows Sage stock reconciliation
				$sage_prod_id = '';
				if( array_key_exists('sage_prod_id', $EM_Ticket_Booking->get_booking()->get_event()->event_attributes ) ) {
					$sage_prod_id = '['.$EM_Ticket_Booking->get_booking()->get_event()->event_attributes['sage_prod_id'].']';
				}

				// Item Description
				$strBasket .= strip_tags( $sage_prod_id . $EM_Ticket_Booking->get_booking()->get_event()->event_name.' - '.$EM_Ticket_Booking->get_ticket()->name );
				$strBasket  = str_replace($bask_sep, ' ', $strBasket ); // Remove any colons from within the event and ticket names
				$strBasket .= $bask_sep;
				// Quantity
				$strBasket .= $spaces . $bask_sep;
				// Item Value
				$strBasket .= $EM_Ticket_Booking->get_booking()->get_price_pre_taxes() / $spaces . $bask_sep;
				// Item Tax
				$strBasket .= $EM_Ticket_Booking->get_booking()->get_price_taxes() / $spaces . $bask_sep;
				// Item Total
				$strBasket .= $EM_Ticket_Booking->get_booking()->get_price_post_taxes() / $spaces . $bask_sep;
				// Line Total
				$strBasket .= $EM_Ticket_Booking->get_booking()->get_price_post_taxes() . $bask_sep;

				$count++;
			}
		}

		// strip off final seperator

		$strBasket = substr( $strBasket, 0, -( strlen( $bask_sep ) ) );

		// prepend basket count to strBasket
		$strBasket = $count.$bask_sep.$strBasket;

		$strPost=$strPost . "&Basket=" . $strBasket;

		$strPost=$strPost . "&Amount=" . number_format( $EM_Booking->get_price(), 2); // Formatted to 2 decimal places with leading digit

		$currency = get_option('dbem_bookings_currency', 'GBP');
		$currency = apply_filters('em_gateway_sage_get_currency', $currency, $EM_Booking );
		$strPost=$strPost . "&Currency=" . $currency;

		// Up to 100 chars of free format description
		// Changed for v1.3.1: With site url in description, it can easily surpass the 100 chars.
		// Need a proper fix to strip the string at 100 chars.
		$desc = $EM_Booking->get_event()->event_name;
		if( strlen( $desc ) > 100 ) {
			$desc = substr( $desc, 0, 99 );
		}
		$strPost=$strPost . "&Description=" . $desc;

		$strPost=$strPost . "&SuccessURL=" . $this->get_payment_return_url();
		$strPost=$strPost . "&FailureURL=" . $this->get_payment_return_url();

		// This is an Optional setting. Here we are just using the Billing names given.
		$strPost=$strPost . "&CustomerName=" . substr( $EM_Booking->get_person()->user_firstname . " " . $EM_Booking->get_person()->user_lastname, 0, 100);

		/* Email settings:
		** Flag 'SendEMail' is an Optional setting.
		** 0 = Do not send either customer or vendor e-mails,
		** 1 = Send customer and vendor e-mails if address(es) are provided(DEFAULT).
		** 2 = Send Vendor Email but not Customer Email. If you do not supply this field, 1 is assumed and e-mails are sent if addresses are provided. **/
		$strPost=$strPost . "&SendEMail=" . get_option('em_'. $this->gateway . '_send_email', 1);

		if (strlen( $EM_Booking->get_person()->user_email ) > 0) {
				$strPost=$strPost . "&CustomerEMail=" . $EM_Booking->get_person()->user_email;
		}

		if (strlen( get_option('em_'. $this->gateway . "_vendor_email" )) > 0  ) {
			$strPost=$strPost . "&VendorEMail=" . get_option('em_'. $this->gateway . "_vendor_email" );
		}

		// You can specify any custom message to send to your customers in their confirmation e-mail here
		// The field can contain HTML if you wish, and be different for each order.  This field is optional
		//$strPost=$strPost . "&eMailMessage=Thank you so very much for your order.";


		// Billing Details + Delivery Details: (same as billing as we are not posting anything):
		// Updated for version 1.2

		$delivery = '';

		$names = explode(' ', $EM_Booking->get_person()->get_name());

		if( !empty($names[0]) ) {
			$strPost.= "&BillingFirstnames=" . substr( $names[0], 0, 20);
			$delivery.= "&DeliveryFirstnames=" . substr( array_shift($names), 0, 20);
		}
		if( implode(' ',$names) != '' ) {
			$strPost.= "&BillingSurname=" . substr( implode(' ',$names), 0, 20);
			$delivery.= "&DeliverySurname=" . substr( implode(' ',$names), 0, 20);
		}else{
			// Must have a value for this, so just use username if we don't have a surname
			$strPost.= "&BillingSurname=" . substr( $EM_Booking->get_person()->get_name(), 0, 20);
			$delivery.= "&DeliverySurname=" . substr( $EM_Booking->get_person()->get_name(), 0, 20);
		}
		if( EM_Gateways::get_customer_field('address', $EM_Booking) != '' ) {
			$strPost.= "&BillingAddress1=" . substr( EM_Gateways::get_customer_field('address', $EM_Booking), 0, 100);
			$delivery.= "&DeliveryAddress1=" . substr( EM_Gateways::get_customer_field('address', $EM_Booking), 0, 100);
		}
		if( EM_Gateways::get_customer_field('address_2', $EM_Booking) != '' ) {
			$strPost.= "&BillingAddress2=" . substr( EM_Gateways::get_customer_field('address_2', $EM_Booking), 0, 100);
			$delivery.= "&DeliveryAddress2=" . substr( EM_Gateways::get_customer_field('address_2', $EM_Booking), 0, 100);
		}
		if( EM_Gateways::get_customer_field('city', $EM_Booking) != '' ) {
			$strPost.= "&BillingCity=" . substr( EM_Gateways::get_customer_field('city', $EM_Booking), 0, 40);
			$delivery.= "&DeliveryCity=" . substr( EM_Gateways::get_customer_field('city', $EM_Booking), 0, 40);
		}
		if( EM_Gateways::get_customer_field('zip', $EM_Booking) != '' ) {
			$strPost.= "&BillingPostCode=" . substr( EM_Gateways::get_customer_field('zip', $EM_Booking), 0 , 10);
			$delivery.= "&DeliveryPostCode=" . substr( EM_Gateways::get_customer_field('zip', $EM_Booking), 0 , 10);
		}

		// tmp workaround for us state. v1.3
		if( isset( $EM_Booking->booking_meta['booking']['us_state'] ) ) {
			$strPost.= "&BillingState=" . $EM_Booking->booking_meta['booking']['us_state'];
			$strPost.= "&DeliveryState=" . $EM_Booking->booking_meta['booking']['us_state'];
		}

		if( EM_Gateways::get_customer_field('country', $EM_Booking) != '' ){
			$countries = em_get_countries();

			//$country = $countries[EM_Gateways::get_customer_field('country', $EM_Booking)];
			$country = EM_Gateways::get_customer_field('country', $EM_Booking);
			// Sanitise country codes (EM Countries gives options for England, Scotland, Wales and Northern Ireland which are not ISO compliant and are rejected by Sage).
			if( $country == 'XE' || $country == 'XI' || $country == 'XS' || $country == 'XW') {
				$country = 'GB';
			}

			$strPost.= "&BillingCountry=" . $country;
			$delivery.= "&DeliveryCountry=" . $country;
		}

		$strPost.= $delivery;

		// For charities registered for Gift Aid, set to 1 to display the Gift Aid check box on the payment pages
		$strPost=$strPost . "&AllowGiftAid=0";

		/* Allow fine control over AVS/CV2 checks and rules by changing this value. 0 is Default
		** It can be changed dynamically, per transaction, if you wish.  See the Server Protocol document */
		if ( get_option('em_'. $this->gateway . '_transaction_type') !== "AUTHENTICATE") {
			$strPost=$strPost . "&ApplyAVSCV2=0";
		}

		/* Allow fine control over 3D-Secure checks and rules by changing this value. 0 is Default
		** It can be changed dynamically, per transaction, if you wish.  See the Form Protocol document */
		$strPost=$strPost . "&Apply3DSecure=0";

		require_once('sagepay/sagePayForm.php');
		$sagePayForm = new SagePayForm( $this->gateway );

		$sagepay_vars = array(
			"VPSProtocol" => "2.23",
			"TxType" => get_option('em_'. $this->gateway . '_transaction_type', 'PAYMENT'),
			'vendor' => get_option('em_'. $this->gateway . "_vendor" )
		);

		$sagepay_vars['Crypt'] = $sagePayForm->encryptAndEncode($strPost);

		return apply_filters('em_gateway_sagepay_get_sagepay_vars', $sagepay_vars, $EM_Booking, $this);
	}

	/**
	 * gets sage pay gateway url (sandbox or live mode)
	 * @returns string
	 */
	function get_sagepay_form_url(){
		if ( get_option('em_'. $this->gateway . "_status" ) == "live") {
			return "https://live.sagepay.com/gateway/service/vspform-register.vsp";
		}
		elseif (get_option('em_'. $this->gateway . "_status" )=="test") {
			return "https://test.sagepay.com/gateway/service/vspform-register.vsp";
		} else {
			return "https://test.sagepay.com/simulator/vspformgateway.asp";
		}
	}

	function say_thanks(){
		if( $_REQUEST['thanks'] == 1 ){
			echo "<div class='em-booking-message em-booking-message-success'>".get_option('em_'.$this->gateway.'_booking_feedback_thanks').'</div>';
		}
	}

	function pay_fail_message() {
		if( isset( $_REQUEST['fail'] ) ) {
			echo "<div class='em-booking-message em-booking-message-error'><p>Payment Unsuccessful</p><p>";
			switch ($_REQUEST['fail']) {
				case "NOTAUTHED":
					echo "You payment was declined by the bank.  This could be due to insufficient funds, or incorrect card details.";
					break;
				case "ABORT":
					echo "You chose to Cancel your order on the Sage Pay payment pages.";
					break;
				case "REJECTED":
					echo "Your order did not meet the minimum fraud screening requirements.";
					break;
				case "INVALID":
				case "MALFORMED":
					echo "We could not process your order because we have been unable to register your transaction with our Payment Gateway.";
					break;
				case "ERROR":
					echo "We could not process your order because our Payment Gateway service was experiencing difficulties.";
					break;
			}
			echo "</p></div>";
		}
	}

	/**
	 * Runs when user returns from Sage Pay with transaction result. Bookings are updated and transactions are recorded accordingly.
	 */
	function handle_payment_return() {

		// Now check we have a Crypt field passed to this page
		$strCrypt=$_REQUEST["crypt"];
		if (strlen($strCrypt)==0) {
			echo 'Error: Empty Sage Pay request, crypt missing.';
			exit;
		}

		require_once('sagepay/sagePayForm.php');
		$sagePayForm = new SagePayForm( $this->gateway );

		// Now decode the Crypt field and extract the results
		$strDecoded = $sagePayForm->decodeAndDecrypt( $strCrypt );

		$values = $sagePayForm->getToken( $strDecoded );
		// Split out the useful information into variables we can use
		$strStatus = $values['Status'];
		$strStatusDetail = $values['StatusDetail'];
		$strVendorTxCode = $values["VendorTxCode"];
		$strVPSTxId = $values["VPSTxId"];
		$strTxAuthNo = $values["TxAuthNo"];
		$strAmount = $values["Amount"];
		$strAVSCV2 = $values["AVSCV2"];
		$strAddressResult = $values["AddressResult"];
		$strPostCodeResult = $values["PostCodeResult"];
		$strCV2Result = $values["CV2Result"];
		$strGiftAid = $values["GiftAid"];
		$str3DSecureStatus = $values["3DSecureStatus"];
		$strCAVV = $values["CAVV"];
		$strCardType = $values["CardType"];
		$strLast4Digits = $values["Last4Digits"];

		// Remove number formatting from $amount
		// Ideally would like to use numfmt_parse, but that is 5.3 up, so a risk for EM customers
		$strAmount = str_replace(',', '', $strAmount);

		// Load the relevant booking
		$arrVendorTxCode = explode( '-', $strVendorTxCode );

		$EM_Booking = em_get_booking( $arrVendorTxCode[0] );

		// Data we can't get from sage
		$timestamp = date('Y-m-d H:i:s');  // We have no timestamp from sage, so take now
		$currency = get_option('dbem_bookings_currency','GBP');
		$currency = apply_filters('em_gateway_sage_get_currency', $currency, $EM_Booking );

		if( !empty($EM_Booking->booking_id) ){
			//booking exists
			$strReason = '';

			switch( $strStatus ) {
				case "OK":
					// case: successful payment
					$this->record_transaction($EM_Booking, $strAmount, $currency, $timestamp, $strVPSTxId, $strStatus, '');
					//get booking metadata
					$user_data = array();
					if( !empty($EM_Booking->booking_meta['registration']) && is_array($EM_Booking->booking_meta['registration']) ){
						foreach($EM_Booking->booking_meta['registration'] as $fieldid => $field){
							if( trim($field) !== '' ){
								$user_data[$fieldid] = $field;
							}
						}
					}
					if( $strAmount >= $EM_Booking->get_price(false, false, true) && (!get_option('em_'.$this->gateway.'_manual_approval', false) || !get_option('dbem_bookings_approval')) ){
						$EM_Booking->approve();
					}else{
						//TODO do something if sage pay payment not enough
						$EM_Booking->set_status(0); //Set back to normal "pending"
					}
					do_action('em_payment_processed', $EM_Booking, $this);



					// Redirect to custom page, or default thanks message
					$redirect = get_option('em_'. $this->gateway . '_return_success');
					if( empty( $redirect ) ) {
						$redirect = get_permalink(get_option("dbem_my_bookings_page")).'?thanks=1';
					}

					header('Location: '.$redirect);
					return;

				case "NOTAUTHED": // Leaving all cases in for future reference
				case "ABORT":
				case "REJECTED":
				case "INVALID":
				case "MALFORMED":
				case "ERROR":
				default:
					$this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $strVPSTxId, $strStatus, $strStatusDetail);

					$EM_Booking->cancel();
					do_action( 'em_payment_'.strtolower($strStatus), $EM_Booking, $this);

					// Redirect to custom page, or my bookings with error

					$redirect = get_option('em_'. $this->gateway . '_return_fail');

					if( empty( $redirect ) ) {
						$redirect = get_permalink(get_option("dbem_my_bookings_page")).'?fail='.$strStatus;
					}
					header('Location: '.$redirect);
					return;
			}

		}else{
			if( $strStatus == 'OK' ){

				$message = apply_filters('em_gateway_paypal_bad_booking_email',"
A Payment has been received by Sage Pay for a non-existent booking.

Event Details : %event%

It may be that this user's booking has timed out yet they proceeded with payment at a later stage.

To refund this transaction, you must go to your SagePay account and search for this transaction:

Transaction ID : %transaction_id%

When viewing the transaction details, you should see an option to issue a refund.

If there is still space available, the user must book again.

Sincerely,
Events Manager
					", $booking_id, $event_id);

				if( !empty($event_id) ){
					$EM_Event = new EM_Event($event_id);
					$event_details = $EM_Event->name . " - " . date_i18n(get_option('date_format'), $EM_Event->start);
				}else{
					$event_details = __('Unknown','em-pro');
				}
				$message  = str_replace(array('%transaction_id%', '%event%'), array($strVPSTxId, $event_details), $message);
				wp_mail(get_option('em_'. $this->gateway . "_email" ), __('Unprocessed payment needs refund'), $message);
			}else{
				echo 'Error: Bad Sage Pay request, custom ID does not correspond with any pending booking.';
				exit;
			}
		}
		return;
	}

	/*
	 * --------------------------------------------------
	 * Gateway Settings Functions
	 * --------------------------------------------------
	 */

	/**
	 * Outputs custom Sage Pay setting fields in the settings page
	 */
	function mysettings() {
		global $EM_options;
		?>
		<table class="form-table">
		<tbody>
			<tr valign="top">
				<th scope="row"><?php _e('Success Message', 'em-pro') ?></th>
				<td>
					<input type="text" name="sagepay_form_booking_feedback" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('The message that is shown to a user when a booking is successful whilst being redirected to Sage Pay for payment.','em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Success Free Message', 'em-pro') ?></th>
				<td>
					<input type="text" name="sagepay_form_booking_feedback_free" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback_free" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('If some cases if you allow a free ticket (e.g. pay at gate) as well as paid tickets, this message will be shown and the user will not be redirected to Sage Pay.','em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Thank You Message', 'em-pro') ?></th>
				<td>
					<input type="text" name="sagepay_form_booking_feedback_thanks" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback_thanks" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('If you choose to return users to the default Events Manager thank you page after a user has paid on Sage Pay, you can customize the thank you message here.','em-pro'); ?></em>
				</td>
			</tr>
		</tbody>
		</table>

		<h3><?php echo sprintf(__('%s Options','em-pro'),'Sage Pay (Form)'); ?></h3>

		<table class="form-table">
		<tbody>
			<tr valign="top">
				<th scope="row"><?php _e('Sage Pay Vendor Name', 'em-pro') ?></th>
					<td><input type="text" name="sagepay_form_vendor" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_vendor" )); ?>" />
					<br />
					<em><?php _e('Set this value to the Vendor Name assigned to you by Sage Pay or chosen when you applied', 'em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Sage Pay Mode', 'em-pro') ?></th>
				<td>
					<select name="sagepay_form_status">
						<option value="live" <?php if (get_option('em_'. $this->gateway . "_status" ) == 'live') echo 'selected="selected"'; ?>><?php _e('Live Site', 'em-pro') ?></option>
						<option value="test" <?php if (get_option('em_'. $this->gateway . "_status" ) == 'test') echo 'selected="selected"'; ?>><?php _e('Test Site', 'em-pro') ?></option>
						<option value="simulator" <?php if (get_option('em_'. $this->gateway . "_status" ) == 'simulator') echo 'selected="selected"'; ?>><?php _e('Simulator', 'em-pro') ?></option>
					</select>
					<br />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Sage Pay XOR Encryption password', 'em-pro') ?></th>
					<td><input type="text" name="sagepay_form_encryption_pass" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_encryption_pass" )); ?>" />
					<br />
					<em><?php _e('Set this value to the XOR Encryption password assigned to you by Sage Pay', 'em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Sage Pay Currency', 'em-pro') ?></th>
				<td><?php echo esc_html(get_option('dbem_bookings_currency','GBP')); ?><br /><i><?php echo sprintf(__('Set your currency in the <a href="%s">settings</a> page.','dbem'),EM_ADMIN_URL.'&amp;page=events-manager-options'); ?></i></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Sage Pay Transaction Type', 'em-pro') ?></th>
				<td>
					<select name="sagepay_form_transaction_type">
						<option value="PAYMENT" <?php if (get_option('em_'. $this->gateway . "_transaction_type" ) == 'PAYMENT') echo 'selected="selected"'; ?>><?php _e('Payment', 'em-pro') ?></option>
						<option value="DEFERRED" <?php if (get_option('em_'. $this->gateway . "_transaction_type" ) == 'DEFERRED') echo 'selected="selected"'; ?>><?php _e('Deferred', 'em-pro') ?></option>
						<option value="AUTHENTICATE" <?php if (get_option('em_'. $this->gateway . "_transaction_type" ) == 'AUTHENTICATE') echo 'selected="selected"'; ?>><?php _e('Authenticate', 'em-pro') ?></option>
					</select>
					<br />
					<em><?php _e('This can be DEFERRED or AUTHENTICATE if your Sage Pay account supports those payment types. If unsure, leave as PAYMENT', 'em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Sage Pay Email Options', 'em-pro') ?></th>
				<td>
					<select name="sagepay_form_send_email">
						<option value="1" <?php if (get_option('em_'. $this->gateway . "_send_email" ) == 1) echo 'selected="selected"'; ?>><?php _e('Vendor & Customer', 'em-pro') ?></option>
						<option value="2" <?php if (get_option('em_'. $this->gateway . "_send_email" ) == 2) echo 'selected="selected"'; ?>><?php _e('Vendor Only', 'em-pro') ?></option>
						<option value="0" <?php if (get_option('em_'. $this->gateway . "_send_email" ) == 0) echo 'selected="selected"'; ?>><?php _e('None', 'em-pro') ?></option>
					</select>
					<br />
					<em><?php _e('Define who to send email to. For the vendor to be sent the email, enter vendor email below.', 'em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Sage Pay Vendor Email', 'em-pro') ?></th>
					<td><input type="text" name="sagepay_form_vendor_email" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_vendor_email" )); ?>" />
					<br />
					<em><?php _e('Set this to the mail address which will receive order confirmations and failures.', 'em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Sage Pay Partner ID', 'em-pro') ?></th>
					<td><input type="text" name="sagepay_form_partner_id" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_partner_id" )); ?>" />
					<br />
					<em><?php _e('Optional setting. If you are a Sage Pay Partner and wish to flag the transactions with your unique partner id set it here.', 'em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Sage Pay Encryption Type', 'em-pro') ?></th>
				<td>
					<select name="sagepay_form_encryption_type">
						<option value="AES" <?php if (get_option('em_'. $this->gateway . "_encryption_type" ) == 'AES') echo 'selected="selected"'; ?>><?php _e('AES', 'em-pro') ?></option>
						<option value="XOR" <?php if (get_option('em_'. $this->gateway . "_encryption_type" ) == 'XOR') echo 'selected="selected"'; ?>><?php _e('XOR', 'em-pro') ?></option>
					</select>
					<br />
					<em><?php _e('Encryption type should be left set to AES unless you are experiencing problems and have been told by SagePay support to change it.', 'em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Return Success URL', 'em-pro') ?></th>
				<td>
					<input type="text" name="sagepay_form_return_success" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_return_success" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('Once a payment is completed, users will sent to the My Bookings page which confirms that the payment has been made. If you would to customize the thank you page, create a new page and add the link here. Leave blank to return to default booking page with the thank you message specified above.', 'em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Return Fail URL', 'em-pro') ?></th>
				<td>
					<input type="text" name="sagepay_form_return_fail" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_return_fail" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('If a payment is unsucessful or if a user cancels, they will be redirected to the my bookings page. If you want a custom page instead, create a new page and add the link here.', 'em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Delete Bookings Pending Payment', 'em-pro') ?></th>
				<td>
					<input type="text" name="sagepay_form_booking_timeout" style="width:50px;" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_timeout" )); ?>" style='width: 40em;' /> <?php _e('minutes','em-pro'); ?><br />
					<em><?php _e('Once a booking is started and the user is taken to Sage Pay, Events Manager stores a booking record in the database to identify the incoming payment. These spaces may be considered reserved if you enable <em>Reserved unconfirmed spaces?</em> in your Events &gt; Settings page. If you would like these bookings to expire after x minutes, please enter a value above (note that bookings will be deleted, and any late payments will need to be refunded manually via Sage Pay).','em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Manually approve completed transactions?', 'em-pro') ?></th>
				<td>
					<input type="checkbox" name="sagepay_form_manual_approval" value="1" <?php echo (get_option('em_'. $this->gateway . "_manual_approval" )) ? 'checked="checked"':''; ?> /><br />
					<em><?php _e('By default, when someone pays for a booking, it gets automatically approved once the payment is confirmed. If you would like to manually verify and approve bookings, tick this box.','em-pro'); ?></em><br />
					<em><?php echo sprintf(__('Approvals must also be required for all bookings in your <a href="%s">settings</a> for this to work properly.','em-pro'),EM_ADMIN_URL.'&amp;page=events-manager-options'); ?></em>
				</td>
			</tr>
		</tbody>
		</table>

		<h3><?php _e('Sage Stock Reconciliation'); ?></h3>

		<p><?php _e('It is possible to enable stock reconciliation via Sage Pay and Sage accounting products by adding Sage defined Product ids to events. These will then be transmitted to Sage Pay as part of the basket breakdown submitted to Sage Pay.') ?></p>
		<p><?php _e('To make use of this feature, Enable Event Attributes via the settings page and add the attribute #_ATT{sage_prod_id}. This will then be able to define the product id per event so that this matches the settings within the Sage Accountancy Software.') ?></p>
		<?php
	}

	/*
	 * Run when saving Sage Pay settings, saves the settings available in EM_Gateway_SagePay_Form::mysettings()
	 */
	function update() {

//add_option('em_user_fields', array ( 'dbem_address_1' => array ( 'label' => __('Address 1','dbem'), 'type' => 'text', 'fieldid'=>'dbem_address_1' )) );

		parent::update();
		if( !empty($_REQUEST[$this->gateway.'_vendor']) ) {
			$gateway_options = array(
				$this->gateway . "_vendor" => $_REQUEST[ $this->gateway.'_vendor' ],
				//$this->gateway . "_site" => $_REQUEST[ $this->gateway.'_site' ],
				$this->gateway . "_encryption_pass" => $_REQUEST[ $this->gateway.'_encryption_pass' ],
				//$this->gateway . "_currency" => $_REQUEST[ 'currency' ],
				$this->gateway . "_status" => $_REQUEST[ $this->gateway.'_status' ],
				$this->gateway . "_transaction_type" => $_REQUEST[ $this->gateway.'_transaction_type' ],
				$this->gateway . "_partner_id" => $_REQUEST[ $this->gateway.'_partner_id' ],
				$this->gateway . "_send_email" => $_REQUEST[ $this->gateway.'_send_email' ],
				$this->gateway . "_vendor_email" => $_REQUEST[ $this->gateway.'_vendor_email' ],
				$this->gateway . "_encryption_type" => $_REQUEST[ $this->gateway.'_encryption_type' ],
				$this->gateway . "_manual_approval" => $_REQUEST[ $this->gateway.'_manual_approval' ],
				$this->gateway . "_booking_feedback" => wp_kses_data($_REQUEST[ $this->gateway.'_booking_feedback' ]),
				$this->gateway . "_booking_feedback_free" => wp_kses_data($_REQUEST[ $this->gateway.'_booking_feedback_free' ]),
				$this->gateway . "_booking_feedback_thanks" => wp_kses_data($_REQUEST[ $this->gateway.'_booking_feedback_thanks' ]),
				$this->gateway . "_booking_timeout" => $_REQUEST[ $this->gateway.'_booking_timeout' ],
				$this->gateway . "_return_success" => $_REQUEST[ $this->gateway.'_return_success' ],
				$this->gateway . "_return_fail" => $_REQUEST[ $this->gateway.'_return_fail' ],
				$this->gateway . "_form" => $_REQUEST[ $this->gateway.'_form' ]
			);
			foreach($gateway_options as $key=>$option){
				update_option('em_'.$key, stripslashes($option));
			}
		}
		//default action is to return true
		return true;
	}
}
EM_Gateways::register_gateway('sagepay_form', 'EM_Gateway_SagePay_Form');

/**
 * Deletes bookings pending payment that are more than x minutes old, defined by Sage Pay options.
 */
function em_gateway_sagepay_form_booking_timeout(){
	global $wpdb;
	//Get a time from when to delete
	$minutes_to_subtract = absint(get_option('em_sagepay_form_booking_timeout'));
	if( $minutes_to_subtract > 0 ){
		//Run the SQL query
		//first delete ticket_bookings with expired bookings
		$sql = "DELETE FROM ".EM_TICKETS_BOOKINGS_TABLE." WHERE booking_id IN (SELECT booking_id FROM ".EM_BOOKINGS_TABLE." WHERE booking_date < TIMESTAMPADD(MINUTE, -{$minutes_to_subtract}, NOW()) AND booking_status=4);";
		$wpdb->query($sql);
		//then delete the bookings themselves
		$sql = "DELETE FROM ".EM_BOOKINGS_TABLE." WHERE booking_date < TIMESTAMPADD(MINUTE, -{$minutes_to_subtract}, NOW()) AND booking_status=4;";
		$wpdb->query($sql);
		update_option('emp_result_try',$sql);
	}
}
add_action('emp_cron_hook', 'em_gateway_sagepay_form_booking_timeout');
