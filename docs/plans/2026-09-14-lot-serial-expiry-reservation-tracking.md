# Lot/serial/expiry: treated the same way the aggregate bucket system already treats quantity

Owner: "Do we have TWO batch on sales docs?" — yes: `InventoryDepthBundle`'s real ledger
(`InventoryLot`/`InventoryDetail`), and a grandfathered free-text `batch` string on
`SalesOrderLine`/`InvoiceLine` predating it, with no relation between the two. This plan makes the
line-level UI real, backed by the same reservation mechanism the aggregate system already uses —
no new mechanism, no new consumption event.

## The one rule this whole plan answers to

**Detailed/advanced inventory tracking must reconcile to the overall inventory numbers, which do
not change today.** This is more detail added on top of an already-correct system, not a
redesign of it — the same relationship `InventoryDetail` itself already has to
`product_inventory` (`SUM(inventory_detail.quantity) WHERE status='available' ==
product_inventory.quantity`, stated in `InventoryDetail`'s own class docblock). Every piece below
is additive and reconciling; nothing changes how the aggregate numbers or the existing bucket
system already behave. If any step below starts to look like it's altering aggregate behavior
rather than adding a lot/serial-scoped view onto the same numbers, that step is wrong and should
be cut, not carried through.

## Context

The overall inventory number and bucket system is correct and stays exactly as it is. The only gap
is that `SalesOrderLine`/`InvoiceLine`'s `batch` column — a grandfathered placeholder from before
`TrackingPolicy`/`InventoryLot`/`InventoryDetail` (`InventoryDepthBundle`) existed — is a free-text
string with no FK and no relation to anything, so it plays no part in the real availability math.
Lot/serial/expiry need to go through **the same reservation mechanism the aggregate system already
uses**, not a new one.

The pattern to mirror, confirmed by reading it directly (`ProductInventory::getAvailableQuantity()`,
`src/Entity/ProductInventory.php`): available is a **pure subtraction** — "Starting Inventory minus
every hold bucket" — with holds tracked as rows in `OrderInventoryReservation`/
`InvoiceInventoryReservation` and reconciled by `InventoryReservationReconciler`
(`src/Service/Inventory/`) whenever a `SalesOrder`/`Invoice` is written. There is no separate
"consumption" or "sold" write anywhere in this model — availability is always computed by
subtraction, never decremented by a standalone event. The equivalent for a specific lot should be
the identical shape: `SUM(inventory_detail.quantity WHERE lot = X AND status = 'available') -
SUM(reservations held against lot X)`. Nothing new invented, nothing new to consume — the same
subtraction, one dimension narrower.

## Design

### 1. Reservation schema: widen, don't replace

Add nullable `lot` (FK → `InventoryLot`) and `serial` (string) columns to the existing
`OrderInventoryReservation`/`InvoiceInventoryReservation` (`src/Entity/`) — not a new table. This
matches the "eight tables stay" ruling (`docs/plans/2026-08-21-inventory-deltas-incoming-and-
received.md`) exactly: that ruling forbids merging these tables, not adding a column to one, and
it's the same shape `InventoryDetail` itself already uses (lot/serial as nullable columns on one
table, not a split ledger).

Both unique constraints need the NULL-collapsing index technique `InventoryDetail` already uses
(`uniq_inventory_detail` on `COALESCE(location_id,0), COALESCE(lot_id,0), COALESCE(serial,'')`) — a
plain Doctrine `UniqueConstraint` won't collapse NULLs consistently:

- `OrderInventoryReservation`: currently `(order, product, warehouse, bucket)` — widen to include
  `COALESCE(lot_id,0), COALESCE(serial,'')`.
- `InvoiceInventoryReservation`: currently `(invoice, product, warehouse)` — no `bucket` in the key
  today (an invoice only ever holds one bucket at a time). Adding lot/serial here needs `bucket`
  added to the key too, since a lot-specific row and a generic row can no longer share the
  unqualified 3-column key.

