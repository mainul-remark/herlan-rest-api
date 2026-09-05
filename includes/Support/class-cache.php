<?php

namespace HerlanRestApi\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Transient-based cache for expensive read endpoints (taxonomy terms, home
 * bundle, product listings/detail). Keys are namespaced by a content version
 * that's bumped whenever a product or term changes, so cached data never
 * needs a long-lived TTL to stay correct — mirrors the theme's
 * herlan_get_home_content_version() pattern (inc/theme/home-cache.php).
 */
final class Cache
{
    private const VERSION_OPTION = 'herlan_rest_api_content_version';

    public static function boot(): void
    {
        add_action('woocommerce_update_product', [self::class, 'bump_content_version'], 20);
        add_action('save_post_product', [self::class, 'bump_content_version'], 20);
        add_action('save_post_product_variation', [self::class, 'bump_content_version'], 20);
        add_action('edit_term', [self::class, 'bump_content_version'], 20);
        add_action('create_term', [self::class, 'bump_content_version'], 20);
        add_action('delete_term', [self::class, 'bump_content_version'], 20);
        add_action('acf/save_post', [self::class, 'bump_content_version'], 20);
        add_action('save_post_wcapf-form', [self::class, 'bump_content_version'], 20);
        add_action('save_post_wcapf-filter', [self::class, 'bump_content_version'], 20);

        self::enable_wpc_linked_variation_cache();
    }

    /**
     * WPC Linked Variation's own get_linked_data()/get_linked_products() calls
     * (used by Controller::linked_products_summary(), called once per product
     * in /home's product_groups and in /group/products listings) ship with
     * built-in 24h transient caching, but it's off by default (wpclv_enable_cache
     * filter defaults to false). Left disabled, every single call re-runs an
     * uncached get_posts() scan of up to 500 'wpclv' link-config posts — by far
     * the most expensive per-product operation in this plugin. Turning this on
     * costs nothing we don't already accept elsewhere (product/term saves are
     * rare); the one gap is that WPC's own cache has no save-time invalidation,
     * so an admin edit to a link group can take up to 24h to show up in the API.
     */
    private static function enable_wpc_linked_variation_cache(): void
    {
        add_filter('wpclv_enable_cache', '__return_true');
    }

    /**
     * Recomputes and re-caches a value unconditionally (unlike remember(), which
     * only computes on a miss). Used by scheduled cache warmers so a transient
     * is refreshed proactively, before it expires, and no real request is ever
     * the one that pays for an expensive rebuild.
     */
    public static function warm(string $group, array $key_parts, int $ttl, callable $callback)
    {
        $value = $callback();

        set_transient(self::key($group, $key_parts), $value, $ttl);

        return $value;
    }

    public static function bump_content_version(): void
    {
        update_option(self::VERSION_OPTION, (string) microtime(true), false);
    }

    public static function content_version(): string
    {
        $version = get_option(self::VERSION_OPTION, '');

        return is_string($version) && $version !== '' ? $version : (string) time();
    }

    /**
     * Returns the cached value for ($group, $key_parts) if present, otherwise
     * computes it via $callback, caches it for $ttl seconds, and returns it.
     */
    public static function remember(string $group, array $key_parts, int $ttl, callable $callback)
    {
        $cache_key = self::key($group, $key_parts);
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $value = $callback();

        set_transient($cache_key, $value, $ttl);

        return $value;
    }

    private static function key(string $group, array $key_parts): string
    {
        return 'herlan_ra_' . $group . '_' . md5(self::content_version() . '|' . wp_json_encode($key_parts));
    }
}
