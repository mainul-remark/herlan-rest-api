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

---

## Product Listing API

This section covers the unified product listing endpoint and its companion filter endpoints. They power every product archive page, filter drawer, and search screen in the mobile app.

### How it works

Every product listing screen — whether it is a brand page, a category page, a filtered result page, or a search — is served by a single endpoint:

```text
GET /wp-json/herlan/v1/group/products
```

The caller tells the endpoint two things:

1. **Archive context** — where the user is (e.g., on the Nior brand page, or the Lipstick category page)
2. **Active filters** — what the user has selected (e.g., skin type = Combination Skin, category = Eyes)

The endpoint returns the matching products, filter options with live counts, price range, pagination, and the current sort state — all in one response.

---

### Website URL → API call mapping

This is the most important concept for the mobile developer. Every URL on the website maps directly to an API call. The table below shows common examples.

| Website URL | Equivalent API call |
| --- | --- |
| `/shop/` | `GET /group/products` |
| `/brand/d32/` | `GET /group/products?context_taxonomy=brand&context_term=d32` |
| `/brand/nior/` | `GET /group/products?context_taxonomy=brand&context_term=nior` |
| `/brand/d32/?product-cat=electric-toothbrush` | `GET /group/products?context_taxonomy=brand&context_term=d32&filter_product_cat=electric-toothbrush` |
| `/brand/nior/?_skin-type=combination-skin` | `GET /group/products?context_taxonomy=brand&context_term=nior&filter_skin-type=combination-skin` |
| `/product-category/makeup/` | `GET /group/products?context_taxonomy=product_cat&context_term=makeup` |
| `/product-category/makeup/lip/lipstick/` | `GET /group/products?context_taxonomy=product_cat&context_term=lipstick` |
| `/product-category/skin-care/?_brand=herlan` | `GET /group/products?context_taxonomy=product_cat&context_term=skin-care&filter_brand=herlan` |
| `/search/?s=lipstick` | `GET /group/products?search=lipstick` |

**Important notes:**

- For hierarchical category URLs like `/makeup/lip/lipstick/`, only the final term slug (`lipstick`) is needed in `context_term`. WordPress stores each term with a unique slug.
- The endpoint always includes child categories automatically (`include_children=true`), so pointing to a parent category like `makeup` will include all products in `lip`, `lipstick`, `lip-gloss`, and every other child.
- Legacy frontend query string parameters from the website (like `?product-cat=...` or `?_brand=...`) are accepted and normalized automatically — the mobile app does not need to translate them.

---

### Legacy alias normalization

The website frontend uses some non-standard query parameter names for filters. The API accepts both the old and new names and normalizes them internally.

| Legacy (website URL style) | Canonical (use this in new code) |
| --- | --- |
| `_brand` | `filter_brand` |
| `product-cat` | `filter_product_cat` |
| `_skin-type` | `filter_skin-type` |
| `_age-range` | `filter_age-range` |
| `_keywords` | `filter_keywords` |
| `sort-by` | `orderby` |

Example — this legacy frontend URL:

```text
/brand/nior/?product-cat=eyebrow,eyes&_skin-type=combination-skin
```

Can be sent to the API as-is and is normalized to:

```text
GET /group/products?context_taxonomy=brand&context_term=nior&filter_product_cat=eyebrow,eyes&filter_skin-type=combination-skin
```

---

### `GET /group/products`

Returns a paginated product list with live filter counts, price range, active filters, and pagination. This is the core endpoint for all product listing screens.

#### Request parameters

**Archive context** — where the user is:

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `context_taxonomy` | string | No | Base archive taxonomy slug. One of: `product_cat`, `product_tag`, `brand`, `keywords`, `skin-type`, `age-range` |
| `context_term` | string | Conditional | Base archive term slug. Required when `context_taxonomy` is provided. |

Rules:
- If both are omitted, the endpoint behaves as a general shop/search listing.
- If `context_taxonomy` is set, `context_term` is required.
- For `product_cat`, child categories are always included.

**Taxonomy filters** — what the user has selected:

Filters follow this format: `filter_{taxonomy}=slug-a,slug-b`

| Parameter | Example | Description |
| --- | --- | --- |
| `filter_brand` | `filter_brand=nior,herlan` | Filter by one or more brand slugs |
| `filter_product_cat` | `filter_product_cat=eyes,eyebrow` | Filter by one or more category slugs |
| `filter_skin-type` | `filter_skin-type=combination-skin` | Filter by skin type |
| `filter_age-range` | `filter_age-range=25-35` | Filter by age range |
| `filter_keywords` | `filter_keywords=paraben-free` | Filter by keyword |
| `filter_pa_colors` | `filter_pa_colors=vintage-vibes` | Filter by color attribute |
| `filter_pa_size` | `filter_pa_size=100ml` | Filter by size attribute |
| `filter_pa_variant` | `filter_pa_variant=aloe` | Filter by variant attribute |

Any `pa_*` attribute registered in WooCommerce is supported without code changes.

**Filter operators** — how multi-value filters are combined:

| Parameter | Default | Values | Description |
| --- | --- | --- | --- |
| `filter_operator_{taxonomy}` | `in` | `in`, `and`, `not_in` | `in` = any selected term, `and` = must match all selected terms, `not_in` = exclude |

Example: `filter_operator_brand=and` — product must belong to ALL selected brands.

**Price, stock, and sale:**

| Parameter | Type | Description |
| --- | --- | --- |
| `min_price` | number | Minimum price |
| `max_price` | number | Maximum price |
| `stock_status` | string | `instock`, `outofstock`, or `onbackorder` |
| `on_sale` | boolean | `1` or `true` to show only on-sale products |

**Search and sort:**

| Parameter | Type | Default | Description |
| --- | --- | --- | --- |
| `search` | string | — | Product text search |
| `orderby` | string | `menu_order` | `menu_order`, `popularity`, `rating`, `date`, `price`, `price-desc`, `title` |

Sort reference:

| `orderby` value | Sorts by |
| --- | --- |
| `menu_order` | Default catalog order set in admin |
| `popularity` | Best selling (WooCommerce total sales) |
| `rating` | Highest average customer rating |
| `date` | Newest first |
| `price` | Lowest price first |
| `price-desc` | Highest price first |
| `title` | Alphabetical A–Z |

**Pagination:**

| Parameter | Type | Default | Description |
| --- | --- | --- | --- |
| `page` | integer | `1` | Page number |
| `per_page` | integer | `12` | Products per page (max `50`) |

**Include flags** — control what the response contains:

| Parameter | Type | Default | Description |
| --- | --- | --- | --- |
| `include_products` | boolean | `1` | Include product list in response |
| `include_filters` | boolean | `0` | Include filter groups and counts. Must be explicitly set to `1` to return filter data. Defaults to `0` to keep responses lightweight |
| `include_pagination` | boolean | `1` | Include pagination block |

**Tip:** Keep `include_filters=0` (the default) for normal product listing and pagination requests. Only pass `include_filters=1` when the user opens the filter drawer — this triggers the full filter computation including live term counts across all taxonomies.

#### Response shape

```json
{
  "success": true,
  "message": "",
  "data": {
    "context": {},
    "request": {},
    "products": [],
    "filters": {},
    "pagination": {}
  }
}
```

#### `data.context`

Describes the archive the user is browsing. Null fields mean no archive context (general listing or search).

