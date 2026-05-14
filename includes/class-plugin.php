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
use HerlanRestApi\Controllers\UserController;
use HerlanRestApi\Controllers\TaxonomyController;
use HerlanRestApi\Controllers\WishlistController;

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
        add_action('rest_api_init', [$this, 'register_routes']);
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

    public function register_routes(): void
    {
        $auth = new AuthController();

        (new AppController())->register_routes();
        $auth->register_routes();
        (new UserController($auth))->register_routes();
        (new AccountController($auth))->register_routes();
        (new OrderController($auth))->register_routes();
        (new LoyaltyController($auth))->register_routes();
        (new CouponController($auth))->register_routes();
        (new ProductController())->register_routes();
        (new TaxonomyController())->register_routes();
        (new WishlistController($auth))->register_routes();
        (new PaymentController($auth))->register_routes();
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
