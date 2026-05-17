<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

if (! defined('ABSPATH')) {
    exit;
}

final class CartController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/cart', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_cart'],
            'permission_callback' => [$this, 'can_access'],
        ]);

        register_rest_route($this->namespace, '/cart/add-to-cart', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'add_item'],
            'permission_callback' => [$this, 'can_access'],
            'args'                => [
                'product_id'   => ['required' => true,  'type' => 'integer', 'minimum' => 1],
                'quantity'     => ['required' => false, 'type' => 'integer', 'minimum' => 1, 'default' => 1],
                'variation_id' => ['required' => false, 'type' => 'integer', 'default' => 0],
                'variation'    => ['required' => false, 'type' => 'object',  'default' => []],
            ],
        ]);

        register_rest_route($this->namespace, '/cart/update-item', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'update_item'],
            'permission_callback' => [$this, 'can_access'],
            'args'                => [
                'cart_item_key' => ['required' => true, 'type' => 'string'],
                'quantity'      => ['required' => true, 'type' => 'integer', 'minimum' => 0],
            ],
        ]);

        register_rest_route($this->namespace, '/cart/remove-item/(?P<cart_item_key>[a-f0-9]{32})', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'remove_item'],
            'permission_callback' => [$this, 'can_access'],
        ]);

        register_rest_route($this->namespace, '/cart/clear', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'clear_cart'],
            'permission_callback' => [$this, 'can_access'],
        ]);
    }

    /* ── Callbacks ─────────────────────────────────────────────────── */

    public function get_cart(WP_REST_Request $request)
    {
        $user = $this->current_user($request);
        if (! $user) {
            return new WP_Error('herlan_user_missing', __('Authentication required.', 'herlan-rest-api'), ['status' => 401]);
        }

        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        return Response::success(['cart' => $this->format_cart()]);
    }

    public function add_item(WP_REST_Request $request)
    {
        $user = $this->current_user($request);
        if (! $user) {
            return new WP_Error('herlan_user_missing', __('Authentication required.', 'herlan-rest-api'), ['status' => 401]);
        }

        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        $product_id   = (int) $request->get_param('product_id');
        $quantity     = max(1, (int) $request->get_param('quantity'));
        $variation_id = (int) $request->get_param('variation_id');
        $variation    = (array) ($request->get_param('variation') ?? []);

        $product = wc_get_product($product_id);

        if (! $product || ! $product->is_purchasable()) {
            return new WP_Error('herlan_product_not_found', __('Product not found or is not available for purchase.', 'herlan-rest-api'), ['status' => 404]);
        }

        if (! $product->is_in_stock()) {
            return new WP_Error('herlan_out_of_stock', __('This product is currently out of stock.', 'herlan-rest-api'), ['status' => 422]);
        }

        wc_clear_notices();

        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);

        if ($cart_item_key === false) {
            $notices = wc_get_notices('error');
            $message = ! empty($notices)
                ? wp_strip_all_tags($notices[0]['notice'])
                : __('Could not add the product to your cart.', 'herlan-rest-api');
            wc_clear_notices();

            return new WP_Error('herlan_cart_add_failed', $message, ['status' => 422]);
        }

        return Response::success(
            ['cart' => $this->format_cart()],
            200,
            /* translators: %s: product name */
            sprintf(__('"%s" has been added to your cart.', 'herlan-rest-api'), $product->get_name())
        );
    }

    public function update_item(WP_REST_Request $request)
    {
        $user = $this->current_user($request);
        if (! $user) {
            return new WP_Error('herlan_user_missing', __('Authentication required.', 'herlan-rest-api'), ['status' => 401]);
        }

        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        $key      = sanitize_text_field((string) $request->get_param('cart_item_key'));
        $quantity = (int) $request->get_param('quantity');

        if (! array_key_exists($key, WC()->cart->get_cart())) {
            return new WP_Error('herlan_cart_item_not_found', __('Cart item not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        if ($quantity === 0) {
            WC()->cart->remove_cart_item($key);
        } else {
            WC()->cart->set_quantity($key, $quantity);
        }

        return Response::success(
            ['cart' => $this->format_cart()],
            200,
            __('Cart updated.', 'herlan-rest-api')
        );
    }

    public function remove_item(WP_REST_Request $request)
    {
        $user = $this->current_user($request);
        if (! $user) {
            return new WP_Error('herlan_user_missing', __('Authentication required.', 'herlan-rest-api'), ['status' => 401]);
        }

        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        $key = sanitize_text_field((string) $request->get_param('cart_item_key'));

        if (! array_key_exists($key, WC()->cart->get_cart())) {
            return new WP_Error('herlan_cart_item_not_found', __('Cart item not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        WC()->cart->remove_cart_item($key);

        return Response::success(
            ['cart' => $this->format_cart()],
            200,
            __('Item removed from cart.', 'herlan-rest-api')
        );
    }

    public function clear_cart(WP_REST_Request $request)
    {
        $user = $this->current_user($request);
        if (! $user) {
            return new WP_Error('herlan_user_missing', __('Authentication required.', 'herlan-rest-api'), ['status' => 401]);
        }

        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        WC()->cart->empty_cart();

        return Response::success(
            ['cart' => $this->format_cart()],
            200,
            __('Cart cleared.', 'herlan-rest-api')
        );
    }

    /* ── Helpers ───────────────────────────────────────────────────── */

    /**
     * Initialize WooCommerce cart/session for REST API context.
     * wc_load_cart() is the official WC function for this (WC 3.6.4+).
     *
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

    private function format_cart(): array
    {
        $cart  = WC()->cart;
        $items = [];

        foreach ($cart->get_cart() as $key => $item) {
            $product = $item['data'] instanceof WC_Product ? $item['data'] : wc_get_product($item['product_id']);

            $image = null;
            if ($product) {
                $image_id = $product->get_image_id();
                $image    = $image_id
                    ? wp_get_attachment_image_url($image_id, 'thumbnail')
                    : wc_placeholder_img_src('thumbnail');
                $image    = $image ?: null;
            }

            $items[] = [
                'key'          => $key,
                'product_id'   => (int) $item['product_id'],
                'variation_id' => (int) ($item['variation_id'] ?? 0),
                'name'         => $product ? wp_strip_all_tags($product->get_name()) : '',
                'sku'          => $product ? $product->get_sku() : '',
                'quantity'     => (int) $item['quantity'],
                'price'        => $product ? wc_format_decimal(wc_get_price_to_display($product), 2) : '0.00',
                'subtotal'     => wc_format_decimal($item['line_subtotal'] ?? 0, 2),
                'image'        => $image,
            ];
        }

        $cart->calculate_totals();

        return [
            'items'      => $items,
            'item_count' => $cart->get_cart_contents_count(),
            'subtotal'   => wc_format_decimal($cart->get_subtotal(), 2),
            'total'      => wc_format_decimal($cart->get_total('edit'), 2),
            'currency'   => get_woocommerce_currency(),
        ];
    }
}
