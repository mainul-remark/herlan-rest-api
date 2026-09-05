<?php

namespace HerlanRestApi\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the set of taxonomies exposed as filters, sourced from the site's
 * actual "WCAPF – Ajax Product Filter for WooCommerce" configuration (the
 * plugin that really drives filtering on herlan.com — the theme's own
 * herlan_archive_filter_* system is disabled: herlan_archive_filter_settings
 * has enabled=>"0") instead of auto-discovering every public/pa_* product
 * taxonomy. That auto-discovery previously let the app filter by taxonomies
 * (e.g. keywords, un-configured pa_* attributes) with no equivalent on the
 * website. Falls back to the old auto-discovery when WCAPF isn't
 * installed/configured, so the API still works without it.
 */
final class WcapfFilters
{
    private const FALLBACK_EXCLUDED  = ['product_type', 'product_visibility', 'product_shipping_class', 'pos_product_visibility'];
    private const FALLBACK_PREFERRED = ['product_cat', 'brand', 'product_tag', 'keywords', 'skin-type', 'age-range'];

    /**
     * @return array<string, \WP_Taxonomy> taxonomy slug => taxonomy object
     */
    public static function taxonomies(): array
    {
        return Cache::remember('wcapf_filterable_taxonomies', [], 15 * MINUTE_IN_SECONDS, [self::class, 'build_taxonomies']);
    }

    public static function build_taxonomies(): array
    {
        $configured = self::configured_taxonomy_slugs();

        if (empty($configured)) {
            return self::fallback_taxonomies();
        }

        $result = [];

        foreach ($configured as $slug) {
            $tax_object = get_taxonomy($slug);

            if ($tax_object) {
                $result[$slug] = $tax_object;
            }
        }

        return apply_filters('herlan_rest_api_filterable_taxonomies', $result);
    }

    /**
     * Reads every published wcapf-form's taxonomy-type filters and returns
     * the unique set of taxonomy slugs actually configured on the site.
     * Mirrors the post_excerpt ("taxonomy>{slug}") + post_content settings
     * convention already parsed by ProductListingController::filter_form().
     */
    private static function configured_taxonomy_slugs(): array
    {
        if (! post_type_exists('wcapf-form')) {
            return [];
        }

        $form_ids = get_posts([
            'post_type'      => 'wcapf-form',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);

        if (empty($form_ids)) {
            return [];
        }

        $filter_posts = get_posts([
            'post_type'       => 'wcapf-filter',
            'post_status'     => 'publish',
            'post_parent__in' => $form_ids,
            'posts_per_page'  => -1,
            'no_found_rows'   => true,
        ]);

        $slugs = [];

        foreach ($filter_posts as $filter_post) {
            $excerpt_parts = explode('>', (string) $filter_post->post_excerpt);
            $type          = $excerpt_parts[0] ?? '';
            $taxonomy      = $excerpt_parts[1] ?? '';

            if ($type === 'taxonomy' && $taxonomy !== '') {
                $slugs[$taxonomy] = true;
            }
        }

        return array_keys($slugs);
    }

    private static function fallback_taxonomies(): array
    {
        $all    = get_object_taxonomies('product', 'objects');
        $result = [];

        foreach (self::FALLBACK_PREFERRED as $taxonomy) {
            if (isset($all[$taxonomy]) && ! in_array($taxonomy, self::FALLBACK_EXCLUDED, true)) {
                $result[$taxonomy] = $all[$taxonomy];
            }
        }

        foreach ($all as $taxonomy => $object) {
            if (isset($result[$taxonomy]) || in_array($taxonomy, self::FALLBACK_EXCLUDED, true)) {
                continue;
            }

            if ($object->public || str_starts_with($taxonomy, 'pa_')) {
                $result[$taxonomy] = $object;
            }
        }

        return apply_filters('herlan_rest_api_filterable_taxonomies', $result);
    }
}
