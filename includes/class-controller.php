<?php

namespace HerlanRestApi;

use HerlanRestApi\Controllers\AuthController;
use WP_Error;
use WP_REST_Request;
use WP_User;

if (! defined('ABSPATH')) {
    exit;
}

abstract class Controller
{
    protected string $namespace = Plugin::REST_NAMESPACE;

    protected ?AuthController $auth = null;

    public function __construct(?AuthController $auth = null)
    {
        $this->auth = $auth;
    }

    abstract public function register_routes(): void;

    public function can_access(WP_REST_Request $request): bool|WP_Error
    {
        if (! $this->auth) {
            return new WP_Error('herlan_auth_unavailable', __('Authentication is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $user = $this->auth->authenticate_request($request);

        if (is_wp_error($user)) {
            return $user;
        }

        $request->set_param('herlan_current_user', $user);

        return true;
    }

    protected function current_user(WP_REST_Request $request): ?WP_User
    {
        $user = $request->get_param('herlan_current_user');

        return $user instanceof WP_User ? $user : null;
    }

    /**
     * Casts a value to float, stripping thousands-separator commas first
     * (the loyalty API returns amounts like "273,600" as strings).
     */
    protected static function to_float($value): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
