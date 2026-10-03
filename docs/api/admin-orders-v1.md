# Admin Orders API v1: Return Decisions and Physical Returns

All endpoints below use the existing admin route group:

```text
/api/v1/admin
```

They require `auth:sanctum`, `admin.access`, and a UUID `Idempotency-Key` on
mutating requests.

## Phase 1: return decisions

This phase records a decision only. It does not complete a physical receipt and
does not perform refunds, COD settlement, exchanges, policy-deadline checks, or
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
return_inventory_target_missing
return_inventory_reserved_conflict
return_inventory_overflow
return_order_status_not_allowed
```

## Rollout warning

There is no historical backfill or cutoff date. Do not record a return that was
already manually restocked as a new restocking operation. From activation,
this feature is the supported way to record physical return restocking.
