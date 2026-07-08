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

final class UserController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/user/me', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'me'],
            'permission_callback' => [$this, 'can_access'],
        ]);
    }

    public function me(WP_REST_Request $request)
    {
        $user = $this->current_user($request);

        if (! $user) {
            return new WP_Error('herlan_user_missing', __('Authenticated user could not be loaded.', 'herlan-rest-api'), ['status' => 401]);
        }

        $user_data = Response::user($user);

        $user_data['total_orders'] = function_exists('wc_get_customer_order_count')
            ? (int) wc_get_customer_order_count($user->ID)
            : 0;

        $user_data['wishlist_count'] = $this->get_wishlist_count($user->ID);

        $user_data['address_count'] = class_exists('Auth_Popup_Address_Manager')
            ? count(\Auth_Popup_Address_Manager::get_addresses($user->ID))
            : 0;

        return Response::success([
            'user' => $user_data,
        ]);

//        return Response::success([
//            'user' => Response::user($user),
//        ]);
    }

    private function get_wishlist_count(int $user_id): int
    {
        if (! class_exists('TInvWL_Wishlist') || ! class_exists('TInvWL_Product')) {
            return 0;
        }

        wp_set_current_user($user_id);

        $wl      = new \TInvWL_Wishlist();
        $results = $wl->get_by_user_default($user_id);

        if (empty($results) || ! is_array($results)) {
            return 0;
        }

        $wishlist = array_shift($results);
        $wlp      = new \TInvWL_Product($wishlist);

        return (int) $wlp->get_wishlist([], true);
    }
}
