<style>
    .form-row{
        margin: 0 0 6px !important;
        padding: 3px !important;
    }
    
    .form-row select{
        width: 100% !important;
        margin-top: 1em;
    }
    
    .openpay-select { 
        color: #444;
        line-height: 28px;
        border-radius: 2px !important;
        padding: 5px 10px !important;        
        border: solid 2px #e4e4e4;
    }    
</style>
<fieldset>
    <?php if ($this->description): ?>
        <?php echo wpautop(esc_html($this->description)); ?>
    <?php endif; ?>
    <div class="form-row form-row-wide">        
        <select name="openpay_cc" id="openpay_cc" class="openpay-select">
            <?php foreach($this->cc_options as $cc): ?>
                <option value="<?php echo $cc['value'] ?>"><?php echo $cc['name'] ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="openpay_new_card"
         data-description=""
         data-amount="<?php echo $this->get_openpay_amount(WC()->cart->total); ?>"
         data-name="<?php echo sprintf(__('%s', 'openpay-woosubscriptions'), get_bloginfo('name')); ?>"
         data-label="<?php _e('Confirm and Pay', 'openpay-woosubscriptions'); ?>"
         data-currency="<?php echo strtolower(get_woocommerce_currency()); ?>"
    >
        <?php $this->cc_form->form() ?>        
    </div>
    <input type="hidden" name="device_session_id" id="device_session_id" />
    <div style="display: flex; justify-content: center;">
        Transacciones realizadas vía: <img alt="" src="<?php echo $this->images_dir ?>openpay.png" style="float: none; display: inline; height: 30px; margin-left: 8px;">
    </div>
</fieldset>