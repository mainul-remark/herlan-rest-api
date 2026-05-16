# Herlan REST API Documentation

Mobile application API for Herlan Live.

## Base URL

Local development:

```text
http://localhost/herlanlive3/wp-json/herlan/v1
```

Production:

```text
https://YOUR_DOMAIN/wp-json/herlan/v1
```

## Response Format

Successful responses use this wrapper:

```json
{
  "success": true,
  "message": "",
  "data": {}
}
```

WordPress REST errors use this format:

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

Protected endpoints require a bearer token:

```http
Authorization: Bearer USER_ID_BASE64.SECRET
```

Tokens are issued by login/register and expire after 30 days.

Public endpoints:

- `GET /status`
- `GET /promo-bar`
- `GET /header-config`
- `GET /image-popup`
- `POST /auth/login`
- `POST /auth/register`
- `GET /products/{id}`
- `GET /products/filters`
- `GET /drawer-brands-categories`

Protected endpoints:

- `POST /auth/logout`
- `GET /user/me`
- `PUT/PATCH /user/account`
- `GET /user/loyalty`
- `GET /user/coupons`
- `GET /orders`
- `GET /orders/{id}`
- `GET /wishlist`
- `POST /wishlist`
- `DELETE /wishlist/{product_id}`
- `GET /payments/methods`
- `POST /payments/create`

## Endpoints

## Status

### `GET /status`

Returns API status and site metadata.

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

## Promo Bar

### `GET /promo-bar`

Returns the site-wide promotional top bar configuration saved under **Herlan Settings → Top Bar Promo**. Mobile apps use this to decide whether to show the banner and with what content.

This endpoint is public.

Example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/promo-bar"
```

Response — promo bar enabled:

```json
{
  "success": true,
  "message": "",
  "data": {
    "enabled": true,
    "bg_color": "#D50032",
    "text": "<p>Free shipping on orders over <strong>৳999</strong>!</p>"
  }
}
```

Response — promo bar disabled:

```json
{
  "success": true,
  "message": "",
  "data": {
    "enabled": false,
    "bg_color": "#333333",
    "text": ""
  }
}
```

### Fields

| Field | Type | Notes |
| --- | --- | --- |
| `enabled` | boolean | Whether the promo bar is turned on in admin settings |
| `bg_color` | string | Hex background color for the bar, e.g. `#D50032` |
| `text` | string | Sanitized HTML content to display inside the bar |

> When `enabled` is `false`, hide the bar entirely regardless of `text` or `bg_color` values.

## Header Config

### `GET /header-config`

Returns the header appearance settings saved under **Herlan Settings → Header**. Mobile apps use this to theme the app header colors and optionally display decorative ornament images.

This endpoint is public.

Example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/header-config"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "top_bar": {
      "bg_color": "#ffffff",
      "text_color": "#000000"
    },
    "nav": {
      "bg_color": "#ffffff",
      "text_color": "#000000"
    },
    "mini_cart_badge_color": "#D50032",
    "ornaments": {
      "enabled": true,
      "left": "https://herlan.com/wp-content/uploads/ornament-left.png",
      "right": "https://herlan.com/wp-content/uploads/ornament-right.png"
    }
  }
}
```

### Fields

| Field | Type | Notes |
| --- | --- | --- |
| `top_bar.bg_color` | string | Hex background color for the top bar |
| `top_bar.text_color` | string | Hex text color for the top bar |
| `nav.bg_color` | string | Hex background color for the navigation bar |
| `nav.text_color` | string | Hex text color for the navigation bar |
| `mini_cart_badge_color` | string | Hex background color for the cart item count badge |
| `ornaments.enabled` | boolean | Whether header ornament images are active |
| `ornaments.left` | string | URL of the left ornament image, empty string if not set |
| `ornaments.right` | string | URL of the right ornament image, empty string if not set |

> When `ornaments.enabled` is `false`, ignore the `left` and `right` image URLs.

## Image Popup

### `GET /image-popup`

Returns the image popup configuration saved under **Herlan Settings → Image Popup**. Mobile apps use this to decide whether to display a promotional popup, when to show it again, and which image to use.

This endpoint is public.

Example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/image-popup"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "enabled": true,
    "display_location": "all",
    "show_again_after": {
      "hours": 24,
      "minutes": 0,
      "seconds": 0
    },
    "image": "https://herlan.com/wp-content/uploads/popup-desktop.jpg",
    "mobile": {
      "enabled": true,
      "image": "https://herlan.com/wp-content/uploads/popup-mobile.jpg"
    },
    "cta_url": "https://herlan.com/shop/"
  }
}
```

### Fields