```json
{
  "taxonomy": "brand",
  "label": "Brands",
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

If no context:

```json
{
  "taxonomy": null,
  "label": null,
  "term": null
}
```

#### `data.request`

The normalized parameters actually used for this request after alias resolution and sanitization. Useful for debugging and for the mobile app to reflect the current filter state back to the user.

```json
{
  "page": 1,
  "per_page": 12,
  "orderby": "popularity",
  "order": "ASC",
  "search": null,
  "filters": {
    "filter_product_cat": ["eyes", "eyebrow"],
    "filter_skin-type": ["combination-skin"],
    "min_price": "300",
    "max_price": "1200",
    "stock_status": "instock",
    "on_sale": true
  }
}
```

#### `data.products[]`

Each product in the listing. This is a lightweight card object — use `GET /products/{id}` for full detail.

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
    { "id": 88, "name": "Lipstick", "slug": "lipstick" }
  ],
  "tags": [
    { "id": 45, "name": "Herlan Cushion Matte Lipstick", "slug": "herlan-cushion-matte-lipstick" }
  ]
}
```

Product card fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | WooCommerce product ID |
| `name` | string | Product name |
| `slug` | string | URL slug |
| `type` | string | `simple`, `variable`, etc. |
| `permalink` | string | Full product URL |
| `sku` | string | SKU |
| `price` | string | Current active price |
| `regular_price` | string | Regular (non-sale) price |
| `sale_price` | string | Sale price if set, otherwise empty string |
| `price_html` | string | WooCommerce formatted price HTML |
| `on_sale` | boolean | `true` if currently on sale |
| `stock_status` | string | `instock`, `outofstock`, `onbackorder` |
| `in_stock` | boolean | `true` if purchasable stock exists |
| `average_rating` | string | Decimal string e.g. `"4.5"` |
| `rating_count` | integer | Number of ratings |
| `brand` | object\|null | First brand term |
| `image` | object\|null | Main product image |
| `categories` | array | Assigned `product_cat` terms |
| `tags` | array | Assigned `product_tag` terms |

#### `data.filters`

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

**`filters.active`** — filters the user has currently applied, suitable for rendering "remove filter" chips:

```json
[
  {
    "key": "filter_product_cat",
    "value": "eyebrow",
    "label": "Product categories: Eyebrow"
  },
  {
    "key": "filter_skin-type",
    "value": "combination-skin",
    "label": "Skin Types: Combination Skin"
  },
  {
    "key": "stock_status",
    "value": "instock",
    "label": "In stock"
  }
]
```

**`filters.available`** — one object per filterable taxonomy, with live product counts:

```json
{
  "brand": {
    "label": "Brands",
    "taxonomy": "brand",
    "hierarchical": false,
    "terms": [
      {
        "id": 12,
        "name": "Nior",
        "slug": "nior",
        "count": 7,
        "parent": 0,
        "link": "https://herlan.com/brand/nior/",
        "image": null
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
        "link": "...",
        "color": "#a8483d",
        "image": null
      }
    ]
  }
}
```

**How counts work (faceted filtering):** The count for each term reflects how many products would match if the user tapped that term — using all current context and active filters, but excluding the currently-being-counted taxonomy. This matches the behaviour of the website's filter plugin. A term with `count: 0` is not returned.

**`filters.price_range`** — min and max price of the currently matched product set:

```json
{
  "min": 300,
  "max": 1200
}
```

**`filters.availability`** — stock and sale counts for the current result set:

```json
{
  "in_stock_count": 18,
  "on_sale_count": 4
}
```

**`filters.sorting`** — current sort and all available sort options:

```json
{
  "current": "popularity",
  "options": [
    { "key": "menu_order", "label": "Default sorting" },
    { "key": "popularity", "label": "Sort by popularity" },
    { "key": "rating",     "label": "Sort by average rating" },
    { "key": "date",       "label": "Sort by latest" },
    { "key": "price",      "label": "Sort by price: low to high" },
    { "key": "price-desc", "label": "Sort by price: high to low" }
  ]
}
```

**`filters.per_page`** — current page size and options:

```json
{
  "current": 12,
  "options": [12, 24, 36, 48]
}
```

#### `data.pagination`

```json
{
  "page": 1,
  "per_page": 12,
  "total": 48,
  "total_pages": 4,
  "has_more": true
}
```

#### Error codes

| Code | HTTP | Description |
| --- | --- | --- |
| `herlan_woocommerce_unavailable` | 500 | WooCommerce is not active |
| `herlan_invalid_context_taxonomy` | 400 | `context_taxonomy` is not a supported taxonomy |
| `herlan_missing_context_term` | 400 | `context_taxonomy` was set but `context_term` was not |
| `herlan_invalid_orderby` | 400 | `orderby` value is not recognized |
| `herlan_invalid_stock_status` | 400 | `stock_status` is not one of `instock`, `outofstock`, `onbackorder` |

#### Example requests

**Brand archive — Nior:**

```bash
curl "https://herlan.com/wp-json/herlan/v1/products?context_taxonomy=brand&context_term=nior"
```

**Brand archive with category and skin-type filter:**

```bash
curl "https://herlan.com/wp-json/herlan/v1/products?context_taxonomy=brand&context_term=nior&filter_product_cat=eyes,eyebrow&filter_skin-type=combination-skin"
```

**Category archive — Lipstick (nested URL `/makeup/lip/lipstick/`):**

```bash
curl "https://herlan.com/wp-json/herlan/v1/products?context_taxonomy=product_cat&context_term=lipstick"
```

**Category archive — D32 brand filtered to electric toothbrush:**

```bash
curl "https://herlan.com/wp-json/herlan/v1/products?context_taxonomy=brand&context_term=d32&filter_product_cat=electric-toothbrush"
```

**Search with price range, stock, and sort:**

```bash
curl "https://herlan.com/wp-json/herlan/v1/products?search=lipstick&min_price=300&max_price=900&stock_status=instock&orderby=popularity&page=1&per_page=12"
```

**Fetch page 2 of products (filters already off by default):**

```bash
curl "https://herlan.com/wp-json/herlan/v1/group/products?context_taxonomy=brand&context_term=nior&page=2"
```

**Fetch products with filter data (e.g. when opening the filter drawer):**

```bash
curl "https://herlan.com/wp-json/herlan/v1/group/products?context_taxonomy=brand&context_term=nior&include_filters=1"
```

**Using legacy frontend alias parameters (also accepted):**

```bash
curl "https://herlan.com/wp-json/herlan/v1/products?context_taxonomy=brand&context_term=nior&product-cat=eyebrow,eyes&_skin-type=combination-skin&sort-by=popularity"
```

---

### `GET /filter-config`

Returns the list of available filter types that the mobile app should render in the filter drawer. Also includes sorting options and per-page controls.

This endpoint is designed to be fetched once when the filter drawer opens, then cached locally by the app.

#### Request parameters

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `context_taxonomy` | string | No | Archive taxonomy slug for context-aware config |
| `context_term` | string | No | Archive term slug |
| `page` | integer | No | Page number. Default `1` |
| `per_page` | integer | No | Filter definitions per page. Default `50`, max `100` |

#### Response shape

