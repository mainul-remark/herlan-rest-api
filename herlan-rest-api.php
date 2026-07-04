<?php
/**
 * Plugin Name: Herlan REST API
 * Description: Mobile application REST API for Herlan Live.
 * Version: 0.1.0
 * Author: Herlan
 * Text Domain: herlan-rest-api
 */

if (! defined('ABSPATH')) {
    exit;
}

defined('HERLAN_REST_API_FILE') || define('HERLAN_REST_API_FILE', __FILE__);
defined('HERLAN_REST_API_PATH') || define('HERLAN_REST_API_PATH', plugin_dir_path(__FILE__));
defined('HERLAN_REST_API_VERSION') || define('HERLAN_REST_API_VERSION', '0.1.0');

require_once HERLAN_REST_API_PATH . 'includes/Support/class-response.php';
require_once HERLAN_REST_API_PATH . 'includes/Support/class-payment-return-redirect.php';
require_once HERLAN_REST_API_PATH . 'includes/class-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-app-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-home-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-auth-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-user-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-product-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-product-listing-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-payment-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-account-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-order-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-loyalty-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-coupon-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-wishlist-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-cart-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-checkout-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-taxonomy-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-search-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-store-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/Controllers/class-blog-controller.php';
require_once HERLAN_REST_API_PATH . 'includes/class-plugin.php';

add_action('plugins_loaded', static function () {
    \HerlanRestApi\Plugin::instance()->boot();
});
