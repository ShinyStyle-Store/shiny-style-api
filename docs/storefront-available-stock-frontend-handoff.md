# Storefront Available Stock API Handoff

This document describes the product detail and order behavior implemented by
the API.

## Product detail availability

```http
GET /api/v1/products/{slug}
```

The endpoint returns a product in `data`. Its displayed-item fields include
`displayVariantId`, `sku`, `inStock`, and `availableQuantity`. The full
`data.sellableItems` array contains each publicly visible active sellable item
with its own `id`, `sku`, `inStock`, and `availableQuantity`.

For each item, the API calculates:

```text
availableQuantity = max(stock_quantity - reserved_quantity, 0)
inStock = availableQuantity > 0
```

The top-level `availableQuantity` and `inStock` refer to the same item as
`displayVariantId`. This is the item selected by the existing storefront
display rules: an available default item first, then another available item,
then the default item, then the first public item. A product with no displayed
sellable item returns `displayVariantId: null`, `sku: null`,
`availableQuantity: 0`, and `inStock: false`.

### Simple product example

For a default sellable item with stock 10 and 3 reserved, the relevant response
fields are:

```json
{
  "data": {
    "displayVariantId": "501",
    "sku": "THROW-BEIGE",
    "availableQuantity": 7,
    "inStock": true,
    "sellableItems": [
      {
        "id": "501",
        "sku": "THROW-BEIGE",
        "availableQuantity": 7,
        "inStock": true,
        "images": [],
        "optionValues": {}
      }
    ],
    "defaultSellableItemId": "501"
  }
}
```

### Product with variants example

Each variant is calculated independently. Here the default variant has stock 3
with 3 reserved, while another active variant has stock 10 with 3 reserved.
The second item is selected for display because it is available.

```json
{
  "data": {
    "displayVariantId": "502",
    "sku": "THROW-GRAY",
    "availableQuantity": 7,
    "inStock": true,
    "sellableItems": [
      {
        "id": "501",
        "sku": "THROW-BEIGE",
        "availableQuantity": 0,
        "inStock": false,
        "images": [],
        "optionValues": {"color": "beige"}
      },
      {
        "id": "502",
        "sku": "THROW-GRAY",
        "availableQuantity": 7,
        "inStock": true,
        "images": [],
        "optionValues": {"color": "gray"}
      }
    ],
    "defaultSellableItemId": "502"
  }
}
```

These are response excerpts showing the availability-related shape. The real
product detail response also includes the product, category, price, SEO,
structured data, description, options, gallery, and other existing fields.

Inactive, soft-deleted, and superseded sellable items are not returned in
`data.sellableItems`. Inventory fields are calculated from each sellable item;
aggregate product stock is not used. A GET request only reads availability
and does not reserve or update inventory. Availability is a snapshot and can
change after the response is sent.

## Order creation and insufficient stock

```http
POST /api/v1/orders
```

The frontend may use `availableQuantity` to limit quantity selection and give
immediate feedback. This is only a user experience aid. At order creation the
backend reads current availability again inside its transaction while locking
the relevant product and sellable-item rows. The backend is authoritative if
availability changed since the product response.

If an item is short on stock, the request returns HTTP `422` with this shape:

```json
{
  "code": "insufficient_stock",
  "message": "The requested quantity exceeds the current available stock.",
  "sellable_item_id": 502,
  "requested_quantity": 8,
  "available_quantity": 7
}
```

The quantities and ID in this response are integers. `available_quantity` is
the current quantity at the transactional stock check. If any line in a
multi-item order is short, the order fails and no line is reserved.

## Verification coverage

The product availability response, display-item consistency, and inventory
read-only behavior are covered by
`ProductApiTest::test_product_details_return_each_public_items_clamped_availability_without_changing_inventory`.
The existing public display-priority test is
`ProductApiTest::test_public_availability_uses_available_quantity_and_preserves_display_variant_priority`.
Order shortage response, stale availability, and all-or-nothing reservation
behavior are covered by
`OrderCreationTest::test_exact_available_stock_succeeds_and_excess_stock_rolls_back`,
`OrderCreationTest::test_stale_order_request_returns_current_available_quantity`,
and
`OrderCreationTest::test_insufficient_stock_in_multi_item_order_rolls_back_all_reservations`.

The PostgreSQL-specific
`PostgreSqlInventoryConcurrencyTest::test_concurrent_orders_cannot_reserve_more_than_available_stock`
test exercises two competing order operations against the PostgreSQL locking
behavior. It requires the dedicated PostgreSQL test configuration and database.
