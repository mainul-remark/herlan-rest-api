<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WC_Product;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Server;
use WP_Term;

if (! defined('ABSPATH')) {
    exit;
}

final class BlogController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/posts', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'index'],
            'permission_callback' => '__return_true',
            'args'                => [
                'category' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'description'       => 'Filter by category term ID.',
                ],
                'tag' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'description'       => 'Filter by tag term ID.',
                ],
                'page' => [
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 1,
                    'minimum'  => 1,
                ],
                'per_page' => [
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 10,
                    'minimum'  => 1,
                    'maximum'  => 50,
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/posts/categories', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'categories'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/posts/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'show'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => static fn ($value) => is_numeric($value) && absint($value) > 0,
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/posts/slug/(?P<slug>[a-zA-Z0-9_-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'show_by_slug'],
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);
    }

    public function index(WP_REST_Request $request)
    {
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = min(50, max(1, (int) $request->get_param('per_page')));
        $category = absint($request->get_param('category'));
        $tag      = absint($request->get_param('tag'));

        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'no_found_rows'  => false,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $tax_query = [];

        if ($category) {
            $tax_query[] = [
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => $category,
            ];
        }

        if ($tag) {
            $tax_query[] = [
                'taxonomy' => 'post_tag',
                'field'    => 'term_id',
                'terms'    => $tag,
            ];
        }

        if (! empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        $query = new \WP_Query($args);
        $posts = [];

        foreach ($query->posts as $post) {
            if ($post instanceof WP_Post) {
                $posts[] = $this->format_post_card($post);
            }
        }

        $total       = (int) $query->found_posts;
        $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;

        wp_reset_postdata();

        return Response::success([
            'posts'      => $posts,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $total_pages,
                'has_more'    => $page < $total_pages,
            ],
        ]);
    }

    public function categories()
    {
        $terms = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return Response::success(['categories' => []]);
        }

        $categories = array_map(function (WP_Term $term): array {
            $link = get_term_link($term);
            return [
                'id'          => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'count'       => (int) $term->count,
                'parent'      => $term->parent,
                'link'        => is_wp_error($link) ? null : $link,
            ];
        }, $terms);

        return Response::success(['categories' => array_values($categories)]);
    }

    public function show(WP_REST_Request $request)
    {
        $post = get_post(absint($request->get_param('id')));

        if (! $post instanceof WP_Post || $post->post_type !== 'post' || $post->post_status !== 'publish') {
            return new WP_Error('herlan_post_not_found', __('Post not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        return Response::success($this->format_post($post));
    }

    public function show_by_slug(WP_REST_Request $request)
    {
        $slug = (string) $request->get_param('slug');
        $post = get_page_by_path($slug, OBJECT, 'post');

        if (! $post instanceof WP_Post || $post->post_status !== 'publish') {
            return new WP_Error('herlan_post_not_found', __('Post not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        return Response::success($this->format_post($post));
    }

    private function format_post_card(WP_Post $post): array
    {
        $thumbnail_id = (int) get_post_thumbnail_id($post->ID);
        $author_id    = (int) $post->post_author;

        return [
            'id'             => $post->ID,
            'title'          => get_the_title($post),
            'slug'           => $post->post_name,
            'excerpt'        => $this->post_excerpt($post),
            'date'           => get_the_date('c', $post),
            'date_modified'  => get_the_modified_date('c', $post),
            'permalink'      => get_permalink($post),
            'reading_time'   => $this->reading_time($post->post_content),
            'featured_image' => $thumbnail_id ? $this->attachment_data($thumbnail_id) : null,
            'author'         => [
                'id'   => $author_id,
                'name' => get_the_author_meta('display_name', $author_id),
            ],
            'categories'     => $this->post_terms($post->ID, 'category'),
            'tags'           => $this->post_terms($post->ID, 'post_tag'),
        ];
    }

    private function format_post(WP_Post $post): array
    {
        $data                      = $this->format_post_card($post);
        $data['content']           = apply_filters('the_content', $post->post_content);
        $data['custom_fields']     = $this->custom_fields($post->ID);
        $data['relevant_products'] = $this->relevant_products($post->ID);

        return $data;
    }

    private function post_excerpt(WP_Post $post): string
    {
        if (! empty($post->post_excerpt)) {
            return wp_kses_post($post->post_excerpt);
        }

        return wp_trim_words(strip_shortcodes(wp_strip_all_tags($post->post_content)), 25);
    }

    private function post_terms(int $post_id, string $taxonomy): array
    {
        $terms = get_the_terms($post_id, $taxonomy);

        if (! $terms || is_wp_error($terms)) {
            return [];
        }

        return array_values(array_map(function (WP_Term $term): array {
            $link = get_term_link($term);
            return [
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'link' => is_wp_error($link) ? null : $link,
            ];
        }, $terms));
    }

    private function attachment_data(int $id): ?array
    {
        $src = wp_get_attachment_image_src($id, 'full');

        if (! $src) {
            return null;
        }

        return [
            'id'     => $id,
            'src'    => $src[0],
            'width'  => (int) $src[1],
            'height' => (int) $src[2],
            'alt'    => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        ];
    }

    private function relevant_products(int $post_id): array
    {
        if (! function_exists('wc_get_product')) {
            return [];
        }

        $ids = function_exists('get_field') ? get_field('relevant_products', $post_id) : get_post_meta($post_id, 'relevant_products', true);

        if (! is_array($ids) || empty($ids)) {
            return [];
        }

        $products = [];

        foreach ($ids as $product_id) {
            $product = wc_get_product(absint($product_id));

            if (! $product instanceof WC_Product || $product->get_status() !== 'publish') {
                continue;
            }

            $image_id  = $product->get_image_id();
            $image_src = $image_id ? wp_get_attachment_image_src($image_id, 'full') : null;

            $products[] = [
                'id'             => $product->get_id(),
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
                'average_rating' => $product->get_average_rating(),
                'rating_count'   => $product->get_rating_count(),
                'image'          => $image_src ? [
                    'id'     => $image_id,
                    'src'    => $image_src[0],
                    'width'  => (int) $image_src[1],
                    'height' => (int) $image_src[2],
                    'alt'    => (string) get_post_meta($image_id, '_wp_attachment_image_alt', true),
                ] : null,
            ];
        }

        return $products;
    }

    private function custom_fields(int $post_id): array
    {
        if (function_exists('get_fields')) {
            $fields = get_fields($post_id);
            return is_array($fields) ? $fields : [];
        }

        return [];
    }

    private function reading_time(string $content): int
    {
        $word_count = str_word_count(strip_tags($content));
        return max(1, (int) ceil($word_count / 200));
    }
}
