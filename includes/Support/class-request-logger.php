<?php

namespace HerlanRestApi\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Logs every herlan/v1 mobile API request/response to a dedicated file in
 * the plugin, so cart-token/auth-flow issues (like the guest cart merge
 * investigation) can be traced from real device traffic instead of relying
 * on manually pasted client-side console logs.
 *
 * Never logs raw secrets: Cart-Token and Bearer/Basic credentials are logged
 * as a short one-way hash (for correlating "same token across two requests")
 * plus, where safe, the non-secret identity a token decodes to (a Cart-Token's
 * embedded user_id, or the WP user ID half of this plugin's own
 * base64(user_id).secret Bearer format) — never the signature/secret itself.
 */
final class RequestLogger
{
    private const LOG_DIR = 'logs';

    public static function boot(): void
    {
        if (! apply_filters('herlan_rest_api_enable_request_log', true)) {
            return;
        }

        add_filter('rest_pre_dispatch', [self::class, 'log_request'], PHP_INT_MIN, 3);
        add_filter('rest_post_dispatch', [self::class, 'log_response'], PHP_INT_MAX, 3);
    }

    public static function log_request($result, $server, $request)
    {
        if ($request instanceof \WP_REST_Request && self::is_herlan_route($request)) {
            $request->set_param('_herlan_log_start', microtime(true));

            self::write(sprintf(
                'REQ  %-6s %s  cart_token=%s  auth=%s  ip=%s',
                $request->get_method(),
                $request->get_route(),
                self::describe_cart_token($request),
                self::describe_auth($request),
                self::client_ip()
            ));
        }

        return $result;
    }

    public static function log_response($response, $server, $request)
    {
        if (! $request instanceof \WP_REST_Request || ! self::is_herlan_route($request)) {
            return $response;
        }

        $started   = $request->get_param('_herlan_log_start');
        $duration  = is_float($started) ? round((microtime(true) - $started) * 1000) . 'ms' : 'n/a';
        $status    = $response instanceof \WP_REST_Response ? $response->get_status() : 'n/a';
        $data      = $response instanceof \WP_REST_Response ? $response->get_data() : null;
        $cart_info = self::describe_response_cart($data);
        $user_id   = is_user_logged_in() ? get_current_user_id() : 0;
        $error     = is_int($status) && $status >= 400 ? self::describe_error($data) : '';

        self::write(sprintf(
            'RES  %-6s %s  status=%s  duration=%s  current_user=%s%s%s',
            $request->get_method(),
            $request->get_route(),
            $status,
            $duration,
            $user_id ?: 'guest',
            $cart_info,
            $error
        ));

        return $response;
    }

    private static function is_herlan_route(\WP_REST_Request $request): bool
    {
        return str_starts_with($request->get_route(), '/' . \HerlanRestApi\Plugin::REST_NAMESPACE . '/');
    }

    private static function describe_cart_token(\WP_REST_Request $request): string
    {
        $token = isset($_SERVER['HTTP_CART_TOKEN']) ? wc_clean(wp_unslash($_SERVER['HTTP_CART_TOKEN'])) : '';

        if ($token === '') {
            return 'none';
        }

        $hash    = substr(hash('sha256', $token), 0, 10);
        $payload = self::decode_jwt_payload($token);
        $userId  = is_array($payload) ? ($payload['user_id'] ?? '?') : '?';

        return "{$hash}(uid={$userId})";
    }

    private static function describe_auth(\WP_REST_Request $request): string
    {
        $header = (string) $request->get_header('authorization');

        if ($header === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
        }

        if ($header === '') {
            return 'none';
        }

        if (stripos($header, 'Bearer ') === 0) {
            $raw   = trim(substr($header, 7));
            $parts = explode('.', $raw, 2);

            // This plugin's own token format: base64(user_id).secret — the
            // id half is not a secret, only log that, never the secret half.
            if (count($parts) === 2) {
                $decoded = base64_decode($parts[0], true);
                if ($decoded !== false && ctype_digit($decoded)) {
                    return "bearer(herlan_uid={$decoded})";
                }
            }

            return 'bearer(opaque, hash=' . substr(hash('sha256', $raw), 0, 10) . ')';
        }

        if (stripos($header, 'Basic ') === 0) {
            return 'basic(present)';
        }

        return 'other(present)';
    }

    /**
     * WP_Error responses (thrown by a controller, e.g. herlan_cart_add_failed)
     * serialize to {code, message, data:{status}} — surface the code/message
     * so a non-2xx log line explains itself without needing the raw response
     * body pulled separately.
     */
    private static function describe_error($data): string
    {
        if (! is_array($data) || ! isset($data['code'])) {
            return '';
        }

        $message = isset($data['message']) && is_string($data['message']) ? $data['message'] : '';

        return sprintf('  error=%s(%s)', $data['code'], $message);
    }

    private static function describe_response_cart($data): string
    {
        if (! is_array($data)) {
            return '';
        }

        // Response::success() wraps everything under a top-level 'data' key
        // (i.e. {success, message, data: {...}}) — unwrap it first.
        $inner = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $cart  = $inner['cart'] ?? (isset($inner['item_count']) ? $inner : null);

        if (! is_array($cart) || ! isset($cart['item_count'])) {
            return '';
        }

        $tokenNote = '';
        if (! empty($cart['cart_token']) && is_string($cart['cart_token'])) {
            $payload = self::decode_jwt_payload($cart['cart_token']);
            $userId  = is_array($payload) ? ($payload['user_id'] ?? '?') : '?';
            $hash    = substr(hash('sha256', $cart['cart_token']), 0, 10);
            $tokenNote = "  cart_token={$hash}(uid={$userId})";
        }

        return sprintf('  item_count=%d%s', (int) $cart['item_count'], $tokenNote);
    }

    /**
     * Decodes a JWT's payload segment (base64url, NOT verified here — this is
     * for logging only, not authentication) so the non-secret user_id/exp
     * claims can be logged without ever touching the signature.
     */
    private static function decode_jwt_payload(string $jwt): ?array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        $padded = strtr($parts[1], '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $json   = base64_decode($padded, true);

        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    private static function client_ip(): string
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : 'unknown';
    }

    private static function write(string $line): void
    {
        $dir = HERLAN_REST_API_PATH . self::LOG_DIR;

        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // Protect the log directory from direct web access (Apache; a
        // similar rule should be added manually for nginx if used).
        if (! file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }

        if (! file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }

        $file = $dir . '/mobile-api-' . gmdate('Y-m-d') . '.log';
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $line . "\n";

        error_log($line, 3, $file);
    }
}
