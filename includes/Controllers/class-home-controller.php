<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WP_REST_Server;

if (! defined('ABSPATH')) {
    exit;
}

final class HomeController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/home', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'index'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function index()
    {
        return Response::success([
            'hero_slider'        => $this->hero_slider(),
            'promotional_block'  => $this->promotional_block(),
            'campaign_shortcuts' => $this->campaign_shortcuts(),
            'product_groups'     => $this->product_groups(),
            'campaigns'          => $this->campaigns(),
            'categories_block'   => $this->categories_block(),
            'custom_tags'        => $this->custom_tags(),
            'product_sliders'    => $this->product_sliders(),
            'home_video'         => $this->home_video(),
            'brands'             => $this->brands(),
            'shop_by_category'   => $this->shop_by_category(),
        ]);
    }

    private function hero_slider(): array
    {
        $raw_items        = get_field('slider_v2_items', 'option') ?: [];
        $autoplay_duration = (int) (get_field('slider_v2_duration', 'option') ?: 5000);
        $items = array_map(function (array $item): array {
            $type = $item['type'] ?? 'image';
            $url  = $item['url'] ?? null;

            if ($type === 'video') {
                return [
                    'type'      => 'video',
                    'url'       => $url,
                    'post_type' => $item['post_type'] ?: null,
                    'video_url' => $item['video_file'] ?? null,
                    'term'      => $this->resolve_term_from_url($url),
                ];
            }

            $image_id        = $item['image'] ?? 0;
            $mobile_image_id = $item['mobile_image'] ?: $image_id;

            return [
                'type'         => 'image',
                'url'          => $url,
                'post_type'    => $item['post_type'] ?: null,
                'image'        => $this->attachment_data($image_id),
                'mobile_image' => $this->attachment_data($mobile_image_id),
                'term'         => $this->resolve_term_from_url($url),
            ];
        }, $raw_items);

        return [
            'autoplay_duration' => $autoplay_duration,
            'items'             => $items,
        ];
    }

