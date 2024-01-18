<?php

/*
 * Plugin Name: Yedpay for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/yedpay-for-woocommerce
 * Description: Easily accept Alipay, AlipayHK, Wechat Pay, UnionPay, Visa and mastercard on your Wordpress site using Yedpay WooCommerce payment gateway in one plugin for free.
 * Version: 1.2.1
 * Author: Yedpay
 * Author URI: https://www.yedpay.com/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yedpay-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 8.5.1
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Declare High Performance Order Storage compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

register_uninstall_hook(__FILE__, 'yedpay_uninstall');

// remove stored setting when plugin uninstall
function yedpay_uninstall()
{
    delete_option('woocommerce_yedpay_settings');
}

add_action('plugins_loaded', 'woocommerce_yedpay_init', 0);

function woocommerce_yedpay_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    /**
     * Yedpay Payment Gateway class
     */
    include_once plugin_dir_path(__FILE__) . '/WoocommerceYedpay.php';

    /**
     * Add this Gateway to WooCommerce
     */
    function woocommerce_add_yedpay_gateway($methods)
    {
        $methods[] = 'WoocommerceYedpay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_yedpay_gateway');
}
