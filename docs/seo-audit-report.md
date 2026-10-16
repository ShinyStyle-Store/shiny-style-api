# SEO audit report API

## Endpoint

`GET /api/v1/admin/seo/audit` uses the existing `auth:sanctum` and `admin.access` middleware. The response is read-only and wrapped in `data`. The report does not write SEO audits, cache entries, or content records.

## Response contract

```json
{
  "data": {
    "scope": {
      "products": "Effectively public products with at least one supported, non-empty localized name.",
      "indexEligibility": "Current Laravel visibility, locale, robots, canonical, redirect, and SEO indexing rules.",
      "seoImages": "Public product, category, and configured content-page locale records; counts invalid non-null custom image overrides.",
      "activeRedirects": "All active SEO redirect rows across domain scopes.",
      "descriptionCriteria": "The final public description after custom, localized content, then localized default fallback; empty after HTML and whitespace cleanup is missing. No minimum length is applied."
    },
    "products": {
      "indexEligible": {
        "unit": "distinct products and product-locale pages",
        "distinctProducts": 42,
        "byLocale": { "ar": 40, "en": 35 },
        "totalProductLocalePages": 75,
        "indexingAllowed": true
      },
      "customSeoTitleByLocale": { "ar": 12, "en": 8 },
      "missingUsefulDescriptionByLocale": { "ar": 3, "en": 5 }
    },
    "seoImages": {
      "invalidCustomOverrides": {
        "byLocale": { "ar": 2, "en": 1 },
        "byEntityType": {
          "products": { "ar": 1, "en": 0 },
          "categories": { "ar": 1, "en": 0 },
          "pages": { "ar": 0, "en": 1 }
        },
        "totalPageLocaleOverrides": 3,
        "unconfiguredOverrideIsNotAnError": true
      }
    },
    "sitemap": {
      "eligibleUrlsByType": {
        "product": { "ar": 40, "en": 35, "total": 75, "distinctEntities": 42 },
        "category": { "ar": 12, "en": 10, "total": 22, "distinctEntities": 12 },
        "page": { "ar": 4, "en": 3, "total": 7, "distinctEntities": 4 }
      },
      "eligibleUrlsByLocale": { "ar": 56, "en": 48 },
      "totalEligibleUrls": 104,
      "xmlPublicationStatus": null,
      "lastGenerationError": null,
      "publicationStatusAvailable": false,
      "countsAreGoogleIndexingMetrics": false
    },
    "activeRedirects": 9
  }
}
```

Numbers above illustrate the response shape only.

## Metric definitions and sources

- `products.indexEligible` is derived from `SeoSitemapItemsService`, which reuses current public visibility, locale availability, robots, canonical, redirect collision, and global `SEO_INDEXING_ALLOWED` rules. `distinctProducts` counts a product once even when both locales qualify. `byLocale` and `totalProductLocalePages` count locale-specific pages, so those units are explicit and not added to the distinct product count.
- `customSeoTitleByLocale` counts public products with an available locale and a non-empty custom `meta_title` after the same public text cleanup used by `SeoMetadataService`. It does not count generated fallback titles.
- `missingUsefulDescriptionByLocale` counts public product-locale pages where the final description is empty after the existing precedence: custom meta description, localized product description with the current content-language fallback, then same-locale `default_meta_description`. Product descriptions are cleaned and capped at the existing 160 character metadata limit. “Missing” means empty after HTML stripping, entity decoding, and whitespace cleanup; no arbitrary minimum length is imposed. These two content-quality metrics concern public localized pages, including `noindex` pages, and are separate from the eligible sitemap counts.
- `seoImages.invalidCustomOverrides` counts only non-null explicit `og_image_id` overrides on public localized products, visible categories, and configured content pages where the linked image fails `SeoPublicImageService` validation (missing/deleted record, non-image, non-public disk, or invalid/private URL). It is grouped by entity type and locale. An unset override is not counted; the metadata fallback remains responsible for choosing the default OG/content image. This metric does not claim that every possible fallback image was rendered or validated.
- `activeRedirects` counts active rows in `seo_redirects` across all `domain_scope` values.
- `sitemap` counts locale URLs currently eligible through the existing Sitemap item service. It is not the number of URLs present in generated XML, published to a host, submitted to a search engine, or indexed by Google. The application has no authoritative XML publication status or last generation error source, so both are `null` and `publicationStatusAvailable` is `false`.

## Operational and consistency limits

The report performs a bounded/chunked walk over public entities and the current Sitemap cursor pages; it does not load the product catalog into memory or add a cache. Total query work grows with the public catalog. Counts are a live read and may span concurrent content changes; they are not a database snapshot. The application currently does not track Sitemap XML publication or generation failures.
