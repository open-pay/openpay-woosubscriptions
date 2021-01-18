jQuery(document).ready(function () {
    var country = jQuery('#woocommerce_openpay_country').val();
    console.log('admin.js', country);
    showOrHideElements(country);

    function showOrHideElements(country) {
        if(country === 'MX'){
            jQuery("#woocommerce_openpay_iva").closest("tr").hide();
        }else if(country === 'CO'){
            jQuery("#woocommerce_openpay_iva").closest("tr").show();
        }
    }

    jQuery('#woocommerce_openpay_country').change(function () {
        var country = jQuery(this).val();
        console.log('woocommerce_openpay_country', country);        

        showOrHideElements(country)
    });

    if(jQuery("#woocommerce_openpay_sandbox").length){
        is_sandbox();

        jQuery("#woocommerce_openpay_sandbox").on("change", function(e){
            is_sandbox();
        });
    }

    function is_sandbox(){
        sandbox = jQuery("#woocommerce_openpay_sandbox").is(':checked');
        if(sandbox){
            jQuery("input[name*='live']").parent().parent().parent().hide();
            jQuery("input[name*='test']").parent().parent().parent().show();
        }else{
            jQuery("input[name*='test']").parent().parent().parent().hide();
            jQuery("input[name*='live']").parent().parent().parent().show();
        }
    }
});