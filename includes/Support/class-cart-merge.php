<?php

namespace HerlanRestApi\Support;

use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Merges an anonymous guest cart (identified by the "Cart-Token" header the
 * client is still sending from before login) into an authenticated user's
 * own cart session, so items added while browsing as a guest aren't lost or
 * left permanently orphaned under the anonymous session once the customer
 * logs in.
 *
 * Deliberately keyed off request STATE (is_user_logged_in() + a foreign
 * Cart-Token present) rather than hooked into a specific login endpoint, so
 * it covers every way a user can end up authenticated — this plugin's own
 * /auth/login and /auth/register, and third-party auth flows this plugin
 * doesn't control, like the separate auth-popup plugin's Google/Facebook/
 * OTP login (which issues its own tokens via a completely different code
 * path — herlan-rest-api's own AuthController::authenticate_request()
 * already recognizes those tokens via its ap_api_{hash} transient fallback,
 * so is_user_logged_in() is true for them too by the time a request reaches
 * here).
 *
 * WooCommerce's own guest→account cart migration only exists on the default
 * cookie-based WC_Session_Handler; the Store API's token-based SessionHandler
 * (which this plugin's cart endpoints use — see CartController::boot_cart())
 * has no such migration, so it's done here by hand: read the guest session's
 * cart line items directly out of wp_woocommerce_sessions and copy the ones
 * the user doesn't already have into their own session row. Both handler
 * classes share that table and the same double-serialization format
 * (WC_Session::set() serializes each value before the whole $_data array is
 * serialized again on save), and the persisted cart item shape excludes the
 * live WC_Product object (see WC_Cart_Session::get_cart_for_session()), so
 * this is a plain, safe array copy — WC_Cart re-derives everything else
 * (prices, totals, product validity) from these fields the next time the
 * cart loads.
 *
 * Only merges line items, not applied coupons or cached totals — those get
 * recalculated fresh on the next cart read regardless.
 */
final class CartMerge
{
    /**
     * If the current request carries a Cart-Token belonging to a different
     * (anonymous) session, merges its cart into $user_id's own session row
     * and returns true so the caller knows to stop trusting that token for
     * the rest of this request (see CartController::boot_cart(), which
     * unsets the header on a true return so the following session-handler
     * resolution falls back to the authenticated user's own identity
     * instead of the stale guest one).
     *
     * No-ops (returns false) when there's no token, it's invalid/expired,
     * or it already belongs to this user.
     */
    public static function reconcile_for_authenticated_user(int $user_id): bool
    {
        if (! class_exists(CartTokenUtils::class)) {
            return false;
        }

        $token = isset($_SERVER['HTTP_CART_TOKEN']) ? wc_clean(wp_unslash($_SERVER['HTTP_CART_TOKEN'])) : '';

        if ($token === '' || ! CartTokenUtils::validate_cart_token($token)) {
            return false;
        }

        $payload           = CartTokenUtils::get_cart_token_payload($token);
        $guest_customer_id = (string) ($payload['user_id'] ?? '');

        if ($guest_customer_id === '' || $guest_customer_id === (string) $user_id) {
            return false;
        }

        self::merge_session_cart($guest_customer_id, $user_id);

        return true;
    }

    private static function merge_session_cart(string $guest_customer_id, int $user_id): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_sessions';

        $guest_row = $wpdb->get_row($wpdb->prepare(
            "SELECT session_value FROM {$table} WHERE session_key = %s",
            $guest_customer_id
        ));

        if (! $guest_row) {
            return;
        }

        $guest_data = maybe_unserialize($guest_row->session_value);
        $guest_cart = is_array($guest_data) && isset($guest_data['cart']) ? maybe_unserialize($guest_data['cart']) : null;

        if (! is_array($guest_cart) || empty($guest_cart)) {
            return;
        }

        $user_row = $wpdb->get_row($wpdb->prepare(
            "SELECT session_value, session_expiry FROM {$table} WHERE session_key = %s",
            (string) $user_id
        ));

        $user_data = $user_row ? maybe_unserialize($user_row->session_value) : [];
        $user_data = is_array($user_data) ? $user_data : [];
        $user_cart = isset($user_data['cart']) ? maybe_unserialize($user_data['cart']) : [];
        $user_cart = is_array($user_cart) ? $user_cart : [];

        // Cart item keys are a deterministic hash of product/variation/data
        // (WC_Cart::generate_cart_id()), so an item already in the user's
        // cart will collide on the same key regardless of which session
        // added it first — a plain array union is enough to dedupe.
        $merged = $user_cart + $guest_cart;

        if (count($merged) === count($user_cart)) {
            return;
        }

        $user_data['cart'] = maybe_serialize($merged);
        $expiry            = $user_row ? (int) $user_row->session_expiry : (time() + (int) apply_filters('wc_session_expiration', 7 * DAY_IN_SECONDS));

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (`session_key`, `session_value`, `session_expiry`) VALUES (%s, %s, %d)
             ON DUPLICATE KEY UPDATE `session_value` = VALUES(`session_value`), `session_expiry` = VALUES(`session_expiry`)",
            (string) $user_id,
            maybe_serialize($user_data),
            $expiry
        ));
    }
}
