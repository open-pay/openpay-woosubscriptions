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
            add_action('woocommerce_subscription_failing_payment_method_updated_openpay', array($this, 'update_failing_payment_method'), 10, 2);
            add_action('admin_enqueue_scripts', array($this, 'openpay_cards_admin_enqueue'), 10, 2);
        }

    }

    public function openpay_cards_admin_enqueue($hook) {
        wp_enqueue_script('openpay_cards_admin_form', plugins_url('../assets/js/admin.js', __FILE__), array('jquery'), '', true);
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
        $openpay_cc = $_POST['openpay_cc'];

        if (!$customer_id || !is_string($customer_id)) {
            $customer_id = 0;
        }


        // Use Openpay CURL API for payment
        try {
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

            // Store the ID in the order
            update_post_meta($order_id, '_openpay_customer_id', $customer_id);
            update_post_meta($order_id, '_openpay_card_id', $openpay_token);
            
            if($openpay_cc == 'new'){
                $card_id = $this->add_card($customer_id, $openpay_token, $device_session_id);
                if (is_wp_error($card_id)) {
                    throw new Exception($card_id->get_error_message());
                }
                update_post_meta($order_id, '_openpay_card_id', $card_id);
            }
            

            $initial_payment = $order->get_total();

            if ($initial_payment > 0) {
                $charge = $this->process_subscription_payment($order, $initial_payment, $device_session_id);
            }

            if (is_wp_error($charge)) {
                $order->add_order_note(sprintf(__($charge->get_error_message(), 'openpay-woosubscriptions')));
                error_log('ERROR '.$charge->get_error_message());
                throw new Exception($charge->get_error_message());
            }else{
                $order->add_order_note(sprintf(__('Openpay subscription payment completed (Charge ID: %s)', 'openpay-woosubscriptions'), $charge->id));            
                update_post_meta($order->id, '_openpay_charge_id', $charge->id);

                // Payment complete
                $order->payment_complete($charge->id);

                // Remove cart
                WC()->cart->empty_cart();

                // Activate subscriptions
                WC_Subscriptions_Manager::activate_subscriptions_for_order($order);

                if (isset($charge->fee->amount)) {
                    $fee = number_format(($charge->fee->amount + $charge->fee->tax), 2);
                    update_post_meta($order->id, 'Openpay Fee', $fee);
                    update_post_meta($order->id, 'Net Revenue From Openpay', $order->order_total - $fee);
                }
            }

            // Return thank you page redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
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
            $device_session_id = isset($_POST['device_session_id']) ? wc_clean($_POST['device_session_id']) : '';;
            $customer_id = is_user_logged_in() ? get_user_meta(get_current_user_id(), '_openpay_customer_id', true) : 0;
            $openpay_cc = $_POST['openpay_cc'];
            
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
                if ($card_id !== 'new' && $customer_id) {
                    $post_data['customer'] = $customer_id;
                    $post_data['source_id'] = $openpay_token;
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
                if($openpay_cc == 'new'){
                    $card_id = $this->add_card($customer_id, $openpay_token, $device_session_id);
                    if (is_wp_error($card_id)) {
                        throw new Exception($card_id->description);
                    }
                    $post_data['source_id'] = $card_id;
                }
                
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
        if ($this->has_subscription($order_id)) {
            if ($this->is_change_payment()) {
				return $this->change_payment_method($order_id);
            }
            return $this->process_subscription($order_id);
        } elseif (class_exists('WC_Pre_Orders_Order') && WC_Pre_Orders_Order::order_contains_pre_order($order_id)) {
            return $this->process_pre_order($order_id);
            // Processing regular product
        } else {
            return parent::process_payment($order_id);
        }
    }

    /**
	 * Process the payment method change for subscriptions.
	 *
	 * @since 3.0.0
	 * @param int $order_id
	 */
	public function change_payment_method($order_id) {
		try {
            $subscription = wc_get_order($order_id);
            $parent_id = $subscription->get_parent_id();
            $openpay_token = isset($_POST['openpay_token']) ? wc_clean($_POST['openpay_token']) : '';
            $device_session_id = isset($_POST['device_session_id']) ? wc_clean($_POST['device_session_id']) : '';
            $customer_id = is_user_logged_in() ? get_user_meta(get_current_user_id(), '_openpay_customer_id', true) : 0;
            $openpay_cc = $_POST['openpay_cc'];

            if($openpay_cc == 'new'){
                $card_id = $this->add_card($customer_id, $openpay_token, $device_session_id);
                if (is_wp_error($card_id)) {
                    throw new Exception($card_id->get_error_message());
                }
            }else{
                $card_id = $openpay_token;
            }

            update_post_meta($order_id, '_openpay_customer_id', $customer_id);
            update_post_meta($order_id, '_openpay_card_id', $card_id);
            update_post_meta($parent_id, '_openpay_card_id', $card_id);
            

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $subscription ),
            );
            
		} catch (Exception $e) {
            wc_add_notice(__('Error:', 'openpay-woosubscriptions').' "'.$e->getMessage().'"', 'error');
            return;
		}
    }
    
    /**
	 * Is $order_id a subscription?
	 * @param  int  $order_id
	 * @return boolean
	 */
    public function has_subscription( $order_id ) {
		return ( class_exists('WC_Subscriptions_Order') && ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) );
    }
    
    /**
	 * Checks if page is pay for order and change payment page.
     * 
	 * @since 3.0.0
	 * @return bool
	 */
    public function is_change_payment() {
		return ( isset( $_GET['pay_for_order'] ) && isset( $_GET['change_payment_method'] ) );
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
        $charge = $this->process_subscription_payment($order, $amount_to_charge, null, $renewal_order->id);

        if (is_wp_error($charge)) {
            $renewal_order->add_order_note(sprintf(__($charge->get_error_message(), 'openpay-woosubscriptions')));
            error_log('process_subscription_payment_failure_on_order: '.$order->id);
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order);
        }else{
            $renewal_order->add_order_note(sprintf(__('Openpay successful payment subscription (Charge ID: %s)', 'openpay-woosubscriptions'), $charge->id));
            update_post_meta($renewal_order->id, '_openpay_charge_id', $charge->id);
            
            // Payment complete
            $renewal_order->payment_complete($charge->id);
            //WC_Subscriptions_Manager::process_subscription_payments_on_order($order);
            WC_Subscriptions_Manager::activate_subscriptions_for_order($order);
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
    public function process_subscription_payment($order = '', $amount = 0, $device_session_id = null, $renewal_order_id = null) {
        $order_items = $order->get_items();
        $order_item = array_shift($order_items);
        if($renewal_order_id){
            $order_id = $renewal_order_id;
        }else{
            $order_id = $order->get_order_number();
        }
        $subscription_name = sprintf(__('Suscripción "%s"', 'openpay-woosubscriptions'), $order_item['name']).' '.sprintf(__('(Order %s)', 'openpay-woosubscriptions'), $order_id );

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
            'order_id' => $order_id."_".date('Ymd_His')
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
        $charge = $this->openpay_request($openpay_payment_args, 'customers/'.$openpay_customer.'/charges');
        if (isset($charge->id)) {
            return $charge;
        }else{
            $msg = $this->handleRequestError($charge->error_code);
            return new WP_Error('Error', __($charge->error_code.' '.$msg, 'openpay-woosubscriptions'));
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
    public function update_failing_payment_method($subscription, $renewal_order) {
        $new_customer_id = get_post_meta($renewal_order->id, '_openpay_customer_id', true);
        $new_card_id = get_post_meta($renewal_order->id, '_openpay_card_id', true);
        update_post_meta($subscription->get_id(), '_openpay_customer_id', $new_customer_id);
        update_post_meta($subscription->get_id(), '_openpay_card_id', $new_card_id);
        update_post_meta($subscription->get_parent_id(), '_openpay_card_id', $new_card_id);
    }


}
