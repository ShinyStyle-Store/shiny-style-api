# SEO frontend integration contract

Reviewed against the Laravel source and feature tests on 16 October 2026. API prefix: `/api/v1`. This document describes the checked-in implementation; it does not certify a deployment or live storefront. The October 16 handoff is reflected where present in `docs/seo-revalidation-laravel.md` and related SEO notes. The referenced PDF at `F:\Downloads\SEO Architecture & Implementation Specification.pdf` is outside this workspace and was not available for direct verification; no unverified PDF requirement is represented as implemented behavior.

## Conventions

- API locale middleware accepts `Accept-Language: ar` or `en`; absent/unsupported values resolve to Arabic. Localized responses include `Content-Language` and `Vary: Accept-Language`.
- Ordinary resource responses use `{ "data": ... }`. Laravel validation errors use HTTP 422 and `{ "message": "The given data was invalid.", "errors": { "field": ["..."] } }`. Authenticated admin APIs return 401 when unauthenticated and 403 when authenticated without `admin.access`. Missing entities return 404.
- No endpoint-specific `Cache-Control` contract is established here. Cache headers/CDN behavior must be configured by the consuming runtime. Redirect resolver's internal lookup cache is not an HTTP cache policy.
- Status labels describe implementation and repository test coverage. **Implemented and tested** means a matching feature test exists; **Implemented but untested** means no direct feature test was located; **Planned** means no implementation. Actual result for this documentation revision: PHP is unavailable in the shell, so the referenced test suites could not be run here. Test names below are exact class/method identifiers where listed; their presence is not reported as a fresh passing run.

## 1. Admin SEO records

### GET and PUT `/api/v1/admin/seo/{type}/{id}`

**Status:** Implemented and tested. `GET` returns a resource collection; `PUT` updates/creates one locale record. Auth: Sanctum token plus `admin.access`. Headers: `Accept: application/json`; PUT requires JSON (`Content-Type: application/json`). Locale is required in each PUT body; `Accept-Language` does not select the record locale. Supported `type` aliases are `product`, `category`, `content_page`, and `page` (both page aliases target content pages). Numeric `id` is the stable entity ID. The PHP class name alias is not supported. No query parameters or explicit HTTP cache policy.

GET request example:

```http
GET /api/v1/admin/seo/product/42
Authorization: Bearer <admin-token>
Accept: application/json
```

```json
{"data":[{"locale":"ar","metaTitle":"عنوان المنتج","metaDescription":null,"canonicalPath":"/products/example","robots":{"index":true,"follow":true},"openGraph":{"title":null,"description":null,"imageId":null}}]}
```

The collection can be empty: `{ "data": [] }`. Records are returned by locale; each has exactly `locale`, `metaTitle`, `metaDescription`, `canonicalPath`, `robots.index`, `robots.follow`, `openGraph.title`, `openGraph.description`, and `openGraph.imageId`.

PUT accepts `locale` (`ar|en`) and at least one of `metaTitle`, `metaDescription`, `canonicalPath`, `robots`, or `openGraph`. Allowed nested keys are `robots.index|follow` and `openGraph.title|description|imageId`. Text fields can be nullable; title max 180 chars, description max 5000, canonical path max 2048. Blank title/description is invalid; send `null` to clear. `imageId` must identify an available public image asset. Unknown keys are rejected. Omitted fields are unchanged; supplied null clears nullable values; booleans including `false` are saved as values. Nested objects are partial too: sending only `robots.index` leaves `robots.follow` unchanged.

```json
{"locale":"en","metaTitle":"English title","robots":{"index":false},"openGraph":{"imageId":null}}
```

Response is the updated single record in `{ "data": { ...same shape as GET row... } }`; clearing the last persisted field may yield `{ "data": null }`. Errors: 422 validation; 401/403 admin access; 404 unknown target. No query parameters are defined. Arabic and English records are independent, and locale-specific GET/PUT should be used to edit each.

Tests: `tests/Feature/Api/AdminSeoApiTest::test_record_updates_are_partial_locale_specific_and_audited_only_when_changed`, `::test_clearing_last_custom_value_deletes_the_row_and_preserves_history`, `::test_targets_use_fixed_aliases_and_canonical_paths_must_match_entity`, and `::test_seo_admin_routes_require_an_admin_token_and_history_is_bounded`. Result this revision: **not run** (`php` unavailable).

## 2. Admin SEO settings

### GET and PUT `/api/v1/admin/seo/settings`

**Status:** Implemented and tested. Auth: Sanctum plus `admin.access`. GET has no parameters and returns all stored rows as `{ "data": [{"key":"...","locale":"ar|en|null","value":...}] }`. PUT body must contain `settings`, a list of 1–50 `{key, locale?, value}` objects; each object allows only those properties. The response is the resource collection of stored settings, same row shape. `Accept: application/json`; PUT `Content-Type: application/json`.

Supported API keys and types:

| Key | Scope | Value |
|---|---|---|
| `storeName` | localized | nonblank string ≤255 |
| `defaultSeoTitle`, `homeSeoTitle`, `shopSeoTitle`, `contactSeoTitle` | localized | nonblank title ≤180 |
| `defaultMetaDescription`, `homeMetaDescription`, `shopMetaDescription`, `contactMetaDescription` | localized | nonblank description ≤5000 |
| `defaultOgImageId`, `storeLogoId`, `homeOgImageId`, `shopOgImageId`, `contactOgImageId` | global | positive integer ID of available public image |
| `canonicalBaseUrl` | global | HTTPS origin only; no credentials, path (except `/`), query or fragment |
| `supportedLocales` | global | nonempty unique list, each `ar` or `en` |
| `currency` | global | three uppercase letters (`^[A-Z]{3}$`) |
| `defaultRobotsPolicy`, `homeRobotsPolicy`, `shopRobotsPolicy`, `contactRobotsPolicy` | global | object containing both `index` and `follow`, each boolean or null |

Localized keys require explicit `locale: "ar"` or `"en"`. Global keys require locale omitted, null, or empty string. Example with Arabic, English and global settings:

```json
{"settings":[{"key":"homeSeoTitle","locale":"ar","value":"الرئيسية"},{"key":"homeSeoTitle","locale":"en","value":"Home"},{"key":"homeRobotsPolicy","value":{"index":false,"follow":true}}]}
```

