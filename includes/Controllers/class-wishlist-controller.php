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

final class WishlistController extends Controller
{
    private const ALLOWED_ORDER_BY = ['date', 'price', 'product_id', 'quantity', 'id'];

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/wishlist', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'index'],
                'permission_callback' => [$this, 'can_access'],
                'args'                => [
                    'page'     => ['required' => false, 'type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page' => ['required' => false, 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50],
                    'order'    => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => 'desc',
                        'enum'              => ['asc', 'desc'],
                        'sanitize_callback' => static fn ($v) => strtolower((string) $v),
                    ],
                    'order_by' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => 'date',
                        'enum'              => self::ALLOWED_ORDER_BY,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'add'],
                'permission_callback' => [$this, 'can_access'],
                'args'                => [
                    'product_id'   => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                    'variation_id' => ['required' => false, 'type' => 'integer', 'default' => 0],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/wishlist/(?P<product_id>\d+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'remove'],
            'permission_callback' => [$this, 'can_access'],
            'args'                => [
                'product_id'   => [
                    'required'          => true,
                    'validate_callback' => static fn ($v) => is_numeric($v) && absint($v) > 0,
                ],
                'variation_id' => ['required' => false, 'type' => 'integer', 'default' => 0],
            ],
        ]);
    }

    public function index(WP_REST_Request $request)
    {
        if (! $this->wishlist_available()) {
            return new WP_Error('herlan_wishlist_unavailable', __('Wishlist plugin is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $user     = $this->current_user($request);
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = min(50, max(1, (int) $request->get_param('per_page')));
        $order    = strtoupper((string) $request->get_param('order'));
        $order_by = in_array($request->get_param('order_by'), self::ALLOWED_ORDER_BY, true)
            ? (string) $request->get_param('order_by')
            : 'date';

        wp_set_current_user($user->ID);

        $wishlist = $this->get_user_wishlist($user->ID);

        if (! $wishlist) {
            return Response::success([
                'items'      => [],
                'pagination' => ['page' => $page, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 0],
            ]);
        }

        $wlp    = new \TInvWL_Product($wishlist);
        $total  = (int) $wlp->get_wishlist([], true);
        $offset = ($page - 1) * $per_page;

        $raw_items = $wlp->get_wishlist([
            'count'    => $per_page,
            'offset'   => $offset,
            'order'    => $order,
            'order_by' => $order_by,
        ]);

        $items = [];

        foreach ($raw_items as $item) {
            $formatted = $this->format_item($item);
            if ($formatted) {
                $items[] = $formatted;
            }
        }

        return Response::success([
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
            ],
        ]);
    }

    public function add(WP_REST_Request $request)
    {
        if (! $this->wishlist_available()) {
            return new WP_Error('herlan_wishlist_unavailable', __('Wishlist plugin is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $user         = $this->current_user($request);
        $product_id   = absint($request->get_param('product_id'));
        $variation_id = absint($request->get_param('variation_id') ?? 0);

        $product = wc_get_product($variation_id ?: $product_id);

        if (! $product instanceof WC_Product || $product->get_status() !== 'publish') {
            return new WP_Error('herlan_product_not_found', __('Product not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        wp_set_current_user($user->ID);

        $wishlist = $this->get_or_create_user_wishlist($user->ID);

        if (! $wishlist) {
            return new WP_Error('herlan_wishlist_error', __('Could not retrieve wishlist.', 'herlan-rest-api'), ['status' => 500]);
        }

        $wlp    = new \TInvWL_Product($wishlist);
        $result = $wlp->add_product([
            'product_id'   => $product_id,
            'variation_id' => $variation_id,
            'wishlist_id'  => $wishlist['ID'],
        ]);

        if (! $result) {
            return new WP_Error('herlan_wishlist_add_failed', __('Could not add product to wishlist.', 'herlan-rest-api'), ['status' => 500]);
        }

        return Response::success([], 200, __('Product added to wishlist.', 'herlan-rest-api'));
    }

    public function remove(WP_REST_Request $request)
    {
        if (! $this->wishlist_available()) {
            return new WP_Error('herlan_wishlist_unavailable', __('Wishlist plugin is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $user         = $this->current_user($request);
        $product_id   = absint($request->get_param('product_id'));
        $variation_id = absint($request->get_param('variation_id') ?? 0);

        wp_set_current_user($user->ID);

        $wishlist = $this->get_user_wishlist($user->ID);

        if (! $wishlist) {
            return new WP_Error('herlan_wishlist_not_found', __('Wishlist not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        $wlp    = new \TInvWL_Product($wishlist);
        $result = $wlp->remove_product_from_wl($wishlist['ID'], $product_id, $variation_id);

        if (! $result) {
            return new WP_Error('herlan_wishlist_remove_failed', __('Could not remove product from wishlist.', 'herlan-rest-api'), ['status' => 500]);
        }

        return Response::success([], 200, __('Product removed from wishlist.', 'herlan-rest-api'));
    }

    private function get_user_wishlist(int $user_id): ?array
    {
        $wl      = new \TInvWL_Wishlist();
        $results = $wl->get_by_user_default($user_id);

        // get_by_user_default returns an array-of-wishlists; TInvWL_Product needs a single wishlist.
        if (empty($results) || ! is_array($results)) {
            return null;
        }

        return array_shift($results);
    }

    private function get_or_create_user_wishlist(int $user_id): ?array
    {
        $wl      = new \TInvWL_Wishlist();
        $wishlist = $wl->add_user_default($user_id);

        return is_array($wishlist) ? $wishlist : null;
    }

    private function format_item(array $item): ?array
    {
        $product = $item['data'] ?? null;

        if (! $product instanceof WC_Product) {
            $pid     = absint($item['variation_id'] ?? 0) ?: absint($item['product_id'] ?? 0);
            $product = $pid ? wc_get_product($pid) : null;
        }

        if (! $product instanceof WC_Product) {
            return null;
        }

        $image_id = $product->get_image_id();

        return [
            'wishlist_item_id' => absint($item['ID'] ?? 0),
            'product_id'       => absint($item['product_id'] ?? $product->get_id()),
            'variation_id'     => absint($item['variation_id'] ?? 0) ?: null,
            'date_added'       => $item['date'] ?? null,
            'quantity'         => absint($item['quantity'] ?? 1),
            'product'          => [
                'id'            => $product->get_id(),
                'name'          => $product->get_name(),
                'slug'          => $product->get_slug(),
                'type'          => $product->get_type(),
                'permalink'     => $product->get_permalink(),
                'sku'           => $product->get_sku(),
                'price'         => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price'    => $product->get_sale_price(),
                'price_html'    => $product->get_price_html(),
                'on_sale'       => $product->is_on_sale(),
                'stock_status'  => $product->get_stock_status(),
                'in_stock'      => $product->is_in_stock(),
                'image'         => $image_id ? [
                    'id'  => $image_id,
                    'src' => wp_get_attachment_url($image_id),
                    'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                ] : null,
            ],
        ];
    }

    private function wishlist_available(): bool
    {
        return class_exists('TInvWL_Wishlist') && class_exists('TInvWL_Product');
    }
}
