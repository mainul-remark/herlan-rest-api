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

final class CheckoutController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/checkout', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'place_order'],
            'permission_callback' => [$this, 'can_access'],
            'args'                => $this->checkout_args(),
        ]);

        register_rest_route($this->namespace, '/checkout/payment-methods', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'payment_methods'],
            'permission_callback' => [$this, 'can_access'],
        ]);

        register_rest_route($this->namespace, '/checkout', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'checkout_summary'],
            'permission_callback' => '__return_true',
            'args'                => [
                'district' => ['required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'country'  => ['required' => false, 'type' => 'string', 'default' => 'BD', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    /* ── Callbacks ─────────────────────────────────────────────────── */

    public function payment_methods(WP_REST_Request $request)
    {
        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        return Response::success(['payment_methods' => $this->get_payment_methods()]);
    }

    public function checkout_summary(WP_REST_Request $request)
    {
        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        $country  = sanitize_text_field((string) ($request->get_param('country') ?: 'BD'));
        $district = sanitize_text_field((string) ($request->get_param('district') ?? ''));

        if ($district !== '') {
            $states = WC()->countries->get_states($country);
            if (! is_array($states) || ! isset($states[$district])) {
                return new WP_Error(
                    'herlan_invalid_district',
                    __('The selected district is not valid for this country.', 'herlan-rest-api'),
                    ['status' => 422]
                );
            }
        }

        return Response::success([
            'payment_methods'  => $this->get_payment_methods(),
            'shipping_methods' => $this->get_shipping_methods($country, $district),
        ]);
    }

    public function place_order(WP_REST_Request $request)
    {
        $user = $this->current_user($request);
        if (! $user) {
            return new WP_Error('herlan_user_missing', __('Authentication required.', 'herlan-rest-api'), ['status' => 401]);
        }

        // Rate-limit: 10 checkout attempts per user per hour.
        $rate_key = 'herlan_checkout_rate_' . $user->ID;
        $attempts = (int) get_transient($rate_key);
        if ($attempts >= 10) {
            return new WP_Error(
                'herlan_rate_limit',
                __('Too many checkout attempts. Please try again in an hour.', 'herlan-rest-api'),
                ['status' => 429]
            );
        }
        set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);

        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        if (WC()->cart->is_empty()) {
            return new WP_Error('herlan_cart_empty', __('Your cart is empty.', 'herlan-rest-api'), ['status' => 422]);
        }

        // Ensure current-user context so loyalty/fee hooks see is_user_logged_in() === true.
        wp_set_current_user($user->ID);

        $payment_method     = sanitize_key((string) $request->get_param('payment_method'));
        $available_gateways = WC()->payment_gateways()->get_available_payment_gateways();

        if (! isset($available_gateways[$payment_method])) {
            return new WP_Error(
                'herlan_invalid_payment_method',
                __('The selected payment method is not available.', 'herlan-rest-api'),
                ['status' => 422]
            );
        }

        // Build and validate addresses.
        $billing        = $this->extract_address($request, 'billing');
        $ship_different = (bool) $request->get_param('ship_to_different_address');
        $shipping       = $ship_different ? $this->extract_address($request, 'shipping') : $billing;

        $validation = $this->validate_billing($billing);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $customer_note = sanitize_textarea_field((string) ($request->get_param('customer_note') ?? ''));

        // Tell WC which gateway is selected so gateway hooks read the correct value.
        WC()->session->set('chosen_payment_method', $payment_method);

        // Recalculate totals — fires woocommerce_cart_calculate_fees, which applies
        // Herlan Cash (negative fee), BOGO free gifts, Discount Rules, etc.
        WC()->cart->calculate_totals();

        $data = $this->build_checkout_data($billing, $shipping, $ship_different, $payment_method, $customer_note);

        // Prevent WC from reusing an in-progress order from a previous attempt.
        WC()->session->set('order_awaiting_payment', 0);

        $checkout = WC()->checkout();

        try {
            $order_id = $checkout->create_order($data);
        } catch (\Exception $e) {
            return new WP_Error('herlan_order_create_failed', $e->getMessage(), ['status' => 500]);
        }

        if (is_wp_error($order_id)) {
            return $order_id;
        }

        $order = wc_get_order((int) $order_id);
        if (! $order instanceof WC_Order) {
            return new WP_Error(
                'herlan_order_not_found',
                __('Order could not be retrieved after creation.', 'herlan-rest-api'),
                ['status' => 500]
            );
        }

        // Stamp order with the API customer and channel.
        $order->set_customer_id($user->ID);
        $order->set_created_via('herlan-rest-api');
        $order->save();

        // Process payment — for SSLCommerz returns ['result'=>'success','redirect'=>$pay_url].
        $gateway = $available_gateways[$payment_method];

        try {
            $result = $gateway->process_payment((int) $order_id);
        } catch (\Exception $e) {
            $order->update_status('failed', 'API payment error: ' . $e->getMessage());
            return new WP_Error('herlan_payment_failed', $e->getMessage(), ['status' => 500]);
        }

        if (! is_array($result) || ($result['result'] ?? '') !== 'success') {
            $order->update_status('failed', 'Payment gateway returned an unexpected result.');
            return new WP_Error(
                'herlan_payment_failed',
                __('Payment could not be initiated. Please try again.', 'herlan-rest-api'),
                ['status' => 502]
            );
        }

        // Mirror the hook WC native checkout fires so Herlan Loyalty redeems cash.
        do_action('woocommerce_checkout_order_processed', $order_id, $data, $order);

        WC()->cart->empty_cart();

        return Response::success(
            [
                'order_id'      => (int) $order_id,
                'order_number'  => $order->get_order_number(),
                'status'        => $order->get_status(),
                'total'         => wc_format_decimal($order->get_total(), 2),
                'currency'      => $order->get_currency(),
                'payment_url'   => $result['redirect'] ?? null,
                'thank_you_url' => $order->get_checkout_order_received_url(),
            ],
            201,
            __('Order placed successfully.', 'herlan-rest-api')
        );
    }

    /* ── Args ──────────────────────────────────────────────────────── */

    private function checkout_args(): array
    {
        $text  = ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'];
        $otext = $text + ['required' => false, 'default' => ''];
        $rtext = $text + ['required' => true];

        return [
            'payment_method'            => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_key'],
            'billing_first_name'        => $rtext,
            'billing_last_name'         => $rtext,
            'billing_email'             => ['required' => true,  'type' => 'string', 'format' => 'email'],
            'billing_phone'             => $rtext,
            'billing_address_1'         => $rtext,
            'billing_city'              => $rtext,
            'billing_address_2'         => $otext,
            'billing_state'             => $otext,
            'billing_postcode'          => $otext,
            'billing_country'           => ['required' => false, 'type' => 'string', 'default' => 'BD', 'sanitize_callback' => 'sanitize_text_field'],
            'billing_company'           => $otext,
            'ship_to_different_address' => ['required' => false, 'type' => 'boolean', 'default' => false],
            'shipping_first_name'       => $otext,
            'shipping_last_name'        => $otext,
            'shipping_address_1'        => $otext,
            'shipping_address_2'        => $otext,
            'shipping_city'             => $otext,
            'shipping_state'            => $otext,
            'shipping_postcode'         => $otext,
            'shipping_country'          => $otext,
            'shipping_company'          => $otext,
            'customer_note'             => ['required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field'],
        ];
    }

    /* ── Helpers ───────────────────────────────────────────────────── */

    private function extract_address(WP_REST_Request $request, string $prefix): array
    {
        return [
            'first_name' => (string) ($request->get_param("{$prefix}_first_name") ?? ''),
            'last_name'  => (string) ($request->get_param("{$prefix}_last_name") ?? ''),
            'email'      => $prefix === 'billing' ? sanitize_email((string) ($request->get_param('billing_email') ?? '')) : '',
            'phone'      => $prefix === 'billing' ? (string) ($request->get_param('billing_phone') ?? '') : '',
            'company'    => (string) ($request->get_param("{$prefix}_company") ?? ''),
            'address_1'  => (string) ($request->get_param("{$prefix}_address_1") ?? ''),
            'address_2'  => (string) ($request->get_param("{$prefix}_address_2") ?? ''),
            'city'       => (string) ($request->get_param("{$prefix}_city") ?? ''),
            'state'      => (string) ($request->get_param("{$prefix}_state") ?? ''),
            'postcode'   => (string) ($request->get_param("{$prefix}_postcode") ?? ''),
            'country'    => (string) ($request->get_param("{$prefix}_country") ?: 'BD'),
        ];
    }

    /**
     * @return true|WP_Error
     */
    private function validate_billing(array $billing)
    {
        $required = [
            'first_name' => __('Billing first name is required.', 'herlan-rest-api'),
            'last_name'  => __('Billing last name is required.', 'herlan-rest-api'),
            'phone'      => __('Billing phone number is required.', 'herlan-rest-api'),
            'address_1'  => __('Billing address is required.', 'herlan-rest-api'),
            'city'       => __('Billing city is required.', 'herlan-rest-api'),
        ];

        foreach ($required as $field => $message) {
            if (empty($billing[$field])) {
                return new WP_Error('herlan_missing_field', $message, ['status' => 422]);
            }
        }

        if (empty($billing['email']) || ! is_email($billing['email'])) {
            return new WP_Error(
                'herlan_invalid_email',
                __('A valid billing email address is required.', 'herlan-rest-api'),
                ['status' => 422]
            );
        }

        return true;
    }

    private function build_checkout_data(
        array $billing,
        array $shipping,
        bool $ship_different,
        string $payment_method,
        string $customer_note
    ): array {
        return [
            'billing_first_name'        => $billing['first_name'],
            'billing_last_name'         => $billing['last_name'],
            'billing_company'           => $billing['company'],
            'billing_email'             => $billing['email'],
            'billing_phone'             => $billing['phone'],
            'billing_address_1'         => $billing['address_1'],
            'billing_address_2'         => $billing['address_2'],
            'billing_city'              => $billing['city'],
            'billing_state'             => $billing['state'],
            'billing_postcode'          => $billing['postcode'],
            'billing_country'           => $billing['country'],
            'shipping_first_name'       => $shipping['first_name'],
            'shipping_last_name'        => $shipping['last_name'],
            'shipping_company'          => $shipping['company'] ?? '',
            'shipping_address_1'        => $shipping['address_1'],
            'shipping_address_2'        => $shipping['address_2'],
            'shipping_city'             => $shipping['city'],
            'shipping_state'            => $shipping['state'],
            'shipping_postcode'         => $shipping['postcode'],
            'shipping_country'          => $shipping['country'],
            'ship_to_different_address' => $ship_different ? 1 : 0,
            'payment_method'            => $payment_method,
            'order_comments'            => $customer_note,
        ];
    }

    private function get_payment_methods(): array
    {
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $methods  = [];

        foreach ($gateways as $id => $gateway) {
            $methods[] = [
                'id'          => $id,
                'title'       => wp_strip_all_tags($gateway->get_title()),
                'description' => wp_strip_all_tags($gateway->get_description()),
            ];
        }

        return $methods;
    }

    private function get_shipping_methods(string $country = '', string $district = ''): array
    {
        $cart = WC()->cart;

        if ($district === '') {
            $cart->calculate_shipping();
            $packages = WC()->shipping()->get_packages();
        } else {
            // Compute rates for an arbitrary district without touching the customer's
            // saved/session shipping address — this is a destination override, not a selection.
            $packages = $cart->get_shipping_packages();
            foreach ($packages as &$package) {
                $package['destination']['country'] = $country;
                $package['destination']['state']    = $district;
            }
            unset($package);

            $packages = WC()->shipping()->calculate_shipping($packages);
        }

        $chosen  = (array) WC()->session->get('chosen_shipping_methods', []);
        $methods = [];

        foreach ($packages as $package) {
            foreach ($package['rates'] ?? [] as $rate_id => $rate) {
                $methods[] = [
                    'id'           => $rate_id,
                    'method_id'    => $rate->get_method_id(),
                    'instance_id'  => $rate->get_instance_id(),
                    'title'        => wp_strip_all_tags($rate->get_label()),
                    'cost'         => wc_format_decimal($rate->get_cost(), 2),
                    'is_selected'  => in_array($rate_id, $chosen, true),
                ];
            }
        }

        return $methods;
    }

    /**
     * @return true|WP_Error
     */
    private function boot_cart()
    {
        if (! function_exists('WC') || ! WC()) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        if (WC()->cart === null) {
            if (! function_exists('wc_load_cart')) {
                return new WP_Error('herlan_cart_unavailable', __('Cart could not be initialized. Please update WooCommerce.', 'herlan-rest-api'), ['status' => 500]);
            }

            wc_load_cart();
        }

        return true;
    }
}