`value` is required. `null` is accepted by request validation and means clearing/deleting that setting; it is not a stored false. Omission of a setting object leaves it unchanged. `false` inside a robots policy is explicit and is preserved; a robots policy object must include both `index` and `follow`, each boolean or null. Duplicate key+locale entries, unsupported keys/locales, invalid types, blank strings, unavailable images, and unknown properties return 422. GET when none exist is `{ "data": [] }`. Auth errors: 401/403. There is no explicit HTTP cache policy.

No-op updates do not create audit entries. Actual value changes create audit snapshots; clearing an existing value is audited, while clearing an absent value is a no-op. Settings response contains only stored settings (not synthesized defaults).

Tests: `tests/Feature/Api/AdminSeoApiTest::test_settings_are_fixed_typed_partial_and_global_values_have_one_row`, `::test_seo_admin_routes_require_an_admin_token_and_history_is_bounded`, `tests/Feature/Api/PublicSeoSettingsApiTest::test_admin_page_overrides_round_trip_and_null_clears_back_to_global_fallback`, `::test_public_reads_do_not_write_settings_or_audits_and_noop_admin_updates_do_not_revalidate`, and `tests/Feature/Services/SeoRevalidationQueueTest`. Result this revision: **not run** (`php` unavailable).

### Admin SEO audit and history

`GET /api/v1/admin/seo/audit` requires the same Sanctum plus `admin.access` authorization as the other admin SEO endpoints. It preserves the four integer summary counts and adds a Laravel paginated `findings` object inside `data`. `page` defaults to 1; `per_page` defaults to 20 and accepts 1–100.

```json
{"data":{"total":8,"missing_title":7,"missing_description":2,"non_indexable":1,"findings":{"data":[{"record_type":"product","record_id":"17","display_name":"Linen shirt","slug":"linen-shirt","locale":"en","path":"/en/products/linen-shirt","canonical_path":"/products/linen-shirt","issues":[{"code":"missing_custom_title","field":"meta_title","fallback_available":true},{"code":"not_sitemap_eligible","reason":"robots_noindex"}]}],"links":{"first":"<request-origin>/api/v1/admin/seo/audit?page=1&per_page=20","last":"<request-origin>/api/v1/admin/seo/audit?page=1&per_page=20","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"per_page":20,"to":1,"total":1}}}}
```

The counting unit is a public page-locale pair: effectively visible products, visible categories, and existing configured content pages, for each supported locale with a non-empty localized product/category name or page title. `total` counts all such pairs, whether indexable or not. Summary counts cover the full scope, regardless of the current findings page. In keeping with the existing audit's `customSeoTitleByLocale` diagnostic, `missing_title` counts pairs without a usable entity-specific SEO title override, even when a fallback title is available. Findings label this as `missing_custom_title`; `fallback_available` tells the client whether public title resolution currently produces a fallback. `missing_description` counts pairs whose final public description is empty after custom SEO, localized content (including the existing alternate-language fallback), and localized default-description fallback. `non_indexable` counts pairs in this same scope that are not eligible for the current sitemap. These are application sitemap-eligibility results, not Google indexing metrics.

Each finding is one page-locale pair and is included only when it has at least one issue. `record_type` is `product`, `category`, or `content_page`; `record_id` is a string; `display_name` uses the locale's existing public text fallback; `slug` is the entity slug; `path` is locale-prefixed and `canonical_path` is the resolved locale-independent canonical path (either may be null when no path is available). Issue codes are `missing_custom_title` (`field: meta_title`), `missing_resolved_description` (`field: meta_description`), and `not_sitemap_eligible`. The last code includes a reason: `indexing_disabled`, `robots_noindex`, `canonical_path_unavailable`, or `redirect_source_conflict`. Findings and counts use the same visible record scope and supported localized pages. No `seo_setting` change-history rows are included; those remain on `/api/v1/admin/seo/history`. The endpoint only reads data.

`GET /api/v1/admin/seo/history` keeps the existing descending `created_at`, then descending `id` ordering and Laravel pagination. It accepts only `page` (minimum 1) and `per_page` (1–100; default 20). Each row contains only:

```json
{"id":"42","record_type":"product","record_id":"17","locale":"ar","changed_at":"2026-10-18T04:00:00.000000Z","changed_by":"Admin display name"}
```

`id` is the stable audit-row primary key; `record_id` is the stable ID of the existing audit target. `record_type` uses the stored supported audit target types (`product`, `category`, `content_page`, `seo_setting`, `seo_redirect`). `locale` is always `ar` or `en`. Locale-specific record, setting, and redirect changes retain their locale. Shared/global changes use `ar` as the documented primary-locale label; it does not mean the change affects Arabic only. The locale migration recovers locale from stored setting snapshots and localized redirect paths where possible. Shared changes and legacy snapshots without recoverable locale use the same `ar` primary label. New audit writes always persist a valid locale, defaulting to `ar` for shared/unscoped changes. `changed_at` is the audit creation timestamp in ISO-8601 UTC form. `changed_by` uses the linked admin's display name only; it is null if the actor record is no longer available. Audit snapshots and admin contact details are not returned.

Both endpoints retain the existing authentication/authorization boundary. Audit history remains paginated as `{ "data": [...], "links": ..., "meta": ... }`. Focused coverage is in `tests/Feature/Api/AdminSeoAuditApiTest` and `tests/Feature/Api/AdminSeoApiTest`.

### Global store Open Graph image

`defaultOgImageId` is an existing global setting. Set it through the same admin endpoint; its value must be the numeric ID of an existing, non-deleted `MediaAsset` whose image URL passes the shared public-image checks. It does not accept a locale. Omit `locale` and send a JSON number, not a URL or filename:

```http
PUT /api/v1/admin/seo/settings
Authorization: Bearer <admin-token>
Accept: application/json
Content-Type: application/json
```

```json
{"settings":[{"key":"defaultOgImageId","value":123}]}
```

`123` is a placeholder: replace it with a real media asset ID. Sending `"value": null` clears this setting; omitting the setting from a later PUT leaves it unchanged. Invalid, deleted, non-image, or non-public/unavailable media is rejected with 422 using the standard Laravel validation response. Admin authentication failures are 401/403. The write follows the settings audit/no-op behavior above.

