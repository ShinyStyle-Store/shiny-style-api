# Frontend: Convert a simple product

Both endpoints require the admin bearer token.

## 1. Read the conversion context

Use the product's `sourceSellableItemId` from the admin product list or detail response as `{source}`.

```http
GET {{base_url}}/api/v1/admin/products/{{product_id}}/conversion-context/{{source_id}}
Authorization: Bearer {{admin_token}}
Accept: application/json
```

This GET request has **no body**. Read `data.contextToken` and `data.inventory.oldStock` from its response. Fetch a fresh context immediately before converting.

## 2. Submit the conversion

```http
POST {{base_url}}/api/v1/admin/products/{{product_id}}/convert-simple-item/{{source_id}}
Authorization: Bearer {{admin_token}}
Accept: application/json
Content-Type: application/json
Idempotency-Key: {{uuid}}
```

Body example. Here the source has 8 units and the two new variants total 10, so the confirmed difference is `10 - 8 = 2`:

```json
{
  "context_token": "PASTE_FRESH_contextToken_HERE",
  "options": [
    {
      "ref": "size-option",
      "code": "size",
      "name_ar": "المقاس",
      "name_en": "Size",
      "sort_order": 0,
      "values": [
        {
          "ref": "small",
          "code": "small",
          "value_ar": "صغير",
          "value_en": "Small",
          "sort_order": 0
        },
        {
          "ref": "large",
          "code": "large",
          "value_ar": "كبير",
          "value_en": "Large",
          "sort_order": 1
        }
      ]
    }
  ],
  "variants": [
    {
      "ref": "small-variant",
      "sku": "PRODUCT-2-SMALL",
      "price": "25.00",
      "stock_quantity": 5,
      "sort_order": 0,
      "option_value_refs": ["small"]
    },
    {
      "ref": "large-variant",
      "sku": "PRODUCT-2-LARGE",
      "price": "27.00",
      "stock_quantity": 5,
      "sort_order": 1,
      "option_value_refs": ["large"]
    }
  ],
  "stock_adjustment": {
    "confirmed_difference": 2,
    "reason": "تعديل المخزون بعد العد"
  },
  "default_variant_ref": "small-variant"
}
```

### Stock adjustment rule

Calculate:

```text
confirmed_difference = sum(variants[].stock_quantity) - data.inventory.oldStock
```

- If the result is `0`, omit `stock_adjustment`.
- If it is not `0`, include the exact signed result and a non-empty reason.
- If the source has reserved stock, conversion is rejected; do not use `availableQuantity` for this calculation.

## Response IDs

- `data.source.id` is the superseded historical source.
- Use `data.variants[].id` as the IDs of the new sellable items.
- `data.variants[].optionValueIds` identifies each variant's option values; those IDs are in `data.options[].values[].id`.
- An exact retry must reuse the same `Idempotency-Key`. Use a new UUID for a new conversion.

`GET /api/v1/admin/products/{product}/convert-simple-item/{source}` is not supported. Use the GET context endpoint above, then POST the conversion.