| Field | Type | Notes |
| --- | --- | --- |
| `enabled` | boolean | Whether the popup is active |
| `display_location` | string | `all` — all pages, `checkout` — checkout page only |
| `show_again_after.hours` | integer | Hours before the popup is shown again |
| `show_again_after.minutes` | integer | Additional minutes |
| `show_again_after.seconds` | integer | Additional seconds. All three at `0` means show on every page load |
| `image` | string\|null | Desktop popup image URL, `null` if not set |
| `mobile.enabled` | boolean | Whether a separate mobile image is configured |
| `mobile.image` | string\|null | Mobile popup image URL, `null` if not set |
| `cta_url` | string\|null | URL to open when the popup image is tapped, `null` if not set |

> **Mobile image logic:** if `mobile.enabled` is `true` and `mobile.image` is not `null`, use `mobile.image` on mobile devices. Otherwise fall back to `image`.

> **Show again timer:** store the timestamp when the popup was last shown locally on the device and compare it against the total interval (`hours × 3600 + minutes × 60 + seconds`) to decide whether to show the popup again.

## Auth

### `POST /auth/login`

Login with WordPress username/email and password.

Request:

```json
{
  "username": "customer@example.com",
  "password": "password123",
  "device_name": "iPhone 15"
}
```

Example:

```bash
curl -X POST "http://localhost/herlanlive3/wp-json/herlan/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"customer@example.com\",\"password\":\"password123\",\"device_name\":\"iPhone 15\"}"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "token_type": "Bearer",
    "access_token": "MQ.example_token_secret",
    "expires_at": "2026-05-30T06:00:00+00:00",
    "user": {
      "id": 1,
      "name": "Customer Name",
      "first_name": "Customer",
      "last_name": "Name",
      "email": "customer@example.com",
      "username": "customer@example.com",
      "roles": ["customer"]
    }
  }
}
```

Errors:

- `401 herlan_invalid_credentials`

### `POST /auth/register`

Create a WordPress user and return a mobile token.

WordPress registration must be enabled.

Request:

```json
{
  "name": "Customer Name",
  "email": "customer@example.com",
  "password": "password123"
}
```

Response data is the same shape as login.

Errors:

- `403 herlan_registration_disabled`
- `409 herlan_email_exists`
- `422 herlan_invalid_email`
- `422 herlan_weak_password`

### `POST /auth/logout`

Revokes the current bearer token.

Example:

```bash
curl -X POST "http://localhost/herlanlive3/wp-json/herlan/v1/auth/logout" \
  -H "Authorization: Bearer MQ.example_token_secret"
```

Response:

```json
{
  "success": true,
  "message": "Logged out successfully.",
  "data": {}
}
```

## User

### `GET /user/me`

Returns the authenticated user.

Example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/user/me" \
  -H "Authorization: Bearer MQ.example_token_secret"
```

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
      "roles": ["customer"]
    }
  }
}
```

### `PUT /user/account` · `PATCH /user/account`

Updates the authenticated user's profile. All fields are optional — only send what needs to change.

Changing `email` or `new_password` requires `current_password`.

Request body fields:

| Field | Type | Notes |
| --- | --- | --- |
| `first_name` | string | No password required |
| `last_name` | string | No password required |
| `display_name` | string | No password required |
| `email` | string | Requires `current_password` |
| `current_password` | string | Required when changing email or password |
| `new_password` | string | Min 8 characters. Requires `current_password` |

Example — update name only:

```bash
curl -X PATCH "http://localhost/herlanlive3/wp-json/herlan/v1/user/account" \
  -H "Authorization: Bearer MQ.example_token_secret" \
  -H "Content-Type: application/json" \
  -d "{\"first_name\":\"Jane\",\"last_name\":\"Doe\"}"
```

Example — change password:

```bash
curl -X PATCH "http://localhost/herlanlive3/wp-json/herlan/v1/user/account" \
  -H "Authorization: Bearer MQ.example_token_secret" \
  -H "Content-Type: application/json" \
  -d "{\"current_password\":\"oldpass123\",\"new_password\":\"newpass456\"}"
```

Response:

```json
{
  "success": true,
  "message": "Account updated successfully.",
  "data": {
    "user": {
      "id": 1,
      "name": "Jane Doe",
      "first_name": "Jane",
      "last_name": "Doe",
      "email": "customer@example.com",
      "username": "customer@example.com",
      "roles": ["customer"]
    }
  }
}
```

Errors:

- `401 herlan_invalid_password` — current password is wrong
- `409 herlan_email_exists` — email already in use by another account
- `422 herlan_password_required` — tried to change email/password without providing `current_password`
- `422 herlan_invalid_email`
- `422 herlan_weak_password`

### `GET /user/loyalty`

Returns the authenticated user's loyalty program summary. Requires the Herlan Loyalty plugin to be active and the user's billing phone number to be set.

Results are cached per user for 5 minutes.

