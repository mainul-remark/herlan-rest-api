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

        return Response::success([
            'user' => Response::user($user),
        ]);
    }
}
