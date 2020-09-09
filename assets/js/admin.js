jQuery(document).ready(function () {
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