```json
{
  "success": true,
  "message": "",
  "data": {
    "context": {
      "taxonomy": "brand",
      "term": { "slug": "nior" }
    },
    "filters": [
      {
        "type": "taxonomy",
        "taxonomy": "product_cat",
        "filter_key": "product-cat",
        "canonical_key": "filter_product_cat",
        "label": "Product categories",
        "multiple": true,
        "options_endpoint": "https://herlan.com/wp-json/herlan/v1/filter-terms?taxonomy=product_cat"
      },
      {
        "type": "taxonomy",
        "taxonomy": "brand",
        "filter_key": "_brand",
        "canonical_key": "filter_brand",
        "label": "Brands",
        "multiple": true,
        "options_endpoint": "https://herlan.com/wp-json/herlan/v1/filter-terms?taxonomy=brand"
      },
      {
        "type": "price",
        "filter_key": "price",
        "canonical_key": "min_price,max_price",
        "label": "Price"
      },
      {
        "type": "sort-by",
        "filter_key": "sort-by",
        "canonical_key": "orderby",
        "label": "Sort By"
      }
    ],
    "sorting": {
      "current": "menu_order",
      "options": [...]
    },
    "per_page": {
      "current": 12,
      "options": [12, 24, 36, 48]
    },
    "pagination": {
      "page": 1,
      "per_page": 50,
      "total": 12,
      "total_pages": 1,
      "has_more": false
    }
  }
}
```

Filter definition fields:

| Field | Type | Notes |
| --- | --- | --- |
| `type` | string | `taxonomy`, `attribute`, `price`, or `sort-by` |
| `taxonomy` | string | Taxonomy slug (taxonomy and attribute types only) |
| `filter_key` | string | Legacy-compatible key (use when building website-style URLs) |
| `canonical_key` | string | The canonical API parameter name (use when calling `GET /group/products`) |
| `label` | string | Human-readable label for the filter section heading |
| `multiple` | boolean | Whether multiple terms can be selected simultaneously |
| `options_endpoint` | string | URL to call for the list of selectable terms with counts |

---

### `GET /filter-terms`

Returns the selectable terms for one taxonomy, with product counts computed in the current context. Use this to populate a filter's option list.

#### Request parameters

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `taxonomy` | string | **Yes** | Taxonomy slug to fetch terms for |
| `context_taxonomy` | string | No | Archive taxonomy for context-scoped counts |
| `context_term` | string | No | Archive term slug |
| `search` | string | No | Filter terms by name |
| `page` | integer | No | Page number. Default `1` |
| `per_page` | integer | No | Terms per page. Default `20`, max `100` |

#### Response shape

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
        "image": null,
        "color": "#a8483d"
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

Term fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Term ID |
| `name` | string | Display name |
| `slug` | string | API slug — use this in `filter_{taxonomy}` params |
| `count` | integer | Products matching this term in the current context |
| `parent` | integer | Parent term ID, `0` for root terms |
| `image` | string\|null | Swatch image URL if configured |
| `color` | string | Swatch hex color if configured (e.g. `"#a8483d"`) |

Pagination is important here — `pa_colors` has 217 terms in the current catalog. Use `page` and `per_page` to load them incrementally (e.g. on scroll in a color picker).

Error codes:

- `400 herlan_invalid_filter_taxonomy` — taxonomy is missing or does not exist

#### Example — load color swatches for the brand archive:

```bash
curl "https://herlan.com/wp-json/herlan/v1/filter-terms?taxonomy=pa_colors&context_taxonomy=brand&context_term=nior&per_page=50"
```

---

### `GET /filter-forms/{id}`

Returns the full filter configuration of a WooCommerce Ajax Product Filter (wcapf) form. This is the primary endpoint for driving the mobile filter drawer — it tells the app exactly which filters exist, how to display them, what terms are available, and which API parameter to send to `GET /group/products` when the user makes a selection.

#### How it connects to `/group/products`

Each filter in the response includes an `api_param` field. When the user selects a filter option in the app, use that param name when calling `/group/products`.

Example flow:
1. Call `GET /filter-forms/20537?context_taxonomy=brand&context_term=siodil`
2. Response includes a `skin-type` filter with `"api_param": "filter_skin-type"` and terms like `{ "slug": "combination-skin", ... }`
3. User selects "Combination Skin"
4. Call `GET /group/products?context_taxonomy=brand&context_term=siodil&filter_skin-type=combination-skin`

#### Path parameters

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | integer | Yes | The wcapf form post ID (e.g. `20537`) |

#### Query parameters

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `context_taxonomy` | string | No | Archive taxonomy slug — scopes term counts to this context |
| `context_term` | string | No | Archive term slug — scopes term counts to this context |

Passing context is strongly recommended. Without it, term counts reflect the entire catalog. With it, counts reflect only products in the current archive (e.g. the Siodil brand page).

#### Response shape

```json
{
  "success": true,
  "message": "",
  "data": {
    "form": {
      "id": 20537,
      "title": "Sample form",
      "slug": "sample-form"
    },
    "context": {
      "taxonomy": "brand",
      "term": "siodil"
    },
    "filters": [...],
    "products_endpoint": "https://herlan.com/wp-json/herlan/v1/group/products"
  }
}
```

#### `filters[]` — filter types

Each filter in the array is one panel in the filter drawer. The `type` field determines what kind of filter it is.

**Taxonomy filter** (`type: "taxonomy"`) — a list of selectable terms:

```json
{
  "id": 1001,
  "type": "taxonomy",
  "label": "Brand",
  "filter_key": "_brand",
  "taxonomy": "brand",
  "api_param": "filter_brand",
  "display_type": "checkbox",
  "multiple": true,
  "operator": "in",
  "hierarchical": false,
  "terms": [
    {
      "id": 12,
      "name": "Siodil",
      "slug": "siodil",
      "count": 18,
      "parent": 0,
      "image": null
    }
  ],
  "terms_endpoint": "https://herlan.com/wp-json/herlan/v1/filter-terms?taxonomy=brand&context_taxonomy=brand&context_term=siodil"
}
```

Taxonomy filter fields:

| Field | Type | Notes |
| --- | --- | --- |
| `filter_key` | string | The URL param used on the website (legacy). Use `api_param` instead when calling `/group/products` |
| `api_param` | string | The parameter to send to `/group/products` when user selects a term. Format: `filter_{taxonomy}` |
| `display_type` | string | How wcapf displays this filter: `checkbox`, `radio`, `select`, `multi-select`, `label` |
| `multiple` | boolean | Whether multiple terms can be selected at once |
| `operator` | string | `in` (any selected term matches) or `and` (all selected terms must match). Pass as `filter_operator_{taxonomy}` to `/group/products` |
| `hierarchical` | boolean | Whether terms have a parent/child hierarchy |
| `terms` | array | Available terms with product counts in the current context |
| `terms_endpoint` | string | URL to load terms with pagination — use for large taxonomies like `pa_colors` (217 terms) |

Term fields inside `terms[]`:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Term ID |
| `name` | string | Display name |
| `slug` | string | Use this value in the API call: `filter_brand=siodil` |
| `count` | integer | Products matching this term in the current context |
| `parent` | integer | Parent term ID, `0` for root |
| `image` | string\|null | Swatch image URL if configured |
| `color` | string | Swatch hex color if configured (only present when set) |

**Price filter** (`type: "price"`) — a range slider:

```json
{
  "id": 1002,
  "type": "price",
  "label": "Price",
  "filter_key": "price",
  "api_param_min": "min_price",
  "api_param_max": "max_price",
  "range": {
    "min": 150,
    "max": 2500
  }
}
```

`range` shows the actual min/max price of products in the current context. Use `api_param_min` and `api_param_max` as parameter names when calling `/group/products`.

**Sort-by filter** (`type: "sort-by"`):

