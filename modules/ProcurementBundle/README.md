# Procurement (#555)

The buy side: **who we buy from, what we ordered, what turned up, and what we were charged.**

```
sell side:   Estimate ──accept──> SalesOrder ──convert──> Invoice
buy  side:   (RFQ) ─────────────> PurchaseOrder ─────────> VendorBill
                                        │
                                 GoodsReceipt ──> inventory_movement (type=receipt)
```

Plan: `docs/plans/2026-08-21-procurement-vendor-po-bill-receiving.md`.
Prerequisites, both merged: **#550** (inventory depth) and **#539** (invoice as a separate entity).

## The one rule everything else follows from

> **Receiving does not write stock. It asks #550 to.**

`ReceivingService` builds an `InventoryDepthBundle\Movement\MovementRequest` and hands it to
`StockMovementService::apply()` — the same service the stock adjustment screen and the cycle count
call — then stores the `InventoryMovementGroup` it gets back on the receipt. It never touches
`inventory_detail`, never touches `product_inventory.quantity`, and knows nothing about how either
is maintained.

A receipt therefore produces **exactly the same detail rows and movement group the equivalent
manual adjustment would**, because it is the same call.
`ReceivingServiceTest::testAReceiptProducesExactlyWhatTheEquivalentManualAdjustmentWould` proves it
by doing both and comparing.

That is not tidiness. A second path into `inventory_detail` diverges on validation and on audit, and
receiving is precisely where lot, expiry and serial capture must be enforced identically to
everywhere else.

## Removing this bundle

**Core reads nothing this bundle writes** — asserted by
`ProcurementPackagingTest::testCoreDoesNotReadAnyProcurementTable`, which greps `src/` for every one
of the thirteen table names and for the namespace. `vendor_contact` is in that list for a reason
beyond tidiness: `src/` is where every security decision this app makes lives, and a vendor contact
must stay unreachable from all of it.

Remove it and stock still enters the building, through the adjustment screen, which #550 already
specifies as the way stock enters before receiving exists. No precondition, nothing to migrate.

**Data outlives the bundle.** The receipts and the movements they created remain, and remain visible
in #550's movement history. `goods_receipt.movement_group_id` is `ON DELETE SET NULL` in both
directions of that thought: losing the movement history must not delete the record that goods
arrived, and losing this bundle must not delete the movements.

The one thing that would break the rule is **valuation** — a receipt's cost feeding the product's
cost. That is explicitly not in this job. A receipt *records* `unit_cost` and applies none of it.

## What it mirrors from the sell side, and why

#539 settled how a document behaves in this codebase. Copying that was cheaper than discovering it
twice, and every borrowed convention is load-bearing:

| Convention | Where | Why |
|---|---|---|
| Transitions are **named actions** taking an explicit `DocumentActor`; no `setStatus()` | `PurchaseOrder`, `VendorBill` | A string setter accepts an illegal transition and nothing objects; a Doctrine subscriber cannot object either, because by `onFlush` the caller's intent is gone |
| Each action writes **its own timeline entry** | `PurchaseOrderLog`, `VendorBillLog` | A changeset cannot record why. "Closed short — vendor discontinued it" and "Closed short — we cancelled the rest" are the same changeset |
| The actor is **passed in**, never resolved by the entity | every action | An entity cannot reach the security context, and an ambient lookup leaves a console command or an import unable to sign its own work |
| Audit logging is **path-independent already** | `AuditLogSubscriber` | So no action re-implements it |
| **Status is derived where it can be** | `PurchaseOrderStatusDeriver`, `VendorBillStatusDeriver` | A status somebody can type is a second answer to a question the rows already answer |
| Numbering through `DocumentNumberAllocator`'s per-`(kind, prefix)` atomic counter | `PurchaseDocumentNumberGenerator` | Two concurrent receivers must not be handed one receipt number |
| A cancelled document **keeps its number forever**; nothing is deleted | everywhere | Sequences with holes are questions auditors have to ask |
| Counterparty **snapshotted**, never joined live | `AbstractPurchaseDocument::$vendorName` | Renaming a vendor must not rewrite a three-year-old bill |

### Deliberately not mirrored