Example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/user/loyalty" \
  -H "Authorization: Bearer MQ.example_token_secret"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "customer": {
      "name": "Customer Name",
      "phone": "01712345678",
      "status": "Active",
      "level": "Gold"
    },
    "points": 1250,
    "available_cash": 125.50,
    "total_spent": 15000.00,
    "level": {
      "name": "Gold",
      "color": "#DDAE56",
      "progress_percent": 62.5,
      "next": "Platinum",
      "next_at": 20000,
      "retention": {
        "message": "Spend BDT 5,000 more to keep Gold level.",
        "target": 12000
      }
    },
    "next_expiring_cash": null,
    "transactions": {
      "purchase_orders": [],
      "redeem_orders": [],
      "cashes": []
    }
  }
}
```

Errors:

- `422 herlan_loyalty_no_phone` — no billing phone on the account
- `502 herlan_loyalty_auth_failed` — loyalty API is unreachable or rejected the request
- `503 herlan_loyalty_unavailable` — Herlan Loyalty plugin is not active

## Coupons

### `GET /user/coupons`

Returns the authenticated user's coupons from the WooCommerce Smart Coupon plugin. Requires the `wt-smart-coupon-pro` plugin to be active.

Query parameters:

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `type` | string | `available` | `available`, `expired`, or `used` |
| `page` | integer | `1` | Page number |
| `per_page` | integer | `10` | Coupons per page, max `50` |
| `orderby` | string | `created_date:asc` | `created_date:asc`, `created_date:desc`, `amount:asc`, `amount:desc`. Ignored for `type=used` |

Examples:

```bash
# Available coupons
curl "http://localhost/herlanlive3/wp-json/herlan/v1/user/coupons" \
  -H "Authorization: Bearer MQ.example_token_secret"

# Expired coupons, newest first
curl "http://localhost/herlanlive3/wp-json/herlan/v1/user/coupons?type=expired&orderby=created_date:desc" \
  -H "Authorization: Bearer MQ.example_token_secret"

# Used coupons, page 2
curl "http://localhost/herlanlive3/wp-json/herlan/v1/user/coupons?type=used&page=2" \
  -H "Authorization: Bearer MQ.example_token_secret"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "coupons": [
      {
        "id": 4210,
        "code": "WELCOME10",
        "discount_type": "percent",
        "coupon_type": "Cart discount",
        "amount": 10.0,
        "coupon_amount": "10%",
        "description": "Welcome discount for new customers.",
        "free_shipping": false,
        "starts_at": null,
        "expires_at": "2026-12-31T23:59:59+00:00",
        "is_expired": false,
        "minimum_amount": "500",
        "maximum_amount": "",
        "usage_limit": 1,
        "usage_count": 0,
        "usage_limit_per_user": 1,
        "used_by_current_user": 0,
        "individual_use": true,
        "email_restrictions": []
      }
    ],
    "pagination": {
      "page": 1,
      "per_page": 10,
      "has_more": false
    }
  }
}
```

> **Note:** `type=available` and `type=expired` use `has_more` pagination (no `total` count) because the plugin's coupon eligibility rules are applied live and a total count query is not available. `type=used` includes `total` and `total_pages` because all used coupon codes are fetched from order history upfront.

### Coupon Fields

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | WooCommerce coupon post ID |
| `code` | string | Coupon code to apply at checkout |
| `discount_type` | string | Raw type: `fixed_cart`, `fixed_product`, `percent`, `percent_product`, `store_credit`, `wbte_sc_bogo`, etc. |
| `coupon_type` | string | Human-readable label: `Cart discount`, `Product discount`, `Free shipping`, `Free products`, `Store credit`, etc. |
| `amount` | float | Raw numeric discount amount |
| `coupon_amount` | string | Formatted amount, e.g. `৳690` or `10%`. Empty for free shipping / free product coupons |
| `description` | string | Admin-defined coupon description |
| `free_shipping` | boolean | Whether the coupon grants free shipping |
| `starts_at` | string\|null | ISO 8601 start date, or `null` if no start restriction |
| `expires_at` | string\|null | ISO 8601 expiry date, or `null` if the coupon never expires |
| `is_expired` | boolean | `true` when `expires_at` is in the past |
| `minimum_amount` | string | Minimum order subtotal required, empty string if none |
| `maximum_amount` | string | Maximum order subtotal allowed, empty string if none |
| `usage_limit` | integer | Global usage limit, `0` means unlimited |
| `usage_count` | integer | Total number of times the coupon has been used |
| `usage_limit_per_user` | integer | Per-user usage limit, `0` means unlimited |
| `used_by_current_user` | integer | Number of times the authenticated user has used this coupon |
| `individual_use` | boolean | Cannot be combined with other coupons if `true` |
| `email_restrictions` | array | Email addresses the coupon is restricted to, empty array if open |

Errors:

- `503 herlan_coupons_unavailable` — `wt-smart-coupon-pro` plugin is not active

## Orders

### `GET /orders`

Returns the authenticated user's WooCommerce order history, newest first.

Query parameters:

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `page` | integer | `1` | Page number |
| `per_page` | integer | `10` | Orders per page, max `50` |
| `status` | string | `any` | WooCommerce order status, for example `processing`, `completed`, `cancelled` |

Example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/orders" \
  -H "Authorization: Bearer MQ.example_token_secret"
```