```json
{
  "id": 1003,
  "type": "sort-by",
  "label": "Sort By",
  "filter_key": "sort-by",
  "api_param": "orderby",
  "options": [
    { "key": "menu_order", "label": "Default sorting" },
    { "key": "popularity", "label": "Sort by popularity" },
    { "key": "date",       "label": "Sort by latest" },
    { "key": "price",      "label": "Sort by price: low to high" },
    { "key": "price-desc", "label": "Sort by price: high to low" }
  ]
}
```

**Product status filter** (`type: "product-status"`):

```json
{
  "id": 1004,
  "type": "product-status",
  "label": "Availability",
  "filter_key": "product-status",
  "api_param": "on_sale or stock_status",
  "note": "Pass on_sale=1 for on-sale products, stock_status=instock|outofstock|onbackorder for stock filtering."
}
```

**Keyword / search filter** (`type: "keyword"`):

```json
{
  "id": 1005,
  "type": "keyword",
  "label": "Search",
  "filter_key": "s",
  "api_param": "search"
}
```

#### Full example

```bash
curl "https://herlan.com/wp-json/herlan/v1/filter-forms/20537?context_taxonomy=brand&context_term=siodil"
```

```bash
curl "https://herlan.com/wp-json/herlan/v1/filter-forms/20537?context_taxonomy=product_cat&context_term=liquid-lipstick"
```

#### How to build a filter request from this response

```
# User is on /brand/d32/ and selects Electric Toothbrush category + In Stock
GET /group/products
  ?context_taxonomy=brand
  &context_term=d32
  &filter_product_cat=electric-toothbrush   ← api_param from category filter
  &stock_status=instock                      ← from product-status filter
```

Error codes:

- `404 herlan_wcapf_form_not_found` — form ID does not exist or is not a wcapf form

---

## Other public endpoints

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
| `product_groups` | object | Product group tabs/tags with up to 4 products per group |
| `campaigns` | array | Active campaign sections |
| `categories_block` | object | Category block enabled flag |
| `custom_tags` | object | Custom tag cards |
| `product_sliders` | array | Configured product slider definitions |
| `home_video` | object\|null | Home video block |
| `brands` | object | All published brands (`total` + `items[]`) |
| `shop_by_category` | object | Featured home categories (`taxonomy`, `total`, `terms[]`) |

Key nested shapes:

- `hero_slider.items[]`
  - image slide: `type`, `url`, `image`, `mobile_image`
  - video slide: `type`, `url`, `video_url`
- `promotional_block.posts[]`: `id`, `title`, `subtitle`, `image`, `url`
- `campaign_shortcuts.items[]`: `name`, `url`
- `product_groups.groups[]`: `title`, `taxonomy`, `term`, `link`, `product_tag`, `featured_tag`, `products[]` — see shape below
- `campaigns[]`: `title`, `product_tag`, `tag_url`, `see_all_text`, `background_image`
- `custom_tags.items[]`: `name`, `url`, `image`
- `product_sliders[]`: `title`, `product_type`, `taxonomy_type`, `term_slug`, `url`, `background_image`
- `home_video`: `video_desktop`, `video_mobile`, `poster`, `link_url`
- `brands`: `total` (integer), `items[]` — see shape below
- `shop_by_category`: `taxonomy`, `total` (integer), `terms[]` — see shape below

`brands.items[]` shape (identical to `GET /drawer-brands-categories` brand items):

```json
{
  "id": 42,
  "name": "Nior",
  "slug": "nior",
  "description": "",
  "count": 22,
  "link": "https://herlan.com/brand/nior/",
  "image": {
    "id": 100,
    "src": "https://herlan.com/wp-content/uploads/nior-logo.jpg",
    "alt": "Nior"
  }
}
```

Brands are ordered alphabetically. Only brands with at least one published product are included (`hide_empty: true`). `image` is `null` when no logo has been uploaded for the brand.

`shop_by_category` shape:

```json
{
  "taxonomy": "product_cat",
  "total": 8,
  "terms": [
    {
      "id": 12,
      "name": "Skin Care",
      "slug": "skin-care",
      "count": 34,
      "link": "https://herlan.com/product-category/skin-care/",
      "image": {
        "id": 301,
        "src": "https://herlan.com/wp-content/uploads/skincare.jpg",
        "width": 800,
        "height": 800,
        "alt": "Skin Care"
      }
    }
  ]
}
```

`shop_by_category.terms[]` fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Term ID |
| `name` | string | Category display name |
| `slug` | string | URL slug |
| `count` | integer | Number of products in this category |
| `link` | string\|null | Category archive URL |
| `image` | object\|null | Category thumbnail (`id`, `src`, `width`, `height`, `alt`) |

Only categories with `herlan_featured = 1` term meta are included. Order follows the manually configured `product_cat_custom_order` term meta — matching the website's "Shop by Category" swiper. `image` is `null` when no thumbnail has been set for the category.

`product_groups` shape:

```json
{
  "enabled": true,
  "section_title": "Shop by Group",
  "groups": [
    {
      "title": "Best Sellers",
      "taxonomy": "product_tag",
      "term": {
        "id": 55,
        "name": "Best Sellers",
        "slug": "best-sellers",
        "link": "https://herlan.com/product-tag/best-sellers/"
      },
      "link": "https://herlan.com/product-tag/best-sellers/",
      "product_tag": "best-sellers",
      "featured_tag": "featured-best-sellers",
      "products": [
        {
          "id": 101,
          "name": "Caviar Night Cream",
          "image": {
            "id": 301,
            "src": "https://herlan.com/wp-content/uploads/product.jpg",
            "width": 800,
            "height": 800,
            "alt": "Caviar Night Cream"
          }
        }
      ]
    }
  ]
}
```

`product_groups.groups[]` fields:

| Field | Type | Notes |
| --- | --- | --- |
| `title` | string | Group display title (falls back to term name if not set) |
| `taxonomy` | string | Always `product_tag` |
| `term` | object\|null | The `product_tag` term for this group (`id`, `name`, `slug`, `link`) |
| `link` | string\|null | Term archive URL — use this for the "See all" button |
| `product_tag` | string | Main tag slug used to fetch products |
| `featured_tag` | string\|null | Optional priority tag slug — products from this tag are shown first |
| `products` | array | Up to 4 product cards for this group |

`products[]` fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | WooCommerce product ID |
| `name` | string | Product name |
| `image` | object\|null | Product thumbnail (`id`, `src`, `width`, `height`, `alt`) |

Product fetching mirrors the website's home page group cards: up to 4 in-stock products are returned, prioritising `featured_tag` items first, then backfilling from `product_tag`. Groups with no `product_tag` configured are omitted entirely.

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

Returns mobile product listing filters without products.

Query parameters:

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `taxonomy` | string | none | Optional context taxonomy slug |
| `term` | string | none | Optional context term slug |
| `include_counts` | boolean | `true` | Recalculate counts for current context |

Note: This is the original filter metadata endpoint. The newer `GET /group/products` endpoint returns the same filter data alongside products in one request, which is preferred for listing screens. Use this endpoint only when you need filter metadata independently.

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

---

### `GET /taxonomies`

Returns all filterable product taxonomies and their terms in a single call. Use this to populate filter UI (dropdowns, checkboxes, chips) and to know which `filter_*` parameter to send to `GET /group/products` when the user picks a term.

Query parameters:

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `hide_empty` | boolean | `true` | Exclude terms with no published products |

Example:

```bash
curl "https://herlan.com/wp-json/herlan/v1/taxonomies"
curl "https://herlan.com/wp-json/herlan/v1/taxonomies?hide_empty=false"
```

Response shape:

```json
{
  "success": true,
  "message": "",
  "data": {
    "taxonomies": [
      {
        "taxonomy": "product_cat",
        "label": "Product categories",
        "filter_key": "filter_product_cat",
        "hierarchical": true,
        "terms": [
          { "id": 12, "name": "Skin Care", "slug": "skin-care", "count": 34, "parent": 0, "image": null },
          { "id": 15, "name": "Cleanser",  "slug": "cleanser",  "count":  8, "parent": 12, "image": null }
        ]
      },
      {
        "taxonomy": "brand",
        "label": "Brands",
        "filter_key": "filter_brand",
        "hierarchical": false,
        "terms": [
          { "id": 42, "name": "Nior", "slug": "nior", "count": 22, "parent": 0, "image": { "id": 100, "src": "...", "alt": "Nior" } }
        ]
      },
      {
        "taxonomy": "pa_colors",
        "label": "Product Colors",
        "filter_key": "filter_pa_colors",
        "hierarchical": false,
        "terms": [
          { "id": 88, "name": "Classic Red", "slug": "classic-red", "count": 5, "parent": 0, "image": null, "color": "#cc0000" }
        ]
      }
    ]
  }
}
```

Taxonomy response fields:

| Field | Type | Notes |
| --- | --- | --- |
| `taxonomy` | string | Internal taxonomy slug |
| `label` | string | Human-readable taxonomy label |
| `filter_key` | string | Query param name to use with `GET /group/products` |
| `hierarchical` | boolean | Whether terms have parent/child relationships |
| `terms` | array | Terms for this taxonomy |

Term fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Term ID |
| `name` | string | Display name |
| `slug` | string | URL slug — use this value when filtering |
| `count` | integer | Number of published products |
| `parent` | integer | Parent term ID (0 = top-level) |
| `image` | object\|null | Term image (thumbnail or swatch image) |
| `color` | string | (only present when set) Hex color code for swatch terms |

#### How to use with `GET /group/products`

The `filter_key` field tells you exactly which query parameter to append to `/group/products`. Pass one or more slugs (comma-separated) as the value.

```
GET /group/products?{filter_key}={slug1},{slug2}
```

Examples:

```bash
# Filter by product category
curl "https://herlan.com/wp-json/herlan/v1/group/products?filter_product_cat=skin-care"

# Filter by brand
curl "https://herlan.com/wp-json/herlan/v1/group/products?filter_brand=nior"

# Filter by skin type + age range (combined)
curl "https://herlan.com/wp-json/herlan/v1/group/products?filter_skin-type=oily-skin&filter_age-range=25-35"

# Filter by color attribute
curl "https://herlan.com/wp-json/herlan/v1/group/products?filter_pa_colors=classic-red,cherry"

# Context + filter: brand archive filtered by skin type
curl "https://herlan.com/wp-json/herlan/v1/group/products?context_taxonomy=brand&context_term=nior&filter_skin-type=oily-skin"
```

---

## Search API

### `GET /search`

Product search endpoint powered by the same engine as the live autocomplete on the website (Ajax Search for WooCommerce / FiboSearch). Returns clean JSON product cards — no HTML rendering. Use this for the mobile search results screen.

#### Request parameters

| Parameter | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `s` | string | **Yes** | — | Search phrase (minimum 2 characters) |
| `per_page` | integer | No | `10` | Results per page (max `50`) |
| `page` | integer | No | `1` | Page number |

#### Response shape

```json
{
  "success": true,
  "message": "",
  "data": {
    "query": "cav",
    "products": [],
    "pagination": {
      "page": 1,
      "per_page": 10,
      "total": 5,
      "total_pages": 1,
      "has_more": false
    }
  }
}
```

#### `data.products[]`

Each item is a lightweight product card (same structure as `GET /group/products`):

```json
{
  "id": 123,
  "name": "Caviar Night Cream",
  "slug": "caviar-night-cream",
  "type": "simple",
  "permalink": "https://herlan.com/product/caviar-night-cream/",
  "sku": "CAV-001",
  "price": "1200",
  "regular_price": "1500",
  "sale_price": "1200",
  "price_html": "<del>৳1,500</del> ৳1,200",
  "on_sale": true,
  "stock_status": "instock",
  "in_stock": true,
  "average_rating": "4.5",
  "rating_count": 12,
  "image": {
    "id": 456,
    "src": "https://herlan.com/wp-content/uploads/product.jpg",
    "width": 300,
    "height": 300,
    "alt": "Caviar Night Cream"
  },
  "categories": [
    { "id": 7, "name": "Skincare", "slug": "skincare" }
  ]
}
```

Product card fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | WooCommerce product ID |
| `name` | string | Product name |
| `slug` | string | URL slug |
| `type` | string | `simple`, `variable`, etc. |
| `permalink` | string | Full product URL |
| `sku` | string | SKU |
| `price` | string | Current active price |
| `regular_price` | string | Regular (non-sale) price |
| `sale_price` | string | Sale price if set, otherwise empty string |
| `price_html` | string | WooCommerce formatted price HTML |
| `on_sale` | boolean | `true` if currently on sale |
| `stock_status` | string | `instock`, `outofstock`, `onbackorder` |
| `in_stock` | boolean | `true` if purchasable stock exists |
| `average_rating` | string | Decimal string e.g. `"4.5"` |
| `rating_count` | integer | Number of ratings |
| `image` | object\|null | Main product image (`id`, `src`, `width`, `height`, `alt`) |
| `categories` | array | Assigned `product_cat` terms (`id`, `name`, `slug`) |

Notes:

- Queries shorter than 2 characters return an empty `products` array immediately (no search is performed).
- Search results match those of the website autocomplete. If the FiboSearch plugin is unavailable the endpoint falls back to native WooCommerce search.
- For full product detail (description, gallery, variations, etc.) call `GET /products/{id}` using the returned `id`.

#### Example requests

**Basic search:**

```bash
curl "https://herlan.com/wp-json/herlan/v1/search?s=cav"
```

**Search with pagination:**

```bash
curl "https://herlan.com/wp-json/herlan/v1/search?s=lipstick&per_page=20&page=1"
```

**Search page 2:**

```bash
curl "https://herlan.com/wp-json/herlan/v1/search?s=lipstick&per_page=10&page=2"
```

#### Error codes

| Code | HTTP | Description |
| --- | --- | --- |
| `herlan_woocommerce_unavailable` | 500 | WooCommerce is not active |

---

## Store Locator endpoints

### `GET /stores`

Returns a paginated list of published stores. Supports filtering by district, area, and a keyword search.

All parameters are optional.

#### Request parameters

| Parameter | Type | Default | Description |
| --- | --- | --- | --- |
| `district` | string | — | Exact-match filter by city/district name |
| `area` | string | — | Exact-match filter by area name |
| `search` | string | — | Keyword search across `store_name`, `store_address`, `district`, and `phone` |
| `limit` | integer | `50` | Results per page (max `200`) |
| `offset` | integer | `0` | Pagination offset |

#### Response shape

```json
{
  "success": true,
  "message": "",
  "data": {
    "stores": [
      {
        "id": 1,
        "store_code": "DH-001",
        "store_name": "Herlan Dhanmondi",
        "store_address": "House 12, Road 4, Dhanmondi, Dhaka",
        "district": "Dhaka",
        "area": "Dhanmondi",
        "phone": "01700000001",
        "working_hours": "10:00 AM - 9:00 PM",
        "offday": "Friday",
        "map_link": "https://maps.google.com/?q=...",
        "image_url": "https://herlan.com/wp-content/uploads/store-dh001.jpg"
      }
    ],
    "total": 42,
    "limit": 50,
    "offset": 0
  }
}
```

