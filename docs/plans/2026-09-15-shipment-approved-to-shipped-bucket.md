# A real `shipped` bucket, transferred from `approved` at Completed — core only

## Status: implemented (2026-09-15)

Simpler than planned, in the good way. **No separate "transfer primitive" was built** — the plan
below proposed one specifically so `shipment-dispatch.md` could later reuse it for partial
quantities, but building a reusable shape for a caller that does not exist yet is exactly the
premature abstraction this whole session kept finding and removing elsewhere. What actually shipped:

- `InvoiceInventoryBucketResolver::bucketForStatus()` now maps `Completed` to
  `InvoiceInventoryReservation::BUCKET_SHIPPED` instead of `BUCKET_APPROVED`. That is the entire
  mechanism. `InventoryReservationReconciler::reconcile()` already recomputes an invoice's held
  quantity to whatever this method returns for its current status, on every write — the same
  machinery that already moves `pending` → `approved` at Processing. Changing one mapping made it
  move `approved` → `shipped` at Completed too, for free.
- A real discovery this surfaced: `ProductImportService::clearApprovedBalance()` — the only
  mechanism that ever releases `approved` (a recount) — only knew about the `approved` bucket. Left
  alone, a Completed invoice's hold would have become permanently un-recountable the moment it
  became `shipped`, breaking the exact "released by import/recount only" invariant this plan exists
  to preserve. Fixed to clear and stamp `syncedQuantity` on both buckets. `InventoryReservationReconciler`
  needed the equivalent generalization — the synced-quantity baseline now carries forward across
  Processing → Completed the same way it already does within one status, via a named
  `releasedByRecountOnly()` check rather than a second `approved`-only condition.
- New column: `product_inventory.shipped_quantity`, subtracted in `getAvailableQuantity()` alongside
  `approved`, logged automatically by the unmodified `InventoryBucketChangeLogger` (now twelve
  buckets, not eleven).
