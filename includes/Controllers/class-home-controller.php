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
        ]);
    }

    private function hero_slider(): array
    {
        $raw_items        = get_field('slider_v2_items', 'option') ?: [];
        $autoplay_duration = (int) (get_field('slider_v2_duration', 'option') ?: 5000);

        $items = array_map(function (array $item): array {
            $type = $item['type'] ?? 'image';

            if ($type === 'video') {
                return [
                    'type'      => 'video',
                    'url'       => $item['url'] ?? null,
                    'video_url' => $item['video_file'] ?? null,
                ];
            }

            $image_id        = $item['image'] ?? 0;
            $mobile_image_id = $item['mobile_image'] ?: $image_id;

            return [
                'type'         => 'image',
                'url'          => $item['url'] ?? null,
                'image'        => $this->attachment_data($image_id),
                'mobile_image' => $this->attachment_data($mobile_image_id),
            ];
        }, $raw_items);

        return [
            'autoplay_duration' => $autoplay_duration,
            'items'             => $items,
        ];
    }

    private function promotional_block(): array
    {
        $block   = get_field('promotional_block', 'option') ?: [];
        $enabled = ! empty($block['status']);

        if (! $enabled) {
            return ['enabled' => false, 'posts' => []];
        }

        $query = new \WP_Query([
            'post_type'      => 'promotional_posts',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ]);

        $posts = array_map(function (int $id): array {
            $thumb_id = (int) get_post_thumbnail_id($id);
            return [
                'id'       => $id,
                'title'    => get_field('title', $id) ?: get_the_title($id),
                'subtitle' => get_field('subtitle', $id) ?: null,
                'image'    => $thumb_id ? $this->attachment_data($thumb_id) : null,
                'url'      => get_field('external_url', $id) ?: null,
            ];
        }, $query->posts);

        return ['enabled' => true, 'posts' => $posts];
    }

    private function campaign_shortcuts(): array
    {
        $enabled = (bool) get_field('campaign_tags_is_active', 'option');
        $raw     = get_field('campaign_tags_items', 'option') ?: [];

        $bg_image_raw        = get_field('field_694a33e959829', 'option');
        $bg_image_mobile_raw = get_field('field_694a56f580277', 'option');

        $items = array_map(fn(array $item): array => [
            'name' => $item['name'] ?? '',
            'url'  => $item['url'] ?? null,
        ], $raw);

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

        $items = array_map(fn(array $g): array => [
            'title'        => $g['group_title'] ?? '',
            'product_tag'  => $g['group_product_tag'] ?? null,
            'featured_tag' => $g['group_featured_tag'] ?? null,
        ], $groups);

        return [
            'enabled'       => ! empty($groups),
            'section_title' => $section_title,
            'groups'        => $items,
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

            $result[] = [
                'title'            => $campaign['title'] ?? '',
                'product_tag'      => $tag_slug,
                'tag_url'          => $tag_url,
                'see_all_text'     => $campaign['see_all_text'] ?? null,
                'background_image' => $bg_url,
            ];
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
            return [
                'name'  => $item['name'] ?? '',
                'url'   => $item['url'] ?? null,
                'image' => $image_id ? $this->attachment_data((int) $image_id) : null,
            ];
        }, $raw);

        return ['enabled' => $enabled, 'items' => $items];
    }

    private function product_sliders(): array
    {
        $raw = get_field('product_sliders', 'option') ?: [];

        return array_map(function (array $slider): array {
            $bg_id  = $slider['background_image'] ?? null;
            $bg_url = $bg_id ? wp_get_attachment_image_url((int) $bg_id, 'full') : null;

            return [
                'title'            => $slider['title'] ?? '',
                'product_type'     => $slider['product_type'] ?? null,
                'taxonomy_type'    => $slider['taxonomy_type'] ?? null,
                'term_slug'        => $slider['term_slug'] ?? null,
                'url'              => $slider['url'] ?? null,
                'background_image' => $bg_url,
            ];
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
