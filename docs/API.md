# Herlan REST API

Mobile application API exposed by the `herlan-rest-api` WordPress plugin.

## Base URL

```text
https://YOUR_DOMAIN/wp-json/herlan/v1
```

Local example:

```text
http://localhost/herlanlive3/wp-json/herlan/v1
```

## Response format

Successful responses use:

```json
{
  "success": true,
  "message": "",
  "data": {}
}
```

WordPress REST errors use:

```json
{
  "code": "herlan_error_code",
  "message": "Error message.",
  "data": {
    "status": 400
  }
}
```

## Authentication

Protected endpoints require:

```http
Authorization: Bearer USER_ID_BASE64.SECRET
```

Tokens are issued by `POST /auth/login` and `POST /auth/register`.

Notes:

- Herlan mobile tokens currently live for 30 days.
- The plugin also accepts compatible auth-popup bearer tokens when present.

## User object

Several endpoints return the same user shape:

```json
{
  "id": 1,
  "name": "Customer Name",
  "first_name": "Customer",
  "last_name": "Name",
  "email": "customer@example.com",
  "username": "customer@example.com",
  "roles": ["customer"],
  "avatar": "https://example.com/avatar.jpg",
  "phone": "01712345678",
  "has_password": true
}
```

## Public endpoints

### `GET /status`

Returns API and site metadata.

