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

final class ProductListingController extends Controller
{
    private const CONTEXT_TAXONOMIES = ['product_cat', 'product_tag', 'brand', 'keywords', 'skin-type', 'age-range'];

    private const ALIASES = [
        '_brand'      => 'filter_brand',
        'product-cat' => 'filter_product_cat',
        '_skin-type'  => 'filter_skin-type',
        '_age-range'  => 'filter_age-range',
        '_keywords'   => 'filter_keywords',
        'sort-by'     => 'orderby',
    ];

    private const LEGACY_KEYS = [
        'brand'       => '_brand',
        'product_cat' => 'product-cat',
        'skin-type'   => '_skin-type',
        'age-range'   => '_age-range',
        'keywords'    => '_keywords',
    ];

    private const VALID_ORDERBY = ['menu_order', 'popularity', 'rating', 'date', 'price', 'price-desc', 'title'];
    private const VALID_STOCK   = ['instock', 'outofstock', 'onbackorder'];
    private const OPERATOR_MAP  = ['in' => 'IN', 'and' => 'AND', 'not_in' => 'NOT IN'];

    // ─── Route registration ────────────────────────────────────────────────

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/group/products', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'index'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/filter-config', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'filter_config'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/filter-terms', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'filter_terms'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/filter-forms/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'filter_form'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => static fn ($v) => is_numeric($v) && absint($v) > 0,
                ],
            ],
        ]);
    }

    // ─── Endpoint handlers ─────────────────────────────────────────────────

    public function index(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $params = $this->normalize_params($request);

        $context_taxonomy = $params['context_taxonomy'];
        $context_term     = $params['context_term'];

        if ($context_taxonomy && ! in_array($context_taxonomy, self::CONTEXT_TAXONOMIES, true)) {
            return new WP_Error('herlan_invalid_context_taxonomy', __('Invalid context taxonomy.', 'herlan-rest-api'), ['status' => 400]);
        }

        if ($context_taxonomy && ! $context_term) {
            return new WP_Error('herlan_missing_context_term', __('context_term is required when context_taxonomy is set.', 'herlan-rest-api'), ['status' => 400]);
        }

        if (! in_array($params['orderby'], self::VALID_ORDERBY, true)) {
            return new WP_Error('herlan_invalid_orderby', __('Invalid orderby value.', 'herlan-rest-api'), ['status' => 400]);
        }

        if ($params['stock_status'] && ! in_array($params['stock_status'], self::VALID_STOCK, true)) {
            return new WP_Error('herlan_invalid_stock_status', __('Invalid stock_status value.', 'herlan-rest-api'), ['status' => 400]);
        }

        $context_tax_query = $this->build_context_tax_query($context_taxonomy, $context_term);
        $query_args        = $this->build_query_args($params, $context_tax_query, $params['page'], $params['per_page']);
        $query             = new \WP_Query($query_args);

        $data = [
            'context'    => $this->format_context_block($context_taxonomy, $context_term),
            'request'    => $this->format_request_block($params),
            'products'   => [],
            'filters'    => [],
            'pagination' => $this->format_pagination_block($query, $params['page'], $params['per_page']),
        ];

        if ($params['include_products']) {
            foreach ($query->posts as $post_id) {
                $product = wc_get_product($post_id);
                if ($product instanceof WC_Product) {
                    $data['products'][] = $this->format_product_card($product);
                }
            }
        }

        if ($params['include_filters']) {
            $data['filters'] = $this->format_filters_block($params, $context_tax_query);
        }

        wp_reset_postdata();

        return Response::success($data);
    }

    public function filter_config(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $context_taxonomy = sanitize_key((string) $request->get_param('context_taxonomy'));
        $context_term     = sanitize_title((string) $request->get_param('context_term'));
        $page             = max(1, (int) ($request->get_param('page') ?? 1));
        $per_page         = min(100, max(1, (int) ($request->get_param('per_page') ?? 50)));

        $taxonomies  = $this->filterable_taxonomies();
        $filter_defs = [];

        foreach ($taxonomies as $taxonomy => $tax_object) {
            $canonical_key = 'filter_' . $taxonomy;
            $legacy_key    = self::LEGACY_KEYS[$taxonomy] ?? $canonical_key;

            $filter_defs[] = [
                'type'             => str_starts_with($taxonomy, 'pa_') ? 'attribute' : 'taxonomy',
                'taxonomy'         => $taxonomy,
                'filter_key'       => $legacy_key,
                'canonical_key'    => $canonical_key,
                'label'            => $tax_object->label,
                'multiple'         => true,
                'options_endpoint' => rest_url($this->namespace . '/filter-terms?taxonomy=' . $taxonomy),
            ];
        }

        $filter_defs[] = [
            'type'          => 'price',
            'filter_key'    => 'price',
            'canonical_key' => 'min_price,max_price',
            'label'         => __('Price', 'herlan-rest-api'),
        ];

        $filter_defs[] = [
            'type'          => 'sort-by',
            'filter_key'    => 'sort-by',
            'canonical_key' => 'orderby',
            'label'         => __('Sort By', 'herlan-rest-api'),
        ];

        $total       = count($filter_defs);
        $offset      = ($page - 1) * $per_page;
        $paged       = array_slice($filter_defs, $offset, $per_page);
        $total_pages = (int) ceil($total / $per_page);

        return Response::success([
            'context' => [
                'taxonomy' => $context_taxonomy ?: null,
                'term'     => $context_term ? ['slug' => $context_term] : null,
            ],
            'filters'  => $paged,
            'sorting'  => $this->sort_options_block('menu_order'),
            'per_page' => $this->per_page_block(12),
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $total_pages,
                'has_more'    => $page < $total_pages,
            ],
        ]);
    }

    public function filter_terms(WP_REST_Request $request)
    {
        $taxonomy         = sanitize_key((string) $request->get_param('taxonomy'));
        $context_taxonomy = sanitize_key((string) $request->get_param('context_taxonomy'));
        $context_term     = sanitize_title((string) $request->get_param('context_term'));
        $search           = sanitize_text_field((string) $request->get_param('search'));
        $page             = max(1, (int) ($request->get_param('page') ?? 1));
        $per_page         = min(100, max(1, (int) ($request->get_param('per_page') ?? 20)));

        if (! $taxonomy || ! taxonomy_exists($taxonomy)) {
            return new WP_Error('herlan_invalid_filter_taxonomy', __('Invalid or missing taxonomy.', 'herlan-rest-api'), ['status' => 400]);
        }

        $tax_object = get_taxonomy($taxonomy);

        $context_tax_query = $this->build_context_tax_query($context_taxonomy, $context_term);
        $pool_ids          = $this->pool_product_ids_raw($context_tax_query);
        $counts            = $this->count_terms_by_product_ids($taxonomy, $pool_ids);

        $term_args = ['taxonomy' => $taxonomy, 'hide_empty' => true, 'number' => 0];

        if ($search) {
            $term_args['search'] = $search;
        }

        $all_terms = get_terms($term_args);
        $items     = [];

        if (! is_wp_error($all_terms)) {
            foreach ($all_terms as $term) {
                if (! $term instanceof WP_Term) {
                    continue;
                }

                $count = $counts[$term->slug] ?? 0;

                if ($count < 1) {
                    continue;
                }

                $item = [
                    'id'     => $term->term_id,
                    'name'   => $term->name,
                    'slug'   => $term->slug,
                    'count'  => $count,
                    'parent' => $term->parent,
                    'image'  => $this->term_image($term),
                ];

                $color = (string) get_term_meta($term->term_id, 'wpcvs_color', true);
                if ($color) {
                    $item['color'] = $color;
                }

                $items[] = $item;
            }
        }

        $total       = count($items);
        $offset      = ($page - 1) * $per_page;
        $paged       = array_slice($items, $offset, $per_page);
        $total_pages = (int) ceil($total / $per_page);

        return Response::success([
            'taxonomy' => [
                'name'         => $taxonomy,
                'label'        => $tax_object ? $tax_object->label : $taxonomy,
                'hierarchical' => $tax_object ? (bool) $tax_object->hierarchical : false,
            ],
            'terms'      => $paged,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $total_pages,
                'has_more'    => $page < $total_pages,
            ],
        ]);
    }

    public function filter_form(WP_REST_Request $request)
    {
        $form_id          = absint($request->get_param('id'));
        $context_taxonomy = sanitize_key((string) ($request->get_param('context_taxonomy') ?? ''));
        $context_term     = sanitize_title((string) ($request->get_param('context_term') ?? ''));

        $form_post = get_post($form_id);

        if (! $form_post || $form_post->post_type !== 'wcapf-form') {
            return new WP_Error('herlan_wcapf_form_not_found', __('Filter form not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        $filter_posts = get_posts([
            'post_type'   => 'wcapf-filter',
            'post_status' => 'publish',
            'post_parent' => $form_id,
            'nopaging'    => true,
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
        ]);

        $context_tax_query = $this->build_context_tax_query($context_taxonomy, $context_term);

        // Compute pool once — reused for all taxonomy term counts.
        $pool_ids = $this->pool_product_ids_raw($context_tax_query);

        $filters = [];

        foreach ($filter_posts as $filter_post) {
            $filter_key    = $filter_post->post_name;
            $excerpt_parts = explode('>', (string) $filter_post->post_excerpt);
            $type          = $excerpt_parts[0] ?? '';
            $property      = $excerpt_parts[1] ?? '';

            // Skip UI-only components (active-filters, reset-button, etc.)
            if ('component' === $type || '' === $type) {
                continue;
            }

            $settings = maybe_unserialize($filter_post->post_content);
            if (! is_array($settings)) {
                $settings = [];
            }

            $label = sanitize_text_field($settings['title'] ?? $filter_post->post_title);

            $entry = [
                'id'         => $filter_post->ID,
                'type'       => $type,
                'label'      => $label,
                'filter_key' => $filter_key,
            ];

            if ('taxonomy' === $type) {
                $taxonomy     = $property;
                $tax_object   = get_taxonomy($taxonomy);
                $display_type = $settings['display_type'] ?? 'checkbox';
                $query_type   = $settings['query_type'] ?? 'or';
                $multiple     = in_array($display_type, ['checkbox', 'multi-select'], true);

                $entry['taxonomy']     = $taxonomy;
                $entry['api_param']    = 'filter_' . $taxonomy;
                $entry['display_type'] = $display_type;
                $entry['multiple']     = $multiple;
                $entry['operator']     = 'and' === $query_type ? 'and' : 'in';
                $entry['hierarchical'] = $tax_object ? (bool) $tax_object->hierarchical : false;
                $entry['terms']        = $this->terms_for_filter($taxonomy, $pool_ids, $settings);
                $entry['terms_endpoint'] = rest_url(
                    $this->namespace . '/filter-terms?taxonomy=' . $taxonomy
                    . ($context_taxonomy ? '&context_taxonomy=' . $context_taxonomy : '')
                    . ($context_term ? '&context_term=' . $context_term : '')
                );

            } elseif ('price' === $type) {
                $entry['api_param_min'] = 'min_price';
                $entry['api_param_max'] = 'max_price';
                $entry['range']         = $this->price_range_for_ids($pool_ids);

            } elseif ('sort-by' === $type) {
                $entry['api_param'] = 'orderby';
                $entry['options']   = $this->sort_options_block('menu_order')['options'];

            } elseif ('product-status' === $type) {
                $entry['api_param'] = 'on_sale or stock_status';
                $entry['note']      = 'Pass on_sale=1 for on-sale products, stock_status=instock|outofstock|onbackorder for stock filtering.';

            } elseif ('keyword' === $type) {
                $entry['api_param'] = 'search';

            } elseif ('rating' === $type) {
                $entry['note'] = 'Rating filter is not yet supported by /group/products.';
            }

            $filters[] = $entry;
        }

        return Response::success([
            'form' => [
                'id'    => $form_id,
                'title' => $form_post->post_title,
                'slug'  => $form_post->post_name,
            ],
            'context' => [
                'taxonomy' => $context_taxonomy ?: null,
                'term'     => $context_term ?: null,
            ],
            'filters'          => $filters,
            'products_endpoint' => rest_url($this->namespace . '/group/products'),
        ]);
    }

    private function terms_for_filter(string $taxonomy, array $pool_ids, array $settings): array
    {
        $counts    = $this->count_terms_by_product_ids($taxonomy, $pool_ids);
        $all_terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true, 'number' => 0]);

        if (is_wp_error($all_terms)) {
            return [];
        }

        $items = [];

        foreach ($all_terms as $term) {
            if (! $term instanceof WP_Term) {
                continue;
            }

            $count = $counts[$term->slug] ?? 0;

            if ($count < 1) {
                continue;
            }

            $item = [
                'id'     => $term->term_id,
                'name'   => $term->name,
                'slug'   => $term->slug,
                'count'  => $count,
                'parent' => $term->parent,
                'image'  => $this->term_image($term),
            ];

            $color = (string) get_term_meta($term->term_id, 'wpcvs_color', true);
            if ($color) {
                $item['color'] = $color;
            }

            $items[] = $item;
        }

        return $items;
    }

    // ─── Parameter normalization ───────────────────────────────────────────

    private function normalize_params(WP_REST_Request $request): array
    {
        $raw = $request->get_params();

        foreach (self::ALIASES as $old => $new) {
            if (isset($raw[$old]) && ! isset($raw[$new])) {
                $raw[$new] = $raw[$old];
            }
        }

        $filters          = [];
        $filter_operators = [];

        foreach ($raw as $key => $value) {
            if (str_starts_with($key, 'filter_operator_')) {
                $tax = substr($key, strlen('filter_operator_'));
                $op  = sanitize_key((string) $value);
                if (array_key_exists($op, self::OPERATOR_MAP)) {
                    $filter_operators[$tax] = $op;
                }
            } elseif (str_starts_with($key, 'filter_')) {
                $tax   = substr($key, strlen('filter_'));
                $slugs = array_values(array_filter(array_map('sanitize_title', explode(',', (string) $value))));
                if ($slugs) {
                    $filters[$tax] = $slugs;
                }
            }
        }

        return [
            'context_taxonomy'   => sanitize_key((string) ($raw['context_taxonomy'] ?? '')),
            'context_term'       => sanitize_title((string) ($raw['context_term'] ?? '')),
            'page'               => max(1, (int) ($raw['page'] ?? 1)),
            'per_page'           => min(50, max(1, (int) ($raw['per_page'] ?? 12))),
            'orderby'            => sanitize_key((string) ($raw['orderby'] ?? 'menu_order')),
            'order'              => strtoupper(in_array(strtoupper((string) ($raw['order'] ?? '')), ['ASC', 'DESC'], true) ? strtoupper($raw['order']) : 'ASC'),
            'search'             => sanitize_text_field((string) ($raw['search'] ?? '')),
            'min_price'          => isset($raw['min_price']) && is_numeric($raw['min_price']) ? (float) $raw['min_price'] : null,
            'max_price'          => isset($raw['max_price']) && is_numeric($raw['max_price']) ? (float) $raw['max_price'] : null,
            'stock_status'       => sanitize_key((string) ($raw['stock_status'] ?? '')),
            'on_sale'            => isset($raw['on_sale']) ? rest_sanitize_boolean($raw['on_sale']) : false,
            'include_products'   => isset($raw['include_products']) ? rest_sanitize_boolean($raw['include_products']) : true,
            'include_filters'    => isset($raw['include_filters']) ? rest_sanitize_boolean($raw['include_filters']) : false,
            'include_pagination' => isset($raw['include_pagination']) ? rest_sanitize_boolean($raw['include_pagination']) : true,
            'filters'            => $filters,
            'filter_operators'   => $filter_operators,
        ];
    }

    // ─── Query building ────────────────────────────────────────────────────

    private function build_context_tax_query(string $taxonomy, string $term): array
    {
        $base = [
            [
                'taxonomy' => 'product_visibility',
                'field'    => 'name',
                'terms'    => 'exclude-from-catalog',
                'operator' => 'NOT IN',
            ],
        ];

        if (! $taxonomy || ! $term) {
            return $base;
        }

        return array_merge($base, [
            [
                'taxonomy'         => $taxonomy,
                'field'            => 'slug',
                'terms'            => $term,
                'include_children' => true,
            ],
        ]);
    }

    private function build_filter_tax_query(array $filters, array $filter_operators): array
    {
        $clauses = [];

        foreach ($filters as $taxonomy => $slugs) {
            if (! taxonomy_exists($taxonomy) || empty($slugs)) {
                continue;
            }

            $op     = $filter_operators[$taxonomy] ?? 'in';
            $wp_op  = self::OPERATOR_MAP[$op] ?? 'IN';

            $clauses[] = [
                'taxonomy'         => $taxonomy,
                'field'            => 'slug',
                'terms'            => $slugs,
                'operator'         => $wp_op,
                'include_children' => $wp_op === 'IN',
            ];
        }

        return $clauses;
    }

    private function build_query_args(array $params, array $context_tax_query, int $page, int $per_page): array
    {
        $tax_query      = $context_tax_query;
        $filter_clauses = $this->build_filter_tax_query($params['filters'], $params['filter_operators']);

        if ($filter_clauses) {
            $tax_query = array_merge($tax_query, $filter_clauses);
        }

        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }

        $meta_query = [];

        if ($params['stock_status']) {
            $meta_query[] = ['key' => '_stock_status', 'value' => $params['stock_status'], 'compare' => '='];
        }

        if ($params['min_price'] !== null || $params['max_price'] !== null) {
            $meta_query[] = [
                'key'     => '_price',
                'compare' => 'BETWEEN',
                'type'    => 'DECIMAL',
                'value'   => [$params['min_price'] ?? 0, $params['max_price'] ?? PHP_INT_MAX],
            ];
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'tax_query'      => $tax_query,
        ];

        if ($meta_query) {
            $args['meta_query'] = array_merge([['relation' => 'AND']], $meta_query);
        }

        if ($params['search']) {
            $args['s'] = $params['search'];
        }

        if ($params['on_sale'] && function_exists('wc_get_product_ids_on_sale')) {
            $sale_ids          = wc_get_product_ids_on_sale();
            $args['post__in'] = ! empty($sale_ids) ? $sale_ids : [0];
        }

        return $this->apply_ordering($args, $params['orderby']);
    }

    private function apply_ordering(array $args, string $orderby): array
    {
        switch ($orderby) {
            case 'popularity':
                $args['meta_key'] = 'total_sales';
                $args['orderby']  = 'meta_value_num';
                $args['order']    = 'DESC';
                break;

            case 'rating':
                $args['meta_key'] = '_wc_average_rating';
                $args['orderby']  = 'meta_value_num';
                $args['order']    = 'DESC';
                break;

            case 'date':
                $args['orderby'] = 'date';
                $args['order']   = 'DESC';
                break;

            case 'price':
                $args['meta_key'] = '_price';
                $args['orderby']  = 'meta_value_num';
                $args['order']    = 'ASC';
                break;

            case 'price-desc':
                $args['meta_key'] = '_price';
                $args['orderby']  = 'meta_value_num';
                $args['order']    = 'DESC';
                break;

            case 'title':
                $args['orderby'] = 'title';
                $args['order']   = 'ASC';
                break;

            default:
                $args['orderby'] = 'menu_order';
                $args['order']   = 'ASC';
                break;
        }

        return $args;
    }

    // ─── Filter pool queries ───────────────────────────────────────────────

    /**
     * Returns all matching product IDs for a given context + filters.
     * When $exclude_taxonomy is set, that taxonomy's active filter is omitted.
     * This is used for faceted filter counts ("show counts as if this filter weren't applied").
     */
    private function pool_product_ids(array $params, array $context_tax_query, ?string $exclude_taxonomy): array
    {
        $filters = $params['filters'];

        if ($exclude_taxonomy !== null) {
            unset($filters[$exclude_taxonomy]);
        }

        $tax_query      = $context_tax_query;
        $filter_clauses = $this->build_filter_tax_query($filters, $params['filter_operators']);

        if ($filter_clauses) {
            $tax_query = array_merge($tax_query, $filter_clauses);
        }

        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }

        $meta_query = [];

        if ($params['stock_status']) {
            $meta_query[] = ['key' => '_stock_status', 'value' => $params['stock_status'], 'compare' => '='];
        }

        if ($params['min_price'] !== null || $params['max_price'] !== null) {
            $meta_query[] = [
                'key'     => '_price',
                'compare' => 'BETWEEN',
                'type'    => 'DECIMAL',
                'value'   => [$params['min_price'] ?? 0, $params['max_price'] ?? PHP_INT_MAX],
            ];
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => $tax_query,
        ];

        if ($meta_query) {
            $args['meta_query'] = array_merge([['relation' => 'AND']], $meta_query);
        }

        if ($params['search']) {
            $args['s'] = $params['search'];
        }

        if ($params['on_sale'] && function_exists('wc_get_product_ids_on_sale')) {
            $sale_ids          = wc_get_product_ids_on_sale();
            $args['post__in'] = ! empty($sale_ids) ? $sale_ids : [0];
        }

        return (new \WP_Query($args))->posts;
    }

    /**
     * Returns product IDs matching only the context tax query — no user filters.
     * Used by /filter-terms to count terms in a context.
     */
    private function pool_product_ids_raw(array $context_tax_query): array
    {
        return (new \WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => $context_tax_query,
        ]))->posts;
    }

    // ─── Counting queries (wpdb GROUP BY — one query per taxonomy) ─────────

    private function count_terms_by_product_ids(string $taxonomy, array $product_ids): array
    {
        if (empty($product_ids)) {
            return [];
        }

        global $wpdb;

        $ids_sql = implode(',', array_map('intval', $product_ids));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.slug, COUNT(DISTINCT tr.object_id) AS count
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                 WHERE tr.object_id IN ({$ids_sql})
                 AND tt.taxonomy = %s
                 GROUP BY t.slug",
                $taxonomy
            )
        );
        // phpcs:enable

        $counts = [];

        foreach ($results as $row) {
            $counts[$row->slug] = (int) $row->count;
        }

        return $counts;
    }

    private function price_range_for_ids(array $product_ids): array
    {
        if (empty($product_ids)) {
            return ['min' => null, 'max' => null];
        }

        global $wpdb;

        $ids_sql = implode(',', array_map('intval', $product_ids));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            "SELECT MIN(CAST(meta_value AS DECIMAL(10,2))) AS min_price,
                    MAX(CAST(meta_value AS DECIMAL(10,2))) AS max_price
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$ids_sql})
             AND meta_key = '_price'
             AND meta_value != ''"
        );
        // phpcs:enable

        return [
            'min' => ($row && $row->min_price !== null) ? (float) $row->min_price : null,
            'max' => ($row && $row->max_price !== null) ? (float) $row->max_price : null,
        ];
    }

    private function availability_counts(array $product_ids): array
    {
        if (empty($product_ids)) {
            return ['in_stock_count' => 0, 'on_sale_count' => 0];
        }

        global $wpdb;

        $ids_sql = implode(',', array_map('intval', $product_ids));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $in_stock = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id)
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$ids_sql})
             AND meta_key = '_stock_status'
             AND meta_value = 'instock'"
        );

        $on_sale = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id)
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$ids_sql})
             AND meta_key = '_sale_price'
             AND meta_value != ''
             AND CAST(meta_value AS DECIMAL(10,2)) > 0"
        );
        // phpcs:enable

        return [
            'in_stock_count' => $in_stock,
            'on_sale_count'  => $on_sale,
        ];
    }

    // ─── Response formatters ───────────────────────────────────────────────

    private function format_context_block(string $taxonomy, string $term): array
    {
        if (! $taxonomy || ! $term) {
            return ['taxonomy' => null, 'label' => null, 'term' => null];
        }

        $tax_object  = get_taxonomy($taxonomy);
        $term_object = get_term_by('slug', $term, $taxonomy);

        if (! $term_object instanceof WP_Term) {
            return [
                'taxonomy' => $taxonomy,
                'label'    => $tax_object ? $tax_object->label : null,
                'term'     => null,
            ];
        }

        $link = get_term_link($term_object);

        return [
            'taxonomy' => $taxonomy,
            'label'    => $tax_object ? $tax_object->label : $taxonomy,
            'term'     => [
                'id'          => $term_object->term_id,
                'name'        => $term_object->name,
                'slug'        => $term_object->slug,
                'taxonomy'    => $taxonomy,
                'description' => $term_object->description,
                'parent'      => $term_object->parent,
                'count'       => $term_object->count,
                'link'        => is_wp_error($link) ? null : $link,
                'image'       => $this->term_image_full($term_object),
            ],
        ];
    }

    private function format_request_block(array $params): array
    {
        $active_filters = [];

        foreach ($params['filters'] as $taxonomy => $slugs) {
            $active_filters['filter_' . $taxonomy] = $slugs;
        }

        if ($params['min_price'] !== null) {
            $active_filters['min_price'] = (string) $params['min_price'];
        }

        if ($params['max_price'] !== null) {
            $active_filters['max_price'] = (string) $params['max_price'];
        }

        if ($params['stock_status']) {
            $active_filters['stock_status'] = $params['stock_status'];
        }

        if ($params['on_sale']) {
            $active_filters['on_sale'] = true;
        }

        return [
            'page'     => $params['page'],
            'per_page' => $params['per_page'],
            'orderby'  => $params['orderby'],
            'order'    => $params['order'],
            'search'   => $params['search'] ?: null,
            'filters'  => $active_filters,
        ];
    }

    private function format_product_card(WC_Product $product): array
    {
        $id = $product->get_id();

        return [
            'id'             => $id,
            'name'           => $product->get_name(),
            'slug'           => $product->get_slug(),
            'type'           => $product->get_type(),
            'permalink'      => $product->get_permalink(),
            'sku'            => $product->get_sku(),
            'price'          => $product->get_price(),
            'regular_price'  => $product->get_regular_price(),
            'sale_price'     => $product->get_sale_price(),
            'price_html'     => $product->get_price_html(),
            'on_sale'        => $product->is_on_sale(),
            'stock_status'   => $product->get_stock_status(),
            'in_stock'       => $product->is_in_stock(),
            'average_rating' => $product->get_average_rating(),
            'rating_count'   => $product->get_rating_count(),
            'brand'          => $this->first_term($id, 'brand'),
            'image'          => $product->get_image_id() ? $this->image($product->get_image_id()) : null,
            'categories'     => $this->terms($id, 'product_cat'),
            'tags'           => $this->terms($id, 'product_tag'),
            // 'taxonomies'  => $this->listing_taxonomies($id), // uncomment when filter drawer needs per-product taxonomy data
        ];
    }

    private function listing_taxonomies(int $product_id): array
    {
        $keys = ['brand', 'product_cat', 'skin-type', 'age-range', 'keywords', 'pa_colors', 'pa_size', 'pa_variant'];
        $data = [];

        foreach ($keys as $taxonomy) {
            if (! taxonomy_exists($taxonomy)) {
                $data[$taxonomy] = [];
                continue;
            }

            $terms          = get_the_terms($product_id, $taxonomy);
            $data[$taxonomy] = [];

            if ($terms && ! is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $data[$taxonomy][] = [
                        'id'   => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    ];
                }
            }
        }

        return $data;
    }

    private function format_filters_block(array $params, array $context_tax_query): array
    {
        $taxonomies = $this->filterable_taxonomies();

        // Full pool: context + all active filters (used for price range and availability).
        // Reused for taxonomies that have no active filter — no extra query needed.
        $full_pool = $this->pool_product_ids($params, $context_tax_query, null);

        return [
            'active'       => $this->format_active_filters($params),
            'available'    => $this->available_filters_with_counts($params, $context_tax_query, $taxonomies, $full_pool),
            'price_range'  => $this->price_range_for_ids($full_pool),
            'availability' => $this->availability_counts($full_pool),
            'sorting'      => $this->sort_options_block($params['orderby']),
            'per_page'     => $this->per_page_block($params['per_page']),
        ];
    }

    private function format_active_filters(array $params): array
    {
        $active = [];

        foreach ($params['filters'] as $taxonomy => $slugs) {
            $tax_object = get_taxonomy($taxonomy);
            $tax_label  = $tax_object ? $tax_object->label : ucfirst(str_replace(['-', '_'], ' ', $taxonomy));

            foreach ($slugs as $slug) {
                $term = get_term_by('slug', $slug, $taxonomy);
                $active[] = [
                    'key'   => 'filter_' . $taxonomy,
                    'value' => $slug,
                    'label' => $tax_label . ': ' . ($term instanceof WP_Term ? $term->name : $slug),
                ];
            }
        }

        if ($params['stock_status']) {
            $labels  = ['instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'On backorder'];
            $active[] = [
                'key'   => 'stock_status',
                'value' => $params['stock_status'],
                'label' => $labels[$params['stock_status']] ?? $params['stock_status'],
            ];
        }

        if ($params['on_sale']) {
            $active[] = ['key' => 'on_sale', 'value' => true, 'label' => 'On sale'];
        }

        if ($params['min_price'] !== null || $params['max_price'] !== null) {
            $active[] = [
                'key'   => 'price',
                'value' => [$params['min_price'], $params['max_price']],
                'label' => 'Price: ' . ($params['min_price'] ?? 0) . ' - ' . ($params['max_price'] ?? '∞'),
            ];
        }

        return $active;
    }

    private function available_filters_with_counts(array $params, array $context_tax_query, array $taxonomies, array $full_pool): array
    {
        $available = [];

        foreach ($taxonomies as $taxonomy => $tax_object) {
            $has_active_filter = isset($params['filters'][$taxonomy]);

            // If this taxonomy has an active filter, compute a pool without it (faceted count).
            // Otherwise reuse the full pool — no extra query needed.
            $pool_ids = $has_active_filter
                ? $this->pool_product_ids($params, $context_tax_query, $taxonomy)
                : $full_pool;

            if (empty($pool_ids)) {
                continue;
            }

            $counts    = $this->count_terms_by_product_ids($taxonomy, $pool_ids);
            $all_terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true, 'number' => 0]);

            if (is_wp_error($all_terms) || empty($all_terms)) {
                continue;
            }

            $items = [];

            foreach ($all_terms as $term) {
                if (! $term instanceof WP_Term) {
                    continue;
                }

                $count = $counts[$term->slug] ?? 0;

                if ($count < 1) {
                    continue;
                }

                $link = get_term_link($term);
                $item = [
                    'id'     => $term->term_id,
                    'name'   => $term->name,
                    'slug'   => $term->slug,
                    'count'  => $count,
                    'parent' => $term->parent,
                    'link'   => is_wp_error($link) ? null : $link,
                    'image'  => $this->term_image($term),
                ];

                $color = (string) get_term_meta($term->term_id, 'wpcvs_color', true);
                if ($color) {
                    $item['color'] = $color;
                }

                $items[] = $item;
            }

            if (empty($items)) {
                continue;
            }

            $available[$taxonomy] = [
                'label'        => $tax_object->label,
                'taxonomy'     => $taxonomy,
                'hierarchical' => (bool) $tax_object->hierarchical,
                'terms'        => $items,
            ];
        }

        return $available;
    }

    private function sort_options_block(string $current): array
    {
        return [
            'current' => $current,
            'options' => [
                ['key' => 'menu_order', 'label' => __('Default sorting', 'herlan-rest-api')],
                ['key' => 'popularity', 'label' => __('Sort by popularity', 'herlan-rest-api')],
                ['key' => 'rating',     'label' => __('Sort by average rating', 'herlan-rest-api')],
                ['key' => 'date',       'label' => __('Sort by latest', 'herlan-rest-api')],
                ['key' => 'price',      'label' => __('Sort by price: low to high', 'herlan-rest-api')],
                ['key' => 'price-desc', 'label' => __('Sort by price: high to low', 'herlan-rest-api')],
            ],
        ];
    }

    private function per_page_block(int $current): array
    {
        return [
            'current' => $current,
            'options' => [12, 24, 36, 48],
        ];
    }

    private function format_pagination_block(\WP_Query $query, int $page, int $per_page): array
    {
        $total       = (int) $query->found_posts;
        $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;

        return [
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => $total_pages,
            'has_more'    => $page < $total_pages,
        ];
    }

    // ─── Shared utilities ──────────────────────────────────────────────────

    private function filterable_taxonomies(): array
    {
        $excluded  = ['product_type', 'product_visibility', 'product_shipping_class', 'pos_product_visibility'];
        $preferred = ['product_cat', 'brand', 'product_tag', 'keywords', 'skin-type', 'age-range'];
        $all       = get_object_taxonomies('product', 'objects');
        $result    = [];

        foreach ($preferred as $taxonomy) {
            if (isset($all[$taxonomy]) && ! in_array($taxonomy, $excluded, true)) {
                $result[$taxonomy] = $all[$taxonomy];
            }
        }

        foreach ($all as $taxonomy => $object) {
            if (isset($result[$taxonomy]) || in_array($taxonomy, $excluded, true)) {
                continue;
            }

            if ($object->public || str_starts_with($taxonomy, 'pa_')) {
                $result[$taxonomy] = $object;
            }
        }

        return apply_filters('herlan_rest_api_filterable_taxonomies', $result);
    }

    private function image(int $image_id): array
    {
        return [
            'id'  => $image_id,
            'src' => wp_get_attachment_url($image_id),
            'alt' => (string) get_post_meta($image_id, '_wp_attachment_image_alt', true),
        ];
    }

    private function terms(int $product_id, string $taxonomy): array
    {
        $terms = get_the_terms($product_id, $taxonomy);
        $data  = [];

        if ($terms && ! is_wp_error($terms)) {
            foreach ($terms as $term) {
                $data[] = ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug];
            }
        }

        return $data;
    }

    private function first_term(int $product_id, string $taxonomy): ?array
    {
        $terms = $this->terms($product_id, $taxonomy);
        return $terms[0] ?? null;
    }

    private function term_image(WP_Term $term): ?string
    {
        $image_id = absint(get_term_meta($term->term_id, 'wpcvs_image', true));
        return $image_id ? wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail') : null;
    }

    private function term_image_full(WP_Term $term): ?array
    {
        $image_id = absint(get_term_meta($term->term_id, 'wpcvs_image', true));

        if (! $image_id) {
            $image_id = absint(get_term_meta($term->term_id, 'thumbnail_id', true));
        }

        if (! $image_id) {
            return null;
        }

        return [
            'id'  => $image_id,
            'src' => wp_get_attachment_image_url($image_id, 'full') ?: wp_get_attachment_url($image_id),
            'alt' => (string) get_post_meta($image_id, '_wp_attachment_image_alt', true),
        ];
    }
}
