<?php

/*
  Plugin Name: Openpay WooSubscriptions Plugin
  Plugin URI: https://github.com/open-pay/openpay-woosubscriptions
  Description: Este plugin soporta suscripciones a través de Openpay utilizando WooCommerce (2.6.8) y WooCommerce Subscriptions (2.1.0)
  Version: 2.7.0
  Author: Federico Balderas
  Author URI: http://foograde.com

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

/**
 * Main Openpay class which sets the gateway up for us
 */
class WC_Openpay_Subscriptions {

    /**
     * Constructor
     */
    public function __construct() {
        define('WC_OPENPAY_VERSION', '2.7.0');
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
