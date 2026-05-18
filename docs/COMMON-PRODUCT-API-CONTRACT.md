# Common Product API Contract

Proposed contract for a single archive/search/filter API for Herlan product data.

This document defines the API shape only. It does **not** mean the endpoint is implemented yet.

## Goal

Provide one REST endpoint that can power:

- product category pages
- brand pages
- custom taxonomy archive pages
- filtered product listing pages
- product search result pages
- mobile app product listing
- frontend filter drawers

The endpoint should support:

- archive context such as `brand=nior` or `product_cat=lipstick`
- taxonomy filters such as `brand`, `age-range`, `skin-type`, `keywords`
- attribute filters such as `pa_colors`, `pa_size`, `pa_variant`
- price, stock, sale, sort, and pagination
- filter term counts based on the current context
- alignment with the current `wcapf` filter plugin configuration

## Pagination Rule

Every successful non-POST collection-style response in this contract must include a `pagination` object.

This applies to:

- product listing responses
- filter config list responses
- filter term list responses
- sort option list responses

This does not apply to:

- `POST` endpoints
- single-object detail responses where pagination is not meaningful

## Endpoint

```text
GET /wp-json/herlan/v1/products
```

If a plugin-specific namespace is preferred later, the same contract can be exposed under:

```text
GET /wp-json/{plugin-namespace}/v1/products
```

Additional compatibility/config endpoints recommended for `wcapf` alignment:

```text
GET /wp-json/herlan/v1/filter-config
GET /wp-json/herlan/v1/filter-forms/{id}
GET /wp-json/herlan/v1/filter-terms
```

## Response Wrapper

Successful responses use:

```json
{
  "success": true,
  "message": "",
  "data": {}
}
```

Errors use standard WordPress REST error format:

```json
{
  "code": "herlan_error_code",
  "message": "Error message.",
  "data": {
    "status": 400
  }
}
```

## Supported Context Taxonomies

Archive context may be any of:

- `product_cat`
- `product_tag`
- `brand`
- `keywords`
- `skin-type`
- `age-range`

## Supported Filter Taxonomies

Taxonomy filters may include:

- `product_cat`
- `product_tag`
- `brand`
- `keywords`
- `skin-type`
- `age-range`
- `pa_colors`
- `pa_size`
- `pa_variant`

The endpoint should be implemented so more `pa_*` attributes can be supported later without changing the contract.

## WCAPF Alignment

The website currently uses the **WooCommerce Ajax Product Filter** plugin (`wc-ajax-product-filter`, `wcapf`) for filtering behavior.

The API contract should therefore support two layers:

1. canonical API parameters and response shapes
2. compatibility with `wcapf` filter keys, sort options, and filter form configuration

Recommended design:

- use `wcapf` as the admin/source-of-truth for filter configuration
- use the common products API as the product-result engine
- expose a normalized `wcapf` config API for mobile developers

This means the mobile app should not need to parse raw `wcapf` storage structures directly.

## Proposed Endpoint Set

### 1. Products API

```text
GET /wp-json/herlan/v1/products
```

Purpose:

- return filtered products
- return active filters
- return available filters with counts
- return pagination

### 2. Filter Config API

```text
GET /wp-json/herlan/v1/filter-config
```

Purpose:

- return the normalized filter configuration that mobile should render
- return `wcapf`-derived filter definitions when applicable
- return sorting and per-page options
- return pagination

### 3. Filter Form API

```text
GET /wp-json/herlan/v1/filter-forms/{id}
```

Purpose:

- inspect one specific `wcapf` form
- return its normalized fields, settings, and applicable filter keys

### 4. Filter Terms API

```text
GET /wp-json/herlan/v1/filter-terms
```

Purpose:

- return terms for one requested filter taxonomy
- return counts in current context
- return pagination for large term sets

## Request Parameters

### Archive context

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `context_taxonomy` | string | No | Base archive taxonomy slug |
| `context_term` | string | No | Base archive term slug |

Rules:

- If both are missing, the endpoint behaves like a general product listing.
- If `context_taxonomy` is provided, `context_term` is required.
- `context_taxonomy` must be one of the supported context taxonomies.
- For `product_cat`, child categories should be included by default in the base context query.

### Taxonomy filters

Each taxonomy filter uses this format:

```text
filter_{taxonomy}=slug-a,slug-b,slug-c
```

Examples:

```text
filter_brand=nior,cavotin
filter_product_cat=eyes,eyebrow
filter_skin-type=combination-skin
filter_age-range=25-35
filter_keywords=paraben-free,long-lasting
filter_pa_colors=vintage-vibes,retro-rust
filter_pa_size=100ml,200ml
filter_pa_variant=aloe,berry
```

