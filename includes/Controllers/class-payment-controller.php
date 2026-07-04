<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WC_Order;
use WP_Error;
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

        register_rest_route($this->namespace, '/payments/status/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'status'],
            'permission_callback' => [$this, 'maybe_authenticate'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => static fn ($v) => is_numeric($v) && absint($v) > 0,
                ],
                'order_key' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
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

    /**
     * Authoritative payment-status check for the app to call after the
     * WebView payment flow ends (deep link, poll timeout, or app resume).
     * Supports both logged-in customers and guest checkout (via order_key).
     */
    public function status(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_order')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $order = wc_get_order(absint($request->get_param('id')));
        if (! $order instanceof WC_Order) {
            return new WP_Error('herlan_order_not_found', __('Order not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        $auth = $this->authorize_order_access($order, $request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        return Response::success($this->format_payment_status($order));
    }

    private function format_payment_status(WC_Order $order): array
    {
        return [
            'order_id'       => $order->get_id(),
            'status'         => $order->get_status(),
            'payment_status' => $this->simplify_status($order),
            'paid'           => $order->is_paid(),
            'transaction_id' => $order->get_transaction_id(),
            'total'          => wc_format_decimal($order->get_total(), 2),
            'currency'       => $order->get_currency(),
        ];
    }

    /**
     * Collapses WooCommerce's many order statuses into the 3 states the app
     * needs to decide which screen to show.
     */
    private function simplify_status(WC_Order $order): string
    {
        if ($order->is_paid()) {
            return 'success';
        }

        if ($order->has_status(['failed', 'cancelled'])) {
            return 'failed';
        }

        return 'pending';
    }
}
