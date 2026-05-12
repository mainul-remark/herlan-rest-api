<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WP_REST_Server;

if (! defined('ABSPATH')) {
    exit;
}

final class AppController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'status'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function status()
    {
        return Response::success([
            'api_version' => HERLAN_REST_API_VERSION,
            'site' => get_bloginfo('name'),
            'timezone' => wp_timezone_string(),
        ]);
    }
}
