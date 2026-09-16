# Catalog API v1

## 1. Overview

The public catalog API is version `v1` and returns JSON.

Base prefix:

```text
/api/v1
```

The catalog endpoints are public and do not require authentication. The old
unversioned `/api/products` routes are unavailable and return `404`.

## 2. Headers

Clients should send:

```http
Accept: application/json
Accept-Language: ar
```

or:

```http
Accept-Language: en
```

Locale resolution is based only on the first language in the request header:

- Missing, empty, or unsupported values resolve to `ar`.
- `ar-EG` resolves to `ar`.
- `en-US` resolves to `en`.

Localized responses include `Content-Language`. `Vary` includes
`Accept-Language`; it may also contain other valid tokens, such as `Origin`.
Clients must not depend on a particular token order.

## 3. CORS

Allowed origins are configured with the comma-separated environment variable
`CORS_ALLOWED_ORIGINS`. Origins are exact values; wildcard origins are
rejected. Credentials are disabled, and `Content-Language` is exposed to the
browser.

The configured request headers include `Accept-Language`, `Authorization`,
and `Idempotency-Key`. The current catalog endpoints do not require
`Authorization`.

Local example:

```env
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000
```

Production example:

```env
CORS_ALLOWED_ORIGINS=https://shiny-style-storefront-839ce62c9569.herokuapp.com
```

## 4. Product listing

```http
GET /api/v1/products
```

Optional query parameters:

| Parameter | Constraints | Behavior |
|---|---|---|
| `q` | string, maximum 100 characters | Searches Arabic name, English name, and slug. |
| `category` | string, maximum 255 characters | Filters by direct membership in an active category. |
| `page` | integer, minimum 1 | Selects the page. |
| `per_page` | integer, 1–100 | Controls page size; default is 24. |

Search is case-insensitive and supports Arabic, English, slug fragments,
partial matching, and multiple whitespace-separated words. `q` and `category`
can be combined. Search values should be URL-encoded.

Category filtering uses direct active-category membership. Parent categories do
not automatically include products attached only to descendants. An unknown,
inactive, or deleted category produces an empty paginated `200` response. An
empty category value applies no category filter.

Only visible products are listed: the product must be active and published,
have an active sellable item, and have an active category. Results are ordered
by `published_at` descending and then `id` descending.

Each item has this exact resource shape (IDs are strings and prices are JSON
numbers):

```json
{
  "id": "1",
  "slug": "soft-sofa-throw-blanket",
  "name": "Soft Sofa Throw Blanket",
  "category": {
    "slug": "home-textiles",
    "name": "Home Textiles"
  },
  "price": 650,
  "originalPrice": 750,
  "badge": "new",
  "inStock": true,
  "image": "https://res.cloudinary.com/dodvtbpwq/image/upload/v1789454206/img2_ponchv.jpg"
}
```

The `category` and `image` values may be `null`; `originalPrice` may also be
`null`.

The listing response is Laravel pagination JSON with `data`, `links`, and
`meta`. `meta` contains the pagination values, including `current_page`,
`per_page`, `last_page`, and `total`.

## 5. Featured products

```http
GET /api/v1/products/featured
```

This returns a JSON object with a `data` array using the same item shape as
product listing. It is not paginated. It contains only visible products whose
`is_featured` value is true, ordered by publication date descending and then
ID descending, with a maximum of 8 results.

Example:

```json
{
  "data": [
    {
      "id": "1",
      "slug": "soft-sofa-throw-blanket",
      "name": "Soft Sofa Throw Blanket",
      "category": {
        "slug": "home-textiles",
        "name": "Home Textiles"
      },
      "price": 650,
      "originalPrice": 750,
      "badge": "new",
      "inStock": true,
      "image": "https://res.cloudinary.com/dodvtbpwq/image/upload/v1789454206/img2_ponchv.jpg"
    }
  ]
}
```

## 6. Product details

```http
GET /api/v1/products/{slug}
```

The detail response uses the listing fields and adds:

```json
{
  "id": "1",
  "slug": "soft-sofa-throw-blanket",
  "name": "Soft Sofa Throw Blanket",
  "category": {
    "slug": "home-textiles",
    "name": "Home Textiles"
  },
  "price": 650,
  "originalPrice": 750,
  "badge": "new",
  "inStock": true,
  "image": "https://res.cloudinary.com/dodvtbpwq/image/upload/v1789454206/img2_ponchv.jpg",
  "description": "A soft throw blanket that adds warmth and comfort to any sofa.",
  "features": ["Soft woven fabric", "Lightweight and comfortable", "Easy to care for"],
  "specifications": {"material": "Cotton blend", "use": "Sofa throw"},
  "gallery": ["https://res.cloudinary.com/dodvtbpwq/image/upload/v1789454206/img2_ponchv.jpg"],
  "videoUrl": null,
  "options": [
    {
      "id": "1",
      "code": "color",
      "name": "Color",
      "values": [
        {"id": "1", "code": "beige", "label": "Beige", "metadata": {"hex": "#D8C3A5"}}
      ]
    }
  ],
  "sellableItems": [
    {
      "id": "1",
      "sku": "THROW-BEIGE-M",
      "price": 650,
      "originalPrice": 750,
      "inStock": true,
      "optionValues": {"color": "beige", "size": "medium"}
    }
  ],
  "defaultSellableItemId": "1"
}
```

The `features`, `specifications`, `gallery`, `options`, and `sellableItems`
values are arrays or objects as shown; `description`, `videoUrl`, and nullable
localized fields can be `null`. The actual collections may contain multiple
entries.

Inactive and soft-deleted variants are hidden. Active out-of-stock variants
remain present and have `inStock: false`. Product `inStock` is true when any
active variant has stock. The display variant is selected in this order:

1. Active default variant.
2. First active, in-stock variant by ascending ID.
3. First active variant by ascending ID.

The endpoint returns `404` when the product is missing, inactive, unpublished,
has no active variant, or has no active category.

## 7. Category listing

```http
GET /api/v1/categories
```

Only active, non-deleted categories are returned. Results are ordered by
`sort_order`, then `id`. The response is a flat collection, not a recursive
tree; `parentId` identifies a parent category and products are not embedded.

Arabic example:

```json
{
  "data": [
    {
      "id": 1,
      "slug": "home-textiles",
      "name": "منسوجات منزلية",
      "description": null,
      "parentId": null,
      "sortOrder": 1
    }
  ]
}
```

English example:

```json
{
  "data": [
    {
      "id": 1,
      "slug": "home-textiles",
      "name": "Home Textiles",
      "description": null,
      "parentId": null,
      "sortOrder": 1
    }
  ]
}
```

Category `id` and `parentId` are integers or `null`.

## 8. Category details

```http
GET /api/v1/categories/{slug}
```

This returns category metadata only, with the same fields as category listing.
It does not embed products. Use `/api/v1/products?category={slug}` to load
products. Missing, inactive, or deleted categories return `404`.

## 9. Localization shape

Localized public fields are single values, for example:

```json
{"name": "ماكينة قهوة إسبريسو"}
```

They are not returned as `{ "ar": "...", "en": "..." }` objects. Fallback
order is:

1. Requested locale.
2. Arabic.
3. English.
4. `null`.

For `features` and `specifications`, bilingual top-level JSON with `ar` and
`en` keys is resolved to the requested locale. Legacy language-neutral JSON is
returned unchanged. The API does not invent translations.

## 10. Errors

- `404 Not Found`: missing or non-public product/category, or an unavailable
  slug.
- `422 Unprocessable Entity`: invalid listing query values, including an
  overlong or non-string `q` or `category`, or invalid pagination values.
- Empty search or unavailable-category results: `200` with an empty `data`
  array and normal pagination metadata for product listing.
- Unsupported language values do not produce an error; they fall back to
  Arabic.

Validation and exception responses use Laravel's current application handling;
no additional global error envelope is defined by the catalog controllers.

## 11. Next.js integration checklist

- Use `/api/v1`.
- Send `Accept: application/json`.
- Send an explicit `Accept-Language: ar` or `Accept-Language: en`.
- Use `encodeURIComponent()` or `URLSearchParams` for search values.
- Do not manually decode Arabic query strings.
- Read pagination metadata from listing responses.
- Treat server-returned price and stock as authoritative.
- Do not use hidden or inactive sellable items.
- Configure the exact frontend origin in the backend deployment environment.

## 12. Deferred endpoints

The following are not part of the current catalog contract:

- Authentication
- Cart persistence
- Shipping
- Checkout
- Orders
- Admin APIs
- Payments
- Notifications

