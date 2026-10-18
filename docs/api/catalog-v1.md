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
| `category` | string, maximum 255 characters | Filters by an active category and its visible descendants. |
| `page` | integer, minimum 1 | Selects the page. |
| `per_page` | integer, 1–100 | Controls page size; default is 24. |
| `sort` | `newest`, `price_asc`, `price_desc` | Sorts by publication or effective displayed price. |
| `min_price`, `max_price` | non-negative decimal, at most 2 fractional digits | Inclusive bounds on the effective displayed price. |
| `offer` | `active` | Keeps products with a currently qualifying offer. |
| `colors[]` | Up to 20 distinct lowercase kebab-case color codes | Filters by matching active color variants with OR semantics. |

Search is case-insensitive and supports Arabic, English, slug fragments,
partial matching, and multiple whitespace-separated words. `q` and `category`
can be combined. Search values should be URL-encoded.

Category filtering matches an active category and its visible descendants. An
unknown, inactive, or deleted category produces an empty paginated `200`
response. An empty category value applies no category filter.

Only visible products are listed: the product must be active and published,
have an active sellable item, and have an active category. The default order is
`published_at` descending and then `id` descending. Price sorts add the same
publication and ID tie-breakers after effective price.

### Admin product readiness

An admin product must satisfy its sellable-item and option-value rules before
it can be activated or scheduled for publication. A future `published_at` is a
schedule; a past publication timestamp left on an inactive product is not.
Draft and inactive products without a future publication time can be prepared
with incomplete variant sets. Active variants must have valid assignments for
all active options. A product without active options must have exactly one
active, unarchived variant with no option values. Inactive and archived
variants do not count toward that rule. Stock may be zero, and products with
options do not need every possible option combination.

Catalog changes that affect variants or option assignments are checked against
the final product state in the same transaction. This prevents changes from
leaving an active or scheduled product incomplete. Existing inactive products
are not automatically repaired or deactivated; they are checked when an admin
activates or schedules them. Variant-structure changes are checked when the
product is already active or has a future publication time.

Each item has this exact resource shape (IDs are strings and prices are JSON
numbers):