### Filter operators

Optional operator parameters:

```text
filter_operator_{taxonomy}=in|and|not_in
```

Examples:

```text
filter_operator_brand=in
filter_operator_product_cat=and
filter_operator_keywords=not_in
```

Default behavior:

- Within one taxonomy, default operator is `in`
- Across different taxonomies, the global relationship is `AND`

### Price and stock filters

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `min_price` | number | No | Minimum catalog price |
| `max_price` | number | No | Maximum catalog price |
| `stock_status` | string | No | `instock`, `outofstock`, or `onbackorder` |
| `on_sale` | boolean/integer | No | `1` or `true` to limit to on-sale products |

### Search and sort

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `search` | string | No | Product text search |
| `orderby` | string | No | `menu_order`, `popularity`, `rating`, `date`, `price`, `price-desc`, `title` |
| `order` | string | No | `asc` or `desc` when relevant |

Recommended sort mapping:

- `popularity` -> WooCommerce sales ordering
- `rating` -> average rating
- `date` -> newest first
- `price` -> low to high
- `price-desc` -> high to low
- `menu_order` -> default catalog sort
- `title` -> alphabetical

### Pagination

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `page` | integer | No | Page number, default `1` |
| `per_page` | integer | No | Products per page, default `12`, max `50` |

### Include flags

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `include_filters` | boolean/integer | No | Return filter groups and counts. Default `1` |
| `include_products` | boolean/integer | No | Return product list. Default `1` |
| `include_pagination` | boolean/integer | No | Return pagination block. Default `1` |
| `form_id` | integer | No | Explicit `wcapf` form to align with when building filter config |
| `include_wcapf` | boolean/integer | No | Include `wcapf` compatibility metadata. Default `0` |

## Backward Compatibility Aliases

The endpoint should accept legacy frontend query aliases and normalize them internally.

| Legacy | Canonical |
| --- | --- |
| `_brand` | `filter_brand` |
| `product-cat` | `filter_product_cat` |
| `_skin-type` | `filter_skin-type` |
| `_age-range` | `filter_age-range` |
| `_keywords` | `filter_keywords` |
| `sort-by` | `orderby` |

If the `wcapf` plugin defines custom filter keys for specific filters, the API should normalize those to the canonical `filter_{taxonomy}` format while optionally returning the original `wcapf` keys in metadata.

Example:

```text
/wp-json/herlan/v1/products?context_taxonomy=brand&context_term=nior&product-cat=eyes,eyebrow&_skin-type=combination-skin
```

Should be normalized as:

```text
context_taxonomy=brand
context_term=nior
filter_product_cat=eyes,eyebrow
filter_skin-type=combination-skin
```

## Query Rules

### Base query

The endpoint should query:

- `post_type=product`
- `post_status=publish`

### Context application

If archive context is present, add one base `tax_query` rule:

```json
{
  "taxonomy": "brand",
  "field": "slug",
  "terms": ["nior"]
}
```

For `product_cat`, use:

```json
{
  "taxonomy": "product_cat",
  "field": "slug",
  "terms": ["lipstick"],
  "include_children": true
}
```

### Filter application

Each `filter_{taxonomy}` becomes an additional `tax_query` rule.

Different taxonomies are always combined with:

```text
AND
```

Within the same taxonomy:

- `in` means any selected term
- `and` means product must match all selected terms
- `not_in` excludes matching terms

### Meta filters

Price filter uses `_price`.

Stock filter uses WooCommerce stock status.

Sale filter should use WooCommerce sale logic rather than raw meta assumptions.

## Response Contract

```json
{
  "success": true,
  "message": "",
  "data": {
    "context": {},
    "request": {},
    "products": [],
    "filters": {},
    "wcapf": {},
    "pagination": {}
  }
}
```

## `data.context`

```json
{
  "taxonomy": "brand",
  "label": "Brand",
  "term": {
    "id": 12,
    "name": "Nior",
    "slug": "nior",
    "taxonomy": "brand",
    "description": "",
    "parent": 0,
    "count": 24,
    "link": "https://herlan.com/brand/nior/",
    "image": {
      "id": 501,
      "src": "https://herlan.com/wp-content/uploads/nior-logo.jpg",
      "alt": "Nior"
    }
  }
}
```

If no archive context exists:

```json
{
  "taxonomy": null,
  "label": null,
  "term": null
}
```

## `data.request`

Normalized request metadata after alias handling and sanitization.

