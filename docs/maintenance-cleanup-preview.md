# Maintenance cleanup preview

`POST /api/v1/admin/maintenance/cleanup` is currently preview-only. It does not delete database records or files. Orders are intentionally not accepted. Sending `dry_run: false` is rejected until the execution design is approved.

## Postman request

**Method:** `POST`  
**URL:** `{{base_url}}/api/v1/admin/maintenance/cleanup`  
**Authorization:** Bearer Token from a fresh owner login  
**Header:** `Accept: application/json`  
**Body:** raw JSON

```json
{
  "targets": ["archived_products", "categories"],
  "dry_run": true,
  "filters": {
    "archived_products": { "ids": [{{archived_product_id}}] },
    "categories": { "ids": [{{category_id}}] }
  }
}
```

Supply one or both targets and non-empty `ids` for each target. The endpoint accepts at most 100 IDs per target. Product IDs are returned only when the product is actually soft-deleted (archived); an active or missing product is reported as a blocker. An inactive category is still a valid explicitly selected candidate.

The feature flag `ALLOW_DATA_CLEANUP` defaults to `false`; enable it through environment configuration and rebuild Laravel's config cache when changing it. A caller must have an active admin membership with role `owner` and a token carrying both `admin-access` and `maintenance.cleanup`. A token issued before a membership was promoted to owner must be replaced by logging in again. Existing owners are not inferred during migration; an operator must deliberately run `php artisan admin:promote-owner ACCOUNT_PUBLIC_ID --reason="Approved owner designation"` and type the exact 26-character public ID at the prompt. The command verifies the account is active, verified, and has an active membership, and writes a sanitized Laravel security log event with membership ID, user ID, role, and reason.

## Example response

```json
{
  "data": {
    "archived_products": [
      {
        "id": 123,
        "slug": "sample-product",
        "name_ar": "منتج تجريبي",
        "name_en": "Sample product",
        "archived_at": "2026-11-01T10:00:00.000000Z",
        "dependency_counts": {
          "variants": 1,
          "order_items": 0,
          "variant_inventory_records": 0,
          "conversion_records": 0,
          "return_inventory_transfers": 0,
          "return_receipt_items": 0,
          "product_options": 1,
          "product_option_values": 2,
          "offer_links": 1,
          "seo_records": 1,
          "seo_redirects": 0,
          "media_attachments": 2,
          "shared_media_assets": 1,
          "seo_referenced_media_assets": 0,
          "review_images": 0
        }
      }
    ],
    "categories": []
  },
  "counts": { "archived_products": 1, "categories": 0 },
  "dependency_counts": {
    "products": { "123": { "order_items": 0, "variants": 1 } },
    "categories": {}
  },
  "blockers": [],
  "fingerprint": "..."
}
```

Blocker codes include `product_linked_to_order`, `variant_inventory_not_empty`, `product_has_conversion_history`, `product_has_return_inventory_transfers`, `variant_has_return_history`, `product_has_seo_redirect_history`, `category_has_products_not_selected_for_cleanup`, `category_has_child_categories`, and missing/not-archived selection codes. The response contains no customer or payment data.

## Proposed execution design for security review

1. Bind a short-lived authenticated preview token to actor, exact filters, candidate IDs, relevant `updated_at` values, dependency counts, and a digest of the candidate state. On execution, re-resolve every candidate under row locks and reject stale state or any blocker; block the entire batch if any selected target is blocked.
2. Use one database transaction. Lock selected categories/products, variants, category pivots, offer links, relevant media assets/attachments, SEO rows, and dependency rows in a stable ID order. Recheck archive state and order, inventory, return, conversion, category-child, and category-product relationships after locks are held.
3. For a product with no surviving order or historical inventory references, delete polymorphic attachments and SEO rows, product review image rows/attachments, offer pivots, variant option assignments, option values/options, variants, then the archived product using model `forceDelete()` only for the models that use `SoftDeletes`. Preserve slug redirects; any redirect history remains a blocker. Category deletion follows only after remaining product links are gone, then its own SEO/media attachments and soft-deleted category row are handled. Never rely on cascades as the cleanup plan.
4. Insert a sanitized audit row in the same transaction with actor, timestamp, IP, reason, filters, selected references, counts, blockers, outcome, and a unique idempotency key. On duplicate key, return the persisted result without repeating deletion.
5. Collect detached asset IDs in the transaction. After commit, recheck each asset for remaining media attachments and SEO/default-image references using the existing `MediaService` storage adapter. Delete the physical object only when unreferenced. Persist pending/failure state so file deletion can be retried; never remove a file before commit.

This design is a proposal, not an enabled execution route. Shared-media cleanup behavior, the durable retry record for physical deletion, and the final foreign-key order require security review before adding execution.
