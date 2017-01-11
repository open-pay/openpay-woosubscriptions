OpenPay.setId(wc_openpay_params.merchant_id);
OpenPay.setApiKey(wc_openpay_params.public_key);
OpenPay.setSandboxMode(wc_openpay_params.sandbox_mode);


jQuery(function() {
    
    /* Checkout Form */
    jQuery('form.checkout').on('checkout_place_order_openpay', function(event) {
        return openpayFormHandler();
    });

    /* Both Forms */
    jQuery("form.checkout, form#order_review").on('change', '#openpay-card-number, #openpay-card-expiry, #openpay-card-cvc, input[name=openpay_card_id]', function(event) {
        //jQuery('#openpay_token').val("");
        jQuery('#openpay_token').remove();
        jQuery('#device_session_id').remove();        
        jQuery('.woocommerce_error, .woocommerce-error, .woocommerce-message, .woocommerce_message').remove();
    });

});

function openpayFormHandler() {
    if (jQuery('#payment_method_openpay').is(':checked')) {
        
        if (!jQuery('#openpay_token').val()) {
            var card = jQuery('#openpay-card-number').val();
            var cvc = jQuery('#openpay-card-cvc').val();
            var expires = jQuery('#openpay-card-expiry').payment('cardExpiryVal');
            var $form = jQuery("form.checkout, form#order_review");
            
            $form.block({message: null, overlayCSS: {background: '#fff url(' + woocommerce_params.ajax_loader_url + ') no-repeat center', opacity: 0.6}});

            var str = expires['year'];
            var year = str.toString().substring(2, 4);
            
            
            var data = {
                card_number: card.replace(/ /g,''),
                cvv2: cvc,
                expiration_month: expires['month'] || 0,
                expiration_year: year || 0,
                address: {}
            };
            
            if (jQuery('#billing_first_name').size() > 0) {
                data.holder_name = jQuery('#billing_first_name').val() + ' ' + jQuery('#billing_last_name').val();
            } else if (wc_openpay_params.billing_first_name) {
                data.holder_name = wc_openpay_params.billing_first_name + ' ' + wc_openpay_params.billing_last_name;
            }

            if (jQuery('#billing_address_1').size() > 0) {
                data.address.line1 = jQuery('#billing_address_1').val();
                data.address.line2 = jQuery('#billing_address_2').val();
                data.address.state = jQuery('#billing_state').val();
                data.address.city = jQuery('#billing_city').val();
                data.address.postal_code = jQuery('#billing_postcode').val();
                data.address.country_code = 'MX';
            } else if (data.address.line1) {
                data.address.line1 = wc_openpay_params.billing_address_1;
                data.address.line2 = wc_openpay_params.billing_address_2;
                data.address.state = wc_openpay_params.billing_state;
                data.address.city = wc_openpay_params.billing_city;
                data.address.postal_code = wc_openpay_params.billing_postcode;
                data.address.country_code = 'MX';
            }
            
            OpenPay.token.create(data, success_callbak, error_callbak);                        
            return false;
        }
    }
    return true;
}


function success_callbak(response) {
    var $form = jQuery("form.checkout, form#order_review");
    var token = response.data.id;
    var deviceSessionId = OpenPay.deviceData.setup();

    $form.append('<input type="hidden" id="openpay_token" name="openpay_token" value="' + escape(token) + '" />');
    $form.append('<input type="hidden" id="device_session_id" name="device_session_id" value="' + escape(deviceSessionId) + '" />');    
    $form.submit();
};


function error_callbak(response) {
    var $form = jQuery("form.checkout, form#order_review");
    var msg = "";
    switch(response.data.error_code){
        case 1000:
            msg = "Servicio no disponible.";
            break;

        case 1001:
            msg = "Los campos no tienen el formato correcto, o la petición no tiene campos que son requeridos.";
            break;

        case 1004:
            msg = "Servicio no disponible.";
            break;

        case 1005:
            msg = "Servicio no disponible.";
            break;

        case 2004:
            msg = "El dígito verificador del número de tarjeta es inválido de acuerdo al algoritmo Luhn.";
            break;    

        case 2005:
            msg = "La fecha de expiración de la tarjeta es anterior a la fecha actual.";
            break;

        case 2006:
            msg = "El código de seguridad de la tarjeta (CVV2) no fue proporcionado.";
            break;

        default: //Demás errores 400 
            msg = "La petición no pudo ser procesada.";
            break;
    }

    // show the errors on the form
    jQuery('.woocommerce_error, .woocommerce-error, .woocommerce-message, .woocommerce_message').remove();
    jQuery('#openpay-card-number').closest('p').before('<ul style="background-color: #e2401c; color: #fff;" class="woocommerce_error woocommerce-error"><li> ERROR ' + response.data.error_code + '. '+msg+'</li></ul>');
    $form.unblock();
    
};