- No test needed forcing a "simple product" scenario specifically — `InventoryReservationReconcilerTest`'s
  fixture product is `simple` by default (confirmed against `ProductCore`'s own default), so the
  existing, now-updated transfer test already covers it. The mechanism was never gated by inventory
  mode in the first place; it operates on the reservation ledger, never `InventoryDetail`.
- Full regression check: `tests/Entity`, `tests/Service`, `tests/EventSubscriber`, `tests/Command`,
  and the three bundle suites all diffed against the pre-change baseline (git stash) — zero new
  failures anywhere, four pre-existing tests updated to assert the new (correct) behavior instead of
  the old one (`InventoryReservationReconcilerTest` x2, `InvoiceInventoryBucketResolverTest` x1 data
  case + the pre-filter agreement test, `PurchaseToInvoiceCompletedBucketLifecycleTest`'s tripwire).

## Scope, narrowed on purpose

This plan is **core-only and bundle-independent.** It does not create `Shipment`/`ShipmentLine`,
does not touch `InventoryDetail`, and has no concept of a partial shipment. It is exactly one thing:
when an invoice reaches `Completed`, the entire remaining `approved` balance for that invoice
transfers to a new `shipped` bucket. That's it. It works identically whether `InventoryDepthBundle`
is installed or not, and identically for a simple or dimensional product, because it never reaches
the layer that would tell them apart.

Everything about real shipping documents, partial/combined shipments, and the physical
`available → sold` movement for a tracked lot belongs to
`docs/plans/2026-09-14-shipment-dispatch.md`, not here. That plan is what will eventually call the
same transfer primitive this one builds, with a specific quantity instead of "everything remaining"
— see "Built to be reused" below. Splitting it this way removes a real sequencing problem: without
this split, this plan's build order depended on `Shipment`/`ShipmentLine` existing, which in turn
depended on settling that plan's entity shape (invoice moving from header to line, to support
combined shipments) before this one could even start. With the split, this plan has no dependency on
that decision at all.

## This supersedes part of `docs/plans/2026-09-14-shipment-dispatch.md`

That plan's "already proven, not a design question" section concludes no new bucket is needed —
*"approved is already sold"* — reasoning purely from the availability math, which stays correct:
`approved` already removes a unit from `available` permanently and correctly, for every product,
whether or not it ever ships. What that conclusion didn't address: once
`docs/plans/2026-09-15-simple-inventory-bucket-parity.md` established that every tracked condition
needs an independent, aggregate figure for `InventoryDetail` to reconcile against, `sold` turned out
to be the one physical condition with no such figure — and `approved` can't be made to serve that
purpose without conflating two different invoice stages in one bucket.

Owner: *"if approved -> don't have another bucket to move to, the aggregate level log won't be able
to have an entry for the inventory detail to reconcile to. Hence i could not let 2 invoice stages in
1 bucket."*

(The actual `bucket == SUM(sold detail)` reconciliation this motivates only becomes real once
`shipment-dispatch.md` starts producing `sold`-status `InventoryDetail` rows — this plan only builds
the bucket half. Noted here so the motivation isn't lost, not because this plan implements it.)

## The design, settled across several wrong turns worth naming

**Rejected: leave `approved` untouched and add `shipped` as an additional subtracted bucket.**
That double-counts — the same unit gets removed from `available` once by `approved` and again by
`shipped`, reproducing the exact 20-vs-25 bug `SellingADimensionalProductTest` was written to catch.

**Rejected: add `shipped` as a pure audit-only figure, excluded from the `available` subtraction.**
Safe, but doesn't match the actual business fact: once something ships, it doesn't newly become
*more* unavailable — it already was, under a different name. It would also make a later
detail-to-bucket reconciliation meaningless, for the same reason as the next rejection.

**The actual design: `approved` and `shipped` are the same claim, relabeled as fulfillment
progresses — a transfer, not an addition, the same pattern `pending → approved` already uses.**
`approved` decreases by exactly the amount `shipped` increases by. Total held
(`approved + shipped`) never changes for a given invoice line; only which of the two names it
currently sits under does. `available`'s formula is unaffected either way. No double-count, because
nothing is added on top; no release, because the sum never drops.

**Rejected: manually backfill an `inventory_bucket_change_log` row.** Considered and rejected by the
owner directly: *"that is plausible but i dont think is a good idea in this case."*
`InventoryBucketChangeLogger`'s entire value is that a row exists if and only if a real changeset
happened — never authored by hand. `shipped` has to be a real, independently written column so its
log rows are real too.

## What drives the transfer, in this plan specifically

**Only one trigger here: `Invoice`'s transition into `Completed`.** No partial capability, no
admin-chosen quantity — the whole remaining `approved` balance for that invoice moves to `shipped`,
in one shot, in the same transaction as the status write. There is deliberately no smaller unit of
work in this plan; that's what `shipment-dispatch.md` adds later.

### Built to be reused

The transfer itself — "move N units of this invoice line's `approved` hold to `shipped`" — should be
written as its own quantity-parameterized primitive (on `InventoryReservationReconciler` or a
sibling service, not inlined into the Completed-transition handler), even though this plan only
ever calls it with "the full remaining balance." `shipment-dispatch.md` will later call the exact
same primitive with an admin-chosen partial quantity when a `ShipmentLine` is recorded early. One
function, two callers, the parity plan's "no second implementation" rule applied across plan
boundaries rather than just within one.

## Schema

- `ProductInventory`: new `shippedQuantity` column, same shape as the other ten bucket columns,
  added to `InventoryBucketChangeLogger::BUCKET_FIELDS` — no new logging code, same as every other
  bucket.
- `InvoiceInventoryReservation`: `BUCKET_SHIPPED` joins `BUCKET_PENDING`/`BUCKET_APPROVED` as a value
  of the existing `bucket` string column — no schema change needed for this half.
- `ProductInventory::getAvailableQuantity()`: add `shippedQuantity` to the subtraction, alongside
  `approvedQuantity`.

## Build order

1. Migration: `shippedQuantity` on `ProductInventory`; `BUCKET_SHIPPED` constant on
   `InvoiceInventoryReservation`; wire into `InventoryBucketChangeLogger::BUCKET_FIELDS` and
   `getAvailableQuantity()`.
2. The transfer primitive: quantity-parameterized, moves an invoice line's reservation from
   `approved` to `shipped`, partial-quantity-aware from day one even though this plan's only caller
   passes the full amount.
3. Wire `Invoice`'s transition into `Completed` to call it with "everything still in `approved` for
   this invoice."
4. No test changes needed to `SellingADimensionalProductTest` or any other existing dimensional test
   — none of them transition an invoice to `Completed`, so none of them are affected by this plan.
   (`shipment-dispatch.md` is what will eventually need to update that test, once it adds a caller
   that reaches this same transfer through an early, partial `ShipmentLine`.)

## Test plan

- `CompletingAnInvoiceTransfersApprovedToShippedTest` — Processing → Completed moves the full
  `approved` balance to `shipped`; `available` is unchanged across the transition.
- `CompletingASimpleProductInvoiceTest` — same behavior, no `InventoryDetail` involvement, proving
  this plan needs no bundle.
- `TheTransferPrimitiveIsPartialQuantityAwareTest` — call the primitive directly with less than the
  full held amount; confirm it splits correctly (this is the test that protects
  `shipment-dispatch.md`'s future reuse of it, written now while the shape is fresh).

Do **not** run the full suite to confirm any of this — name the classes.