The public endpoint remains `GET /api/v1/seo/settings`, with optional `Accept-Language: ar|en`. The setting is global, so the same image is used for either locale. For each fixed page, its page-specific image setting (`homeOgImageId`, `shopOgImageId`, or `contactOgImageId`) takes precedence; `defaultOgImageId` is the fallback. A usable image is returned as the resolved URL in that page's existing `data.pages.{home|shop|contact}.openGraph.image` field. If neither page nor default image is usable, the field is JSON `null`; the GET does not write to the database. Example result when only the global image is configured:

```json
{"data":{"locale":"en","pages":{"home":{"openGraph":{"image":"https://cdn.example.test/media/store-og.webp"}},"shop":{"openGraph":{"image":"https://cdn.example.test/media/store-og.webp"}},"contact":{"openGraph":{"image":"https://cdn.example.test/media/store-og.webp"}}}}}
```

The complete page objects retain all fields documented in the public global SEO section; the abbreviated JSON above only highlights the image paths. Arabic has the same response shape and uses the same global image URL.

### Upload the global store image: POST `/api/v1/admin/seo/default-og-image`

**Status:** Implemented but not currently verified. Feature coverage is in `tests/Feature/Api/StoreOgImageApiTest`. Requires a Sanctum bearer token and `admin.access`. Send `multipart/form-data` with exactly one file field named `image`; there are no query parameters. The Laravel `MediaService` applies configured size, MIME, extension, and image-content checks. The resulting storage disk must also produce a publicly usable URL or the upload is rejected and the newly created unreferenced asset is cleaned up.

```http
POST /api/v1/admin/seo/default-og-image
Authorization: Bearer <admin-token>
Accept: application/json
Content-Type: multipart/form-data
```

```text
image: <image file>
```

Success uses HTTP 201, following existing media upload endpoints:

```json
{"data":{"id":123,"url":"https://cdn.example.test/media/images/2026/10/example.webp"}}
```

`id` is the numeric `MediaAsset` ID, and `url` is the URL generated by the configured storage disk. The endpoint stores that ID as global `defaultOgImageId` (no locale), returns no credentials or storage internals, and does not attach the image to any product or variant. Supported content types/extensions and the size limit come from the current Media configuration; the current size limit is 5 MiB. Non-image/unsupported/oversized or non-publicly available uploads return HTTP 422 with Laravel's `{ "message": ..., "errors": { "image": [...] } }` validation shape. Missing admin credentials return 401; authenticated users without active admin permission return 403. Storage/recording failures use the application's normal exception handling; no success response is returned. Replacing the setting keeps the old Media asset; it is not deleted automatically.

Clear the selection with the existing partial settings update:

```http
PUT /api/v1/admin/seo/settings
Authorization: Bearer <admin-token>
Content-Type: application/json
```

```json
{"settings":[{"key":"defaultOgImageId","value":null}]}
```

This clears the setting and does not delete the Media file. Subsequent public settings GET responses return `openGraph.image: null` wherever no usable page-specific image or global fallback exists. `storeLogoId` is a separate setting and is not used as a substitute. The global image is the same for Arabic and English.

Frontend flow: **Upload image → receive Media ID → save `defaultOgImageId` (the upload endpoint already saves it) → read public SEO settings → use `openGraph.image` in page metadata.** The frontend uploads only to this authenticated admin endpoint; no public upload endpoint exists. The returned ID can also be used by the admin UI as the selected media ID. Do not upload a product image automatically or pass a product ID/attachment ID instead of a Media ID.

Coverage: `tests/Feature/Api/StoreOgImageApiTest` covers admin upload, auth, invalid/oversized/private media, replacement, compensation on settings-save failure, public localized response, clearing without deleting the file, and read-only GET behavior. Existing `tests/Feature/Api/PublicSeoSettingsApiTest::test_page_image_uses_shared_public_validation_and_falls_back_for_private_invalid_or_deleted_assets` covers page-specific image precedence and global fallback. A repeated upload is not deduplicated by the current `MediaService`; each successful upload creates a new `MediaAsset` and setting update. There is no migration for this endpoint.

## 3. Public global SEO

### GET `/api/v1/seo/settings`

**Status:** Implemented and tested. Public, no authentication. Headers: optional `Accept-Language: ar|en`; no query parameters are accepted (unknown query key gives 422). Response is exactly `{ "data": { "locale": "ar|en", "pages": { "home": ..., "shop": ..., "contact": ... } } }`. Each page contains exactly `title`, `description`, `canonicalPath`, `robots` (`index`, `follow`), `openGraph` (`title`, `description`, `image`), `indexable`, `availableLocales`, and `lastSignificantModifiedAt`. `availableLocales` defaults to `["ar","en"]` unless configured; timestamp may be null.

Canonical paths are fixed locale-free paths `/`, `/shop`, `/contact`. For title, fallback is page title setting in requested locale, then `defaultSeoTitle` in that locale, then localized `storeName`. For description, fallback is page description setting then `defaultMetaDescription` in requested locale. Content locale fallback behavior does not synthesize page settings across languages. Open Graph title/description fall back to resolved page title/description; image falls back page image then default OG image. Robots use page policy then global default policy, with indexing gate applied (`index` is forced false when indexing is disabled). Explicit false is not treated as missing.

Example shape (values shown as valid representative values; settings determine actual strings):

```json
{"data":{"locale":"en","pages":{"home":{"title":"Home","description":"Welcome","canonicalPath":"/","robots":{"index":true,"follow":true},"openGraph":{"title":"Home","description":"Welcome","image":null},"indexable":true,"availableLocales":["ar","en"],"lastSignificantModifiedAt":null},"shop":{"title":"Shop","description":null,"canonicalPath":"/shop","robots":{"index":true,"follow":true},"openGraph":{"title":"Shop","description":null,"image":null},"indexable":true,"availableLocales":["ar","en"],"lastSignificantModifiedAt":null},"contact":{"title":"Contact","description":null,"canonicalPath":"/contact","robots":{"index":true,"follow":true},"openGraph":{"title":"Contact","description":null,"image":null},"indexable":true,"availableLocales":["ar","en"],"lastSignificantModifiedAt":null}}}}
```

