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
    private const ORDER_STATUS_LABELS = [
        'pending'           => ['label' => 'Pending Payment', 'color' => '#6b7280', 'bg' => '#f3f4f6'],
        'orderplaced'       => ['label' => 'Order Placed',    'color' => '#2563eb', 'bg' => '#eff6ff'],
        'confirmed'         => ['label' => 'Confirmed',       'color' => '#3bb54a', 'bg' => '#ecfdf5'],
        'processing'        => ['label' => 'Processing',      'color' => '#2563eb', 'bg' => '#eff6ff'],
        'on-hold'           => ['label' => 'On Hold',         'color' => '#d97706', 'bg' => '#fffbeb'],
        'completed'         => ['label' => 'Delivered',       'color' => '#059669', 'bg' => '#ecfdf5'],
        'orderreturn'       => ['label' => 'Return',          'color' => '#7c3aed', 'bg' => '#f5f3ff'],
        'ordernotreachable' => ['label' => 'Not Reachable',   'color' => '#dc2626', 'bg' => '#fef2f2'],
        'cancelled'         => ['label' => 'Cancelled',       'color' => '#dc2626', 'bg' => '#fef2f2'],
        'refunded'          => ['label' => 'Refunded',        'color' => '#7c3aed', 'bg' => '#f5f3ff'],
        'failed'            => ['label' => 'Failed',          'color' => '#dc2626', 'bg' => '#fef2f2'],
    ];

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

        register_rest_route($this->namespace, '/orders/track', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'track'],
            'permission_callback' => '__return_true',
            'args'                => [
                'order_number' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'billing_contact' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'Billing email or phone number used to verify ownership of the order.',
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

    /**
     * Public order lookup by order number + billing email/phone — no authentication
     * required. Used by the "Track Order" page for both guests and logged-in users.
     */
    public function track(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_order')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $order_number    = ltrim((string) $request->get_param('order_number'), '# ');
        $billing_contact = (string) $request->get_param('billing_contact');

        $order = wc_get_order(absint($order_number));

        if (! $order instanceof WC_Order) {
            $results = wc_get_orders(['meta_key' => '_order_number', 'meta_value' => $order_number, 'limit' => 1]);
            $order   = ! empty($results) ? $results[0] : null;
        }

        if (! $order instanceof WC_Order || ! $this->track_contact_matches($order, $billing_contact)) {
            return new WP_Error(
                'herlan_order_not_found',
                __('No order found. Please check your order number and try again.', 'herlan-rest-api'),
                ['status' => 404]
            );
        }

        return Response::success($this->format_tracking($order));
    }

    private function track_contact_matches(WC_Order $order, string $contact): bool
    {
        if ($contact === '') {
            return false;
        }

        if (strtolower($order->get_billing_email()) === strtolower($contact)) {
            return true;
        }

        $order_phone_digits = preg_replace('/\D/', '', $order->get_billing_phone());
        $contact_digits     = preg_replace('/\D/', '', $contact);

        return $order_phone_digits !== '' && $order_phone_digits === $contact_digits;
    }

    private function format_tracking(WC_Order $order): array
    {
        $status      = $order->get_status();
        $status_info = self::ORDER_STATUS_LABELS[$status] ?? ['label' => ucfirst($status), 'color' => '#6b7280', 'bg' => '#f3f4f6'];

        $tracking_number = '';
        $tracking_url    = '';

        if (function_exists('herlan_get_order_tracking_number')) {
            $tn = herlan_get_order_tracking_number($order);
            if ($tn !== (string) $order->get_order_number()) {
                $tracking_number = $tn;
            }
        }

        if ($tracking_number !== '' && function_exists('herlan_get_order_tracking_url')) {
            $tu = herlan_get_order_tracking_url($order);
            if ($tu !== home_url('/shipping-and-tracking/')) {
                $tracking_url = $tu;
            }
        }

        return [
            'id'                    => $order->get_id(),
            'number'                => $order->get_order_number(),
            'status'                => $status,
            'status_label'          => $status_info['label'],
            'status_color'          => $status_info['color'],
            'status_bg'             => $status_info['bg'],
            'order_status'          => $this->build_order_status_list($status),
            'date_created'          => $order->get_date_created()?->format('c'),
            'currency'              => $order->get_currency(),
            'subtotal'              => $order->get_subtotal(),
            'shipping_total'        => $order->get_shipping_total(),
            'discount_total'        => $order->get_discount_total(),
            'total'                 => $order->get_total(),
            'payment_method_title'  => $order->get_payment_method_title(),
            'shipping_address'      => $order->get_address('shipping'),
            'billing_address'       => $order->get_address('billing'),
            'tracking_number'       => $tracking_number,
            'tracking_url'          => $tracking_url,
            'line_items'            => $this->format_line_items($order),
        ];
    }

    private function order_summary(WC_Order $order): array
    {
        return [
            'id'                   => $order->get_id(),
            'number'               => $order->get_order_number(),
            'status'               => $order->get_status(),
            'order_status'         => $this->build_order_status_list($order->get_status()),
            'date_created'         => $order->get_date_created()?->format('c'),
            'currency'             => $order->get_currency(),
            'total'                => $order->get_total(),
            'item_count'           => $order->get_item_count(),
            'payment_method_title' => $order->get_payment_method_title(),
            'line_items' => $this->format_line_items($order),
        ];
    }

    private function format_order(WC_Order $order): array
    {
        return [
            'id'                   => $order->get_id(),
            'number'               => $order->get_order_number(),
            'status'               => $order->get_status(),
            'order_status'         => $this->build_order_status_list($order->get_status()),
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

    /**
     * Full list of order statuses with an `active` flag on the one matching
     * the given order's current status, for rendering a status stepper.
     *
     * Sourced from wc_get_order_statuses() (the same filterable list WooCommerce
     * and the theme's custom statuses feed into) rather than a hardcoded list,
     * so custom statuses like confirmed/orderplaced/orderreturn/ordernotreachable
     * are never silently missing from the response.
     */
    private function build_order_status_list(string $current_status): array
    {
        $list = [];

        foreach (wc_get_order_statuses() as $key => $label) {
            $key  = str_starts_with($key, 'wc-') ? substr($key, 3) : $key;
            $info = self::ORDER_STATUS_LABELS[$key] ?? ['label' => $label, 'color' => '#6b7280', 'bg' => '#f3f4f6'];

            $list[] = [
                'key'    => $key,
                'label'  => $info['label'],
                'color'  => $info['color'],
                'bg'     => $info['bg'],
                'active' => $key === $current_status,
            ];
        }

        return $list;
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