```json
{
  "id": "1",
  "slug": "soft-sofa-throw-blanket",
  "name": "Soft Sofa Throw Blanket",
  "displayVariantId": "1",
  "sku": "THROW-BEIGE-M",
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

`displayVariantId` identifies the SellableItem used for the card's displayed
price, image, and availability. It is a string ID or `null` when no valid
active, unarchived variant exists. `sku` comes from that same item and is `null`
when no display variant exists.

The listing response is Laravel pagination JSON with `data`, `links`, and
`meta`. `meta` contains the pagination values, including `current_page`,
`per_page`, `last_page`, and `total`.

Catalog-wide filtering and sorting use only these query parameters:

| Parameter | Accepted values | Default / validation |
|---|---|---|
| `q` | text | Optional, at most 100 characters. |
| `category` | visible category slug | Optional, at most 255 characters; includes visible descendants. |
| `page` | integer | 1 or greater; default 1. An out-of-range page returns 200 with empty `data` and the requested pagination metadata. |
| `per_page` | integer | 1–100; default 24. |
| `sort` | `newest`, `price_asc`, `price_desc` | Default `newest`. |
| `min_price`, `max_price` | non-negative decimal amount | Up to 10 whole digits and 2 fractional digits; bounds are inclusive and `min_price` cannot exceed `max_price`. |
| `offer` | `active` | Includes products with an enabled offer whose start is at or before the request instant and whose end is after it. |
| `colors[]` | 1–20 distinct lowercase kebab-case codes | Optional OR filter; unknown valid codes yield no matches. |

Unknown parameters and unsupported values (including `collection` and
`sort=popular`) return the standard Laravel 422 validation response. Price
filters and price ordering run in the database before pagination. Price means
the current `price` field shown in each `ProductListResource` card: the API
selects the display variant by availability/default priority, then applies the
qualifying product offer. It is not the minimum price across variants, and
out-of-stock products remain eligible if they pass the existing public
visibility rules. A request captures one UTC instant for offer selection;
offer start is inclusive and offer end is exclusive. Multiple qualifying
offers retain the existing pricing invariant failure instead of silently
choosing one.

When `colors[]` is present, only active, non-deleted, non-superseded sellable
items assigned to one of the requested canonical color values can make the
product match. Requested colors use OR semantics. A valid unknown code returns
an empty paginated result. Filtering happens before pagination. A matching
out-of-stock item still matches; the response selects the best matching item
using the same default/availability/ID priority used by ordinary listings. The
filtered item supplies `displayVariantId`, `sku`, `price`, `originalPrice`,
offer fields, `inStock`, and variant-image fallback. Price bounds and sorting
use that same item's effective price and one captured request instant. Without
`colors[]`, display selection keeps the existing behavior. `colors` must be an
array of 1–20 distinct strings matching `^[a-z0-9]+(?:-[a-z0-9]+)*$`; malformed,
duplicate, or oversized lists return 422. `popular` and `collection` remain
unsupported.

Ordering is deterministic:

- `newest`: `published_at DESC, id DESC`.
- `price_asc`: effective displayed price ascending, then `published_at DESC, id DESC`.
- `price_desc`: effective displayed price descending, then `published_at DESC, id DESC`.

For example, the following filtered two-page traversal returns only matches,
with the filtered `total` repeated on each page:

```http
GET /api/v1/products?min_price=200.00&max_price=300.00&sort=price_asc&per_page=1&page=1
```

```json
{"data":[{"id":"101","slug":"linen-shirt","price":200}],"meta":{"current_page":1,"per_page":1,"total":2,"last_page":2}}
```

```http
GET /api/v1/products?min_price=200.00&max_price=300.00&sort=price_asc&per_page=1&page=2
```

```json
{"data":[{"id":"102","slug":"cotton-shirt","price":300}],"meta":{"current_page":2,"per_page":1,"total":2,"last_page":2}}
```

These IDs and records are sanitized response examples, not current catalog
fixtures. `Accept-Language: ar` and `en` localizes card text but does not
change eligibility, IDs, or ordering.

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
      "displayVariantId": "1",
      "sku": "THROW-BEIGE-M",
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
  "displayVariantId": "1",
  "sku": "THROW-BEIGE-M",
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

The display variant is selected using this exact order:

1. The active, unarchived default variant with `availableQuantity() > 0`.
2. Otherwise, the first active, unarchived variant with
   `availableQuantity() > 0`, ordered by ascending ID.
3. If all active, unarchived variants are unavailable, the active,
   unarchived default variant.
4. Otherwise, the first active, unarchived variant ordered by ascending ID.

`availableQuantity()` is `stock_quantity - reserved_quantity`. The displayed
price, display image, `inStock`, and offer presentation all use the selected
display variant. The image uses that variant's primary image when available,
and falls back to the Product's primary image.

Inactive and soft-deleted variants are hidden. Active variants with no
available-to-sell inventory remain present and have `inStock: false`.
Availability is calculated as `stock_quantity - reserved_quantity`; reservations
therefore reduce availability immediately. Product `inStock` reflects the
selected display variant's available-to-sell inventory.

The checkout quote checks current availability but does not reserve stock.
Order creation performs the final availability check and creates the
reservation. A successful quote therefore does not guarantee that the same
quantity will still be available when the order is submitted.

The endpoint returns `404` when the product is missing, inactive, unpublished,
has no active variant, or has no active category.

### Structured data source payload

Product detail adds `structuredData.product` and `structuredData.breadcrumbs`;
category detail adds `structuredData.breadcrumbs`. These are typed source data
for the frontend. The API does not return JSON-LD or HTML. Existing `seo`,
`sellableItems`, `gallery`, and category `breadcrumbs` remain available.

Example product payload (values depend on the requested locale and current
catalog state):

```json
{
  "structuredData": {
    "product": {
      "productId": "42",
      "name": "Soft Sofa Throw Blanket",
      "description": "A soft throw blanket.",
      "images": ["https://cdn.example/image.jpg"],
      "canonicalPath": "/products/soft-sofa-throw-blanket",
      "displayVariantId": "81",
      "offer": {
        "sellableItemId": "81",
        "sku": "THROW-BEIGE-M",
        "offerId": null,
        "offerApplied": false,
        "price": 650,
        "priceCurrency": "EGP",
        "availability": "InStock"
      },
      "variants": [
        {
          "id": "81",
          "sku": "THROW-BEIGE-M",
          "offerId": null,
          "offerApplied": false,
          "price": 650,
          "originalPrice": 750,
          "priceCurrency": "EGP",
          "availability": "InStock",
          "inStock": true,
          "images": ["https://cdn.example/image.jpg"],
          "optionValues": {"color": "beige", "size": "medium"}
        }
      ],
      "variationAttributes": [],
      "productGroup": null
    },
    "breadcrumbs": [
      {"position": 1, "entityType": "category", "id": "7", "name": "Home Textiles", "path": "/category/home-textiles"},
      {"position": 2, "entityType": "product", "id": "42", "name": "Soft Sofa Throw Blanket", "path": "/products/soft-sofa-throw-blanket"}
    ]
  }
}
```

`productId` and variant IDs are catalog identifiers; an ID is never substituted
for a missing SKU. Names and descriptions follow the existing requested-locale
fallback. Images are public media URLs from the selected variant and product
gallery, de-duplicated; each variant's own images are also included in its
variant entry. `canonicalPath` comes from the existing SEO resolver and is a
logical path without locale or domain. The frontend adds its configured locale
and canonical origin.

`offer` describes only the currently displayed variant. Its SKU is the actual
SellableItem SKU, its price and offer fields come from the existing
`OfferPricingService` result, and availability uses the existing
`availableQuantity()` calculation (stock minus reservations). `priceCurrency`
comes from the single store currency setting in `config/store.php` (`EGP`),
also used when creating new checkout quotes and orders. Existing order,
payment, and refund currency fields are historical transaction snapshots; this
setting does not rewrite them. The orders table's EGP default remains a schema
default, while runtime commerce and structured data use the config source.

`variants` contains current public sellable items only; inactive, archived,
soft-deleted, and superseded variants are excluded. Pricing, SKU, availability,
and variant image remain attached to their own variant. `productGroup` is
present only when multiple current variants and complete option assignments
provide a reliable group and `variesBy` relationship. It uses the product ID
as `productGroupId`, never as a SKU. Regardless of grouping, the `offer` stays
the single displayed variant offer. No `AggregateOffer` is inferred for a set
of variants.

Breadcrumb entries use the actual visible category hierarchy, localized names,
and `/category/{slug}` paths. Product breadcrumbs follow the API's existing
primary-category choice, then append the product using its resolved canonical
path. Category detail breadcrumbs contain the visible root-to-category path.
No path is reconstructed from URL text. `structuredData.breadcrumbs` uses
1-based positions and carries `entityType`, catalog `id`, localized `name`,
and locale-free logical `path` for each entry.

The payload deliberately omits brand, GTIN, MPN, ratings, reviews, seller
shipping details, and return-policy terms because no authoritative source for
those values is currently wired to the catalog response. When the localized
`storeName` setting exists, `structuredData.product.seller` contains the same
organization identity returned below; it is `null` when that locale has no
usable store name. The frontend should map these source fields to Schema.org
types and emit JSON-LD in page HTML; it must not fill missing values with
guesses or accept free-form JSON-LD from admin input.

## SEO redirects

Redirect rows are scoped to the host (and non-default port) from the configured
global `canonical_base_url` SEO setting. The host is not hard-coded. If that
setting is absent or invalid, the public resolver safely returns no match and
manual redirect writes are rejected; changing the slug of a currently public
product also fails atomically until a valid canonical origin is configured.
Configure the production HTTPS origin before enabling redirect management.
Changing that origin selects a new domain scope; existing rows are not copied
to the new scope automatically, so domain migrations need an explicit redirect
data plan.

The API stores locale-prefixed logical paths, such as
`/ar/products/old-slug` and `/en/products/old-slug`. It does not store a full
URL or issue an HTTP redirect. Product slug changes create 301 history for both
supported locales when the old product page was publicly visible. Robots
`noindex` does not make a public URL ineligible for history. Repeated changes
update earlier product history to the latest path rather than building chains.
Category and mapped content paths can be managed manually; automatic history
is currently connected only to product slug updates.

Manual redirect creation uses the exact camelCase JSON keys `sourcePath`,
`destinationPath`, and `statusCode` (301 or 308); snake_case aliases and
unknown keys are rejected. Both paths must be normalized, locale-prefixed
internal product, category, or mapped content routes. External, protocol-
relative, escaped, query/fragment, unsupported, or otherwise unsafe paths are
rejected. Source and destination must use the same locale and cannot be
identical. A source that is live or already reserved is rejected; the
destination must currently be public; conflicts, loops, and chains are
rejected.

PATCH accepts only `destinationPath`, `statusCode` (301/308), and `isActive`;
`sourcePath` is immutable. Destination changes must stay in the source locale.
DELETE deactivates the row and returns the updated redirect resource. The
public resolver returns the stored 301 or 308 and exact same-locale destination
only while the redirect is active and its destination remains safe and public.

Every source path remains reserved while its row exists, including when the
row is inactive. This prevents a live product/category path from shadowing
history or creating a loop. The API does not silently delete old history or
reuse a reserved slug. Product/category create, update, and restore operations
validate this reservation. Archiving or deleting a destination does not create
a redirect; the resolver returns no match whenever its source is live or its
destination is no longer public. A redirect becomes usable again only if its
destination is public and its row remains active.

### Public resolver

```http
GET /api/v1/seo/redirects/resolve?path=%2Fen%2Fproducts%2Fold-slug
Accept: application/json
```

Matched response:

```json
{
  "data": {
    "destinationPath": "/en/products/new-slug",
    "statusCode": 301
  }
}
```

No match is `{"data":null}`. Unsupported, non-normalized, external, or
unrecognized paths receive `422` validation errors. Supported paths are
locale-prefixed product, category, and mapped content paths only. The frontend
should use the returned status code for a permanent redirect on its own
storefront host, preserving the locale in the path; it must not redirect to the
API host or assume that a redirect exists when the response is null.

Lookup caches positive and negative results for up to ten minutes without
requiring cache tags. Writes invalidate their source lookup after the database
transaction commits. Public visibility is checked again during each resolution,
so cached rows cannot bypass current product/category/content visibility.
Changes to the storefront's domain and any future frontend lookup cache or
Sitemap integration need corresponding cache invalidation wiring; those
integrations are not part of this API change. The resolver currently has no
dedicated rate limiter; deployment-level request limits should be considered
if this endpoint is exposed directly to untrusted traffic.

### Admin redirect management

All management endpoints use the existing `auth:sanctum` and `admin.access`
middleware. Listing is paginated (`per_page` defaults to 20 and is limited to
1–100). Creation accepts a mapped, non-live source, a currently public
internal destination, and status 301 or 308:

```http
POST /api/v1/admin/redirects
Authorization: Bearer <admin-token>
Content-Type: application/json
```

```json
{
  "sourcePath": "/en/products/legacy-name",
  "destinationPath": "/en/products/current-name",
  "statusCode": 301
}
```

`PATCH /api/v1/admin/redirects/{id}` can change `destinationPath`,
`statusCode`, or `isActive`. Activating a row revalidates its internal
destination and rejects live sources and chains. `DELETE` deactivates the row
without deleting its audit/history record. Creation, changes, and deactivation
are written to the existing SEO audit log in the same transaction. The
database unique key on `(domain_scope, source_hash)` is the final duplicate
guard, and the exact source text is also checked after hash lookup.

The existing API has no general public API throttle; only selected commerce and
authentication routes use named limiters. This redirect resolver follows that
existing convention rather than introducing a new limiter in this change.

### Store and merchant structured data source

```http
GET /api/v1/store/structured-data
```

This aggregate public endpoint is an implementation choice so the frontend
can load the Organization/OnlineStore, WebSite, contact, social profile, EGP,
and policy reference data from one locale-aware response. It reuses the
existing SEO settings, public store contact, enabled social links, content
page service, canonical mapping, and store currency. It exposes only the
allowlisted public fields; the Admin SEO settings response and contact/social
administration fields are not returned.

Example when the policy pages exist but store identity and canonical origin
have not been configured:

```json
{
  "data": {
    "organization": {
      "organizationId": null,
      "name": null,
      "url": null,
      "logo": null,
      "contact": {
        "supportPhone": null,
        "supportWhatsapp": null,
        "supportEmail": null,
        "address": null,
        "googleMapsUrl": null
      },
      "sameAs": []
    },
    "website": {
      "websiteId": null,
      "name": null,
      "url": null,
      "inLanguage": "en",
      "publisherId": null
    },
    "currency": "EGP",
    "policies": {
      "returns": {"contentPageSlug": "returns-policy", "path": "/return-policy", "url": null},
      "shipping": {"contentPageSlug": "shipping-policy", "path": "/shipping-policy", "url": null}
    }
  }
}
```

`organization.name` and `website.name` come from the `storeName` SEO setting
for the request locale only. Missing translations stay `null`; they do not
fall back to a default SEO title or another locale. The logo comes only from
`storeLogoId`, and is omitted if its image asset is missing/deleted, not an
image, on a non-publicly served disk, or cannot produce an absolute HTTP(S)
URL. `defaultOgImageId` is not treated as a store logo. `url` comes only from
the HTTPS origin in `canonicalBaseUrl`; `APP_URL` and frontend defaults are
not used as a fallback. `organizationId`, `websiteId`, and
`website.publisherId` are derived from that configured origin, or `null` when
the origin is absent or invalid.

`contact` uses the same public fields and localized address as
`GET /api/v1/store/contact`. `sameAs` contains only enabled Facebook,
Instagram, and TikTok profile links that still pass the platform host/path
rules used by the admin link validator. WhatsApp remains in the public contact
object, not `sameAs`; disabled, unsupported, or invalid persisted links are
omitted. `currency` uses the single `config/store.php` EGP setting shared with
checkout and new order creation.

`policies.returns` and `policies.shipping` are references only. Each appears
only when its corresponding content page record exists and uses the confirmed
canonical mapping: `returns-policy` → `/return-policy` and
`shipping-policy` → `/shipping-policy`. Its absolute `url` is present only
when `canonicalBaseUrl` is configured. The API does not derive refund timing,
fees, shipping speed, or other merchant policy terms from page prose. Missing
page records produce `null` references.

Product `seller` is generated from the same identity resolver and has the same
`organizationId`, localized `name`, origin `url`, and public `logo` as this
endpoint's `organization`; the storefront can reuse it rather than constructing
a conflicting merchant. The frontend remains responsible for translating
these fields to JSON-LD types and for adding the final HTML script tag.

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

### Content pages

```http
GET /api/v1/content-pages/{slug}
```

The API slug remains the backend content record slug. Public logical
`seo.canonicalPath` values use the confirmed frontend route mapping below;
they do not include a locale prefix or domain. The frontend adds both from its
own routing and canonical-origin configuration.

| Backend slug | `seo.canonicalPath` |
|---|---|
| `about-us` | `/about` |
| `returns-policy` | `/return-policy` |
| `shipping-policy` | `/shipping-policy` |

For these pages, an admin canonical override is accepted only when it exactly
matches the mapped path for that record. A missing override uses the mapped
path. If a stored override no longer matches, the API returns the mapped path
without changing the stored SEO record during the read. A content page without
a confirmed mapping returns `null`; the API does not infer a path from its
backend slug. For example, `privacy-policy` currently has no confirmed public
mapping.

The page response keeps its localized `title` and `body` fields and includes
the shared `seo` object. Its `canonicalPath` remains locale-free, for example:

```json
{
  "data": {
    "title": "About us",
    "seo": {
      "canonicalPath": "/about"
    }
  }
}
```

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

For `specifications`, bilingual top-level JSON with `ar` and `en` keys is
resolved to the requested locale. For new `features`, the admin API uses a
paired list so each entry keeps its own translations. The public API resolves
each paired feature independently and falls back to the other language when
the requested translation is missing or blank. Legacy feature formats remain
readable and retain their existing localization behavior; the API does not
invent translation pairings.

Admin product create and update requests accept the same JSON fields. The
recommended bilingual shape is:

```json
{
  "features": [
    {"ar": "ميزة عربية", "en": "English feature"}
  ],
  "specifications": {
    "ar": {"اللون": "أحمر"},
    "en": {"Color": "Red"}
  }
}
```

The Admin product response returns paired features unchanged:

```json
{
  "features": [
    {"ar": "خامة عالية الجودة", "en": "High-quality fabric"},
    {"ar": "سهل التنظيف", "en": "Easy to clean"}
  ]
}
```

Public product details still return a localized string list, not bilingual
objects:

```json
// Accept-Language: ar
{"features": ["خامة عالية الجودة", "سهل التنظيف"]}

