<?php

/**
 * Payright Payment Gateway Class
 * Author dev@payright.my
 */
class Payright extends WC_Payment_Gateway
{
    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {
        // Setup properties.
        $this->id = 'payright';
        $this->icon = apply_filters('payright_icon', plugins_url('../assets/payright.png', __FILE__ ));
        $this->method_title = __('Payright', 'payright');
        $this->method_description = __('Payright Payment Gateway Plug-in for WooCommerce', 'payright');
        $this->title = __('Payright', 'payright');
        $this->has_fields = true;
        $this->order_button_text  =  __('Pay with Payright', 'payright');

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        foreach ($this->settings as $setting_key => $value) {
            $this->$setting_key = $value;
        }

        if (is_admin()) {
            add_action('woocommerce_update_options_payment_gateways_'.$this->id, array(
                $this,
                'process_admin_options',
            ));
        }

        add_action('woocommerce_api_callback_'.$this->id, [$this, 'check_payright_callback']);
    }

    /**
     * Build the administration fields for Payright
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable / Disable', 'payright'),
                'label' => __('Enable Payright', 'payright'),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'payright'),
                'type' => 'text',
                'desc_tip' => __('Payment title the customer will see during the checkout process.', 'payright'),
                'default' => __('Payright', 'payright'),
            ),
            'description' => array(
                'title' => __('Description', 'payright'),
                'type' => 'textarea',
                'desc_tip' => __('Payment description the customer will see during the checkout process.', 'payright'),
                'default' => __('Pay securely using your credit card or online banking through payright.', 'payright'),
                'css' => 'max-width:400px;',
            ),
            'display_logo' => array(
                'title' => __( 'Payright Logo', 'payright'),
                'type' => 'select',
                'default' => 'minimal',
                'desc_tip' => false,
                'description' => sprintf(__('This controls which logo appeared on checkout page. <a target="_blank" href="%s">Minimal</a> | <a target="_blank" href="%s">Full</a>', 'payright' ), plugins_url('../assets/payright.png', __FILE__ ), plugins_url('../assets/payright.png', __FILE__ )),
                'options' => array(
                    'minimal' => 'Minimal',
                    'full' => 'Full',
                ),
            ),
            'api_credentials' => array(
                'title' => __('API Credentials', 'payright'),
                'type' => 'title',
                'description' => __('All of these details can be obtained from Payright Account Setttings.', 'payright'),
            ),
            'apikey' => array(
                'title' => __('API Key', 'payright'),
                'type' => 'text',
                'placeholder' => 'Eg : Aew9tPhETJuauM8vGE0CRFmfTe4hHM3oiI0D71Ar',
                'desc_tip' => __('You can obtain this API key from account settings in Payright', 'payright'),
            ),
            'collection_id' => array(
                'title' => __('Collection ID', 'payright'),
                'type' => 'text',
                'desc_tip' => __('Payright Collection ID. Can be obtained from Payright Collection pages.', 'payright'),
                'description' => __('Create new collection at your payright dashboard and fill in your collection code here.', 'payright'),
            ),
            'signature_key' => array(
                'title' => __('Signature Key', 'payright'),
                'type' => 'text',
                'desc_tip' => __('You can obtain this Signature Key from account settings in Payright', 'payright'),
            )
        );
    }

    /**
     * Submit payment via payright
     * @param $order_id
     */
    public function process_payment($order_id)
    {
        # Get this order's information so that we know who to charge and how much
        $customer_order = wc_get_order($order_id);

        # Prepare the data to send to payright gateway
        $detail = 'Payment for Order #'.$order_id;

        $callback_url = add_query_arg(array('wc-api' => 'payright', 'order' => $order_id), home_url('/'));
        $return_url = wc_get_endpoint_url('order-received', '', wc_get_checkout_url());

        # support old version
        if ($this->is_old_version()) {
            $order_id = $customer_order->id;
            $amount = $customer_order->order_total;
            $name = $customer_order->billing_first_name.' '.$customer_order->billing_last_name;
            $email = $customer_order->billing_email;
            $phone = $customer_order->billing_phone;
        } else {
            $order_id = $customer_order->get_id();
            $amount = $customer_order->get_total();
            $name = $customer_order->get_billing_first_name().' '.$customer_order->get_billing_last_name();
            $email = $customer_order->get_billing_email();
            $phone = $customer_order->get_billing_phone();
        }

        # preparing body for API request
        $post_args = array(
            'body' => json_encode([
                'collection' => $this->collection_id,
                'description' => $detail,
                'amount' => intval($amount * 100),
                'order_id' => $order_id,
                'biller_name' => $name,
                'biller_email' => $email,
                'biller_mobile' => $phone,
                'callback_url' => $callback_url,
                'redirect_url' => $return_url,
                'external_reference_no' => $order_id,
            ]
            ),
            'method' => 'POST',
            'headers' => [
                'Content-type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->apikey,
            ],
        );

        $endpoint = $this->get_endpoint().'/bills';

        $request = wp_remote_post($endpoint, $post_args);
        $response = wp_remote_retrieve_body($request);

        $data_response = json_decode($response, true);

        if (!isset($data_response['data']['id'])) {
            wc_add_notice('Unable to proceed the payment using this method.', 'error');
            return;
        }

        $bill_id = $data_response['data']['id'] ?? 0;
        $bill_url = $data_response['data']['url'] ?? null;

        $order_note = wc_get_order($order_id);

        $order_note->add_order_note(
            'Customer made a payment attempt via Payright.<br>Bill Code : '.$bill_id.'<br>You can check the payment status of this bill in Payright account.'
        );

        # handle redirection to bill page
        return array(
            'result' => 'success',
            'redirect' => $this->base_url().'/bill/'.$bill_id,
        );
    }