Filtered example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/orders?status=completed&per_page=5" \
  -H "Authorization: Bearer MQ.example_token_secret"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "orders": [
      {
        "id": 5021,
        "number": "5021",
        "status": "processing",
        "date_created": "2026-05-10T14:32:00+06:00",
        "currency": "BDT",
        "total": "1380",
        "item_count": 2,
        "payment_method_title": "bKash"
      }
    ],
    "pagination": {
      "page": 1,
      "per_page": 10,
      "total": 24,
      "total_pages": 3
    }
  }
}
```

### `GET /orders/{id}`

Returns full detail of a single order. Users can only access their own orders.

Example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/orders/5021" \
  -H "Authorization: Bearer MQ.example_token_secret"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "id": 5021,
    "number": "5021",
    "status": "processing",
    "date_created": "2026-05-10T14:32:00+06:00",
    "date_modified": "2026-05-10T14:35:00+06:00",
    "currency": "BDT",
    "total": "1380",
    "subtotal": "1380",
    "total_tax": "0",
    "shipping_total": "0",
    "discount_total": "0",
    "payment_method": "bkash",
    "payment_method_title": "bKash",
    "transaction_id": "TXN123456",
    "customer_note": "",
    "billing": {
      "first_name": "Customer",
      "last_name": "Name",
      "company": "",
      "address_1": "123 Gulshan Avenue",
      "address_2": "",
      "city": "Dhaka",
      "state": "DH",
      "postcode": "1212",
      "country": "BD",
      "email": "customer@example.com",
      "phone": "01712345678"
    },
    "shipping": {
      "first_name": "Customer",
      "last_name": "Name",
      "company": "",
      "address_1": "123 Gulshan Avenue",
      "address_2": "",
      "city": "Dhaka",
      "state": "DH",
      "postcode": "1212",
      "country": "BD",
      "email": "",
      "phone": ""
    },
    "line_items": [
      {
        "id": 88,
        "product_id": 10119,
        "variation_id": null,
        "name": "Herlan Cushion Matte Lipstick Vintage Vibes",
        "sku": "HL-LIP-VV",
        "quantity": 2,
        "subtotal": "1380",
        "total": "1380",
        "image": "http://localhost/herlanlive3/wp-content/uploads/product.jpg"
      }
    ],
    "coupon_lines": []
  }
}
```

### Order Fields

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | WooCommerce order ID |
| `number` | string | Order number displayed to customer |
| `status` | string | Order status without `wc-` prefix |
| `date_created` | string | ISO 8601 date |
| `date_modified` | string | ISO 8601 date |
| `currency` | string | Currency code, for example `BDT` |
| `total` | string | Order grand total |
| `subtotal` | string | Sum of line item subtotals |
| `total_tax` | string | Total tax amount |
| `shipping_total` | string | Shipping cost |
| `discount_total` | string | Total discount applied |
| `payment_method` | string | Payment method slug |
| `payment_method_title` | string | Payment method display name |
| `transaction_id` | string | Gateway transaction ID |
| `customer_note` | string | Note left by the customer |
| `billing` | object | Billing address |
| `shipping` | object | Shipping address |
| `line_items` | array | Ordered products |
| `coupon_lines` | array | Applied coupons |

### Line Item Fields

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Order item ID |
| `product_id` | integer | Parent product ID |
| `variation_id` | integer\|null | Variation ID, null for simple products |
| `name` | string | Product name at time of order |
| `sku` | string\|null | Product SKU |
| `quantity` | integer | Quantity ordered |
| `subtotal` | string | Line subtotal before coupons |
| `total` | string | Line total after coupons |
| `image` | string\|null | Product image URL |

Errors:

- `404 herlan_order_not_found` — order does not exist or belongs to another user
- `500 herlan_woocommerce_unavailable`

## Products

### `GET /products/filters`

Returns mobile filter metadata for shop and taxonomy pages.

This endpoint is public.

Query parameters:

| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `taxonomy` | string | No | Current archive taxonomy, for example `product_cat`, `brand`, `keywords`, `pa_colors` |
| `term` | string | No | Current archive term slug |
| `include_counts` | boolean | No | Defaults to `true`; when true, each term count is calculated in the current context |

Shop page example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/products/filters"
```

Category page example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/products/filters?taxonomy=product_cat&term=makeup"
```

