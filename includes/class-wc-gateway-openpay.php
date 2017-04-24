<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Gateway_Openpay class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Openpay extends WC_Payment_Gateway
{

    protected $currencies = array('MXN', 'USD');

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'openpay';
        $this->method_title = __('Openpay', 'openpay-woosubscriptions');
        $this->method_description = __('Openpay works by adding credit card fields on the checkout and then sending the details to Openpay for verification.', 'openpay-woosubscriptions');
        $this->has_fields = true;
        $apiEndpoint = 'https://api.openpay.mx/v1';
        $apiSandboxEndpoint = 'https://sandbox-api.openpay.mx/v1';
        $this->supports = array(
            'subscriptions',
            'products',
            'subscription_cancellation',
            'subscription_reactivation',
            'subscription_suspension',
            'subscription_amount_changes',
            'subscription_payment_method_change',
            'subscription_date_changes',
        );

        // Icon
        $icon = 'cards.png';
        $this->icon = apply_filters('wc_openpay_icon', plugins_url('/assets/images/'.$icon, dirname(__FILE__)));

        // Load the form fields
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get setting values
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = $this->get_option('testmode') === "yes" ? true : false;
        $this->merchant_id = $this->testmode ? $this->get_option('test_merchant_id') : $this->get_option('merchant_id');
        $this->secret_key = $this->testmode ? $this->get_option('test_secret_key') : $this->get_option('secret_key');
        $this->publishable_key = $this->testmode ? $this->get_option('test_publishable_key') : $this->get_option('publishable_key');
        $this->api_endpoint = $this->testmode ? $apiSandboxEndpoint : $apiEndpoint;


        if ($this->testmode) {
            $this->description .= ' '.__('SANDBOX MODE ENABLED. In test mode, you can use the card number 4111111111111111 with any CVC and a valid expiration date.', 'openpay-woosubscriptions');
            $this->description = trim($this->description);
        }

        // Hooks
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));

        if (!$this->validateCurrency()) {
            $this->enabled = false;
        }
    }

    /**
     * Get Openpay amount to pay
     * @return float
     */
    public function get_openpay_amount($total) {
        switch (get_woocommerce_currency()) {
            // Zero decimal currencies
            case 'BIF' :
            case 'CLP' :
            case 'DJF' :
            case 'GNF' :
            case 'JPY' :
            case 'KMF' :
            case 'KRW' :
            case 'MGA' :
            case 'PYG' :
            case 'RWF' :
            case 'VND' :
            case 'VUV' :
            case 'XAF' :
            case 'XOF' :
            case 'XPF' :
                $total = absint($total);
                break;
            default :
                $total = number_format($total, 2, '.', '');
                break;
        }
        return $total;
    }

    /**
     * Check if SSL is enabled and notify the user
     */
    public function admin_notices() {
        if ($this->enabled == 'no') {
            return;
        }

        // Check required fields
        if (!$this->secret_key) {
            echo '<div class="error"><p>'.sprintf(__('Openpay error: Please enter your secret key <a href="%s">here</a>', 'openpay-woosubscriptions'), admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_gateway_openpay')).'</p></div>';
            return;
        } elseif (!$this->publishable_key) {
            echo '<div class="error"><p>'.sprintf(__('Openpay error: Please enter your public key <a href="%s">here</a>', 'openpay-woosubscriptions'), admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_gateway_openpay')).'</p></div>';
            return;
        }

        // Simple check for duplicate keys
        if ($this->secret_key == $this->publishable_key) {
            echo '<div class="error"><p>'.sprintf(__('Openpay error: Your secret and public keys match. Please check and re-enter.', 'openpay-woosubscriptions'), admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_gateway_openpay')).'</p></div>';
            return;
        }

        // Show message if enabled and FORCE SSL is disabled and WordpressHTTPS plugin is not detected
        if (get_option('woocommerce_force_ssl_checkout') == 'no' && !class_exists('WordPressHTTPS')) {
            echo '<div class="error"><p>'.sprintf(__('Openpay is enabled, but the <a href="%s">force SSL option</a> is disabled; your checkout may not be secure! Please enable SSL and ensure your server has a valid SSL certificate - Openpay will only work in test mode.', 'openpay-woosubscriptions'), admin_url('admin.php?page=wc-settings&tab=checkout')).'</p></div>';
        }
    }

    /**
     * Check if this gateway is enabled
     */
    public function is_available() {
        if ($this->enabled == "yes") {
            if (!is_ssl() && !$this->testmode) {
                return false;
            }
            // Required fields check
            if (!$this->secret_key || !$this->publishable_key) {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = apply_filters('wc_openpay_settings', array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'openpay-woosubscriptions'),
                'label' => __('Enable Openpay', 'openpay-woosubscriptions'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no'
            ),
            'testmode' => array(
                'title' => __('Sandbox mode', 'openpay-woosubscriptions'),
                'label' => __('Enable Sandbox', 'openpay-woosubscriptions'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'openpay-woosubscriptions'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'openpay-woosubscriptions'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'openpay-woosubscriptions'),
                'default' => __('Tarjeta de crédito o débito', 'openpay-woosubscriptions')
            ),
            'description' => array(
                'title' => __('Description', 'openpay-woosubscriptions'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'openpay-woosubscriptions'),
                'default' => __('Pay with your credit card via Openpay.', 'openpay-woosubscriptions')
            ),
            'merchant_id' => array(
                'title' => __('Production Merchant ID', 'openpay-woosubscriptions'),
                'type' => 'text',
                'description' => __('Get your API keys from your openpay account.', 'openpay-woosubscriptions'),
                'default' => ''
            ),
            'secret_key' => array(
                'title' => __('Production Secret Key', 'openpay-woosubscriptions'),
                'type' => 'text',
                'description' => __('Get your API keys from your openpay account.', 'openpay-woosubscriptions'),
                'default' => ''
            ),
            'publishable_key' => array(
                'title' => __('Production Public Key', 'openpay-woosubscriptions'),
                'type' => 'text',
                'description' => __('Get your API keys from your openpay account.', 'openpay-woosubscriptions'),
                'default' => ''
            ),
            'test_merchant_id' => array(
                'title' => __('Sandbox Merchant ID', 'openpay-woosubscriptions'),
                'type' => 'text',
                'description' => __('Get your API keys from your openpay account.', 'openpay-woosubscriptions'),
                'default' => ''
            ),
            'test_secret_key' => array(
                'title' => __('Sandbox Secret Key', 'openpay-woosubscriptions'),
                'type' => 'text',
                'description' => __('Get your API keys from your openpay account.', 'openpay-woosubscriptions'),
                'default' => ''
            ),
            'test_publishable_key' => array(
                'title' => __('Sandbox Public Key', 'openpay-woosubscriptions'),
                'type' => 'text',
                'description' => __('Get your API keys from your openpay account.', 'openpay-woosubscriptions'),
                'default' => ''
            )
        ));
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields() {

        $cc_form = new WC_Payment_Gateway_CC;
        $cc_form->id       = $this->id;
        $cc_form->supports = $this->supports;
        $this->cc_form = $cc_form;
        
        $form_template = realpath(dirname(__FILE__)).'/../templates/payment_form.php';        
        include_once($form_template);        
    }

    /**
     * payment_scripts function.
     *
     * Outputs scripts used for openpay payment
     *
     * @access public
     */
    public function payment_scripts() {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_script('openpay', 'https://openpay.s3.amazonaws.com/openpay.v1.min.js', '', '1.0', true);
        wp_enqueue_script('openpay_fraud', 'https://openpay.s3.amazonaws.com/openpay-data.v1.min.js', '', '1.0', true);
        wp_enqueue_script('woocommerce_openpay', plugins_url('assets/js/openpay.js', dirname(__FILE__)), array('openpay'), WC_OPENPAY_VERSION, true);

        $openpay_params = array(
            'merchant_id' => $this->merchant_id,
            'public_key' => $this->publishable_key,
            'sandbox_mode' => $this->testmode,
            'i18n_terms' => __('Please accept the terms and conditions first', 'openpay-woosubscriptions'),
            'i18n_required_fields' => __('Please fill in required checkout fields first', 'openpay-woosubscriptions'),
        );

        // If we're on the pay page we need to pass openpay.js the address of the order.
        if (is_checkout_pay_page() && isset($_GET['order']) && isset($_GET['order_id'])) {
            $order_key = urldecode($_GET['order']);
            $order_id = absint($_GET['order_id']);
            $order = new WC_Order($order_id);

            if ($order->id == $order_id && $order->order_key == $order_key) {
                $openpay_params['billing_first_name'] = $order->billing_first_name;
                $openpay_params['billing_last_name'] = $order->billing_last_name;
                $openpay_params['billing_address_1'] = $order->billing_address_1;
                $openpay_params['billing_address_2'] = $order->billing_address_2;
                $openpay_params['billing_state'] = $order->billing_state;
                $openpay_params['billing_city'] = $order->billing_city;
                $openpay_params['billing_postcode'] = $order->billing_postcode;
                $openpay_params['billing_country'] = $order->billing_country;
            }
        }

        wp_localize_script('woocommerce_openpay', 'wc_openpay_params', $openpay_params);
    }

    /**
     * Process the DIRECT payment 
     */
    public function process_payment($order_id) {
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

            // Check amount
            if ($order->order_total * 100 < 50) {
                throw new Exception(__('Sorry, the minimum allowed order total is 0.50 to use this payment method.', 'openpay-woosubscriptions'));
            }

            // Pay using a saved card!
            if (empty($openpay_token)) { // If not using a saved card, we need a token
                $error_msg = __('Please make sure your card details have been entered correctly and that your browser supports JavaScript.', 'openpay-woosubscriptions');

                if ($this->testmode) {
                    $error_msg .= ' '.__('Developers: Please make sure that you are including jQuery and there are no JavaScript errors on the page.', 'openpay-woosubscriptions');
                }

                throw new Exception($error_msg);
            }

            if (!$customer_id) {
                $customer_id = $this->add_customer($order);
                if (is_wp_error($customer_id)) {
                    throw new Exception($customer_id->get_error_message());
                }
            }

            $card_id = $this->add_card($customer_id, $openpay_token, $device_session_id);

            if (is_wp_error($card_id)) {
                throw new Exception($card_id->get_error_message());
            }

            // Store the ID in the order
            if ($customer_id) {
                update_post_meta($order_id, '_openpay_customer_id', $customer_id);
            }
            if ($card_id) {
                update_post_meta($order_id, '_openpay_card_id', $card_id);
            }

            // Other charge data
            $post_data['source_id'] = $card_id;
            $post_data['amount'] = $this->get_openpay_amount($order->order_total);
            $post_data['currency'] = strtolower(get_woocommerce_currency());
            $post_data['description'] = sprintf(__('%s - Order '.$card_id.' %s', 'openpay-woosubscriptions'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $order->get_order_number());
            $post_data['method'] = 'card';
            $post_data['device_session_id'] = $device_session_id;
            $post_data['order_id'] = $order->id."_".date('YmdHis');

            // Make the request
            $response = $this->openpay_request($post_data, 'customers/'.$customer_id.'/charges');

            if (isset($response->error_code)) {
                throw new Exception($response->description);
            }

            // Store charge ID
            update_post_meta($order->id, '_openpay_charge_id', $response->id);

            // Store other data such as fees
            update_post_meta($order->id, 'Openpay Payment ID', $response->id);

            if (isset($response->fee->amount)) {
                $fee = number_format(($response->fee->amount + $response->fee->tax), 2);
                update_post_meta($order->id, 'Openpay Fee', $fee);
                update_post_meta($order->id, 'Net Revenue From Openpay', $order->order_total - $fee);
            }

            // Store captured value
            update_post_meta($order->id, '_openpay_charge_captured', 'yes');

            // Payment complete
            $order->payment_complete($response->id);

            // Add order note
            $order->add_order_note(sprintf(__('Openpay charge complete (Charge ID: %s)', 'openpay-woosubscriptions'), $response->id));

            // Remove cart
            WC()->cart->empty_cart();

            // Return thank you page redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            return;
        }
    }

    /**
     * Add a customer to Openpay via the API.
     *
     * @param int $order
     * @param string $openpay_token
     * @return int|WP_ERROR
     */
    public function add_customer($order) {

        $customerData = array(
            'name' => $order->billing_first_name,
            'last_name' => $order->billing_last_name,
            'email' => $order->billing_email,
            'requires_account' => false,
            'phone_number' => $order->billing_phone,            
        );

        if($this->hasAddress($order)) {
            $customerData['address'] = array(
                'line1' => substr($order->billing_address_1, 0, 200),
                'line2' => substr($order->billing_address_2, 0, 50),
                'line3' => '',
                'state' => $order->billing_state,
                'city' => $order->billing_city,
                'postal_code' => $order->billing_postcode,
                'country_code' => $order->billing_country
            );
        }
                
        $response = $this->openpay_request($customerData, 'customers');

        if (!isset($response->error_code)) {
            // Store the ID on the user account
            if (is_user_logged_in()) {
                update_user_meta(get_current_user_id(), '_openpay_customer_id', $response->id);
            }

            // Store the ID in the order
            update_post_meta($order->id, '_openpay_customer_id', $response->id);

            return $response->id;
        } else {
            $msg = $this->handleRequestError($response->error_code);
            return new WP_Error('error', __($response->error_code.' '.$msg, 'openpay-woosubscriptions'));
        }
    }
    
    public function hasAddress($order) {
        if($order->billing_address_1 && $order->billing_state && $order->billing_postcode && $order->billing_country && $order->billing_city) {
            return true;
        }
        return false;    
    }


    /**
     * Add a card to a customer via the API.
     *
     * @param int $order
     * @param string $openpay_token
     * @return int|WP_ERROR
     */
    public function add_card($customer_id, $openpay_token, $device_session_id) {
        if ($openpay_token) {

            $cardDataRequest = array(
                'token_id' => $openpay_token,
                'device_session_id' => $device_session_id
            );

            $response = $this->openpay_request($cardDataRequest, 'customers/'.$customer_id.'/cards');

            delete_transient('openpay_cards_'.$customer_id);

            if (isset($response->id)) {
                return $response->id;
            } else {
                $msg = $this->handleRequestError($response->error_code);
                return new WP_Error('error', __($response->error_code.' '.$msg, 'openpay-woosubscriptions'));
            }
        }
    }

    /**
     * Send the request to Openpay's API
     *
     * @param array $request
     * @param string $api
     * @return array|WP_Error
     */
    public function openpay_request($params, $api, $method = 'POST') {

        $absUrl = $this->api_endpoint.'/'.$this->merchant_id.'/';
        $absUrl .= $api;

        $username = $this->secret_key;
        $password = "";

        $data_string = json_encode($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $absUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: '.strlen($data_string))
        );
        $result = curl_exec($ch);
        curl_close($ch);

        return json_decode($result);
    }

    public function handleRequestError($responseCode) {

        switch ($responseCode) {

            case "1000":
                $msg = "Servicio no disponible.";
                break;

            case "1001":
                $msg = "Los campos no tienen el formato correcto, o la petición no tiene campos que son requeridos.";
                break;

            case "1004":
                $msg = "Servicio no disponible.";
                break;

            case "1005":
                $msg = "Servicio no disponible.";
                break;

            case "2004":
                $msg = "El dígito verificador del número de tarjeta es inválido de acuerdo al algoritmo Luhn.";
                break;

            case "2005":
                $msg = "La fecha de expiración de la tarjeta es anterior a la fecha actual.";
                break;

            case "2006":
                $msg = "El código de seguridad de la tarjeta (CVV2) no fue proporcionado.";
                break;

            case "3001":
                $msg = "La tarjeta fue rechazada.";
                break;

            case "3002":
                $msg = "La tarjeta ha expirado.";
                break;

            case "3003":
                $msg = "La tarjeta no tiene fondos suficientes.";
                break;

            case "3004":
                $msg = "La tarjeta ha sido identificada como una tarjeta robada.";
                break;

            case "3005":
                $msg = "El cargo fue declinado por alto riesgo, intente nuevamente en 30 minutos.";
                break;

            case "3006":
                $msg = "La operación no esta permitida para este cliente o esta transacción.";
                break;

            case "3007":
                $msg = "Deprecado. La tarjeta fue declinada.";
                break;

            case "3008":
                $msg = "La tarjeta no es soportada en transacciones en línea.";
                break;

            case "3009":
                $msg = "La tarjeta fue reportada como perdida.";
                break;

            case "3010":
                $msg = "El banco ha restringido la tarjeta.";
                break;

            case "3011":
                $msg = "El banco ha solicitado que la tarjeta sea retenida. Contacte al banco.";
                break;

            case "3012":
                $msg = "Se requiere solicitar al banco autorización para realizar este pago.";
                break;

            default: //Demás errores 400 
                $msg = "La petición no pudo ser procesada.";
                break;
        }

        return $msg;
    }

    public function validateCurrency() {
        return in_array(get_woocommerce_currency(), $this->currencies);
    }

}
