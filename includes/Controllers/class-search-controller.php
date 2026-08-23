<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * GET /wp-json/herlan/v1/search
 *
 * Replicates the dgwt_wcas_ajax_search functionality but returns JSON
 * instead of rendered HTML, for use by the mobile app.
 *
 * Query parameters:
 *   s         (string, required) – search phrase
 *   per_page  (int, optional, default 10, max 50)
 *   page      (int, optional, default 1)
 */
final class SearchController extends Controller
{
    /** User meta key storing the per-user recent search history (JSON-decoded to an array). */
    private const HISTORY_META_KEY = '_herlan_search_history';

    /** Max number of phrases kept in a user's search history. */
    private const HISTORY_MAX_ITEMS = 15;

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/search', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'search'],
            'permission_callback' => [$this, 'maybe_authenticate'],
            'args'                => [
                's' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'Search phrase.',
                ],
                'per_page' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 10,
                    'minimum'           => 1,
                    'maximum'           => 50,
                    'description'       => 'Number of results per page.',
                ],
                'page' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 1,
                    'minimum'           => 1,
                    'description'       => 'Page number.',
                ],
                'remember' => [
                    'required'          => false,
                    'type'              => 'boolean',
                    'default'           => false,
                    'description'       => 'When true and the request is authenticated, save this phrase into the user\'s search history (used for submitted searches, not autocomplete keystrokes).',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/search/popular-history', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'popular_and_history'],
                'permission_callback' => [$this, 'maybe_authenticate'],
                'args'                => [
                    'limit' => [
                        'required'    => false,
                        'type'        => 'integer',
                        'default'     => 10,
                        'minimum'     => 1,
                        'maximum'     => 30,
                        'description' => 'Number of popular phrases to return.',
                    ],
                    'days' => [
                        'required'    => false,
                        'type'        => 'integer',
                        'default'     => 30,
                        'minimum'     => 1,
                        'maximum'     => 90,
                        'description' => 'Lookback window (in days) for popular phrases.',
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'clear_history'],
                'permission_callback' => [$this, 'can_access'],
            ],
        ]);
    }

    public function search(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $phrase   = (string) $request->get_param('s');
        $per_page = min(50, max(1, (int) $request->get_param('per_page')));
        $page     = max(1, (int) $request->get_param('page'));
        $remember = (bool) $request->get_param('remember');

        if (mb_strlen($phrase) < 2) {
            return Response::success([
                'query'      => $phrase,
                'products'   => [],
                'pagination' => $this->pagination_block(0, $page, $per_page),
            ]);
        }

        [$product_ids, $total] = $this->run_search($phrase, $per_page, $page);

        $products = [];
        foreach ($product_ids as $id) {
            $product = wc_get_product($id);
            if ($product instanceof WC_Product) {
                $products[] = $this->format_product_card($product);
            }
        }

        if ($remember) {
            $user = $this->current_user($request);
            if ($user) {
                $this->remember_search_phrase($user->ID, $phrase);
            }
        }

        $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;

        return Response::success([
            'query'      => $phrase,
            'products'   => $products,
            'pagination' => $this->pagination_block($total, $page, $per_page),
        ]);
    }

    /**
     * GET /wp-json/herlan/v1/search/popular-history
     *
     * Site-wide popular searches (from the FiboSearch / Ajax Search for WooCommerce
     * analytics table) plus the current user's own recent search history, in one
     * response. History is an empty array for guests / unauthenticated requests.
     */
    public function popular_and_history(WP_REST_Request $request)
    {
        $limit = min(30, max(1, (int) $request->get_param('limit')));
        $days  = min(90, max(1, (int) $request->get_param('days')));
        $user  = $this->current_user($request);

        return Response::success([
            'popular' => $this->get_popular_searches($limit, $days),
            'history' => $user ? $this->get_search_history($user->ID) : [],
        ]);
    }

    /**
     * DELETE /wp-json/herlan/v1/search/popular-history
     *
     * Clears the authenticated user's own search history. Does not affect the
     * site-wide popular searches data.
     */
    public function clear_history(WP_REST_Request $request)
    {
        $user = $this->current_user($request);

        delete_user_meta($user->ID, self::HISTORY_META_KEY);

        return Response::success([], 200, __('Search history cleared.', 'herlan-rest-api'));
    }

    /**
     * Top search phrases over the last $days, sourced from the FiboSearch analytics
     * table (wp_dgwt_wcas_stats). Filters out short/keystroke-noise phrases and
     * phrases that returned no results, so it reflects real popular searches rather
     * than raw autocomplete logging. Returns an empty array if the Analytics module
     * isn't installed/enabled.
     */
    private function get_popular_searches(int $limit, int $days): array
    {
        if (! class_exists('\DgoraWcas\Analytics\Database') || ! \DgoraWcas\Analytics\Database::exist()) {
            return [];
        }

        global $wpdb;

        $table    = \DgoraWcas\Analytics\Database::getTableName();
        $since    = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        //phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            "SELECT phrase, COUNT(*) AS qty
             FROM {$table}
             WHERE created_at > %s
             AND hits > 0
             AND CHAR_LENGTH(phrase) >= 3
             GROUP BY phrase
             ORDER BY qty DESC, phrase ASC
             LIMIT %d",
            $since,
            $limit
        );
        //phpcs:enable

        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map(static fn ($row) => [
            'phrase' => (string) $row['phrase'],
            'count'  => (int) $row['qty'],
        ], $rows);
    }

    /**
     * The user's stored search history, most-recent-first.
     */
    private function get_search_history(int $user_id): array
    {
        $history = get_user_meta($user_id, self::HISTORY_META_KEY, true);

        return is_array($history) ? array_values($history) : [];
    }

    /**
     * Save a searched phrase into the user's history: dedupes (moves an existing
     * phrase to the front instead of duplicating it), then caps the list length.
     */
    private function remember_search_phrase(int $user_id, string $phrase): void
    {
        $phrase = trim($phrase);

        if ($phrase === '') {
            return;
        }

        $history = $this->get_search_history($user_id);

        $history = array_values(array_filter(
            $history,
            static fn ($item) => ! isset($item['phrase']) || mb_strtolower((string) $item['phrase']) !== mb_strtolower($phrase)
        ));

        array_unshift($history, [
            'phrase'      => $phrase,
            'searched_at' => gmdate('c'),
        ]);

        $history = array_slice($history, 0, self::HISTORY_MAX_ITEMS);

        update_user_meta($user_id, self::HISTORY_META_KEY, $history);
    }

    /**
     * Run the search and return [product_ids[], total_count].
     * Uses the FiboSearch / Ajax Search for WooCommerce engine when available,
     * otherwise falls back to a standard WooCommerce WP_Query.
     */
    private function run_search(string $phrase, int $per_page, int $page): array
    {
        // Prefer the dgwt_wcas engine – same results as the live search autocomplete.
        if (class_exists('\DgoraWcas\Search')) {
            $searcher = new \DgoraWcas\Search();
            $result   = $searcher->searchPosts($phrase, [
                'post_type' => 'product',
                'fields'    => 'ids',
                'per_page'  => $per_page,
                'page'      => $page,
            ]);

            if (! is_wp_error($result) && isset($result['results'])) {
                return [$result['results'], (int) ($result['total'] ?? count($result['results']))];
            }
        }

        // Fallback: native WooCommerce search via WP_Query.
        $query = new \WP_Query([
            's'              => $phrase,
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'tax_query'      => [
                [
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => ['exclude-from-search'],
                    'operator' => 'NOT IN',
                ],
            ],
        ]);

        $ids   = array_map('intval', (array) $query->posts);
        $total = (int) $query->found_posts;

        wp_reset_postdata();

        return [$ids, $total];
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
            'image'          => $this->product_image($this->effective_image_id($product)),
            'categories'     => $this->product_terms($id, 'product_cat'),
        ];
    }

    private function product_image(?int $image_id): ?array
    {
        if (! $image_id) {
            return null;
        }

        $src = wp_get_attachment_image_src($image_id, 'woocommerce_thumbnail');
        if (! $src) {
            return null;
        }

        return [
            'id'     => $image_id,
            'src'    => $src[0],
            'width'  => $src[1],
            'height' => $src[2],
            'alt'    => (string) get_post_meta($image_id, '_wp_attachment_image_alt', true),
        ];
    }

    private function product_terms(int $product_id, string $taxonomy): array
    {
        $terms = get_the_terms($product_id, $taxonomy);
        if (! $terms || is_wp_error($terms)) {
            return [];
        }

        return array_values(array_map(static fn ($t) => [
            'id'   => $t->term_id,
            'name' => $t->name,
            'slug' => $t->slug,
        ], $terms));
    }

    private function pagination_block(int $total, int $page, int $per_page): array
    {
        $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;

        return [
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => $total_pages,
            'has_more'    => $page < $total_pages,
        ];
    }
}