Store fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Store row ID |
| `store_code` | string | Unique store code |
| `store_name` | string | Store display name |
| `store_address` | string | Full store address |
| `district` | string | City / district name |
| `area` | string | Area within the district |
| `phone` | string | Contact number |
| `working_hours` | string | Opening hours text |
| `offday` | string | Weekly off day(s) |
| `map_link` | string\|null | Google Maps link |
| `image_url` | string\|null | Store image URL |

#### Example requests

**All stores:**

```bash
curl "https://herlan.com/wp-json/herlan/v1/stores"
```

**Filter by district:**

```bash
curl "https://herlan.com/wp-json/herlan/v1/stores?district=Dhaka"
```

**Filter by district and area:**

```bash
curl "https://herlan.com/wp-json/herlan/v1/stores?district=Dhaka&area=Dhanmondi"
```

**Keyword search:**

```bash
curl "https://herlan.com/wp-json/herlan/v1/stores?search=dhanmondi"
```

**Paginate:**

```bash
curl "https://herlan.com/wp-json/herlan/v1/stores?limit=20&offset=20"
```

---

### `GET /stores/{id}`

Returns a single published store by its ID.

#### Path parameters

| Parameter | Type | Description |
| --- | --- | --- |
| `id` | integer | Store row ID |

#### Response shape

```json
{
  "success": true,
  "message": "",
  "data": {
    "store": {
      "id": 1,
      "store_code": "DH-001",
      "store_name": "Herlan Dhanmondi",
      "store_address": "House 12, Road 4, Dhanmondi, Dhaka",
      "district": "Dhaka",
      "area": "Dhanmondi",
      "phone": "01700000001",
      "working_hours": "10:00 AM - 9:00 PM",
      "offday": "Friday",
      "map_link": "https://maps.google.com/?q=...",
      "image_url": "https://herlan.com/wp-content/uploads/store-dh001.jpg"
    }
  }
}
```

#### Error codes

| Code | HTTP | Description |
| --- | --- | --- |
| `herlan_store_not_found` | 404 | No published store exists with the given ID |
| `herlan_store_db_error` | 500 | Database error while fetching the store |

#### Example

```bash
curl "https://herlan.com/wp-json/herlan/v1/stores/1"
```

---

### `GET /store-locations`

Returns all districts (cities) along with the areas available within each district, for published stores only. Use this to populate district and area dropdowns in the store locator UI.

#### Request parameters

None.

#### Response shape

```json
{
  "success": true,
  "message": "",
  "data": {
    "districts": [
      {
        "district": "Chattogram",
        "areas": ["Agrabad", "GEC Circle", "Nasirabad"]
      },
      {
        "district": "Dhaka",
        "areas": ["Banani", "Dhanmondi", "Gulshan", "Mirpur", "Uttara"]
      }
    ]
  }
}
```

Districts are ordered alphabetically. Areas within each district are also ordered alphabetically.

#### Example

```bash
curl "https://herlan.com/wp-json/herlan/v1/store-locations"
```

---

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
          "image": "https://your-domain.com/wp-content/uploads/.../thumbnail.jpg",
          "is_free_gift": false
        }
      ],
      "item_count": 1,
      "subtotal": "450.00",
      "total": "400.00",
      "currency": "BDT",
      "bogo": { "enabled": false },
      "free_shipping": {
        "available": true,
        "min_amount": "500.00",
        "requires": "min_amount",
        "remaining": "50.00",
        "qualified": false
      },
      "herlan_cash": {
        "available": true,
        "available_cash": "150.00",
        "max_redeemable": "150.00",
        "enabled": true,
        "redeemed_amount": "50.00",
        "next_expiring_amount": "50.00",
        "next_expiring_date": "2026-06-15"
      }
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
| `price` | string | Unit price (decimal string). `"0.00"` for free-gift items |
| `subtotal` | string | `price × quantity`. `"0.00"` for free-gift items |
| `image` | string\|null | Thumbnail image URL |
| `is_free_gift` | boolean | `true` when added by a BOGO promotion |

`cart.herlan_cash` fields:

| Field | Type | Notes |
| --- | --- | --- |
| `available` | boolean | `false` when loyalty plugin is inactive or user has no billing phone — **hide the Herlan Cash UI entirely when `false`** |
| `available_cash` | string | Total usable Herlan Cash balance (decimal string) |
| `max_redeemable` | string | Maximum amount the user can redeem for this cart order (floored to nearest ৳10, capped at cart subtotal minus coupons) |
| `enabled` | boolean | Whether the user has turned on Herlan Cash for this order — use this to set the toggle switch state |
| `redeemed_amount` | string | Amount currently being deducted from the cart total (decimal string) |
| `next_expiring_amount` | string | Next cash amount due to expire (decimal string) |
| `next_expiring_date` | string | Expiry date of the next expiring cash (`"YYYY-MM-DD"` or empty string) |

**How `available` vs `enabled` differ:**

- `available: false` → loyalty plugin is off or no phone linked → **hide the Herlan Cash section entirely**
- `available: true, enabled: false` → user has cash but the toggle is OFF → **show toggle in off state**
- `available: true, enabled: true` → toggle is ON, `redeemed_amount` is being deducted from `total`

`cart.bogo` fields (when `enabled: true`):

| Field | Type | Notes |
| --- | --- | --- |
| `enabled` | boolean | `false` when no BOGO products are configured |
| `cart_subtotal` | string | Subtotal used for tier evaluation (excludes free gifts and bundle children) |
| `current_tier` | integer | Tier the cart has qualified for (`0` = none) |
| `current_gift_id` | integer | Product ID of the currently earned free gift |
| `next_tier` | integer | Next tier the cart is working toward |
| `next_gift_id` | integer | Product ID of the next free gift |
| `remaining_for_next_tier` | string | Amount still needed to reach the next tier |
| `tiers` | array | All configured tiers (`tier`, `threshold`, `product_id`, `qualified`) |

`cart.free_shipping` fields:

| Field | Type | Notes |
| --- | --- | --- |
| `available` | boolean | `false` when no free shipping method is configured |
| `min_amount` | string | Minimum order subtotal for free shipping |
| `requires` | string | WooCommerce `requires` setting (e.g. `"min_amount"`) |
| `remaining` | string | How much more needs to be added to qualify |
| `qualified` | boolean | `true` when cart already meets the free shipping threshold |

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

### `POST /cart/herlan-cash/toggle`

Enables or disables Herlan Cash redemption for the current cart order.

- When **enabling** (`enabled: true`): fetches the user's available cash balance and automatically sets `redeemed_amount` to the maximum redeemable amount (floored to nearest ৳10).
- When **disabling** (`enabled: false`): clears the redemption — `redeemed_amount` becomes `0` and `total` is restored.

The returned `cart.total` immediately reflects the change.

Request body:

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `enabled` | boolean | Yes | `true` to enable, `false` to disable |

Example — enable:

```bash
curl -X POST "https://your-domain.com/wp-json/herlan/v1/cart/herlan-cash/toggle" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"enabled": true}'
```

Example — disable:

```bash
curl -X POST "https://your-domain.com/wp-json/herlan/v1/cart/herlan-cash/toggle" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"enabled": false}'
```

Success response (`200`) — returns the updated full cart:

```json
{
  "success": true,
  "message": "Herlan Cash enabled.",
  "data": {
    "cart": {
      "subtotal": "450.00",
      "total": "400.00",
      "herlan_cash": {
        "available": true,
        "available_cash": "150.00",
        "max_redeemable": "150.00",
        "enabled": true,
        "redeemed_amount": "50.00",
        "next_expiring_amount": "50.00",
        "next_expiring_date": "2026-06-15"
      }
    }
  }
}
```

Errors:

| Code | HTTP | Description |
| --- | --- | --- |
| `herlan_loyalty_unavailable` | 503 | Loyalty plugin is not active |
| `herlan_loyalty_no_phone` | 422 | Authenticated user has no billing phone number linked |

---

### `POST /cart/herlan-cash/set-amount`

Sets a specific Herlan Cash redemption amount for the current order.

- The amount is clamped down to the nearest multiple of ৳10 (e.g. `55` → `50`).
- The amount must not exceed `cart.herlan_cash.max_redeemable`.
- Passing `amount: 0` disables Herlan Cash entirely (same as `toggle` with `enabled: false`).

Request body:

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `amount` | integer | Yes | Redemption amount in BDT. Must be `≥ 0`. |

Example — set ৳100:

```bash
curl -X POST "https://your-domain.com/wp-json/herlan/v1/cart/herlan-cash/set-amount" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount": 100}'
```

Example — remove (set to 0):

```bash
curl -X POST "https://your-domain.com/wp-json/herlan/v1/cart/herlan-cash/set-amount" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount": 0}'
```

Success response (`200`) — returns the updated full cart with `herlan_cash.redeemed_amount` reflecting the clamped value.

Errors:

| Code | HTTP | Description |
| --- | --- | --- |
| `herlan_loyalty_unavailable` | 503 | Loyalty plugin is not active |
| `herlan_loyalty_no_phone` | 422 | Authenticated user has no billing phone number linked |
| `herlan_cash_exceeds_limit` | 422 | `amount` is greater than `max_redeemable` — response message includes the allowed maximum |

---

---

## Checkout endpoints

All checkout endpoints require a Bearer token. The cart must already be populated (items, coupons, Herlan Cash, etc.) before calling `POST /checkout`.

Plugin discounts — Woo Discount Rules, Smart Coupons Pro, BOGO free gifts, Herlan Cash redemption, and Easy Product Bundles — are all applied automatically from the cart when the order is created. Nothing extra is needed from the mobile side.

---

### `GET /checkout/payment-methods`

Returns the payment gateways currently enabled in WooCommerce. Call this to populate the payment method selector on the checkout screen.

Example:

```bash
curl "https://your-domain.com/wp-json/herlan/v1/checkout/payment-methods" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "payment_methods": [
      {
        "id": "sslcommerz",
        "title": "Pay Online (Card / Mobile Banking / Net Banking)",
        "description": "Pay securely via SSLCommerz."
      },
      {
        "id": "cod",
        "title": "Cash on Delivery",
        "description": "Pay when your order arrives."
      }
    ]
  }
}
```

Payment method fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | string | Gateway ID — pass this as `payment_method` when placing an order |
| `title` | string | Human-readable label to display in the app |
| `description` | string | Optional description text |

---

### `POST /checkout`

Places an order from the authenticated user's current cart. Returns an order ID and a `payment_url` the mobile app should open in a WebView to complete payment.

**The recommended flow:**

1. Build the cart (`POST /cart/add-to-cart`, apply coupons, set Herlan Cash, etc.)
2. Fetch available payment methods (`GET /checkout/payment-methods`)
3. Show checkout form — collect billing/shipping details and payment method choice
4. Submit `POST /checkout`
5. On success, open `payment_url` in a WebView for gateway-based payments (SSLCommerz), or navigate to the order confirmation screen for COD

#### Request body

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `payment_method` | string | **Yes** | Gateway ID from `GET /checkout/payment-methods` |
| `billing_first_name` | string | **Yes** | |
| `billing_last_name` | string | **Yes** | |
| `billing_email` | string | **Yes** | Valid email address |
| `billing_phone` | string | **Yes** | |
| `billing_address_1` | string | **Yes** | |
| `billing_city` | string | **Yes** | |
| `billing_address_2` | string | No | Defaults to `""` |
| `billing_state` | string | No | Defaults to `""` |
| `billing_postcode` | string | No | Defaults to `""` |
| `billing_country` | string | No | ISO 3166-1 alpha-2. Defaults to `"BD"` |
| `billing_company` | string | No | Defaults to `""` |
| `ship_to_different_address` | boolean | No | `false` — copies billing address to shipping. Set `true` to provide separate shipping fields |
| `shipping_first_name` | string | Conditional | Required when `ship_to_different_address: true` |
| `shipping_last_name` | string | Conditional | Required when `ship_to_different_address: true` |
| `shipping_address_1` | string | Conditional | Required when `ship_to_different_address: true` |
| `shipping_city` | string | Conditional | Required when `ship_to_different_address: true` |
| `shipping_address_2` | string | No | |
| `shipping_state` | string | No | |
| `shipping_postcode` | string | No | |
| `shipping_country` | string | No | |
| `shipping_company` | string | No | |
| `customer_note` | string | No | Order note visible to the store |

#### Example — SSLCommerz payment

```bash
curl -X POST "https://your-domain.com/wp-json/herlan/v1/checkout" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "payment_method": "sslcommerz",
    "billing_first_name": "Rahim",
    "billing_last_name": "Uddin",
    "billing_email": "rahim@example.com",
    "billing_phone": "01712345678",
    "billing_address_1": "House 12, Road 4, Dhanmondi",
    "billing_city": "Dhaka",
    "billing_postcode": "1205",
    "billing_country": "BD",
    "customer_note": "Please deliver after 5 PM"
  }'
```

#### Example — COD with separate shipping address

```bash
curl -X POST "https://your-domain.com/wp-json/herlan/v1/checkout" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "payment_method": "cod",
    "billing_first_name": "Rahim",
    "billing_last_name": "Uddin",
    "billing_email": "rahim@example.com",
    "billing_phone": "01712345678",
    "billing_address_1": "House 12, Road 4, Dhanmondi",
    "billing_city": "Dhaka",
    "billing_country": "BD",
    "ship_to_different_address": true,
    "shipping_first_name": "Karim",
    "shipping_last_name": "Uddin",
    "shipping_address_1": "Flat 3B, Tower 5, Bashundhara RA",
    "shipping_city": "Dhaka",
    "shipping_country": "BD"
  }'
```

#### Success response (`201`)

```json
{
  "success": true,
  "message": "Order placed successfully.",
  "data": {
    "order_id": 12345,
    "order_number": "12345",
    "status": "pending",
    "total": "1350.00",
    "currency": "BDT",
    "payment_url": "https://your-domain.com/checkout/order-pay/12345/?pay_for_order=true&key=wc_order_abc123",
    "thank_you_url": "https://your-domain.com/checkout/order-received/12345/?key=wc_order_abc123"
  }
}
```

Response fields:

| Field | Type | Notes |
| --- | --- | --- |
| `order_id` | integer | WooCommerce order ID |
| `order_number` | string | Displayed order number |
| `status` | string | Initial order status — usually `"pending"` for SSLCommerz, `"processing"` for COD |
| `total` | string | Grand total (decimal string) |
| `currency` | string | Currency code, e.g. `"BDT"` |
| `payment_url` | string | Open this URL in a WebView to complete payment. For SSLCommerz this shows the pay button that redirects to the gateway. For COD it leads to the order received page |
| `thank_you_url` | string | Order received / thank-you page URL — navigate here after the WebView detects payment completion |

