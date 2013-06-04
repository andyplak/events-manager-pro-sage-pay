//add sagepay redirection
$(document).bind('em_booking_gateway_add_sagepay_form', function(event, response){

	// called by EM if return JSON contains gateway key, notifications messages are shown by now.
	if(response.result){
		var spForm = $('<form action="'+response.sagepay_form_url+'" method="post" id="em-sagepay-form-redirect-form"></form>');
		$.each( response.sagepay_form_vars, function(index,value){
			spForm.append('<input type="hidden" name="'+index+'" value="'+value+'" />');
		});
		spForm.append('<input id="em-paypal-submit" type="submit" style="display:none" />');
		spForm.appendTo('body').trigger('submit');
	}
});