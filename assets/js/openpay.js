OpenPay.setId(wc_openpay_params.merchant_id);
OpenPay.setApiKey(wc_openpay_params.public_key);
OpenPay.setSandboxMode(wc_openpay_params.sandbox_mode);
var deviceSessionId = OpenPay.deviceData.setup();

jQuery(function() {
    jQuery('#device_session_id').val(deviceSessionId);
    var $form = jQuery('form.checkout,form#order_review');

    jQuery("form.checkout, form#order_review").on('focus', '#openpay-card-cvc', function(event) {
        jQuery("#openpay-card-cvc").attr('type','password');
    });

    /* Checkout Form */
    jQuery('form.checkout').on('checkout_place_order_openpay', function(event) {
        if (jQuery('#openpay_cc').val() !== 'new') {
            $form.append('<input type="hidden" name="openpay_token" value="' + jQuery('#openpay_cc').val() + '" />');
            return true;
        }
        return openpayFormHandler();
    });

    jQuery('body').on('click', 'form.checkout button:submit', function () {
        jQuery('.woocommerce_error, .woocommerce-error, .woocommerce-message, .woocommerce_message').remove();
        // Make sure there's not an old token on the form
        jQuery('form.checkout').find('[name=openpay_token]').remove();

        if (jQuery('input[name=payment_method]:checked').val() == 'openpay') {
            console.log("Verifying card data");
            return CardsErrorHandler();
        }

    });

    if(jQuery('#openpay_cc').children('option').length > 1){
        jQuery('#openpay_cc').parent().show();
    }else{
        jQuery('#openpay_cc').parent().hide();
    }

    jQuery(document).on("change", "#openpay_cc", function() {   
        if (jQuery('#openpay_cc').val() !== "new") {  
                                                          
            jQuery('#openpay-card-number').val("");                                     
            jQuery('#openpay-card-expiry').val("");            
            jQuery('#openpay-card-cvc').val("");                                                         
                            
            //jQuery('.openpay_new_card').hide();
            jQuery('#openpay-card-number').parent().hide();
            jQuery('#openpay-card-expiry').parent().hide();
            jQuery('#openpay-card-cvc').parents('p').removeClass("form-row-last");
        } else {                    
            //jQuery('.openpay_new_card').show();
            jQuery('#openpay-card-number').parent().show();
            jQuery('#openpay-card-expiry').parent().show();
            jQuery('#openpay-card-cvc').parents('p').addClass("form-row-last");
        }
    });

    jQuery('form#order_review').on('submit', function(event) {
        if (jQuery('#openpay_cc').val() !== 'new') {
            $form.append('<input type="hidden" name="openpay_token" value="' + jQuery('#openpay_cc').val() + '" />');
            return true;
        }
        return openpayFormHandler();
    });

    /* Both Forms */
    jQuery("form.checkout, form#order_review").on('change', '#openpay-card-number, #openpay-card-expiry, #openpay-card-cvc, input[name=openpay_card_id]', function(event) {
        //jQuery('#openpay_token').val("");
        jQuery('#openpay_token').remove();
        jQuery('.woocommerce_error, .woocommerce-error, .woocommerce-message, .woocommerce_message').remove();
    });


});

function CardsErrorHandler (){
    // Check if cvv is not empty
    if (jQuery('#openpay_cc').val() !== "new" &&  jQuery('#openpay-card-cvc').val().length < 3) {
        error_callbak({data:{error_code:2006}});
        return false;
    }
}

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
            };
            
            if (jQuery('#billing_first_name').length) {
                data.holder_name = jQuery('#billing_first_name').val() + ' ' + jQuery('#billing_last_name').val();
            } else if (wc_openpay_params.billing_first_name) {
                data.holder_name = wc_openpay_params.billing_first_name + ' ' + wc_openpay_params.billing_last_name;
            }

            if (jQuery('#billing_address_1').length) {
                if(jQuery('#billing_address_1').val() && jQuery('#billing_state').val() && jQuery('#billing_city').val() && jQuery('#billing_postcode').val()) {
                    data.address = {};
                    data.address.line1 = jQuery('#billing_address_1').val();
                    data.address.line2 = jQuery('#billing_address_2').val();
                    data.address.state = jQuery('#billing_state').val();
                    data.address.city = jQuery('#billing_city').val();
                    data.address.postal_code = jQuery('#billing_postcode').val();
                    data.address.country_code = jQuery('#billing_country').val();
                }
            } else if (wc_openpay_params.billing_address_1) {
                data.address = {};
                data.address.line1 = wc_openpay_params.billing_address_1;
                data.address.line2 = wc_openpay_params.billing_address_2;
                data.address.state = wc_openpay_params.billing_state;
                data.address.city = wc_openpay_params.billing_city;
                data.address.postal_code = wc_openpay_params.billing_postcode;
                data.address.country_code = wc_openpay_params.billing_country;
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

    $form.append('<input type="hidden" id="openpay_token" name="openpay_token" value="' + escape(token) + '" />'); 
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
