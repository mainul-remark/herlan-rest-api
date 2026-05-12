<?php

namespace HerlanRestApi\Support;

use WP_REST_Response;
use WP_User;

if (! defined('ABSPATH')) {
    exit;
}

final class Response
{
    public static function success(array $data = [], int $status = 200, string $message = ''): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function user(WP_User $user): array
    {
        return [
            'id'           => $user->ID,
            'name'         => $user->display_name,
            'first_name'   => $user->first_name,
            'last_name'    => $user->last_name,
            'email'        => $user->user_email,
            'username'     => $user->user_login,
            'roles'        => array_values($user->roles),
        ];
    }
}
