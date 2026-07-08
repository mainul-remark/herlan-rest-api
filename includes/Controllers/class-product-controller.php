<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WC_Product;
use WP_Comment;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WP_Term;

if (! defined('ABSPATH')) {
    exit;
}

final class ProductController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/products/filters', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'filters'],
            'permission_callback' => '__return_true',
            'args' => [
                'taxonomy' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'term' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_title',
                ],
                'include_counts' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => true,
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/products/(?P<id>\d+)/reviews', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'reviews_index'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => static fn ($v) => is_numeric($v) && absint($v) > 0,
                    ],
                    'page' => ['required' => false, 'type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page' => ['required' => false, 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_review'],
                'permission_callback' => [$this, 'can_access'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => static fn ($v) => is_numeric($v) && absint($v) > 0,
                    ],
                    'rating' => [
                        'required' => true,
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 5,
                    ],
                    'content' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/products/(?P<id>\d+)/questions', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'questions_index'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => static fn ($v) => is_numeric($v) && absint($v) > 0,
                    ],
                    'page' => ['required' => false, 'type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page' => ['required' => false, 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_question'],
                'permission_callback' => [$this, 'can_access'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => static fn ($v) => is_numeric($v) && absint($v) > 0,
                    ],
                    'question' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/products/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'show'],
            'permission_callback' => '__return_true',
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => static fn ($value) => is_numeric($value) && absint($value) > 0,
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/products/(?P<id>\d+)/bundle-items', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'bundle_items'],
            'permission_callback' => '__return_true',
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => static fn ($value) => is_numeric($value) && absint($value) > 0,
                ],
                'index' => [
                    'required' => true,
                    'type' => 'integer',
                    'minimum' => 0,
                ],
                'page' => [
                    'required' => false,
                    'default' => 1,
                    'sanitize_callback' => static fn ($value) => ($value === '' || $value === null) ? 1 : max(1, absint($value)),
                ],
                'per_page' => [
                    'required' => false,
                    'default' => 12,
                    'sanitize_callback' => static fn ($value) => ($value === '' || $value === null) ? 12 : min(50, max(1, absint($value))),
                ],
                'search' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    public function filters(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $context_taxonomy = (string) $request->get_param('taxonomy');
        $context_term = (string) $request->get_param('term');
        $include_counts = rest_sanitize_boolean($request->get_param('include_counts'));
        $base_tax_query = $this->filter_base_tax_query($context_taxonomy, $context_term);
        $cache_key = 'herlan_mobile_filters_' . md5(wp_json_encode([
            'taxonomy' => $context_taxonomy,
            'term' => $context_term,
            'include_counts' => $include_counts,
        ]));

        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return Response::success($cached);
        }

        $data = [
            'context' => [
                'taxonomy' => $context_taxonomy ?: null,
                'term' => $context_term ?: null,
            ],
            'sort_options' => $this->sort_options(),
            'price_range' => $this->price_range($base_tax_query),
            'filters' => $this->available_filters($base_tax_query, $include_counts),
        ];

        set_transient($cache_key, $data, 10 * MINUTE_IN_SECONDS);

        return Response::success($data);
    }

    public function show(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $product = wc_get_product(absint($request->get_param('id')));

        if (! $product instanceof WC_Product || $product->get_status() !== 'publish') {
            return new WP_Error('herlan_product_not_found', __('Product not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        return Response::success($this->format_product($product));
    }

    public function bundle_items(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $product = wc_get_product(absint($request->get_param('id')));

        if (! $product instanceof WC_Product || $product->get_status() !== 'publish') {
            return new WP_Error('herlan_product_not_found', __('Product not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        if (! $product->is_type('easy_product_bundle') || ! method_exists($product, 'get_item_products')) {
            return new WP_Error('herlan_product_not_bundle', __('This product does not have selectable bundle items.', 'herlan-rest-api'), ['status' => 400]);
        }

        $index = absint($request->get_param('index'));
        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(50, max(1, (int) $request->get_param('per_page')));
        $search = (string) $request->get_param('search');

        $result = $product->get_item_products([
            'index' => $index,
            'page' => $page,
            'limit' => $per_page,
            'search' => $search,
        ]);

        return Response::success([
            'items' => $result['products'] ?? [],
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => (int) ($result['total'] ?? 0),
                'total_pages' => (int) ($result['pages'] ?? 1),
            ],
        ]);
    }

    public function reviews_index(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $product_id = absint($request->get_param('id'));
        $product = wc_get_product($product_id);

        if (! $product instanceof WC_Product || $product->get_status() !== 'publish') {
            return new WP_Error('herlan_product_not_found', __('Product not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(50, max(1, (int) $request->get_param('per_page')));
        $offset = ($page - 1) * $per_page;

        $total = (int) get_comments([
            'post_id' => $product_id,
            'type' => 'review',
            'status' => 'approve',
            'parent' => 0,
            'count' => true,
        ]);

        $comments = get_comments([
            'post_id' => $product_id,
            'type' => 'review',
            'status' => 'approve',
            'parent' => 0,
            'number' => $per_page,
            'offset' => $offset,
            'orderby' => 'comment_date',
            'order' => 'DESC',
        ]);

        return Response::success([
            'items' => array_values(array_map([$this, 'format_review'], is_array($comments) ? $comments : [])),
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
            ],
        ]);
    }

    public function questions_index(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $product_id = absint($request->get_param('id'));
        $product = wc_get_product($product_id);

        if (! $product instanceof WC_Product || $product->get_status() !== 'publish') {
            return new WP_Error('herlan_product_not_found', __('Product not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(50, max(1, (int) $request->get_param('per_page')));
        $offset = ($page - 1) * $per_page;

        $total = (int) get_comments([
            'post_id' => $product_id,
            'type' => 'cr_qna',
            'status' => 'approve',
            'parent' => 0,
            'count' => true,
        ]);

        $comments = get_comments([
            'post_id' => $product_id,
            'type' => 'cr_qna',
            'status' => 'approve',
            'parent' => 0,
            'number' => $per_page,
            'offset' => $offset,
            'orderby' => 'comment_date',
            'order' => 'DESC',
        ]);

        return Response::success([
            'items' => array_values(array_map([$this, 'format_question'], is_array($comments) ? $comments : [])),
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
            ],
        ]);
    }

    public function create_review(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $product_id = absint($request->get_param('id'));
        $product = wc_get_product($product_id);

        if (! $product instanceof WC_Product || $product->get_status() !== 'publish') {
            return new WP_Error('herlan_product_not_found', __('Product not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        $user = $this->current_user($request);
        $rating = (int) $request->get_param('rating');
        $content = (string) $request->get_param('content');

        $existing = (int) get_comments([
            'post_id' => $product_id,
            'user_id' => $user->ID,
            'type' => 'review',
            'count' => true,
        ]);

        if ($existing > 0) {
            return new WP_Error(
                'herlan_review_duplicate',
                __('You have already submitted a review for this product.', 'herlan-rest-api'),
                ['status' => 409]
            );
        }

        $is_manager = user_can($user, 'manage_woocommerce');
        $is_verified = function_exists('wc_customer_bought_product')
            && wc_customer_bought_product($user->user_email, $user->ID, $product_id);

        if (! $is_manager && ! $is_verified) {
            return new WP_Error(
                'herlan_review_not_purchased',
                __('Only verified purchasers can submit a review for this product.', 'herlan-rest-api'),
                ['status' => 403]
            );
        }

        $comment_id = wp_insert_comment([
            'comment_post_ID' => $product_id,
            'comment_author' => $user->display_name,
            'comment_author_email' => $user->user_email,
            'comment_author_url' => '',
            'comment_content' => $content,
            'comment_type' => 'review',
            'comment_parent' => 0,
            'user_id' => $user->ID,
            'comment_approved' => 1,
            'comment_date' => current_time('mysql'),
            'comment_date_gmt' => current_time('mysql', 1),
        ]);

        if (! $comment_id) {
            return new WP_Error('herlan_review_failed', __('Could not submit review.', 'herlan-rest-api'), ['status' => 500]);
        }

        update_comment_meta($comment_id, 'rating', $rating);
        update_comment_meta($comment_id, 'verified', (int) $is_verified);

        if (function_exists('herlan_recalculate_product_rating')) {
            herlan_recalculate_product_rating($product_id);
        }

        return Response::success([], 201, __('Review submitted successfully.', 'herlan-rest-api'));
    }

    public function create_question(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $product_id = absint($request->get_param('id'));
        $product = wc_get_product($product_id);

        if (! $product instanceof WC_Product || $product->get_status() !== 'publish') {
            return new WP_Error('herlan_product_not_found', __('Product not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        $user = $this->current_user($request);
        $question_text = (string) $request->get_param('question');

        $is_manager = user_can($user, 'manage_woocommerce');

        $comment_id = wp_insert_comment([
            'comment_post_ID' => $product_id,
            'comment_author' => $user->display_name,
            'comment_author_email' => $user->user_email,
            'comment_author_url' => '',
            'comment_content' => $question_text,
            'comment_type' => 'cr_qna',
            'comment_parent' => 0,
            'user_id' => $user->ID,
            'comment_approved' => $is_manager ? 1 : 0,
            'comment_date' => current_time('mysql'),
            'comment_date_gmt' => current_time('mysql', 1),
        ]);

        if (! $comment_id) {
            return new WP_Error('herlan_question_failed', __('Could not submit question.', 'herlan-rest-api'), ['status' => 500]);
        }

        $message = $is_manager
            ? __('Question submitted successfully.', 'herlan-rest-api')
            : __('Question submitted and pending approval.', 'herlan-rest-api');

        return Response::success([], 201, $message);
    }

    private function format_product(WC_Product $product): array
    {
        $product_id = $product->get_id();

        $data = [
            'id' => $product_id,
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'type' => $product->get_type(),
            'permalink' => $product->get_permalink(),
            'status' => $product->get_status(),
            'sku' => $product->get_sku(),
            'description' => wp_kses_post($product->get_description()),
            'short_description' => wp_kses_post($product->get_short_description()),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'price_html' => $product->get_price_html(),
            'on_sale' => $product->is_on_sale(),
            'purchasable' => $product->is_purchasable(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'total_sold' => (int) get_post_meta($product_id, 'total_sales', true),
            'average_rating' => $product->get_average_rating(),
            'rating_count' => $product->get_rating_count(),
            'review_count' => $product->get_review_count(),
            'rating_distribution' => $this->rating_distribution($product_id),
            'wishlist_count' => $this->wishlist_count($product_id),
            'categories' => $this->terms($product_id, 'product_cat'),
            'tags' => $this->terms($product_id, 'product_tag'),
            'brand' => $this->first_term($product_id, 'brand'),
            'taxonomies' => $this->product_taxonomies($product_id),
            'attributes' => $this->attributes($product),
            'images' => $this->images($product),
            'custom_fields' => $this->custom_fields($product_id),
            'linked_products' => $this->linked_products($product),
            'recommendations' => $this->recommendations($product),
            'reviews' => $this->inline_reviews($product_id),
            'questions' => $this->inline_questions($product_id),
        ];

        if ($product->is_type('variable')) {
            $data['variations'] = $this->variations($product);
        }

        if ($product->is_type('easy_product_bundle')) {
            $data['bundle'] = $this->bundle_info($product);
        }

        return apply_filters('herlan_rest_api_product_data', $data, $product);
    }

    /**
     * Summarizes an "Easy Product Bundles" product's slots (e.g. a "buy 1 of these
     * 15 shades, get this specific product free" offer) so a client can detect the
     * offer and know which slots need a selection before add-to-cart.
     *
     * Slot product lists are not inlined here (they can be large and support
     * search/pagination) — use GET /products/{id}/bundle-items?index={slot} instead.
     */
    private function bundle_info(WC_Product $product): array
    {
        $items = method_exists($product, 'get_items') ? (array) $product->get_items() : [];
        $slots = [];
        $has_free_slot = false;

        foreach ($items as $index => $item) {
            $discount_type = $item['discount_type'] ?? 'none';
            $discount = isset($item['discount']) ? (float) $item['discount'] : 0.0;
            $is_free = ('percentage' === $discount_type && $discount >= 100.0);
            $is_selectable = ! empty($item['products']) && is_array($item['products']);
            $fixed_product_id = ! $is_selectable && ! empty($item['product']) ? absint($item['product']) : 0;

            if ($is_free) {
                $has_free_slot = true;
            }

            $slot = [
                'index' => (int) $index,
                'title' => (string) ($item['title'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'is_selectable' => $is_selectable,
                'is_optional' => 'true' === ($item['optional'] ?? 'false'),
                'is_free' => $is_free,
                'discount_type' => $discount_type,
                'discount' => $discount,
                'quantity' => (int) ($item['quantity'] ?? 1),
                'min_quantity' => isset($item['min_quantity']) && '' !== $item['min_quantity'] ? (int) $item['min_quantity'] : null,
                'max_quantity' => isset($item['max_quantity']) && '' !== $item['max_quantity'] ? (int) $item['max_quantity'] : null,
                'options_count' => $is_selectable ? count($item['products']) : ($fixed_product_id ? 1 : 0),
                'product' => null,
            ];

            if ($fixed_product_id) {
                $fixed_product = wc_get_product($fixed_product_id);
                if ($fixed_product instanceof WC_Product) {
                    $slot['product'] = $this->product_card($fixed_product);
                }
            }

            $slots[] = $slot;
        }

        return [
            'is_bundle' => true,
            'has_free_item' => $has_free_slot,
            'is_fixed_price' => method_exists($product, 'is_fixed_price') && $product->is_fixed_price(),
            'slots' => $slots,
        ];
    }

    private function linked_products(WC_Product $product): array
    {
        if (! class_exists('WPCleverWpclv')) {
            return [
                'enabled' => false,
                'attributes' => [],
            ];
        }

        $product_id = $product->get_id();
        $link_data = \WPCleverWpclv::get_linked_data($product, 'single');

        if (empty($link_data)) {
            return [
                'enabled' => true,
                'link_id' => null,
                'attributes' => [],
            ];
        }

        $link_product_ids = \WPCleverWpclv::get_linked_products($link_data, 'single');
        $link_product_ids = apply_filters('wpclv_linked_products', array_diff(array_map('absint', $link_product_ids), [$product_id]), $product_id);
        $link_attributes = $link_data['attributes'] ?? [];
        $link_images = $link_data['images'] ?? [];
        $link_swatches = $link_data['swatches'] ?? [];
        $link_dropdown = $link_data['dropdown'] ?? [];
        $linked_attributes = [];
        $assigned_attributes = array_keys($product->get_attributes());
        $product_attributes = [];

        foreach ($assigned_attributes as $assigned_attribute) {
            $product_attributes[$assigned_attribute] = wc_get_product_terms($product_id, $assigned_attribute, ['fields' => 'ids']);
        }

        $link_attribute_ids = array_map(static fn ($value) => (int) filter_var((string) $value, FILTER_SANITIZE_NUMBER_INT), $link_attributes);

        foreach ($link_attributes as $link_attribute) {
            $attribute_id = (int) filter_var((string) $link_attribute, FILTER_SANITIZE_NUMBER_INT);
            $attribute = wc_get_attribute($attribute_id);

            if (! $attribute) {
                continue;
            }

            $terms = get_terms([
                'taxonomy' => $attribute->slug,
                'hide_empty' => false,
            ]);
            $current_terms = wc_get_product_terms($product_id, $attribute->slug, ['fields' => 'slugs']);

            if (empty($terms) || empty($current_terms) || is_wp_error($terms)) {
                continue;
            }

            $used_linked_ids = [];
            $items = [];

            foreach ($terms as $term) {
                if (! $term instanceof WP_Term) {
                    continue;
                }

                $is_active = in_array($term->slug, $current_terms, true);
                $linked_id = $is_active ? $product_id : $this->linked_product_id_for_term($term, $product_attributes, $link_attribute_ids, $link_product_ids, $used_linked_ids);

                if (! $linked_id && \WPCleverWpclv::get_setting('hide_empty', 'yes') !== 'no') {
                    continue;
                }

                if ($linked_id && ! $is_active) {
                    $used_linked_ids[] = $linked_id;
                }

                $linked_product = $linked_id ? wc_get_product($linked_id) : null;

                $items[] = [
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'color' => (string) get_term_meta($term->term_id, 'wpcvs_color', true),
                    'image' => $this->term_image($term),
                    'active' => $is_active,
                    'in_stock' => $linked_product instanceof WC_Product ? $linked_product->is_in_stock() : false,
                    'product' => $linked_product instanceof WC_Product ? $this->linked_product_summary($linked_product) : null,
                ];
            }

            $linked_attributes[] = [
                'id' => $attribute_id,
                'name' => $attribute->name,
                'slug' => $attribute->slug,
                'label' => wc_attribute_label($attribute->name),
                'display' => $this->linked_attribute_display($link_attribute, $link_images, $link_swatches, $link_dropdown),
                'current_terms' => array_values($current_terms),
                'terms' => $items,
            ];
        }

        return [
            'enabled' => true,
            'link_id' => absint($link_data['id'] ?? 0) ?: null,
            'source' => $link_data['source'] ?? 'products',
            'attributes' => $linked_attributes,
        ];
    }

    private function linked_product_id_for_term(WP_Term $term, array $product_attributes, array $link_attribute_ids, array $link_product_ids, array $used_linked_ids): int
    {
        $tax_query = [];
        $term_query = [
            'taxonomy' => $term->taxonomy,
            'term' => $term->slug,
        ];

        foreach ($product_attributes as $product_attribute_key => $product_attribute) {
            $product_attribute_id = wc_attribute_taxonomy_id_by_name($product_attribute_key);

            if (! in_array($product_attribute_id, $link_attribute_ids, true)) {
                continue;
            }

            if ($term->taxonomy !== $product_attribute_key) {
                $tax_query[] = [
                    'taxonomy' => $product_attribute_key,
                    'term' => $product_attribute,
                ];
            }
        }

        $tax_query[] = $term_query;

        $linked_id = \WPCleverWpclv::get_linked_product_id($tax_query, $link_product_ids, $used_linked_ids);

        if (! $linked_id && apply_filters('wpclv_get_imperfect_product', true)) {
            $linked_id = \WPCleverWpclv::get_linked_product_id([$term_query], $link_product_ids, $used_linked_ids);
        }

        return absint($linked_id);
    }

    private function linked_attribute_display(string $link_attribute, array $images, array $swatches, array $dropdown): string
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

    private function linked_product_summary(WC_Product $product): array
    {
        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'permalink' => $product->get_permalink(),
            'sku' => $product->get_sku(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'on_sale' => $product->is_on_sale(),
            'stock_status' => $product->get_stock_status(),
            'image' => $product->get_image_id() ? $this->image($product->get_image_id(), 0) : null,
        ];
    }

    private function recommendations(WC_Product $product): array
    {
        $product_id = $product->get_id();
        $limit = (int) apply_filters('herlan_rest_api_product_recommendation_limit', 10, $product);
        $categories = get_the_terms($product_id, 'product_cat');
        $brands = get_the_terms($product_id, 'brand');
        $shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : get_post_type_archive_link('product');

        $category = is_array($categories) && ! empty($categories) && ! is_wp_error($categories) ? $categories[0] : null;
        $brand = is_array($brands) && ! empty($brands) && ! is_wp_error($brands) ? $brands[0] : null;

        return [
            'you_may_like' => [
                'title' => 'You May Like',
                'url' => $category instanceof WP_Term ? $this->term_link($category) : null,
                'products' => $category instanceof WP_Term
                    ? $this->recommendation_products($this->taxonomy_recommendation_args('product_cat', $category->slug, $product_id, $limit), $limit)
                    : [],
            ],
            'more_from_this_brand' => [
                'title' => 'More from this brand',
                'url' => $brand instanceof WP_Term ? $this->term_link($brand) : null,
                'products' => $brand instanceof WP_Term
                    ? $this->recommendation_products($this->taxonomy_recommendation_args('brand', $brand->slug, $product_id, $limit), $limit)
                    : [],
            ],
            'best_selling' => [
                'title' => 'Best Selling',
                'url' => $shop_url ? add_query_arg('orderby', 'sales', $shop_url) : null,
                'products' => $this->recommendation_products([
                    'post__not_in' => [$product_id],
                    'meta_key' => 'total_sales',
                    'orderby' => 'meta_value_num',
                    'order' => 'DESC',
                    'meta_query' => $this->in_stock_meta_query(),
                    'tax_query' => $this->visible_product_tax_query(),
                ], $limit),
            ],
            'new_arrivals' => [
                'title' => 'New Arrivals',
                'url' => $shop_url ? add_query_arg('orderby', 'date', $shop_url) : null,
                'products' => $this->recommendation_products([
                    'post__not_in' => [$product_id],
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'meta_query' => $this->in_stock_meta_query(),
                    'tax_query' => array_merge($this->visible_product_tax_query(), [
                        [
                            'taxonomy' => 'product_cat',
                            'field' => 'slug',
                            'terms' => ['home-care'],
                            'operator' => 'NOT IN',
                            'include_children' => true,
                        ],
                    ]),
                ], $limit),
            ],
        ];
    }

    private function taxonomy_recommendation_args(string $taxonomy, string $term_slug, int $product_id, int $limit): array
    {
        return [
            'post__not_in' => [$product_id],
            'orderby' => [
                'meta_value' => 'ASC',
                'rand' => 'DESC',
            ],
            'meta_key' => '_stock_status',
            'meta_query' => [
                [
                    'key' => '_stock_status',
                    'value' => ['instock', 'outofstock'],
                    'compare' => 'IN',
                ],
            ],
            'tax_query' => array_merge($this->visible_product_tax_query(), [
                [
                    'taxonomy' => $taxonomy,
                    'field' => 'slug',
                    'terms' => $term_slug,
                    'include_children' => true,
                ],
            ]),
        ];
    }

    private function recommendation_products(array $args, int $limit): array
    {
        $query_args = wp_parse_args($args, [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'no_found_rows' => true,
            'fields' => 'ids',
        ]);

        $query = new \WP_Query($query_args);
        $products = [];

        foreach ($query->posts as $product_id) {
            $recommended = wc_get_product($product_id);

            if ($recommended instanceof WC_Product) {
                $products[] = $this->product_card($recommended);
            }
        }

        wp_reset_postdata();

        return $products;
    }

    private function term_link(WP_Term $term): ?string
    {
        $link = get_term_link($term);

        return is_wp_error($link) ? null : $link;
    }

    private function product_card(WC_Product $product): array
    {
        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'type' => $product->get_type(),
            'permalink' => $product->get_permalink(),
            'sku' => $product->get_sku(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'price_html' => $product->get_price_html(),
            'on_sale' => $product->is_on_sale(),
            'stock_status' => $product->get_stock_status(),
            'average_rating' => $product->get_average_rating(),
            'rating_count' => $product->get_rating_count(),
            'brand' => $this->first_term($product->get_id(), 'brand'),
            'image' => $product->get_image_id() ? $this->image($product->get_image_id(), 0) : null,
        ];
    }

    private function in_stock_meta_query(): array
    {
        return [
            [
                'key' => '_stock_status',
                'value' => 'outofstock',
                'compare' => '!=',
            ],
        ];
    }

    private function visible_product_tax_query(): array
    {
        return [
            [
                'taxonomy' => 'product_visibility',
                'field' => 'name',
                'terms' => 'exclude-from-catalog',
                'operator' => 'NOT IN',
            ],
        ];
    }

    private function custom_fields(int $product_id): array
    {
        if (function_exists('get_fields')) {
            $fields = get_fields($product_id);

            return is_array($fields) ? $this->normalize_value($fields) : [];
        }

        return [];
    }

    private function normalize_value(mixed $value): mixed
    {
        if ($value instanceof WC_Product) {
            return $this->linked_product_summary($value);
        }

        if ($value instanceof WP_Term) {
            return [
                'id' => $value->term_id,
                'name' => $value->name,
                'slug' => $value->slug,
                'taxonomy' => $value->taxonomy,
            ];
        }

        if ($value instanceof \WP_Post) {
            return [
                'id' => $value->ID,
                'title' => get_the_title($value),
                'slug' => $value->post_name,
                'type' => $value->post_type,
            ];
        }

        if (is_array($value)) {
            return array_map([$this, 'normalize_value'], $value);
        }

        return $value;
    }

    private function attributes(WC_Product $product): array
    {
        $attributes = [];

        foreach ($product->get_attributes() as $attribute) {
            $options = [];

            if ($attribute->is_taxonomy()) {
                $terms = wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields' => 'all']);

                foreach ($terms as $term) {
                    if ($term instanceof WP_Term) {
                        $options[] = [
                            'id' => $term->term_id,
                            'name' => $term->name,
                            'slug' => $term->slug,
                            'color' => (string) get_term_meta($term->term_id, 'wpcvs_color', true),
                            'image' => $this->term_image($term),
                        ];
                    }
                }
            } else {
                $options = array_values($attribute->get_options());
            }

            $attributes[] = [
                'id' => $attribute->get_id(),
                'name' => $attribute->get_name(),
                'label' => wc_attribute_label($attribute->get_name()),
                'position' => $attribute->get_position(),
                'visible' => $attribute->get_visible(),
                'variation' => $attribute->get_variation(),
                'options' => $options,
            ];
        }

        return $attributes;
    }

    private function variations(WC_Product $product): array
    {
        $variations = [];

        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);

            if ($variation instanceof WC_Product) {
                $variations[] = [
                    'id' => $variation->get_id(),
                    'sku' => $variation->get_sku(),
                    'price' => $variation->get_price(),
                    'regular_price' => $variation->get_regular_price(),
                    'sale_price' => $variation->get_sale_price(),
                    'on_sale' => $variation->is_on_sale(),
                    'stock_status' => $variation->get_stock_status(),
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'attributes' => $variation->get_attributes(),
                    'image' => $variation->get_image_id() ? $this->image($variation->get_image_id(), 0) : null,
                ];
            }
        }

        return $variations;
    }

    private function images(WC_Product $product): array
    {
        $images = [];

        if ($product->get_image_id()) {
            $images[] = $this->image($product->get_image_id(), 0);
        }

        $position = 1;

        foreach ($product->get_gallery_image_ids() as $image_id) {
            $images[] = $this->image($image_id, $position);
            $position++;
        }

        return $images;
    }

    private function image(int $image_id, int $position): array
    {
        return [
            'id' => $image_id,
            'src' => wp_get_attachment_url($image_id),
            'name' => get_the_title($image_id),
            'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
            'position' => $position,
        ];
    }

    private function term_image(WP_Term $term): ?string
    {
        $image_id = absint(get_term_meta($term->term_id, 'wpcvs_image', true));

        return $image_id ? wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail') : null;
    }

    private function terms(int $product_id, string $taxonomy): array
    {
        $terms = get_the_terms($product_id, $taxonomy);
        $data = [];

        if ($terms && ! is_wp_error($terms)) {
            foreach ($terms as $term) {
                $data[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                ];
            }
        }

        return $data;
    }

    private function product_taxonomies(int $product_id): array
    {
        $excluded = [
            'product_type',
            'product_visibility',
            'product_shipping_class',
            'pos_product_visibility',
        ];
        $taxonomies = get_object_taxonomies('product', 'objects');
        $data = [];

        foreach ($taxonomies as $taxonomy => $object) {
            if (in_array($taxonomy, $excluded, true)) {
                continue;
            }

            if (! $object->public && ! str_starts_with($taxonomy, 'pa_')) {
                continue;
            }

            $terms = $this->terms_with_meta($product_id, $taxonomy);

            if (empty($terms)) {
                continue;
            }

            $data[$taxonomy] = [
                'name' => $taxonomy,
                'label' => $object->label,
                'hierarchical' => (bool) $object->hierarchical,
                'terms' => $terms,
            ];
        }

        return $data;
    }

    private function available_filters(array $base_tax_query, bool $include_counts): array
    {
        $filters = [];

        foreach ($this->filterable_taxonomies() as $taxonomy => $object) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => true,
            ]);

            if (empty($terms) || is_wp_error($terms)) {
                continue;
            }

            $items = [];

            foreach ($terms as $term) {
                if (! $term instanceof WP_Term) {
                    continue;
                }

                $count = $include_counts ? $this->term_product_count($taxonomy, $term->slug, $base_tax_query) : (int) $term->count;

                if ($include_counts && $count < 1) {
                    continue;
                }

                $items[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'taxonomy' => $term->taxonomy,
                    'count' => $count,
                    'parent' => $term->parent,
                    'link' => $this->term_link($term),
                    'color' => (string) get_term_meta($term->term_id, 'wpcvs_color', true),
                    'image' => $this->term_image($term),
                ];
            }

            if (empty($items)) {
                continue;
            }

            $filters[] = [
                'taxonomy' => $taxonomy,
                'label' => $object->label,
                'type' => str_starts_with($taxonomy, 'pa_') ? 'attribute' : 'taxonomy',
                'hierarchical' => (bool) $object->hierarchical,
                'terms' => $items,
            ];
        }

        return $filters;
    }

    private function filterable_taxonomies(): array
    {
        $excluded = [
            'product_type',
            'product_visibility',
            'product_shipping_class',
            'pos_product_visibility',
        ];
        $preferred = [
            'product_cat',
            'brand',
            'product_tag',
            'keywords',
        ];
        $taxonomies = get_object_taxonomies('product', 'objects');
        $filterable = [];

        foreach ($preferred as $taxonomy) {
            if (isset($taxonomies[$taxonomy]) && ! in_array($taxonomy, $excluded, true)) {
                $filterable[$taxonomy] = $taxonomies[$taxonomy];
            }
        }

        foreach ($taxonomies as $taxonomy => $object) {
            if (isset($filterable[$taxonomy]) || in_array($taxonomy, $excluded, true)) {
                continue;
            }

            if ($object->public || str_starts_with($taxonomy, 'pa_')) {
                $filterable[$taxonomy] = $object;
            }
        }

        return apply_filters('herlan_rest_api_filterable_taxonomies', $filterable);
    }

    private function filter_base_tax_query(string $taxonomy, string $term): array
    {
        if (! $taxonomy || ! $term || ! taxonomy_exists($taxonomy)) {
            return $this->visible_product_tax_query();
        }

        return array_merge($this->visible_product_tax_query(), [
            [
                'taxonomy' => $taxonomy,
                'field' => 'slug',
                'terms' => $term,
                'include_children' => true,
            ],
        ]);
    }

    private function term_product_count(string $taxonomy, string $term_slug, array $base_tax_query): int
    {
        $tax_query = array_merge($base_tax_query, [
            [
                'taxonomy' => $taxonomy,
                'field' => 'slug',
                'terms' => $term_slug,
                'include_children' => true,
            ],
        ]);

        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }

        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'tax_query' => $tax_query,
        ]);

        return (int) $query->found_posts;
    }

    private function price_range(array $base_tax_query): array
    {
        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'tax_query' => $base_tax_query,
        ]);
        $prices = [];

        foreach ($query->posts as $product_id) {
            $product = wc_get_product($product_id);

            if ($product instanceof WC_Product && $product->get_price() !== '') {
                $prices[] = (float) $product->get_price();
            }
        }

        if (empty($prices)) {
            return [
                'min' => null,
                'max' => null,
            ];
        }

        return [
            'min' => min($prices),
            'max' => max($prices),
        ];
    }

    private function sort_options(): array
    {
        return [
            ['key' => 'popularity', 'label' => __('Best Selling', 'herlan-rest-api'), 'orderby' => 'sales'],
            ['key' => 'date', 'label' => __('New Arrivals', 'herlan-rest-api'), 'orderby' => 'date'],
            ['key' => 'price_asc', 'label' => __('Price: Low to High', 'herlan-rest-api'), 'orderby' => 'price', 'order' => 'ASC'],
            ['key' => 'price_desc', 'label' => __('Price: High to Low', 'herlan-rest-api'), 'orderby' => 'price', 'order' => 'DESC'],
            ['key' => 'rating', 'label' => __('Top Rated', 'herlan-rest-api'), 'orderby' => 'rating'],
        ];
    }

    private function terms_with_meta(int $product_id, string $taxonomy): array
    {
        $terms = get_the_terms($product_id, $taxonomy);
        $data = [];

        if ($terms && ! is_wp_error($terms)) {
            foreach ($terms as $term) {
                $data[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'taxonomy' => $term->taxonomy,
                    'description' => $term->description,
                    'parent' => $term->parent,
                    'count' => $term->count,
                    'link' => $this->term_link($term),
                    'color' => (string) get_term_meta($term->term_id, 'wpcvs_color', true),
                    'image' => $this->term_image($term),
                ];
            }
        }

        return $data;
    }

    private function first_term(int $product_id, string $taxonomy): ?array
    {
        $terms = $this->terms($product_id, $taxonomy);

        return $terms[0] ?? null;
    }

    private function rating_distribution(int $product_id): array
    {
        $stored = get_post_meta($product_id, '_wc_rating_counts', true);
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

        if (is_array($stored)) {
            foreach ($stored as $star => $count) {
                $star = (int) $star;
                if ($star >= 1 && $star <= 5) {
                    $distribution[$star] = (int) $count;
                }
            }
        }

        return $distribution;
    }

    private function wishlist_count(int $product_id): int
    {
        if (! class_exists('TInvWL_Wishlist')) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tinvwl_wishlist_product';
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT wishlist_id) FROM {$table} WHERE product_id = %d",
            $product_id
        ));

        return (int) $count;
    }

    private function inline_reviews(int $product_id, int $limit = 5): array
    {
        $total = (int) get_comments([
            'post_id' => $product_id,
            'type' => 'review',
            'status' => 'approve',
            'parent' => 0,
            'count' => true,
        ]);

        $comments = get_comments([
            'post_id' => $product_id,
            'type' => 'review',
            'status' => 'approve',
            'parent' => 0,
            'number' => $limit,
            'orderby' => 'comment_date',
            'order' => 'DESC',
        ]);

        return [
            'total' => $total,
            'items' => array_values(array_map([$this, 'format_review'], is_array($comments) ? $comments : [])),
        ];
    }

    private function inline_questions(int $product_id, int $limit = 5): array
    {
        $total = (int) get_comments([
            'post_id' => $product_id,
            'type' => 'cr_qna',
            'status' => 'approve',
            'parent' => 0,
            'count' => true,
        ]);

        $comments = get_comments([
            'post_id' => $product_id,
            'type' => 'cr_qna',
            'status' => 'approve',
            'parent' => 0,
            'number' => $limit,
            'orderby' => 'comment_date',
            'order' => 'DESC',
        ]);

        return [
            'total' => $total,
            'items' => array_values(array_map([$this, 'format_question'], is_array($comments) ? $comments : [])),
        ];
    }

    private function format_review(WP_Comment $comment): array
    {
        $comment_id = (int) $comment->comment_ID;
        $rating = (int) get_comment_meta($comment_id, 'rating', true);
        $verified = (bool) get_comment_meta($comment_id, 'verified', true);

        if (! $verified && $comment->user_id && function_exists('wc_customer_bought_product')) {
            $verified = wc_customer_bought_product(
                $comment->comment_author_email,
                (int) $comment->user_id,
                (int) $comment->comment_post_ID
            );
        }

        return [
            'id' => $comment_id,
            'author' => $comment->comment_author,
            'author_id' => (int) $comment->user_id ?: null,
            'rating' => $rating,
            'content' => $comment->comment_content,
            'date' => $comment->comment_date,
            'verified_purchase' => (bool) $verified,
        ];
    }

    private function format_question(WP_Comment $question): array
    {
        $answers_raw = get_comments([
            'parent' => $question->comment_ID,
            'type' => 'cr_qna',
            'status' => 'approve',
            'orderby' => 'comment_date',
            'order' => 'ASC',
        ]);

        $answers = [];

        foreach ($answers_raw as $answer) {
            $author_type = 0;

            if ($answer->user_id && user_can((int) $answer->user_id, 'manage_woocommerce')) {
                $author_type = 1;
            }

            $answers[] = [
                'id' => (int) $answer->comment_ID,
                'author' => $answer->comment_author,
                'author_id' => (int) $answer->user_id ?: null,
                'author_type' => $author_type,
                'content' => $answer->comment_content,
                'date' => $answer->comment_date,
            ];
        }

        return [
            'id' => (int) $question->comment_ID,
            'author' => $question->comment_author,
            'author_id' => (int) $question->user_id ?: null,
            'question' => $question->comment_content,
            'date' => $question->comment_date,
            'answer_count' => count($answers),
            'answers' => $answers,
        ];
    }
}
