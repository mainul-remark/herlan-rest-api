<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Term;

if (! defined('ABSPATH')) {
    exit;
}

final class TaxonomyController extends Controller
{
    private const ORDER_BY_MAP = [
        'name'  => 'name',
        'count' => 'count',
        'id'    => 'term_id',
        'slug'  => 'slug',
    ];

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/drawer-brands-categories', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'index'],
            'permission_callback' => '__return_true',
            'args'                => [
                'hide_empty'      => ['required' => false, 'type' => 'boolean', 'default' => true],
                'categories_flat' => ['required' => false, 'type' => 'boolean', 'default' => false],
                'order'           => ['required' => false, 'type' => 'string', 'default' => 'asc', 'enum' => ['asc', 'desc']],
                'order_by'        => ['required' => false, 'type' => 'string', 'default' => 'name', 'enum' => array_keys(self::ORDER_BY_MAP)],
            ],
        ]);

        register_rest_route($this->namespace, '/taxonomies', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'all_taxonomies'],
            'permission_callback' => '__return_true',
            'args'                => [
                'hide_empty' => ['required' => false, 'type' => 'boolean', 'default' => true],
            ],
        ]);
    }

    public function all_taxonomies(WP_REST_Request $request)
    {
        $hide_empty = rest_sanitize_boolean($request->get_param('hide_empty'));
        $taxonomies = $this->get_filterable_taxonomies();
        $result     = [];

        foreach ($taxonomies as $taxonomy => $tax_object) {
            $terms = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => $hide_empty,
                'number'     => 0,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]);

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            $term_items = [];

            foreach ($terms as $term) {
                if (! $term instanceof WP_Term) {
                    continue;
                }

                $item = [
                    'id'     => $term->term_id,
                    'name'   => $term->name,
                    'slug'   => $term->slug,
                    'count'  => (int) $term->count,
                    'parent' => $term->parent,
                    'image'  => $this->term_image($term->term_id, 'thumbnail_id')
                                 ?? $this->term_image($term->term_id, 'wpcvs_image'),
                ];

                $color = (string) get_term_meta($term->term_id, 'wpcvs_color', true);
                if ($color) {
                    $item['color'] = $color;
                }

                $term_items[] = $item;
            }

            $result[] = [
                'taxonomy'     => $taxonomy,
                'label'        => $tax_object->label,
                'filter_key'   => 'filter_' . $taxonomy,
                'hierarchical' => (bool) $tax_object->hierarchical,
                'terms'        => $term_items,
            ];
        }

        return Response::success(['taxonomies' => $result]);
    }

    public function index(WP_REST_Request $request)
    {
        $hide_empty      = rest_sanitize_boolean($request->get_param('hide_empty'));
        $categories_flat = rest_sanitize_boolean($request->get_param('categories_flat'));
        $order           = strtoupper((string) $request->get_param('order'));
        $order_by        = self::ORDER_BY_MAP[$request->get_param('order_by')] ?? 'name';

        $query_args = [
            'hide_empty' => $hide_empty,
            'order'      => $order,
            'orderby'    => $order_by,
            'number'     => 0,
        ];

        $categories = $this->get_categories($query_args, $categories_flat);
        $brands     = $this->get_brands($query_args);

        return Response::success([
            'categories'       => $categories['items'],
            'total_categories' => $categories['total'],
            'brands'           => $brands['items'],
            'total_brands'     => $brands['total'],
        ]);
    }

    private function get_categories(array $args, bool $flat): array
    {
        $terms = get_terms(array_merge($args, ['taxonomy' => 'product_cat']));

        if (is_wp_error($terms) || empty($terms)) {
            return ['items' => [], 'total' => 0];
        }

        $formatted = array_map([$this, 'format_category'], $terms);

        return [
            'items' => $flat ? $formatted : $this->build_tree($formatted),
            'total' => count($formatted),
        ];
    }

    private function get_brands(array $args): array
    {
        if (! taxonomy_exists('brand')) {
            return ['items' => [], 'total' => 0];
        }

        $terms = get_terms(array_merge($args, ['taxonomy' => 'brand']));

        if (is_wp_error($terms) || empty($terms)) {
            return ['items' => [], 'total' => 0];
        }

        $formatted = array_map([$this, 'format_brand'], $terms);

        return ['items' => $formatted, 'total' => count($formatted)];
    }

    private function format_category(WP_Term $term): array
    {
        $link = get_term_link($term);

        return [
            'id'          => $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'count'       => (int) $term->count,
            'parent'      => $term->parent,
            'link'        => is_wp_error($link) ? null : $link,
            'image'       => $this->term_image($term->term_id, 'thumbnail_id'),
            'children'    => [],
        ];
    }

    private function format_brand(WP_Term $term): array
    {
        $link = get_term_link($term);

        return [
            'id'          => $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'count'       => (int) $term->count,
            'link'        => is_wp_error($link) ? null : $link,
            'image'       => $this->term_image($term->term_id, 'logo'),
        ];
    }

    private function build_tree(array $items, int $parent_id = 0): array
    {
        $tree = [];

        foreach ($items as $item) {
            if ((int) $item['parent'] === $parent_id) {
                $item['children'] = $this->build_tree($items, $item['id']);
                $tree[]           = $item;
            }
        }

        return $tree;
    }

    private function get_filterable_taxonomies(): array
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

    private function term_image(int $term_id, string $meta_key): ?array
    {
        $image_id = absint(get_term_meta($term_id, $meta_key, true));

        if (! $image_id) {
            return null;
        }

        return [
            'id'  => $image_id,
            'src' => wp_get_attachment_url($image_id),
            'alt' => (string) get_post_meta($image_id, '_wp_attachment_image_alt', true),
        ];
    }
}
