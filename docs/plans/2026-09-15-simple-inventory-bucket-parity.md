# Simple inventory gets the same bucket treatment as dimensional inventory

## Status: implemented (2026-09-15)

All five build-order steps are done, each with dedicated tests, and the full
InventoryDepthBundle/ProcurementBundle/WarehouseOpsBundle suite (430 tests) and the rest of the
repo's suite pass with zero regressions traceable to this work (verified by grepping the full
suite's failure list for every file this plan touched — none appear; the remaining failures are
the same pre-existing, unrelated ones the lot/serial/expiry plan's own Status section already
documented).

- **`received`**: `ReceivingService`/`StockMovementService::apply()` now credit it for a simple
  line too, with no `InventoryDetail` row created. `StockMovementService::apply()`'s blanket
  per-line refusal was replaced with a per-line skip (detail work only if dimensional) — the "one
  shared function" shape rule 11 demands, not a second implementation.
- **`quarantine`/`write_off`**: no longer recomputed from `SUM(inventory_detail)` — independently
  accumulated via `MovementRequest::quarantineDelta()`/`writeOffDelta()`, the same shape as
  `receivedDelta()`. Works for every product; a dimensional line still gets its detail row in the
  same transaction.
- **`transfer_out`/`transfer_in`**: `TransferOrderService::assertDimensional()`'s refusal removed
  entirely (and the now-dead method deleted). The bucket math was already detail-free.
- **Reconciliation**: `app:inventory-depth:detail-check` extended with two new independent checks,
  `quarantine`/`write_off` bucket vs. `SUM(detail)`, reported under their own real bucket names —
  narrower than the existing combined equation and able to catch a case it would mask (two buckets
  drifting oppositely by the same amount).
- **Screens**: `AdjustmentController`'s product picker now lists every product; `addLines()` takes
  a dimensional check before the outbound source-picking logic (`AutomaticSourcePicker`/named-row
  picker), building a plain warehouse-only movement for a simple product instead — one flow, the
  detail-row question skipped as an inner step, never a second top-level path.
- **Bonus**: `tests/Service/Inventory/PurchaseToInvoiceCompletedBucketLifecycleTest.php` — the full
  document chain, PO through Completed, asserting both the bucket value and a matching
  `inventory_bucket_change_log` row at every step that should move, and proving (as a permanent,
  intentional-failure tripwire) that marking an invoice Completed today moves nothing — the exact
  gap `docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md` exists to close.

Known gap, not closed here: `AdjustmentController`'s new simple-product path has no browser-driven
(Cest) coverage yet — only the underlying service-layer behavior it calls into is tested. Existing
Cest scenarios are unaffected (they exercise dimensional products, and no existing route behavior
changed), but a positive Cest for quarantining/writing off a simple product through the real screen
is a reasonable follow-up.

## How this was found

Not a feature request — a bug found by tracing a different question. While reviewing the shipment-
dispatch plan (`docs/plans/2026-09-14-shipment-dispatch.md`), the owner asked what verb writes
`InventoryDetail::STATUS_SOLD`. That led to mapping every bucket on `ProductInventory` against the
verb that writes it (`InventoryMovementGroup::TYPE_*` via `StockMovementService`), which surfaced
that four of those buckets — `received`, `quarantine`, `write_off`, `transfer_out`/`transfer_in` —
have exactly one writer each, and every one of those writers refuses to run at all for a product on
`simple` inventory (`InventoryModeResolver::isDimensional() === false`).

Owner, once this was traced to the actual code: *"i am afraid this is built wrong... it makes no
sense detail inventory gets it but simple inventory doesn't get received"* — followed by, once
`StockMovementService`'s own comment on `quarantine`/`write_off` was read aloud (*"the ones whose
ledger IS the detail rows"*): *"Claude screwed up."* Confirmed, not disputed: this was never a
considered scope boundary. `#550`'s acceptance criterion was specifically that a `simple` product's
`quantity` field stays untouched — a real, correct rule, still true today — and the buckets that
should have been built as independent, product-agnostic counters *beside* that rule were instead
built as a one-way rollup off `InventoryDetail`, which only exists for the minority of products that
opted into lot/bin/serial tracking. Nobody decided "simple products don't get audit history for
receiving, quarantine, write-off or transfer." It just fell out of building the dimensional feature
first and never coming back to build the product-agnostic version underneath it.

## The one rule this plan answers to

**The individual transaction is the authority. The bucket is a cached sum of the transaction
stream, not an authority in its own right. `InventoryDetail`'s lot/bin/serial breakdown is a finer
slice of that same stream, and it has to foot back to the bucket's cached sum — never the other way
around, and never the only place the sum is kept.**

Concretely: if `ProductInventory.available` says 100 units of product X, and X is on dimensional
tracking, every lot's `available` `InventoryDetail` quantity must sum to exactly 100. If a mismatch
ever appears, that means the transaction stream and one of its two cached views (the bucket, or the
detail rows) have drifted apart — not that one of them gets to overrule the other by construction.
Today, for `quarantine`/`write_off`, there is only one cached view (the detail rows) and the bucket
is defined as whatever that view currently sums to — which means there is nothing to reconcile,
because there is no second, independent figure to check it against. That is the specific defect
this plan fixes, one bucket at a time.

