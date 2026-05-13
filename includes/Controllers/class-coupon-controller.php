<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WC_Coupon;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WP_User;

if (! defined('ABSPATH')) {
    exit;
}

final class CouponController extends Controller
{
    private const VALID_ORDERBY = [
        'created_date:asc',
        'created_date:desc',
        'amount:asc',
        'amount:desc',
    ];

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/user/coupons', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'index'],
            'permission_callback' => [$this, 'can_access'],
            'args'                => [
                'type'     => [
                    'required' => false,
                    'type'     => 'string',
                    'default'  => 'available',
                    'enum'     => ['available', 'expired', 'used'],
                ],
                'page'     => ['required' => false, 'type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['required' => false, 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50],
                'orderby'  => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'created_date:asc',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function index(WP_REST_Request $request)
    {
        if (! class_exists('Wt_Smart_Coupon_Public')) {
            return new WP_Error('herlan_coupons_unavailable', __('Coupon module is not available.', 'herlan-rest-api'), ['status' => 503]);
        }

        $user     = $this->current_user($request);
        $type     = (string) $request->get_param('type');
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = min(50, max(1, (int) $request->get_param('per_page')));
        $orderby  = (string) $request->get_param('orderby');

        if (! in_array($orderby, self::VALID_ORDERBY, true)) {
            $orderby = 'created_date:asc';
        }

        if ($type === 'used') {
            return $this->used_coupons($user, $page, $per_page);
        }

        return $this->listed_coupons($user, $type, $page, $per_page, $orderby);
    }

    private function listed_coupons(WP_User $user, string $type, int $page, int $per_page, string $orderby)
    {
        $plugin_type = $type === 'expired' ? 'expired_coupons' : 'available_coupons';
        $offset      = ($page - 1) * $per_page;

        // Fetch one extra to detect whether a next page exists without a separate count query.
        $post_ids = \Wt_Smart_Coupon_Public::get_user_coupons($user, $offset, $per_page + 1, [
            'type'    => $plugin_type,
            'section' => 'my_account',
            'orderby' => $orderby,
        ]);

        $has_more = count($post_ids) > $per_page;

        if ($has_more) {
            array_pop($post_ids);
        }

        $coupons = [];

        foreach ($post_ids as $post_id) {
            $coupon = new WC_Coupon(absint($post_id));

            if ($coupon->get_id()) {
                $coupons[] = $this->format_coupon($coupon, $user);
            }
        }

        return Response::success([
            'coupons'    => $coupons,
            'pagination' => [
                'page'     => $page,
                'per_page' => $per_page,
                'has_more' => $has_more,
            ],
        ]);
    }

    private function used_coupons(WP_User $user, int $page, int $per_page)
    {
        $codes = \Wt_Smart_Coupon_Public::get_coupon_used_by_a_customer($user);

        if (! is_array($codes)) {
            $codes = [];
        }

        $total       = count($codes);
        $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;
        $offset      = ($page - 1) * $per_page;
        $paged_codes = array_slice($codes, $offset, $per_page);
        $coupons     = [];

        foreach ($paged_codes as $code) {
            $coupon = new WC_Coupon((string) $code);

            if ($coupon->get_id()) {
                $coupons[] = $this->format_coupon($coupon, $user);
            }
        }

        return Response::success([
            'coupons'    => $coupons,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $total_pages,
                'has_more'    => $page < $total_pages,
            ],
        ]);
    }

    private function format_coupon(WC_Coupon $coupon, WP_User $user): array
    {
        $meta        = \Wt_Smart_Coupon_Public::get_coupon_meta_data($coupon);
        $coupon_id   = $coupon->get_id();
        $expires_ts  = isset($meta['coupon_expires']) && $meta['coupon_expires'] ? (int) $meta['coupon_expires'] : null;
        $starts_ts   = isset($meta['start_date']) && $meta['start_date'] ? (int) $meta['start_date'] : null;

        return [
            'id'                   => $coupon_id,
            'code'                 => $coupon->get_code(),
            'discount_type'        => $coupon->get_discount_type(),
            'coupon_type'          => $meta['coupon_type'] ?? '',
            'amount'               => (float) $coupon->get_amount(),
            'coupon_amount'        => $meta['coupon_amount'] ?? '',
            'description'          => $coupon->get_description(),
            'free_shipping'        => $coupon->get_free_shipping(),
            'starts_at'            => $starts_ts ? gmdate('c', $starts_ts) : null,
            'expires_at'           => $expires_ts ? gmdate('c', $expires_ts) : null,
            'is_expired'           => $expires_ts ? ($expires_ts < time()) : false,
            'minimum_amount'       => $coupon->get_minimum_amount(),
            'maximum_amount'       => $coupon->get_maximum_amount(),
            'usage_limit'          => $coupon->get_usage_limit() ?: 0,
            'usage_count'          => $coupon->get_usage_count(),
            'usage_limit_per_user' => $coupon->get_usage_limit_per_user() ?: 0,
            'used_by_current_user' => $this->used_by_user_count($coupon_id, $user->ID),
            'individual_use'       => $coupon->get_individual_use(),
            'email_restrictions'   => $meta['email_restriction'] ?? [],
        ];
    }

    private function used_by_user_count(int $coupon_id, int $user_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(meta_id) FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key = '_used_by' AND meta_value = %d",
            $coupon_id,
            $user_id
        ));
    }
}
