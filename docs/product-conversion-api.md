# Simple product conversion API

Both endpoints require the existing admin authentication middleware.

## Read conversion context

`GET /api/v1/admin/products/{product}/conversion-context/{source}` returns the simple sellable item, its stock/reservation quantities, and a `contextToken`. Read this immediately before submitting the conversion. The token becomes stale when the product catalog or source state changes.

```json
{
  "data": {
    "product": { "id": 12, "nameAr": "قميص", "nameEn": "Shirt", "status": "active" },
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

The sum of new variant stock must equal the source stock unless the request includes `stock_adjustment` with the exact signed difference and a reason, for example `{ "confirmed_difference": -2, "reason": "Damaged units removed during count" }`. A successful first request returns `201`; an exact retry using the same idempotency key and request returns the stored result with `200` and `meta.replayed: true`. Reusing a key for different input, a stale context, a reserved product, or a second conversion returns `409`. Invalid fields or assignments return `422`.

The source item remains as an inactive, zero-stock historical record with its order and return references intact. Admin sellable item responses identify it with `isSuperseded` and `supersededAt`. It cannot be edited, archived, restored, or reactivated for sale.
