<?php
/**
 * Plugin Name: Payright for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/payright-for-woocommerce/#installation
 * Description: Integrate your WooCommerce site with Payright Payment Gateway.
 * Version: 1.0.3
 * Author: Payright Sdn Bhd
 * Author URI: https://payright.my
 * WC tested up to: 6.0.2
 *
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

# Include Payright class and register Payment Gateway with WooCommerce
add_action('plugins_loaded', 'payright_init', 0);

function payright_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    include_once 'src/payright.php';

    add_filter('woocommerce_payment_gateways', 'add_payright_to_woocommerce');

    /**
     * @param  $methods
     * @return mixed
     */
    function add_payright_to_woocommerce($methods)
    {
        $methods[] = 'payright';

        return $methods;
    }
}

# Add custom action links
add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'payright_link');

/**
 * @param $links
 */
function payright_link($links)
{
    $plugin_links = array(
        '<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section=payright').'">'.__('Settings', 'payright').'</a>',
    );

    return array_merge($plugin_links, $links);
}

add_action('init', 'payright_check_response', 15);

/**
 * @return null
 */
function payright_check_response()
{
    # If the parent WC_Payment_Gateway class doesn't exist it means WooCommerce is not installed on the site, so do nothing
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    include_once 'src/payright.php';

    $payright = new Payright();
    $payright->handle_payright_return();
    $payright->check_payright_callback();
}

add_filter('https_ssl_verify', '__return_false');
