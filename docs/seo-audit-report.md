# SEO audit API

## Endpoint

`GET /api/v1/admin/seo/audit` is protected by `auth:sanctum` and `admin.access`. It is read-only and does not write SEO settings, SEO records, or SEO change-history rows. Change history is available separately at `GET /api/v1/admin/seo/history`.

## Response

The endpoint preserves the existing summary fields and adds paginated `findings` inside `data`:

```json
{
  "data": {
    "total": 8,
    "missing_title": 7,
    "missing_description": 2,
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
            { "code": "missing_custom_title", "field": "meta_title", "fallback_available": true },
            { "code": "not_sitemap_eligible", "reason": "robots_noindex" }
          ]
        }
      ],
      "links": { "first": "<request-origin>/api/v1/admin/seo/audit?page=1&per_page=20", "last": "<request-origin>/api/v1/admin/seo/audit?page=1&per_page=20", "prev": null, "next": null },
      "meta": { "current_page": 1, "from": 1, "last_page": 1, "per_page": 20, "to": 1, "total": 1 }
    }
  }
}
```

The values illustrate the shape. `page` defaults to 1; `per_page` defaults to 20 and is limited to 1–100. Summary counts cover the full audit scope, not only the current findings page. Findings contain only page-locale pairs with one or more issues, so an otherwise complete, sitemap-eligible pair is omitted.

## Scope and issue definitions

The scope is effectively public products, visible categories, and existing configured content pages, for each supported locale with a non-empty localized product/category name or content-page title. Product visibility retains the current `Product::visible` rules; category visibility retains the hierarchy visibility snapshot.

- `total`: all in-scope page-locale pairs.
- `missing_title`: pairs without a usable custom SEO `meta_title`, even if a public fallback title resolves. The finding code is `missing_custom_title`; `fallback_available` reports whether title resolution produces a fallback.
- `missing_description`: pairs whose resolved public description is empty after custom SEO description, localized product/category description or page body, and localized default description fallbacks. The finding code is `missing_resolved_description`.
- `non_indexable`: in-scope pairs omitted from sitemap eligibility under the existing indexing, locale, robots, canonical-path, and redirect-source rules. Findings use `not_sitemap_eligible` with one reason: `indexing_disabled`, `robots_noindex`, `canonical_path_unavailable`, or `redirect_source_conflict`. These are application sitemap-eligibility results, not Google indexing metrics.

`record_type` is `product`, `category`, or `content_page`; `record_id` is a string; `display_name` follows the existing localized content fallback; `slug` is the record slug; `path` is locale-prefixed; `canonical_path` is the locale-independent resolved canonical path. A path is null if the resolver cannot provide one. Each page-locale pair appears at most once, with all applicable issues in its `issues` array.

Relevant implementation and coverage: `SeoAuditReportService::dashboard`, `SeoSitemapItemsService::localeDecision`, `SeoResolverService::sitemapLocaleDecision`, and `tests/Feature/Api/AdminSeoAuditApiTest`.
