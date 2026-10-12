# Admin Orders API v1: Return Decisions and Physical Returns

All endpoints below use the existing admin route group:

```text
/api/v1/admin
```

They require `auth:sanctum`, `admin.access`, and a UUID `Idempotency-Key` on
mutating requests.

## Admin dashboard overview

```http
GET /api/v1/admin/dashboard/overview
```

Optional query parameter:

```text
chart_days=7|30
```

`chart_days` defaults to `7` and controls only the daily order chart. Other
dashboard metrics always keep their existing definitions and periods.

This endpoint is read-only and uses the existing admin authentication
middleware. It reports the current calendar month through the request-time
instant using `Africa/Cairo` boundaries. Database timestamp comparisons are
converted to UTC.

```json
{
  "data": {
    "period": {
      "timezone": "Africa/Cairo",
      "start": "2026-10-01T00:00:00+03:00",
      "end": "2026-10-04T14:30:00+03:00"
    },
    "currency": "EGP",
    "metrics": {
      "orders_created_this_month": 42,
      "delivered_orders": 18,
      "delivered_order_value": "24500.00",
      "average_delivered_order_value": "1361.11",
      "active_products": 120,
      "unavailable_active_products": 14
    },
    "orders_chart": {
      "days": 7,
      "timezone": "Africa/Cairo",
      "start_date": "2026-09-28",
      "end_date": "2026-10-04",
      "points": [
        { "date": "2026-09-28", "orders": 0 },
        { "date": "2026-09-29", "orders": 0 },
        { "date": "2026-09-30", "orders": 3 },
        { "date": "2026-10-01", "orders": 8 },
        { "date": "2026-10-02", "orders": 12 },
        { "date": "2026-10-03", "orders": 11 },
        { "date": "2026-10-04", "orders": 8 }
      ]
    },
    "currency_totals": [
      {
        "currency": "EGP",
        "delivered_orders": 18,
        "delivered_order_value": "24500.00",
        "average_delivered_order_value": "1361.11"
      }
    ],
    "top_selling_products": [
      {
        "product_id": 12,
        "historical_order_item_id": null,
        "name": "Product name",
        "net_units_sold": 17,
        "image": null
      }
    ]
  }
}
```

`orders_created_this_month` counts every order by `created_at`, regardless of
status. `delivered_orders` and `delivered_order_value` use only orders with
`status=delivered` and `delivered_at` in the period. The value is the original
order `total`, including shipping; it is not profit, net revenue, or reduced by
refunds. The average uses that same cohort and is rounded half-up to two
decimal places.

`orders_chart` counts every order by `created_at`, regardless of status, for
the preceding `chart_days - 1` Cairo calendar days plus today. Today's point
ends at the request-time instant. The response always contains exactly 7 or 30
ascending date points, with zero-filled dates where no orders were created.
The daily buckets use Cairo calendar midnights converted to UTC, including
daylight-saving transitions.

Catalog counts are current-state values, independent of the period. Active
products exclude soft-deleted products. An active product is unavailable when
it has no active, non-deleted variant with `stock_quantity > reserved_quantity`.

With one delivered currency, the singular `currency`, value, and average fields
are populated. With multiple delivered currencies, singular currency and
monetary fields are `null`; `currency_totals` keeps each currency separate.
With no delivered orders, `currency` is `EGP`, monetary values are `"0.00"`,
and `currency_totals` is empty.

Top products use delivered-order items from the same cohort. Net units are
ordered quantity minus the sum of `effective_received_quantity` from all
non-reversed return receipts, including legacy receipts. Returns are applied
as of now even when recorded after the reporting period. Variants aggregate by
stable `product_id`; rows whose product ID is null remain separate by
`historical_order_item_id` and are never merged by name. Soft-deleted products
are returned with their available identity and media. Hard-deleted products
can only use their order-item name snapshots and have no current product image.

## Admin order list summaries and filters

`GET /api/v1/admin/orders` retains its existing order and payment statuses and
adds these fields to every order row:

```json
{
  "return_summary": "none",
  "return_workflow_status": "waiting_for_return",
  "refund_recording": {
    "paid_amount": "0.00",
    "refunded_amount": "0.00",
    "remaining_balance": "0.00",
    "currency": "EGP",
    "source": "undetermined",
    "eligible": false,
    "blocker": "paid_amount_unavailable",
    "confirmed": false,
    "confirmed_at": null,
    "confirmed_by_user_id": null,
    "record_id": null,
    "record_public_id": null
  }
}
```

`return_summary` is the same effective `none|partial|full` summary as order
details. It sums current received quantities after corrections and excludes
reversed receipts. `return_workflow_status` is `waiting_for_return`, `received`,
or `null` when there is no return decision. A `waiting_for_return` workflow
takes precedence for the return badge even when `return_summary` is `none`.
Otherwise, `partial` and `full` indicate received items; `none` means there is
no extra return badge.

`refund_recording` has the same fields and meaning as the order detail summary.
`confirmed=true` means a manual refund record exists; display its
`refunded_amount` and `currency`. It does not prove that the complete collected
amount was refunded. Compare `paid_amount` with `refunded_amount` only when
`source` is authoritative. In particular, `source=undetermined` means the
summary's `paid_amount="0.00"` is a placeholder and must not be treated as a
known collected amount. When there is no confirmed record, `eligible=true` is
only a refund review cue, not an approved amount or debt. Otherwise there is no
extra refund badge. An unpaid COD delivery refusal is ineligible.

Optional server-side filters are:

```text
return_status=awaiting_return|received|none
refund_status=review|recorded|none
```

`return_status=received` includes partial and full effective receipts unless a
workflow is currently `waiting_for_return`. `return_status=none` means no
waiting workflow and no positive effective received quantity.
`refund_status=recorded` means a manual record is confirmed;
`refund_status=review` means there is no record and the detail summary has
`eligible=true`; `refund_status=none` means neither condition applies, including
when the paid amount is undetermined. Both filters combine with the existing
list filters and are applied before sorting and pagination, so pagination
totals and links describe the filtered result.

## Phase 1: return decisions

This phase records a decision only. It does not complete a physical receipt and
does not execute provider refunds, COD settlement, exchanges, policy-deadline checks, or
any inventory/reservation/payment mutation.

### Delivery refusal

```http
POST /api/v1/admin/orders/{public_id}/refuse-delivery
```

Allowed only when the order is `shipped`. The order becomes
`delivery_refused` and a decision is created with:

```json
{
  "reason": "refused_delivery",
  "note": "The customer refused the complete shipment."
}
```

### Return approval after delivery

```http
POST /api/v1/admin/orders/{public_id}/approve-return
```

Allowed only when the order is `delivered`. The order remains `delivered` and
the decision status is `waiting_for_return`.

Both endpoints reject unknown request fields. `kind`, `status`, `actor`,
`order_id`, and `version` are server-controlled. A new operation returns `201`;
an identical idempotent replay returns `200`. Successful decision responses are
snapshotted at creation, so a replay returns the same normalized `data` payload
even after the decision changes workflow state.

Decision responses contain:

```json
{
  "data": {
    "id": 12,
    "kind": "delivery_refusal",
    "status": "waiting_for_return",
    "reason": "refused_delivery",
    "note": "The customer refused the complete shipment.",
    "version": 0,
    "actor": {"id": 7},
    "recorded_at": "2026-10-03T10:15:00+03:00"
  }
}
```

Stable `409` codes include:

```text
return_decision_order_status_not_allowed
return_decision_already_exists
return_decision_legacy_conflict
return_decision_receipt_required
idempotency_key_conflict
```

Admin order details expose `return_workflow` separately from the existing
`return_summary`. It is `null` when no decision exists. Existing receipts do
not create or backfill a decision.

`delivery_refused` is a public order status. Public responses do not expose
admin notes or actor information.

Existing successful legacy receipt replays, legacy reads, legacy corrections,
and their idempotency behavior remain supported. Decision-linked receipts use
the full-order rules in the Phase 2 section below.