Example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/status"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "api_version": "0.1.0",
    "site": "Herlan Live",
    "timezone": "Asia/Dhaka"
  }
}
```

### `GET /promo-bar`

Returns top promo bar settings.

Response fields:

| Field | Type | Notes |
| --- | --- | --- |
| `enabled` | boolean | Whether the bar should be shown |
| `bg_color` | string | Hex color |
| `text` | string | Sanitized HTML |

### `GET /header-config`

Returns header color and ornament settings.

Response fields:

| Field | Type | Notes |
| --- | --- | --- |
| `top_bar.bg_color` | string | Top bar background |
| `top_bar.text_color` | string | Top bar text color |
| `nav.bg_color` | string | Nav background |
| `nav.text_color` | string | Nav text color |
| `mini_cart_badge_color` | string | Badge background |
| `ornaments.enabled` | boolean | Ornament toggle |
| `ornaments.left` | string | Left ornament URL or empty string |
| `ornaments.right` | string | Right ornament URL or empty string |

### `GET /image-popup`

Returns popup configuration.

Response fields:

| Field | Type | Notes |
| --- | --- | --- |
| `enabled` | boolean | Popup active flag |
| `display_location` | string | Stored option, usually `all` |
| `show_again_after.hours` | integer | Interval hours |
| `show_again_after.minutes` | integer | Interval minutes |
| `show_again_after.seconds` | integer | Interval seconds |
| `image` | string\|null | Desktop image URL |
| `mobile.enabled` | boolean | Separate mobile image enabled |
| `mobile.image` | string\|null | Mobile image URL |
| `cta_url` | string\|null | Optional click target |

### `GET /home`

Returns mobile home page configuration assembled from ACF option fields and related content.

Top-level response fields:

| Field | Type | Notes |
| --- | --- | --- |
| `hero_slider` | object | Slider items and autoplay duration |
| `promotional_block` | object | Promotional post cards |
| `campaign_shortcuts` | object | Shortcut tags/buttons |
| `product_groups` | object | Product group tabs/tags |
| `campaigns` | array | Active campaign sections |
| `categories_block` | object | Category block enabled flag |
| `custom_tags` | object | Custom tag cards |
| `product_sliders` | array | Configured product slider definitions |
| `home_video` | object\|null | Home video block |

Key nested shapes:

- `hero_slider.items[]`
  - image slide: `type`, `url`, `image`, `mobile_image`
  - video slide: `type`, `url`, `video_url`
- `promotional_block.posts[]`: `id`, `title`, `subtitle`, `image`, `url`
- `campaign_shortcuts.items[]`: `name`, `url`
- `product_groups.groups[]`: `title`, `product_tag`, `featured_tag`
- `campaigns[]`: `title`, `product_tag`, `tag_url`, `see_all_text`, `background_image`
- `custom_tags.items[]`: `name`, `url`, `image`
- `product_sliders[]`: `title`, `product_type`, `taxonomy_type`, `term_slug`, `url`, `background_image`
- `home_video`: `video_desktop`, `video_mobile`, `poster`, `link_url`

### `POST /auth/login`

Logs a user in with WordPress username/email and password.

Request body:

```json
{
  "username": "customer@example.com",
  "password": "password123",
  "device_name": "iPhone 15"
}
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "token_type": "Bearer",
    "access_token": "MQ.example_secret",
    "expires_at": "2026-06-16T12:00:00+00:00",
    "user": {
      "id": 1,
      "name": "Customer Name",
      "first_name": "Customer",
      "last_name": "Name",
      "email": "customer@example.com",
      "username": "customer@example.com",
      "roles": ["customer"],
      "avatar": "https://example.com/avatar.jpg",
      "phone": "01712345678",
      "has_password": true
    }
  }
}
```

Errors:

- `401 herlan_invalid_credentials`

### `POST /auth/register`

Creates a WordPress user and immediately issues a bearer token.

Request body:

```json
{
  "name": "Customer Name",
  "email": "customer@example.com",
  "password": "password123"
}
```

Response data matches `POST /auth/login`.

Errors:

- `403 herlan_registration_disabled`
- `409 herlan_email_exists`
- `422 herlan_invalid_email`
- `422 herlan_weak_password`

### `GET /products/filters`

Returns mobile product listing filters.

Query parameters:

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `taxonomy` | string | none | Optional context taxonomy slug |
| `term` | string | none | Optional context term slug |
| `include_counts` | boolean | `true` | Recalculate counts for current context |

Response shape:

```json
{
  "success": true,
  "message": "",
  "data": {
    "context": {
      "taxonomy": "product_cat",
      "term": "lips"
    },
    "sort_options": [
      { "key": "popularity", "label": "Best Selling", "orderby": "sales" },
      { "key": "date", "label": "New Arrivals", "orderby": "date" },
      { "key": "price_asc", "label": "Price: Low to High", "orderby": "price", "order": "ASC" },
      { "key": "price_desc", "label": "Price: High to Low", "orderby": "price", "order": "DESC" },
      { "key": "rating", "label": "Top Rated", "orderby": "rating" }
    ],
    "price_range": {
      "min": 690,
      "max": 1990
    },
    "filters": [
      {
        "taxonomy": "brand",
        "label": "Brands",
        "type": "taxonomy",
        "hierarchical": false,
        "terms": []
      }
    ]
  }
}
```

Term fields inside each filter:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Term ID |
| `name` | string | Term name |
| `slug` | string | Term slug |
| `taxonomy` | string | Taxonomy name |
| `count` | integer | Product count |
| `parent` | integer | Parent term ID |
| `link` | string\|null | Term archive URL |
| `color` | string | Swatch color if configured |
| `image` | string\|null | Swatch image URL if configured |

Errors:

- `500 herlan_woocommerce_unavailable`

### `GET /products/{id}`

Returns a published WooCommerce product.

Main fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Product ID |
| `name` | string | Product name |
| `slug` | string | Product slug |
| `type` | string | WooCommerce type |
| `permalink` | string | Product URL |
| `status` | string | Product status |
| `sku` | string | SKU |
| `description` | string | Sanitized HTML |
| `short_description` | string | Sanitized HTML |
| `price` | string | Current price |
| `regular_price` | string | Regular price |
| `sale_price` | string | Sale price |
| `price_html` | string | WooCommerce formatted HTML |
| `on_sale` | boolean | Sale flag |
| `purchasable` | boolean | Purchasable flag |
| `stock_status` | string | Stock status |
| `stock_quantity` | integer\|null | Stock quantity |
| `average_rating` | string | Average rating |
| `rating_count` | integer | Rating count |
| `categories` | array | Assigned `product_cat` terms |
| `tags` | array | Assigned `product_tag` terms |
| `brand` | object\|null | First brand term |
| `taxonomies` | object | Assigned public taxonomies and attributes |
| `attributes` | array | Product attributes |
| `images` | array | Main image plus gallery |
| `custom_fields` | object | ACF fields normalized for API use |
| `linked_products` | object | WPC Linked Variation data |
| `recommendations` | object | Recommendation sections |
| `variations` | array | Present for variable products only |

`recommendations` always contains:

- `you_may_like`
- `more_from_this_brand`
- `best_selling`
- `new_arrivals`

Errors:

- `404 herlan_product_not_found`
- `500 herlan_woocommerce_unavailable`

### `GET /drawer-brands-categories`

Returns product categories and brands for app navigation.

Query parameters:

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `hide_empty` | boolean | `true` | Exclude empty terms |
| `categories_flat` | boolean | `false` | Return categories as a flat list |
| `order` | string | `asc` | `asc` or `desc` |
| `order_by` | string | `name` | `name`, `count`, `id`, or `slug` |

Example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/drawer-brands-categories"
```

