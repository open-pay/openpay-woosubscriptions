<?php

/*
  Plugin Name: Openpay WooSubscriptions Plugin
  Plugin URI: https://github.com/open-pay/openpay-woosubscriptions
  Description: Este plugin soporta suscripciones a través de Openpay utilizando WooCommerce y WooCommerce Subscriptions
  Version: 3.2.2
  Author: Openpay
  Author URI: https://openpay.mx
  Developer: Openpay
  
  WC requires at least: 3.0
  WC tested up to: 9.1.*

  Copyright: © 2009-2014 WooThemes.
  License: GNU General Public License v3.0
  License URI: http://www.gnu.org/licenses/gpl-3.0.html

  Openpay Docs: http://www.openpay.mx/docs/
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Required functions
 */
if (!function_exists('woothemes_queue_update')) {
    require_once( 'woo-includes/woo-functions.php' );
}


add_action('woocommerce_api_openpay_confirm', 'openpay_woocommerce_confirm', 10, 0);
//add_action('template_redirect',array($this, 'wc_custom_redirect_after_purchase'),0 );


add_action('template_redirect', 'wc_custom_redirect_after_purchase', 0);
function wc_custom_redirect_after_purchase() {
    ob_start();
    global $wp;
    $logger = wc_get_logger();
    if (is_checkout() && !empty($wp->query_vars['order-received'])) {
        $order = new WC_Order($wp->query_vars['order-received']);
        $redirect_url = get_post_meta($order->get_id(), '_openpay_3d_secure_url', true);
        $logger->debug('wc_custom_redirect_after_purchase ');
        $logger->debug('3DS_redirect_url : ' .  $redirect_url);
        $logger->debug('order_status : ' .  $order->get_status());

        if ($redirect_url && $order->get_status() != 'processing') {
            $order->delete_meta_data($order->id, '_openpay_3d_secure_url');
            $order->save();
            $logger->debug('order not processed redirect_url : ' . $redirect_url);
            wp_redirect($redirect_url);
            ob_end_flush();
            exit();
        }
    }
}

function openpay_woocommerce_confirm() {   
    global $woocommerce;
    $logger = wc_get_logger();
    
    $id = $_GET['id'];        
    
    $logger->info('openpay_woocommerce_confirm => '.$id);   
    
    try {            
        $openpay_subscriptions = new WC_Gateway_Openpay();  
        $charge = $openpay_subscriptions->openpay_request(null, 'charges/'.$id, 'GET');

        $order = new WC_Order($charge->order_id);
        $logger->info('openpay_woocommerce_confirm => ORDER: '. $order->get_id());   
        $logger->info('openpay_woocommerce_confirm => '.json_encode(array('id' => $charge->id, 'status' => $charge->status)));   

        if ($order && $charge->status != 'completed') {
            if (property_exists($charge, 'authorization') && ($charge->status == 'in_progress' && ($charge->id != $charge->authorization))) {  
                $order->set_status('on-hold');
                $order->save();
                $logger->info('openpay_woocommerce_confirm => set_status:on-hold');
            } else {  
                $order->add_order_note(sprintf("%s Credit Card Payment Failed with message: '%s'", 'openpay-woosubscriptions', 'Status '.$charge->status));
                $order->set_status('failed');
                $order->save();
                $logger->info('openpay_woocommerce_confirm => set_status:failed');

                if ($order && $charge->status == 'failed') {
                    $logger->info('openpay_woocommerce_confirm => Returning to cart for order: '. $order->get_id()); 
                    $logger->info('openpay_woocommerce_confirm => Return checkout URL: '. $woocommerce->cart->get_checkout_url()); 
                    //$woocommerce->cart->empty_cart();
                    wp_redirect($woocommerce->cart->get_checkout_url());
                    exit();
                }

                if (function_exists('wc_add_notice')) { 
                    wc_add_notice(__('Error en la transacción: No se pudo completar tu pago.'), 'error');
                } else {  
                    $woocommerce->add_error(__('Error en la transacción: No se pudo completar tu pago.'), 'woothemes');
                }
            }
        } else if ($order && $charge->status == 'completed') {
            $order->payment_complete();
            $logger->info('openpay_woocommerce_confirm => set_status:completed');
            $woocommerce->cart->empty_cart();
            $order->add_order_note(sprintf("%s payment completed with Transaction Id of '%s'", 'openpay-woosubscriptions', $charge->id));
            
            if(null != get_post_meta($order->get_id(), '_openpay_charge_subscription_id', true)){
                // Activate subscriptions
                WC_Subscriptions_Manager::activate_subscriptions_for_order($order);
            }
        }                 
        wp_redirect($openpay_subscriptions->get_return_url($order));    
        exit();      
    } catch (Exception $e) {
        $logger->error($e->getMessage());            
        status_header( 404 );
        nocache_headers();
        include(get_query_template('404'));
        die();
    }                
}    

