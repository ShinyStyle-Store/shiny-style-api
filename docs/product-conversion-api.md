# Simple product conversion API

Both endpoints require the existing admin authentication middleware.

## Frontend sequence

1. Read the admin product list (`GET /api/v1/admin/products`) or detail (`GET /api/v1/admin/products/{product}`). Both responses include `sourceSellableItemId` as an integer when the product has an eligible simple source, or `null` otherwise. Eligibility follows the conversion service rules; clients should not infer it from `variantsCount` or choose a default item.
2. Use that ID as `{source}` to read a fresh conversion context.
3. Submit the conversion with the returned `contextToken` and an `Idempotency-Key` UUID.
4. Use each `data.variants[].id` in the conversion response as the persisted resulting sellable-item ID. `data.source.id` is the historical source that was superseded. Each variant's `optionValueIds` maps to IDs under `data.options[].values[].id`.

Product list item example:

```json
{
  "id": 12,
  "variantsCount": 1,
  "sourceSellableItemId": 44
}
```

Admin product list and detail responses count only current, non-deleted sellable items. `variantsCount` includes current items with either active or inactive status, and excludes every item with `supersededAt` set. `activeVariantsCount` counts only active items from that same current set. A converted historical source is therefore excluded from both counts, while a current inactive variant remains included in `variantsCount` only. Soft-deleted items are excluded from both fields.

## Read conversion context

`GET /api/v1/admin/products/{product}/conversion-context/{source}` returns the simple sellable item, its stock/reservation quantities, and a `contextToken`. Read this immediately before submitting the conversion. The token becomes stale when the product catalog or source state changes.

```json
{
  "data": {
    "product": { "id": 12, "nameAr": "قميص", "nameEn": "Shirt", "status": "active" },
    "sourceSellableItemId": 44,
    "source": { "id": 44, "sku": "SHIRT-01", "price": "25.00", "stockQuantity": 10, "reservedQuantity": 0, "availableQuantity": 10 },
    "inventory": { "oldStock": 10, "reserved": 0, "available": 10, "transferScope": "source_sellable_item_only" },
    "options": [],
    "variants": [],
    "contextToken": "<64-character sha256 token>"
  }
}
```

## Convert

`POST /api/v1/admin/products/{product}/convert-simple-item/{source}` accepts an `Idempotency-Key` UUID header. Temporary `ref` values connect new options, values, and variants; clients must not send database IDs for these new records.

```json
{
  "context_token": "<contextToken>",
  "options": [{
    "ref": "size-option", "code": "size", "name_ar": "المقاس", "name_en": "Size", "sort_order": 0,
    "values": [{ "ref": "small", "code": "small", "value_ar": "صغير", "value_en": "Small", "sort_order": 0 }]
  }],
  "variants": [{
    "ref": "small-variant", "sku": "SHIRT-S", "price": "25.00", "stock_quantity": 10,
    "sort_order": 0, "option_value_refs": ["small"]
  }],
  "default_variant_ref": "small-variant"
}
```

The top-level `sourceSellableItemId` in the context is the same ID as `source.id` and the `{source}` route parameter, after the service verifies it belongs to the requested product and is convertible.

The sum of new variant stock must equal the source stock unless the request includes `stock_adjustment` with the exact signed difference and a reason, for example `{ "confirmed_difference": -2, "reason": "Damaged units removed during count" }`. A successful first request returns `201`; an exact retry using the same idempotency key and request returns the stored result with `200` and `meta.replayed: true`. Reusing a key for different input, a stale context, a reserved product, or a second conversion returns `409`. Invalid fields or assignments return `422`.

Successful response excerpt:

```json
{
  "data": {
    "source": { "id": 44, "stockBefore": 10, "stockAfter": 0, "supersededAt": "2026-10-13T12:00:00.000000Z" },
    "options": [{ "id": 9, "values": [{ "id": 21, "ref": "small", "code": "small" }] }],
    "variants": [{ "id": 61, "ref": "small-variant", "sku": "SHIRT-S", "optionValueIds": [21] }],
    "defaultVariantId": 61,
    "conversionId": 3
  },
  "meta": { "replayed": false }
}
```

The `variants[].id` values are the persisted new sellable items. The source remains a separate historical record and is never included as a resulting variant. An idempotent retry returns the stored response, including the same resulting IDs.

The source item remains as an inactive, zero-stock historical record with its order and return references intact. Admin sellable item responses identify it with `isSuperseded` and `supersededAt`. It cannot be edited, archived, restored, or reactivated for sale.
