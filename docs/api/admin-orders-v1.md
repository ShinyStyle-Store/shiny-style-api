# Admin Orders API v1: Physical Returns

All endpoints below use the existing admin route group:

```text
/api/v1/admin
```

They require `auth:sanctum`, `admin.access`, and a UUID `Idempotency-Key` on
mutating requests.

## Physical receipt

```http
POST /api/v1/admin/orders/{public_id}/returns
```

```json
{
  "items_received_at": "2026-10-02T10:15:00+03:00",
  "return_requested_at": "2026-09-28T12:30:00+03:00",
  "default_reason": "changed_mind",
  "note": "Received at the store.",
  "items": [
    {
      "order_item_id": 41,
      "received_quantity": 2,
      "restock_quantity": 2,
      "note": "Packaging is intact."
    },
    {
      "order_item_id": 42,
      "received_quantity": 1,
      "restock_quantity": 0,
      "reason": "defective_item",
      "note": "Item is damaged."
    }
  ]
}
```

Only `shipped` and `delivered` orders are accepted. `items_received_at` defaults
to server time once for the operation, rejects future dates, and cannot precede
a known `shipped_at`. `return_requested_at` is optional and is never inferred;
when present it cannot be later than `items_received_at`. No 14-day or 48-hour
deadline is enforced by this phase.

The effective item reason is `items.*.reason` followed by
`default_reason`. Supported values are:

```text
changed_mind, refused_delivery, failed_delivery, defective_item,
wrong_item, other
```

`other` requires a meaningful item or receipt note. `restock_quantity` is
the only quantity added to `stock_quantity`; `reserved_quantity`, order
prices, totals, payment state, and catalog activation are not changed.

## Reads

```http
GET /api/v1/admin/orders/{public_id}/returns?page=1&per_page=20
GET /api/v1/admin/orders/{public_id}/returns/{return_receipt}
```

Reads expose `original_*` and `current_*` quantities/reasons, `version`, actor
and operation identity. Receipt detail exposes correction history ordered by
`new_version`, not timestamps. The order detail endpoint also exposes:

```json
{
  "return_summary": "none|partial|full"
}
```

The receipt-level `return_summary` keeps its existing receipt-local meaning.
The order-level `return_summary` uses cumulative current received quantities
across all receipts; restockability does not affect it.

Return item fields are:

```json
{
  "ordered_quantity": 2,
  "original_received_quantity": 2,
  "original_restock_quantity": 1,
  "original_reason": "changed_mind",
  "original_note": null,
  "current_received_quantity": 1,
  "current_restock_quantity": 0,
  "current_reason": "defective_item",
  "current_note": "Damaged during inspection."
}
```

`ordered_quantity` is the quantity purchased in the original order.
`original_*` values are recorded when the receipt is first created. `current_*`
values are the effective values after corrections. `restock_quantity` is the
received quantity approved for adding to sellable stock.

The authenticated admin order detail response includes each original
`OrderItem` primary key and its stored catalog references:

```json
{
  "items": [
    {
      "id": 41,
      "product_id": 12,
      "sellable_item_id": 87,
      "sku": "SKU-1"
    }
  ]
}
```

`id` identifies the historical `OrderItem`. `product_id` and
`sellable_item_id` support admin catalog navigation and may be `null` when the
referenced catalog rows are no longer available. Historical snapshots remain
available independently of these identifiers. Send `id` as
`items.*.order_item_id` when creating a physical return; do not send
`product_id` or `sellable_item_id` for that purpose.

## Corrections

```http
POST /api/v1/admin/orders/{public_id}/returns/{return_receipt}/corrections
```

```json
{
  "expected_version": 0,
  "correction_reason": "The initial inspection was recorded incorrectly.",
  "items": [
    {
      "return_receipt_item_id": 71,
      "received_quantity": 0,
      "restock_quantity": 0
    }
  ]
}
```

Correction quantities replace the selected effective values. Omitted receipt
items remain unchanged. `reason` omission keeps the current reason; explicit
`null` also keeps it. `note` omission keeps the current note; explicit `null`
clears it. Initial receipts require `received_quantity > 0`, while corrections
allow zero. Always:

```text
0 <= restock_quantity <= received_quantity
```

The stock mutation is always:

```text
new_current_restock_quantity
- current_restock_quantity
```

Therefore changing `2` to `0` subtracts `2` from physical stock. A correction
to zero is not automatically a zero-stock operation.

Corrections are append-only. `expected_version` must equal the locked current
`version`. A stale operation returns `409` and makes no mutation. A successful
retry with the same key and canonical content returns the original operation
result, even after later versions; `GET` returns the current receipt state.
The frontend reads `version` from `GET`, sends it as `expected_version`, and
refreshes for user review after a conflict instead of automatically retrying.

## Delivery guards

`shipped -> delivered` is rejected after a full effective physical return.
COD delivery is temporarily rejected after any effective physical return,
because partial COD settlement is not implemented in this phase. There is no
admin bypass endpoint. Returns do not cancel delivered orders and do not alter
payment status or totals.

## Errors

Validation errors use the existing Laravel `422` validation response. Domain
conflicts use the existing `code`/`message` JSON shape with `409`, including:

```text
return_revision_conflict (expected_version conflict)
idempotency_key_conflict
return_inventory_target_missing
return_inventory_reserved_conflict
return_inventory_overflow
return_order_status_not_allowed
```

## Rollout warning

There is no historical backfill or cutoff date. Do not record a return that was
already manually restocked as a new restocking operation. From activation,
this feature is the supported way to record physical return restocking.
