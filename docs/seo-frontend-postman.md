# SEO API Postman Guide for the Frontend

**Integration reference:** Shiny-Style-Backend-SEO-Frontend-Handoff-2026-10-16.md (attached)
**Detailed repository contract:** [seo-frontend-contract.md](seo-frontend-contract.md)
**API version:** /api/v1
**Locales:** ar and en

This guide turns the endpoints in the handoff into Postman-ready requests and examples. Response examples are sanitized and illustrative; they are not captures from a deployed environment. The routes are present in routes/api.php. Actual values depend on the environment data.

## 1. Postman environment

Create an Environment with these variables:

| Variable | Example value | Description |
|---|---|---|
| baseUrl | http://127.0.0.1:8000 | Local API or staging URL |
| adminToken | Set your admin token | Never commit a real token or share it in a collection |
| productId | 101 | ID from an admin product resource |
| categoryId | 12 | ID from an admin category resource |
| contentPageId | 42 | ID from GET /api/v1/admin/content-pages |
| productSlug | winter-blanket | Existing product slug |
| categorySlug | bedding | Existing category slug |

Common headers:

    Accept: application/json
    Accept-Language: ar

Use Accept-Language: en for English. Locale is selected through this header, not a query parameter. Add this header to admin requests:

    Authorization: Bearer {{adminToken}}

All routes below use {{baseUrl}}/api/v1. Admin routes require Sanctum authentication and admin.access authorization.

## 2. Public SEO settings for site pages

### Read resolved values

**GET** {{baseUrl}}/api/v1/seo/settings — public; no token required.

    GET {{baseUrl}}/api/v1/seo/settings
    Accept: application/json
    Accept-Language: ar

Response shape:

    {
      "data": {
        "locale": "ar",
        "pages": {
          "home": {
            "title": "Home | Shiny & Style",
            "description": "Discover the store's products.",
            "canonicalPath": "/",
            "robots": { "index": true, "follow": true },
            "openGraph": {
              "title": "Home | Shiny & Style",
              "description": "Discover the store's products.",
              "image": null
            },
            "indexable": true,
            "availableLocales": ["ar", "en"],
            "lastSignificantModifiedAt": "2026-10-16T12:00:00.000000Z"
          },
          "shop": { "canonicalPath": "/shop" },
          "contact": { "canonicalPath": "/contact" }
        }
      }
    }

The shop and contact values use the same SEO DTO as home; they are abbreviated above. Their logical paths are /, /shop, and /contact. The backend does not add the locale prefix or domain; the frontend adds each once. For English, send Accept-Language: en. Do not treat false as a missing value or fall back to Arabic text for English. The public endpoint returns resolved values after fallback, not the raw stored overrides.

### Read and update fixed-page settings

**GET** {{baseUrl}}/api/v1/admin/seo/settings — protected.
**PUT** {{baseUrl}}/api/v1/admin/seo/settings — protected; writes settings.

    PUT {{baseUrl}}/api/v1/admin/seo/settings
    Authorization: Bearer {{adminToken}}
    Accept: application/json
    Content-Type: application/json

Request body:

    {
      "settings": [
        { "key": "homeSeoTitle", "locale": "ar", "value": "الرئيسية | Shiny & Style" },
        { "key": "homeMetaDescription", "locale": "ar", "value": "تسوقي أحدث المنتجات." },
        { "key": "homeSeoTitle", "locale": "en", "value": "Home | Shiny & Style" },
        { "key": "homeRobotsPolicy", "value": { "index": false, "follow": true } }
      ]
    }

The shop and contact pages use the corresponding keys, such as shopSeoTitle and contactMetaDescription. Titles and descriptions are localized. Robots and the Open Graph image are page-level settings without a locale. An omitted entry remains unchanged. A supported value of null clears its override; false is an explicit value. After PUT, call the public endpoint with the desired language to inspect the resolved value. Perform writes only in a local or dedicated test environment.

## 3. Content pages and the SEO editor

### Admin content pages

| Method | URL | Access |
|---|---|---|
| GET | {{baseUrl}}/api/v1/admin/content-pages | Admin |
| GET | {{baseUrl}}/api/v1/admin/content-pages/about-us | Admin |
| PUT | {{baseUrl}}/api/v1/admin/content-pages/about-us | Admin |

Admin content resources include id, slug, titleAr, titleEn, bodyAr, bodyEn, createdAt, and updatedAt. A predefined page may have id: null until it is first saved. Never derive the ID from the slug. Content management routes continue to use the slug. Content PUT requires both languages' title and body fields in the same request.

### Read and update content-page SEO

The SEO route alias is content_page; do not use page.

| Method | URL | Access |
|---|---|---|
| GET | {{baseUrl}}/api/v1/admin/seo/content_page/{{contentPageId}} | Admin |
| PUT | {{baseUrl}}/api/v1/admin/seo/content_page/{{contentPageId}} | Admin |