Arabic example is the same shape with `Accept-Language: ar` and `data.locale: "ar"`; text is resolved from Arabic settings/fallback. Empty settings still return all three pages with resolved nullable strings and policy defaults. Noindex is represented by `robots.index:false` and `indexable:false`, not by removing a page. Errors: 422 for unexpected query keys. Cache behavior: no explicit HTTP cache policy.

Test: `tests/Feature/Api/PublicSeoSettingsApiTest::test_public_endpoint_resolves_fixed_canonicals_and_localized_fallbacks_without_authentication`; `::test_missing_language_values_do_not_fall_back_across_locales_and_unknown_locale_uses_api_default`; `::test_page_image_uses_shared_public_validation_and_falls_back_for_private_invalid_or_deleted_assets`. Result this revision: **not run** (`php` unavailable).

## 4. Product and category public SEO

### GET `/api/v1/products/{slug}` and `/api/v1/categories/{slug}`

**Status:** Implemented and tested (SEO behavior). Public, no auth. `Accept-Language` selects localized presentation; fallback values can use the other language. No request body/query parameters are defined for these detail routes. Existing response envelope is `{ "data": ... }` and SEO fields are embedded in the detail resource; list responses do not add detail SEO fields.

`data.seo` exact fields: `title`, `description`, `canonicalPath`, `robots:{index,follow}`, `openGraph:{title,description,image}`, `indexable`, `availableLocales`, and `lastSignificantModifiedAt`. Nullable resolved strings/path/image/timestamp remain JSON null where the resource supplies null. `data.structuredData` for categories is `{ "breadcrumbs": [...] }`; for products it is `{ "product": {...}, "breadcrumbs": [...] }`.

Product structured product fields: `productId`, `name`, `description`, `images[]`, `canonicalPath`, `displayVariantId`, `offer`, `variants[]`, `variationAttributes[]`, `productGroup`, and `seller`. IDs are strings. `offer` is null when no display variant; otherwise it contains `sellableItemId`, `sku`, `offerId`, `offerApplied`, `price`, `priceCurrency`, `availability`. Each variant contains `id`, `sku`, `offerId`, `offerApplied`, `price`, `originalPrice`, `priceCurrency`, `availability`, `inStock`, `images[]`, `optionValues`. Prices are numeric or null; currency is configured store currency; availability is `InStock` or `OutOfStock`. SKU comes from the sellable item, never product ID. Only public current variants are represented; superseded/inactive variants are excluded. Seller is null unless a usable store name exists; when present it has `organizationId`, `name`, `url`, `logo`. Do not fabricate brand/GTIN/MPN/aggregate offers. Breadcrumb rows contain `position`, `entityType`, `id`, `name`, and `path` (values may be null where source data is unavailable).

Eligibility examples: visible published product and visible category can return 200 with `indexable:true`; setting `robots.index:false` returns 200 with `indexable:false`; zero stock alone does not hide a visible product or make it ineligible. Future/unpublished product, inactive category, or product with hidden category ancestry returns 404 regardless of robots index. A category may be empty and still return 200. Missing localized content does not by itself 404; `availableLocales` and robots/indexable reflect actual translation eligibility (for example English missing, Arabic available gives `availableLocales:["ar"]`). SEO noindex does not remove ordinary API access.

Representative English request:

```http
GET /api/v1/products/linen-shirt
Accept-Language: en
```

```json
{"data":{"seo":{"title":"Linen shirt","description":"Light linen","canonicalPath":"/products/linen-shirt","robots":{"index":true,"follow":true},"openGraph":{"title":"Linen shirt","description":"Light linen","image":null},"indexable":true,"availableLocales":["ar","en"],"lastSignificantModifiedAt":null},"structuredData":{"product":{"productId":"42","name":"Linen shirt","description":"Light linen","images":[],"canonicalPath":"/products/linen-shirt","displayVariantId":"501","offer":{"sellableItemId":"501","sku":"LINEN-M","offerId":null,"offerApplied":false,"price":125.5,"priceCurrency":"EGP","availability":"InStock"},"variants":[{"id":"501","sku":"LINEN-M","offerId":null,"offerApplied":false,"price":125.5,"originalPrice":null,"priceCurrency":"EGP","availability":"InStock","inStock":true,"images":[],"optionValues":{}}],"variationAttributes":[],"productGroup":null,"seller":null},"breadcrumbs":[]}}}
```

Arabic uses the same exact key structure and Arabic localized names/text with `Accept-Language: ar`. The configured currency controls `priceCurrency`; `EGP` above is illustrative only. Product/category not found or ineligible due to visibility returns 404. These GETs have no validation body; noindex is a normal 200 case. No HTTP cache policy is defined.

Tests: `tests/Feature/Api/SeoPublicPayloadTest::test_structured_product_excludes_superseded_variants_and_optional_unsourced_claims`, `::test_product_structured_breadcrumbs_follow_the_existing_primary_category_and_localize_names`, `::test_category_noindex_does_not_hide_it_and_category_lists_keep_their_existing_shape`, `::test_runtime_indexing_gate_blocks_index_without_changing_public_access`, and `::test_index_true_does_not_make_unpublished_products_or_hidden_category_ancestors_public`. Result this revision: **not run** (`php` unavailable).

## 5. Store structured data

### GET `/api/v1/store/structured-data`

**Status:** Implemented and tested by `tests/Feature/Api/StoreStructuredDataApiTest`; result this revision **not run** (`php` unavailable). Public, no auth or query/body parameters. Headers: optional `Accept-Language: ar|en` (`ar` default); response includes `Content-Language` and `Vary: Accept-Language`. `Accept-Language` selects `website.inLanguage` and localized store name. Response is the resource data directly under `data`:

```json
{"data":{"organization":{"organizationId":null,"name":null,"url":null,"logo":null,"contact":{"supportPhone":null,"supportWhatsapp":null,"supportEmail":null,"address":null,"googleMapsUrl":null},"sameAs":[]},"website":{"websiteId":null,"name":null,"url":null,"inLanguage":"ar","publisherId":null},"currency":"EGP","policies":{"returns":null,"shipping":null}}}
```