Response fields:

| Field | Type | Notes |
| --- | --- | --- |
| `categories` | array | Nested tree by default |
| `total_categories` | integer | Number of category terms before nesting |
| `brands` | array | Brand term list |
| `total_brands` | integer | Number of brand terms |

Category fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Term ID |
| `name` | string | Category name |
| `slug` | string | Category slug |
| `description` | string | Category description |
| `count` | integer | Product count |
| `parent` | integer | Parent term ID |
| `link` | string\|null | Category archive URL |
| `image` | object\|null | Thumbnail image |
| `children` | array | Child categories |

Brand fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Term ID |
| `name` | string | Brand name |
| `slug` | string | Brand slug |
| `description` | string | Brand description |
| `count` | integer | Product count |
| `link` | string\|null | Brand archive URL |
| `image` | object\|null | Brand logo image |

Image object:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Attachment ID |
| `src` | string | Image URL |
| `alt` | string | Alt text |

## Protected endpoints

### `POST /auth/logout`

Revokes the current token.

Response:

```json
{
  "success": true,
  "message": "Logged out successfully.",
  "data": {}
}
```

### `GET /user/me`

Returns the authenticated user.

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "user": {
      "id": 1,
      "name": "Customer Name",
      "first_name": "Customer",
      "last_name": "Name",
      "email": "customer@example.com",
      "username": "customer@example.com",
      "roles": ["customer"],
      "avatar": "https://example.com/avatar.jpg",
      "phone": "01712345678",
      "has_password": true
    }
  }
}
```

### `PUT /user/account` and `PATCH /user/account`

Updates the authenticated user's account.

Request fields:

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `first_name` | string | No | Optional |
| `last_name` | string | No | Optional |
| `display_name` | string | No | Optional |
| `email` | string | No | Requires `current_password` |
| `current_password` | string | Conditional | Required when changing email or password |
| `new_password` | string | No | Minimum 8 chars, requires `current_password` |

Response contains the updated `user` object.

Errors:

- `401 herlan_user_missing`
- `409 herlan_email_exists`
- `422 herlan_password_required`
- `422 herlan_invalid_password`
- `422 herlan_invalid_email`
- `422 herlan_weak_password`

### `POST /user/avatar`

Uploads or replaces the authenticated user's profile photo.

**Content-Type:** `multipart/form-data`

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `avatar` | file | Yes | JPEG, PNG, GIF, or WebP — maximum 3 MB |

The uploaded file is stored in the WordPress uploads directory. The `profile_image_url` user meta is updated with the public URL and is reflected in the `avatar` field of all subsequent user responses.

Response contains the updated `user` object.

Example (curl):

```bash
curl -X POST "https://your-domain.com/wp-json/herlan/v1/user/avatar" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "avatar=@/path/to/photo.jpg"
```

Response:

```json
{
  "success": true,
  "message": "Profile photo updated.",
  "data": {
    "user": {
      "id": 1,
      "name": "Customer Name",
      "avatar": "https://your-domain.com/wp-content/uploads/2026/05/photo.jpg",
      "...": "..."
    }
  }
}
```

Errors:

- `400 herlan_missing_file` — no file attached in the `avatar` field
- `400 herlan_file_too_large` — file exceeds 3 MB
- `400 herlan_invalid_file_type` — file is not a supported image format
- `401 herlan_user_missing`
- `500 herlan_upload_failed` — server-side upload error

---

### `GET /user/loyalty`

Returns loyalty summary for the authenticated user.

The controller is implemented in `class-loyalty-controller.php`; the response includes customer profile data, points, available cash, level/progress information, next expiring cash, and transaction groups.

Typical errors:

- `422 herlan_loyalty_no_phone`
- `502 herlan_loyalty_auth_failed`
- `503 herlan_loyalty_unavailable`

### `GET /user/coupons`

Returns Smart Coupon data for the authenticated user.

Query parameters:

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `type` | string | `available` | `available`, `expired`, `used` |
| `page` | integer | `1` | Page number |
| `per_page` | integer | `10` | Max `50` |
| `orderby` | string | `created_date:asc` | `created_date:asc`, `created_date:desc`, `amount:asc`, `amount:desc` |

Coupon fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Coupon post ID |
| `code` | string | Coupon code |
| `discount_type` | string | WooCommerce discount type |
| `coupon_type` | string | Human readable type from Smart Coupon |
| `amount` | float | Raw numeric amount |
| `coupon_amount` | string | Formatted amount label |
| `description` | string | Coupon description |
| `free_shipping` | boolean | Free shipping flag |
| `starts_at` | string\|null | ISO 8601 |
| `expires_at` | string\|null | ISO 8601 |
| `is_expired` | boolean | Derived from expiry |
| `minimum_amount` | string | Minimum order amount |
| `maximum_amount` | string | Maximum order amount |
| `usage_limit` | integer | Total usage limit, `0` means unlimited |
| `usage_count` | integer | Current usage count |
| `usage_limit_per_user` | integer | Per-user limit, `0` means unlimited |
| `used_by_current_user` | integer | Times used by this user |
| `individual_use` | boolean | Cannot combine with other coupons |
| `email_restrictions` | array | Allowed email list |

Pagination notes:

- `available` and `expired` return `page`, `per_page`, and `has_more`.
- `used` returns `page`, `per_page`, `total`, `total_pages`, and `has_more`.

Errors:

- `503 herlan_coupons_unavailable`

### `GET /orders`

Returns the authenticated user's orders.

Query parameters:

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `page` | integer | `1` | Page number |
| `per_page` | integer | `10` | Max `50` |
| `status` | string | `any` | WooCommerce order status slug |

Order summary fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Order ID |
| `number` | string | Order number |
| `status` | string | Order status |
| `date_created` | string\|null | ISO 8601 |
| `currency` | string | Currency code |
| `total` | string | Grand total |
| `item_count` | integer | Total item count |
| `payment_method_title` | string | Payment method label |

### `GET /orders/{id}`

Returns one order belonging to the authenticated user.

Additional detail fields:

| Field | Type | Notes |
| --- | --- | --- |
| `date_modified` | string\|null | ISO 8601 |
| `subtotal` | string | Line-item subtotal |
| `total_tax` | string | Tax total |
| `shipping_total` | string | Shipping total |
| `discount_total` | string | Discount total |
| `payment_method` | string | Gateway ID |
| `transaction_id` | string | Gateway transaction ID |
| `customer_note` | string | Customer note |
| `billing` | object | WooCommerce billing address |
| `shipping` | object | WooCommerce shipping address |
| `line_items` | array | Ordered items |
| `coupon_lines` | array | Applied coupons |

`line_items[]` fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Line item ID |
| `product_id` | integer | Product ID |
| `variation_id` | integer\|null | Variation ID |
| `name` | string | Item name |
| `sku` | string\|null | SKU |
| `quantity` | integer | Quantity |
| `subtotal` | string | Line subtotal |
| `total` | string | Line total |
| `image` | string\|null | Product image URL |

Errors:

- `404 herlan_order_not_found`
- `500 herlan_woocommerce_unavailable`

### `GET /wishlist`

Returns the authenticated user's wishlist items.

Query parameters:

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `page` | integer | `1` | Page number |
| `per_page` | integer | `10` | Max `50` |
| `order` | string | `desc` | `asc` or `desc` |
| `order_by` | string | `date` | `date`, `price`, `product_id`, `quantity`, `id` |

Response fields:

| Field | Type | Notes |
| --- | --- | --- |
| `items` | array | Wishlist items |
| `pagination.page` | integer | Current page |
| `pagination.per_page` | integer | Page size |
| `pagination.total` | integer | Total items |
| `pagination.total_pages` | integer | Page count |

Wishlist item fields:

| Field | Type | Notes |
| --- | --- | --- |
| `wishlist_item_id` | integer | Wishlist row ID |
| `product_id` | integer | Product ID |
| `variation_id` | integer\|null | Variation ID |
| `date_added` | string\|null | Raw plugin date string |
| `quantity` | integer | Quantity |
| `product` | object | Product summary |

### `POST /wishlist`

Adds a product to the wishlist.

Request body:

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `product_id` | integer | Yes | Product ID |
| `variation_id` | integer | No | Defaults to `0` |

Errors:

- `404 herlan_product_not_found`
- `500 herlan_wishlist_unavailable`
- `500 herlan_wishlist_error`
- `500 herlan_wishlist_add_failed`

### `DELETE /wishlist/{product_id}`

Removes a product from the wishlist.

Query parameters:

| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `variation_id` | integer | No | Use for variation wishlist items |

Errors:

- `404 herlan_wishlist_not_found`
- `500 herlan_wishlist_unavailable`
- `500 herlan_wishlist_remove_failed`

---

## Cart endpoints

All cart endpoints require a Bearer token. The cart is tied to the authenticated user and persists between sessions (backed by WooCommerce's persistent cart in user meta).

Every cart response includes the full `cart` object in `data`:

```json
{
  "success": true,
  "message": "...",
  "data": {
    "cart": {
      "items": [
        {
          "key": "a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4",
          "product_id": 49045,
          "variation_id": 0,
          "name": "BOS Mystique Island Body Mist 150 ml",
          "sku": "1400001233",
          "quantity": 1,
          "price": "450.00",
          "subtotal": "450.00",
          "image": "https://your-domain.com/wp-content/uploads/.../thumbnail.jpg"
        }
      ],
      "item_count": 1,
      "subtotal": "450.00",
      "total": "450.00",
      "currency": "BDT"
    }
  }
}
```

`cart.items[]` fields:

| Field | Type | Notes |
| --- | --- | --- |
| `key` | string | MD5 cart item key — use this for update/remove |
| `product_id` | integer | Product ID |
| `variation_id` | integer | Variation ID, `0` for simple products |
| `name` | string | Product name |
| `sku` | string | Product SKU |
| `quantity` | integer | Quantity in cart |
| `price` | string | Unit price (decimal string) |
| `subtotal` | string | `price × quantity` |
| `image` | string\|null | Thumbnail image URL |

---

### `GET /cart`

Returns the current user's cart.

Example:

```bash
curl "https://your-domain.com/wp-json/herlan/v1/cart" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### `POST /cart/add-to-cart`