Partial Arabic update:

    PUT {{baseUrl}}/api/v1/admin/seo/content_page/{{contentPageId}}
    Authorization: Bearer {{adminToken}}
    Accept: application/json
    Content-Type: application/json

    {
      "locale": "ar",
      "metaTitle": "من نحن",
      "metaDescription": "تعرفي على المتجر.",
      "robots": { "index": false }
    }

English is saved independently:

    {
      "locale": "en",
      "metaTitle": "About us",
      "metaDescription": "Learn more about the store."
    }

To clear an override, send null explicitly:

    {
      "locale": "ar",
      "metaTitle": null,
      "openGraph": { "imageId": null }
    }

Fields omitted from the request remain unchanged. SEO saves do not update page content, and content saves do not update SEO. A custom canonical must match the page's confirmed path. Current mappings are about-us to /about, returns-policy to /return-policy, and shipping-policy to /shipping-policy. Removing the final stored override may return data: null.

### Read a public content page

**GET** {{baseUrl}}/api/v1/content-pages/about-us — public; choose the locale with the header.

    GET {{baseUrl}}/api/v1/content-pages/about-us
    Accept: application/json
    Accept-Language: en

The response contains title, body, and seo. It does not currently contain structuredData for content pages. A missing translation does not automatically produce a 404; title and body may be null, and seo.indexable is false for the unavailable locale.

## 4. Products, categories, and structured data

### Public detail routes

| Method | URL | Response additions |
|---|---|---|
| GET | {{baseUrl}}/api/v1/products/{{productSlug}} | seo, structuredData.product, structuredData.breadcrumbs |
| GET | {{baseUrl}}/api/v1/categories/{{categorySlug}} | seo and breadcrumbs |
| GET | {{baseUrl}}/api/v1/content-pages/{slug} | Content and seo; no structuredData |
| GET | {{baseUrl}}/api/v1/store/structured-data | Organization, WebSite, currency, and available policy references |

Example product request:

    GET {{baseUrl}}/api/v1/products/{{productSlug}}
    Accept: application/json
    Accept-Language: en

Use the displayed variant's price, SKU, availability, and image from the response. Do not recalculate these values in the frontend. Use structuredData.product.seller so seller identity stays consistent with the store identity. The configured store currency is EGP. Do not invent Brand, GTIN, ratings, reviews, or shipping/return policy details.

Example store request:

    GET {{baseUrl}}/api/v1/store/structured-data
    Accept: application/json
    Accept-Language: ar

The response includes data.organization, data.website, data.currency, and data.policies. Some values may be null when no trusted source is configured. Do not derive shipping fees, delivery times, or return terms from free-text policy pages.

## 5. Sitemap eligibility feed

**GET** {{baseUrl}}/api/v1/seo/sitemap-items — public.

| Query parameter | Required | Accepted values / limit |
|---|---:|---|
| type | Yes | product, category, page |
| limit | No | 1–100; default 50 |
| cursor | No | Opaque value from data.nextCursor; keep the same type |

    GET {{baseUrl}}/api/v1/seo/sitemap-items?type=product&limit=100
    Accept: application/json
    Accept-Language: ar

    {
      "data": {
        "items": [
          {
            "id": 101,
            "entityType": "product",
            "slug": "winter-blanket",
            "eligibleLocales": ["ar", "en"],
            "locales": [
              { "locale": "ar", "path": "/products/winter-blanket", "lastSignificantModifiedAt": "2026-10-16T00:00:00.000000Z" },
              { "locale": "en", "path": "/products/winter-blanket", "lastSignificantModifiedAt": "2026-10-16T00:00:00.000000Z" }
            ]
          }
        ],
        "nextCursor": "eyJ2IjoxLCJ0eXBlIjoicHJvZHVjdCIsImlkIjoyMDB9"
      }
    }

Continue until data.nextCursor is null, including when an intermediate page has an empty items array. To request the next page:

    GET {{baseUrl}}/api/v1/seo/sitemap-items?type=product&limit=100&cursor={{nextCursor}}

Each path is locale-free and has no domain. The frontend builds the final URL by adding the locale prefix and domain once. This endpoint supplies eligible items; it does not generate or publish XML and does not report Google indexing. There is no stable snapshot while the catalog changes. If any page fails, do not publish a partial sitemap as complete. When indexing is disabled, a successful empty result is valid; a read failure must remain an HTTP error, not an empty success.

## 6. Redirect resolution and management

### Public resolver

**GET** {{baseUrl}}/api/v1/seo/redirects/resolve?path=... — public.

    GET {{baseUrl}}/api/v1/seo/redirects/resolve?path=%2Far%2Fproducts%2Fold-slug
    Accept: application/json
    Accept-Language: ar

When a redirect exists:

    { "data": { "destinationPath": "/ar/products/new-slug", "statusCode": 301 } }

When there is no redirect:

    { "data": null }

The path parameter is required and must be a safe internal path. Absolute URLs, admin/login/checkout paths, and unsupported query parameters return 422. The API does not redirect the visitor. Next.js performs the 301/308 on the storefront domain after validating the result.

### Admin redirect CRUD

All routes below require an admin token and admin.access authorization.

