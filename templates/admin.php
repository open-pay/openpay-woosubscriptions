<?php
/*  
  Title:	Openpay WooSubscriptions extension for WooCommerce
  Author:	Openpay
  URL:		http://www.openpay.mx
  License: GNU General Public License v3.0
  License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */
?>

<h3>
    <?php _e('Openpay WooSubscriptions', 'openpay-woosubscriptions'); ?>
</h3>

<?php if(!$this->validateCurrency()): ?>
    <?php if($this->country != 'MX'): ?>
        <div class="inline error">Openpay WooSubscriptions Plugin is only available for MX currency.</div>
    <?php else: ?>
        <div class="inline error">Openpay WooSubscriptions Plugin is only available for COP currency.</div>
    <?php endif; ?>
<?php endif; ?>

<p><?php _e('Our WooSubscriptions plugin enables to setup your eCommerce so it can use Openpay as the payment platform to process those recurrent charges.', 'woothemes'); ?></p>


<table class="form-table">
    <?php $this->generate_settings_html(); ?>
</table>