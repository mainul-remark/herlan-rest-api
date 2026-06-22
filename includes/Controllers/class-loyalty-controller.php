<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

if (! defined('ABSPATH')) {
    exit;
}

final class LoyaltyController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/user/loyalty', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'summary'],
            'permission_callback' => [$this, 'can_access'],
        ]);
    }

    public function summary(WP_REST_Request $request)
    {
        if (! defined('HERLAN_API_BASE_URL')) {
            return new WP_Error('herlan_loyalty_unavailable', __('Loyalty program is not available.', 'herlan-rest-api'), ['status' => 503]);
        }

        $user  = $this->current_user($request);
        $phone = (string) get_user_meta($user->ID, 'billing_phone', true);

        if (! $phone) {
            return new WP_Error('herlan_loyalty_no_phone', __('No phone number is associated with this account.', 'herlan-rest-api'), ['status' => 422]);
        }

        $cache_key = 'herlan_mobile_loyalty_' . $user->ID;
        $cached    = get_transient($cache_key);

        if (is_array($cached)) {
            return Response::success($cached);
        }

        $login = $this->loyalty_post('login', ['phone' => $phone]);

        if (empty($login['success']) || empty($login['data']['access_token'])) {
            return new WP_Error('herlan_loyalty_auth_failed', __('Could not connect to loyalty program.', 'herlan-rest-api'), ['status' => 502]);
        }

        $token    = (string) $login['data']['access_token'];
        $customer = $login['data']['customer'] ?? [];
        $raw      = $this->loyalty_get('summary', $token);
        $summary  = $this->resolve_summary($raw);

        $data = [
            'customer'           => $this->format_customer($customer),
            'points'             => (int) ($summary['total_usable_point'] ?? 0),
            'available_cash'     => (float) ($summary['total_usable_cash'] ?? 0),
            'total_spent'        => (float) ($summary['total_spent_amount'] ?? 0),
            'level'              => $this->format_level($customer, $summary),
            'next_expiring_cash' => $summary['next_expiring_cash'] ?? null,
            'transactions'       => [
                'purchase_orders' => $summary['purchase_orders'] ?? [],
                'redeem_orders'   => $summary['redeem_orders'] ?? [],
                'cashes'          => $summary['cashes'] ?? [],
            ],
        ];

        set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);

        return Response::success($data);
    }

    private function resolve_summary(array $raw): array
    {
        if (isset($raw['data']) && is_array($raw['data'])) {
            $raw = $raw['data'];
        }

        if (isset($raw['summary']) && is_array($raw['summary'])) {
            return array_merge($raw['summary'], $raw);
        }

        return $raw;
    }

    private function format_customer(array $customer): array
    {
        $level_raw = $customer['level'] ?? null;
        $level_name = is_array($level_raw)
            ? (string) ($level_raw['name'] ?? 'Unknown')
            : (string) ($level_raw ?? $customer['level_name'] ?? 'Unknown');

        return [
            'name'   => (string) ($customer['name'] ?? $customer['full_name'] ?? ''),
            'phone'  => (string) ($customer['phone'] ?? ''),
            'status' => ucfirst(strtolower((string) ($customer['status'] ?? 'active'))),
            'level'  => ucfirst(strtolower($level_name)),
        ];
    }

    private function format_level(array $customer, array $summary): array
    {
        $levels  = $summary['levels'] ?? [];
        $current = $levels['current'] ?? [];
        $next    = $levels['next'] ?? [];

        $level_name     = $current['name'] ?? $this->format_customer($customer)['level'];
        $current_amount = (float) ($current['starting_amount'] ?? 0);
        $next_amount    = (float) ($next['starting_amount'] ?? 0);
        $total_spent    = (float) ($summary['total_spent_amount'] ?? 0);

        $color = '#C1C1C1';
        foreach ($current['meta'] ?? [] as $m) {
            if (isset($m['key']) && stripos(trim((string) $m['key']), 'color') !== false && ! empty($m['value'])) {
                $color = (string) $m['value'];
                break;
            }
        }

        $tier_range = $next_amount - $current_amount;

        if ($tier_range > 0) {
            $spent_in_tier    = max(0, $total_spent - $current_amount);
            $progress_percent = min(100, round(($spent_in_tier / $tier_range) * 100, 2));
        } else {
            $progress_percent = empty($next['name']) ? 100.0 : 0.0;
        }

        $retention = $current['retention'] ?? [];

        return [
            'name'             => $level_name,
            'color'            => $color,
            'progress_percent' => $progress_percent,
            'next'             => $next['name'] ?? null,
            'next_at'          => $next_amount ?: null,
            'retention'        => [
                'message' => $retention['message'] ?? null,
                'target'  => (float) ($retention['required_minimum_spend'] ?? 0) ?: null,
            ],
        ];
    }

    private function loyalty_post(string $endpoint, array $body): array
    {
        $response = wp_remote_post(HERLAN_API_BASE_URL . $endpoint, [
            'body'        => wp_json_encode($body),
            'headers'     => ['Content-Type' => 'application/json'],
            'timeout'     => 6,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : [];
    }

    private function loyalty_get(string $endpoint, string $token): array
    {
        $response = wp_remote_get(HERLAN_API_BASE_URL . $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 6,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : [];
    }
}
