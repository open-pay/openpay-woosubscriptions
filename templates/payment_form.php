<fieldset>
    <?php if ($this->description): ?>
        <?php echo wpautop(esc_html($this->description)); ?>
    <?php endif; ?>
    <div class="openpay_new_card"
         data-description=""
         data-amount="<?php echo $this->get_openpay_amount(WC()->cart->total); ?>"
         data-name="<?php echo sprintf(__('%s', 'openpay-woosubscriptions'), get_bloginfo('name')); ?>"
         data-label="<?php _e('Confirm and Pay', 'openpay-woosubscriptions'); ?>"
         data-currency="<?php echo strtolower(get_woocommerce_currency()); ?>"
    >
        <?php $this->cc_form->form() ?>        
    </div>
</fieldset>