<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WP_REST_Request;
use WP_REST_Server;

if (! defined('ABSPATH')) {
    exit;
}

final class PaymentController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/payments/methods', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'methods'],
            'permission_callback' => [$this, 'can_access'],
        ]);

        register_rest_route($this->namespace, '/payments/create', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create'],
            'permission_callback' => [$this, 'can_access'],
        ]);
    }

    public function methods(WP_REST_Request $request)
    {
        return Response::success([
            'methods' => [],
        ], 200, __('No mobile payment methods have been configured yet.', 'herlan-rest-api'));
    }

    public function create(WP_REST_Request $request)
    {
        return Response::success([
            'payment' => null,
        ], 202, __('Payment gateway integration is pending.', 'herlan-rest-api'));
    }
}