## Manual refund records

Manual refund records document a transfer already completed by the store owner
outside the website. They do not call Paymob or initiate a transfer.

```http
POST /api/v1/admin/orders/{public_id}/refund-records
Content-Type: multipart/form-data
Idempotency-Key: <uuid>
```

The multipart fields are `amount`, `currency`, `transfer_method`,
`transferred_at`, `transaction_reference` (optional), `note` (optional), and
mandatory image field `evidence`. A successful response means both the refund
record and evidence attachment were persisted; the record response includes
`version: 0`. A replay with the same key and
equivalent fields and evidence digest returns the original record without a
second upload; a changed payload or evidence returns `409` with
`idempotency_key_conflict`.

Refund records are allowed when an order has an `order_returns` decision or
legacy `return_receipts` history, including `waiting_for_return` and `received`
decisions. A paid order cancelled before shipment is also eligible without a
return decision or physical receipt. This cancellation path requires
`status=cancelled` and no authoritative `shipped_at` history; an active
pre-shipment order or an order with shipment history does not qualify through
the cancellation path. The API does not use `return_summary` or warehouse
arrival as the sole eligibility test.

For paid COD orders, the determined amount source is
`cod_full_collection_policy` and equals the original order total. For card
orders, it is the single authoritative successful payment attempt amount.
Failed, expired, ambiguous, or review-required payment attempts are blocked.
The owner chooses the amount; shipping is neither automatically added nor
deducted. The response and admin order detail expose `paid_amount`,
`refunded_amount`, `remaining_balance`, `source`, `eligible`, and `blocker`.
`remaining_balance` is an arithmetic difference only; it is not money owed and
does not make another refund eligible after confirmation. A successful create
is the order's one final refund confirmation, even when the owner records less
than the paid amount. The summary additionally exposes `confirmed`,
`confirmed_at`, `confirmed_by_user_id`, `record_id`, and `record_public_id`.

After confirmation, a different idempotency key returns `409` with
`refund_recording_already_confirmed` and does not upload evidence. An exact
replay of the original key, fields, and evidence digest returns the original
record with `200`, including after confirmation and after later corrections.
Changing any field or the evidence bytes with that key returns
`idempotency_key_conflict`.

Refund-record history is available through:

```http
GET /api/v1/admin/orders/{public_id}/refund-records
GET /api/v1/admin/orders/{public_id}/refund-records/{refund_record_id}
```

Corrections use a multipart `PATCH` with the replacement values, mandatory
replacement `evidence`, `expected_version`, and `reason`. Corrections preserve
the original values and evidence relationship; they correct recorded data and
do not reverse or claim to undo the external bank transfer. They preserve the
order's confirmed state and never reopen final refund recording. Frontends must
retry an uncertain request with the identical fields, identical evidence file,
and identical operation key. Successful corrections increment `version` and
return `expected_version` and `new_version` in the correction audit item. A
correction does not authorize a second transfer
or change payment, order, return, or inventory state.

## Phase 2: full physical receipt

The same receipt endpoint is used for a decision-linked receipt:

```http
POST /api/v1/admin/orders/{public_id}/returns
```

For a new decision, the request must contain every original order item exactly
once and each `received_quantity` must equal that item's original ordered
quantity. `restock_quantity` may be zero or any integer up to the received
quantity. The operation creates one effective receipt, applies only restock
stock deltas, leaves reservations/payments/totals unchanged, and changes the
decision to `received` atomically.

The response keeps the existing item names and additionally includes:

```json
{
  "order_return_id": 12,
  "workflow_linked": true,
  "reversed": false,
  "version": 0
}
```

Missing, duplicate, extra, foreign, or incomplete item sets return
`return_full_order_items_required` or the existing validation response. A
second effective receipt returns `return_receipt_already_exists`.

## Whole-receipt reversal

```http
POST /api/v1/admin/orders/{public_id}/returns/{return_receipt}/reverse
```

```json
{
  "expected_version": 0,
  "reversal_reason": "The inspection was recorded against the wrong shipment."
}
```

