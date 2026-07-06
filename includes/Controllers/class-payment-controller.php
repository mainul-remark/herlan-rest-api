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

        register_rest_route($this->namespace, '/payments/verify/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'verify'],
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

    /**
     * Fallback confirmation for SSLCommerz orders whose browser return-leg
     * (?wc-api=WC_sslcommerz) never fires — e.g. a mobile WebView that fails
     * to complete SSLCommerz's auto-submit POST back. Unlike that callback,
     * this doesn't need val_id: it asks SSLCommerz directly for the status
     * of our tran_id (the order ID), so the app can trigger it any time
     * after the payment attempt regardless of what the WebView did.
     *
     * Order status is otherwise still finalized normally by the IPN
     * (sslcommerz_ipn.php) or the wc-api return leg when those do fire —
     * this is a backup path, not a replacement for either.
     */
    public function verify(WP_REST_Request $request)
    {
        $order = wc_get_order(absint($request->get_param('id')));
        if (! $order instanceof WC_Order) {
            return new WP_Error('herlan_order_not_found', __('Order not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        $auth = $this->authorize_order_access($order, $request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        if ($order->get_payment_method() !== 'sslcommerz') {
            return new WP_Error(
                'herlan_not_sslcommerz_order',
                __('This order was not paid via SSLCommerz.', 'herlan-rest-api'),
                ['status' => 422]
            );
        }

        // Already resolved (by the wc-api leg or the IPN) — nothing to check.
        if (! $order->has_status(['processing', 'completed'])) {
            $result = $this->verify_with_sslcommerz($order);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        return Response::success($this->format_payment_status($order));
    }

    /**
     * Queries SSLCommerz's tran_id-based Order Validation API and applies
     * the same status transitions as check_sslcommerz_response()/the IPN
     * handler in the SSLCommerz plugin. Mutates and saves $order in place.
     *
     * @return true|WP_Error
     */
    private function verify_with_sslcommerz(WC_Order $order)
    {
        $settings = get_option('woocommerce_sslcommerz_settings');
        if (empty($settings['store_id']) || empty($settings['store_password'])) {
            return new WP_Error(
                'herlan_sslcommerz_not_configured',
                __('SSLCommerz is not configured on this site.', 'herlan-rest-api'),
                ['status' => 500]
            );
        }

        $host = ($settings['testmode'] ?? 'no') === 'yes'
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com';

        $url = add_query_arg(
            [
                'tran_id'      => $order->get_id(),
                'store_id'     => $settings['store_id'],
                'store_passwd' => $settings['store_password'],
                'v'            => 1,
                'format'       => 'json',
            ],
            $host . '/validator/api/merchantTransIDvalidationAPI.php'
        );

        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error(
                'herlan_sslcommerz_unreachable',
                __('Could not reach SSLCommerz to verify this payment. Please try again shortly.', 'herlan-rest-api'),
                ['status' => 502]
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $transactions = is_array($body['element'] ?? null) ? $body['element'] : [];

        // Prefer a transaction whose amount actually matches the order — SSLCommerz
        // can return multiple attempts under the same tran_id if the customer retried.
        $match = null;
        foreach ($transactions as $transaction) {
            $amount_matches = abs(self::to_float($transaction['currency_amount'] ?? 0) - (float) $order->get_total()) < 0.01;
            if ($amount_matches && in_array($transaction['status'] ?? '', ['VALID', 'VALIDATED'], true)) {
                $match = $transaction;
                break;
            }
        }

        if (! $match) {
            // Nothing valid found yet — could still be genuinely pending, or failed/cancelled.
            $failed = false;
            foreach ($transactions as $transaction) {
                if (in_array($transaction['status'] ?? '', ['FAILED', 'CANCELLED', 'UNATTEMPTED', 'EXPIRED'], true)) {
                    $failed = true;
                    break;
                }
            }

            if ($failed && $order->has_status(['pending', 'on-hold'])) {
                $order->update_status('failed', __('Marked failed via mobile-app payment verification (SSLCommerz reported no valid transaction).', 'herlan-rest-api'));
            }

            return true;
        }

        $risk_level = (int) ($match['risk_level'] ?? 0);
        $note = sprintf(
            "Verified via mobile-app payment verification endpoint.\nPayment Status = %s\nBank txnid = %s\nRisk Level = %s",
            $match['status'] ?? '',
            $match['bank_tran_id'] ?? '',
            $match['risk_level'] ?? ''
        );

        if ($risk_level === 0) {
            if (! $order->is_paid()) {
                $order->update_status('processing');
                $order->payment_complete((string) ($match['bank_tran_id'] ?? ''));
                $order->add_order_note($note);
            }
        } elseif ($order->has_status(['pending'])) {
            $order->update_status('on-hold', $note);
        }

        return true;
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
