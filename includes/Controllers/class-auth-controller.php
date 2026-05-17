<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WP_User;

if (! defined('ABSPATH')) {
    exit;
}

final class AuthController extends Controller
{
    private const TOKEN_META_KEY = '_herlan_mobile_api_tokens';
    private const TOKEN_TTL = 2592000;

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/auth/login', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'login'],
            'permission_callback' => '__return_true',
            'args' => [
                'username' => ['required' => true, 'type' => 'string'],
                'password' => ['required' => true, 'type' => 'string'],
                'device_name' => ['required' => false, 'type' => 'string'],
            ],
        ]);

        register_rest_route($this->namespace, '/auth/register', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'register'],
            'permission_callback' => '__return_true',
            'args' => [
                'name' => ['required' => true, 'type' => 'string'],
                'email' => ['required' => true, 'type' => 'string', 'format' => 'email'],
                'password' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route($this->namespace, '/auth/logout', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'logout'],
            'permission_callback' => [$this, 'can_access'],
        ]);
    }

    public function login(WP_REST_Request $request)
    {
        $user = wp_authenticate(
            sanitize_user((string) $request->get_param('username')),
            (string) $request->get_param('password')
        );

        if (is_wp_error($user)) {
            return new WP_Error('herlan_invalid_credentials', __('Invalid username or password.', 'herlan-rest-api'), ['status' => 401]);
        }

        return $this->issue_token_response($user, (string) $request->get_param('device_name'));
    }

    public function register(WP_REST_Request $request)
    {
        if (! get_option('users_can_register')) {
            return new WP_Error('herlan_registration_disabled', __('Registration is currently disabled.', 'herlan-rest-api'), ['status' => 403]);
        }

        $email = sanitize_email((string) $request->get_param('email'));
        $password = (string) $request->get_param('password');
        $name = sanitize_text_field((string) $request->get_param('name'));

        if (! is_email($email)) {
            return new WP_Error('herlan_invalid_email', __('A valid email address is required.', 'herlan-rest-api'), ['status' => 422]);
        }

        if (email_exists($email)) {
            return new WP_Error('herlan_email_exists', __('This email address is already registered.', 'herlan-rest-api'), ['status' => 409]);
        }

        if (strlen($password) < 8) {
            return new WP_Error('herlan_weak_password', __('Password must be at least 8 characters.', 'herlan-rest-api'), ['status' => 422]);
        }

        $user_id = wp_create_user($email, $password, $email);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        wp_update_user([
            'ID' => $user_id,
            'display_name' => $name,
            'nickname' => $name,
        ]);

        update_user_meta($user_id, '_herlan_has_password', '1');

        $user = get_user_by('id', $user_id);

        return $this->issue_token_response($user, 'mobile');
    }

    public function logout(WP_REST_Request $request)
    {
        $user = $this->authenticate_request($request);

        if (is_wp_error($user)) {
            return $user;
        }

        $token_hash = (string) $request->get_param('herlan_token_hash');
        $tokens = array_filter($this->tokens_for($user->ID), static fn ($token) => ($token['hash'] ?? '') !== $token_hash);
        update_user_meta($user->ID, self::TOKEN_META_KEY, array_values($tokens));

        return Response::success([], 200, __('Logged out successfully.', 'herlan-rest-api'));
    }

    public function can_access(WP_REST_Request $request): bool|WP_Error
    {
        $user = $this->authenticate_request($request);

        if (is_wp_error($user)) {
            return $user;
        }

        $request->set_param('herlan_current_user', $user);

        return true;
    }

    public function authenticate_request(WP_REST_Request $request): WP_User|WP_Error
    {
        $header = $request->get_header('authorization');

        if (! $header && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
        }

        if (! $header || ! preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return new WP_Error('herlan_missing_token', __('Bearer token is required.', 'herlan-rest-api'), ['status' => 401]);
        }

        $raw_token = trim($matches[1]);
        $parts = explode('.', $raw_token, 2);

        // Try native herlan token format: base64(user_id).secret
        if (count($parts) === 2) {
            $user_id = absint(base64_decode($parts[0], true));
            $secret  = $parts[1];
            $user    = $user_id ? get_user_by('id', $user_id) : false;

            if ($user instanceof WP_User && $secret) {
                $hash = hash('sha256', $secret);
                $now  = time();

                foreach ($this->tokens_for($user->ID) as $token) {
                    if (($token['hash'] ?? '') === $hash && (int) ($token['expires_at'] ?? 0) > $now) {
                        $request->set_param('herlan_token_hash', $hash);

                        return $user;
                    }
                }
            }
        }

        // Fallback: validate auth-popup tokens.
        // Auth-popup stores access tokens as transients keyed by 'ap_api_{sha256(token)}'.
        // We cannot rely on wp_get_current_user() here because determine_current_user fires
        // during WordPress init before REST_REQUEST is defined, so auth-popup's filter skips
        // and the user is cached as 0 by the time this code runs.
        $ap_user_id = (int) get_transient('ap_api_' . hash('sha256', $raw_token));
        if ($ap_user_id > 0) {
            $user = get_user_by('id', $ap_user_id);
            if ($user instanceof WP_User) {
                wp_set_current_user($user->ID);
                return $user;
            }
        }

        return new WP_Error('herlan_invalid_token', __('Invalid or expired token.', 'herlan-rest-api'), ['status' => 401]);
    }

    private function issue_token_response(WP_User $user, string $device_name)
    {
        $secret = bin2hex(random_bytes(32));
        $expires_at = time() + self::TOKEN_TTL;
        $tokens = $this->tokens_for($user->ID);
        $tokens[] = [
            'hash' => hash('sha256', $secret),
            'device_name' => sanitize_text_field($device_name ?: 'mobile'),
            'issued_at' => time(),
            'expires_at' => $expires_at,
        ];

        update_user_meta($user->ID, self::TOKEN_META_KEY, $tokens);

        return Response::success([
            'token_type' => 'Bearer',
            'access_token' => base64_encode((string) $user->ID) . '.' . $secret,
            'expires_at' => gmdate('c', $expires_at),
            'user' => Response::user($user),
        ]);
    }

    private function tokens_for(int $user_id): array
    {
        $tokens = get_user_meta($user_id, self::TOKEN_META_KEY, true);

        return is_array($tokens) ? $tokens : [];
    }
}