//    private function promotional_block(): array
//    {
//        $block   = get_field('promotional_block', 'option') ?: [];
//        $enabled = ! empty($block['status']);
//
//        if (! $enabled) {
//            return ['enabled' => false, 'posts' => []];
//        }
//
//        $query = new \WP_Query([
//            'post_type'      => 'promotional_posts',
//            'posts_per_page' => -1,
//            'post_status'    => 'publish',
//            'fields'         => 'ids',
//            'orderby'        => 'menu_order',
//            'order'          => 'ASC',
//        ]);
//
//        $posts = array_map(function (int $id): array {
//            $thumb_id = (int) get_post_thumbnail_id($id);
////            return [
////                'id'       => $id,
////                'title'    => get_field('title', $id) ?: get_the_title($id),
////                'subtitle' => get_field('subtitle', $id) ?: null,
////                'image'    => $thumb_id ? $this->attachment_data($thumb_id) : null,
////                'url'      => get_field('external_url', $id) ?: null,
////            ];
//            $url      = get_field('external_url', $id) ?: null;
//            $parsed   = $this->resolve_shortcut_filters($url);
//
//            return array_merge([
//                'id'       => $id,
//                'title'    => get_field('title', $id) ?: get_the_title($id),
//                'subtitle' => get_field('subtitle', $id) ?: null,
//                'image'    => $thumb_id ? $this->attachment_data($thumb_id) : null,
//                'url'      => $url,
//            ], $parsed);
//        }, $query->posts);
//
//        return ['enabled' => true, 'posts' => $posts];
//    }

    private function promotional_block(): array
    {
        $block   = get_field('promotional_block', 'option') ?: [];
        $enabled = ! empty($block['status']);

        if (! $enabled) {
            return ['enabled' => false, 'posts' => []];
        }

        $items = get_option('herlan_promotional_posts', []);

        if (! is_array($items) || empty($items)) {
            return ['enabled' => true, 'posts' => []];
        }

        $items = array_values(array_filter($items, static function ($item): bool {
            return is_array($item) && (($item['active'] ?? '0') === '1');
        }));

        $posts = array_map(function (array $item): array {
            $title     = sanitize_text_field($item['title'] ?? '');
            $subtitle  = sanitize_text_field($item['subtitle'] ?? '');
            $image_url = esc_url_raw($item['image_url'] ?? '');
            $url       = esc_url_raw($item['post_url'] ?? '') ?: null;
            $parsed    = $this->resolve_shortcut_filters($url);

            return array_merge([
                'id'       => 0,
                'title'    => $title,
                'subtitle' => $subtitle !== '' ? $subtitle : null,
                'image'    => $image_url !== '' ? [
                    'id'     => 0,
                    'src'    => $image_url,
                    'width'  => 0,
                    'height' => 0,
                    'alt'    => '',
                ] : null,
                'url'      => $url,
            ], $parsed);
        }, $items);

        return ['enabled' => true, 'posts' => $posts];
    }

    private function campaign_shortcuts(): array
    {
        $enabled = (bool) get_field('campaign_tags_is_active', 'option');
        $raw     = get_field('campaign_tags_items', 'option') ?: [];

        $bg_image_raw        = get_field('field_694a33e959829', 'option');
        $bg_image_mobile_raw = get_field('field_694a56f580277', 'option');

        $items = array_map(function (array $item): array {
            $url = $item['url'] ?? null;

            return array_merge([
                'name'      => $item['name'] ?? '',
                'url'       => $url,
                'post_type' => $item['post_type'] ?: null,
            ], $this->resolve_shortcut_filters($url));
        }, $raw);

        return [
            'enabled'              => $enabled,
            'background_image'     => $this->resolve_image_url($bg_image_raw),
            'mobile_background_image' => $this->resolve_image_url($bg_image_mobile_raw),
            'items'                => $items,
        ];
    }

    private function product_groups(): array
    {
        $groups        = get_field('product_groups', 'option') ?: [];
        $section_title = (string) (get_field('product_groups_section_title', 'option') ?: '');

        $items = [];

        foreach ($groups as $g) {
            $tag_slug      = $g['group_product_tag'] ?? '';
            $featured_slug = $g['group_featured_tag'] ?? '';
            $title         = $g['group_title'] ?? '';

            if (empty($tag_slug)) {
                continue;
            }

            $term      = get_term_by('slug', $tag_slug, 'product_tag');
            $term_data = null;
            $link      = null;

            if ($term && ! is_wp_error($term)) {
                $term_link = get_term_link($term);
                $link      = is_wp_error($term_link) ? null : $term_link;
                $term_data = [
                    'id'   => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'link' => $link,
                ];
                if (empty($title)) {
                    $title = $term->name;
                }
            }

            $product_ids = $this->fetch_group_product_ids($tag_slug, $featured_slug);
            $parsed      = $this->resolve_shortcut_filters($link, 'section');

            $items[] = array_merge([
                'title'        => $title,
                'taxonomy'     => 'product_tag',
                'term'         => $term_data,
                'link'         => $link,
                'product_tag'  => $tag_slug,
                'featured_tag' => $featured_slug ?: null,
            ], $parsed, [
                'products' => $this->format_group_products($product_ids),
            ]);
        }

        return [
            'enabled'       => ! empty($items),
            'section_title' => $section_title,
            'groups'        => $items,
        ];
    }

    private function fetch_group_product_ids(string $tag_slug, string $featured_slug): array
    {
        $target     = 4;
        $base_args  = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => $target,
            'meta_query'     => [
                [
                    'key'     => '_stock_status',
                    'value'   => 'outofstock',
                    'compare' => '!=',
                ],
            ],
        ];

        $ids = [];

        // 1. Featured tag first
        if (! empty($featured_slug)) {
            $q = new \WP_Query(array_merge($base_args, [
                'tax_query' => [[
                    'taxonomy' => 'product_tag',
                    'field'    => 'slug',
                    'terms'    => $featured_slug,
                ]],
            ]));
            $ids = array_map('intval', $q->posts);
        }

        // 2. Backfill from main tag if needed
        if (count($ids) < $target) {
            $needed = $target - count($ids);
            $args   = array_merge($base_args, [
                'posts_per_page' => $needed,
                'post__not_in'   => $ids,
                'tax_query'      => [[
                    'taxonomy' => 'product_tag',
                    'field'    => 'slug',
                    'terms'    => $tag_slug,
                ]],
            ]);
            $q    = new \WP_Query($args);
            $ids  = array_merge($ids, array_map('intval', $q->posts));
        }

        return $ids;
    }

    private function format_group_products(array $product_ids): array
    {
        $products = [];

        foreach ($product_ids as $id) {
            $product = wc_get_product($id);
            if (! $product instanceof \WC_Product) {
                continue;
            }

            $image_id = (int) $product->get_image_id();

            $products[] = [
                'id'              => $id,
                'name'            => $product->get_name(),
                'image'           => $image_id ? $this->attachment_data($image_id) : null,
                'linked_products' => $this->linked_products_summary($product),
            ];
        }

        return $products;
    }

    /**
     * WPC Linked Variation group (e.g. colour/shade variants sold as separate products) for a single product.
     * Mirrors WPCleverWpclv::term()/get_linked_data() in wpc-linked-variation.php: picks whichever single
     * attribute the link group is configured to display with, in the plugin's own priority order
     * (images > swatches > dropdown > button-default), then returns each linked product's id + display
     * type + type-specific value, plus which attribute/term it was resolved from.
     */
    private function linked_products_summary(\WC_Product $product): array
    {
        if (! class_exists('WPCleverWpclv')) {
            return ['total' => 0, 'list' => []];
        }

        $link_data = \WPCleverWpclv::get_linked_data($product, 'home');

        if (empty($link_data) || ! is_array($link_data)) {
            return ['total' => 0, 'list' => []];
        }

        [$mode, $taxonomy, $attribute_label, $attribute_type] = $this->linked_products_mode($link_data);

        if ($mode === null) {
            return ['total' => 0, 'list' => []];
        }

        $link_product_ids = \WPCleverWpclv::get_linked_products($link_data, 'home');
        $link_product_ids = apply_filters(
            'wpclv_linked_products',
            array_diff(array_map('absint', $link_product_ids), [$product->get_id()]),
            $product->get_id()
        );

        $list = [];

        foreach ($link_product_ids as $link_product_id) {
            $linked_product = wc_get_product($link_product_id);

            if (! $linked_product instanceof \WC_Product) {
                continue;
            }

            $item = $this->linked_product_typed($linked_product, $mode, $taxonomy, $attribute_label, $attribute_type);

            if ($item !== null) {
                $list[] = $item;
            }
        }

        return ['total' => count($list), 'list' => $list];
    }

    /**
     * Determines the display mode ('image'|'swatch'|'dropdown'|'button'), attribute taxonomy, attribute
     * label, and WooCommerce attribute type (color|image|button) for the single attribute a WPC link
     * group is configured to display with. Priority matches WPCleverWpclv's own per-attribute checks:
     * images, then swatches (only if WPC Variation Swatches is active), then dropdown, else button.
     */
    private function linked_products_mode(array $link_data): array
    {
        $link_attributes = $link_data['attributes'] ?? [];

        if (empty($link_attributes)) {
            return [null, null, null, null];
        }

        $link_images   = $link_data['images'] ?? [];
        $link_swatches = class_exists('WPCleverWpcvs') ? ($link_data['swatches'] ?? []) : [];
        $link_dropdown = $link_data['dropdown'] ?? [];

        $selected_attr_ref = null;
        $mode = null;

        foreach (['image' => $link_images, 'swatch' => $link_swatches, 'dropdown' => $link_dropdown] as $candidate_mode => $set) {
            foreach ($link_attributes as $attr_ref) {
                if (in_array($attr_ref, $set, true)) {
                    $selected_attr_ref = $attr_ref;
                    $mode = $candidate_mode;
                    break 2;
                }
            }
        }

        if ($selected_attr_ref === null) {
            $selected_attr_ref = $link_attributes[0];
            $mode = 'button';
        }

        $attribute = wc_get_attribute((int) str_replace('id:', '', $selected_attr_ref));

        if (! $attribute) {
            return [null, null, null, null];
        }

        $taxonomy = $attribute->slug;
        if (strpos($taxonomy, 'pa_') !== 0) {
            $taxonomy = 'pa_' . $taxonomy;
        }

        return [$mode, $taxonomy, $attribute->name, $attribute->type ?: 'select'];
    }

    /**
     * Resolves a single linked product's {id, type, color|image|label, attribute, term}.
     * Returns null when the linked product has no term for the group's attribute (dropped, same as
     * the plugin's own term-driven loop) — except in 'image' mode, which always shows the product's
     * own thumbnail regardless of term, matching the theme's loop swatch behaviour.
     */
    private function linked_product_typed(\WC_Product $product, string $mode, string $taxonomy, string $attribute_label, string $attribute_type): ?array
    {
        $id    = $product->get_id();
        $terms = wp_get_post_terms($id, $taxonomy);
        $term  = (! empty($terms) && ! is_wp_error($terms)) ? $terms[0] : null;

        if ($mode === 'image') {
            $thumbnail_id = get_post_thumbnail_id($id);
            $image_url    = $thumbnail_id
                ? wp_get_attachment_image_url($thumbnail_id, 'woocommerce_thumbnail')
                : wc_placeholder_img_src();

            return [
                'id'        => $id,
                'type'      => 'image',
                'image'     => $image_url,
                'attribute' => $attribute_label,
                'term'      => $term ? $term->name : null,
            ];
        }

        if ($term === null) {
            return null;
        }

        if ($mode === 'dropdown') {
            return [
                'id'        => $id,
                'type'      => 'dropdown',
                'label'     => $term->name,
                'attribute' => $attribute_label,
                'term'      => $term->name,
            ];
        }

        if ($mode === 'button') {
            return [
                'id'        => $id,
                'type'      => 'button',
                'label'     => $term->name,
                'attribute' => $attribute_label,
                'term'      => $term->name,
            ];
        }

        // $mode === 'swatch' — sub-type driven by the attribute's own WooCommerce type (color|image|button)
        $swatch_type = in_array($attribute_type, ['button', 'color', 'image'], true) ? $attribute_type : 'button';

        if ($swatch_type === 'color') {
            return [
                'id'        => $id,
                'type'      => 'color',
                'color'     => (string) get_term_meta($term->term_id, 'wpcvs_color', true),
                'attribute' => $attribute_label,
                'term'      => $term->name,
            ];
        }

        if ($swatch_type === 'image') {
            $image_id = get_term_meta($term->term_id, 'wpcvs_image', true);

            return [
                'id'        => $id,
                'type'      => 'image',
                'image'     => $image_id ? wp_get_attachment_thumb_url($image_id) : wc_placeholder_img_src(),
                'attribute' => $attribute_label,
                'term'      => $term->name,
            ];
        }

        return [
            'id'        => $id,
            'type'      => 'button',
            'label'     => (string) get_term_meta($term->term_id, 'wpcvs_button', true) ?: $term->name,
            'attribute' => $attribute_label,
            'term'      => $term->name,
        ];
    }

    private function campaigns(): array
    {
        $campaign_group = get_field('campaign_group_v2', 'option') ?: [];
        $raw_campaigns  = $campaign_group['campaigns'] ?? [];

        $result = [];

        foreach ($raw_campaigns as $campaign) {
            if (empty($campaign['status'])) {
                continue;
            }

            $tag_slug = sanitize_title($campaign['product_tag'] ?? '');
            $term     = $tag_slug ? get_term_by('slug', $tag_slug, 'product_tag') : null;
            $tag_url  = ($term && ! is_wp_error($term)) ? get_term_link($term) : null;

            $bg_raw = $campaign['background_image'] ?? null;
            $bg_url = null;

            if (! empty($campaign['is_active']) && $bg_raw) {
                $bg_url = $this->resolve_image_url($bg_raw);
            }

            $parsed = $this->resolve_shortcut_filters($tag_url, 'section');

            $result[] = array_merge([
                'title'            => $campaign['title'] ?? '',
                'product_tag'      => $tag_slug,
                'tag_url'          => $tag_url,
                'see_all_text'     => $campaign['see_all_text'] ?? null,
                'background_image' => $bg_url,
            ], $parsed);
        }

        return $result;
    }

    private function categories_block(): array
    {
        $block = get_field('categories_block', 'option') ?: [];
        return ['enabled' => ! empty($block['status'])];
    }

    private function custom_tags(): array
    {
        $enabled = (bool) get_field('home_custom_tags_is_active', 'option');
        $raw     = get_field('home_custom_tags_items', 'option') ?: [];

        $items = array_map(function (array $item): array {
            $image_id = $item['image'] ?? 0;
            $url      = $item['url'] ?? null;

            return array_merge([
                'name'  => $item['name'] ?? '',
                'url'   => $url,
                'image' => $image_id ? $this->attachment_data((int) $image_id) : null,
            ], $this->resolve_shortcut_filters($url));
        }, $raw);

        return ['enabled' => $enabled, 'items' => $items];
    }

    private function product_sliders(): array
    {
        $raw = get_field('product_sliders', 'option') ?: [];

        return array_map(function (array $slider): array {
            $bg_id  = $slider['background_image'] ?? null;
            $bg_url = $bg_id ? wp_get_attachment_image_url((int) $bg_id, 'full') : null;
            $url    = $slider['url'] ?? null;

            return array_merge([
                'title'            => $slider['title'] ?? '',
                'product_type'     => $slider['product_type'] ?? null,
                'taxonomy_type'    => $slider['taxonomy_type'] ?? null,
                'term_slug'        => $slider['term_slug'] ?? null,
                'url'              => $url,
                'background_image' => $bg_url,
            ], $this->resolve_shortcut_filters($url, 'section'));
        }, $raw);
    }

    private function home_video(): ?array
    {
        $video = get_field('home_video', 'option');

        if (empty($video) || empty($video['video_desktop'])) {
            return null;
        }

        return [
            'video_desktop' => $video['video_desktop'] ?? null,
            'video_mobile'  => $video['video_mobile'] ?? null,
            'poster'        => $video['poster'] ?? null,
            'link_url'      => $video['link_url'] ?? null,
        ];
    }

    private function brands(): array
    {
        if (! taxonomy_exists('brand')) {
            return ['total' => 0, 'items' => []];
        }

        $terms = get_terms([
            'taxonomy'   => 'brand',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
            'number'     => 0,
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return ['total' => 0, 'items' => []];
        }

        $items = array_map(function (\WP_Term $term): array {
            $link = get_term_link($term);
            return [
                'id'          => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'count'       => (int) $term->count,
                'link'        => is_wp_error($link) ? null : $link,
                'image'       => $this->brand_image($term->term_id),
            ];
        }, $terms);

        return ['total' => count($items), 'items' => $items];
    }

    private function brand_image(int $term_id): ?array
    {
        $image_id = absint(get_term_meta($term_id, 'logo', true));

        if (! $image_id) {
            return null;
        }

        return [
            'id'  => $image_id,
            'src' => wp_get_attachment_url($image_id),
            'alt' => (string) get_post_meta($image_id, '_wp_attachment_image_alt', true),
        ];
    }

    private function shop_by_category(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key'     => 'herlan_featured',
                    'value'   => '1',
                    'compare' => '=',
                ],
            ],
            'meta_key' => 'product_cat_custom_order',
            'orderby'  => 'meta_value_num',
            'order'    => 'ASC',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return ['taxonomy' => 'product_cat', 'total' => 0, 'items' => []];
        }

        $items = array_map(function (\WP_Term $term): array {
            $image_id = absint(get_term_meta($term->term_id, 'thumbnail_id', true));
            $link     = get_term_link($term);

            return [
                'id'    => $term->term_id,
                'name'  => $term->name,
                'slug'  => $term->slug,
                'count' => (int) $term->count,
                'link'  => is_wp_error($link) ? null : $link,
                'image' => $image_id ? $this->attachment_data($image_id) : null,
            ];
        }, $terms);

        return [
            'taxonomy' => 'product_cat',
            'total'    => count($items),
            'terms'    => $items,
        ];
    }

    private function attachment_data(int $id): ?array
    {
        if (! $id) {
            return null;
        }

        $src = wp_get_attachment_image_src($id, 'full');

        if (! $src) {
            return null;
        }

        return [
            'id'  => $id,
            'src' => $src[0],
            'width' => (int) $src[1],
            'height' => (int) $src[2],
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        ];
    }

    private function resolve_image_url(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (is_array($value)) {
            return $value['url'] ?? null;
        }

        return wp_get_attachment_image_url((int) $value, 'full') ?: null;
    }
}