// Accept-Language: en
{"features": ["High-quality fabric", "Easy to clean"]}
```

Each new feature object must contain only `ar` and `en`; both keys are
required, values are strings or `null`, and at least one translation must be
nonempty. Surrounding whitespace is trimmed and blank values become `null`.
On `PATCH`, omitted `features` is preserved, `null` clears it, and `[]`
replaces it with an empty list. Sending entries replaces the whole list; it
does not merge entries. The frontend must send the complete paired list when
editing one translation.

Existing legacy feature data is detected as either a language-keyed list
(`{"ar": [...], "en": [...]}`), a flat string list, or empty/null. These
legacy shapes remain readable and accepted with their format-specific
validation. The backend does not zip independent language arrays or guess a
language for flat strings. An admin must explicitly review and save a paired
list to establish translation pairs. Specifications retain their existing
shape and behavior unless the new paired specification list is used.

The recommended admin shape for new specifications is:

```json
{
  "specifications": [
    {
      "ar": {"label": "الخامة", "value": "قطن"},
      "en": {"label": "Material", "value": "Cotton"}
    },
    {
      "ar": {"label": "العناية", "value": "غسيل بارد"},
      "en": {"label": "Care", "value": "Cold wash"}
    }
  ]
}
```

Each paired specification requires both `ar` and `en`. Each translation must
contain only `label` and `value`; both fields are strings or `null`, and a
translation must contain both nonempty fields or both `null`. At least one
complete translation is required per entry. Labels are normalized before
validation, and duplicate labels are rejected for both Arabic and English
public projections, including collisions caused by fallback. The backend
never combines a label from one language with a value from another.

Public details serialize paired specifications as a localized label/value
object while preserving order:

```json
// Accept-Language: ar
{"specifications": {"الخامة": "قطن", "Care": "Cold wash"}}