The operation is available only for the current effective receipt linked to
the order decision. It reverses all current restock deltas atomically, marks
the receipt as `reversed`, increments its version, and returns the decision to
`waiting_for_return`. It does not delete the receipt, corrections, or audit
history.

The response contains an operation and the reversed receipt:

```json
{
  "data": {
    "operation": {
      "return_receipt_id": 71,
      "expected_version": 0,
      "new_version": 1,
      "reversal_reason": "The inspection was recorded against the wrong shipment.",
      "idempotency_key": "..."
    },
    "receipt": {
      "id": 71,
      "order_return_id": 12,
      "reversed": true,
      "reversed_at": "2026-10-03T12:00:00+03:00"
    }
  }
}
```

Reversal conflicts include `return_receipt_reversal_not_allowed`,
`return_receipt_reversal_not_current`, `return_receipt_already_reversed`,
`return_receipt_reversed`, and `return_revision_conflict`.

Reversed receipts remain stored and excluded from effective `return_summary`.
The partial unique index allows one non-reversed receipt per decision. A new
full receipt may be submitted after reversal and becomes the new effective
receipt; the old receipt remains visible as reversed.

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

New receipts require an existing return decision and must be full-order
receipts. Existing legacy receipts remain readable and their successful
idempotent replays and corrections remain supported; the legacy endpoint is
not a route for creating new partial or unlinked receipts. A decision-linked
receipt may be recorded for a `delivery_refused` order.
`items_received_at` defaults to server time once for the operation, rejects
future dates, and cannot precede a known `shipped_at`. `return_requested_at` is
optional and is never inferred; when present it cannot be later than
`items_received_at`. No 14-day or 48-hour deadline is enforced by this phase.

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
      "sku": "SKU-1",
      "image_url": "https://example.test/storage/catalog/variant.jpg"
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

`image_url` is a nullable URL for the current primary image of the purchased
sellable item, falling back to the current primary product image. It is resolved
from current catalog media when the order is read; it is not an image snapshot
saved when the order was placed. It is `null` when neither image is available,
including when the referenced catalog records or media have been permanently
removed.

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
retry with the same key and canonical content returns the exact snapshotted
operation result, even after later versions, corrections, reversal, or
replacement receipts; `GET` returns the current receipt state. New receipt,
correction, and reversal responses are snapshotted after their mutations inside
the same transaction. Replays do not recalculate summaries or serialize live
receipt/workflow relationships.
Correction operation and history items expose `previous_restock_quantity` and
`new_restock_quantity`; the internal `*_restockable_quantity` names are not
part of the public response contract.
The frontend reads `version` from `GET`, sends it as `expected_version`, and
refreshes for user review after a conflict instead of automatically retrying.

## Return inventory transfers

Restocking a received return adds stock to the historical sellable item saved
on its original `OrderItem`, even when that source is superseded. Moving
eligible stock to a current variant is a separate admin operation after
inspection. A transfer changes inventory only; it does not change the order,
payment, or order-item snapshots.

```http
GET  /api/v1/admin/orders/{public_id}/returns/{return_receipt}/items/{return_receipt_item}/inventory-transfers
POST /api/v1/admin/orders/{public_id}/returns/{return_receipt}/items/{return_receipt_item}/inventory-transfers
POST /api/v1/admin/orders/{public_id}/returns/{return_receipt}/items/{return_receipt_item}/inventory-transfers/{transfer}/reverse
```

All three routes are under the existing `auth:sanctum` and `admin.access`
middleware. Send `Authorization: Bearer <admin-token>` and
`Accept: application/json`; JSON writes also send `Content-Type: application/json`.
The token must have the `admin-access` ability and belong to an active admin.

In the URL, `public_id` is the order's public 26-character ID;
`return_receipt` and `return_receipt_item` are integer database IDs for the
receipt and its item; `transfer` is the integer transfer record ID. The source
sellable item is resolved from the historical order/receipt item references;
it is not supplied by the client. The destination is supplied as
`target_sellable_item_id`.