Brand page example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/products/filters?taxonomy=brand&term=herlan"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "context": {
      "taxonomy": "product_cat",
      "term": "makeup"
    },
    "sort_options": [
      {"key": "popularity", "label": "Best Selling", "orderby": "sales"},
      {"key": "date", "label": "New Arrivals", "orderby": "date"},
      {"key": "price_asc", "label": "Price: Low to High", "orderby": "price", "order": "ASC"},
      {"key": "price_desc", "label": "Price: High to Low", "orderby": "price", "order": "DESC"},
      {"key": "rating", "label": "Top Rated", "orderby": "rating"}
    ],
    "price_range": {
      "min": 120,
      "max": 3200
    },
    "filters": [
      {
        "taxonomy": "brand",
        "label": "Brands",
        "type": "taxonomy",
        "hierarchical": false,
        "terms": [
          {
            "id": 12,
            "name": "Herlan",
            "slug": "herlan",
            "taxonomy": "brand",
            "count": 24,
            "parent": 0,
            "link": "http://localhost/herlanlive3/brand/herlan/",
            "color": "",
            "image": null
          }
        ]
      },
      {
        "taxonomy": "pa_colors",
        "label": "Product Colors",
        "type": "attribute",
        "hierarchical": false,
        "terms": []
      }
    ]
  }
}
```

Filter taxonomies currently include public product taxonomies and product attributes:

- `product_cat`
- `brand`
- `product_tag`
- `keywords`
- `pa_*` product attributes, such as `pa_colors`, `pa_size`, `pa_variant`

Internal WooCommerce taxonomies like `product_type`, `product_visibility`, shipping class, and POS visibility are excluded.

### `GET /products/{id}`

Returns one published WooCommerce product for mobile product details.

This endpoint is public.

Example:

```bash
curl "http://localhost/herlanlive3/wp-json/herlan/v1/products/10119"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "id": 10119,
    "name": "Herlan Cushion Matte Lipstick Vintage Vibes",
    "slug": "herlan-cushion-matte-lipstick-vintage-vibes",
    "type": "simple",
    "permalink": "http://localhost/herlanlive3/product/herlan-cushion-matte-lipstick-vintage-vibes/",
    "status": "publish",
    "sku": "",
    "description": "<p>...</p>",
    "short_description": "<p>...</p>",
    "price": "690",
    "regular_price": "690",
    "sale_price": "",
    "price_html": "<span class=\"woocommerce-Price-amount amount\">...</span>",
    "on_sale": false,
    "purchasable": true,
    "stock_status": "instock",
    "stock_quantity": null,
    "average_rating": "0",
    "rating_count": 0,
    "categories": [],
    "tags": [],
    "brand": null,
    "attributes": [],
    "images": [],
    "custom_fields": {},
    "linked_products": {
      "enabled": true,
      "link_id": 123,
      "source": "products",
      "attributes": []
    }
  }
}
```

### Product Fields

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | WooCommerce product ID |
| `name` | string | Product name |
| `slug` | string | Product slug |
| `type` | string | WooCommerce product type |
| `permalink` | string | Product page URL |
| `status` | string | Only published products are returned |
| `sku` | string | Product SKU |
| `description` | string | Sanitized product description HTML |
| `short_description` | string | Sanitized short description HTML |
| `price` | string | Current product price |
| `regular_price` | string | Regular price |
| `sale_price` | string | Sale price, empty if not on sale |
| `price_html` | string | WooCommerce formatted price HTML |
| `on_sale` | boolean | Whether the product is on sale |
| `purchasable` | boolean | WooCommerce purchasable flag |
| `stock_status` | string | `instock`, `outofstock`, or `onbackorder` |
| `stock_quantity` | integer\|null | Stock quantity if managed |
| `average_rating` | string | WooCommerce average rating |
| `rating_count` | integer | Review rating count |
| `categories` | array | Product categories |
| `tags` | array | Product tags |
| `brand` | object\|null | First `brand` taxonomy term |
| `taxonomies` | object | Assigned public product taxonomies and product attributes, keyed by taxonomy slug |
| `attributes` | array | WooCommerce product attributes |
| `images` | array | Main image and gallery |
| `custom_fields` | object | ACF fields from `get_fields($product_id)` |
| `linked_products` | object | WPC Linked Variation data |
| `recommendations` | object | Product recommendation sections from the theme/custom integration |
| `variations` | array | Present only for variable products |

### Taxonomies Shape

`taxonomies` includes assigned product taxonomies such as `brand`, `product_cat`, `product_tag`, `keywords`, and product attributes like `pa_colors`. Internal WooCommerce taxonomies like `product_type`, `product_visibility`, and shipping class are excluded.

```json
{
  "taxonomies": {
    "keywords": {
      "name": "keywords",
      "label": "Keywords",
      "hierarchical": false,
      "terms": [
        {
          "id": 123,
          "name": "Paraben Free",
          "slug": "paraben-free",
          "taxonomy": "keywords",
          "description": "",
          "parent": 0,
          "count": 10,
          "link": "http://localhost/herlanlive3/keywords/paraben-free/",
          "color": "",
          "image": null
        }
      ]
    },
    "pa_colors": {
      "name": "pa_colors",
      "label": "Product Colors",
      "hierarchical": false,
      "terms": []
    }
  }
}
```

### Recommendations Shape

The single product response includes the same four product sections used on the product detail page:

- `you_may_like`
- `more_from_this_brand`
- `best_selling`
- `new_arrivals`

Each section has a title, archive URL, and product-card array:

```json
{
  "recommendations": {
    "you_may_like": {
      "title": "You May Like",
      "url": "http://localhost/herlanlive3/product-category/makeup/",
      "products": []
    },
    "more_from_this_brand": {
      "title": "More from this brand",
      "url": "http://localhost/herlanlive3/brand/herlan/",
      "products": []
    },
    "best_selling": {
      "title": "Best Selling",
      "url": "http://localhost/herlanlive3/shop/?orderby=sales",
      "products": []
    },
    "new_arrivals": {
      "title": "New Arrivals",
      "url": "http://localhost/herlanlive3/shop/?orderby=date",
      "products": []
    }
  }
}
```

Recommendation product card fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Product ID |
| `name` | string | Product name |
| `slug` | string | Product slug |
| `type` | string | WooCommerce product type |
| `permalink` | string | Product URL |
| `sku` | string | SKU |
| `price` | string | Current price |
| `regular_price` | string | Regular price |
| `sale_price` | string | Sale price |
| `price_html` | string | WooCommerce formatted price HTML |
| `on_sale` | boolean | Sale flag |
| `stock_status` | string | Stock status |
| `average_rating` | string | Average rating |
| `rating_count` | integer | Rating count |
| `brand` | object\|null | First brand term |
| `image` | object\|null | Main image |

### Linked Products Shape

`linked_products` is generated from the `wpc-linked-variation` plugin. It is structured for mobile swatch/linked-product UI and replaces the frontend HTML like `.wpclv-attributes`.

```json
{
  "enabled": true,
  "link_id": 456,
  "source": "products",
  "attributes": [
    {
      "id": 1,
      "name": "pa_colors",
      "slug": "pa_colors",
      "label": "Colors",
      "display": "swatches",
      "current_terms": ["vintage-vibes"],
      "terms": [
        {
          "term_id": 999,
          "name": "Vintage Vibes",
          "slug": "vintage-vibes",
          "color": "#a8483d",
          "image": null,
          "active": true,
          "in_stock": true,
          "product": {
            "id": 10119,
            "name": "Herlan Cushion Matte Lipstick Vintage Vibes",
            "slug": "herlan-cushion-matte-lipstick-vintage-vibes",
            "permalink": "http://localhost/herlanlive3/product/herlan-cushion-matte-lipstick-vintage-vibes/",
            "sku": "",
            "price": "690",
            "regular_price": "690",
            "sale_price": "",
            "on_sale": false,
            "stock_status": "instock",
            "image": null
          }
        }
      ]
    }
  ]
}
```

Linked term fields:

| Field | Type | Notes |
| --- | --- | --- |
| `term_id` | integer | Attribute term ID |
| `name` | string | Term label shown to users |
| `slug` | string | Term slug |
| `color` | string | Color value from WPC Variation Swatches meta, for example `#a8483d` |
| `image` | string\|null | Term swatch image URL, if configured |
| `active` | boolean | `true` for the current product term |
| `in_stock` | boolean | Stock state of the linked product |
| `product` | object\|null | Linked product summary, or `null` if no product is linked |