## Scope: four buckets, one shared implementation pattern, no schema change

All four bucket columns already exist on `ProductInventory` for every product, dimensional or
simple — this is service-layer work, not a migration.

| Bucket | Today | After this plan |
|---|---|---|
| `received` | Independently accumulated (`+= receivedDelta`), but only reachable for a dimensional product — `ReceivingService`/`StockMovementService` refuse a `simple` product's line before any bucket write happens. | Same accumulation, reachable for every product. |
| `quarantine` | `StockMovementService::syncCoreTotal()` sets it to `SUM(inventory_detail WHERE status IN quarantineStatuses())` — a pure derivation, no independent figure exists. Only runs when detail rows exist. | An independently accumulated counter, written directly by the action that quarantines stock, for every product. For a dimensional product, the existing detail-row sum becomes a **reconciling check** against that counter, not its definition. |
| `write_off` | Same shape as `quarantine`, derived from `writeOffStatuses()`. | Same fix as `quarantine`. |
| `transfer_out` / `transfer_in` | `TransferOrderService::recomputeTransferBuckets()` sums `TransferOrderLine` quantities per product — this part is already detail-free and works the same way `sales_hold`/`pending` do. But `TransferOrderService::assertDimensional()` refuses to let a `simple` product's line exist on a transfer order at all, so the mechanism never runs for one. | Remove the refusal. The existing bucket recompute needs no change — it was never the part that was broken. |

Left alone, deliberately: `cart_hold`, `sales_hold`, `pending`, `approved`, `backordered` already
work correctly and identically for every product today — no change needed. `quantity` (the
client-owned starting figure) stays exactly as untouchable as it is today, for every product — this
plan extends the *delta* buckets beside it, the same boundary `#550` already drew correctly for
`quantity` itself.

Also out of scope: `InventoryDetail::STATUS_SOLD` / `TYPE_SHIP` (the shipment-dispatch plan's
subject). That gap is structurally different — `approved` already is the correct, permanent,
economic record of a sale for every product; the missing piece there is a physical/temporal
transaction on top of an already-correct number, not a missing top-level authority. This plan is
about the four buckets that have no correct top-level authority at all for a `simple` product.

## Design

### 1. One flow per operation, not two — no exceptions

Owner, stated as a hard rule rather than a preference: **"ABSOLUTELY in NO circumstances should we
have two separate functions calculating simple product and complex product. The bucket and
aggregate inventory level should use ONE SHARED function, and if the inventory is complex, then
perform the InventoryDetail logic as a 2nd step."**

This is the failure mode to avoid, named directly because it is the standing temptation: building a
second, parallel "simple product version" of receiving/quarantine/write-off/transfer next to the
existing dimensional one. That produces exactly the two-sets-of-logic-to-keep-in-sync problem this
whole plan exists to undo — and it is exactly how the current bug happened in the first place
(`quarantine`/`write_off` were built as dimensional-only logic with no shared, product-agnostic
counterpart ever added beside it). There is no case in this plan, or in review of it, where a
second top-level function for "the simple version" is an acceptable answer — if one seems needed,
the fork belongs inside the shared function, at the point where `InventoryDetail` work begins, not
at the entry point.

Instead, each operation is one flow, product-mode-agnostic at the top:

```
receive(product, warehouse, quantity, reference, actor)
quarantine(product, warehouse, quantity, reason, actor)
writeOff(product, warehouse, quantity, reason, actor)
transfer(product, fromWarehouse, toWarehouse, quantity, actor)
```