// Accept-Language: en
{"specifications": {"Material": "Cotton", "Care": "Cold wash"}}
```

Legacy language-keyed objects, single-language objects, and empty/null values
remain readable and accepted with their existing behavior. The backend does
not pair legacy objects by order, count, or matching labels. An admin must
explicitly review and replace legacy data with the paired list. `PATCH`
omission preserves `specifications`, `null` clears it, `[]` stores an empty
list, and a supplied list replaces the complete field.

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

## SEO sitemap eligibility items

`GET /api/v1/seo/sitemap-items` returns bounded batches of public pages that are
currently eligible for indexing. The API supplies data only; the frontend
builds and publishes the XML sitemap on its configured store domain.

Query parameters:

| Parameter | Required | Values | Default / limit |
| --- | --- | --- | --- |
| `type` | yes | `product`, `category`, `page` | One entity type per traversal |
| `cursor` | no | opaque cursor returned by this endpoint | Must belong to the same `type` |
| `limit` | no | integer | 50; maximum 100 |

Example:

```http
GET /api/v1/seo/sitemap-items?type=product&limit=50
Accept: application/json
```

```json
{
  "data": {
    "items": [
      {
        "id": 42,
        "entityType": "product",
        "slug": "linen-shirt",
        "eligibleLocales": ["ar", "en"],
        "locales": [
          {
            "locale": "ar",
            "path": "/products/linen-shirt",
            "lastSignificantModifiedAt": "2026-05-01T12:00:00.000000Z"
          },
          {
            "locale": "en",
            "path": "/products/linen-shirt",
            "lastSignificantModifiedAt": "2026-05-01T12:03:00.000000Z"
          }
        ]
      }
    ],
    "nextCursor": "eyJ2IjoxLCJ0eXBlIjoicHJvZHVjdCIsImlkIjo0Mn0"
  }
}
```

`locales` carries the locale-specific canonical path and significant
modification timestamp. `eligibleLocales` repeats the locale identifiers for
simple grouping. Paths are logical and locale-free: the frontend prefixes each
with the matching locale and configured store origin exactly once. Page paths
use the existing canonical mapping, for example `about-us` maps to `/about`.

For a category, `entityType` is `category` and `path` uses the existing
`/category/{slug}` canonical. For a content page, `entityType` is `page` and
`path` uses the confirmed content-page mapping. Items are emitted only when
public visibility, the deployment indexing switch, supported locale and real
translation, locale robots policy, and canonical path all allow indexing.
Sold-out products remain eligible when their other public visibility rules
pass. Old redirect sources are excluded.

Category and content-page item examples:

```json
{
  "id": 9,
  "entityType": "category",
  "slug": "summer",
  "eligibleLocales": ["ar"],
  "locales": [
    {
      "locale": "ar",
      "path": "/category/summer",
      "lastSignificantModifiedAt": "2026-05-01T12:00:00.000000Z"
    }
  ]
}
```

```json
{
  "id": 3,
  "entityType": "page",
  "slug": "about-us",
  "eligibleLocales": ["ar", "en"],
  "locales": [
    {
      "locale": "ar",
      "path": "/about",
      "lastSignificantModifiedAt": "2026-05-01T12:00:00.000000Z"
    },
    {
      "locale": "en",
      "path": "/about",
      "lastSignificantModifiedAt": "2026-05-01T12:00:00.000000Z"
    }
  ]
}
```

Continue by passing `nextCursor` unchanged with the same `type` and desired
`limit`. Stop only when `nextCursor` is `null`. A successful empty result is
`{"items":[],"nextCursor":null}`. Invalid input or a cursor for another type
returns the standard Laravel validation error (422); database read failures
remain errors and are never represented as an empty successful result.

Each request examines at most 200 public candidate records and returns no more
than 100 eligible items. Significant modification timestamps for the emitted
locale rows are aggregated in a bounded batch; product offers and redirect
sources are also loaded by batch. When filtering leaves no item in a batch but more
candidates remain, the endpoint returns an advancing cursor with an empty
`items` array. Traversals use stable ascending ID keysets and do not load the
whole product catalog. They do not provide a database snapshot across multiple
requests: content, visibility, indexing settings, and timestamps may change
between batches. The frontend must discard an incomplete traversal after any
failed batch and must not publish a partial sitemap as complete.

After a successful content or SEO change, frontend sitemap generation and its
cache should be refreshed through the future commit-level integration point.
That invalidation integration and XML generation/publication are not part of
this API endpoint.

## Product colors (current backend contract)

Option values reuse `product_options` and `product_option_values`; there is no separate color table. The sole canonical option code is `color`. Color values use lowercase kebab-case codes such as `beige`, `grey`, `black`, and `navy-blue`; translated Arabic/English labels are presentation only. IDs and translated names are never filter keys. `popular` and `collection` remain unsupported.

### Admin suggestions: GET `/api/v1/admin/catalog/colors`

Requires Sanctum authentication and `admin.access`. No body or query parameters. Returns one row per consistent canonical code, sorted by code, and includes color values associated with non-deleted products regardless of published/draft/inactive status:

```json
{"data":[{"code":"beige","nameAr":"بيج","nameEn":"Beige"},{"code":"grey","nameAr":"رمادي","nameEn":"Grey"}]}
```

IDs are intentionally omitted. Empty catalog is `{ "data": [] }`. If a stored code is noncanonical, has blank labels, or disagrees on Arabic/English labels, returns 409 with a `message` and `conflicts:[{code,translations:[{nameAr,nameEn}]}]`; it does not silently merge them. A guest receives 401; an authenticated non-admin receives 403. There are no writes during GET. No explicit HTTP cache policy is set. This does not audit a production database: runtime DB audit must run in the target environment before rollout.

### Public filter choices: GET `/api/v1/products/filter-options`

Public, no auth, body, or query parameters. Locale follows `Accept-Language` (`ar` default; `en` supported), with `Content-Language` and `Vary: Accept-Language` from API middleware. Returns globally available color facets without category/search context:

```json
{"data":{"colors":[{"code":"beige","name":"بيج"},{"code":"grey","name":"رمادي"}]}}
```

English response uses the same codes and English `name` values (for example `{"code":"beige","name":"Beige"}`). Only canonical color values assigned to active, non-deleted, non-superseded sellable items for currently visible, published products are included. Hidden products/categories, deleted option/value rows, superseded/deleted variants, and orphan values are excluded. Empty result is `{ "data": { "colors": [] } }`. Noncanonical codes, blank labels, or conflicting legacy names return 409 with conflict details. Reads do not write. No explicit HTTP cache policy is set.

### Listing filter: GET `/api/v1/products?colors[]=beige&colors[]=grey`

`colors` accepts up to 20 distinct lowercase kebab-case codes. Multiple values use OR semantics. Filtering occurs in SQL before pagination; a syntactically valid unknown code returns the normal 200 empty paginated response. Invalid shape/code, duplicates, more than 20 values, and other unknown query parameters return 422. Existing product visibility, category/search/price/offer/sort rules remain in force. An out-of-stock matching variant keeps the product eligible.

With colors selected, display variant priority is: matching default with positive available quantity; first matching in-stock variant by ID; matching default even if out of stock; first matching variant by ID. That one item determines `displayVariantId`, `sku`, displayed/base/effective offer prices, `originalPrice`, `offerApplied`, `offerId`, `discountPercentage`, `inStock`, and variant image fallback. Price bounds and price sorting use that same effective price and the listing request's single UTC offer timestamp. Without `colors[]`, the previous selection behavior is unchanged. Arabic and English requests retain the same filtered product IDs and order; localized names still follow locale middleware.

Example filtered request: `GET /api/v1/products?colors%5B%5D=navy-blue&min_price=100&sort=price_asc&per_page=12`. Example validation error:

```json
{"message":"The given data was invalid.","errors":{"colors.1":["The colors.1 field has a duplicate value."]}}
```

Tests: ``tests/Feature/Api/AdminProductOptionApiTest::test_color_codes_are_canonical_and_reused_translations_must_match`, `::test_admin_color_suggestions_include_nonpublic_products_and_do_not_write`, `::test_admin_color_suggestions_report_legacy_translation_conflicts`, `::test_legacy_color_options_and_values_cannot_be_restored_with_noncanonical_codes`, `tests/Feature/Api/AdminProductConversionApiTest::test_conversion_rejects_color_aliases_and_noncanonical_color_value_codes`, `tests/Feature/Api/ProductCatalogFilteringTest::test_color_filter_uses_matching_variant_for_price_offer_sku_and_out_of_stock_state`, `::test_color_filters_use_or_semantics_before_pagination_and_unknown_codes_return_empty`, and `::test_public_color_filter_options_localize_labels_and_exclude_nonvisible_products`. Product option/value changes use existing significant-modification and revalidation services; checkout, pricing snapshots, payment, and inventory lifecycle are not changed.