    /**
     * handle payment completion for Payright request
     * @return void
     */
    public function handle_payright_return()
    {
        return $this->response_handler();
    }

    /**
     * handle payment completion for Payright request via callback
     * @return void
     */
    public function check_payright_callback()
    {
        return $this->response_handler();
    }


    private function response_handler() {
        global $woocommerce;

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $json_payload = file_get_contents('php://input');
            $payright_payload = json_decode($json_payload, true);
            $is_callback = isset($payright_payload['id']);
            $signature = isset($payright_payload['signature']) ? $this->clean($payright_payload['signature']) : false;
        }

        if ($_SERVER['REQUEST_METHOD'] == 'GET') {
            if (isset($_REQUEST['payright'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $payright_payload = $_REQUEST['payright']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $is_callback = false;
                $signature = isset($payright_payload['signature']) ? $this->clean($payright_payload['signature']) : false;
            }
        }

        if (isset($payright_payload) && is_array($payright_payload)) {
            $payright_payload_raw = $payright_payload; // store this as a raw data to use for checksum, if the data got sanitize, the checksum hash will be difference 
            $payright_payload = $this->sanitize_params($payright_payload);
        }

        if (isset($payright_payload['id']) && isset($payright_payload['order_no']) && $signature) {
            $order_id = $payright_payload['order_no'];
            $bill_id = $payright_payload['id'];
            $bill_status = $is_callback ? $payright_payload['state'] : $payright_payload['status'];
            $call_type = $is_callback ? 'callback' : 'redirect';

            $order = wc_get_order($order_id);

            if ($order && $order_id != 0)
            {
                $order_status = strtolower($order->get_status());
                switch($bill_status)
                {
                    case 'paid':
                        if (in_array($order_status, ['cancelled', 'pending', 'processing'])) {
                            if ($order_status == 'cancelled' || $order_status == 'pending') {
                                $calculate_checksum = $this->calculate_checksum($payright_payload_raw, $this->signature_key, $call_type);
                                if ((string) $calculate_checksum !== (string) $signature)
                                {
                                    $order->add_order_note('Mismatch signature data<br>
                                    <br>Bill ID: '.$bill_id.'
                                    <br>Order ID: '.$order_id);

                                    if ($is_callback) {
                                        echo 'OK';
                                        exit();
                                    }

                                    wp_redirect(wc_get_checkout_url());
                                    wc_add_notice('Payment failed<br>Reason: Mismatch response data', 'error');
                                    exit();
                                }

                                if ($is_callback) {
                                    $order->add_order_note('Payment is successfully made through Payright!<br>
                                    <br>Bill ID: '.$bill_id.'
                                    <br>Order ID: '.$order_id);
                                    $order->payment_complete();
                                }
                            }

                            if ($is_callback) {
                                echo 'OK';
                            } else {
                                wp_redirect($order->get_checkout_order_received_url());
                            }
                            exit();
                        }
                        break;
                    case 'due':
                        if (in_array($order_status, ['cancelled', 'pending', 'processing'])) {
                            if ($order_status == 'cancelled' || $order_status == 'pending') {
                                $calculate_checksum = $this->calculate_checksum($payright_payload_raw, $this->signature_key, $call_type);
                                if ((string) $calculate_checksum !== (string) $signature)
                                {
                                    $order->add_order_note('Mismatch signature data<br>
                                    <br>Bill ID: '.$bill_id.'
                                    <br>Order ID: '.$order_id);
                                    if ($is_callback) {
                                        echo 'OK';
                                        exit();
                                    }

                                    wp_redirect(wc_get_checkout_url());
                                    wc_add_notice('Payment failed<br>Reason: Mismatch response data', 'error');
                                    exit();
                                }

                                if ($is_callback) {
                                    $order->add_order_note('Payment attempt was failed.<br>
                                    <br>Bill ID: '.$bill_id.'
                                    <br>Order ID: '.$order_id);
                                }
                            }

                            if ($is_callback) {
                                echo 'OK';
                            } else {
                                wp_redirect(wc_get_checkout_url());
                                wc_add_notice('Payright payment failed', 'error');
                            }
                            exit();
                        }
                        break;
                }
            }
        }
    }

    private function sanitize_params($data = [])
    {
        $params = [
             'id',
             'collection',
             'paid',
             'state',
             'amount',
             'paid_amount',
             'due_at',
             'biller_name',
             'biller_email',
             'biller_mobile',
             'url',
             'paid_at',
             'order_no',
             'status',
             'signature',
             'wc-api',
         ];

        foreach ($params as $k) {
            if (isset($data[$k])) {
                $data[$k] = sanitize_text_field($data[$k]);
            }
        }
        return $data;
    }

    private function calculate_checksum($data, $signatureKey, $type = 'redirect') {
        switch($type) {
            case 'redirect':
                $data_sorted['payright']['id'] = $data['id'];
                $data_sorted['payright']['order_no'] = $data['order_no'];
                $data_sorted['payright']['paid_at'] = $data['paid_at'];
                $data_sorted['payright']['status'] = $data['status'];
                return hash_hmac('sha256', json_encode($data_sorted, JSON_UNESCAPED_SLASHES), $signatureKey);
            case 'callback':
                $data_sorted['amount'] = $data['amount'];
                $data_sorted['biller_email'] = $data['biller_email'];
                $data_sorted['biller_mobile'] = $data['biller_mobile'];
                $data_sorted['biller_name'] = $data['biller_name'];
                $data_sorted['collection'] = $data['collection'];
                $data_sorted['due_at'] = $data['due_at'];
                $data_sorted['id'] = $data['id'];
                $data_sorted['order_no'] = $data['order_no'];
                $data_sorted['paid'] = $data['paid'];
                $data_sorted['paid_amount'] = $data['paid_amount'];
                $data_sorted['paid_at'] = $data['paid_at'];
                $data_sorted['state'] = $data['state'];
                $data_sorted['url'] = $data['url'];
                return hash_hmac('sha256', json_encode($data_sorted, JSON_UNESCAPED_SLASHES), $signatureKey);
        }
    }

    /**
     * Validate fields, do nothing for the moment
     * @return boolean
     */
    public function validate_fields()
    {
        return true;
    }

   
    public function requirements_check() {
    }

    /**
     * Check if this gateway is enabled and available in the user's country.
     * Note: Not used for the time being
     * @return bool
     */
    public function is_valid_for_use()
    {
        return in_array(get_woocommerce_currency(), array('MYR'));
    }

    /**
     * checking for old version
     * @param  $version
     * @return bool
     */
    public function is_old_version()
    {
        return version_compare(WC_VERSION, '3.0', '<');
    }

    /**
     * get endpoint
     * @return mixed
     * @return string
     */
    public function get_endpoint()
    {
        return 'https://payright.my/api/v1';
    }

    /**
     * get base url
     * @return mixed
     */
    public function base_url()
    {
        return 'https://payright.my';
    }

    private function clean( $var ) {
        if ( is_array( $var ) ) {
            return array_map( 'self::clean', $var );
        } else {
            return is_scalar( $var ) ? sanitize_text_field( $var ) : $var;
        }
    }
    
}
