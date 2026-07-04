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
}