#### WebView flow (SSLCommerz) — mobile app

Orders placed through this API are always tagged `created_via = "herlan-rest-api"`. Because of that tag, the server sends the app a **deep link** instead of a themed WordPress page once the gateway hands control back — this avoids the site header/footer flashing inside the in-app WebView, which is what you'd otherwise see for a moment on the order-received/fail page.

1. Open `payment_url` in a WebView (Custom Tabs / `SFSafariViewController` / `ASWebAuthenticationSession` are all fine).
2. The user completes payment on the SSLCommerz hosted page.
3. SSLCommerz redirects back to the site's configured success or fail page. The plugin intercepts this server-side (before any HTML renders) and 302s the browser to:

   ```text
   herlanlive://payment-result?order_id=12345&status=success
   ```

   (`status` is one of `success`, `pending`, `failed` — a hint only, see step 5.)

   This is a custom URL scheme, not a real page — register `herlanlive://` (or whatever scheme you configure, see below) as a deep link in the app so the WebView's navigation delegate (`shouldOverrideUrlLoading` on Android, `decidePolicyForNavigationAction` on iOS) intercepts it **before any content loads**, so nothing renders inside the WebView.
4. On catching that scheme, immediately close/dismiss the WebView and navigate to a native "processing" screen.
5. Call `GET /payments/status/{order_id}` (see below) to get the **authoritative** status — don't trust the `status` query param on the deep link alone, since the IPN callback that finalizes payment can land slightly after or before the browser redirect. If the app receives `payment_status: "pending"`, poll this endpoint a few times with backoff (e.g. 2s, 5s, 10s) before giving up.
6. Show the success/failure screen based on the polled status, then call `POST /checkout/order-complete` to clear the server-side cart once `payment_status` is `"success"`.

**Configuring the deep-link scheme.** Default is `herlanlive://payment-result`. To change it without touching plugin code, either:

- Define a constant in `wp-config.php`: `define('HERLAN_APP_PAYMENT_DEEPLINK', 'yourapp://checkout-result');`, or
- Hook the filter: `add_filter('herlan_rest_api_payment_deeplink', fn($url, $order) => 'yourapp://checkout-result', 10, 2);`

#### Web (browser) checkout flow

Nothing changes here — this deep-link redirect only fires for orders created through this REST API (i.e. from the mobile app). Orders placed through the site's normal WooCommerce checkout page keep rendering the standard themed order-received/fail pages exactly as before, since they're never tagged `created_via = "herlan-rest-api"`.

### `GET /payments/status/{order_id}`

Authoritative payment status for an order — call this after the WebView/deep-link flow ends (or any time you need to re-check). Supports both logged-in customers and guest checkout.

| Param | Location | Required | Notes |
| --- | --- | --- | --- |
| `id` | path | **Yes** | Order ID |
| `order_key` | query | Conditional | Required for guest orders (`customer_id = 0`) instead of a Bearer token — use the `order_key` returned in `cancel_url.order_key` from the `POST /checkout` response |

Auth: Bearer token (logged-in customers) **or** `order_key` (guest checkout) — same ownership rule as `POST /checkout/order/{id}/cancel`.

Example (logged in):

```bash
curl "https://your-domain.com/wp-json/herlan/v1/payments/status/12345" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Example (guest):

```bash
curl "https://your-domain.com/wp-json/herlan/v1/payments/status/12345?order_key=wc_order_abc123"
```

Response:

```json
{
  "success": true,
  "message": "",
  "data": {
    "order_id": 12345,
    "status": "processing",
    "payment_status": "success",
    "paid": true,
    "transaction_id": "2C9C8A7E1F",
    "total": "1350.00",
    "currency": "BDT"
  }
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `status` | string | Raw WooCommerce order status (`pending`, `processing`, `on-hold`, `failed`, `cancelled`, `completed`, ...) |
| `payment_status` | string | Simplified 3-state value for the app: `success`, `pending`, or `failed` |
| `paid` | boolean | `WC_Order::is_paid()` |
| `transaction_id` | string\|null | Gateway transaction ID, if set |

Error codes: `herlan_order_not_found` (404), `herlan_order_forbidden` (403), `herlan_woocommerce_unavailable` (500).

#### Error codes

| Code | HTTP | Description |
| --- | --- | --- |
| `herlan_cart_empty` | 422 | No items in cart |
| `herlan_invalid_payment_method` | 422 | `payment_method` is not a valid enabled gateway |
| `herlan_missing_field` | 422 | A required billing field is empty |
| `herlan_invalid_email` | 422 | `billing_email` is not a valid email address |
| `herlan_order_create_failed` | 500 | WooCommerce could not create the order |
| `herlan_payment_failed` | 500/502 | Payment gateway returned an error |
| `herlan_woocommerce_unavailable` | 500 | WooCommerce is not active |
| `herlan_cart_unavailable` | 500 | Cart could not be initialized |
| `herlan_rate_limit` | 429 | More than 10 checkout attempts in one hour |

---

### `GET /payments/methods`

Placeholder endpoint (no active gateway configuration).

### `POST /payments/create`

Placeholder endpoint (pending gateway integration).

### `GET /payments/status/{order_id}`

See [above](#get-paymentsstatusorder_id) — documented alongside the WebView flow since it's the confirmation step of that flow.

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
| `GET` | `/search` | No | **Product search (JSON, same engine as website autocomplete)** |
| `GET` | `/group/products` | No | **Product listing with filters** |
| `GET` | `/taxonomies` | No | **All filterable taxonomies and terms** |
| `GET` | `/filter-config` | No | **Filter UI configuration** |
| `GET` | `/filter-terms` | No | **Filter term list with counts** |
| `GET` | `/filter-forms/{id}` | No | **wcapf form inspection** |
| `GET` | `/products/filters` | No | Filter metadata (legacy, use `/products` instead) |
| `GET` | `/products/{id}` | No | Product detail |
| `GET` | `/stores` | No | Store list with district/area/search filters |
| `GET` | `/stores/{id}` | No | Single store detail |
| `GET` | `/store-locations` | No | All districts with their nested areas |
| `GET` | `/drawer-brands-categories` | No | Navigation categories and brands |
| `GET` | `/wishlist` | Yes | Wishlist items |
| `POST` | `/wishlist` | Yes | Add wishlist item |
| `DELETE` | `/wishlist/{product_id}` | Yes | Remove wishlist item |
| `GET` | `/cart` | Yes | Cart contents |
| `POST` | `/cart/add-to-cart` | Yes | Add item to cart |
| `POST` | `/cart/update-item` | Yes | Update item quantity |
| `DELETE` | `/cart/remove-item/{key}` | Yes | Remove one item |
| `DELETE` | `/cart/clear` | Yes | Empty the cart |
| `POST` | `/cart/herlan-cash/toggle` | Yes | Enable or disable Herlan Cash redemption |
| `POST` | `/cart/herlan-cash/set-amount` | Yes | Set a specific Herlan Cash redemption amount |
| `GET` | `/checkout/payment-methods` | Yes | Available payment gateways |
| `POST` | `/checkout` | Yes | **Place order from cart — returns payment URL** |
| `GET` | `/payments/methods` | Yes | Payment method placeholder |
| `POST` | `/payments/create` | Yes | Payment creation placeholder |
| `GET` | `/payments/status/{id}` | Optional | Authoritative payment status (Bearer token or `order_key` for guest orders) |