These transfer and transfer-reversal requests do not accept a version field.
The adjacent receipt correction and whole-receipt reversal APIs use
`expected_version`; see their contracts above.

### Read eligibility and history

`GET` returns `200` with `data` containing the historical source and the
eligibility calculation for this receipt item. It includes all transfer
records, with a nested reversal when one exists:

```json
{
  "data": {
    "order_id": 91,
    "return_receipt_id": 22,
    "return_receipt_item_id": 71,
    "source_sellable_item_id": 44,
    "current_restock_quantity": 3,
    "pre_conversion_restock_quantity": 0,
    "eligibility_known": true,
    "eligible_quantity": 3,
    "transferred_quantity": 1,
    "source_available_quantity": 2,
    "eligible_remaining_quantity": 2,
    "receipt_reversed": false,
    "transfers": [{
      "id": 18,
      "order_id": 91,
      "return_receipt_id": 22,
      "return_receipt_item_id": 71,
      "source_sellable_item_id": 44,
      "target_sellable_item_id": 132,
      "quantity": 1,
      "reason": "Inspected and assigned to the regular fit variant.",
      "recorded_by_user_id": 7,
      "idempotency_key": "11111111-1111-4111-8111-111111111111",
      "source_stock_before": 2,
      "source_stock_after": 1,
      "target_stock_before": 4,
      "target_stock_after": 5,
      "reversed": false,
      "reversal": null,
      "created_at": "2026-10-12T09:30:00.000000Z"
    }]
  }
}
```

`current_restock_quantity` is the receipt item's effective restock quantity.
At conversion, the backend stores the IDs and effective restock quantities of
receipt items that already existed. For a known baseline,
`pre_conversion_restock_quantity` is that item's stored quantity and
`eligible_quantity = max(0, current_restock_quantity - baseline)` while the
receipt is not reversed. `transferred_quantity` sums transfers without a
reversal. `source_available_quantity` is source stock minus reservations,
floored at zero. `eligible_remaining_quantity` is the smaller of remaining
eligibility and source availability, floored at zero.

When a conversion has no baseline (`eligibility_known: false`), eligible
quantities are zero and transfer creation is blocked. In that response,
`pre_conversion_restock_quantity` falls back to the current restock quantity;
it is **not** a recovered or inferred baseline. Do not estimate eligibility
from dates, current stock, or totals.

### Create a transfer

Send a UUID in the `Idempotency-Key` header. The JSON body has exactly these
fields; unknown fields are rejected. `target_sellable_item_id` is an integer
at least 1, `quantity` is an integer from 1 through 2,147,483,647, and `reason`
is trimmed, non-empty text up to 5,000 characters:

```json
{
  "target_sellable_item_id": 132,
  "quantity": 1,
  "reason": "Inspected and assigned to the regular fit variant."
}
```

The target must belong to the same product, be active, unarchived and not
superseded, and have exactly one valid active value for every active product
option (or no option assignments for a simple product). The product need not
be published. A line can be split across targets, but transfers cannot exceed
its remaining eligibility or the source's available stock. Reservations are
not changed. The source remains superseded and unavailable for new sales.

A new transfer returns `201 Created` with `data`:

```json
{
  "data": {
    "id": 18,
    "order_id": 91,
    "return_receipt_id": 22,
    "return_receipt_item_id": 71,
    "source_sellable_item_id": 44,
    "target_sellable_item_id": 132,
    "quantity": 1,
    "reason": "Inspected and assigned to the regular fit variant.",
    "recorded_by_user_id": 7,
    "idempotency_key": "11111111-1111-4111-8111-111111111111",
    "source_stock_before": 2,
    "source_stock_after": 1,
    "target_stock_before": 4,
    "target_stock_after": 5,
    "created_at": "2026-10-12T09:30:00.000000Z"
  }
}
```

The source stock is reduced by `quantity` and the target stock increased by
the same amount in one transaction. Both changes must preserve reserved stock.
An exact retry with the same key and same normalized operation data returns
`200 OK` and the saved `data` response; it does not move stock again. The
transfer response has no `meta.replayed` field. Reusing the key with different
data returns `409 idempotency_key_conflict`.

