<?php

namespace HerlanRestApi\Support;

use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Sends the customer's browser to a mobile app deep link instead of
 * WooCommerce's themed thank-you/fail pages, but only for orders placed
 * through the Herlan REST API (i.e. from the mobile app). Orders placed
 * through the normal web checkout are untouched and keep the themed pages,
 * since they're never created_via 'herlan-rest-api'.
 *
 * Intercepts both legs of a gateway's return flow:
 *  - success: WooCommerce's order-received (thank-you) page
 *  - fail/risk: the gateway's configured "fail" page — SSLCommerz posts
 *    tran_id/val_id back to it, where tran_id is the WooCommerce order ID
 *
 * The status embedded in the deep link is only a hint for the app's initial
 * screen. The app must still call GET /payments/status/{id} to confirm the
 * authoritative status before treating the order as paid.
 */
final class PaymentReturnRedirect
{
    private const CREATED_VIA = 'herlan-rest-api';
    private const DEFAULT_DEEPLINK = 'herlanlive://payment-result';

    public function boot(): void
    {
        add_action('template_redirect', [$this, 'maybe_redirect']);
    }

    public function maybe_redirect(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $order = $this->resolve_order();

        if (! $order instanceof WC_Order || $order->get_created_via() !== self::CREATED_VIA) {
            return;
        }

        wp_redirect($this->deeplink_url($order));
        exit;
    }

    private function resolve_order(): ?WC_Order
    {
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return $this->resolve_order_received();
        }

        return $this->resolve_gateway_fail();
    }

    /**
     * Success leg. Validates the order key the same way WooCommerce's own
     * thank-you template does, before trusting the order.
     */
    private function resolve_order_received(): ?WC_Order
    {
        $order_id = absint(get_query_var('order-received'));
        if (! $order_id) {
            return null;
        }

        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order) {
            return null;
        }

        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        if ($key === '' || ! hash_equals($order->get_order_key(), $key)) {
            return null;
        }

        return $order;
    }

    /**
     * Fail/risk leg. SSLCommerz (and similarly-built gateways) post tran_id +
     * val_id back to the configured fail page; tran_id is the WC order ID.
     */
    private function resolve_gateway_fail(): ?WC_Order
    {
        if (empty($_REQUEST['tran_id']) || empty($_REQUEST['val_id'])) {
            return null;
        }

        $order_id = absint(wp_unslash($_REQUEST['tran_id']));
        if (! $order_id) {
            return null;
        }

        $order = wc_get_order($order_id);

        return $order instanceof WC_Order ? $order : null;
    }

    private function deeplink_url(WC_Order $order): string
    {
        $status = $order->is_paid()
            ? 'success'
            : ($order->has_status(['failed', 'cancelled']) ? 'failed' : 'pending');

        $base = defined('HERLAN_APP_PAYMENT_DEEPLINK') ? HERLAN_APP_PAYMENT_DEEPLINK : self::DEFAULT_DEEPLINK;

        /**
         * Filters the base deep-link URL (scheme + host/path, no query string)
         * the app receives after a payment attempt. Override this if the
         * mobile app's registered scheme differs from the default.
         */
        $base = (string) apply_filters('herlan_rest_api_payment_deeplink', $base, $order);

        return add_query_arg(
            [
                'order_id' => $order->get_id(),
                'status'   => $status,
            ],
            $base
        );
    }
}
