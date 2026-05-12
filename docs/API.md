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
- `POST /auth/login`
- `POST /auth/register`
- `GET /products/{id}`
- `GET /products/filters`

Protected endpoints:

- `POST /auth/logout`
- `GET /user/me`
- `PUT/PATCH /user/account`
- `GET /user/loyalty`
- `GET /orders`
- `GET /orders/{id}`
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
| `POST` | `/auth/login` | No | Login and issue token |
| `POST` | `/auth/register` | No | Register and issue token |
| `POST` | `/auth/logout` | Yes | Revoke current token |
| `GET` | `/user/me` | Yes | Current user profile |
| `PUT/PATCH` | `/user/account` | Yes | Update profile, email, or password |
| `GET` | `/user/loyalty` | Yes | Loyalty points, cash, level, and transactions |
| `GET` | `/orders` | Yes | Paginated order history |
| `GET` | `/orders/{id}` | Yes | Single order detail |
| `GET` | `/products/filters` | No | Shop and taxonomy filter metadata |
| `GET` | `/products/{id}` | No | Single product details |
| `GET` | `/payments/methods` | Yes | Payment method list placeholder |
| `POST` | `/payments/create` | Yes | Payment creation placeholder |