### 2. `InventoryReservationReconciler`: widen the diff key, don't change what it does

`src/Service/Inventory/InventoryReservationReconciler.php` already diffs (document, product,
warehouse, bucket) rows on every `SalesOrder`/`Invoice` write, via `InventoryReconciliationSubscriber`
— no new trigger needed, this already fires on every relevant save. The gap: it currently pools
every line's `(product, warehouse)` into one key **before** the diff runs
(`targetQuantities()`/`InventoryReservationSubject::heldLines()`) — line identity, and therefore any
lot/serial a line carries, doesn't survive to the comparison.

Widen the key at both loops (previous-ledger read, target-quantity build) from `product|warehouse`
to `product|warehouse|lot|serial`, and extend `InventoryReservationSubject`'s contract so
`heldLines()` carries the line's chosen lot/serial through. Same three implementations get the same
change: `SalesOrderReservationSubject`, `SalesOrderBackorderReservationSubject`,
`InvoiceReservationSubject` (all `src/Service/Inventory/`).

Gate the lot-aware path on **both** `TrackingPolicy::tracksLotsOutbound()/tracksSerialsOutbound()`
*and* `InventoryModeResolver::isDimensional($product)` — confirmed these are currently uncoupled (a
product can carry a tracking policy with no `InventoryDetail` ledger behind it), and nothing
existing gates on that composite condition today.

### 3. Lot-level availability: the same subtraction, one dimension narrower

New method on `InventoryDetailRepository` (`modules/InventoryDepthBundle/src/Repository/`),
mirroring `InventoryDetailRepository::availableTotal()`'s own shape (product+warehouse grain today,
no lot filter):

```php
public function availableForLot(InventoryLot $lot): int // SUM(quantity) WHERE lot = :lot AND status = 'available'
```

"How much of Lot A is left" = `availableForLot($lot) - (SUM of OrderInventoryReservation +
InvoiceInventoryReservation rows held against that lot)` — the identical subtraction
`getAvailableQuantity()` already does for the whole product, scoped to one lot. No new status, no
new terminal state, no separate consumption write.

`AdminOrderStockValidator` (`src/Service/Inventory/AdminOrderStockValidator.php`) gains this
lot-scoped check alongside its existing aggregate one: refuse (same existing refusal shape) a
reservation that would exceed what a specific lot has actually available, netting the reserving
document's own existing hold the same way `BackorderSplitResolver::ownHoldFor()` already nets
aggregate holds.

### 4. Backorder release needs NO code changes of its own — settled, not just assumed

`BackorderReleaseService` keeps doing exactly what it does today — promoting backordered quantity
into `sales_hold`, purely aggregate, by flipping the reservation's `bucket` field. Nothing here
gains any new knowledge of lots, serials, or capture rules. A released line has no lot on it, the
same way any other freshly-drafted line has no lot until a human picks one via the picker (section
5) — typically at Invoice time, which is exactly the moment `TrackingPolicy` documents as when the
identity is meant to be captured ("capture the identity on the way out"). The reconciler from
section 2 picks up a real lot the next time a human edits the line, same as every other line,
backordered or not. No FEFO auto-assignment, no new decision point inside the release path,
nothing invasive added to a service that has never made this kind of judgment call before.

That said, the *existing* bucket flip this service already does is load-bearing for section 5
below — release isn't a no-op the rest of the plan can ignore, it's the exact event that lifts the
backorder capture exemption. The service itself just doesn't need to know that; see section 5 for
why.

### 5. Invoice mandatory capture — exempt while backordered, enforced the instant it isn't

This is the piece that actually enforces the owner's original capture rule: **a non-draft Invoice
must error if a line whose product requires lot/serial/expiry on the OUT direction doesn't have
that data filled in.** Copying the SO line's data verbatim is fine on a draft/unsaved invoice —
nothing final has happened yet — but finalizing an invoice requires a human to actually say what's
being invoiced, not carry over a placeholder.