Adds a product to the cart.

Request body:

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `product_id` | integer | Yes | WooCommerce product ID |
| `quantity` | integer | No | Defaults to `1`, minimum `1` |
| `variation_id` | integer | No | Required for variable products |
| `variation` | object | No | Variation attributes, e.g. `{"attribute_pa_color": "red"}` |

Example (simple product):

```bash
curl -X POST "https://your-domain.com/wp-json/herlan/v1/cart/add-to-cart" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"product_id": 49045, "quantity": 1}'
```

Example (variable product):

```bash
curl -X POST "https://your-domain.com/wp-json/herlan/v1/cart/add-to-cart" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"product_id": 49045, "variation_id": 49060, "quantity": 2, "variation": {"attribute_pa_size": "150ml"}}'
```

Success response (`200`):

```json
{
  "success": true,
  "message": "\"BOS Mystique Island Body Mist 150 ml\" has been added to your cart.",
  "data": { "cart": { "...": "..." } }
}
```

Errors:

- `404 herlan_product_not_found` — product does not exist or is not purchasable
- `422 herlan_out_of_stock` — product is out of stock
- `422 herlan_cart_add_failed` — WooCommerce rejected the add (includes WC's own error message)

---

### `POST /cart/update-item`

Updates the quantity of a cart item. Send `quantity: 0` to remove the item.

Request body:

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `cart_item_key` | string | Yes | The `key` field from `cart.items[]` |
| `quantity` | integer | Yes | New quantity; `0` removes the item |

Example:

```bash
curl -X POST "https://your-domain.com/wp-json/herlan/v1/cart/update-item" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"cart_item_key": "a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4", "quantity": 3}'
```

Errors:

- `404 herlan_cart_item_not_found`

---

### `DELETE /cart/remove-item/{cart_item_key}`

Removes a single item from the cart.

`cart_item_key` is the 32-character hex key from `cart.items[].key`.

Example:

```bash
curl -X DELETE "https://your-domain.com/wp-json/herlan/v1/cart/remove-item/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Errors:

- `404 herlan_cart_item_not_found`

---

### `DELETE /cart/clear`

Empties the entire cart.

Example:

```bash
curl -X DELETE "https://your-domain.com/wp-json/herlan/v1/cart/clear" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### `GET /payments/methods`

Placeholder endpoint.

Current response:

```json
{
  "success": true,
  "message": "No mobile payment methods have been configured yet.",
  "data": {
    "methods": []
  }
}
```

### `POST /payments/create`

Placeholder endpoint.

Current response:

```json
{
  "success": true,
  "message": "Payment gateway integration is pending.",
  "data": {
    "payment": null
  }
}
```

## Common auth errors

Protected endpoints may return:

- `401 herlan_missing_token`
- `401 herlan_invalid_token`

## Route inventory

| Method | Path | Auth | Description |
| --- | --- | --- | --- |
| `GET` | `/status` | No | API status |
| `GET` | `/promo-bar` | No | Promo bar settings |
| `GET` | `/header-config` | No | Header settings |
| `GET` | `/image-popup` | No | Popup settings |
| `GET` | `/home` | No | Mobile home payload |
| `POST` | `/auth/login` | No | Login and issue token |
| `POST` | `/auth/register` | No | Register and issue token |
| `POST` | `/auth/logout` | Yes | Revoke current token |
| `GET` | `/user/me` | Yes | Current user |
| `PUT/PATCH` | `/user/account` | Yes | Update account |
| `POST` | `/user/avatar` | Yes | Upload profile photo |
| `GET` | `/user/loyalty` | Yes | Loyalty summary |
| `GET` | `/user/coupons` | Yes | Coupon list |
| `GET` | `/orders` | Yes | Order history |
| `GET` | `/orders/{id}` | Yes | Order detail |
| `GET` | `/products/filters` | No | Filter metadata |
| `GET` | `/products/{id}` | No | Product detail |
| `GET` | `/drawer-brands-categories` | No | Navigation categories and brands |
| `GET` | `/wishlist` | Yes | Wishlist items |
| `POST` | `/wishlist` | Yes | Add wishlist item |
| `DELETE` | `/wishlist/{product_id}` | Yes | Remove wishlist item |
| `GET` | `/cart` | Yes | Cart contents |
| `POST` | `/cart/add-to-cart` | Yes | Add item to cart |
| `POST` | `/cart/update-item` | Yes | Update item quantity |
| `DELETE` | `/cart/remove-item/{key}` | Yes | Remove one item |
| `DELETE` | `/cart/clear` | Yes | Empty the cart |
| `GET` | `/payments/methods` | Yes | Payment method placeholder |
| `POST` | `/payments/create` | Yes | Payment creation placeholder |