/**
 * Main Openpay class which sets the gateway up for us
 */
class WC_Openpay_Subscriptions {

    /**
     * Constructor
     */
    public function __construct() {
        define('WC_OPENPAY_VERSION', '3.2.2');
        define('WC_OPENPAY_TEMPLATE_PATH', untrailingslashit(plugin_dir_path(__FILE__)) . '/templates/');
        define('WC_OPENPAY_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
        define('WC_OPENPAY_MAIN_FILE', __FILE__);

        // Actions
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
        add_action('plugins_loaded', array($this, 'init'), 0);
        add_filter('woocommerce_payment_gateways', array($this, 'register_gateway'));
        add_action('woocommerce_order_status_on-hold_to_processing', array($this, 'capture_payment'));
        add_action('woocommerce_order_status_on-hold_to_completed', array($this, 'capture_payment'));
        //add_action('woocommerce_order_status_on-hold_to_cancelled', array($this, 'cancel_payment'));
        
        
    }

    /**
     * Add relevant links to plugins page
     * @param  array $links
     * @return array
     */
    public function plugin_action_links($links) {
        $plugin_links = array(
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_gateway_openpay') . '">' . __('Settings', 'openpay-woosubscriptions') . '</a>',
            '<a href="http://www.openpay.mx/">' . __('Support', 'openpay-woosubscriptions') . '</a>',
            '<a href="http://www.openpay.mx/docs">' . __('Docs', 'openpay-woosubscriptions') . '</a>',
        );
        return array_merge($plugin_links, $links);
    }

    /**
     * Init localisations and files
     */
    public function init() {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        // Includes
        include_once( 'includes/class-wc-gateway-openpay.php' );

        if (class_exists('WC_Subscriptions_Order') || class_exists('WC_Pre_Orders_Order')) {
            include_once( 'includes/class-wc-gateway-openpay-addons.php' );
        }

        // Localisation
        load_plugin_textdomain('openpay-woosubscriptions', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Register the gateway for use
     */
    public function register_gateway($methods) {
        if (class_exists('WC_Subscriptions_Order') || class_exists('WC_Pre_Orders_Order')) {
            $methods[] = 'WC_Gateway_Openpay_Addons';
        } else {
            $methods[] = 'WC_Gateway_Openpay';
        }

        return $methods;
    }

    /**
     * Capture payment when the order is changed from on-hold to complete or processing
     *
     * @param  int $order_id
     */
    public function capture_payment($order_id) {
        $order = new WC_Order($order_id);

        if ($order->payment_method == 'openpay') {
            $charge = get_post_meta($order_id, '_openpay_charge_id', true);
            $captured = get_post_meta($order_id, '_openpay_charge_captured', true);

            if ($charge && $captured == 'no') {
                $openpay = new WC_Gateway_Openpay();

                $result = $openpay->openpay_request(array(
                    'amount' => $order->order_total * 100
                        ), 'charges/' . $charge . '/capture');

                if (is_wp_error($result)) {
                    $order->add_order_note(__('Unable to capture charge!', 'openpay-woosubscriptions') . ' ' . $result->get_error_message());
                } else {
                    $order->add_order_note(sprintf(__('Openpay charge complete (Charge ID: %s)', 'openpay-woosubscriptions'), $result->id));
                    update_post_meta($order->id, '_openpay_charge_captured', 'yes');

                    // Store other data such as fees
                    update_post_meta($order->id, 'Openpay Payment ID', $result->id);
                    update_post_meta($order->id, 'Openpay Fee', number_format($result->fee / 100, 2, '.', ''));
                    update_post_meta($order->id, 'Net Revenue From Openpay', ( $order->order_total - number_format($result->fee / 100, 2, '.', '')));
                }
            }
        }
    }

}

new WC_Openpay_Subscriptions();