For product `10119`, the API returns the `pa_colors` linked attribute with terms such as:

```text
Vintage Vibes | active=true  | in_stock=true  | color=#a8483d | product.id=10119
Disco Diva    | active=false | in_stock=true  | color=#3e7155 | product.id=10150
Retro Rust    | active=false | in_stock=false | color=#803134 | product.id=10138
```

Errors:

- `404 herlan_product_not_found`
- `500 herlan_woocommerce_unavailable`

## Navigation

Returns all product categories (as a nested tree) and brands in a single request. Designed for mobile app navigation menus and category drawers.

This endpoint is public.

### `GET /drawer-brands-categories`

Query parameters:

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `hide_empty` | boolean | `true` | Exclude categories and brands that have no products |
| `categories_flat` | boolean | `false` | Return categories as a flat list instead of a nested tree |
| `order` | string | `asc` | Sort direction: `asc` or `desc` |
| `order_by` | string | `name` | Sort field: `name`, `count`, `id`, `slug` |

Examples:

```bash
# Default — nested categories + brands
curl "http://localhost/herlanlive3/wp-json/herlan/v1/navigation"

# Flat category list, sorted by product count descending
curl "http://localhost/herlanlive3/wp-json/herlan/v1/navigation?categories_flat=true&order_by=count&order=desc"

# Include empty categories and brands
curl "http://localhost/herlanlive3/wp-json/herlan/v1/navigation?hide_empty=false"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "categories": [
      {
        "id": 5,
        "name": "Makeup",
        "slug": "makeup",
        "description": "",
        "count": 50,
        "parent": 0,
        "link": "https://herlan.com/product-category/makeup/",
        "image": {
          "id": 301,
          "src": "https://herlan.com/wp-content/uploads/makeup.jpg",
          "alt": "Makeup"
        },
        "children": [
          {
            "id": 8,
            "name": "Lips",
            "slug": "lips",
            "description": "",
            "count": 20,
            "parent": 5,
            "link": "https://herlan.com/product-category/makeup/lips/",
            "image": null,
            "children": []
          },
          {
            "id": 9,
            "name": "Eyes",
            "slug": "eyes",
            "description": "",
            "count": 15,
            "parent": 5,
            "link": "https://herlan.com/product-category/makeup/eyes/",
            "image": null,
            "children": []
          }
        ]
      },
      {
        "id": 6,
        "name": "Skin Care",
        "slug": "skin-care",
        "description": "",
        "count": 30,
        "parent": 0,
        "link": "https://herlan.com/product-category/skin-care/",
        "image": {
          "id": 302,
          "src": "https://herlan.com/wp-content/uploads/skin-care.jpg",
          "alt": "Skin Care"
        },
        "children": []
      }
    ],
    "total_categories": 12,
    "brands": [
      {
        "id": 12,
        "name": "Herlan",
        "slug": "herlan",
        "description": "",
        "count": 124,
        "link": "https://herlan.com/brand/herlan/",
        "image": {
          "id": 502,
          "src": "https://herlan.com/wp-content/uploads/herlan-logo.jpg",
          "alt": "Herlan"
        }
      },
      {
        "id": 13,
        "name": "Herlan Pro",
        "slug": "herlan-pro",
        "description": "",
        "count": 45,
        "link": "https://herlan.com/brand/herlan-pro/",
        "image": {
          "id": 503,
          "src": "https://herlan.com/wp-content/uploads/herlan-pro-logo.jpg",
          "alt": "Herlan Pro"
        }
      }
    ],
    "total_brands": 5
  }
}
```

