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

final class StoreController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/stores', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_stores'],
            'permission_callback' => '__return_true',
            'args'                => [
                'district' => ['required' => false, 'type' => 'string', 'default' => ''],
                'area'     => ['required' => false, 'type' => 'string', 'default' => ''],
                'search'   => ['required' => false, 'type' => 'string', 'default' => ''],
                'limit'    => ['required' => false, 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200],
                'offset'   => ['required' => false, 'type' => 'integer', 'default' => 0, 'minimum' => 0],
            ],
        ]);

        register_rest_route($this->namespace, '/stores/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_store'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/store-locations', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_locations'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function get_stores(WP_REST_Request $request)
    {
        global $wpdb;

        $district = sanitize_text_field((string) $request->get_param('district'));
        $area     = sanitize_text_field((string) $request->get_param('area'));
        $search   = sanitize_text_field((string) $request->get_param('search'));
        $limit    = (int) $request->get_param('limit');
        $offset   = (int) $request->get_param('offset');

        $table = $wpdb->prefix . 'herlan_stores';
        $where = ['status = %s'];
        $args  = ['publish'];

        if ($district !== '') {
            $where[] = 'city = %s';
            $args[]  = $district;
        }

        if ($area !== '') {
            $where[] = 'area = %s';
            $args[]  = $area;
        }

        if ($search !== '') {
            $like    = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(store_name LIKE %s OR store_address LIKE %s OR city LIKE %s OR phone_number LIKE %s)';
            $args[]  = $like;
            $args[]  = $like;
            $args[]  = $like;
            $args[]  = $like;
        }

        $where_sql = implode(' AND ', $where);

        $total = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE $where_sql", $args)
        );

        $stores = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE $where_sql ORDER BY city ASC, area ASC LIMIT %d OFFSET %d",
                array_merge($args, [$limit, $offset])
            )
        );

        return Response::success([
            'stores' => array_map([$this, 'format_store'], $stores ?: []),
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    public function get_store(WP_REST_Request $request): WP_Error|\WP_REST_Response
    {
        global $wpdb;

        $id    = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'herlan_stores';

        $store = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id)
        );

        if ($wpdb->last_error) {
            return new WP_Error(
                'herlan_store_db_error',
                __('A database error occurred while fetching the store.', 'herlan-rest-api'),
                ['status' => 500]
            );
        }

        if (! $store) {
            return new WP_Error(
                'herlan_store_not_found',
                __('Store not found.', 'herlan-rest-api'),
                ['status' => 404]
            );
        }

        if ($store->status !== 'publish') {
            return new WP_Error(
                'herlan_store_not_found',
                __('Store not found.', 'herlan-rest-api'),
                ['status' => 404]
            );
        }

        return Response::success(['store' => $this->format_store($store)]);
    }

    public function get_locations()
    {
        global $wpdb;

        $table   = $wpdb->prefix . 'herlan_stores';
        $results = $wpdb->get_results(
            "SELECT DISTINCT city, area FROM $table WHERE status = 'publish' ORDER BY city ASC, area ASC"
        );

        $map = [];
        foreach ($results as $row) {
            $map[$row->city][] = $row->area;
        }

        $districts = [];
        foreach ($map as $city => $areas) {
            $districts[] = [
                'district' => $city,
                'areas'    => $areas,
            ];
        }

        return Response::success(['districts' => $districts]);
    }

    private function format_store(object $store): array
    {
        return [
            'id'            => (int) $store->id,
            'store_code'    => $store->store_code,
            'store_name'    => $store->store_name,
            'store_address' => $store->store_address,
            'district'      => $store->city,
            'area'          => $store->area,
            'phone'         => $store->phone_number,
            'working_hours' => $store->working_hours,
            'offday'        => $store->offday,
            'map_link'      => $store->map_link ?: null,
            'image_url'     => $store->image_url ?: null,
        ];
    }
}