Exact fields: `organization` has `organizationId`, `name`, `url`, `logo`, `contact` (`supportPhone`, `supportWhatsapp`, `supportEmail`, `address`, `googleMapsUrl`), and `sameAs[]`; `website` has `websiteId`, `name`, `url`, `inLanguage`, `publisherId`; top level also has `currency` and `policies.returns|shipping`. Organization/website identifiers derive from normalized canonical origin (`/#organization`, `/#website`) and are null when unavailable. Logo is null unless the configured image resolves to a public available image. Contact is emitted with nullable values; map URL is retained only for allowed HTTPS Google Maps hosts. `sameAs` includes only enabled, valid Facebook/Instagram/TikTok URLs. Currency is configured store currency. Policy references are null if the corresponding content page/path is unavailable; otherwise `{contentPageSlug,path,url}`, with URL null when origin is unavailable. Arabic example above; English changes `inLanguage` and localized name, not keys. Invalid/unavailable source values degrade to null/empty values, not invented data. No explicit cache policy.

## 6. Sitemap items

### GET `/api/v1/seo/sitemap-items?type={product|category|page}[&limit=N][&cursor=...]`

**Status:** Implemented and tested. Public; no auth. Required query `type` is `product`, `category`, or `page`; optional `limit` integer 1–100, default 50; optional opaque cursor string max 128. Unknown query keys and malformed/type-mismatched cursors return 422. No body. Header may include `Accept-Language`, but returned locale coverage is driven by global `supportedLocales` (default `ar,en`) and per-locale eligibility rather than that header.

Response exact envelope: `{ "data": { "items": [...], "nextCursor": string|null } }`. The cursor is **`data.nextCursor`**. Each entity row is `{ "id": integer, "entityType": "product|category|page", "slug": string, "eligibleLocales": ["ar"|"en"...], "locales": [{"locale":"ar|en","path":"...","lastSignificantModifiedAt":"ISO-8601|null"}] }`. IDs increase in stable order. Each locale path is a locale-free canonical route (examples `/products/slug`, `/category/slug`, `/about`). `lastSignificantModifiedAt` is locale-specific significant content time, may be null, and GET does not write timestamps/audits. Product/category rows require public visibility; page rows require configured public page slug and translation/indexing eligibility. Noindex locale is excluded.

```http
GET /api/v1/seo/sitemap-items?type=page&limit=50
Accept-Language: en
```

```json
{"data":{"items":[{"id":7,"entityType":"page","slug":"about-us","eligibleLocales":["ar","en"],"locales":[{"locale":"ar","path":"/about","lastSignificantModifiedAt":"2026-10-16T12:00:00.000000Z"},{"locale":"en","path":"/about","lastSignificantModifiedAt":"2026-10-16T12:00:00.000000Z"}]}],"nextCursor":null}}
```

Normal exhausted/empty page returns 200, `items:[]`, `nextCursor:null`. Disabled indexing also returns exactly that empty success shape. A non-null cursor may accompany an empty `items` page if scanning reaches its bounded scan limit without finding eligible rows; clients must continue while `data.nextCursor !== null`, even for empty items. Malformed cursor or unknown query → 422. Read failure → 500 (not a fabricated empty page; error response has no `data.items`). Arabic and English appear as separate locale entries when eligible. No explicit HTTP caching.

Tests: `tests/Feature/Api/SeoSitemapItemsApiTest::test_product_items_continue_across_cursor_pages_without_missing_or_repeating_rows`, `::test_public_items_continue_past_noindex_rows_and_include_locale_specific_eligibility`, `::test_category_and_content_page_paths_use_the_existing_visibility_and_canonical_rules`, `::test_invalid_type_cursor_limit_and_unknown_parameters_return_validation_errors`, `::test_disabled_indexing_returns_an_empty_successful_page_without_writes`, `::test_sitemap_read_failure_is_not_returned_as_an_empty_successful_page`, and `::test_item_lastmod_uses_the_existing_significant_timestamp_without_writing_during_get`. Result this revision: **not run** (`php` unavailable).

## 7. Content pages

### Admin GET `/api/v1/admin/content-pages` and `/api/v1/admin/content-pages/{slug}`; public GET `/api/v1/content-pages/{slug}`

**Status:** Implemented and tested. Admin auth: Sanctum plus `admin.access`; public route unauthenticated. Admin list/detail uses stable numeric `id` in its resource, while SEO management uses `GET|PUT /api/v1/admin/seo/content_page/{id}` (alias `page` also resolves same entity). Admin requests require JSON for writes; public GET uses optional `Accept-Language`.

Public response fields are localized `title`, `body`, and `seo` (same SEO resolution fields described above); the content resource does not emit structuredData. Missing translation leaves that locale's `title`/`body` null; it does not 404 solely for translation absence. SEO resolver sets `availableLocales` to actual translations and `robots.index/indexable` false for unavailable translation. `about-us` canonical `/about`; `returns-policy` `/return-policy`; `shipping-policy` `/shipping-policy`. These paths are locale-free. English and Arabic requests use same slug and canonical mapping, with localized content where present.

```http
GET /api/v1/content-pages/about-us
Accept-Language: ar
```

```json
{"data":{"title":"من نحن","body":"<p>عن المتجر</p>","seo":{"title":"من نحن","description":null,"canonicalPath":"/about","robots":{"index":true,"follow":true},"openGraph":{"title":"من نحن","description":null,"image":null},"indexable":true,"availableLocales":["ar","en"],"lastSignificantModifiedAt":null}}}
```

Admin SEO record writes are locale-independent of the content save and have partial update semantics in section 1. Public missing slug returns 404. Admin auth errors 401/403; validation on content writes 422. No explicit cache policy.

Admin write example:

```json
{"titleAr":"من نحن","titleEn":"About us","bodyAr":"<p>عن المتجر</p>","bodyEn":"<p>About the store</p>"}
```

The corresponding admin response is the detail resource shape above, with its stable numeric ID and timestamps populated. Public English request is `GET /api/v1/content-pages/about-us` with `Accept-Language: en`; it returns the same keys and English values. Translation-missing case: title/body are JSON `null`, and SEO robots index/indexable are false; this remains 200 for a saved page.

Admin list/detail rows have this exact shape (detail is one row under `data`; list is a `data` array). Unconfigured supported pages return `id:null` and nullable translations/timestamps; saved pages return their stable numeric `id` and concrete source fields:

```json
{"data":{"id":7,"slug":"about-us","titleAr":"من نحن","titleEn":"About us","bodyAr":"<p>عن المتجر</p>","bodyEn":"<p>About the store</p>","createdAt":"2026-10-16T12:00:00.000000Z","updatedAt":"2026-10-16T12:00:00.000000Z"}}
```

