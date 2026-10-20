# Admin SEO Audit: Frontend Integration Guide

This guide describes the current backend response for the admin SEO audit screen.

## Request

```http
GET /api/v1/admin/seo/audit?page=1&per_page=20
Authorization: Bearer <admin-sanctum-token>
Accept: application/json
```

The endpoint requires an authenticated admin. `page` defaults to `1`. `per_page` defaults to `20` and accepts values from `1` to `100`.

## Response

The response keeps the original four summary values and adds a paginated `findings` object under the same `data` object:

```json
{
  "data": {
    "total": 8,
    "missing_title": 1,
    "missing_description": 0,
    "non_indexable": 1,
    "findings": {
      "data": [
        {
          "record_type": "product",
          "record_id": "17",
          "display_name": "Linen shirt",
          "slug": "linen-shirt",
          "locale": "en",
          "path": "/en/products/linen-shirt",
          "canonical_path": "/products/linen-shirt",
          "issues": [
            {
              "code": "missing_custom_title",
              "field": "meta_title",
              "fallback_available": true
            },
            {
              "code": "not_sitemap_eligible",
              "reason": "robots_noindex"
            }
          ]
        }
      ],
      "links": {
        "first": "<request-origin>/api/v1/admin/seo/audit?page=1&per_page=20",
        "last": "<request-origin>/api/v1/admin/seo/audit?page=1&per_page=20",
        "prev": null,
        "next": null
      },
      "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 1,
        "per_page": 20,
        "to": 1,
        "total": 1
      }
    }
  }
}
```

The values above illustrate the shape; the backend returns actual catalog data. `data.total` counts every public page-locale pair in the audit scope. `data.findings.meta.total` counts only page-locale pairs with at least one issue, across the full scope and not just the current findings page. These two `total` values count different things. The other summary counts also cover the full scope.

## Summary fields

| Field | Meaning |
|---|---|
| `total` | Number of public page-locale pairs in scope. |
| `missing_title` | Number of pairs without a usable custom SEO title. A fallback title may still be available. |
| `missing_description` | Number of pairs without a resolved description after custom SEO, localized content, and configured fallback descriptions. |
| `non_indexable` | Number of pairs not eligible for the current sitemap under the application's visibility, locale, robots, canonical path, redirect, and indexing rules. This does **not** report Google indexing results. |

The scope is visible products, visible categories, and configured public content pages for supported locales with a non-empty localized name/title. Findings are limited to pairs that have at least one issue. A clean pair is not included in `findings.data`, even though it is included in `total`.

## Finding fields

| Field | Meaning |
|---|---|
| `record_type` | `product`, `category`, or `content_page`. |
| `record_id` | Record ID serialized as a string. IDs can overlap between record types, so use both `record_type` and `record_id` as the record identity. |
| `display_name` | Name/title for this locale, using the backend's existing localized fallback. |
| `slug` | Product, category, or content-page slug. |
| `locale` | `ar` or `en`. |
| `path` | Locale-prefixed public path when the backend can resolve one. |
| `canonical_path` | Resolved canonical path without the locale prefix, or `null` when unavailable. |
| `issues` | One or more issue objects. A page-locale pair appears once, with all its applicable issues in this array. |

## Issue codes

| Code | Relevant fields | Frontend interpretation |
|---|---|---|
| `missing_custom_title` | `field: "meta_title"`, `fallback_available: boolean` | No usable custom SEO title is set. This remains an issue even when `fallback_available` is `true`; label it as a missing custom title, not a missing rendered title. |
| `missing_resolved_description` | `field: "meta_description"` | No description resolves from the custom field, localized product/category description or page body, or configured default description. |
| `not_sitemap_eligible` | `reason` | The page is excluded from the application's current sitemap eligibility. This is not a search-engine indexing report. |

Current `not_sitemap_eligible.reason` values:

- `indexing_disabled`: the application-wide SEO indexing flag is off.
- `robots_noindex`: the resolved robots policy disallows indexing for the page.
- `canonical_path_unavailable`: no supported canonical path is available for the page.
- `redirect_source_conflict`: the locale-prefixed page path conflicts with a redirect source under the current redirect lookup rules.

## Frontend usage notes

- Render the four summary values from `data` and render rows from `data.findings.data`.
- Use `data.findings.meta.total` for the findings result count and `data.findings.links.next` / `prev` or the `current_page` and `last_page` metadata for paging.
- Use `{record_type, record_id, locale}` as the unique key for a finding.
- Treat issue `code` and `reason` as stable machine-readable values; show friendly text in the UI rather than exposing these codes directly.
- Use `path` to link to the affected localized page. Treat `canonical_path` as audit data only; this endpoint does not provide the Arabic and English alternate paths needed to build `hreflang` links. A `null` path means the backend could not resolve it.
- This endpoint is read-only. SEO change history, including `seo_setting` events, is returned separately by `GET /api/v1/admin/seo/history` and is not part of findings.
