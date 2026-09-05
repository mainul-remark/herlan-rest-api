<?php

namespace HerlanRestApi\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Ports the theme's configurable "New Arrivals" ranking algorithm
 * (inc/features/feature-flags.php + inc/theme/herlan-ajax.php's
 * lazy_load_product_query()) into the REST API, reading the same
 * wp_options the theme's admin settings page writes, so the website and
 * the mobile app show the same New Arrivals ordering without depending on
 * the theme's own functions being defined.
 */
final class NewArrivals
{
    private const OPTION_ALGORITHM = 'herlan_new_arrivals_algorithm_settings';
    private const OPTION_FEATURES  = 'herlan_feature_settings';

    public static function is_enabled(): bool
    {
        $settings = get_option(self::OPTION_FEATURES, []);
        $settings = is_array($settings) ? $settings : [];

        return ($settings['new_arrivals_age_shuffle'] ?? 'off') === 'on';
    }

    public static function settings(): array
    {
        $defaults = [
            'min_age_days'      => 0,
            'max_age_days'      => 30,
            'strategy'          => 'random',
            'candidate_pool'    => 60,
            'recency_weight'    => 60,
            'popularity_weight' => 20,
            'random_weight'     => 20,
        ];

        $settings = get_option(self::OPTION_ALGORITHM, []);
        $settings = is_array($settings) ? $settings : [];
        $settings = wp_parse_args($settings, $defaults);

        $settings['min_age_days']      = max(0, (int) $settings['min_age_days']);
        $settings['max_age_days']      = max($settings['min_age_days'], (int) $settings['max_age_days']);
        $settings['candidate_pool']    = max(20, min(200, (int) $settings['candidate_pool']));
        $settings['strategy']          = in_array($settings['strategy'], ['random', 'newest', 'weighted'], true)
            ? $settings['strategy']
            : 'random';
        $settings['recency_weight']    = max(0, min(100, (int) $settings['recency_weight']));
        $settings['popularity_weight'] = max(0, min(100, (int) $settings['popularity_weight']));
        $settings['random_weight']     = max(0, min(100, (int) $settings['random_weight']));

        return $settings;
    }

    /**
     * Rewrites a built WP_Query args array to use the New Arrivals algorithm
     * instead of a plain date sort. Only call this when the request is
     * actually sorting by "date" (the plugin's own New Arrivals sort key —
     * see ProductController::sort_options()) and the algorithm is enabled.
     * Existing tax_query/meta_query clauses (category filters, price, stock)
     * are preserved and merged with, not replaced by, the algorithm's own
     * exclusions.
     */
    public static function apply(array $args): array
    {
        if (! self::is_enabled()) {
            return $args;
        }

        $settings = self::settings();

        $min_age_days = $settings['min_age_days'];
        $max_age_days = $settings['max_age_days'];

        unset($args['order'], $args['meta_key']);

        $args['date_query'] = [
            [
                'column'    => 'post_date',
                'after'     => $max_age_days . ' days ago',
                'before'    => $min_age_days > 0 ? $min_age_days . ' days ago' : 'now',
                'inclusive' => true,
            ],
        ];

        $args['tax_query'] = self::merge_clauses($args['tax_query'] ?? [], [
            [
                'taxonomy'         => 'product_cat',
                'field'            => 'slug',
                'terms'            => ['home-care'],
                'operator'         => 'NOT IN',
                'include_children' => true,
            ],
        ]);

        $args['meta_query'] = self::merge_clauses($args['meta_query'] ?? [], [
            [
                'key'     => '_stock_status',
                'value'   => 'outofstock',
                'compare' => '!=',
            ],
        ]);

        if ($settings['strategy'] === 'newest') {
            $args['orderby'] = 'date';
            $args['order']   = 'DESC';

            return $args;
        }

        if ($settings['strategy'] === 'weighted') {
            $weighted_ids = self::weighted_ids($settings, $args);

            if (! empty($weighted_ids)) {
                $args['post__in'] = $weighted_ids;
                $args['orderby']  = 'post__in';
                unset($args['date_query']);

                return $args;
            }
        }

        $args['orderby'] = 'rand';

        return $args;
    }

    /**
     * Merges extra tax_query/meta_query clauses into an existing one, adding
     * a top-level 'relation' => 'AND' when the combined clause count requires it.
     */
    private static function merge_clauses(array $existing, array $extra): array
    {
        $relation = $existing['relation'] ?? null;
        unset($existing['relation']);

        $clauses = array_merge(array_values($existing), $extra);

        if (count($clauses) > 1) {
            $clauses['relation'] = $relation ?: 'AND';
        }

        return $clauses;
    }

    /**
     * Scores a candidate pool of recently-published products by recency +
     * popularity (total_sales) + randomness, returning the top 20 IDs.
     * Mirrors herlan_get_weighted_new_arrival_product_ids() in the theme.
     */
    private static function weighted_ids(array $settings, array $base_args): array
    {
        $candidate_query = new \WP_Query(array_merge($base_args, [
            'posts_per_page'          => (int) $settings['candidate_pool'],
            'fields'                  => 'ids',
            'orderby'                 => 'date',
            'order'                   => 'DESC',
            'ignore_sticky_posts'     => true,
            'no_found_rows'           => true,
            'update_post_meta_cache'  => false,
            'update_post_term_cache'  => false,
            'paged'                   => 1,
        ]));

        $candidate_ids = array_map('absint', $candidate_query->posts);

        if (empty($candidate_ids)) {
            return [];
        }

        // The candidate query above disables post/meta caching (it only needs
        // IDs) - prime it in one batch before the per-product get_post_meta()/
        // get_post_time() calls below, same reasoning as the theme's version.
        if (function_exists('_prime_post_caches')) {
            _prime_post_caches($candidate_ids, false, true);
        }

        $max_sales   = 0;
        $sales_by_id = [];

        foreach ($candidate_ids as $product_id) {
            $sales                     = (int) get_post_meta($product_id, 'total_sales', true);
            $sales_by_id[$product_id]  = $sales;
            $max_sales                 = max($max_sales, $sales);
        }

        $now               = current_time('timestamp');
        $window_seconds     = max(1, ($settings['max_age_days'] - $settings['min_age_days']) * DAY_IN_SECONDS);
        $recency_weight    = $settings['recency_weight'];
        $popularity_weight = $settings['popularity_weight'];
        $random_weight     = $settings['random_weight'];

        $scores = [];

        foreach ($candidate_ids as $product_id) {
            $post_timestamp   = get_post_time('U', true, $product_id);
            $age_seconds      = max(0, $now - $post_timestamp);
            $recency_score    = max(0, 1 - ($age_seconds / $window_seconds));
            $popularity_score = $max_sales > 0 ? ($sales_by_id[$product_id] / $max_sales) : 0;
            $random_score     = mt_rand() / mt_getrandmax();

            $scores[$product_id] = ($recency_score * $recency_weight)
                + ($popularity_score * $popularity_weight)
                + ($random_score * $random_weight);
        }

        arsort($scores, SORT_NUMERIC);

        return array_slice(array_keys($scores), 0, 20);
    }
}
