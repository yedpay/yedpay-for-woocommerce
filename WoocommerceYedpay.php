<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once 'vendor/autoload.php';

use Yedpay\Client;
use Yedpay\Response\Success;
use Yedpay\Response\Error;

/**
 * Yedpay Payment Gateway class
 */
class WoocommerceYedpay extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->method = 'AES-128-CBC'; // Encryption method, IT SHOULD NOT BE CHANGED

        // Load language files
        $this->load_text_domain();

        // Woocommerce Setting
        $this->id = 'yedpay';
        $this->method_title = __('Yedpay', 'yedpay-for-woocommerce');
        $this->method_description = __('Extends WooCommerce to Process Payments with Yedpay.', 'yedpay-for-woocommerce');
        $this->icon = $this->get_image_path() . 'yedpay_uqaw.svg';
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];

        // Defining form fields
        $this->init_form_fields();
        // Load the settings.
        $this->init_settings();

        // Define user set variables
        $this->mode = $this->settings['yedpay_working_mode'];
        if ($this->mode == 'test') {
            $this->title = $this->settings['yedpay_title'] . ' - <b>' . __('Test/Sandbox Mode', 'yedpay-for-woocommerce') . '</b>';
        } else {
            $this->title = $this->settings['yedpay_title'];
        }

        $this->description = $this->settings['yedpay_description'];
        $this->yedpay_api_key = $this->settings['yedpay_api_key'];
        $this->yedpay_sign_key = $this->settings['yedpay_sign_key'];
        $this->yedpay_gateway = $this->settings['yedpay_gateway'];
        $this->yedpay_gateway_wallet = $this->settings['yedpay_gateway_wallet'];
        $this->yedpay_expiry_time = $this->settings['yedpay_expiry_time'];
        $this->yedpay_custom_id_prefix = $this->settings['yedpay_custom_id_prefix'];

        $this->yedpay_version = '1.1.0';

        // Saving admin options
        if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [&$this, 'process_admin_options']);
        } else {
            add_action('woocommerce_update_options_payment_gateways', [&$this, 'process_admin_options']);
        }

        // Addition Hook
        add_action('woocommerce_receipt_' . $this->id, [&$this, 'receipt_page']);
        add_action('woocommerce_thankyou_' . $this->id, [&$this, 'thankyou_page'], 10, 1);

        add_action('init', [&$this, 'notify_handler']);
        add_action('woocommerce_init', [&$this, 'notify_handler']);
        $this->notify_url = add_query_arg('wc-api', 'woocommerceyedpay', home_url('/'));
        add_action('woocommerce_api_woocommerceyedpay', [&$this, 'notify_handler']);
    }

    /**
     * function to show fields in admin configuration form
     */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'yedpay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Yedpay Payment Module', 'yedpay-for-woocommerce'),
                'default' => 'no'
            ],
            'yedpay_title' => [
                'title' => __('Title', 'yedpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout', 'yedpay-for-woocommerce'),
                'default' => __('Yedpay', 'yedpay-for-woocommerce')
            ],
            'yedpay_description' => [
                'title' => __('Description', 'yedpay-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout', 'yedpay-for-woocommerce'),
                'default' => __('Pay securely by Yedpay All-in one Payment Platform', 'yedpay-for-woocommerce')
            ],
            'yedpay_api_key' => [
                'title' => __('Yedpay API Key', 'yedpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('Your API Key from Yedpay', 'yedpay-for-woocommerce'),
            ],
            'yedpay_sign_key' => [
                'title' => __('Yedpay Sign Key', 'yedpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('Your Sign Key from Yedpay', 'yedpay-for-woocommerce'),
            ],
            'yedpay_gateway' => [
                'title' => __('Gateway', 'yedpay-for-woocommerce'),
                'type' => 'select',
                'options' => [
                    '0' => __('All', 'yedpay-for-woocommerce'),
                    '4_2' => __('Alipay Online Only', 'yedpay-for-woocommerce'),
                    '8_2' => __('WeChat Pay Online Only', 'yedpay-for-woocommerce'),
                    '9_1' => __('UnionPay ExpressPay Only', 'yedpay-for-woocommerce'),
                    '9_5' => __('UnionPay UPOP Only', 'yedpay-for-woocommerce'),
                    '12_1' => __('Visa/mastercard Only', 'yedpay-for-woocommerce'),
                ],
                'description' => __('Support Gateways', 'yedpay-for-woocommerce')
            ],
            'yedpay_gateway_wallet' => [
                'title' => __('Gateway Wallet', 'yedpay-for-woocommerce'),
                'type' => 'select',
                'options' => [
                    '0' => __('All', 'yedpay-for-woocommerce'),
                    'HK' => __('Hong Kong Wallet', 'yedpay-for-woocommerce'),
                    'CN' => __('China Wallet', 'yedpay-for-woocommerce'),
                ],
                'description' => __('Support Wallet (Applicable only for Alipay Online)', 'yedpay-for-woocommerce')
            ],
            'yedpay_expiry_time' => [
                'title' => __('Expiry Time', 'yedpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('Online Payment Expiry Time in seconds (900-10800)', 'yedpay-for-woocommerce'),
                'default' => '10800'
            ],
            'yedpay_custom_id_prefix' => [
                'title' => __('Order ID Prefix', 'yedpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('Order ID Prefix (Maximum: 10 characters, accept only English letters)', 'yedpay-for-woocommerce'),
            ],
            'yedpay_working_mode' => [
                'title' => __('Payment Mode', 'yedpay-for-woocommerce'),
                'type' => 'select',
                'options' => ['live' => __('Live Mode', 'yedpay-for-woocommerce'), 'test' => __('Test/Sandbox Mode', 'yedpay-for-woocommerce')],
                'description' => __('Live/Test Mode', 'yedpay-for-woocommerce')
            ],
        ];
    }

    /**
     * Validate the yedpay custom id prefix
     *
     * @param string $key
     * @param string $value
     * @return string
     *
     */
    public function validate_yedpay_custom_id_prefix_field($key, $value)
    {
        // check if the Custom id prefix is maximum 10 characters, accept only English letters.
        if (isset($value) &&
            !empty($value) &&
            !preg_match('/^[a-zA-Z]{1,10}$/', $value)) {
            $error_message = "Invalid custom id prefix value: {$value}";
            WC_Admin_Settings::add_error($error_message);
            throw new Exception($error_message);
        }
        return $value;
    }

    /**
     * Validate the yedpay expiry time
     *
     * @param string $key
     * @param string $value
     * @return string
     *
     */
    public function validate_yedpay_expiry_time_field($key, $value)
    {
        // check if the Expiry time is longer than 900 seconds and shorter than 10800 seconds.
        if (isset($value) &&
            is_numeric($value) &&
            filter_var($value, FILTER_VALIDATE_INT) &&
            $value >= '900' &&
            $value <= '10800') {
            return $value;
        }
        $error_message = "Invalid expiry time value: {$value}";
        WC_Admin_Settings::add_error($error_message);
        throw new Exception($error_message);
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     */
    public function admin_options()
    {
        echo '<h3>' . __('Yedpay Payment Method Configuration', 'yedpay-for-woocommerce') . '</h3>';
        echo '<p>' . __('Yedpay is All-in one Payment Platform for Merchant', 'yedpay-for-woocommerce') . '</p>';
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '<tr><td>(' . __('Module Version', 'yedpay-for-woocommerce') . ' ' . $this->yedpay_version . ')</td></tr></table>';
    }

    /**
     *  There are no payment fields for Yedpay, but want to show the description if set.
     */
    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
    }

    /**
     * will call this method if payment gateway callback
     */
    public function notify_handler()
    {
        global $woocommerce;
        @ob_clean();

        $logger = wc_get_logger();

        // remove double slashes in string
        $request = stripslashes_deep($_POST);

        // verify sign
        $client = new Client($this->operation_mode(), $this->yedpay_api_key, false);
        if (!$client->verifySign($request, $this->yedpay_sign_key)) {
            $this->error_response(__('Yedpay payment notification verify sign failed.', 'yedpay-for-woocommerce'));
        }

        $custom_id = sanitize_text_field($request['transaction']['custom_id']);
        if (is_null($custom_id)) {
            $this->error_response(__('Order ID Not Found.', 'yedpay-for-woocommerce'));
        }

        // get order id
        $custom_id_prefix = $this->yedpay_custom_id_prefix;
        if (!empty($custom_id_prefix) && strpos($custom_id, $custom_id_prefix . '-') !== false) {
            $order_id = substr($custom_id, strlen($custom_id_prefix . '-'));
        } elseif (strpos($custom_id, '-') !== false) {
            $order_id = explode('-', $custom_id)[1];
        } else {
            $order_id = $custom_id;
        }

        try {
            $order = new WC_Order($order_id);
        } catch (Exception $e) {
            $this->error_response(__('Order Not Found.', 'yedpay-for-woocommerce'));
        }

        // Update Order Status
        $status = sanitize_text_field($request['transaction']['status']);
        if ($order->get_status() == 'pending' || $order->get_status() == 'failed') {
            // updating extra information in database corresponding to placed order.
            update_post_meta($order_id, 'yedpay_custom_id', $custom_id);
            update_post_meta($order_id, 'yedpay_payment_status', $status);

            // Update Order Status
            if ($status == 'paid') {
                $order->update_status('processing');

                update_post_meta($order_id, 'yedpay_id', sanitize_text_field($request['transaction']['id']));
                update_post_meta($order_id, 'yedpay_transaction_id', sanitize_text_field($request['transaction']['transaction_id']));
                update_post_meta($order_id, 'yedpay_payment_method', sanitize_text_field($request['transaction']['payment_method']));
                update_post_meta($order_id, 'yedpay_payment_gateway_code', sanitize_text_field($request['transaction']['gateway_code']));

                $order->add_order_note($this->getTransactionInformation($request['transaction']));
                $order->payment_complete();
                // $order->reduce_order_stock();
                $woocommerce->cart->empty_cart();
            } elseif ($status == 'cancelled') {
                $order->update_status('cancelled');
                $order->add_order_note(__('Yedpay payment cancelled.', 'yedpay-for-woocommerce'));
            } elseif ($status == 'failed') {
                $order->update_status('failed');
                $order->add_order_note(__('Yedpay payment failed.', 'yedpay-for-woocommerce'));
            } else {
                $order->add_order_note(__('Yedpay payment Error.', 'yedpay-for-woocommerce'));
            }
        } elseif ($order->get_status() != 'refunded' && ($status == 'refunded' || $status == 'void')) {
            $refund_data = (object) $request['transaction'];
            $this->refundByNotification($order_id, $refund_data);
        }
        die('success');
    }

    /**
     * Thank You Page
     */
    public function thankyou_page($order_id)
    {
        global $woocommerce;

        $order = new WC_Order($order_id);

        if ($order->get_status() == 'pending' || $order->get_status() == 'failed') {
            $checkoutUrl = $order->get_checkout_order_received_url();
            $query_str = parse_url($checkoutUrl, PHP_URL_QUERY);
            parse_str($query_str, $query_params);

            $request = $_GET;

            $client = new Client($this->operation_mode(), $this->yedpay_api_key, false);
            if (!$client->verifySign($request, $this->yedpay_sign_key, array_keys($query_params))) {
                $orderNote = 'Yedpay payment verify sign failed.';
                $order->add_order_note(__($orderNote, 'yedpay-for-woocommerce'));
                return;
            }

            $status = sanitize_text_field($request['status']);
            // $order_key = sanitize_text_field($request['key']);

            // updating extra information in database corresponding to placed order.
            update_post_meta($order_id, 'yedpay_custom_id', sanitize_text_field($request['custom_id']));
            update_post_meta($order_id, 'yedpay_payment_status', $status);

            // Update Order Status
            if ($status == 'paid') {
                $order->update_status('processing');

                update_post_meta($order_id, 'yedpay_id', sanitize_text_field($request['id']));
                update_post_meta($order_id, 'yedpay_transaction_id', sanitize_text_field($request['transaction_id']));
                update_post_meta($order_id, 'yedpay_payment_method', sanitize_text_field($request['payment_method']));
                update_post_meta($order_id, 'yedpay_payment_gateway_code', sanitize_text_field($request['gateway_code']));

                $order->add_order_note($this->getTransactionInformation($request));
                $order->payment_complete();
                // $order->reduce_order_stock();
                $woocommerce->cart->empty_cart();
                return;
            } elseif ($status == 'cancelled' || $status == 'expired') {
                $order->update_status('cancelled');
                $order->add_order_note(__('Yedpay payment cancelled.', 'yedpay-for-woocommerce'));
                $cancelUrl = $order->get_cancel_order_url_raw();
                wp_redirect($cancelUrl);
                return;
            } elseif ($status == 'failed') {
                $orderNote = 'Yedpay payment failed.';
            } else {
                $orderNote = 'Yedpay payment Error.';
            }
            $order->add_order_note(__($orderNote, 'yedpay-for-woocommerce'));
            $this->error_response(__($orderNote, 'yedpay-for-woocommerce'), $order);
        }
    }

    /**
     * Receipt Page
     */
    public function receipt_page($order)
    {
        echo '<p>' . __('Thank you for your order, please click the button below to pay with Yedpay.', 'yedpay-for-woocommerce') . '</p>';
    }

    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id)
    {
        global $woocommerce;
        global $wp_version;

        $order = new WC_Order($order_id);

        // Change for 2.1
        if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
            $currency = $order->order_custom_fields['_order_currency'][0];

            $redirect_url = (get_option('woocommerce_thanks_page_id') != '') ? get_permalink(get_option('woocommerce_thanks_page_id')) : get_site_url() . '/';
        } else {
            $currency = $order->get_currency();

            $redirect_url = $this->get_return_url($order);
        }

        try {
            $custom_id = $this->getCustomOrderId($order_id);

            $client = new Client($this->operation_mode(), $this->yedpay_api_key, false);
            $client
                ->setCurrency($this->get_currency($currency))
                ->setReturnUrl($redirect_url)
                ->setNotifyUrl($this->notify_url)
                ->setSubject('Order: ' . $custom_id)
                ->setMetadata(json_encode([
                    'woocommerce' => WC_VERSION,
                    'yedpay_for_woocommerce' => $this->yedpay_version,
                    'wordpress' => $wp_version,
                ]));

            if ($this->yedpay_gateway != '0') {
                $client->setGatewayCode($this->yedpay_gateway);
            }
            if ($this->yedpay_gateway == '4_2' && $this->yedpay_gateway_wallet != '0') {
                $client->setWallet($this->get_wallet($this->yedpay_gateway_wallet));
            }
            if (is_numeric($this->yedpay_expiry_time) &&
                filter_var($this->yedpay_expiry_time, FILTER_VALIDATE_INT) &&
                $this->yedpay_expiry_time >= '900' &&
                $this->yedpay_expiry_time <= '10800'
            ) {
                $client->setExpiryTime($this->yedpay_expiry_time);
            }

            $billing_country = sanitize_text_field($order->get_billing_country());
            $billing_address = [
                'email' => $order->get_billing_email(),
                'billing_country' => $billing_country,
                'billing_post_code' => $order->get_billing_postcode(),
                'billing_city' => $order->get_billing_city(),
                'billing_address1' => $order->get_billing_address_1(),
                'billing_address2' => $order->get_billing_address_2(),
            ];

            if ($billing_country == 'US' || $billing_country == 'CA') {
                $billing_address['billing_state'] = sanitize_text_field($order->get_billing_state());
            }
            $client->setPaymentData(json_encode($billing_address));

            $server_output = $client->onlinePayment($custom_id, $order->order_total);
        } catch (Exception $e) {
            // No response or unexpected response
            $order->add_order_note('Yedpay Precreate failed. Error Message: ' . esc_html($e->getMessage()));
            $this->get_response($order);
            return;
        }

        if ($server_output instanceof Success) {
            $data = $server_output->getData();

            return [
                    'result' => 'success',
                    'redirect' => $data->checkout_url,
                ];
        } elseif ($server_output instanceof Error) {
            $message = $this->getServerOutputErrorMessage($server_output, 'Precreate');
            $order->add_order_note($message);
        }

        // No response or unexpected response
        $this->get_response($order);
        return;
    }

    /**
     * Returns Operation Mode
     *
     * @return string
     */
    public function operation_mode()
    {
        if ($this->mode == 'live') {
            return 'production';
        }
        return 'staging';
    }

    /**
     * function to get form post values
     *
     * @param string $name
     * @return string|void
     */
    public function get_post($name)
    {
        if (isset($_POST[$name])) {
            return sanitize_text_field($_POST[$name]);
        }
        return null;
    }

    /**
     * function to show error response
     *
     * @param string $msg
     * @param array $order
     * @return string
     */
    private function error_response($msg, $order = null)
    {
        $logger = wc_get_logger();
        $logger->warning($msg);
        if ($order && $order->get_status() == 'pending') {
            $order->update_status('failed', __('Payment has been declined', 'yedpay-for-woocommerce'));
        }
        http_response_code(403);
        die($msg);
    }

    /**
     * function to show failed response
     *
     * @param array $order
     * @return void
     */
    public function get_response($order)
    {
        $order->add_order_note(__("Yedpay payment failed. Couldn't connect to gateway server.", 'yedpay-for-woocommerce'));
        wc_add_notice(__('No response from payment gateway server. Try again later or contact the site administrator.', 'yedpay-for-woocommerce'));
    }

    /**
     * Returns Currency Index
     *
     * @param string $currency
     * @return int|void currency index
     */
    public function get_currency($currency)
    {
        if ($currency == Client::CURRENCY_HKD) {
            return Client::INDEX_CURRENCY_HKD;
        }
        return null;
    }

    /**
     * Returns Wallet Index
     *
     * @param string $wallet
     * @return int|void
     */
    public function get_wallet($wallet)
    {
        if ($wallet == Client::HK_WALLET) {
            return Client::INDEX_WALLET_HK;
        } elseif ($wallet == Client::CN_WALLET) {
            return Client::INDEX_WALLET_CN;
        }
        return null;
    }

    /**
     * Returns Logo Image Path
     *
     * @return string
     */
    public function get_image_path()
    {
        return WP_PLUGIN_URL . '/' . plugin_basename(dirname(__FILE__)) . '/images/';
    }

    /**
     * If the gateway declares 'refunds' support, this will allow it to refund.
     *
     * @param int $order_id
     * @param float $amount
     * @param string $reason
     * @return boolean|WP_Error success, fail or a WP_Error object.
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        global $woocommerce;
        $logger = wc_get_logger();

        try {
            $order = new WC_Order($order_id);
        } catch (Exception $e) {
            $logger->error('Order Not Found');
            return new WP_Error('wc-order', __('Order Not Found.', 'yedpay-for-woocommerce'));
        }

        if ($amount < 0.1) {
            return new WP_Error('IllegalAmount', __('Refund amount must be at least 0.1.'));
        }

        if ($order->get_status() == 'refunded') {
            return new WP_Error('wc-order', __('Order has been already refunded.', 'yedpay-for-woocommerce'));
        }

        $is_receive_notification = get_post_meta($order_id, 'yedpay_receive_refund_notification', true);
        if ($is_receive_notification == 'yes' && $this->isCreditCardGateway($order_id)) {
            delete_post_meta($order_id, 'yedpay_receive_refund_notification');
            return true;
        }

        $custom_id = get_post_meta($order_id, 'yedpay_custom_id', true);
        if (empty($custom_id)) {
            $custom_id = $this->getCustomOrderId($order_id);
        }

        try {
            $client = new Client($this->operation_mode(), $this->yedpay_api_key, false);
            $server_output = $client->refundByCustomId($custom_id, !empty($reason) ? $reason : null, $amount);
        } catch (Exception $e) {
            // No response or unexpected response
            $message = "Yedpay Refund failed. Couldn't connect to gateway server.";
            $order->add_order_note(__($message, 'yedpay-for-woocommerce'));
            $logger->error($e->getMessage());
            return new WP_Error('Error', $message);
        }

        if ($server_output instanceof Success) {
            $refund_data = $server_output->getData();

            if (isset($refund_data->status) && $refund_data->status == 'refunded') {
                $order->add_order_note($this->getRefundInformation($refund_data));
                return true;
            } elseif (isset($refund_data->status) && $refund_data->status == 'pending_refund' && $this->isCreditCardGateway($order_id)) {
                update_post_meta($order_id, 'yedpay_refund_reason', sanitize_text_field($reason));
                $message = 'Yedpay Refund processing. Please wait Yedpay refund notification or check the transaction latest status via Yedpay merchant portal.';
                $order->add_order_note(__($message, 'yedpay-for-woocommerce'));
                return new WP_Error('Error', $message);
            }
        } elseif ($server_output instanceof Error) {
            $message = $this->getServerOutputErrorMessage($server_output, 'Refund');
            $order->add_order_note($message);
            $logger->error($message);
            return new WP_Error('Error', $message);
        }

        $message = 'Yedpay Refund failed, please contact Yedpay.';
        $order->add_order_note(__($message, 'yedpay-for-woocommerce'));
        return new WP_Error('Error', $message);
    }

    /**
     * function to load translation file
     */
    private function load_text_domain()
    {
        // Set filter for plugin's languages directory
        $plugin_lang_dir = plugin_dir_path(__FILE__) . 'languages/';

        // Traditional WordPress plugin locale filter
        $locale = apply_filters('plugin_locale', get_locale(), 'yedpay-for-woocommerce');
        $mofile = sprintf('%1$s-%2$s.mo', 'yedpay-for-woocommerce', $locale);

        // Setup paths to current locale file
        $mofile_local = $plugin_lang_dir . $mofile;
        $mofile_global = WP_LANG_DIR . '/' . basename(plugin_dir_path(__FILE__)) . '/' . $mofile;

        if (file_exists($mofile_global)) {
            // Look in global /wp-content/languages/yedpay-for-woocommerce folder
            load_textdomain('yedpay-for-woocommerce', $mofile_global);
        } elseif (file_exists($mofile_local)) {
            // Look in local /wp-content/plugins/yedpay-for-woocommerce/languages/ folder
            load_textdomain('yedpay-for-woocommerce', $mofile_local);
        } else {
            // Load the default language files
            load_plugin_textdomain('yedpay-for-woocommerce', false, $plugin_lang_dir);
        }
    }

    /**
     * Get gateway icon.
     *
     * @access public
     * @return string
     */
    public function get_icon()
    {
        switch ($this->yedpay_gateway) {
            case '4_2':
                $icon_path = $this->get_image_path() . 'yedpay_alipay.svg';
                break;

            case '8_2':
                $icon_path = $this->get_image_path() . 'yedpay_wechatpay.svg';
                break;

            case '9_1':
            case '9_5':
                $icon_path = $this->get_image_path() . 'yedpay_unionpay.svg';
                break;

            default:
                $icon_path = !empty($this->icon) ? $this->icon : $this->get_image_path() . 'yedpay_uqaw.svg';
                break;
        }

        $icon_width = '120';
        $icon_html = '<img src="' . $icon_path . '" alt="' . $this->title . '" style="max-width:' . $icon_width . 'px;"/>';

        return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
    }

    /**
     * function to refund transaction by notification
     *
     * @param int $order_id
     * @param object $refund_data
     * @return void
     */
    protected function refundByNotification($order_id, $refund_data)
    {
        $order = new WC_Order($order_id);
        $refundedAmount = $refund_data->refunded;
        if ($order->get_remaining_refund_amount() >= $refundedAmount) {
            $order->add_order_note(__('Receive Yedpay refund notification.', 'yedpay-for-woocommerce'));
            $refund_reason = get_post_meta($order_id, 'yedpay_refund_reason', true);
            update_post_meta($order_id, 'yedpay_receive_refund_notification', 'yes');

            $refund = wc_create_refund([
                    'amount' => $refundedAmount,
                    'reason' => $refund_reason ?? 'Payment was refunded via Yedpay merchant portal',
                    'order_id' => $order_id,
                    'refund_payment' => true
                ]);
            if (is_wp_error($refund)) {
                if ($refund->get_error_message() == 'Invalid refund amount.') {
                    delete_post_meta($order_id, 'yedpay_receive_refund_notification');
                    $this->error_response(__('Refund requested exceeds remaining order balance of ', 'yedpay-for-woocommerce') . $order->get_formatted_order_total());
                } else {
                    delete_post_meta($order_id, 'yedpay_receive_refund_notification');
                    $this->error_response($refund->get_error_message());
                }
            }

            $gateway_sub_name = get_post_meta($order_id, 'yedpay_payment_method', true);
            $refund_data->gateway_sub_name = $gateway_sub_name;
            $order->add_order_note($this->getRefundInformation($refund_data));
        } else {
            $this->error_response(__('Refund requested exceeds remaining order balance of ', 'yedpay-for-woocommerce') . $order->get_formatted_order_total());
        }
    }

    /**
     * function to show transaction information
     *
     * @param array $payment_data
     * @return string
     */
    protected function getTransactionInformation($payment_data)
    {
        return  'Yedpay Transaction Information:<br>
                Order ID: ' . sanitize_text_field($payment_data['custom_id']) . '<br>
                Yedpay Transaction ID: ' . sanitize_text_field($payment_data['transaction_id']) . '<br>
                Transaction ID: ' . sanitize_text_field($payment_data['id']) . '<br>
                Gateway: ' . sanitize_text_field($payment_data['payment_method']) . '<br>
                Status: ' . sanitize_text_field($payment_data['status']) . '<br>
                Amount: ' . sanitize_text_field($payment_data['amount']) . '<br>
                Currency: ' . sanitize_text_field($payment_data['currency']) . '<br>
                Paid Time: ' . sanitize_text_field($payment_data['paid_at']);
    }

    /**
     * function to show refund information
     *
     * @param array $refund_data
     * @return string
     */
    protected function getRefundInformation($refund_data)
    {
        return  'Yedpay Refund Completed.<br>
                Yedpay Refund Information:<br>
                Order ID: ' . sanitize_text_field($refund_data->custom_id) . '<br>
                Yedpay Transaction ID: ' . sanitize_text_field($refund_data->transaction_id) . '<br>
                Transaction ID: ' . sanitize_text_field($refund_data->id) . '<br>
                Gateway: ' . sanitize_text_field($refund_data->gateway_sub_name) . '<br>
                Status: ' . sanitize_text_field($refund_data->status) . '<br>
                Refunded Amount: ' . sanitize_text_field($refund_data->refunded) . '<br>
                Currency: ' . sanitize_text_field($refund_data->currency) . '<br>
                Refund Time: ' . sanitize_text_field($refund_data->refunded_at);
    }

    /**
     * function to return gateway code is credit card online or not
     *
     * @param array $refund_data
     * @return bool
     */
    protected function isCreditCardGateway($order_id)
    {
        $gateway_code = get_post_meta($order_id, 'yedpay_payment_gateway_code', true);
        return ($gateway_code == '12_1' || $gateway_code == '12_2');
    }

    /**
    * function to show error message from server output
    *
    * @param Error $server_output
    * @param string $type
    * @return string
    */
    protected function getServerOutputErrorMessage($server_output, $type)
    {
        $message = "Yedpay {$type} failed.";

        if ($server_output->getErrorCode() == 422 && is_array($server_output->getErrors())) {
            foreach ($server_output->getErrors() as $validationErrors) {
                $message .= '<br>';
                foreach ($validationErrors as $errorKey => $errorInfo) {
                    $message .= '<br>Error ' . esc_html("{$errorKey}: {$errorInfo}");
                }
            }
        } else {
            $message .= '<br>Error Code: ' . esc_html($server_output->getErrorCode()) .
                '<br>Error Message: ' . esc_html($server_output->getMessage());
        }
        return $message;
    }

    /**
    * function to get custom order id
    *
    * @param string $order_id
    * @return string
    */
    protected function getCustomOrderId($order_id)
    {
        if (!empty($this->yedpay_custom_id_prefix)) {
            return $this->yedpay_custom_id_prefix . '-' . $order_id;
        }
        return $order_id;
    }
}