```json
{
  "page": 1,
  "per_page": 12,
  "orderby": "popularity",
  "order": "desc",
  "search": null,
  "filters": {
    "filter_product_cat": ["eyes", "eyebrow"],
    "filter_skin-type": ["combination-skin"],
    "filter_brand": ["nior"],
    "min_price": "300",
    "max_price": "1200",
    "stock_status": "instock",
    "on_sale": true
  }
}
```

## `data.products`

Product list for archive cards and app listing.

Each product should return a lightweight but complete listing object.

```json
{
  "id": 10119,
  "name": "Herlan Cushion Matte Lipstick Vintage Vibes",
  "slug": "herlan-cushion-matte-lipstick-vintage-vibes",
  "type": "simple",
  "permalink": "https://herlan.com/product/herlan-cushion-matte-lipstick-vintage-vibes/",
  "sku": "HL-LIP-VV",
  "price": "690",
  "regular_price": "690",
  "sale_price": "",
  "price_html": "<span class=\"woocommerce-Price-amount amount\">690</span>",
  "on_sale": false,
  "stock_status": "instock",
  "in_stock": true,
  "average_rating": "0",
  "rating_count": 0,
  "brand": {
    "id": 12,
    "name": "Herlan",
    "slug": "herlan"
  },
  "image": {
    "id": 301,
    "src": "https://herlan.com/wp-content/uploads/product.jpg",
    "alt": "Herlan Cushion Matte Lipstick"
  },
  "categories": [
    {
      "id": 88,
      "name": "Lipstick",
      "slug": "lipstick"
    }
  ],
  "tags": [
    {
      "id": 45,
      "name": "Herlan Cushion Matte Lipstick",
      "slug": "herlan-cushion-matte-lipstick"
    }
  ],
  "taxonomies": {
    "brand": [
      {
        "id": 12,
        "name": "Herlan",
        "slug": "herlan"
      }
    ],
    "product_cat": [
      {
        "id": 88,
        "name": "Lipstick",
        "slug": "lipstick"
      }
    ],
    "skin-type": [],
    "age-range": [],
    "keywords": [],
    "pa_colors": [
      {
        "id": 999,
        "name": "Vintage Vibes",
        "slug": "vintage-vibes"
      }
    ],
    "pa_size": [],
    "pa_variant": []
  }
}
```

## `data.filters`

```json
{
  "active": [],
  "available": {},
  "price_range": {},
  "availability": {},
  "sorting": {},
  "per_page": {}
}
```

### `filters.active`

```json
[
  {
    "key": "filter_product_cat",
    "value": "eyebrow",
    "label": "Category: Eyebrow"
  },
  {
    "key": "filter_skin-type",
    "value": "combination-skin",
    "label": "Skin Type: Combination Skin"
  },
  {
    "key": "stock_status",
    "value": "instock",
    "label": "In stock"
  }
]
```

### `filters.available`

One object per filterable taxonomy.

```json
{
  "brand": {
    "label": "Brand",
    "taxonomy": "brand",
    "hierarchical": true,
    "terms": [
      {
        "id": 12,
        "name": "Nior",
        "slug": "nior",
        "count": 7,
        "parent": 0,
        "link": "https://herlan.com/brand/nior/",
        "image": {
          "id": 501,
          "src": "https://herlan.com/wp-content/uploads/nior-logo.jpg",
          "alt": "Nior"
        }
      }
    ]
  },
  "pa_colors": {
    "label": "Product Colors",
    "taxonomy": "pa_colors",
    "hierarchical": false,
    "terms": [
      {
        "id": 999,
        "name": "Vintage Vibes",
        "slug": "vintage-vibes",
        "count": 3,
        "parent": 0,
        "color": "#a8483d",
        "image": null
      }
    ]
  }
}
```

Rules:

- counts must be calculated against the current archive context and all currently active filters except the taxonomy currently being counted
- `product_cat` should support hierarchical trees
- `pa_*` terms may include `color` and `image` where available

### `filters.price_range`

```json
{
  "min": 300,
  "max": 1200
}
```

This is the computed range for the currently matched product set, not only the requested values.

### `filters.availability`

```json
{
  "in_stock_count": 18,
  "on_sale_count": 4
}
```

### `filters.sorting`

Sorting data for clients. This section should always be present for non-POST filter/list responses, even if empty.

```json
{
  "current": "popularity",
  "options": [
    { "key": "menu_order", "label": "Default sorting" },
    { "key": "popularity", "label": "Sort by popularity" },
    { "key": "rating", "label": "Sort by average rating" },
    { "key": "date", "label": "Sort by latest" },
    { "key": "price", "label": "Sort by price: low to high" },
    { "key": "price-desc", "label": "Sort by price: high to low" }
  ]
}
```

