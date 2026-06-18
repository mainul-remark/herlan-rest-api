<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WC_Product;
use WC_Shipping_Zone;
use WC_Shipping_Zones;
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
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/cart/add-to-cart', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'add_item'],
            'permission_callback' => '__return_true',
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
            'permission_callback' => '__return_true',
            'args'                => [
                'cart_item_key' => ['required' => true, 'type' => 'string'],
                'quantity'      => ['required' => true, 'type' => 'integer', 'minimum' => 0],
            ],
        ]);

        register_rest_route($this->namespace, '/cart/remove-item/(?P<cart_item_key>[a-f0-9]{32})', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'remove_item'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/cart/clear', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'clear_cart'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/cart/item-editor/(?P<cart_item_key>[a-f0-9]{32})', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_item_editor'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/cart/replace-item', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'replace_item'],
            'permission_callback' => '__return_true',
            'args'                => [
                'cart_item_key' => ['required' => true,  'type' => 'string'],
                'product_id'    => ['required' => true,  'type' => 'integer', 'minimum' => 1],
                'variation_id'  => ['required' => false, 'type' => 'integer', 'default' => 0],
                'quantity'      => ['required' => false, 'type' => 'integer', 'minimum' => 1],
                'variation'     => ['required' => false, 'type' => 'object',  'default' => []],
            ],
        ]);

        register_rest_route($this->namespace, '/cart/apply-coupon', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'apply_coupon'],
            'permission_callback' => '__return_true',
            'args'                => [
                'coupon_code' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($this->namespace, '/cart/remove-coupon/(?P<code>[^/]+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'remove_coupon'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/cart/herlan-cash/toggle', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'toggle_herlan_cash'],
            'permission_callback' => [$this, 'can_access'],
            'args'                => [
                'enabled' => ['required' => true, 'type' => 'boolean'],
            ],
        ]);

        register_rest_route($this->namespace, '/cart/herlan-cash/set-amount', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'set_herlan_cash_amount'],
            'permission_callback' => [$this, 'can_access'],
            'args'                => [
                'amount' => ['required' => true, 'type' => 'integer', 'minimum' => 0],
            ],
        ]);
    }

    /* ── Callbacks ─────────────────────────────────────────────────── */

    public function get_cart(WP_REST_Request $request)
    {
        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        return Response::success(['cart' => $this->format_cart()]);
    }

    public function add_item(WP_REST_Request $request)
    {
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

    public function apply_coupon(WP_REST_Request $request)
    {
        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        $code = wc_format_coupon_code((string) $request->get_param('coupon_code'));

        if ($code === '') {
            return new WP_Error('herlan_coupon_code_missing', __('Please enter a coupon code.', 'herlan-rest-api'), ['status' => 422]);
        }

        if (WC()->cart->has_discount($code)) {
            return new WP_Error('herlan_coupon_already_applied', __('This coupon is already applied.', 'herlan-rest-api'), ['status' => 422]);
        }

        wc_clear_notices();
        $applied = WC()->cart->apply_coupon($code);

        if (! $applied) {
            $notices = wc_get_notices('error');
            $message = ! empty($notices)
                ? wp_strip_all_tags($notices[0]['notice'])
                : __('This coupon code is not valid.', 'herlan-rest-api');
            wc_clear_notices();

            return new WP_Error('herlan_coupon_invalid', $message, ['status' => 422]);
        }

        wc_clear_notices();

        return Response::success(
            ['cart' => $this->format_cart()],
            200,
            __('Coupon applied.', 'herlan-rest-api')
        );
    }

    public function remove_coupon(WP_REST_Request $request)
    {
        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        $code = wc_format_coupon_code((string) $request->get_param('code'));

        if (! WC()->cart->has_discount($code)) {
            return new WP_Error('herlan_coupon_not_applied', __('This coupon is not applied to your cart.', 'herlan-rest-api'), ['status' => 404]);
        }

        WC()->cart->remove_coupon($code);

        return Response::success(
            ['cart' => $this->format_cart()],
            200,
            __('Coupon removed.', 'herlan-rest-api')
        );
    }

    public function get_item_editor(WP_REST_Request $request)
    {
        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        $key       = sanitize_text_field((string) $request->get_param('cart_item_key'));
        $cart_data = WC()->cart->get_cart();

        if (! array_key_exists($key, $cart_data)) {
            return new WP_Error('herlan_cart_item_not_found', __('Cart item not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        $cart_item    = $cart_data[$key];
        $product_id   = (int) $cart_item['product_id'];
        $variation_id = (int) ($cart_item['variation_id'] ?? 0);
        $quantity     = (int) $cart_item['quantity'];

        // Active product: variation object if present, else simple/variable product
        $active_product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);

        if (! $active_product instanceof WC_Product) {
            return new WP_Error('herlan_product_not_found', __('Product not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        // Source product for WPCLV lookups: parent variable product if cart item is a variation
        $source_product = $variation_id > 0 ? wc_get_product($product_id) : $active_product;
        if (! $source_product instanceof WC_Product) {
            $source_product = $active_product;
        }

        $image_id = $active_product->get_image_id();
        $images   = [];
        if ($image_id) {
            $images[] = [
                'id'  => (int) $image_id,
                'src' => wp_get_attachment_url($image_id),
                'alt' => (string) get_post_meta($image_id, '_wp_attachment_image_alt', true),
            ];
        }
        foreach ($active_product->get_gallery_image_ids() as $gid) {
            $images[] = [
                'id'  => (int) $gid,
                'src' => wp_get_attachment_url($gid),
                'alt' => (string) get_post_meta($gid, '_wp_attachment_image_alt', true),
            ];
        }

        $parent_variable = ($variation_id > 0 && $source_product->get_type() === 'variable')
            ? $source_product
            : null;

        return Response::success([
            'cart_item_key'   => $key,
            'quantity'        => $quantity,
            'product'         => [
                'id'         => $active_product->get_id(),
                'parent_id'  => $product_id,
                'name'       => wp_strip_all_tags($active_product->get_name()),
                'sku'        => $active_product->get_sku(),
                'price'      => wc_format_decimal(wc_get_price_to_display($active_product), 2),
                'price_html' => $active_product->get_price_html(),
                'in_stock'   => $active_product->is_in_stock(),
                'images'     => $images,
            ],
            'linked_products' => $this->get_editor_linked_products($source_product, $cart_item),
            'attributes'      => $parent_variable ? $this->get_editor_attributes($parent_variable, $cart_item) : [],
            'variations'      => $parent_variable ? $this->get_editor_variations($parent_variable) : [],
        ]);
    }

    public function replace_item(WP_REST_Request $request)
    {
        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        $key          = sanitize_text_field((string) $request->get_param('cart_item_key'));
        $product_id   = (int) $request->get_param('product_id');
        $variation_id = (int) ($request->get_param('variation_id') ?? 0);
        $variation    = (array) ($request->get_param('variation') ?? []);
        $cart_data    = WC()->cart->get_cart();

        if (! array_key_exists($key, $cart_data)) {
            return new WP_Error('herlan_cart_item_not_found', __('Cart item not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        $old_item = $cart_data[$key];
        $quantity = $request->get_param('quantity') !== null
            ? max(1, (int) $request->get_param('quantity'))
            : max(1, (int) $old_item['quantity']);

        $new_product = wc_get_product($product_id);
        if (! $new_product || ! $new_product->is_purchasable()) {
            return new WP_Error('herlan_product_not_found', __('Product not found or is not available for purchase.', 'herlan-rest-api'), ['status' => 404]);
        }

        if (! $new_product->is_in_stock()) {
            return new WP_Error('herlan_out_of_stock', __('This product is currently out of stock.', 'herlan-rest-api'), ['status' => 422]);
        }

        WC()->cart->remove_cart_item($key);

        wc_clear_notices();
        $new_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);

        if ($new_key === false) {
            // Restore original item
            WC()->cart->add_to_cart(
                (int) $old_item['product_id'],
                (int) $old_item['quantity'],
                (int) ($old_item['variation_id'] ?? 0),
                (array) ($old_item['variation'] ?? [])
            );

            $notices = wc_get_notices('error');
            $message = ! empty($notices)
                ? wp_strip_all_tags($notices[0]['notice'])
                : __('Could not replace the cart item.', 'herlan-rest-api');
            wc_clear_notices();

            return new WP_Error('herlan_cart_replace_failed', $message, ['status' => 422]);
        }

        return Response::success(
            ['cart_item_key' => $new_key, 'cart' => $this->format_cart()],
            200,
            /* translators: %s: product name */
            sprintf(__('Cart updated with "%s".', 'herlan-rest-api'), $new_product->get_name())
        );
    }

    public function toggle_herlan_cash(WP_REST_Request $request)
    {
        $user = $this->current_user($request);
        if (! $user) {
            return new WP_Error('herlan_user_missing', __('Authentication required.', 'herlan-rest-api'), ['status' => 401]);
        }

        if (! defined('HERLAN_API_BASE_URL')) {
            return new WP_Error('herlan_loyalty_unavailable', __('Loyalty program is not available.', 'herlan-rest-api'), ['status' => 503]);
        }

        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        $enabled = (bool) $request->get_param('enabled');

        WC()->session->set('herlan_cash_enabled', $enabled);

        if (! $enabled) {
            WC()->session->set('herlan_redeemed_amount', 0);

            return Response::success(
                ['cart' => $this->format_cart()],
                200,
                __('Herlan Cash disabled.', 'herlan-rest-api')
            );
        }

        $phone = (string) get_user_meta($user->ID, 'billing_phone', true);
        if (! $phone) {
            return new WP_Error('herlan_loyalty_no_phone', __('No phone number linked to this account.', 'herlan-rest-api'), ['status' => 422]);
        }

        $available_cash = $this->get_herlan_cash_balance($user->ID, $phone);
        $cart           = WC()->cart;
        $ceiling        = max(0.0, (float) $cart->get_subtotal() - (float) $cart->get_discount_total());
        $max_redeemable = (int) (floor(min($available_cash, $ceiling) / 10) * 10);

        WC()->session->set('herlan_redeemed_amount', $max_redeemable);

        return Response::success(
            ['cart' => $this->format_cart()],
            200,
            __('Herlan Cash enabled.', 'herlan-rest-api')
        );
    }

    public function set_herlan_cash_amount(WP_REST_Request $request)
    {
        $user = $this->current_user($request);
        if (! $user) {
            return new WP_Error('herlan_user_missing', __('Authentication required.', 'herlan-rest-api'), ['status' => 401]);
        }

        if (! defined('HERLAN_API_BASE_URL')) {
            return new WP_Error('herlan_loyalty_unavailable', __('Loyalty program is not available.', 'herlan-rest-api'), ['status' => 503]);
        }

        $boot = $this->boot_cart();
        if (is_wp_error($boot)) {
            return $boot;
        }

        $amount = (int) $request->get_param('amount');

        if ($amount === 0) {
            WC()->session->set('herlan_redeemed_amount', 0);
            WC()->session->set('herlan_cash_enabled', false);

            return Response::success(
                ['cart' => $this->format_cart()],
                200,
                __('Herlan Cash removed.', 'herlan-rest-api')
            );
        }

        $phone = (string) get_user_meta($user->ID, 'billing_phone', true);
        if (! $phone) {
            return new WP_Error('herlan_loyalty_no_phone', __('No phone number linked to this account.', 'herlan-rest-api'), ['status' => 422]);
        }

        $available_cash = $this->get_herlan_cash_balance($user->ID, $phone);
        $cart           = WC()->cart;
        $ceiling        = max(0.0, (float) $cart->get_subtotal() - (float) $cart->get_discount_total());
        $max_redeemable = (int) (floor(min($available_cash, $ceiling) / 10) * 10);

        if ($amount > $max_redeemable) {
            return new WP_Error(
                'herlan_cash_exceeds_limit',
                /* translators: %s: maximum redeemable amount */
                sprintf(__('Amount exceeds maximum redeemable cash of %s.', 'herlan-rest-api'), wc_format_decimal($max_redeemable, 2)),
                ['status' => 422]
            );
        }

        // Clamp to nearest step of 10.
        $clamped = (int) (floor($amount / 10) * 10);

        WC()->session->set('herlan_cash_enabled', true);
        WC()->session->set('herlan_redeemed_amount', $clamped);

        return Response::success(
            ['cart' => $this->format_cart()],
            200,
            __('Herlan Cash amount set.', 'herlan-rest-api')
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
            $product      = $item['data'] instanceof WC_Product ? $item['data'] : wc_get_product($item['product_id']);
            $is_free_gift = ! empty($item['free_gift']);

            $image = null;
            if ($product) {
                $image_id = $product->get_image_id();
                $image    = $image_id
                    ? wp_get_attachment_image_url($image_id, 'thumbnail')
                    : wc_placeholder_img_src('thumbnail');
                $image    = $image ?: null;
            }

            $items[] = [
                'key'                    => $key,
                'product_id'             => (int) $item['product_id'],
                'variation_id'           => (int) ($item['variation_id'] ?? 0),
                'name'                   => $product ? wp_strip_all_tags($product->get_name()) : '',
                'sku'                    => $product ? $product->get_sku() : '',
                'quantity'               => (int) $item['quantity'],
                'price'                  => $is_free_gift ? '0.00' : ($product ? wc_format_decimal(wc_get_price_to_display($product), 2) : '0.00'),
                'subtotal'               => $is_free_gift ? '0.00' : wc_format_decimal($item['line_subtotal'] ?? 0, 2),
                'image'                  => $image,
                'is_free_gift'           => $is_free_gift,
                'is_editable'            => $this->item_is_editable($item),
                'selected_color'         => $this->get_item_color_swatch($item),
                'selected_attribute_name' => $this->get_item_attribute_name($item),
            ];
        }

        $cart->calculate_totals();

        return [
            'items'         => $items,
            'item_count'    => $cart->get_cart_contents_count(),
            'subtotal'      => wc_format_decimal($cart->get_subtotal(), 2),
            'total'         => wc_format_decimal($cart->get_total('edit'), 2),
            'currency'      => get_woocommerce_currency(),
            'bogo'          => $this->get_bogo_info($cart),
            'free_shipping' => $this->get_free_shipping_info($cart),
            'herlan_cash'   => $this->get_herlan_cash_info($cart),
            'coupons'       => $this->get_applied_coupons($cart),
        ];
    }

    private function get_applied_coupons($cart): array
    {
        $coupons = [];

        foreach ($cart->get_applied_coupons() as $code) {
            $coupon = new \WC_Coupon($code);

            $coupons[] = [
                'code'          => $code,
                'discount'      => wc_format_decimal($cart->get_coupon_discount_amount($code), 2),
                'free_shipping' => $coupon->get_free_shipping(),
            ];
        }

        return $coupons;
    }

    private function get_bogo_info($cart): array
    {
        $free_product_1_id = (int) get_option('cft_free_product_1_id', 0);
        $free_product_2_id = (int) get_option('cft_free_product_2_id', 0);

        if (! $free_product_1_id && ! $free_product_2_id) {
            return ['enabled' => false];
        }

        $tier1_threshold = 500.0;
        $tier2_threshold = 1000.0;

        // Subtotal excluding free-gift items and bundle children (mirrors BOGO plugin logic)
        $subtotal = 0.0;
        foreach ($cart->get_cart() as $item) {
            if (! empty($item['free_gift'])) {
                continue;
            }
            if (! empty($item['bundled_by']) || ! empty($item['woosb_parent_id'])) {
                continue;
            }
            $subtotal += floatval($item['data']->get_price()) * intval($item['quantity']);
        }

        $current_tier    = 0;
        $current_gift_id = 0;
        $next_tier       = 0;
        $next_gift_id    = 0;
        $remaining       = 0.0;

        if ($subtotal >= $tier2_threshold && $free_product_2_id > 0) {
            $current_tier    = 2;
            $current_gift_id = $free_product_2_id;
        } elseif ($subtotal >= $tier1_threshold && $free_product_1_id > 0) {
            $current_tier    = 1;
            $current_gift_id = $free_product_1_id;
            if ($free_product_2_id > 0) {
                $next_tier    = 2;
                $next_gift_id = $free_product_2_id;
                $remaining    = max(0.0, $tier2_threshold - $subtotal);
            }
        } else {
            if ($free_product_1_id > 0) {
                $next_tier    = 1;
                $next_gift_id = $free_product_1_id;
                $remaining    = max(0.0, $tier1_threshold - $subtotal);
            }
        }

        $tiers = [];
        if ($free_product_1_id > 0) {
            $tiers[] = [
                'tier'      => 1,
                'threshold' => wc_format_decimal($tier1_threshold, 2),
                'product_id' => $free_product_1_id,
                'qualified' => $subtotal >= $tier1_threshold,
            ];
        }
        if ($free_product_2_id > 0) {
            $tiers[] = [
                'tier'      => 2,
                'threshold' => wc_format_decimal($tier2_threshold, 2),
                'product_id' => $free_product_2_id,
                'qualified' => $subtotal >= $tier2_threshold,
            ];
        }

        return [
            'enabled'                => true,
            'cart_subtotal'          => wc_format_decimal($subtotal, 2),
            'current_tier'           => $current_tier,
            'current_gift_id'        => $current_gift_id,
            'next_tier'              => $next_tier,
            'next_gift_id'           => $next_gift_id,
            'remaining_for_next_tier' => wc_format_decimal($remaining, 2),
            'tiers'                  => $tiers,
        ];
    }

    private function get_herlan_cash_info($cart): array
    {
        if (! defined('HERLAN_API_BASE_URL') || ! WC()->session) {
            return ['available' => false];
        }

        $user_id = get_current_user_id();
        if (! $user_id) {
            return ['available' => false];
        }

        $phone = (string) get_user_meta($user_id, 'billing_phone', true);
        if (! $phone) {
            return ['available' => false];
        }

        $available_cash  = (float) (WC()->session->get('herlan_cached_available_cash_v2') ?? 0);
        $cache_expiry    = WC()->session->get('herlan_cached_cash_expiry_v2');
        $cache_valid     = $cache_expiry && time() < (int) $cache_expiry;

        if (! $cache_valid) {
            $available_cash = $this->get_herlan_cash_balance($user_id, $phone);
        }

        $enabled         = (bool) WC()->session->get('herlan_cash_enabled', false);
        $redeemed_amount = (int) WC()->session->get('herlan_redeemed_amount', 0);

        $ceiling        = max(0.0, (float) $cart->get_subtotal() - (float) $cart->get_discount_total());
        $max_redeemable = (int) (floor(min($available_cash, $ceiling) / 10) * 10);

        // Clamp stored amount to current max (subtotal may have changed).
        if ($redeemed_amount > $max_redeemable) {
            $redeemed_amount = $max_redeemable;
            WC()->session->set('herlan_redeemed_amount', $redeemed_amount);
        }

        $next_expiring_amount = (float) (WC()->session->get('herlan_cached_next_expiring_cash_amount_v2') ?? 0);
        $next_expiring_date   = (string) (WC()->session->get('herlan_cached_next_expiring_cash_date_v2') ?? '');

        return [
            'available'            => true,
            'available_cash'       => wc_format_decimal($available_cash, 2),
            'max_redeemable'       => wc_format_decimal($max_redeemable, 2),
            'enabled'              => $enabled,
            'redeemed_amount'      => wc_format_decimal($redeemed_amount, 2),
            'next_expiring_amount' => wc_format_decimal($next_expiring_amount, 2),
            'next_expiring_date'   => $next_expiring_date,
        ];
    }

    /**
     * Fetch Herlan Cash balance for a user, using WC session cache when available.
     * Falls back to calling the loyalty API directly.
     */
    private function get_herlan_cash_balance(int $user_id, string $phone): float
    {
        // Try the transient cache set by LoyaltyController first.
        $transient = get_transient('herlan_mobile_loyalty_' . $user_id);
        if (is_array($transient) && isset($transient['available_cash'])) {
            return (float) $transient['available_cash'];
        }

        // Call the loyalty API: login then get summary.
        $login = wp_remote_post(HERLAN_API_BASE_URL . 'login', [
            'body'        => wp_json_encode(['phone' => $phone]),
            'headers'     => ['Content-Type' => 'application/json'],
            'timeout'     => 10,
            'data_format' => 'body',
        ]);

        if (is_wp_error($login)) {
            return 0.0;
        }

        $login_data = json_decode(wp_remote_retrieve_body($login), true);
        if (! is_array($login_data) || empty($login_data['data']['access_token'])) {
            return 0.0;
        }

        $token   = (string) $login_data['data']['access_token'];
        $summary = wp_remote_get(HERLAN_API_BASE_URL . 'summary', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($summary)) {
            return 0.0;
        }

        $summary_data = json_decode(wp_remote_retrieve_body($summary), true);
        if (! is_array($summary_data)) {
            return 0.0;
        }

        $balance = 0.0;
        foreach ([
            $summary_data['data']['summary']['total_usable_cash'] ?? null,
            $summary_data['summary']['total_usable_cash'] ?? null,
            $summary_data['data']['total_usable_cash'] ?? null,
            $summary_data['total_usable_cash'] ?? null,
        ] as $candidate) {
            if (is_numeric($candidate)) {
                $balance = (float) $candidate;
                break;
            }
        }

        $next_expiring_amount = 0.0;
        $next_expiring_date   = '';
        $expiring_node        = $summary_data['data']['summary']['next_expiring_cash']
            ?? $summary_data['summary']['next_expiring_cash']
            ?? $summary_data['data']['next_expiring_cash']
            ?? $summary_data['next_expiring_cash']
            ?? [];

        if (is_array($expiring_node)) {
            $next_expiring_amount = (float) ($expiring_node['amount'] ?? $expiring_node['cash_amount'] ?? 0);
            $next_expiring_date   = (string) (
                $expiring_node['expires_at']
                ?? $expiring_node['expiration_date']
                ?? $expiring_node['expire_date']
                ?? $expiring_node['expiry_date']
                ?? $expiring_node['expires_on']
                ?? ''
            );
        }

        // Populate the session cache so subsequent calls within the same request are free.
        if (WC()->session) {
            WC()->session->set('herlan_cached_available_cash_v2', $balance);
            WC()->session->set('herlan_cached_next_expiring_cash_amount_v2', $next_expiring_amount);
            WC()->session->set('herlan_cached_next_expiring_cash_date_v2', $next_expiring_date);
            WC()->session->set('herlan_cached_cash_expiry_v2', time() + 120);
        }

        return $balance;
    }

    private function get_free_shipping_info($cart): array
    {
        $min_amount = null;
        $requires   = null;

        $all_zones = WC_Shipping_Zones::get_zones();
        $all_zones[] = ['shipping_methods' => (new WC_Shipping_Zone(0))->get_shipping_methods()];

        foreach ($all_zones as $zone_data) {
            foreach ($zone_data['shipping_methods'] as $method) {
                if ($method->id !== 'free_shipping' || $method->enabled !== 'yes') {
                    continue;
                }
                $threshold = floatval($method->min_amount);
                if ($threshold > 0 && ($min_amount === null || $threshold < $min_amount)) {
                    $min_amount = $threshold;
                    $requires   = $method->requires;
                }
            }
        }

        if ($min_amount === null) {
            return ['available' => false];
        }

        $cart_subtotal = floatval($cart->get_subtotal());
        $remaining     = max(0.0, $min_amount - $cart_subtotal);

        return [
            'available'  => true,
            'min_amount' => wc_format_decimal($min_amount, 2),
            'requires'   => $requires,
            'remaining'  => wc_format_decimal($remaining, 2),
            'qualified'  => $remaining === 0.0,
        ];
    }

    private function item_is_editable(array $item): bool
    {
        // $item['product_id'] is always the parent/simple product ID (even for variations)
        $source = wc_get_product((int) $item['product_id']);

        if (! $source instanceof WC_Product) {
            return false;
        }

        if ($source->get_type() === 'variable') {
            return true;
        }

        if (class_exists('WPCleverWpclv')) {
            $link_data = \WPCleverWpclv::get_linked_data($source, 'cart');
            if (! empty($link_data) && ! empty(\WPCleverWpclv::get_linked_products($link_data, 'cart'))) {
                return true;
            }
        }

        return false;
    }

    private function get_editor_linked_products(WC_Product $source_product, array $cart_item): array
    {
        if (! class_exists('WPCleverWpclv')) {
            return [];
        }

        $current_product_id = (int) $cart_item['product_id'];
        $link_data          = \WPCleverWpclv::get_linked_data($source_product, 'cart');

        if (empty($link_data)) {
            return [];
        }

        $link_product_ids  = \WPCleverWpclv::get_linked_products($link_data, 'cart');
        $link_product_ids  = apply_filters('wpclv_linked_products', array_map('absint', $link_product_ids), $source_product->get_id());
        $link_attributes   = $link_data['attributes'] ?? [];
        $link_images       = $link_data['images'] ?? [];
        $link_swatches     = $link_data['swatches'] ?? [];
        $link_dropdown     = $link_data['dropdown'] ?? [];
        $link_attr_ids     = array_map(static fn ($v) => (int) filter_var((string) $v, FILTER_SANITIZE_NUMBER_INT), $link_attributes);

        $product_attributes = [];
        foreach (array_keys($source_product->get_attributes()) as $attr) {
            $product_attributes[$attr] = wc_get_product_terms($source_product->get_id(), $attr, ['fields' => 'ids']);
        }

        $result = [];

        foreach ($link_attributes as $link_attribute) {
            $attribute_id = (int) filter_var((string) $link_attribute, FILTER_SANITIZE_NUMBER_INT);
            $attribute    = wc_get_attribute($attribute_id);

            if (! $attribute) {
                continue;
            }

            $terms         = get_terms(['taxonomy' => $attribute->slug, 'hide_empty' => false]);
            $current_terms = wc_get_product_terms($current_product_id, $attribute->slug, ['fields' => 'slugs']);

            if (empty($terms) || empty($current_terms) || is_wp_error($terms)) {
                continue;
            }

            $display  = $this->editor_linked_attribute_display($link_attribute, $link_images, $link_swatches, $link_dropdown);
            $used_ids = [];
            $items    = [];

            foreach ($terms as $term) {
                if (! $term instanceof \WP_Term) {
                    continue;
                }

                $is_active = in_array($term->slug, $current_terms, true);
                $linked_id = $is_active
                    ? $current_product_id
                    : $this->editor_linked_product_id_for_term($term, $product_attributes, $link_attr_ids, $link_product_ids, $used_ids);

                if (! $linked_id && \WPCleverWpclv::get_setting('hide_empty', 'yes') !== 'no') {
                    continue;
                }

                if ($linked_id && ! $is_active) {
                    $used_ids[] = $linked_id;
                }

                $linked_product = $linked_id ? wc_get_product($linked_id) : null;
                $img_id         = $linked_product instanceof WC_Product ? $linked_product->get_image_id() : 0;
                $color_val      = (string) get_term_meta($term->term_id, 'wpcvs_color', true);

                $items[] = [
                    'product_id' => $linked_id ?: null,
                    'term_id'    => (int) $term->term_id,
                    'name'       => $term->name,
                    'slug'       => $term->slug,
                    'is_active'  => $is_active,
                    'in_stock'   => $linked_product instanceof WC_Product ? $linked_product->is_in_stock() : false,
                    'price'      => $linked_product instanceof WC_Product ? wc_format_decimal($linked_product->get_price(), 2) : null,
                    'image'      => $img_id ? wp_get_attachment_image_url($img_id, 'woocommerce_thumbnail') : null,
                    'color'      => $color_val !== '' ? $color_val : null,
                ];
            }

            if (! empty($items)) {
                $result[] = [
                    'attribute_id'   => $attribute_id,
                    'attribute_name' => $attribute->name,
                    'attribute_slug' => $attribute->slug,
                    'label'          => wc_attribute_label($attribute->name),
                    'display'        => $display,
                    'terms'          => $items,
                ];
            }
        }

        return $result;
    }

    private function editor_linked_attribute_display(string $link_attribute, array $images, array $swatches, array $dropdown): string
    {
        if (in_array($link_attribute, $images, true)) {
            return 'image';
        }
        if (in_array($link_attribute, $swatches, true) && class_exists('WPCleverWpcvs')) {
            return 'swatches';
        }
        if (in_array($link_attribute, $dropdown, true)) {
            return 'dropdown';
        }
        return 'button';
    }

    private function editor_linked_product_id_for_term(\WP_Term $term, array $product_attributes, array $link_attr_ids, array $link_product_ids, array $used_ids): int
    {
        $tax_query  = [];
        $term_query = ['taxonomy' => $term->taxonomy, 'term' => $term->slug];

        foreach ($product_attributes as $attr_key => $attr_value) {
            $attr_id = wc_attribute_taxonomy_id_by_name($attr_key);
            if (! in_array($attr_id, $link_attr_ids, true) || $term->taxonomy === $attr_key) {
                continue;
            }
            $tax_query[] = ['taxonomy' => $attr_key, 'term' => $attr_value];
        }

        $tax_query[] = $term_query;
        $linked_id   = \WPCleverWpclv::get_linked_product_id($tax_query, $link_product_ids, $used_ids);

        if (! $linked_id && apply_filters('wpclv_get_imperfect_product', true)) {
            $linked_id = \WPCleverWpclv::get_linked_product_id([$term_query], $link_product_ids, $used_ids);
        }

        return absint($linked_id);
    }

    private function get_editor_attributes(WC_Product $parent, array $cart_item): array
    {
        if (! $parent instanceof \WC_Product_Variable) {
            return [];
        }

        $selected_attrs = [];
        $variation_id   = (int) ($cart_item['variation_id'] ?? 0);

        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if ($variation instanceof \WC_Product_Variation) {
                foreach ($variation->get_variation_attributes() as $key => $value) {
                    $selected_attrs[str_replace('attribute_', '', $key)] = $value;
                }
            }
        }

        $result = [];

        foreach ($parent->get_variation_attributes() as $attr_name => $options) {
            $taxonomy    = wc_attribute_taxonomy_name($attr_name);
            $is_taxonomy = taxonomy_exists($taxonomy);
            $attr_key    = $is_taxonomy ? $taxonomy : sanitize_title($attr_name);
            $selected    = $selected_attrs[$attr_key] ?? $selected_attrs[sanitize_title($attr_name)] ?? '';

            $option_items = [];
            foreach ($options as $option) {
                $term  = $is_taxonomy ? get_term_by('name', $option, $taxonomy) : null;
                $slug  = $term instanceof \WP_Term ? $term->slug : sanitize_title($option);
                $label = $term instanceof \WP_Term ? $term->name : $option;
                $color = null;
                $image = null;

                if ($term instanceof \WP_Term) {
                    $color_val = (string) get_term_meta($term->term_id, 'wpcvs_color', true);
                    $color     = $color_val !== '' ? $color_val : null;
                    $img_id    = absint(get_term_meta($term->term_id, 'wpcvs_image', true));
                    $image     = $img_id ? wp_get_attachment_image_url($img_id, 'woocommerce_thumbnail') : null;
                }

                $option_items[] = [
                    'value'       => $slug,
                    'label'       => $label,
                    'is_selected' => $selected === $slug,
                    'color'       => $color,
                    'image'       => $image,
                ];
            }

            $result[] = [
                'name'    => $attr_name,
                'label'   => wc_attribute_label($attr_name),
                'options' => $option_items,
            ];
        }

        return $result;
    }

    private function get_editor_variations(WC_Product $parent): array
    {
        if (! $parent instanceof \WC_Product_Variable) {
            return [];
        }

        $result = [];

        foreach ($parent->get_available_variations() as $vdata) {
            $variation_id = (int) ($vdata['variation_id'] ?? 0);
            if (! $variation_id) {
                continue;
            }

            $variation = wc_get_product($variation_id);
            $image     = null;

            if ($variation instanceof WC_Product) {
                $img_id = $variation->get_image_id() ?: $parent->get_image_id();
                $image  = $img_id ? wp_get_attachment_image_url($img_id, 'woocommerce_thumbnail') : null;
            }

            $result[] = [
                'variation_id' => $variation_id,
                'sku'          => $variation instanceof WC_Product ? $variation->get_sku() : '',
                'price'        => $variation instanceof WC_Product ? wc_format_decimal(wc_get_price_to_display($variation), 2) : null,
                'price_html'   => $variation instanceof WC_Product ? $variation->get_price_html() : '',
                'in_stock'     => (bool) ($vdata['is_in_stock'] ?? false),
                'attributes'   => $vdata['attributes'] ?? [],
                'image'        => $image,
            ];
        }

        return $result;
    }

    /* ── Color swatch helpers (mirrors selective-checkout-plugin logic) ── */

    private function get_item_color_swatch(array $item): ?string
    {
        $variation = isset($item['variation']) && is_array($item['variation']) ? $item['variation'] : [];

        // WC variable products: read directly from the stored variation attributes.
        $color = $this->color_from_variation_attrs($variation, true);
        if ($color !== '') {
            return $color;
        }

        $color = $this->color_from_variation_attrs($variation, false);
        if ($color !== '') {
            return $color;
        }

        // WPClv simple products: use the linked-data attribute list to find the exact
        // attribute rather than guessing from attribute name keywords.
        if (class_exists('WPCleverWpclv')) {
            $source    = wc_get_product((int) $item['product_id']);
            $link_data = $source instanceof WC_Product ? \WPCleverWpclv::get_linked_data($source, 'cart') : [];

            foreach ((array) ($link_data['attributes'] ?? []) as $link_attribute) {
                $attribute_id = (int) filter_var((string) $link_attribute, FILTER_SANITIZE_NUMBER_INT);
                $attribute    = $attribute_id ? wc_get_attribute($attribute_id) : null;

                if (! $attribute) {
                    continue;
                }

                $terms = wc_get_product_terms((int) $item['product_id'], $attribute->slug);
                if (empty($terms) || is_wp_error($terms)) {
                    continue;
                }

                $term  = reset($terms);
                $color = $term instanceof \WP_Term ? (string) get_term_meta($term->term_id, 'wpcvs_color', true) : '';
                if ($color !== '') {
                    return $color;
                }
            }
        }

        // Generic fallback: scan product terms by attribute name keywords.
        $source = $this->item_source_product($item);
        if ($source instanceof WC_Product) {
            $color = $this->color_from_product_terms($source, true);
            if ($color !== '') {
                return $color;
            }

            $color = $this->color_from_product_terms($source, false);
            if ($color !== '') {
                return $color;
            }
        }

        return null;
    }

    private function get_item_attribute_name(array $item): ?string
    {
        $variation = isset($item['variation']) && is_array($item['variation']) ? $item['variation'] : [];

        // For WooCommerce variations: resolve from the selected variation attribute.
        foreach ($variation as $attr_key => $attr_value) {
            $attr_key   = (string) $attr_key;
            $attr_value = (string) $attr_value;

            if (
                stripos($attr_key, 'color') === false
                && stripos($attr_key, 'colour') === false
                && stripos($attr_key, 'shade') === false
            ) {
                continue;
            }

            $taxonomy = preg_replace('/^attribute_/', '', $attr_key);
            if (! taxonomy_exists($taxonomy)) {
                continue;
            }

            $term = get_term_by('slug', $attr_value, $taxonomy)
                ?: get_term_by('name', $attr_value, $taxonomy);

            if ($term instanceof \WP_Term) {
                return $this->normalize_term_name($term->name);
            }
        }

        // WPClv simple products: use the linked-data attribute list to find the exact attribute.
        if (class_exists('WPCleverWpclv')) {
            $source    = wc_get_product((int) $item['product_id']);
            $link_data = $source instanceof WC_Product ? \WPCleverWpclv::get_linked_data($source, 'cart') : [];

            foreach ((array) ($link_data['attributes'] ?? []) as $link_attribute) {
                $attribute_id = (int) filter_var((string) $link_attribute, FILTER_SANITIZE_NUMBER_INT);
                $attribute    = $attribute_id ? wc_get_attribute($attribute_id) : null;

                if (! $attribute) {
                    continue;
                }

                $terms = wc_get_product_terms((int) $item['product_id'], $attribute->slug);
                if (empty($terms) || is_wp_error($terms)) {
                    continue;
                }

                $term = reset($terms);
                if ($term instanceof \WP_Term) {
                    return $this->normalize_term_name($term->name);
                }
            }
        }

        // Generic fallback for WPClv or other simple products: scan attribute name keywords.
        $source = $this->item_source_product($item);
        if (! $source instanceof WC_Product) {
            return null;
        }

        foreach ($source->get_attributes() as $attribute) {
            if (! $attribute instanceof \WC_Product_Attribute || ! $attribute->is_taxonomy()) {
                continue;
            }

            $taxonomy = $attribute->get_name();
            if (
                stripos($taxonomy, 'color') === false
                && stripos($taxonomy, 'colour') === false
                && stripos($taxonomy, 'shade') === false
            ) {
                continue;
            }

            $terms = wc_get_product_terms($source->get_id(), $taxonomy);
            if (empty($terms) || is_wp_error($terms)) {
                continue;
            }

            $term = reset($terms);
            if ($term instanceof \WP_Term) {
                return $this->normalize_term_name($term->name);
            }
        }

        return null;
    }

    private function color_from_variation_attrs(array $variation, bool $color_named_only): string
    {
        foreach ($variation as $attr_key => $attr_value) {
            $attr_key   = (string) $attr_key;
            $attr_value = (string) $attr_value;

            $is_color_named = stripos($attr_key, 'color') !== false || stripos($attr_key, 'colour') !== false;
            if ($color_named_only && ! $is_color_named) {
                continue;
            }

            $taxonomy = preg_replace('/^attribute_/', '', $attr_key);
            if (! taxonomy_exists($taxonomy)) {
                continue;
            }

            $term = get_term_by('slug', $attr_value, $taxonomy)
                ?: get_term_by('name', $attr_value, $taxonomy);

            if (! $term instanceof \WP_Term) {
                continue;
            }

            $color = (string) get_term_meta($term->term_id, 'wpcvs_color', true);
            if ($color !== '') {
                return $color;
            }
        }

        return '';
    }

    private function color_from_product_terms(WC_Product $product, bool $color_named_only): string
    {
        foreach ($product->get_attributes() as $attribute) {
            if (! $attribute instanceof \WC_Product_Attribute || ! $attribute->is_taxonomy()) {
                continue;
            }

            $taxonomy       = $attribute->get_name();
            $is_color_named = stripos($taxonomy, 'color') !== false
                || stripos($taxonomy, 'colour') !== false
                || stripos($taxonomy, 'shade') !== false;

            if ($color_named_only && ! $is_color_named) {
                continue;
            }

            $terms = wc_get_product_terms($product->get_id(), $taxonomy);
            if (empty($terms) || is_wp_error($terms)) {
                continue;
            }

            $term = reset($terms);
            if (! $term instanceof \WP_Term) {
                continue;
            }

            $color = (string) get_term_meta($term->term_id, 'wpcvs_color', true);
            if ($color !== '') {
                return $color;
            }
        }

        return '';
    }

    private function item_source_product(array $item): ?WC_Product
    {
        if ($item['data'] instanceof WC_Product) {
            $product = $item['data'];
            if ($product->get_type() === 'variation') {
                $parent = wc_get_product($product->get_parent_id());
                if ($parent instanceof WC_Product) {
                    return $parent;
                }
            }
            return $product;
        }

        // Fallback: load directly. For WC variable products product_id is the parent;
        // for WPClv simple products it is the product itself.
        $product = wc_get_product((int) $item['product_id']);
        return $product instanceof WC_Product ? $product : null;
    }

    private function normalize_color(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value)) {
            return $value;
        }
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/i', $value)) {
            return $value;
        }
        if (preg_match('/^hsla?\(\s*\d{1,3}(?:deg)?\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/i', $value)) {
            return $value;
        }

        return '';
    }

    private function normalize_term_name(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+-\s+/', $name);
        if (is_array($parts) && count($parts) > 1) {
            return trim((string) end($parts));
        }

        return $name;
    }
}