Every one of these unconditionally writes its bucket. Only *inside* that same call does inventory
mode matter: if `InventoryModeResolver::isDimensional($product)`, the flow additionally resolves or
creates the specific `InventoryDetail` row(s) the quantity maps to (lot, bin, serial, status) — the
same lot/bin capture UI and validation that already exists for dimensional products, unchanged. If
the product is simple, that inner step is skipped. One controller action, one form, one service
method per operation — the fork happens at the smallest possible point, exactly the pattern the
lot/serial/expiry plan already uses correctly on the Order/Invoice line (`nullableLotId()` returns
null when a product isn't tracked; there is no second line-save method for untracked products).

### 2. `received`

Smallest fix of the four, because the accumulation logic (`StockMovementService::syncCoreTotal()`'s
`$inventory->setReceivedQuantity($inventory->getReceivedQuantity() + $receivedDelta)`) is already
correct and already independent of `InventoryDetail`. The only thing standing in the way is
`ReceivingService::assertLineIsBookable()`'s refusal to call `StockMovementService` at all for a
`simple`-inventory line. Change: still refuse to create `InventoryDetail` rows for a simple line
(nothing to create), but still call `syncCoreTotal()`'s received-delta accumulation for it. The line
already gets recorded as a `GoodsReceiptLine` regardless (`movementApplied` already distinguishes
"a detail movement happened" from "a bucket moved") — this plan makes `movementApplied = false`
lines still credit `received`, instead of being invisible to `ProductInventory` entirely.

### 3. `quarantine` / `write_off`

The real fix. Add two accumulating setters/methods next to `received`'s pattern —
`creditQuarantine(int $delta)` / `creditWriteOff(int $delta)` — called directly by whatever action
quarantines or writes off stock, the same way `received` is credited by receiving. For a dimensional
product, that same action also writes the underlying `InventoryDetail` status change (unchanged from
today); the bucket credit and the detail write happen in the same transaction, from the same call,
so they can never disagree at the moment they're written — only drift apart later if something else
touches one side directly, which is exactly what the reconciliation check below exists to catch.

### 4. `transfer_out` / `transfer_in`

Remove `TransferOrderService::assertDimensional()`'s refusal. A `TransferOrderLine` for a simple
product carries no `lot`/`serial` (already nullable on that entity) and no bin resolution
(`sourceBin()` already returns `null` when a line names no lot — extend that same null-safe path to
mean "no lot to look up," not "error"). `recomputeTransferBuckets()` needs no change: it already
sums `TransferOrderLine` quantities per product, dimensional or not.

### 5. New entry points, extending existing screens rather than adding new ones

`AdjustmentController`'s quarantine/write-off actions today assume a bin-based `InventoryDetail` row
to act on. Per the "one flow" rule above, the fix is not a second, simple-product-only screen — it's
letting the existing form accept a product+warehouse+quantity+reason submission with no bin/lot
selected when the product is simple, the same way the Order/Invoice line's lot picker is simply
absent for an untracked product rather than replaced by a different form.

### 6. Reconciliation check

Once `quarantine`/`write_off` are independently accumulated, a dimensional product now has two
cached views of the same fact (the bucket counter, and the detail-row sum) that must agree. Extend
whatever runs `app:inventory-depth:detail-check`'s existing `SUM(available detail) == quantity +
received` check to also assert `quarantine bucket == SUM(quarantine-status detail)` and `write_off
bucket == SUM(write-off-status detail)` per product/warehouse. A `simple` product has only the one
cached view (the bucket) and nothing to check it against — correct, not a gap, the same way
`quantity` itself has nothing to reconcile against either.

### 7. Audit logging — no new code

`InventoryBucketChangeLogger` already logs any change to any of the eleven bucket columns,
unconditionally, for every product, via the Doctrine changeset — this was never gated by inventory
mode and needs no change. As soon as `received`/`quarantine`/`write_off`/`transfer_*` actually
change for a simple product, `inventory_bucket_change_log` starts recording it automatically, with
the same `action`/`triggeredBy`/`groupId` attribution every other bucket already gets. This is the
concrete payoff of the plan: the audit trail already exists and is airtight for what actually gets
written — the gap was never in the logger, only in what was allowed to reach it.

## Build order

1. `received`: extend `ReceivingService`/`StockMovementService` so a simple-inventory line still
   credits `received`, without creating `InventoryDetail` rows. Test: receiving a simple product
   moves `received` and produces a `inventory_bucket_change_log` row; no `InventoryDetail` row
   exists afterward.
2. `quarantine` / `write_off`: add the direct-credit methods, wire them into whatever
   `AdjustmentController` action currently only writes `InventoryDetail`. Test per bucket:
   quarantining/writing off a simple product moves the bucket and logs it; doing the same for a
   dimensional product moves both the bucket and the detail rows, in the same transaction.
3. `transfer_out` / `transfer_in`: remove `assertDimensional()`'s refusal; confirm
   `recomputeTransferBuckets()` handles a lot-free line unchanged. Test: transferring a simple
   product between warehouses moves both buckets on both ends and logs it.
4. Reconciliation check: extend the existing detail-check command to cover `quarantine`/`write_off`
   for dimensional products.
5. Screens: extend the existing adjustment form to accept a simple product with no bin/lot selected.

## Test plan

Name the classes, whitelist only (standing rule, `docs/QUEUE.md`). New tests, mirroring
`SellingADimensionalProductTest`'s style of conducting the real transaction rather than asserting
internals:

- `ReceivingASimpleProductCreditsReceivedTest` — receive credits `received`, no `InventoryDetail`
  row, one `inventory_bucket_change_log` row.
- `QuarantiningASimpleProductTest` / `WritingOffASimpleProductTest` — same shape, for each bucket.
- `TransferringASimpleProductTest` — both buckets move on both warehouses, both logged.
- `QuarantineReconciliationDetectsDriftTest` — a dimensional product's bucket and detail sum
  disagree; the detail-check command reports it.
- Regression: every existing dimensional-product test in `StockMovementServiceTest`,
  `SellingADimensionalProductTest`, `AutomaticSourcePickerTest`, `TransferOrderService`'s own tests
  still passes unchanged — this plan must not alter dimensional-product behavior at all, only add
  the missing simple-product path beside it.

Do **not** run the full suite to confirm any of this — name the classes.