### Category Fields

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Term ID |
| `name` | string | Category name |
| `slug` | string | Category slug |
| `description` | string | Category description |
| `count` | integer | Number of products in this category |
| `parent` | integer | Parent term ID, `0` for top-level categories |
| `link` | string\|null | Category archive URL |
| `image` | object\|null | Category thumbnail (`thumbnail_id` meta) |
| `children` | array | Nested child categories, empty array if none |

### Brand Fields

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Term ID |
| `name` | string | Brand name |
| `slug` | string | Brand slug |
| `description` | string | Brand description |
| `count` | integer | Number of products under this brand |
| `link` | string\|null | Brand archive URL |
| `image` | object\|null | Brand logo (`logo` meta) |

### Image Object

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | WordPress attachment ID |
| `src` | string | Full image URL |
| `alt` | string | Image alt text |

### Flat Category Response

When `categories_flat=true`, categories are returned as a plain array ordered by the `order_by` parameter. The `parent` field identifies the hierarchy and `children` is always an empty array.

```json
{
  "data": {
    "categories": [
      { "id": 9,  "name": "Eyes",      "parent": 5, "children": [] },
      { "id": 8,  "name": "Lips",      "parent": 5, "children": [] },
      { "id": 5,  "name": "Makeup",    "parent": 0, "children": [] },
      { "id": 6,  "name": "Skin Care", "parent": 0, "children": [] }
    ],
    "total_categories": 4,
    "brands": [],
    "total_brands": 0
  }
}
```

## Wishlist

Requires the **TI WooCommerce Wishlist** plugin (`ti-woocommerce-wishlist`) to be active.

### `GET /wishlist`

Returns the authenticated user's wishlist items with pagination.

Query parameters:

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `page` | integer | `1` | Page number |
| `per_page` | integer | `10` | Items per page, max `50` |
| `order` | string | `desc` | Sort direction: `asc` or `desc` |
| `order_by` | string | `date` | Sort field: `date`, `price`, `product_id`, `quantity`, `ID` |

Examples:

```bash
# Default — newest first
curl "http://localhost/herlanlive3/wp-json/herlan/v1/wishlist" \
  -H "Authorization: Bearer MQ.example_token_secret"

# Page 2, 20 per page, sorted by price ascending
curl "http://localhost/herlanlive3/wp-json/herlan/v1/wishlist?page=2&per_page=20&order_by=price&order=asc" \
  -H "Authorization: Bearer MQ.example_token_secret"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [
      {
        "wishlist_item_id": 14,
        "product_id": 10119,
        "variation_id": null,
        "date_added": "2026-05-14 10:23:45",
        "quantity": 1,
        "product": {
          "id": 10119,
          "name": "Herlan Cushion Matte Lipstick Vintage Vibes",
          "slug": "herlan-cushion-matte-lipstick-vintage-vibes",
          "type": "simple",
          "permalink": "http://localhost/herlanlive3/product/herlan-cushion-matte-lipstick-vintage-vibes/",
          "sku": "HL-LIP-VV",
          "price": "690",
          "regular_price": "690",
          "sale_price": "",
          "price_html": "<span class=\"woocommerce-Price-amount amount\">৳690</span>",
          "on_sale": false,
          "stock_status": "instock",
          "in_stock": true,
          "image": {
            "id": 301,
            "src": "http://localhost/herlanlive3/wp-content/uploads/product.jpg",
            "alt": "Herlan Cushion Matte Lipstick"
          }
        }
      }
    ],
    "pagination": {
      "page": 1,
      "per_page": 10,
      "total": 24,
      "total_pages": 3
    }
  }
}
```

