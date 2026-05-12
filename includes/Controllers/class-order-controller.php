<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WC_Order;
use WC_Order_Item_Product;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

if (! defined('ABSPATH')) {
    exit;
}

final class OrderController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/orders', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'index'],
            'permission_callback' => [$this, 'can_access'],
            'args'                => [
                'page'     => ['required' => false, 'type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['required' => false, 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50],
                'status'   => ['required' => false, 'type' => 'string', 'default' => 'any', 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);

        register_rest_route($this->namespace, '/orders/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'show'],
            'permission_callback' => [$this, 'can_access'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => static fn ($v) => is_numeric($v) && absint($v) > 0,
                ],
            ],
        ]);
    }

    public function index(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_orders')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $user     = $this->current_user($request);
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = min(50, max(1, (int) $request->get_param('per_page')));
        $status   = (string) $request->get_param('status');

        $base_args = [
            'customer_id' => $user->ID,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ];

        if ($status !== 'any') {
            $base_args['status'] = $status;
        }

        $total_ids = wc_get_orders(array_merge($base_args, ['limit' => -1, 'return' => 'ids']));
        $total     = count($total_ids);
        $orders    = wc_get_orders(array_merge($base_args, ['limit' => $per_page, 'paged' => $page]));
        $items     = [];

        foreach ($orders as $order) {
            if ($order instanceof WC_Order) {
                $items[] = $this->order_summary($order);
            }
        }

        return Response::success([
            'orders'     => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
            ],
        ]);
    }

    public function show(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_order')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $user  = $this->current_user($request);
        $order = wc_get_order(absint($request->get_param('id')));

        if (! $order instanceof WC_Order || (int) $order->get_customer_id() !== $user->ID) {
            return new WP_Error('herlan_order_not_found', __('Order not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        return Response::success($this->format_order($order));
    }

    private function order_summary(WC_Order $order): array
    {
        return [
            'id'                   => $order->get_id(),
            'number'               => $order->get_order_number(),
            'status'               => $order->get_status(),
            'date_created'         => $order->get_date_created()?->format('c'),
            'currency'             => $order->get_currency(),
            'total'                => $order->get_total(),
            'item_count'           => $order->get_item_count(),
            'payment_method_title' => $order->get_payment_method_title(),
        ];
    }

    private function format_order(WC_Order $order): array
    {
        return [
            'id'                   => $order->get_id(),
            'number'               => $order->get_order_number(),
            'status'               => $order->get_status(),
            'date_created'         => $order->get_date_created()?->format('c'),
            'date_modified'        => $order->get_date_modified()?->format('c'),
            'currency'             => $order->get_currency(),
            'total'                => $order->get_total(),
            'subtotal'             => $order->get_subtotal(),
            'total_tax'            => $order->get_total_tax(),
            'shipping_total'       => $order->get_shipping_total(),
            'discount_total'       => $order->get_discount_total(),
            'payment_method'       => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'transaction_id'       => $order->get_transaction_id(),
            'customer_note'        => $order->get_customer_note(),
            'billing'              => $order->get_address('billing'),
            'shipping'             => $order->get_address('shipping'),
            'line_items'           => $this->format_line_items($order),
            'coupon_lines'         => $this->format_coupons($order),
        ];
    }

    private function format_line_items(WC_Order $order): array
    {
        $items = [];

        foreach ($order->get_items() as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product  = $item->get_product();
            $image_id = $product ? $product->get_image_id() : null;

            $items[] = [
                'id'           => $item->get_id(),
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id() ?: null,
                'name'         => $item->get_name(),
                'sku'          => $product ? $product->get_sku() : null,
                'quantity'     => $item->get_quantity(),
                'subtotal'     => $item->get_subtotal(),
                'total'        => $item->get_total(),
                'image'        => $image_id ? wp_get_attachment_url($image_id) : null,
            ];
        }

        return $items;
    }

    private function format_coupons(WC_Order $order): array
    {
        $coupons = [];

        foreach ($order->get_items('coupon') as $item) {
            $coupons[] = [
                'code'     => $item->get_name(),
                'discount' => $item->get_discount(),
            ];
        }

        return $coupons;
    }
}
