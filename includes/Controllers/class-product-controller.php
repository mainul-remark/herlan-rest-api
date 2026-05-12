<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WC_Product;
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

    private function format_product(WC_Product $product): array
    {
        $data = [
            'id' => $product->get_id(),
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
            'average_rating' => $product->get_average_rating(),
            'rating_count' => $product->get_rating_count(),
            'categories' => $this->terms($product->get_id(), 'product_cat'),
            'tags' => $this->terms($product->get_id(), 'product_tag'),
            'brand' => $this->first_term($product->get_id(), 'brand'),
            'taxonomies' => $this->product_taxonomies($product->get_id()),
            'attributes' => $this->attributes($product),
            'images' => $this->images($product),
            'custom_fields' => $this->custom_fields($product->get_id()),
            'linked_products' => $this->linked_products($product),
            'recommendations' => $this->recommendations($product),
        ];

        if ($product->is_type('variable')) {
            $data['variations'] = $this->variations($product);
        }

        return apply_filters('herlan_rest_api_product_data', $data, $product);
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
}