| Method | URL | Purpose |
|---|---|---|
| GET | {{baseUrl}}/api/v1/admin/redirects?page=1&per_page=20 | Paginated list; per_page max 100 |
| POST | {{baseUrl}}/api/v1/admin/redirects | Create a redirect |
| PATCH | {{baseUrl}}/api/v1/admin/redirects/{{redirectId}} | Partially update destination/status |
| DELETE | {{baseUrl}}/api/v1/admin/redirects/{{redirectId}} | Deactivate while retaining history |

POST body:

    {
      "sourcePath": "/ar/products/old-slug",
      "destinationPath": "/ar/products/new-slug",
      "statusCode": 301
    }

PATCH body:

    {
      "destinationPath": "/ar/products/current-slug",
      "statusCode": 308,
      "isActive": true
    }

Only internal paths and status codes 301 or 308 are accepted. The destination must be public. Duplicate sources, live-path conflicts, or invalid paths produce validation/conflict responses (typically 422). Do not use this CRUD endpoint to change a product or category slug; the entity update flow records redirect history transactionally.

## 7. SEO administration and audit

### SEO records

    GET {{baseUrl}}/api/v1/admin/seo/product/{{productId}}
    PUT {{baseUrl}}/api/v1/admin/seo/product/{{productId}}
    GET {{baseUrl}}/api/v1/admin/seo/category/{{categoryId}}
    PUT {{baseUrl}}/api/v1/admin/seo/category/{{categoryId}}

These routes are protected. Use the backend's fixed aliases, including content_page for content pages; do not send model class names or arbitrary types. Example localized update:

    {
      "locale": "ar",
      "metaTitle": "عنوان مخصص",
      "metaDescription": "وصف مخصص",
      "robots": { "index": false, "follow": true },
      "openGraph": { "title": null }
    }

PUT is partial: omitted fields remain unchanged, and null clears a supported override. A custom canonical must match the entity's allowed path. Admin GET returns stored SEO records; public entity GET returns resolved metadata after fallback.

### SEO history and audit report

| Method | URL | Notes |
|---|---|---|
| GET | {{baseUrl}}/api/v1/admin/seo/history?page=1&per_page=20 | Protected, paginated history |
| GET | {{baseUrl}}/api/v1/admin/seo/audit | Protected, read-only, no body |

The report contains database and eligibility indicators. It is not Google indexing data and does not prove how many URLs have been published in XML. Sitemap publication status and the last XML-generation error are unavailable unless a trusted operational source is added.

## 8. Current product catalog contract

**GET** {{baseUrl}}/api/v1/products — public.

    GET {{baseUrl}}/api/v1/products?q=blanket&category=bedding&page=1&per_page=24
    Accept: application/json
    Accept-Language: en

Supported parameters are q, category, page, and per_page (1–100; default 24). Search and category filters are applied before pagination. Ordering is published_at DESC, then id DESC. Price range, color, offer, collection, price_asc, price_desc, and popular are not currently supported. Do not filter one already-paginated response and present it as the full result set, or assume an ignored query parameter is supported.

## 9. Common errors

| HTTP status | Meaning |
|---:|---|
| 401 | Admin request without a valid login/token |
| 403 | Authenticated user lacks admin.access |
| 404 | Entity/page is missing or not public for that endpoint |
| 422 | Invalid input, alias, query, or path |
| 5xx | Server/read failure; never convert a Sitemap failure into an empty success |

Special case: no redirect match returns 200 with data: null.

## 10. Postman collection smoke-test checklist

1. Call GET /seo/settings with ar and en; inspect Content-Language, Vary, and locale-free canonical paths.
2. Call product, category, and content details in both languages; inspect resolved SEO, availableLocales, and indexable.
3. Call /store/structured-data and confirm missing values remain null rather than being invented.
4. Request every Sitemap type and follow the cursor to null, including an empty page that still has a cursor.
5. Test the redirect resolver with a matching path and a path with no redirect; do not redirect when data is null.
6. Test an admin GET as guest (401), as a signed-in non-admin where available (403), and as an admin (success).
7. Perform PUT requests only in a test environment; verify read-after-write, locale independence, and omitted versus null behavior.
8. Do not test delivery to the Next.js revalidation receiver through this collection. Revalidation is an outbound Laravel request and SEO_REVALIDATION_ENABLED remains false until both sides, the worker, and scheduler are ready.

## 11. Frontend and operations responsibilities

- The frontend adds the domain and locale prefix to site-relative paths exactly once.
- The frontend renders JSON-LD, generates and publishes XML, and preserves the last successful sitemap if generation fails.
- The frontend performs redirects on its own domain and implements the signed revalidation receiver.
- Price/color/offer filters and popular/collection sorting are not part of the current product-list endpoint.
- Operations must configure the receiver URL and secret, a persistent queue worker, and the scheduler every minute before enabling revalidation. Keep SEO_REVALIDATION_ENABLED=false until the integration is ready.
- Passing backend tests does not prove that staging or production contains the same version or that Postman has reached it. Record the environment and backend version during integration acceptance.