If `wcapf` is active and defines custom sort-by options, those should be reflected here.

### `filters.per_page`

Per-page options for clients that need listing size controls.

```json
{
  "current": 12,
  "options": [12, 24, 36, 48]
}
```

## `data.wcapf`

This section is optional in the products endpoint and required in the filter config endpoint.

It exposes compatibility data derived from the active `wcapf` filter setup.

```json
{
  "enabled": true,
  "form": {
    "id": 20537,
    "title": "Sample form",
    "post_name": "sample-form"
  },
  "settings": {
    "relationship": "and",
    "show_clear_button": true
  },
  "aliases": {
    "_brand": "filter_brand",
    "product-cat": "filter_product_cat",
    "_skin-type": "filter_skin-type",
    "sort-by": "orderby"
  },
  "sorting": {
    "enabled": true,
    "options": [
      { "key": "menu_order", "label": "Default sorting" },
      { "key": "popularity", "label": "Sort by popularity" }
    ]
  },
  "filters": [
    {
      "type": "taxonomy",
      "taxonomy": "brand",
      "filter_key": "_brand",
      "canonical_key": "filter_brand",
      "label": "Brand",
      "multiple": true
    },
    {
      "type": "taxonomy",
      "taxonomy": "skin-type",
      "filter_key": "_skin-type",
      "canonical_key": "filter_skin-type",
      "label": "Skin Type",
      "multiple": true
    },
    {
      "type": "taxonomy",
      "taxonomy": "pa_colors",
      "filter_key": "colors",
      "canonical_key": "filter_pa_colors",
      "label": "Colors",
      "multiple": true
    },
    {
      "type": "price",
      "filter_key": "price",
      "canonical_key": "min_price,max_price"
    },
    {
      "type": "sort-by",
      "filter_key": "sort-by",
      "canonical_key": "orderby"
    }
  ]
}
```

## `data.pagination`

```json
{
  "page": 1,
  "per_page": 12,
  "total": 48,
  "total_pages": 4,
  "has_more": true
}
```

The `pagination` object is mandatory on all successful non-POST collection/list responses in this contract.

If the response is conceptually a list but contains all items in one page, the API should still return:

```json
{
  "page": 1,
  "per_page": 999,
  "total": 20,
  "total_pages": 1,
  "has_more": false
}
```

## Example Requests

### Brand archive

```text
GET /wp-json/herlan/v1/products?context_taxonomy=brand&context_term=nior
```

### Product category archive

```text
GET /wp-json/herlan/v1/products?context_taxonomy=product_cat&context_term=oral-care
```

### Product category archive filtered by brand

```text
GET /wp-json/herlan/v1/products?context_taxonomy=product_cat&context_term=hair-mask&filter_brand=cavotin
```

### Brand archive filtered by category and skin type

```text
GET /wp-json/herlan/v1/products?context_taxonomy=brand&context_term=nior&filter_product_cat=eyebrow,eyes,nior-on-point-micro-eyebrow-pencil&filter_skin-type=combination-skin
```

### Brand archive filtered by attribute terms

```text
GET /wp-json/herlan/v1/products?context_taxonomy=brand&context_term=herlan&filter_pa_colors=vintage-vibes,retro-rust&filter_pa_size=100ml
```

### Search with filters

```text
GET /wp-json/herlan/v1/products?search=lipstick&filter_brand=herlan,nior&filter_keywords=long-lasting&min_price=300&max_price=900&stock_status=instock&orderby=popularity&page=1&per_page=12
```

### Request aligned to `wcapf` style sorting

```text
GET /wp-json/herlan/v1/products?context_taxonomy=brand&context_term=nior&filter_product_cat=eyes&sort-by=popularity&page=1&per_page=12
```

Normalized internally to:

```text
context_taxonomy=brand
context_term=nior
filter_product_cat=eyes
orderby=popularity
page=1
per_page=12
```

### Legacy frontend URL equivalent

Frontend:

```text
/brand/nior/?product-cat=eyebrow,eyes&_skin-type=combination-skin
```

Equivalent API request:

```text
GET /wp-json/herlan/v1/products?context_taxonomy=brand&context_term=nior&product-cat=eyebrow,eyes&_skin-type=combination-skin
```

Normalized internally to:

```text
GET /wp-json/herlan/v1/products?context_taxonomy=brand&context_term=nior&filter_product_cat=eyebrow,eyes&filter_skin-type=combination-skin
```

## Validation Rules

