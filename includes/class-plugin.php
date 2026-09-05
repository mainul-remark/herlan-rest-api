<?php

namespace HerlanRestApi;

use HerlanRestApi\Controllers\AccountController;
use HerlanRestApi\Controllers\AppController;
use HerlanRestApi\Controllers\CouponController;
use HerlanRestApi\Controllers\AuthController;
use HerlanRestApi\Controllers\LoyaltyController;
use HerlanRestApi\Controllers\OrderController;
use HerlanRestApi\Controllers\PaymentController;
use HerlanRestApi\Controllers\ProductController;
use HerlanRestApi\Controllers\ProductListingController;
use HerlanRestApi\Controllers\UserController;
use HerlanRestApi\Controllers\TaxonomyController;
use HerlanRestApi\Controllers\HomeController;
use HerlanRestApi\Controllers\InvoiceController;
use HerlanRestApi\Controllers\CartController;
use HerlanRestApi\Controllers\CheckoutController;
use HerlanRestApi\Controllers\SearchController;
use HerlanRestApi\Controllers\StoreController;
use HerlanRestApi\Controllers\WishlistController;
use HerlanRestApi\Controllers\BlogController;
use HerlanRestApi\Support\Cache;
use HerlanRestApi\Support\PaymentReturnRedirect;
use HerlanRestApi\Support\RequestLogger;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    public const REST_NAMESPACE = 'herlan/v1';

    private static ?self $instance = null;

    public static function instance(): self
    {
        if (! self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_filter('determine_current_user', [$this, 'bypass_jwt_auth_for_herlan_routes'], 1);
        add_filter('rest_pre_dispatch', [$this, 'bypass_jwt_auth_pre_dispatch_for_herlan_routes'], 1, 3);
        // Priority PHP_INT_MAX: must run after every other rest_pre_dispatch filter (e.g. ACF's
        // ACF_Rest_Api::initialize() unconditionally returns null, clobbering any earlier result),
        // so this check has the final say regardless of what other plugins do on this hook.
        add_filter('rest_pre_dispatch', [$this, 'validate_woo_consumer_key'], PHP_INT_MAX, 3);
        add_filter('rest_authentication_errors', [$this, 'bypass_auth_errors_for_herlan_routes'], PHP_INT_MAX);
        add_action('rest_api_init', [$this, 'register_routes']);
        Cache::boot();
        RequestLogger::boot();
        $this->boot_home_cache_warmer();
        (new PaymentReturnRedirect())->boot();
    }

    /**
     * Requires a valid WooCommerce REST API key pair on every herlan/v1
     * request, validated against wp_woocommerce_api_keys — the same
     * credentials generated under WooCommerce → Settings → Advanced → REST
     * API. Accepts either the plugin's own Woo-Consumer-Key /
     * Woo-Consumer-Secret headers, or standard HTTP Basic Auth
     * (Authorization: Basic base64(consumer_key:consumer_secret)) — the same
     * convention WooCommerce's own REST API uses.
     */
    public function validate_woo_consumer_key($result, $server, $request)
    {
        if (null !== $result) {
            return $result;
        }

        if (! $request instanceof \WP_REST_Request || ! str_starts_with($request->get_route(), '/' . self::REST_NAMESPACE . '/')) {
            return $result;
        }

        if (! function_exists('wc_api_hash')) {
            // WooCommerce isn't active — nothing to validate against.
            return $result;
        }

        $consumer_key    = trim((string) $request->get_header('woo-consumer-key'));
        $consumer_secret = trim((string) $request->get_header('woo-consumer-secret'));

        if ($consumer_key === '' || $consumer_secret === '') {
            [$consumer_key, $consumer_secret] = $this->parse_basic_auth_credentials($request);
        }

        if ($consumer_key === '' || $consumer_secret === '') {
            return new \WP_Error(
                'herlan_forbidden_woo_key',
                __('Missing WooCommerce consumer key/secret.', 'herlan-rest-api'),
                ['status' => 403]
            );
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT consumer_secret FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
                wc_api_hash($consumer_key)
            )
        );

        if (! $row || ! hash_equals((string) $row->consumer_secret, $consumer_secret)) {
            return new \WP_Error(
                'herlan_forbidden_woo_key',
                __('Invalid WooCommerce consumer key or secret.', 'herlan-rest-api'),
                ['status' => 403]
            );
        }

        return $result;
    }

    /**
     * Extracts [consumer_key, consumer_secret] from HTTP Basic Auth credentials,
     * if present. PHP_AUTH_USER/PHP_AUTH_PW is populated by mod_php/most SAPIs;
     * php-fpm setups that don't forward it still expose the raw Authorization
     * header, so that's parsed as a fallback.
     *
     * @return array{0: string, 1: string}
     */
    private function parse_basic_auth_credentials(\WP_REST_Request $request): array
    {
        if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            return [
                trim((string) wp_unslash($_SERVER['PHP_AUTH_USER'])),
                trim((string) wp_unslash($_SERVER['PHP_AUTH_PW'])),
            ];
        }

        $header = (string) $request->get_header('authorization');
        if ($header === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
        }

        if (! str_starts_with($header, 'Basic ')) {
            return ['', ''];
        }

        $decoded = base64_decode(trim(substr($header, 6)), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return ['', ''];
        }

        [$user, $pass] = explode(':', $decoded, 2);

        return [trim($user), trim($pass)];
    }

    public function bypass_jwt_auth_for_herlan_routes($user)
    {
        if ($this->is_herlan_rest_request()) {
            $this->remove_jwt_auth_filters();
        }

        return $user;
    }

    public function bypass_jwt_auth_pre_dispatch_for_herlan_routes($result, $server, $request)
    {
        if ($request instanceof \WP_REST_Request && str_starts_with($request->get_route(), '/' . self::REST_NAMESPACE . '/')) {
            $this->remove_jwt_auth_filters();
        }

        return $result;
    }

    public function bypass_auth_errors_for_herlan_routes($result)
    {
        if (is_wp_error($result) && $this->is_herlan_rest_request()) {
            return null;
        }

        return $result;
    }

    public function register_routes(): void
    {
        $auth = new AuthController();

        (new AppController())->register_routes();
        (new HomeController())->register_routes();
        $auth->register_routes();
        (new UserController($auth))->register_routes();
        (new AccountController($auth))->register_routes();
        (new OrderController($auth))->register_routes();
        (new InvoiceController($auth))->register_routes();
        (new LoyaltyController($auth))->register_routes();
        (new CouponController($auth))->register_routes();
        (new ProductListingController())->register_routes();
        (new ProductController($auth))->register_routes();
        (new TaxonomyController())->register_routes();
        (new WishlistController($auth))->register_routes();
        (new CartController($auth))->register_routes();
        (new CheckoutController($auth))->register_routes();
        (new PaymentController($auth))->register_routes();
        (new SearchController($auth))->register_routes();
        (new StoreController())->register_routes();
        (new BlogController())->register_routes();
    }

    /**
     * Keeps the /home transient permanently warm. The home bundle is expensive
     * to rebuild (~40 product-tag groups, each running its own WP_Query +
     * wc_get_product() calls), so instead of a real visitor's request ever
     * paying for a cold-cache rebuild, a cron event refreshes it every 10
     * minutes — inside the 15-minute cache TTL.
     */
    private function boot_home_cache_warmer(): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            if (! isset($schedules['herlan_ra_ten_minutes'])) {
                $schedules['herlan_ra_ten_minutes'] = [
                    'interval' => 10 * MINUTE_IN_SECONDS,
                    'display'  => __('Every 10 Minutes (Herlan REST API)', 'herlan-rest-api'),
                ];
            }

            return $schedules;
        });

        add_action('init', static function (): void {
            if (! wp_next_scheduled('herlan_ra_warm_home_cache')) {
                wp_schedule_event(time(), 'herlan_ra_ten_minutes', 'herlan_ra_warm_home_cache');
            }
        });

        add_action('herlan_ra_warm_home_cache', [HomeController::class, 'warm_cache']);
    }

    private function is_herlan_rest_request(): bool
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';

        if (str_contains($request_uri, '/' . rest_get_url_prefix() . '/' . self::REST_NAMESPACE . '/')) {
            return true;
        }

        if (isset($_GET['rest_route'])) {
            $rest_route = (string) wp_unslash($_GET['rest_route']);

            return str_starts_with($rest_route, '/' . self::REST_NAMESPACE . '/');
        }

        return false;
    }

    private function remove_jwt_auth_filters(): void
    {
        $this->remove_callbacks_by_class('determine_current_user', 'Jwt_Auth_Public');
        $this->remove_callbacks_by_class('rest_pre_dispatch', 'Jwt_Auth_Public');
    }

    private function remove_callbacks_by_class(string $hook, string $class): void
    {
        global $wp_filter;

        if (empty($wp_filter[$hook]) || ! $wp_filter[$hook] instanceof \WP_Hook) {
            return;
        }

        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'] ?? null;

                if (is_array($function) && isset($function[0]) && is_object($function[0]) && $function[0] instanceof $class) {
                    remove_filter($hook, $function, (int) $priority);
                }
            }
        }
    }
}
