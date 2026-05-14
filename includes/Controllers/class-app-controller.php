<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use HerlanRestApi\Support\Response;
use WP_REST_Server;

if (! defined('ABSPATH')) {
    exit;
}

final class AppController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'status'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/promo-bar', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'promo_bar'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->namespace, '/header-config', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'header_config'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function status()
    {
        return Response::success([
            'api_version' => HERLAN_REST_API_VERSION,
            'site'        => get_bloginfo('name'),
            'timezone'    => wp_timezone_string(),
        ]);
    }

    public function promo_bar()
    {
        $options = get_option('herlan_header_promo_settings', []);
        $options = is_array($options) ? $options : [];

        return Response::success([
            'enabled'  => ($options['enable_promo_topbar'] ?? 'off') === 'on',
            'bg_color' => $options['promo_topbar_bg_color'] ?? '#333333',
            'text'     => wp_kses_post($options['promo_topbar_text'] ?? ''),
        ]);
    }

    public function header_config()
    {
        $options = get_option('herlan_header_main_settings', []);
        $options = is_array($options) ? $options : [];

        return Response::success([
            'top_bar'              => [
                'bg_color'   => $options['herlan_header_top_bar_bg'] ?? '#ffffff',
                'text_color' => $options['herlan_header_top_bar_text_color'] ?? '#000000',
            ],
            'nav'                  => [
                'bg_color'   => $options['herlan_header_nav_bg'] ?? '#ffffff',
                'text_color' => $options['herlan_header_nav_text_color'] ?? '#000000',
            ],
            'mini_cart_badge_color' => $options['herlan_mini_count_bg_color'] ?? '#D50032',
            'ornaments'            => [
                'enabled' => ($options['enable_header_ornaments'] ?? 'off') === 'on',
                'left'    => $options['header_ornament_left'] ?? '',
                'right'   => $options['header_ornament_right'] ?? '',
            ],
        ]);
    }
}