Admin `PUT /api/v1/admin/content-pages/{slug}` requires all four nonblank JSON strings (`titleAr`, `titleEn`, `bodyAr`, `bodyEn`); this content write is not partial. It returns the same detail shape. Public missing translation is null and does not itself 404; the SEO resolver reports noindex/unavailable locale. Admin list/detail/save and public GET have no explicit HTTP cache policy. Admin validation is 422, auth is 401/403, unsupported slug is 404; public not-yet-saved page/unsupported slug is 404. English public example uses the same keys and `Accept-Language: en`, with English content and the same canonical path.

Tests: `tests/Feature/Api/ContentPageApiTest::test_admin_list_and_show_return_stable_unconfigured_pages`, `::test_admin_content_page_responses_expose_the_persistent_id_without_changing_slug_routes`, `::test_public_pages_are_not_available_before_first_save_and_unknown_slugs_are_not_found`, `::test_put_requires_all_non_blank_bilingual_fields_and_rejects_unknown_fields`, `tests/Feature/Api/SeoPublicPayloadTest::test_content_page_seo_reports_actual_translation_and_confirmed_canonical`, `::test_content_page_canonical_mapping_is_locale_free_and_reads_do_not_write`, and `tests/Feature/Api/AdminSeoApiTest::test_content_page_seo_is_locale_specific_and_independent_from_content_saves`. Result this revision: **not run** (`php` unavailable).

## 8. Redirects

### Public GET `/api/v1/seo/redirects/resolve?path={normalized-internal-path}`

**Status:** Implemented and tested; result this revision **not run** (`php` unavailable). Public, no auth. Required query `path` max 2048; no body; unknown query keys rejected. Normalized internal paths support locale-prefixed product/category paths and mapped content paths; `/ar/...` and `/en/...` locale prefixes are preserved in the resolved destination. A successful match returns `{ "data": {"destinationPath":"/en/products/new-slug","statusCode":301} }` (configured code is 301 or 308); no match is `{ "data": null }`. This is a resolver result, not an HTTP redirect response. Old product/category slugs resolve when a stored redirect exists and the destination route remains public. A destination with `robots.index:false` can still resolve: noindex affects indexing, not public visibility. Hidden/unavailable destination, unsupported path, no stored match, or unsafe redirect chain returns no match; malformed/unsupported request path returns 422. Internal positive and negative lookup cache is 600 seconds and rechecks source/destination public state and chain safety. No HTTP cache policy.

```http
GET /api/v1/seo/redirects/resolve?path=%2Far%2Fproducts%2Fold-slug
```

```json
{"data":{"destinationPath":"/ar/products/new-slug","statusCode":301}}
```

English equivalent uses `/en/...`. Tests: `tests/Feature/Api/SeoRedirectApiTest`, including `test_public_category_slug_changes_create_locale_redirects_flatten_history_and_revalidate_paths`, noindex/hidden destination cases, and resolver validation/no-match cases.

### Admin redirect management

**Status:** Implemented and tested; result this revision **not run** (`php` unavailable). All routes require Sanctum plus `admin.access` and `Accept: application/json`; writes use JSON. `GET /api/v1/admin/redirects` accepts only `page` ≥1 and `per_page` 1–100 (default 20), and returns the standard Laravel `{data,links,meta}` resource page; empty list is `data:[]`. `POST /api/v1/admin/redirects` body is `{"sourcePath":"/en/products/old-slug","destinationPath":"/en/products/new-slug","statusCode":301}`; it returns 201 and a row. `PATCH /api/v1/admin/redirects/{redirect}` accepts one or more of `destinationPath`, `statusCode` (301/308), `isActive` (boolean), rejects empty/unknown body, and returns the updated row. `DELETE` deactivates and returns the row (it does not physically remove it). Exact resource row:

```json
{"data":{"id":"12","domainScope":"<configured-domain-scope>","sourcePath":"/en/products/old-slug","sourceHash":"<computed-source-hash>","destinationPath":"/en/products/new-slug","statusCode":301,"isActive":true,"reason":"manual","entityType":null,"entityId":null,"createdBy":4,"createdAt":"2026-10-16T12:00:00.000000Z","updatedAt":"2026-10-16T12:00:00.000000Z"}}
```

`domainScope` is the canonical domain scope configured by SEO settings, not a guessed production URL. `sourceHash` is computed from source path. Generated slug redirects may have entity/reason values; manual rows use the source/snapshot fields above. Arabic paths use `/ar/...` and keep that locale through resolution; English uses `/en/...`. Empty list is normal, no-match public resolver is `{ "data": null }`, validation/conflict is 422, missing redirect is 404, auth failure is 401/403. Redirect management has no explicit HTTP cache policy; resolver lookup cache is described above. Tests: `tests/Feature/Api/SeoRedirectApiTest::test_manual_redirect_api_audits_updates_disables_and_invalidates_negative_and_positive_cache_entries`, `::test_manual_redirect_rejects_live_sources_unsafe_paths_loops_and_nonpublic_destinations`, `::test_redirect_admin_requires_admin_access_and_resolver_rejects_unrecognized_path_inputs`, and `::test_redirect_admin_rejects_a_guest_request`. Result this revision: **not run** (`php` unavailable).

Manual redirect POST accepts exactly the camelCase fields `sourcePath`, `destinationPath`, and `statusCode`; snake_case and unknown fields are rejected. Paths must be normalized, locale-prefixed internal product, category, or mapped content routes. External, protocol-relative, query, fragment, escaped, unsupported, and unsafe paths are rejected. Source and destination must use the same locale and must not be identical. Only 301 and 308 are accepted. Sources that are currently live or reserved by existing redirect history are rejected; destinations must be public, and loops or chains are rejected. PATCH accepts only `destinationPath`, `statusCode`, and `isActive`; `sourcePath` cannot be changed. Destination updates must use the source locale. DELETE returns the updated inactive resource. Public resolution preserves the configured 301/308 status and exact same-locale destination, and rechecks current public visibility; inactive, unsafe, cross-locale, or unavailable destinations return no match (`{ "data": null }`).

## 9. Revalidation boundary

