<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Gateway_Openpay_Addons class.
 *
 * @extends WC_Gateway_Openpay
 */
class WC_Gateway_Openpay_Addons extends WC_Gateway_Openpay
{

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();

        if (class_exists('WC_Subscriptions_Order')) {
            add_action('woocommerce_scheduled_subscription_payment_'.$this->id, array($this, 'scheduled_subscription_payment'), 10, 3);
            add_filter('woocommerce_subscriptions_renewal_order_meta_query', array($this, 'remove_renewal_order_meta'), 10, 4);
            add_action('woocommerce_subscriptions_changed_failing_payment_method_openpay', array($this, 'update_failing_payment_method'), 10, 3);
        }

    }

    /**
     * Process the subscription
     *
     * @param int $order_id
     * @return array
     */
    public function process_subscription($order_id) {
        $order = new WC_Order($order_id);
        $device_session_id = isset($_POST['device_session_id']) ? wc_clean($_POST['device_session_id']) : '';
        $openpay_token = isset($_POST['openpay_token']) ? wc_clean($_POST['openpay_token']) : '';
        $customer_id = is_user_logged_in() ? get_user_meta(get_current_user_id(), '_openpay_customer_id', true) : 0;

        if (!$customer_id || !is_string($customer_id)) {
            $customer_id = 0;
        }


        // Use Openpay CURL API for payment
        try {
            $post_data = array();

            // If not using a saved card, we need a token
            if (empty($openpay_token)) {
                $error_msg = __('Please make sure your card details have been entered correctly and that your browser supports JavaScript.', 'openpay-woosubscriptions');

                if ($this->testmode) {
                    $error_msg .= ' '.__('Developers: Please make sure that you are including jQuery and there are no JavaScript errors on the page.', 'openpay-woosubscriptions');
                }

                throw new Exception($error_msg);
            }


            if (!$customer_id) {
                $customer_id = $this->add_customer($order, $openpay_token);
                if (is_wp_error($customer_id)) {
                    throw new Exception($customer_id->get_error_message());
                }
            }

            $card_id = $this->add_card($customer_id, $openpay_token, $device_session_id);

            if (is_wp_error($card_id)) {
                throw new Exception($card_id->get_error_message());
            }

            $post_data['source_id'] = $card_id;
            $post_data['customer'] = $customer_id;

            // Store the ID in the order
            update_post_meta($order_id, '_openpay_customer_id', $customer_id);
            update_post_meta($order_id, '_openpay_card_id', $card_id);

            $initial_payment = $order->get_total();

            if ($initial_payment > 0) {
                $payment_response = $this->process_subscription_payment($order, $initial_payment, $device_session_id);
            }

            if (isset($payment_response->error_code)) {
                throw new Exception($payment_response->description);
            } else {

                if (isset($payment_response->fee->amount)) {
                    $fee = number_format(($payment_response->fee->amount + $payment_response->fee->tax), 2);
                    update_post_meta($order->id, 'Openpay Fee', $fee);
                    update_post_meta($order->id, 'Net Revenue From Openpay', $order->order_total - $fee);
                }

                // Payment complete
                $order->payment_complete($payment_response->id);

                // Remove cart
                WC()->cart->empty_cart();

                // Activate subscriptions
                WC_Subscriptions_Manager::activate_subscriptions_for_order($order);

                // Return thank you page redirect
                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            }
        } catch (Exception $e) {
            wc_add_notice(__('Error:', 'openpay-woosubscriptions').' "'.$e->getMessage().'"', 'error');
            return;
        }
    }

    /**
     * Process the pre-order
     *
     * @param int $order_id
     * @return array
     */
    public function process_pre_order($order_id) {
        if (WC_Pre_Orders_Order::order_requires_payment_tokenization($order_id)) {
            $order = new WC_Order($order_id);
            $openpay_token = isset($_POST['openpay_token']) ? wc_clean($_POST['openpay_token']) : '';
            $device_session_id = isset($_POST['device_session_id']) ? wc_clean($_POST['device_session_id']) : '';
            $card_id = isset($_POST['openpay_card_id']) ? wc_clean($_POST['openpay_card_id']) : '';
            $customer_id = is_user_logged_in() ? get_user_meta(get_current_user_id(), '_openpay_customer_id', true) : 0;

            if (!$customer_id || !is_string($customer_id)) {
                $customer_id = 0;
            }

            try {
                $post_data = array();

                // Check amount
                if ($order->order_total * 100 < 50) {
                    throw new Exception(__('Sorry, the minimum allowed order total is 0.50 to use this payment method.', 'openpay-woosubscriptions'));
                }

                // Pay using a saved card!
                if ($card_id !== 'new' && $card_id && $customer_id) {
                    $post_data['customer'] = $customer_id;
                    $post_data['source_id'] = $card_id;
                }

                // If not using a saved card, we need a token
                elseif (empty($openpay_token)) {
                    $error_msg = __('Please make sure your card details have been entered correctly and that your browser supports JavaScript.', 'openpay-woosubscriptions');

                    if ($this->testmode) {
                        $error_msg .= ' '.__('Developers: Please make sure that you are including jQuery and there are no JavaScript errors on the page.', 'openpay-woosubscriptions');
                    }

                    throw new Exception($error_msg);
                }

                // Save token
                if (!$customer_id) {
                    $customer_id = $this->add_customer($order, $openpay_token);
                    if (is_wp_error($customer_id)) {
                        throw new Exception($customer_id->get_error_message());
                    }
                }

                $card_id = $this->add_card($customer_id, $openpay_token, $device_session_id);
                if (is_wp_error($card_id)) {
                    throw new Exception($card_id->description);
                }

                $post_data['source_id'] = $card_id;


                // Store the ID in the order
                update_post_meta($order->id, '_openpay_customer_id', $customer_id);

                // Store the ID in the order
                update_post_meta($order->id, '_openpay_card_id', $card_id);

                // Reduce stock levels
                $order->reduce_order_stock();

                // Remove cart
                WC()->cart->empty_cart();

                // Is pre ordered!
                WC_Pre_Orders_Order::mark_order_as_pre_ordered($order);

                // Return thank you page redirect
                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            } catch (Exception $e) {
                WC()->add_error($e->getMessage());
                return;
            }
        } else {
            return parent::process_payment($order_id);
        }
    }

    /**
     * Process the payment
     *
     * @param  int $order_id
     * @return array
     */
    public function process_payment($order_id) {
        // Processing subscription
        if (class_exists('WC_Subscriptions_Order') && wcs_order_contains_subscription($order_id)) {
            return $this->process_subscription($order_id);

            // Processing pre-order
        } elseif (class_exists('WC_Pre_Orders_Order') && WC_Pre_Orders_Order::order_contains_pre_order($order_id)) {
            return $this->process_pre_order($order_id);

            // Processing regular product
        } else {
            return parent::process_payment($order_id);
        }
    }

    /**
     * scheduled_subscription_payment function.
     *
     * @param $amount_to_charge float The amount to charge.
     * @param $order WC_Order The WC_Order object of the order which the subscription was purchased in.
     * @param $product_id int The ID of the subscription product for which this payment relates.
     * @access public
     * @return void
     */
    public function scheduled_subscription_payment($amount_to_charge, $renewal_order) {

        $renewal_order->add_order_note(sprintf(__('Openpay starting subscription payment', 'openpay-woosubscriptions')));
                
        $order = WC_Subscriptions_Renewal_Order::get_parent_order($renewal_order->id);                
        $result = $this->process_subscription_payment($order, $amount_to_charge);
                
        if (!isset($result->error_code)) {
            $order->add_order_note(sprintf(__('Openpay successful payment subscription', 'openpay-woosubscriptions')));
            $order->add_order_note(sprintf(__('Active subscription', 'openpay-woosubscriptions')));
            error_log('Openpay successful payment subscription');
            WC_Subscriptions_Manager::process_subscription_payments_on_order($order);
            WC_Subscriptions_Manager::activate_subscriptions_for_order($order);            
        } else {
            error_log('process_subscription_payment_failure_on_order: '.$order->id);
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order);
        }
    }

    /**
     * process_subscription_payment function.
     *
     * @access public
     * @param mixed $order
     * @param int $amount (default: 0)
     * @param string $device_session_id (default: '')
     * @return void
     */
    public function process_subscription_payment($order = '', $amount = 0, $device_session_id = null) {

        $order_items = $order->get_items();
        $order_item = array_shift($order_items);
        $subscription_name = sprintf(__('Suscripción "%s"', 'openpay-woosubscriptions'), $order_item['name']).' '.sprintf(__('(Order %s)', 'openpay-woosubscriptions'), $order->get_order_number());

        if ($amount * 100 < 50) {
            return new WP_Error('openpay_error', __('Sorry, the minimum allowed order total is 0.50 to use this payment method.', 'openpay-woosubscriptions'));
        }

        // We need a customer in Openpay. First, look for the customer ID linked to the USER.
        $user_id = $order->customer_user;
        $openpay_customer = get_user_meta($user_id, '_openpay_customer_id', true);

        // If we couldn't find a Openpay customer linked to the account, fallback to the order meta data.
        if (!$openpay_customer || !is_string($openpay_customer)) {
            $openpay_customer = get_post_meta($order->id, '_openpay_customer_id', true);
        }

        // Or fail :(
        if (!$openpay_customer) {
            return new WP_Error('openpay_error', __('Customer not found', 'openpay-woosubscriptions'));
        }
        
        $card_id = get_post_meta($order->id, '_openpay_card_id', true);
        
        error_log('ORDER ID: '.$order->id);
        error_log('OPANPAY CUSTOMER ID: '.$openpay_customer);        
        error_log('OPANPAY CARD ID: '.$card_id);

        $openpay_payment_args = array(
            'amount' => $this->get_openpay_amount($amount),
            'currency' => strtolower(get_woocommerce_currency()),
            'description' => $subscription_name,
            'method' => 'card',
            'order_id' => $order->id."_".date('Ymd_His')
        );
        
        // See if we're using a particular card
        if ($card_id) {
            $openpay_payment_args['source_id'] = $card_id;
        }

        //If $device_session_id exist
        if ($device_session_id) {
            $openpay_payment_args['device_session_id'] = $device_session_id;
        }

        // Charge the customer
        $response = $this->openpay_request($openpay_payment_args, 'customers/'.$openpay_customer.'/charges');

        if (isset($response->error_code)) {
            $msg = $this->handleRequestError($response->error_code);
            $order->add_order_note(sprintf(__($response->error_code.' '.$msg, 'openpay-woosubscriptions')));
            error_log('ERROR '.$response->error_code.': '.$response->description);
            return $response;
        } else {
            $order->add_order_note(sprintf(__('Openpay subscription payment completed (Charge ID: %s)', 'openpay-woosubscriptions'), $response->id));            
            update_post_meta($order->id, '_openpay_charge_id', $response->id);            
            return $response;
        }
    }

    /**
     * Don't transfer Openpay customer/token meta when creating a parent renewal order.
     *
     * @access public
     * @param array $order_meta_query MySQL query for pulling the metadata
     * @param int $original_order_id Post ID of the order being used to purchased the subscription being renewed
     * @param int $renewal_order_id Post ID of the order created for renewing the subscription
     * @param string $new_order_role The role the renewal order is taking, one of 'parent' or 'child'
     * @return void
     */
    public function remove_renewal_order_meta($order_meta_query, $original_order_id, $renewal_order_id, $new_order_role) {
        if ('parent' == $new_order_role) {
            $order_meta_query .= " AND `meta_key` NOT IN ( '_openpay_customer_id', '_openpay_card_id' ) ";
        }
        return $order_meta_query;
    }

    /**
     * Update the customer_id for a subscription after using Openpay to complete a payment to make up for
     * an automatic renewal payment which previously failed.
     *
     * @access public
     * @param WC_Order $original_order The original order in which the subscription was purchased.
     * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
     * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
     * @return void
     */
    public function update_failing_payment_method($original_order, $renewal_order, $subscription_key) {
        $new_customer_id = get_post_meta($renewal_order->id, '_openpay_customer_id', true);
        $new_card_id = get_post_meta($renewal_order->id, '_openpay_card_id', true);
        update_post_meta($original_order->id, '_openpay_customer_id', $new_customer_id);
        update_post_meta($original_order->id, '_openpay_card_id', $new_card_id);
    }


}