- **One document ancestor.** `AbstractSalesDocument` is keyed to `Company` and a mapped superclass
  cannot parametrize `targetEntity` — so a shared base could hold the scalars and specifically not
  the relationship that decides what the document is. `poNumber` settles it: the customer's
  reference on one side, the document's own identity on the other. Same word, two families.
  `App\Contract\Document\CommercialDocument` is the shared interface instead.
- **The sales side implementing that interface.** `getCurrency()` and `getCounterpartyName()` have
  no equivalent there yet, and #555's acceptance criterion is that it changes nothing on the sales
  side. It joins when something genuinely needs one list of both.
- **Two status columns on the bill.** An invoice separates "have the goods gone" from "has the money
  arrived". A bill has no fulfilment of its own — the goods arriving is the receipt's business — so
  the only lifecycle left is the money one plus the two states an AP clerk needs.
- **Payment rows.** Vendor payments and AP aging are out of scope, so `amount_paid` is a figure a
  named action records rather than a sum over rows. The derivation is already the right shape for
  the day those rows exist; only its input changes.

## What a receiver must capture

`procurement_product_rule` is bundle-owned policy: per product, whether a batch code, an expiry, a
serial or a destination bin is required. **No row means nothing is required**, which is exactly how
receiving behaved before the table existed.

It exists because #550 gives a product the *ability* to carry those values and — correctly, for an
adjustment screen — no way to say it *must*. Receiving is the one and only moment they can be
captured: a pallet booked in without its batch code cannot be given one afterwards, because nobody
can tell which boxes on the shelf it was.

A bundle table rather than a column on `product_core`, because core must be able to read nothing this
writes and the policy is meaningless with the bundle gone.

## Things worth knowing before changing it

- **No foreign key to `product_core` from any line table.** `purchase_order_line`,
  `goods_receipt_line` and `vendor_bill_line` all map `product_id` and the migration emits no
  `FOREIGN KEY` for it, exactly as `sales_order_line` does. A line snapshots what was bought and has
  to outlive the product's deletion; imports delete product rows routinely.
- **Quantities are decimals; the ledger counts whole units.** A fractional quantity for a product
  whose stock this will move is refused by name rather than rounded — rounding is how half a case
  becomes either a free unit or a lost one, silently and forever.
- **A `simple` product is recorded, not stocked.** `StockMovementService` refuses anything that has
  not opted in to the depth layer, by design. The receipt line is written with
  `movement_applied = false` and the screen says so.
- **Over-receipt is allowed.** 250 against a PO for 240 is recorded and flagged. Refusing means the
  warehouse cannot say what is physically on the dock, which is worse.
- **Nothing closes a short shipment automatically.** The remainder is a decision — chase it, cancel
  it, write it off — and `closeShort()` demands a reason. A late delivery against a closed order is
  still recorded and does **not** reopen it.
- **A receipt is never edited.** Correcting one is an adjustment that says so, which is a second
  fact rather than an overwrite of the first.
- **The duplicate-payment guard warns and never blocks.** Vendors do reuse their own numbers, and a
  hard block would eventually force a real bill in under a made-up one.
- **Match tolerances default to 0.** Much easier to loosen one than to discover, six months later,
  what has been auto-approving itself.
