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

final class AccountController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/user/account', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'update'],
            'permission_callback' => [$this, 'can_access'],
            'args'                => [
                'first_name'       => ['required' => false, 'type' => 'string'],
                'last_name'        => ['required' => false, 'type' => 'string'],
                'display_name'     => ['required' => false, 'type' => 'string'],
                'email'            => ['required' => false, 'type' => 'string', 'format' => 'email'],
                'current_password' => ['required' => false, 'type' => 'string'],
                'new_password'     => ['required' => false, 'type' => 'string'],
            ],
        ]);

        register_rest_route($this->namespace, '/user/avatar', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'upload_avatar'],
            'permission_callback' => [$this, 'can_access'],
        ]);
    }

    public function update(WP_REST_Request $request)
    {
        $user = $this->current_user($request);

        if (! $user) {
            return new WP_Error('herlan_user_missing', __('Authenticated user could not be loaded.', 'herlan-rest-api'), ['status' => 401]);
        }

        $new_email        = $request->get_param('email') !== null ? sanitize_email((string) $request->get_param('email')) : null;
        $new_password     = $request->get_param('new_password') !== null ? (string) $request->get_param('new_password') : null;
        $current_password = $request->get_param('current_password') !== null ? (string) $request->get_param('current_password') : null;

        if (($new_email || $new_password) && ! $current_password) {
            return new WP_Error('herlan_password_required', __('Current password is required to change email or password.', 'herlan-rest-api'), ['status' => 422]);
        }

        if ($current_password && ! wp_check_password($current_password, $user->user_pass, $user->ID)) {
            return new WP_Error('herlan_invalid_password', __('Current password is incorrect.', 'herlan-rest-api'), ['status' => 422]);
        }

        $data = ['ID' => $user->ID];

        if ($new_email !== null) {
            if (! is_email($new_email)) {
                return new WP_Error('herlan_invalid_email', __('A valid email address is required.', 'herlan-rest-api'), ['status' => 422]);
            }

            $existing = email_exists($new_email);

            if ($existing && (int) $existing !== $user->ID) {
                return new WP_Error('herlan_email_exists', __('This email address is already in use.', 'herlan-rest-api'), ['status' => 409]);
            }

            $data['user_email'] = $new_email;
        }

        if ($new_password !== null) {
            if (strlen($new_password) < 8) {
                return new WP_Error('herlan_weak_password', __('Password must be at least 8 characters.', 'herlan-rest-api'), ['status' => 422]);
            }

            $data['user_pass'] = $new_password;
        }

        if ($request->get_param('first_name') !== null) {
            $data['first_name'] = sanitize_text_field((string) $request->get_param('first_name'));
        }

        if ($request->get_param('last_name') !== null) {
            $data['last_name'] = sanitize_text_field((string) $request->get_param('last_name'));
        }

        if ($request->get_param('display_name') !== null) {
            $data['display_name'] = sanitize_text_field((string) $request->get_param('display_name'));
        }

        if (isset($data['user_pass'])) {
            update_user_meta($user->ID, '_herlan_has_password', '1');
        }

        $result = wp_update_user($data);

        if (is_wp_error($result)) {
            return $result;
        }

        return Response::success(
            ['user' => Response::user(get_user_by('id', $user->ID))],
            200,
            __('Account updated successfully.', 'herlan-rest-api')
        );
    }

    public function upload_avatar(WP_REST_Request $request)
    {
        $user = $this->current_user($request);

        if (! $user) {
            return new WP_Error('herlan_user_missing', __('Authenticated user could not be loaded.', 'herlan-rest-api'), ['status' => 401]);
        }

        $files = $request->get_file_params();

        if (empty($files['avatar'])) {
            return new WP_Error('herlan_missing_file', __('Please upload an image file in the "avatar" field.', 'herlan-rest-api'), ['status' => 400]);
        }

        $file = $files['avatar'];

        if (! empty($file['size']) && (int) $file['size'] > 3 * MB_IN_BYTES) {
            return new WP_Error('herlan_file_too_large', __('Image must be 3 MB or smaller.', 'herlan-rest-api'), ['status' => 400]);
        }

        $type_check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);

        if (empty($type_check['type']) || strpos($type_check['type'], 'image/') !== 0) {
            return new WP_Error('herlan_invalid_file_type', __('Only image files are allowed (JPEG, PNG, GIF, WebP).', 'herlan-rest-api'), ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $upload = wp_handle_upload($file, [
            'test_form' => false,
            'mimes'     => [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
                'gif'          => 'image/gif',
                'webp'         => 'image/webp',
            ],
        ]);

        if (isset($upload['error'])) {
            return new WP_Error('herlan_upload_failed', $upload['error'], ['status' => 500]);
        }

        $url = esc_url_raw($upload['url'] ?? '');

        if (! $url) {
            return new WP_Error('herlan_upload_failed', __('Upload failed. Please try again.', 'herlan-rest-api'), ['status' => 500]);
        }

        update_user_meta($user->ID, 'profile_image_url', $url);

        return Response::success(
            ['user' => Response::user(get_user_by('id', $user->ID))],
            200,
            __('Profile photo updated.', 'herlan-rest-api')
        );
    }
}
