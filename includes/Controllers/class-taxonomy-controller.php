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