Errors:

- `500 herlan_wishlist_unavailable` — TI WooCommerce Wishlist plugin is not active

### `POST /wishlist`

Adds a product to the authenticated user's wishlist. If the product is already in the wishlist it is updated in place.

Request body:

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `product_id` | integer | Yes | WooCommerce product ID |
| `variation_id` | integer | No | Variation ID for variable products, defaults to `0` |

Example — simple product:

```bash
curl -X POST "http://localhost/herlanlive3/wp-json/herlan/v1/wishlist" \
  -H "Authorization: Bearer MQ.example_token_secret" \
  -H "Content-Type: application/json" \
  -d "{\"product_id\": 10119}"
```

Example — variable product:

```bash
curl -X POST "http://localhost/herlanlive3/wp-json/herlan/v1/wishlist" \
  -H "Authorization: Bearer MQ.example_token_secret" \
  -H "Content-Type: application/json" \
  -d "{\"product_id\": 10200, \"variation_id\": 10205}"
```

Response:

```json
{
  "success": true,
  "message": "Product added to wishlist.",
  "data": {}
}
```

Errors:

- `404 herlan_product_not_found`
- `500 herlan_wishlist_unavailable`
- `500 herlan_wishlist_error` — could not retrieve or create wishlist
- `500 herlan_wishlist_add_failed` — plugin rejected the add operation

### `DELETE /wishlist/{product_id}`

Removes a product from the authenticated user's wishlist.

Query parameters:

| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `variation_id` | integer | No | Required when the wishlisted item is a variation |

Example — simple product:

```bash
curl -X DELETE "http://localhost/herlanlive3/wp-json/herlan/v1/wishlist/10119" \
  -H "Authorization: Bearer MQ.example_token_secret"
```

Example — variation:

```bash
curl -X DELETE "http://localhost/herlanlive3/wp-json/herlan/v1/wishlist/10200?variation_id=10205" \
  -H "Authorization: Bearer MQ.example_token_secret"
```

Response:

```json
{
  "success": true,
  "message": "Product removed from wishlist.",
  "data": {}
}
```

Errors:

- `404 herlan_wishlist_not_found` — user has no wishlist yet
- `500 herlan_wishlist_unavailable`
- `500 herlan_wishlist_remove_failed` — plugin rejected the remove operation

## Payments

Payment endpoints are placeholders and require gateway integration before production use.

### `GET /payments/methods`

Protected endpoint. Returns configured mobile payment methods.

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

Protected endpoint. Placeholder for creating a payment intent/session/order payment request.

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

## Auth Error Codes

Protected endpoints can return:

- `401 herlan_missing_token`
- `401 herlan_invalid_token`
- `401 herlan_expired_token`

## Route Inventory

| Method | Path | Auth | Description |
| --- | --- | --- | --- |
| `GET` | `/status` | No | API status |
| `GET` | `/promo-bar` | No | Promotional top bar settings |
| `GET` | `/header-config` | No | Header colors and ornament settings |
| `GET` | `/image-popup` | No | Image popup configuration |
| `POST` | `/auth/login` | No | Login and issue token |
| `POST` | `/auth/register` | No | Register and issue token |
| `POST` | `/auth/logout` | Yes | Revoke current token |
| `GET` | `/user/me` | Yes | Current user profile |
| `PUT/PATCH` | `/user/account` | Yes | Update profile, email, or password |
| `GET` | `/user/loyalty` | Yes | Loyalty points, cash, level, and transactions |
| `GET` | `/user/coupons` | Yes | Available, expired, or used coupons |
| `GET` | `/orders` | Yes | Paginated order history |
| `GET` | `/orders/{id}` | Yes | Single order detail |
| `GET` | `/products/filters` | No | Shop and taxonomy filter metadata |
| `GET` | `/products/{id}` | No | Single product details |
| `GET` | `/navigation` | No | Nested categories and brands in one request |
| `GET` | `/wishlist` | Yes | Get user's wishlist items |
| `POST` | `/wishlist` | Yes | Add product to wishlist |
| `DELETE` | `/wishlist/{product_id}` | Yes | Remove product from wishlist |
| `GET` | `/payments/methods` | Yes | Payment method list placeholder |
| `POST` | `/payments/create` | Yes | Payment creation placeholder |
