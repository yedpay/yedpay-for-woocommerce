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

        // Woocommerce Setting
        $this->id = 'yedpay';
        $this->method_title = __('Yedpay', 'yedpay_woocommerce');
        $this->method_description = __('Extends WooCommerce to Process Payments with Yedpay.', 'yedpay_woocommerce');
        $this->icon = $this->get_image_path() . 'yedpay.png';
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];

        // Defining form fields
        $this->init_form_fields();
        // Load the settings.
        $this->init_settings();

        // Define user set variables
        $this->mode = $this->settings['yedpay_working_mode'];
        if ($this->mode == 'test') {
            $this->title = $this->settings['yedpay_title'] . ' - <b>Test Mode</b>';
        } else {
            $this->title = $this->settings['yedpay_title'];
        }

        $this->description = $this->settings['yedpay_description'];
        $this->yedpay_api_key = $this->settings['yedpay_api_key'];
        $this->yedpay_sign_key = $this->settings['yedpay_sign_key'];
        $this->yedpay_gateway = $this->settings['yedpay_gateway'];
        $this->yedpay_gateway_wallet = $this->settings['yedpay_gateway_wallet'];
        $this->yedpay_expiry_time = $this->settings['yedpay_expiry_time'];

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
                'title' => __('Enable/Disable', 'yedpay_woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Yedpay Payment Module', 'yedpay_woocommerce'),
                'default' => 'no'
            ],
            'yedpay_title' => [
                'title' => __('Title', 'yedpay_woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout', 'yedpay_woocommerce'),
                'default' => __('Yedpay', 'yedpay_woocommerce')
            ],
            'yedpay_description' => [
                'title' => __('Description', 'yedpay_woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'yedpay_woocommerce'),
                'default' => __('Pay securely by Yedpay All-in one Payment Platform', 'yedpay_woocommerce')
            ],
            'yedpay_api_key' => [
                'title' => __('Yedpay API Key', 'yedpay_woocommerce'),
                'type' => 'text',
                'description' => __('Your API Key from Yedpay', 'yedpay_woocommerce'),
            ],
            'yedpay_sign_key' => [
                'title' => __('Yedpay Sign Key', 'yedpay_woocommerce'),
                'type' => 'text',
                'description' => __('Your Sign Key from Yedpay', 'yedpay_woocommerce'),
            ],
            'yedpay_gateway' => [
                'title' => __('Gateway', 'yedpay_woocommerce'),
                'type' => 'select',
                'options' => [
                    '0' => 'All',
                    '4_2' => 'Alipay Online Only',
                    '8_2' => 'WeChat Pay Online Only',
                ],
                'description' => 'Support Gateways'
            ],
            'yedpay_gateway_wallet' => [
                'title' => __('Gateway Wallet', 'yedpay_woocommerce'),
                'type' => 'select',
                'options' => [
                    '0' => 'All',
                    'HK' => 'Hong Kong Wallet',
                    'CN' => 'China Wallet',
                ],
                'description' => 'Support Wallet (Applicable only for Alipay Online)'
            ],
            'yedpay_expiry_time' => [
                'title' => __('Expiry Time', 'yedpay_woocommerce'),
                'type' => 'text',
                'description' => 'Online Payment Expiry Time in seconds (900-10800)',
                'default' => __('10800', 'yedpay_woocommerce', 'yedpay_woocommerce')
            ],
            'yedpay_working_mode' => [
                'title' => __('Payment Mode', 'yedpay_woocommerce'),
                'type' => 'select',
                'options' => ['live' => 'Live Mode', 'test' => 'Test/Sandbox Mode'],
                'description' => 'Live/Test Mode'
            ],
        ];
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     */
    public function admin_options()
    {
        echo '<h3>' . __('Yedpay Payment Method Configuration', 'yedpay_woocommerce') . '</h3>';
        echo '<p>' . __('Yedpay is All-in one Payment Platform for Merchant', 'yedpay_woocommerce') . '</p>';
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '<tr><td>(Module Version 1.0.0)</td></tr></table>';
    }

    /**
     *  There are no payment fields for Yedpay, but want to show the description if set.
     */
    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }

        $currentUser = wp_get_current_user();
        $userId = $currentUser->ID;
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
        $_REQUEST = stripslashes_deep($_REQUEST);

        // verify sign
        $client = new Client($this->operation_mode(), $this->yedpay_api_key, false);
        if (!$client->verifySign($_REQUEST, $this->yedpay_sign_key, ['wc-api', 'page_id', 'order-received', 'key'])) {
            $this->error_response('Yedpay payment verify sign failed.');
        }

        $order_id = sanitize_text_field($_REQUEST['transaction']['custom_id']);
        if (is_null($order_id)) {
            $this->error_response('Order ID Not Found.');
        }

        try {
            $order = new WC_Order($order_id);
        } catch (Exception $e) {
            $logger->error('Order Not Found.');
        }

        // Update Order Status
        if ($order->get_status() == 'pending' || $order->get_status() == 'failed') {
            $status = sanitize_text_field($_REQUEST['transaction']['status']);

            // updating extra information in databaes corresponding to placed order.
            update_post_meta($order_id, 'yedpay_custom_id', $order_id);
            update_post_meta($order_id, 'yedpay_payment_status', $status);

            // Update Order Status
            if ($status == 'paid') {
                $order->update_status('processing');

                update_post_meta($order_id, 'yedpay_id', sanitize_text_field($_REQUEST['transaction']['id']));
                update_post_meta($order_id, 'yedpay_transaction_id', sanitize_text_field($_REQUEST['transaction']['transaction_id']));
                update_post_meta($order_id, 'yedpay_payment_method', sanitize_text_field($_REQUEST['transaction']['payment_method']));

                $order->add_order_note(__($this->getTransactionInformation($_REQUEST['transaction']), 'yedpay_woocommerce'));
                $order->payment_complete();
                // $order->reduce_order_stock();
                $woocommerce->cart->empty_cart();
            } elseif ($status == 'cancelled') {
                $order->update_status('cancelled');
                $order->add_order_note(__('Yedpay payment cancelled.', 'yedpay_woocommerce'));
            } elseif ($status == 'failed') {
                $order->update_status('failed');
                $order->add_order_note(__('Yedpay payment failed.', 'yedpay_woocommerce'));
            } else {
                $order->add_order_note(__('Yedpay payment Error.', 'yedpay_woocommerce'));
            }
        }
        die('success');
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
                Transaction ID: ' . sanitize_text_field($payment_data['custom_id']) . '<br>
                Yedpay Transaction ID: ' . sanitize_text_field($payment_data['transaction_id']) . '<br>
                Yedpay ID: ' . sanitize_text_field($payment_data['id']) . '<br>
                Gateway: ' . sanitize_text_field($payment_data['payment_method']) . '<br>
                Status: ' . sanitize_text_field($payment_data['status']) . '<br>
                Amount: ' . sanitize_text_field($payment_data['amount']) . '<br>
                Currency: ' . sanitize_text_field($payment_data['currency']) . '<br>
                Paid Time: ' . sanitize_text_field($payment_data['paid_at']);
    }

    /**
     * Thank You Page
     */
    public function thankyou_page($order_id)
    {
        global $woocommerce;

        $order = new WC_Order($order_id);

        $client = new Client($this->operation_mode(), $this->yedpay_api_key, false);
        if (!$client->verifySign($_REQUEST, $this->yedpay_sign_key, ['wc-api', 'page_id', 'order-received', 'key'])) {
            $orderNote = 'Yedpay payment verify sign failed.';
            // $this->error_response($orderNote, $order);
            $order->add_order_note(__($orderNote, 'yedpay_woocommerce'));
            return;
        }

        if ($order->get_status() == 'pending' || $order->get_status() == 'failed') {
            $status = sanitize_text_field($_REQUEST['status']);
            // $order_key = sanitize_text_field($_REQUEST['key']);

            // updating extra information in databaes corresponding to placed order.
            update_post_meta($order_id, 'yedpay_custom_id', $order_id);
            update_post_meta($order_id, 'yedpay_payment_status', $status);

            // Update Order Status
            if ($status == 'paid') {
                $order->update_status('processing');

                update_post_meta($order_id, 'yedpay_id', sanitize_text_field($_REQUEST['id']));
                update_post_meta($order_id, 'yedpay_transaction_id', sanitize_text_field($_REQUEST['transaction_id']));
                update_post_meta($order_id, 'yedpay_payment_method', sanitize_text_field($_REQUEST['payment_method']));

                $order->add_order_note(__($this->getTransactionInformation($_REQUEST), 'yedpay_woocommerce'));
                $order->payment_complete();
                // $order->reduce_order_stock();
                $woocommerce->cart->empty_cart();
                return;
            } elseif ($status == 'cancelled' || $status == 'expired') {
                $order->update_status('cancelled');
                $order->add_order_note(__('Yedpay payment cancelled.', 'yedpay_woocommerce'));
                $cancelUrl = $order->get_cancel_order_url_raw();
                wp_redirect($cancelUrl);
                return;
            } elseif ($status == 'failed') {
                $orderNote = 'Yedpay payment failed.';
            } else {
                $orderNote = 'Yedpay payment Error.';
            }
            $order->add_order_note(__($orderNote, 'yedpay_woocommerce'));
            $this->error_response($orderNote, $order);
        }
    }

    /**
     * Receipt Page
     */
    public function receipt_page($order)
    {
        echo '<p>' . __('Thank you for your order, please click the button below to pay with Yedpay.', 'yedpay_woocommerce') . '</p>';
    }

    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id)
    {
        global $woocommerce;

        $order = new WC_Order($order_id);

        // Change for 2.1
        if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
            $currency = $order->order_custom_fields['_order_currency'][0];

            $redirect_url = (get_option('woocommerce_thanks_page_id') != '') ? get_permalink(get_option('woocommerce_thanks_page_id')) : get_site_url() . '/';
        } else {
            $order_meta = get_post_custom($order_id);
            $currency = $order_meta['_order_currency'][0];

            $redirect_url = $this->get_return_url($order);
        }

        try {
            $client = new Client($this->operation_mode(), $this->yedpay_api_key, false);
            $client
                ->setCurrency($this->get_currency($currency))
                ->setReturnUrl($redirect_url)
                ->setNotifyUrl($this->notify_url)
                ->setSubject('Order #' . $order_id);

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

            $server_output = $client->onlinePayment($order_id, $order->order_total);
        } catch (Exception $e) {
            // No response or unexpected response
            $order->add_order_note(__('Yedpay Precreate failed. Error Message: ' . $e->getMessage(), 'yedpay_woocommerce'));
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
            $message = 'Yedpay Precreate failed. ' .
                        'Error Code: ' . $server_output->getErrorCode() . '. ' .
                        'Error Message: ' . $server_output->getMessage();
            $order->add_order_note(__($message, 'yedpay_woocommerce'));
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
        if ($order) {
            $order->update_status('failed', __('Payment has been declined', 'yedpay_woocommerce'));
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
        $order->add_order_note(__("Yedpay payment failed. Couldn't connect to gateway server.", 'yedpay_woocommerce'));
        wc_add_notice(__('No response from payment gateway server. Try again later or contact the site administrator.', 'yedpay_woocommerce'));
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
        } elseif ($currency == Client::CURRENCY_RMB) {
            return Client::INDEX_CURRENCY_RMB;
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
            return new WP_Error('wc-order', __('Order Not Found', 'yedpay_woocommerce'));
        }

        if ($amount != $order->get_total()) {
            return new WP_Error('IllegalAmount', __('Refund amount must be equal to Order total amount.', 'yedpay_woocommerce'));
        }
        if ($order->get_status() == 'refunded') {
            return new WP_Error('wc-order', __('Order has been already refunded', 'yedpay_woocommerce'));
        }

        $transaction_id = get_post_meta($order_id, 'yedpay_id', true);
        if (!isset($transaction_id)) {
            return new WP_Error('Error', __('Yedpay Transaction ID not found', 'yedpay_woocommerce'));
        }

        try {
            $client = new Client($this->operation_mode(), $this->yedpay_api_key, false);
            $server_output = $client->refund($transaction_id, !empty($reason) ? $reason : null);
        } catch (Exception $e) {
            // No response or unexpected response
            $message = "Yedpay Refund failed. Couldn't connect to gateway server.";
            $order->add_order_note(__($message, 'yedpay_woocommerce'));
            $logger->error($e->getMessage());
            return new WP_Error('Error', $message);
        }

        if ($server_output instanceof Success) {
            $refund_data = $server_output->getData();

            if (isset($refund_data->status) && $refund_data->status == 'refunded') {
                $order->add_order_note(__($this->getRefundInformation($refund_data), 'yedpay_woocommerce'));
                return true;
            }
        } elseif ($server_output instanceof Error) {
            $message = 'Yedpay Refund failed. ' .
                        'Error Code: ' . $server_output->getErrorCode() . '. ' .
                        'Error Message: ' . $server_output->getMessage();
            $order->add_order_note(__($message, 'yedpay_woocommerce'));
            $logger->error($message);
            return new WP_Error('Error', $message);
        }

        $message = 'Yedpay Refund failed, please contact Yedpay.';
        $order->add_order_note(__($message, 'yedpay_woocommerce'));
        return new WP_Error('Error', $message);
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
                Transaction ID: ' . sanitize_text_field($refund_data->custom_id) . '<br>
                Yedpay Transaction ID: ' . sanitize_text_field($refund_data->transaction_id) . '<br>
                Yedpay ID: ' . sanitize_text_field($refund_data->id) . '<br>
                Gateway: ' . sanitize_text_field($refund_data->gateway_sub_name) . '<br>
                Status: ' . sanitize_text_field($refund_data->status) . '<br>
                Amount: ' . sanitize_text_field($refund_data->amount) . '<br>
                Currency: ' . sanitize_text_field($refund_data->currency) . '<br>
                Refund Time: ' . sanitize_text_field($refund_data->refunded_at);
    }
}