### Data audit status

Repository fixtures contain the canonical option `color` and values `beige` and `grey` in `database/seeders/ProductCatalogSeeder.php`; source search found no legacy `colors`/`colour` option fixture. The checked-in `database/database.sqlite` has no product option tables. This shell's `.env` selects PostgreSQL, but PHP, Composer, `psql`, and a Laravel database-query connection are unavailable here. Therefore no claim is made about exact production rows or conflicts. Existing database rows have not been rewritten. Run a read-only query in the configured target database for option code/name aliases and invalid/duplicate color value codes before rollout; resolve any returned rows explicitly through an approved admin repair rather than an automatic rewrite.

Read-only PostgreSQL audit query for exact legacy option/value records:

```sql
SELECT p.slug, po.id AS option_record_id, po.code AS option_code,
       po.name_ar AS option_name_ar, po.name_en AS option_name_en,
       pov.id AS value_record_id, pov.code AS value_code,
       pov.value_ar, pov.value_en, pov.deleted_at AS value_deleted_at
FROM product_options AS po
JOIN products AS p ON p.id = po.product_id
LEFT JOIN product_option_values AS pov ON pov.product_option_id = po.id
WHERE (lower(po.code) IN ('color', 'colors', 'colour', 'colours') AND po.code <> 'color')
   OR po.code IN ('لون', 'اللون', 'ألوان', 'الوان')
   OR lower(po.name_en) IN ('color', 'colors', 'colour', 'colours')
   OR po.name_ar IN ('لون', 'اللون', 'ألوان', 'الوان')
   OR (po.code = 'color' AND pov.id IS NOT NULL
       AND pov.code !~ '^[a-z0-9]+(-[a-z0-9]+)*$')
ORDER BY p.slug, po.id, pov.id;

SELECT pov.code AS color_code,
       array_agg(DISTINCT (pov.value_ar, lower(pov.value_en))) AS translations,
       array_agg(pov.id ORDER BY pov.id) AS value_record_ids
FROM product_option_values AS pov
JOIN product_options AS po ON po.id = pov.product_option_id
WHERE po.code = 'color'
GROUP BY pov.code
HAVING count(DISTINCT (pov.value_ar, lower(pov.value_en))) > 1
ORDER BY pov.code;
```

For a conflicting canonical value code, record the authoritative bilingual labels first. Temporarily move every conflicting value record to a distinct valid kebab-case repair code through the admin value endpoint, correct translations while each code is unique, then set the records back to the shared canonical code with identical labels. Confirm that value assignments stay on the intended variants and that no order or inventory records are changed. If a noncanonical option coexists with a `color` option on one product, reconcile its value assignments into the canonical option before archiving the alias. Review and approve the exact row list before repair; never automate this procedure.

