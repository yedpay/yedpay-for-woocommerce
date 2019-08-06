<?php

/*
  Plugin Name: Yedpay
  Description: Extends WooCommerce to Process Yedpay Payments.
  Version: 1.0.0
  Plugin URI: https://wordpress.org/plugins/yedpay/
  Author: Yedpay
  Author URI: https://www.yedpay.com/
  Developer: Yedpay
  Developer URI:
  License: Under GPL2
  Note: Under Development
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

register_uninstall_hook(__FILE__, 'yedpay_uninstall');

// remove stored setting when plugin uninstall
function yedpay_uninstall()
{
    delete_option('woocommerce_yedpay_settings');
}

add_action('plugins_loaded', 'woocommerce_tech_yedpay_init', 0);

function woocommerce_tech_yedpay_init()
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
    function woocommerce_add_tech_yedpay_gateway($methods)
    {
        $methods[] = 'WoocommerceYedpay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_tech_yedpay_gateway');
}