- invalid `context_taxonomy` -> `400 herlan_invalid_context_taxonomy`
- missing `context_term` when `context_taxonomy` is set -> `400 herlan_missing_context_term`
- unknown taxonomy in `filter_*` -> ignore or return `400 herlan_invalid_filter_taxonomy`
- invalid term slug -> ignore that term unless strict validation is enabled
- `page < 1` -> normalize to `1`
- `per_page > 50` -> clamp to `50`
- invalid `stock_status` -> `400 herlan_invalid_stock_status`
- invalid operator -> `400 herlan_invalid_filter_operator`

Recommended first implementation:

- be permissive on unknown or empty filter values
- fail only for malformed core parameters
- always return `pagination` on successful list responses

## Error Codes

- `400 herlan_invalid_context_taxonomy`
- `400 herlan_missing_context_term`
- `400 herlan_invalid_filter_operator`
- `400 herlan_invalid_stock_status`
- `400 herlan_invalid_orderby`
- `404 herlan_context_term_not_found`
- `500 herlan_woocommerce_unavailable`

## Implementation Notes

- This contract should use WooCommerce product queries, not frontend HTML scraping.
- The endpoint should reuse the same taxonomy/filter logic already used by the theme archive filters where practical.
- The endpoint should read `wcapf` configuration when `wcapf` alignment is requested or enabled.
- Filter counts should be calculated in-context, matching the current archive behavior.
- The endpoint should return only published products.
- For mobile and frontend performance, the `products` objects should stay listing-focused; full product detail belongs in `GET /products/{id}`.

## Filter Config Endpoint Contract

```text
GET /wp-json/herlan/v1/filter-config
```

### Purpose

Return the filter UI contract needed by mobile developers, ideally aligned with `wcapf`.

### Request Parameters

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `form_id` | integer | No | Explicit `wcapf` form ID |
| `context_taxonomy` | string | No | Archive context taxonomy |
| `context_term` | string | No | Archive context term |
| `page` | integer | No | Pagination page for large filter-definition sets. Default `1` |
| `per_page` | integer | No | Items per page. Default `50` |

### Response Shape

```json
{
  "success": true,
  "message": "",
  "data": {
    "context": {
      "taxonomy": "brand",
      "term": {
        "slug": "nior"
      }
    },
    "form": {
      "id": 20537,
      "title": "Sample form"
    },
    "filters": [
      {
        "type": "taxonomy",
        "taxonomy": "brand",
        "filter_key": "_brand",
        "canonical_key": "filter_brand",
        "label": "Brand",
        "multiple": true,
        "options_endpoint": "/wp-json/herlan/v1/filter-terms?taxonomy=brand"
      }
    ],
    "sorting": {
      "current": "popularity",
      "options": [
        { "key": "popularity", "label": "Sort by popularity" }
      ]
    },
    "per_page": {
      "current": 12,
      "options": [12, 24, 36, 48]
    },
    "pagination": {
      "page": 1,
      "per_page": 50,
      "total": 8,
      "total_pages": 1,
      "has_more": false
    }
  }
}
```

## Filter Terms Endpoint Contract

```text
GET /wp-json/herlan/v1/filter-terms
```

### Purpose

Return terms for one taxonomy, with counts calculated in the current context and filter state.

### Request Parameters

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `taxonomy` | string | Yes | Requested taxonomy slug |
| `context_taxonomy` | string | No | Archive context taxonomy |
| `context_term` | string | No | Archive context term |
| `search` | string | No | Filter term search |
| `page` | integer | No | Page number |
| `per_page` | integer | No | Terms per page |

The endpoint may also accept the same active product filter parameters as the products endpoint so counts match the current result set.

### Response Shape

```json
{
  "success": true,
  "message": "",
  "data": {
    "taxonomy": {
      "name": "pa_colors",
      "label": "Product Colors",
      "hierarchical": false
    },
    "terms": [
      {
        "id": 999,
        "name": "Vintage Vibes",
        "slug": "vintage-vibes",
        "count": 3,
        "parent": 0,
        "color": "#a8483d",
        "image": null
      }
    ],
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 217,
      "total_pages": 11,
      "has_more": true
    }
  }
}
```

## Current Project Mapping

This contract is designed to cover the taxonomies already present in the project:

- `brand`
- `age-range`
- `skin-type`
- `keywords`
- `product_cat`
- `product_tag`
- `pa_colors`
- `pa_size`
- `pa_variant`

It also supports current frontend-style filters such as:

- `_brand`
- `product-cat`
- `_skin-type`
- price range
- stock
- on sale
- archive sorting
