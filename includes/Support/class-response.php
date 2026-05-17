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
            'avatar'       => self::resolve_user_avatar($user->ID),
            'phone'        => self::resolve_user_phone($user->ID),
            'has_password' => get_user_meta($user->ID, '_herlan_has_password', true) === '1',
        ];
    }

    private static function resolve_user_avatar(int $user_id): ?string
    {
        $url = (string) get_user_meta($user_id, 'profile_image_url', true);

        if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return get_avatar_url($user_id, ['size' => 96]) ?: null;
    }

    private static function resolve_user_phone(int $user_id): ?string
    {
        if (class_exists('\Auth_Popup_User_Auth')) {
            $phone = \Auth_Popup_User_Auth::get_user_phone($user_id);
            return $phone !== '' ? $phone : null;
        }

        $phone = (string) get_user_meta($user_id, 'billing_phone', true);
        return $phone !== '' ? $phone : null;
    }
}