### Reverse a transfer

Reverse the whole transfer record; partial reversal is not supported. Use a
new UUID `Idempotency-Key`. The body contains exactly one field,
`reversal_reason`: trimmed, non-empty text up to 5,000 characters. Unknown
fields are rejected.

```json
{ "reversal_reason": "The item was assigned to the wrong variant." }
```

A new reversal returns `201 Created` with `data`:

```json
{
  "data": {
    "id": 31,
    "transfer_id": 18,
    "reason": "The item was assigned to the wrong variant.",
    "recorded_by_user_id": 7,
    "idempotency_key": "22222222-2222-4222-8222-222222222222",
    "source_stock_before": 1,
    "source_stock_after": 2,
    "target_stock_before": 5,
    "target_stock_after": 4,
    "created_at": "2026-10-12T10:00:00.000000Z"
  }
}
```

The reversal moves the original transfer quantity from the target back to the
historical source and never reactivates the source. It requires target
available stock (`stock_quantity - reserved_quantity`) to cover the full
quantity. An inactive or archived target remains usable for this historical
reversal. The transfer row is retained unchanged; reversal is a separate
append-only record, and a transfer can have only one reversal.

An exact retry with the same key and same reversal data returns `200 OK` with
the saved reversal response and makes no second stock change. Same key with a
different transfer or reason returns `409 idempotency_key_conflict`. A new key
for an already reversed transfer returns `409
return_inventory_transfer_already_reversed`.

### Status codes and conflict handling

| Status | Meaning and client action |
|---|---|
| `200` | Eligibility/history read, or exact idempotent replay. Refresh state after replay because it can represent an earlier operation. |
| `201` | A transfer or reversal was newly recorded. Refresh eligibility, stock and history. |
| `401` / `403` | Missing/invalid admin authentication or insufficient admin access. Re-authenticate or request access; do not retry unchanged automatically. |
| `404` | Order, receipt, receipt item or transfer reference was not found in the supplied relationship. Refresh the relevant page and IDs. |
| `422` | Missing/invalid fields, invalid UUID header, or unknown body keys. Map validation errors to fields and preserve the user's inputs. |
| `409` | Domain or idempotency conflict. Read `code` and `message`, refresh relevant state, and let the admin resolve it; do not retry automatically. |

Domain conflicts return `{ "code": "...", "message": "..." }`. Transfer and
reversal conflicts from this service are:

| Code | Meaning / frontend response |
|---|---|
| `idempotency_key_conflict` | The key belongs to different operation data. Refresh history; if this is a new intended operation, submit it with a new key. |
| `return_inventory_source_not_converted`, `return_inventory_source_mismatch` | Historical order/receipt/source references do not match a recorded conversion. Refresh the order and receipt; do not select a different source manually. |
| `return_receipt_reversed`, `return_receipt_not_current` | The receipt is reversed or no longer the current received receipt. Refresh return state; transfer is unavailable for it. |
| `return_inventory_target_invalid` | Target is missing from current eligible choices, belongs to another product, is inactive/archived/superseded, or its option assignment is invalid. Reload current variants and select a valid target. |
| `return_inventory_eligibility_unknown` | No conversion baseline exists. Keep transfer disabled and route for manual review; do not estimate. |
| `return_inventory_eligible_quantity_insufficient` | Requested quantity exceeds unallocated eligible restock. Refresh eligibility and lower the quantity if appropriate. |
| `return_inventory_source_unavailable` | Historical source available stock is insufficient after reservations. Refresh source and eligibility. |
| `return_inventory_invariant_violation`, `return_inventory_reserved_conflict` | Inventory is inconsistent or the mutation would violate a reservation. Stop and refresh/escalate; do not bypass the guard. |
| `return_inventory_target_unavailable` | Reversal target available stock is insufficient after reservations. Refresh; wait for enough available stock before trying again. |
| `return_inventory_overflow` | The resulting stock would exceed the supported integer range. Stop and request inventory review. |
| `return_inventory_transfer_item_mismatch`, `return_inventory_target_missing` | Transfer/source/target references no longer match or a required inventory row is missing. Refresh and escalate; do not issue another stock mutation. |
| `return_inventory_transfer_already_reversed` | This transfer already has a reversal. Refresh history; do not move stock again. |