**Status:** Laravel producer implemented and covered by tests; this revision's tests **not run** (`php` unavailable). Laravel producers enqueue after database commit for SEO/content/product/category/redirect changes and shared setting changes. When `SEO_REVALIDATION_ENABLED=false` (the code default), producer methods return without enqueueing and the time-boundary command exits successfully without advancing the checkpoint. A queued job that runs after the flag is disabled is failed as a permanent disabled event. The time-boundary command is `seo:revalidation-time-boundaries`, registered every minute in `bootstrap/app.php`; it checkpoints after enqueue success and detects scheduled publication/offer boundaries. It requires a persistent asynchronous Laravel queue connection; queue name defaults to `seo-revalidation`.

`SendSeoRevalidation` sends the exact UTF-8 JSON body and HMAC-SHA256 signature over `timestamp + "." + body`, with `X-Shiny-SEO-Timestamp` and `X-Shiny-SEO-Signature`. It retries up to five attempts with backoff 10/60/300/900 seconds; retryable network/408/425/429/5xx and invalid acknowledgements retry, and `Retry-After` is honored. Success requires HTTP 200 plus JSON acknowledgement `version:1`, matching `eventId`, and `status:"accepted"`. Other HTTP response/configuration errors fail the job. No secret, URL, credential, or deployment value belongs in this contract. Tests: `tests/Feature/Services/SendSeoRevalidationTest`, `tests/Feature/Services/SeoRevalidationQueueTest`, `tests/Feature/Services/SeoTimeBoundaryRevalidationTest`.

A scheduled Laravel command checks time-boundary changes once per minute with checkpointing; it enqueues before advancing checkpoint. A persistent async queue worker and host scheduler are required operationally. A process gap between database commit and enqueue remains possible (no outbox/exactly-once guarantee). Tests: `tests/Feature/Services/SendSeoRevalidationTest`, `tests/Feature/Services/SeoRevalidationQueueTest`, and `tests/Feature/Services/SeoTimeBoundaryRevalidationTest`.

The Next receiver, idempotency/cache-tag mapping, runtime configuration, persistent worker/scheduler operation, and live acceptance are frontend/operations work and are not claimed implemented here. Keep `SEO_REVALIDATION_ENABLED=false` until both ends are built and accepted. No credentials, secrets, or live deployment claims are included.

English example uses the identical exact key structure with `website.inLanguage:"en"`, localized `organization.name`/`website.name`, and configured URLs/labels; other missing sources remain null/empty. Empty or invalid sources do not produce an error response or use an application URL/default OG image: nullable fields are null, `sameAs` is `[]`, and absent policy pages are null. Noindex is not applicable to this profile endpoint. No permission case and no explicit HTTP cache policy.

Tests: `tests/Feature/Api/StoreStructuredDataApiTest::test_public_store_profile_reuses_localized_identity_contact_social_links_currency_and_policy_routes`, `::test_missing_or_deleted_store_sources_return_null_without_falling_back_to_app_url_or_default_og_image`, and `::test_public_disk_accepts_a_local_url_when_its_visibility_is_explicitly_public`. Result this revision: **not run** (`php` unavailable).

## 10. Public catalog filtering and pagination

### GET `/api/v1/products`

**Status:** Implemented and tested; this revision's execution result is **not run here** because `php` is unavailable. Public, no authentication. Headers: optional `Accept-Language: ar|en` (`ar` default); localized strings fall back to the other language, and the locale middleware emits `Content-Language` and `Vary: Accept-Language`. No request body. Supported query keys are `q`, `category`, `page`, `per_page`, `sort`, `min_price`, `max_price`, `offer`, and `colors[]`; any other key is 422.

| Query | Type and behavior |
|---|---|
| `q` | Optional nullable string, max 100; all normalized terms must match across localized name/slug fields. |
| `category` | Optional nullable slug string, max 255; includes the visible category and descendants. Unknown/hidden category produces no matches. |
| `page` | Integer ≥1; default 1. |
| `per_page` | Integer 1–100; default 24. |
| `sort` | `newest` (default), `price_asc`, `price_desc`. Price sorting is by effective presented price, then published date and ID for stable ties. |
| `min_price`, `max_price` | Optional nonnegative decimal strings, up to 10 integer digits and 2 decimals; inclusive. Minimum cannot exceed maximum. |
| `offer` | Only `active`; uses enabled offer window, start inclusive and end exclusive. |
| `colors[]` | See Colors below; up to 20 distinct lowercase kebab-case codes, OR matched. |

All filters and ordering run before pagination. Pricing and the resource use the same selected display variant and the same request instant. The item price is the active offer effective price when an offer applies; otherwise the variant price. A valid unknown color code is not invalid input: it returns 200 with no items and `meta.total: 0`. A page beyond the result range is 200 with `data: []` and pagination metadata retaining the requested page. A request with no matches is also a successful empty page. Out-of-stock products remain visible if otherwise eligible.

Example request (English; Arabic uses the same query and `Accept-Language: ar`):

```http
GET /api/v1/products?colors%5B%5D=beige&colors%5B%5D=grey&sort=price_asc&per_page=24&page=1
Accept: application/json
Accept-Language: en
```