- **This bundle owns `product_inventory.incoming_quantity`** (#583). `IncomingStockReconciler`
  recomputes it — never increments it — from the outstanding quantity on every `Issued` /
  `Partially Received` / `Received` order for that product in that warehouse, on issue, on close, on
  cancel and after every receipt. It is a **forecast, not stock**: it is absent from
  `getAvailableQuantity()` in every state, because counting it would let a purchase order sell units
  nobody has. With this bundle Inactive nothing writes it and nothing zeroes it — the rows keep what
  the last active period left on them, and the next action recomputes from the orders. The changes
  log themselves: `inventory_bucket_change_log` rows appear under the action
  `purchase_order_incoming` with a null `group_id`, because no stock moved (#582).

## Vendor master data (#605, #606)

The vendor side mirrors the customer side, **minus login identity**.

| customer side | vendor side | |
| --- | --- | --- |
| `company` | `vendor` | gained `payment_term_id`, matching `company.payment_term_id` |
| `customer_user` | `vendor_contact` | **new** — the same person-record, minus every auth column |
| `company_address` | `vendor_address` | gained the contact block, and four purposes |
| `company_note` | `vendor_note` | **new** — one row per note, with an author and a date |
| `company_payment_method` | — | not applicable: how a vendor is paid is a term, not a stored method |
| `company_fulfillment_region` | — | not applicable: about serving customers |
| `company_product_access` | — | not applicable: about what a customer may see |
| `custom_field_value_company` | — | **cannot be built from here** — see below |

### Vendor contacts are records, never accounts

`vendor_contact` takes `customer_user`'s shape and **drops every authentication column**: no
`password`, no `roles`, no `reset_token`, no `reset_token_expires_at`, no `last_login_at`, no
`api_enabled`. It does not implement `UserInterface`, it is not a provider in `security.yaml`, and
`VendorContactCarriesNoLoginIdentityTest` asserts each of those structurally so that adding one
fails a build rather than a review.

`vendor.email` still decides who a purchase order is emailed to when it is set; when it is not, the
primary Active contact's address is used. That fallback is why #605 was raised — 118 of 124 vendors
on the old dev data had no `vendor.email`, so **Send PO** was refused for 95% of the file.

### Address purposes

`is_order_to`, `is_ship_from`, `is_remit_to`, `is_return_to` — **four flags, not one enum**, because
a small supplier is one location wearing all four hats and an enum would need four duplicate rows to
say so. `is_default` stays and is the fallback every purpose resolves through, so a database where
nobody has ticked anything behaves exactly as it did before purposes existed.

Each is frozen onto a document, never joined live:

| purpose | frozen onto | when |
| --- | --- | --- |
| order-to | `purchase_order.vendor_address` | `PurchaseOrder::issue()` |
| ship-from | `goods_receipt.ship_from_address` | when the receipt is booked |
| remit-to | `vendor_bill.remit_to_address` | when the bill is entered, once |
| return-to | — | nothing reads it yet; vendor returns are unbuilt |

### Codes, not names

`vendor_address.province` is `VARCHAR(8)` and `.country` is `VARCHAR(2)`, holding codes, where
`company_address` holds free-text names. That asymmetry is **deliberate and was not resolved by
widening this side**: `App\Service\Region` already states the rule ("addresses store codes …
normalisation happens once, at the write boundary") and `app:seed-regions` maintains the reference
data. `VendorController` normalises through `Region` on save and the screen resolves the name for
display.

### Custom fields on vendors are not built

`custom_field_value_vendor` would need an entry in `CustomFieldDefinition::OBJECT_TYPE_*`, in
`CustomFieldValueRepository::MAP` and in `CustomFieldController::OBJECT_TYPES`. All three are private
constants in `src/`, with no extension point a bundle can reach. Left unbuilt and reported rather
than forced.

## Screens

All under `/admin/bundles/procurement`, behind one sidebar entry under **Apps › Inventory**:
vendors, purchase orders, expected arrivals, receiving, bills, exceptions, settings. Filter state
lives in URL GET params on every list, per the standing requirement.

### Sending a purchase order to its vendor

`/purchase-orders/{id}/print` renders the PO document, and `?pdf=1` is the same render through
dompdf; `/purchase-orders/{id}/send` emails it to the vendor with that PDF attached and calls
`PurchaseOrder::recordSent()`, which writes the timeline entry. Both are built from the sell-side
invoice equivalents (`InvoiceController::document()`/`send()`) and go through the same machinery —
`EmailTemplateRenderer` for the copy, `AppSettings::applyFromAddress()` for the sender,
`MailerInterface` for the send, so `MailerLogSubscriber` writes the `email_log` row that verifies it.

The document is buy-side and deliberately carries **no amount due, no amount received, no payment
status and no remit-to**: nothing is owed on a purchase order, and the vendor's bill is what creates
a payable.

The email body ships as the view `@Procurement/emails/purchase_order_vendor.html.twig` under the
code `purchase_order_vendor`. Nothing resolves for that code until an admin creates a row for it in
Settings → Email Templates, at which point their row wins outright, the same way an override of a
shipped code does. It is not registered in `ShippedEmailTemplates`, which is byte-pinned to the
migration chain as the record of the 22 templates #507 lifted out of it — see the constants on
`PurchaseOrderController`.

## Not in this job

Inventory valuation and costing, landed cost, payments to vendors and AP aging, vendor RFQ / quote
comparison, EDI, vendor portals, drop-ship, and automatic reorder-point purchasing.

Currency is stored per document and **never converted** — a deliberate deferral. If a real vendor
bills in another currency, that stops being deferrable and should be settled before more schema is
written rather than after.