**The backorder carve-out, and why it needs no new coupling:** a line still held against the
`backordered` bucket has no real stock to pick a lot from — there's nothing to fill in yet — so it
must be exempt from the mandatory check until real stock is behind it. The moment
`BackorderReleaseService::release()` does what it already does today (flip the reservation from
`backordered` to `sales_hold` — unchanged, section 4), the exemption needs to disappear: the same
invoice, if still unfinalized, now needs the data before it can be saved as non-draft.

This needs no new field, no new flag, and no new coupling into `BackorderReleaseService`. The
reservation's `bucket` — already `backordered` vs `sales_hold`, already flipped by
`BackorderReleaseService` today, untouched by this plan — is the exact signal the mandatory-capture
check needs: exempt while the line's held bucket is `backordered`, mandatory otherwise. This is
the concrete shape of "division of labour": `BackorderReleaseService` owns exactly one fact (is
this line's hold still backordered) and keeps not knowing anything about capture rules; the
invoice-side validator owns exactly one decision (does this line need capture data before I can
finalize) and reads that one fact to make it. Neither needs to know the other exists, and the
exemption tracks itself for free off a state transition that already happens today.

**Where this lives:** wire the mandatory check into wherever non-draft Invoice save already
validates today (`InvoiceController`'s save handling / `OrderInvoicingService` — the two files
already carrying invoice-save logic). Exact call site is an implementation detail, not pinned down
further here, same as the picker UI itself.

## Why this already gives a live, exact answer — no gap, nothing left to close

Owner: "There's legit no gap!! If your starting inventory in lot A are all deducted to zero, it is
closed. It's like a bank account balance. You minus minus minus to 0 you're done. You don't go
back and erase the beginning balance." Correct, and worth stating plainly here rather than leaving
an earlier, wrong instinct in this plan: a live remaining balance for a lot does **not** need a
real OUT-side movement write. The subtraction model has no gap — it works the same way a bank
balance is always exact without ever rewriting the opening figure: deposits minus every debit,
live, is the current balance.

Applied here: `availableForLot($lot)` (section 3) is the deposit side — what receiving actually
put in, permanent, untouched history. Every hold against that lot — `sales_hold`, `backordered`,
and now the `sold` reservation this plan adds (sections 1-2) — is a debit.
`availableForLot($lot) - SUM(every currently active hold against that lot)` is the exact live
remaining balance at every instant, the same way `ProductInventory::getAvailableQuantity()` is
already exact for the whole product today. If holds against a lot sum to everything it has,
remaining hits zero the moment the last hold lands — already correct, already complete, nothing
further to write.

This depends on one discipline, already true of the aggregate system today and not a new rule
this plan invents: **a given claim on inventory sits in exactly one bucket at a time.** A line
moves from `backordered` to `sales_hold` to `sold` — its hold transitions, it doesn't accumulate
across three buckets at once. Section 1-2's schema and reconciler widening has to preserve that
same one-bucket-at-a-time discipline for lot/serial, or the subtraction stops being exact — not
because the subtraction model has a gap, but because double-holding the same unit would.

Owner, on why the buckets work at all: "the buckets are literally sum cache anyways. When you're
complaining about buckets, you're essentially complaining about cache calc." Exactly right —
`InventoryReservationReconciler` is a cache-refresh, not a ledger: the reservation rows are a
materialized sum of what the live documents currently claim, recomputed and diffed on every write.
Section 2's key-widening is cache invalidation at finer granularity, nothing more. It's the same
relationship a GL summary has to its subledger: the reservation tables are the subledger detail
(each hold tied to a real document/product/warehouse/lot/serial), the aggregate `available` number
is the GL-summary view that always has to foot to it.

## How this reconciles with Shipment

Shipment (`docs/plans/2026-09-14-shipment-dispatch.md`, a separate agent's work) isn't a
gap-closer, and there's no gap for it to close (see above). It's also not a second, independent
deduction stacked on top of `sold` — the same one-bucket-at-a-time discipline applies: if a
shipment is the bucket a `sold` claim transitions into at ship time, it **replaces** that hold on
the lot, it doesn't add beside it. Subtracting both at once would double-hold the same unit and
undercount what's actually still there — the exact failure mode the discipline above exists to
prevent.

Exactly how that handoff gets written — does creating a Shipment release the
`InvoiceInventoryReservation` on the same lot, the same way an Invoice's own reservation already
has to release whatever `OrderInventoryReservation` preceded it — is Shipment's own implementation
question, not this plan's. But the constraint itself belongs here: it's what this plan's
reservation rows have to hand off correctly the moment Shipment starts reading them. See the
matching note added to `docs/plans/2026-09-14-shipment-dispatch.md`.

And it's partial, not all-or-nothing, at every one of those handoffs, the same way it's already
partial today between SO and Invoice: an Invoice can take all or some of a SO line's held
quantity (already true, already how `InvoiceReservationSubject` works), and a Shipment can take
all or some of an Invoice line's `sold` quantity — one more bucket layer in the same chain, not a
special case. Whatever quantity of a lot hasn't yet transitioned to the next bucket simply stays
in the previous one, still correctly subtracted, still live, still exact.

## Explicitly out of scope

- **Shipment itself** — a separate agent's work; this plan only reconciles with it (above), it
  does not implement any part of it.
- **A new "sold"/terminal consumption event anywhere on the sell side.** Confirmed there is no
  aggregate-level precedent for one — the whole model is subtraction-based holds, never a
  standalone decrement. Introducing one for lot/serial specifically would be adding something the
  aggregate system doesn't have, not mirroring it.
- **VendorBill/PurchaseOrder's own lot handling** — sell side only here; mirror to the buy side as
  a separate follow-up once this is proven, not built in parallel.
- **The old `SalesOrderLine::$batch`/`InvoiceLine::$batch` string columns** — untouched, no
  migration. They become legacy once the real reservation exists; removing them is a separate,
  later decision.

## Files touched

- **Schema**: `OrderInventoryReservation.php`, `InvoiceInventoryReservation.php` — new nullable
  `lot`/`serial` columns, widened NULL-collapsing unique constraints (migration-level).
- **`src/Service/Inventory/InventoryReservationReconciler.php`** — widened diff key, both loops.
- **`src/Service/Inventory/InventoryReservationSubject.php`** (interface) +
  `SalesOrderReservationSubject.php`, `SalesOrderBackorderReservationSubject.php`,
  `InvoiceReservationSubject.php` — carry lot/serial through `heldLines()`.
- **New**: `availableForLot()` on `InventoryDetailRepository`
  (`modules/InventoryDepthBundle/src/Repository/`).
- **`src/Service/Inventory/AdminOrderStockValidator.php`** — lot-scoped refusal alongside the
  existing aggregate one.
- **`BackorderReleaseService.php` — untouched.** No changes needed (see section 4); its existing
  bucket flip is what section 5's exemption reads, but the service itself gains no new code.
- **Non-draft Invoice save validation** (`InvoiceController` / `OrderInvoicingService`) — new
  mandatory lot/serial/expiry check, exempting lines whose reservation `bucket` is still
  `backordered` (section 5).
- **UI**: the Order/Invoice line's Batch cell (currently free-text) becomes a real lot/serial
  **picker** reading actual available `InventoryLot` rows for the product+warehouse — not a
  free-text box any more. Exact picker UI is an implementation detail against the existing
  `sales_line_row.html.twig`/`app.js` structure, not pinned down further here.

## Verification

- New unit tests for the widened reconciler covering: two orders cannot both hold more of one lot
  than it has; releasing an order's hold frees the lot for another order; a formerly-backordered
  line that later has a lot picked on it (at Invoice time, same as any other line) reconciles
  correctly with no special-casing anywhere in the path.
- New test proving the one-bucket-at-a-time discipline holds for lot/serial: an order's hold on a
  lot is released the moment its invoice's own hold on that same lot is created, so the two never
  both subtract against the same unit.
- New test for the section 5 exemption: a non-draft Invoice save succeeds without lot/serial/expiry
  data on a line still held `backordered`; after `BackorderReleaseService::release()` flips that
  hold to `sales_hold`, saving the same invoice as non-draft without that data now fails, and
  filling it in makes it succeed.
- `php bin/console doctrine:schema:validate` clean after the migration.
- Confirm via `Stock by Product` (`/admin/bundles/inventory-depth/stock/product/{id}`) — the
  existing, already-correct report — that reserving a lot on an order actually shows up as held
  there, which is the concrete, observable proof this answers "what SKU 234 Lot A do we have
  left" correctly.

## Status

Implemented (2026-09-14). All sections built:

- **Section 1**: `lot_id`/`serial` added to `OrderInventoryReservation`/`InvoiceInventoryReservation`,
  widened NULL-collapsing unique indexes (`Version20260919210000`). One correction to this section as
  written: `InventoryLot` belongs to `modules/InventoryDepthBundle`, which core may not hold a
  compile-time reference to (the same rule `InventoryBucketChangeLog::$groupId` already lives under),
  so `lot_id` is a plain nullable integer in the mapping with a real FK only at the migration/DB level
  — not a Doctrine association. The same widening was applied to `SalesOrderLine`/`InvoiceLine`
  (needed but not named in the original "Files touched" list — `heldLines()` has to read the chosen
  lot/serial from somewhere, and this is where it lives, parallel to `$batch`).
- **Section 2**: `InventoryReservationReconciler`'s diff key widened to `product|warehouse|lot|serial`
  in both loops, gated on the composite `TrackingPolicy::tracksLotsOutbound()/tracksSerialsOutbound()`
  AND `InventoryModeResolver::isDimensional()` check, in one place (`heldLotAndSerial()`) shared by all
  three subjects.
- **Section 3**: `InventoryDetailRepository::availableForLot()` added. `AdminOrderStockValidator`
  gained a lot-scoped shortfall pass (`lotShortfalls()`), reusing the existing `StockShortfall`/
  override machinery unchanged. Crossing the same core/bundle boundary as section 1, this went through
  a new seam (`App\Contract\Inventory\LotAvailabilityProviderInterface` +
  `LotAvailabilityResolver`, implemented by `InventoryDepthBundle\Inventory\LotAvailabilityProvider`)
  rather than a direct call, mirroring `DimensionalInventoryProviderInterface`'s existing pattern.
- **Section 4**: `BackorderReleaseService` — untouched, exactly as specified.
- **Section 5**: `MandatoryCaptureGuard` added and wired into `Invoice::assertRoomToIssue()`, reading
  the exemption straight off the invoice line's `SalesOrderLine::getBackorderedUnits()` rather than
  querying the reservation ledger — the same fact, cheaper to read.
- **UI**: the free-text batch box stays (out of scope, untouched); a real lot `<select>`/serial input
  sits beside it, gated on the product's tracking mode, backed by a new
  `GET /admin/bundles/inventory-depth/lots/available` endpoint and wired into both the Order and
  Invoice line JS (`syncSellDocLotPicker()`/`populateLotSelect()` in `app.js`).

Verification: `tests/Service/Inventory/LotSerialReservationTest.php`,
`tests/Service/Inventory/AdminOrderStockValidatorLotTest.php`,
`tests/Entity/InvoiceMandatoryCaptureTest.php` — covering the composite gate, the formerly-backordered
line, the one-bucket-at-a-time handoff to an invoice, the lot-scoped shortfall alongside the aggregate
one, and the section 5 exemption/enforcement transition. Full PHPUnit suite diffed against the
pre-change baseline: zero regressions. `php bin/console doctrine:schema:validate` reports the same
pre-existing failures the baseline already has (the `*Log` inverse-side mapping notices, and a DBAL
introspection crash on `uniq_inventory_detail`'s own COALESCE index) and nothing new.
