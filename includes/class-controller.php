<?php

namespace HerlanRestApi;

use HerlanRestApi\Controllers\AuthController;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_User;

if (! defined('ABSPATH')) {
    exit;
}

abstract class Controller
{
    protected string $namespace = Plugin::REST_NAMESPACE;

    protected ?AuthController $auth = null;

    /** Maps legacy/widget query param names to the canonical keys accepted by /group/products. */
    private const FILTER_QUERY_ALIASES = [
        '_brand'      => 'filter_brand',
        'product-cat' => 'filter_product_cat',
        '_skin-type'  => 'filter_skin-type',
        '_age-range'  => 'filter_age-range',
        '_keywords'   => 'filter_keywords',
    ];

    private const FILTER_TAXONOMY_KEYS = [
        'filter_product_cat',
        'filter_brand',
        'filter_skin-type',
        'filter_age-range',
        'filter_keywords',
    ];

    public function __construct(?AuthController $auth = null)
    {
        $this->auth = $auth;
    }

    abstract public function register_routes(): void;

    public function can_access(WP_REST_Request $request): bool|WP_Error
    {
        if (! $this->auth) {
            return new WP_Error('herlan_auth_unavailable', __('Authentication is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $user = $this->auth->authenticate_request($request);

        if (is_wp_error($user)) {
            return $user;
        }

        $request->set_param('herlan_current_user', $user);

        return true;
    }

    protected function current_user(WP_REST_Request $request): ?WP_User
    {
        $user = $request->get_param('herlan_current_user');

        return $user instanceof WP_User ? $user : null;
    }

    /**
     * Optional authentication for routes that also serve guests.
     *
     * If a valid bearer token is present, sets the current user so ownership
     * checks (e.g. authorize_order_access()) can match against it. Always
     * returns true so guest flows keep working without a token.
     */
    public function maybe_authenticate(WP_REST_Request $request): bool
    {
        if ($this->auth) {
            $header = $request->get_header('authorization');
            if (! $header && isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $header = (string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
            }

            if ($header) {
                $user = $this->auth->authenticate_request($request);
                if ($user instanceof WP_User) {
                    $request->set_param('herlan_current_user', $user);
                }
            }
        }

        return true;
    }

    /**
     * Logged-in customers must own the order; guest orders are verified via the
     * order key instead, since there's no user to check.
     *
     * @return true|WP_Error
     */
    protected function authorize_order_access(WC_Order $order, WP_REST_Request $request)
    {
        $customer_id = $order->get_customer_id();

        if ($customer_id > 0) {
            $user = $this->current_user($request);
            if (! $user || $user->ID !== $customer_id) {
                return new WP_Error(
                    'herlan_order_forbidden',
                    __('You do not have permission to access this order.', 'herlan-rest-api'),
                    ['status' => 403]
                );
            }

            return true;
        }

        $order_key = (string) $request->get_param('order_key');
        if ($order_key === '' || ! hash_equals($order->get_order_key(), $order_key)) {
            return new WP_Error(
                'herlan_order_forbidden',
                __('You do not have permission to access this order.', 'herlan-rest-api'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Casts a value to float, stripping thousands-separator commas first
     * (the loyalty API returns amounts like "273,600" as strings).
     */
    protected static function to_float($value): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    protected function get_herlan_cash_info($cart): array
    {
        if (! defined('HERLAN_API_BASE_URL') || ! WC()->session) {
            return ['available' => false];
        }

        $user_id = get_current_user_id();
        if (! $user_id) {
            return ['available' => false];
        }

        $phone = (string) get_user_meta($user_id, 'billing_phone', true);
        if (! $phone) {
            return ['available' => false];
        }

        $available_cash  = (float) (WC()->session->get('herlan_cached_available_cash_v2') ?? 0);
        $cache_expiry    = WC()->session->get('herlan_cached_cash_expiry_v2');
        $cache_valid     = $cache_expiry && time() < (int) $cache_expiry;

        if (! $cache_valid) {
            $available_cash = $this->get_herlan_cash_balance($user_id, $phone);
        }

        $enabled         = (bool) WC()->session->get('herlan_cash_enabled', false);
        $redeemed_amount = (int) WC()->session->get('herlan_redeemed_amount', 0);

        $ceiling        = max(0.0, (float) $cart->get_subtotal() - (float) $cart->get_discount_total());
        $max_redeemable = (int) (floor(min($available_cash, $ceiling) / 10) * 10);

        // Clamp stored amount to current max (subtotal may have changed).
        if ($redeemed_amount > $max_redeemable) {
            $redeemed_amount = $max_redeemable;
            WC()->session->set('herlan_redeemed_amount', $redeemed_amount);
        }

        $next_expiring_amount = (float) (WC()->session->get('herlan_cached_next_expiring_cash_amount_v2') ?? 0);
        $next_expiring_date   = (string) (WC()->session->get('herlan_cached_next_expiring_cash_date_v2') ?? '');

        return [
            'available'            => true,
            'available_cash'       => wc_format_decimal($available_cash, 2),
            'max_redeemable'       => wc_format_decimal($max_redeemable, 2),
            'enabled'              => $enabled,
            'redeemed_amount'      => wc_format_decimal($redeemed_amount, 2),
            'next_expiring_amount' => wc_format_decimal($next_expiring_amount, 2),
            'next_expiring_date'   => $next_expiring_date,
        ];
    }

    /**
     * Fetch Herlan Cash balance for a user, using WC session cache when available.
     * Falls back to calling the loyalty API directly.
     */
    protected function get_herlan_cash_balance(int $user_id, string $phone): float
    {
        // Try the transient cache set by LoyaltyController first.
        $transient = get_transient('herlan_mobile_loyalty_' . $user_id);
        if (is_array($transient) && isset($transient['available_cash'])) {
            return (float) $transient['available_cash'];
        }

        // Negative cache: skip the remote calls entirely while a recent failure is still fresh,
        // so an outage doesn't block every cart request behind a fresh ~12s timeout.
        if (WC()->session && WC()->session->get('herlan_cash_fetch_failed_until')) {
            if (time() < (int) WC()->session->get('herlan_cash_fetch_failed_until')) {
                return 0.0;
            }
        }

        // Call the loyalty API: login then get summary.
        $login_url  = HERLAN_API_BASE_URL . 'login';
        $login_body = wp_json_encode(['phone' => $phone]);
        $login      = wp_remote_post($login_url, [
            'body'        => $login_body,
            'headers'     => array_merge(
                ['Content-Type' => 'application/json'],
                $this->loyalty_signing_headers('POST', $login_url, $login_body)
            ),
            'timeout'     => 6,
            'data_format' => 'body',
        ]);

        if (is_wp_error($login)) {
            $this->mark_herlan_cash_fetch_failed();
            return 0.0;
        }

        $login_data    = json_decode(wp_remote_retrieve_body($login), true);
        $login_payload = is_array($login_data) ? ($login_data['data'] ?? []) : [];
        if (empty($login_payload['access_token'])) {
            $this->mark_herlan_cash_fetch_failed();
            return 0.0;
        }

        $token       = (string) $login_payload['access_token'];
        $device_id   = (string) ($login_payload['device_id'] ?? '');
        $summary_url = HERLAN_API_BASE_URL . 'summary';
        $summary     = wp_remote_get($summary_url, [
            'headers' => array_merge(
                [
                    'Authorization' => 'Bearer ' . $token,
                    'X-DEVICE-ID'   => $device_id,
                    'Content-Type'  => 'application/json',
                ],
                $this->loyalty_signing_headers('GET', $summary_url)
            ),
            'timeout' => 6,
        ]);

        if (is_wp_error($summary)) {
            $this->mark_herlan_cash_fetch_failed();
            return 0.0;
        }

        $summary_data = json_decode(wp_remote_retrieve_body($summary), true);
        if (! is_array($summary_data)) {
            $this->mark_herlan_cash_fetch_failed();
            return 0.0;
        }

        $balance = 0.0;
        foreach ([
            $summary_data['data']['summary']['total_usable_cash'] ?? null,
            $summary_data['summary']['total_usable_cash'] ?? null,
            $summary_data['data']['total_usable_cash'] ?? null,
            $summary_data['total_usable_cash'] ?? null,
        ] as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                $balance = self::to_float($candidate);
                break;
            }
        }

        $next_expiring_amount = 0.0;
        $next_expiring_date   = '';
        $expiring_node        = $summary_data['data']['summary']['next_expiring_cash']
            ?? $summary_data['summary']['next_expiring_cash']
            ?? $summary_data['data']['next_expiring_cash']
            ?? $summary_data['next_expiring_cash']
            ?? [];

        if (is_array($expiring_node)) {
            $next_expiring_amount = self::to_float($expiring_node['amount'] ?? $expiring_node['cash_amount'] ?? 0);
            $next_expiring_date   = (string) (
                $expiring_node['expires_at']
                ?? $expiring_node['expiration_date']
                ?? $expiring_node['expire_date']
                ?? $expiring_node['expiry_date']
                ?? $expiring_node['expires_on']
                ?? ''
            );
        }

        // Populate the session cache so subsequent calls within the same request are free.
        if (WC()->session) {
            WC()->session->set('herlan_cached_available_cash_v2', $balance);
            WC()->session->set('herlan_cached_next_expiring_cash_amount_v2', $next_expiring_amount);
            WC()->session->set('herlan_cached_next_expiring_cash_date_v2', $next_expiring_date);
            WC()->session->set('herlan_cached_cash_expiry_v2', time() + 120);
        }

        return $balance;
    }

    /**
     * Build HMAC-SHA256 signing headers required by the Loyalty API.
     * Reads channel_key_id and channel_secret from auth-popup settings.
     * Returns an empty array if credentials are not configured.
     */
    protected function loyalty_signing_headers(string $method, string $url, string $body = ''): array
    {
        $settings    = get_option('auth_popup_settings', []);
        $channel_key = $settings['loyalty_channel_key_id'] ?? '';
        $secret      = $settings['loyalty_channel_secret'] ?? '';

        if (! $channel_key || ! $secret) {
            return [];
        }

        $parsed    = parse_url($url);
        $path      = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        $timestamp = (string) time();
        $nonce     = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $canonical = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
        $signature = hash_hmac('sha256', $canonical, $secret);

        return [
            'X-Channel-Key' => $channel_key,
            'X-Timestamp'   => $timestamp,
            'X-Nonce'       => $nonce,
            'X-Signature'   => $signature,
        ];
    }

    /**
     * Suppress further loyalty API attempts for 60s after a failure, so an outage
     * doesn't re-trigger a fresh ~12s blocking timeout on every cart request.
     */
    protected function mark_herlan_cash_fetch_failed(): void
    {
        if (WC()->session) {
            WC()->session->set('herlan_cash_fetch_failed_until', time() + 60);
        }
    }

    /**
     * Resolves a URL's path (e.g. /product-tag/best-selling/) to the registered
     * taxonomy term it points at, if any. Supports nested category paths by taking
     * the last path segment.
     */
    protected function resolve_term_from_url(?string $url): ?array
    {
        if (empty($url)) {
            return null;
        }

        $path = trim(wp_parse_url($url, PHP_URL_PATH) ?? '', '/');

        if (empty($path)) {
            return null;
        }

        // Strip WordPress subdirectory install prefix (e.g. "herlan.com/")
        $home_path = trim(wp_parse_url(home_url(), PHP_URL_PATH) ?? '', '/');
        if ($home_path !== '' && strpos($path, $home_path . '/') === 0) {
            $path = trim(substr($path, strlen($home_path) + 1), '/');
        }

        if (empty($path)) {
            return null;
        }

        // Map URL base slugs to registered taxonomy names
        $taxonomy_bases = [
            'product-category' => 'product_cat',
            'product-tag'      => 'product_tag',
            'brand'            => 'brand',
            'skin-type'        => 'skin-type',
            'age-range'        => 'age-range',
            'keywords'         => 'keywords',
        ];

        foreach ($taxonomy_bases as $base => $taxonomy) {
            if (strpos($path, $base . '/') !== 0) {
                continue;
            }

            // Take the last path segment to support nested categories
            $slug = basename(rtrim(substr($path, strlen($base) + 1), '/'));

            if (empty($slug)) {
                continue;
            }

            $term = get_term_by('slug', $slug, $taxonomy);

            if (! $term || is_wp_error($term)) {
                continue;
            }

            $link = get_term_link($term);

            $params = [];
            $query  = wp_parse_url($url, PHP_URL_QUERY);
            if ($query) {
                parse_str($query, $params);
            }

            return [
                'taxonomy'   => $taxonomy,
                'term_id'    => $term->term_id,
                'name'       => $term->name,
                'slug'       => $term->slug,
                'link'       => is_wp_error($link) ? null : $link,
                'url_params' => $params ?: null,
            ];
        }

        return null;
    }

    /**
     * Resolves a single product permalink (any permalink structure) to its product
     * ID/slug via url_to_postid(), the same way WordPress rewrite matching does it.
     */
    protected function resolve_product_from_url(?string $url): ?array
    {
        if (empty($url)) {
            return null;
        }

        $post_id = url_to_postid($url);

        if (! $post_id || get_post_type($post_id) !== 'product') {
            return null;
        }

        return [
            'product_id'   => $post_id,
            'product_slug' => get_post_field('post_name', $post_id),
        ];
    }

    /**
     * Parses a shortcut/section URL into the params /group/products understands:
     * path segments (e.g. /product-tag/best-selling/) resolve to {$prefix}_taxonomy +
     * {$prefix}_term, and the query string (filter_*, min_price/max_price, or the
     * WooCommerce price widget's "price=min~max" format) resolves to the filter keys.
     *
     * When the URL points at a single product permalink instead of an archive,
     * target_type is 'product' and product_id/product_slug are populated so callers
     * can fetch the product directly (e.g. GET /products/{product_id}) instead of
     * treating it as a /group/products listing query.
     *
     * @param string $prefix 'context' for individual content items (banners, shortcuts,
     *                        custom tags) or 'section' for listing blocks that own their
     *                        own "View all" (campaigns, product groups/sliders, recommendations).
     */
    protected function resolve_shortcut_filters(?string $url, string $prefix = 'context'): array
    {
        $result = [
            "{$prefix}_taxonomy" => null,
            "{$prefix}_term"     => null,
            'target_type'        => null,
            'product_id'         => null,
            'product_slug'       => null,
            'min_price'          => null,
            'max_price'          => null,
        ];

        foreach (self::FILTER_TAXONOMY_KEYS as $key) {
            $result[$key] = null;
        }

        if (empty($url)) {
            return $result;
        }

        $term_data = $this->resolve_term_from_url($url);
        if ($term_data) {
            $result["{$prefix}_taxonomy"] = $term_data['taxonomy'];
            $result["{$prefix}_term"]     = $term_data['slug'];
            $result['target_type']        = 'archive';
        } else {
            $product_data = $this->resolve_product_from_url($url);
            if ($product_data) {
                $result['target_type']  = 'product';
                $result['product_id']   = $product_data['product_id'];
                $result['product_slug'] = $product_data['product_slug'];
            }
        }

        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (! $query) {
            return $result;
        }

        parse_str($query, $params);

        foreach (self::FILTER_QUERY_ALIASES as $raw_key => $canonical_key) {
            if (isset($params[$raw_key]) && ! isset($params[$canonical_key])) {
                $params[$canonical_key] = $params[$raw_key];
            }
        }

        foreach (self::FILTER_TAXONOMY_KEYS as $key) {
            if (empty($params[$key])) {
                continue;
            }

            $slugs = array_values(array_filter(array_map('sanitize_title', explode(',', (string) $params[$key]))));

            if ($slugs) {
                $result[$key] = $slugs;
            }
        }

        if (isset($params['min_price']) && is_numeric($params['min_price'])) {
            $result['min_price'] = (float) $params['min_price'];
        }

        if (isset($params['max_price']) && is_numeric($params['max_price'])) {
            $result['max_price'] = (float) $params['max_price'];
        }

        // WooCommerce native price-widget format, e.g. "price=10~500".
        if ($result['min_price'] === null && $result['max_price'] === null && isset($params['price']) && str_contains((string) $params['price'], '~')) {
            [$min, $max] = array_pad(explode('~', (string) $params['price'], 2), 2, null);

            if (is_numeric($min)) {
                $result['min_price'] = (float) $min;
            }

            if (is_numeric($max)) {
                $result['max_price'] = (float) $max;
            }
        }

        return $result;
    }

    /**
     * WPC Linked Variation group (e.g. colour/shade variants sold as separate products) for a single product.
     * Mirrors WPCleverWpclv::term()/get_linked_data() in wpc-linked-variation.php: picks whichever single
     * attribute the link group is configured to display with, in the plugin's own priority order
     * (images > swatches > dropdown > button-default), then returns each linked product's id + display
     * type + type-specific value, plus which attribute/term it was resolved from.
     */
    protected function linked_products_summary(\WC_Product $product): array
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
    protected function linked_products_mode(array $link_data): array
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
    protected function linked_product_typed(\WC_Product $product, string $mode, string $taxonomy, string $attribute_label, string $attribute_type): ?array
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
}