Exact item shape (values below illustrate the resource's concrete/null fields; pagination `links` URLs use the incoming request origin):

```json
{"data":[{"id":"42","slug":"linen-shirt","name":"Linen shirt","category":{"slug":"shirts","name":"Shirts"},"displayVariantId":"501","sku":"LINEN-M","price":125.5,"originalPrice":null,"offerApplied":false,"offerId":null,"discountPercentage":null,"badge":null,"inStock":true,"image":null}],"links":{"first":"<request-origin>/api/v1/products?page=1","last":"<request-origin>/api/v1/products?page=1","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"per_page":24,"to":1,"total":1}}
```

Arabic example is the same exact key shape with localized `name` and category name and `Accept-Language: ar`. `category` is null when no primary category is available; `displayVariantId`, `sku`, price fields, `offerId`, `badge`, and `image` can be null where the product has no corresponding source; `offerApplied` and `inStock` are booleans. Empty response retains the Laravel pagination envelope with `data: []` and `meta.total: 0`. Unknown color follows this empty response. Malformed, duplicate, scalar `colors`, >20 values, invalid price/sort, or unsupported query key returns 422 with Laravel `{message,errors}`. Public endpoint has no permission-error case (no auth); no explicit HTTP cache policy is established.

Tests: `tests/Feature/Api/ProductCatalogFilteringTest::test_search_filters_the_catalog_before_pagination`, `::test_price_bounds_filter_before_pagination_and_page_two_is_stable`, `::test_color_filter_uses_matching_variant_for_price_offer_sku_and_out_of_stock_state`, `::test_color_filters_use_or_semantics_before_pagination_and_unknown_codes_return_empty`, `::test_price_ascending_and_descending_use_effective_displayed_prices`, `::test_locales_keep_identical_catalog_order_and_unknown_filters_are_rejected`, and `::test_invalid_catalog_values_return_validation_errors_and_empty_filters_remain_successful`. Result in this revision: **not run** (`php` unavailable).

## 11. Colors

Colors reuse product option/value rows; no separate color entity is exposed. `product_options.code` is exactly `color`. Color value `code` is lowercase ASCII kebab-case matching `^[a-z0-9]+(?:-[a-z0-9]+)*$`; examples: `beige`, `grey`, `navy-blue`. The option value's `value_ar` and `value_en` are translated display labels; the code is the stable filter value. Reused codes must keep consistent nonblank translations across products. Color aliases such as `colors` are rejected for color options; unrelated non-color option codes remain supported.

### Admin suggestions: GET `/api/v1/admin/catalog/colors`

**Status:** Implemented and tested; this revision's execution result is **not run here** (`php` unavailable). Auth: Sanctum bearer token plus `admin.access`. Headers: `Accept: application/json`; no `Accept-Language` dependency because both labels are returned. No query/body parameters; any query key returns 422. Response is sorted `{ "data": [{"code":"...","nameAr":"...","nameEn":"..."}] }`. Suggestions include color values attached to any non-deleted product, including draft/inactive products; they are not restricted to public assignments. Empty catalog gives `{ "data": [] }`. If legacy rows have noncanonical codes, blank translations, or conflicting labels, endpoint returns 409:

```json
{"message":"Legacy color records require canonical codes and consistent translations.","conflicts":[{"code":"beige","translations":[{"nameAr":"بيج","nameEn":"Beige"},{"nameAr":"رملي","nameEn":"Sand"}]}]}
```

Normal Arabic/English response example:

```json
{"data":[{"code":"beige","nameAr":"بيج","nameEn":"Beige"},{"code":"grey","nameAr":"رمادي","nameEn":"Grey"}]}
```

Auth errors are 401/403; invalid query is 422; legacy conflict is 409. No HTTP cache policy is defined. Tests: `tests/Feature/Api/AdminProductOptionApiTest::test_admin_color_suggestions_include_nonpublic_products_and_do_not_write`, `::test_admin_color_suggestions_report_legacy_translation_conflicts`, `::test_color_codes_are_canonical_and_reused_translations_must_match`, and `::test_nested_option_and_value_routes_require_admin_authentication`; `tests/Feature/Api/AdminProductConversionApiTest::test_conversion_rejects_color_aliases_and_noncanonical_color_value_codes`. Result in this revision: **not run** (`php` unavailable).

### Public filter choices: GET `/api/v1/products/filter-options`

**Status:** Implemented and tested; this revision's execution result is **not run here** (`php` unavailable). Public, no auth. Headers: `Accept: application/json`; optional `Accept-Language: ar|en` (`ar` default). No body or supported query parameters; unknown query key returns 422. The exact response is `{ "data": { "colors": [{"code":"...","name":"..."}] } }`. It includes distinct colors assigned to active, current, non-superseded sellable variants of visible published products, excluding deleted/orphan color values and hidden category ancestry. Duplicate codes with consistent translations collapse to one row. An empty public catalog returns `{ "data": { "colors": [] } }`.

```http
GET /api/v1/products/filter-options
Accept: application/json
Accept-Language: ar
```

```json
{"data":{"colors":[{"code":"beige","name":"بيج"},{"code":"grey","name":"رمادي"}]}}
```

English request `Accept-Language: en` returns the same fields with English `name` values (for example `Beige`, `Grey`). A blank translation is treated as a legacy conflict, as are inconsistent labels/noncanonical values; endpoint returns 409 with `message` and `conflicts` instead of guessing a label. Validation error is 422. No permission error applies. No explicit HTTP cache policy. Tests: `tests/Feature/Api/ProductCatalogFilteringTest::test_public_color_filter_options_localize_labels_and_exclude_nonvisible_products`; result **not run** (`php` unavailable).

### Apply colors: GET `/api/v1/products?colors[]=beige&colors[]=grey`

This is the catalog endpoint above with the `colors[]` query parameter. Multiple colors use **OR** semantics: a product matches when any eligible current sellable item has any requested color. Filtering occurs before pagination. When a filter matches, the display variant is selected from matching variants using the existing default/in-stock/ID priority. The same selected variant supplies `displayVariantId`, `sku`, `price`, `originalPrice`, `offerApplied`, `offerId`, `discountPercentage`, `inStock` (availability), and primary image/fallback. An out-of-stock matching variant still matches and may be selected if it wins the established priority; its `inStock` is `false`. Without `colors[]`, the existing unfiltered display selection remains unchanged.

```http
GET /api/v1/products?colors%5B%5D=beige&colors%5B%5D=grey
Accept: application/json
Accept-Language: en
```

For a matching item the response uses the catalog item JSON above; for example, `displayVariantId:"501"`, `sku:"LINEN-M"`, `price:36`, `originalPrice:45`, `offerApplied:true`, and `inStock:false` all describe the same matched variant, and `image` is its primary image URL or the product's primary image fallback. A syntactically valid unknown color returns 200 with empty `data` and `meta.total:0`. Invalid code syntax, repeated exact code, scalar `colors=beige`, more than 20 values, or any unsupported parameter returns 422. Public route has no permission error and no explicit HTTP cache policy. Arabic request uses identical keys and localized catalog labels; the filter itself is code-based. Result for `ProductCatalogFilteringTest::test_color_filter_uses_matching_variant_for_price_offer_sku_and_out_of_stock_state` and `::test_color_filters_use_or_semantics_before_pagination_and_unknown_codes_return_empty`: **not run in this revision** (`php` unavailable).

`popular` and `collection` are not supported catalog parameters and return 422.