Receipt correction and whole-receipt reversal are separate endpoints. They
require `expected_version` as documented above. A correction that reduces an
item's restock quantity below its non-reversed transferred quantity, or a
receipt reversal while it has active transfers, returns `409
return_inventory_transfer_requires_reversal`. Reverse every outstanding
transfer first, then refresh the receipt and use its current version for a new
correction or receipt reversal. A full-order correction must retain every
receipt line and its full `received_quantity`; it can reduce `restock_quantity`
alone. Reason/note edits that do not reduce transferred restock remain
allowed. Reversing a transfer does not cascade into receipt corrections.

Mutations share the return workflow lock order: original Order, return
decision, return receipt, return receipt item, original OrderItem, Product,
then source and target SellableItems in ascending ID order. The Order lock serializes transfer with
receipt correction and reversal; ascending SellableItem locks serialize
competing stock changes. This documents application ordering, not a claim that
SQLite tests prove PostgreSQL concurrency behavior.

### Legacy operation replays

Rows created before response snapshots were added have a nullable snapshot and
are not backfilled from current state. Legacy create replays reconstruct only
immutable initial receipt values and guaranteed create semantics; historical
workflow state and update time are `null` when they were not stored. Legacy
correction replays return the immutable correction `operation` and `receipt:
null`, because older correction rows cannot reconstruct the complete receipt at
that revision without leaking later live state. Legacy reversal replays use
persisted reversal data and immutable receipt history; unrecoverable historical
summary/update fields are `null`.

## Delivery guards

`shipped -> delivered` is rejected after any positive effective physical
return, for all payment methods. `delivery_refused` cannot be delivered,
shipped again, or cancelled through the existing lifecycle actions. There is no
admin bypass endpoint. Returns do not cancel delivered orders and do not alter
payment status or totals.

## Errors

Validation errors use the existing Laravel `422` validation response. Domain
conflicts use the existing `code`/`message` JSON shape with `409`, including:

```text
return_revision_conflict (expected_version conflict)
idempotency_key_conflict
refund_recording_already_confirmed
refund_amount_exceeds_balance
paid_amount_unavailable / paid_amount_ambiguous / paid_amount_requires_review
cancelled_after_shipment_not_eligible
return_inventory_target_missing
return_inventory_reserved_conflict
return_inventory_overflow
return_order_status_not_allowed
```

## Rollout warning

There is no historical backfill or cutoff date. Do not record a return that was
already manually restocked as a new restocking operation. From activation,
this feature is the supported way to record physical return restocking.

## Product and variant archive management

The admin product list keeps its existing default of excluding archived
products and accepts `archive_state=unarchived|archived|all`:

```text
GET /api/v1/admin/products?archive_state=archived
GET /api/v1/admin/products?archive_state=all
```

The legacy `include_archived=true` remains supported when
`archive_state` is omitted. Unsupported values, or sending both parameters,
return `422`. The variant list uses the same filter:

```text
GET /api/v1/admin/products/{product}/sellable-items?archive_state=all
```

Archived product and variant detail GET requests remain available to admins,
including archived product options and media listing requests. Mutation
endpoints continue to use normal model binding, so archived records remain
read-only until restored. Resources expose `archiveState` and `archivedAt`.

Restoring a product leaves it `inactive` with `publishedAt=null`; restoring a
variant leaves it `inactive`. Restoring a variant under an archived product
returns HTTP `409` with code `product_archived`; missing resources and
wrong-parent variants return `404`.

Product archive and order creation coordinate on the product row lock. An
order transaction that acquired and validated that lock before archive may
finish; a transaction that acquires it after archive completes rejects the
product. A quote does not reserve purchase permission. Product locks precede
sellable-item locks and use deterministic ID order for multi-product orders.
