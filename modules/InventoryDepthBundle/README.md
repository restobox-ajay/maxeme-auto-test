# InventoryDepthBundle

Issue #550. Adds a layer beneath `product_inventory.quantity` that says **where that number
physically is** — which bin, which lot, which serial, what condition — **without changing what the
number means.**

```
today:    product_inventory(product, warehouse).quantity = 47

after:    product_inventory(product, warehouse).quantity = 47      ← unchanged, still the truth
          inventory_detail: A-12 / lot L1 / available  30
                            B-03 / lot L2 / available  17
                            B-03 / lot L1 / damaged     2          ← not counted
```

Per product, opt-in. A client with one warehouse and no bins never sees any of it.

## The invariant

```
product_inventory(product, warehouse).quantity
  == SUM(inventory_detail.quantity) WHERE status = 'available' AND warehouse = that warehouse
```

Only `available` counts. `in_transit`, `damaged`, `quarantine`, `expired`, `sold`,
`scrapped`, `lost` and `returned` do not — they are physically present or accounted for, but not
sellable, and the core number has never included them.

Maintained in the same transaction as the movement that changes it, by
`Movement\StockMovementService`. That is only possible because it is one database, and it is the
whole reason this is safe.

## How the layers stack

They stack; they do not compete.

| | |
|---|---|
| `quantity` | the physical total — what is in the building |
| `cartHold` / `salesHold` / `pending` / `approved` | **claims** against it — who has spoken for some |
| `inventory_detail` | **where the physical total is** — bin, lot, serial, condition |

Detail rows reconcile to `quantity`, never to `getAvailableQuantity()`. The bucket machinery is
untouched by this bundle and keeps behaving exactly as it does now.

## Data outlives the bundle

**Core reads nothing this writes.** Core reads `product_inventory.quantity`; it never reads
`inventory_detail`. That works because **`quantity` is maintained, never derived on read** — every
movement writes the detail rows and the core total in the same transaction, so the number is always
sitting in `product_inventory`, correct, whether or not anything is looking at the breakdown behind
it.

So removing this bundle loses the breakdown, not the number. A product at 47 across three bins is
still a product at 47; it just goes back to an editable field. **No precondition, no conversion, no
migration, no data loss.**

Delete `modules/InventoryDepthBundle/` and:

- `config/bundles.php`, `config/services.yaml` and `App\Routing\BundleControllerLoader` all glob one
  fewer directory;
- `App\Service\Inventory\InventoryModeResolver` finds no provider, so **a stored `dimensional` reads
  back as `simple`** — the column is never a lockout;
- every quantity field is editable again, and every number is exactly what it was.

The `inventory_mode` flag stays in **core** for one reason: core has to know whether to render an
editable quantity field, and it cannot ask the bundle that without depending on it. But
`dimensional` only *means* anything while this bundle is installed and Active.

The asymmetry falls straight out of the invariant:

| | needs |
|---|---|
| `simple → dimensional` | an **opening balance movement** — detail rows have to come from somewhere |
| `dimensional → simple` | **nothing at all** — the total is already there |

Neither direction changes the number. The opening balance is read from the very total it replaces.

## The three rules

1. **A detail row is a unique `(product, warehouse, location, lot, serial, status)` combination. Its
   identity never changes — only its `quantity` moves.** Rows are never deleted; a row at 0 is
   history.
2. **A movement moves quantity from one detail row to another.** `from = NULL` means entering the
   system, `to = NULL` means leaving it. Serialized and non-serialized use the identical code path;
   a serial simply never exceeds 1.
3. **Sold, scrapped and lost rows keep their warehouse but drop their location.** That is what makes
   "which site shipped it" and "where was it lost" answerable.

## Screens

All under `/admin/bundles/inventory-depth`. Filter state lives in URL GET params on every list, so a
copied URL reproduces the exact result.

| Screen | |
|---|---|
| Stock by product | the breakdown for one product, with the total reconciling **visibly** to `product_inventory.quantity` |
| Stock by location | what is in a bin right now, across every product — what a cycle count is run from |
| Stock adjustment | **pick what happened**; the movement is derived from it. One movement group per submission |
| Bins | CRUD over `warehouse_location`, plus bulk creation of an aisle/rack/shelf range |
| Lots | CRUD plus an expiring-soon window; **code and expiry always shown together** |
| Serial lookup | where serial X is now, and its full history |
| Movement history | the ledger, filterable by product, warehouse, bin, lot, date, type and actor |
| Cycle count | count a bin; differences become adjustment movements with a count reason |
| Break a Case | declare that one case SKU holds N of a unit SKU, and break or rebuild cases. **Two SKUs, one operation** |
| Mode toggle | on the **product edit** screen, with the opening balance on switching |

## Breaking a case is not a unit of measure (#22)

A distributor's case and its unit are **two SKUs**: two `product_core` rows, two
`product_inventory` balances. Cutting a case open moves stock from one to the other, and that is a
physical event — it gets a date, an actor and a ledger entry, exactly like a transfer.

A **unit of measure** is a different thing entirely and lives in core. It re-expresses a quantity
*within one SKU*: `40 BOX-12` of SKU-X is `480 EA` of SKU-X. One balance, nothing moved, nothing
recorded. `App\Entity\UnitOfMeasure` and `App\Service\Uom\LineDenomination` own it, and the
denomination never reaches this layer at all.

**Merging the two would be the worst available outcome**, because a unit conversion that could move
stock between products means a mis-picked dropdown silently relocates inventory. NetSuite draws the
same line: same item, use a unit; different items, use an assembly build/unbuild. So:

```
inventory_pack_rule          1 CASE-COLA-12 = 12 x COLA-EA      (case_product_id is UNIQUE)
inventory_movement_group     type = pack_convert, one reason, one actor, one date
  inventory_movement           3 CASE-COLA-12 out   (to_detail_id NULL)
  inventory_movement          36 COLA-EA in         (from_detail_id NULL)
inventory_pack_conversion    direction, cases, units_per_case frozen, case_cost, unit_cost
```

Two movements under one group, because an `inventory_movement` row carries one `product_id` and
should: the two balances are separate numbers and each moves on its own record. No new ledger, no
second movement table.

**Directional.** `case_product_id` is unique and `unit_product_id` is not: "what does this case
hold" has exactly one answer, while "which case does this unit belong to" legitimately has several —
the same bottle ships in a twelve and in a twenty-four.

**Cost is proportional, and the case is the anchor in both directions.** A case costing `24.000000`
that holds twelve gives units of `2.000000`. Where it does not divide evenly the per-unit figure is
rounded half-up at six places and the remainder is a recorded residue, so a break followed by a
rebuild of the same cases returns the value *exactly* rather than losing a fraction every round
trip. `Movement\PackCost` does the arithmetic in integer micro-units, because bcmath is not
installed and a float cannot promise "exactly". **No conversion ever writes a product's cost price** —
the derived figure lives on the operation that derived it, and inventory valuation stays parked
(#600).

Refused, each with a sentence the operator can act on: a pack whose two products are the same
product, a pack of one, a chain that closes a loop, a lot- or serial-tracked side (an
`inventory_lot` row belongs to one product, so the identity has no row to cross into), rebuilding a
pack nobody marked rebuildable, and more cases than the warehouse holds. Every one of them is raised
before `StockMovementService` opens its transaction, so *refused* means neither balance moved.

## Stock adjustment is reason-first (#585)

The screen asks **what happened**, and derives the movement. The operator never sees or picks a
status, and there is no movement-type dropdown.

| reason | from | to | bucket effect |
|---|---|---|---|
| Stock found | — (enters the ledger) | available | `received` + |
| Reverse a write-off | the reversed entry's `to` | available | `write_off` − |
| Damaged | available | damaged | `write_off` + |
| Spoiled / expired | available | expired | `write_off` + |
| Scrapped | available | scrapped | `write_off` + |
| Lost / shrinkage | available | lost | `write_off` + |
| Put on hold | available | quarantine | `quarantine` + |
| Release from hold | quarantine | available | `quarantine` − |

Column 2 is `inventory_detail.status`. Column 3 is the **recomputed** `product_inventory` bucket — a
consequence, not a command. Nothing writes a bucket directly.

The reasons are rows in `inventory_adjustment_reason`: relabel them, deactivate them, add your own,
and hang a G/L account off one when there is something to post to. `Entity\InventoryAdjustmentReason
::defaults()` is the single source the migration seeds from, the repository lazily recreates from,
and the tests assert the partition over. A reason's statuses are still re-checked in the controller
against `InventoryDetail::documentBackedStatuses()`, because they are configuration and configuration
is input.

**Which fields appear depends on the product's `TrackingPolicy`, per direction.** `track_in` and
`track_out` are independent, and this screen respects the direction it is actually moving stock in:

- outbound, `track_out = true` → the operator names the rows;
- outbound, `track_out = false` → `AutomaticSourcePicker` chooses (earliest expiry, then lowest bin
  `sort_key`) and nothing is asked;
- inbound, `track_in = true` → the batch is **pick-or-create**, because a found box carries a code
  that may never have been in this system. Expiry is required on creation when the policy says so;
- inbound, `track_in = false` on a tracked product → the row takes `sentinel_in` and
  `expect_resolution`, and lands on the tracking worklist. Tracking never blocks.

**Reversing a write-off ties to the original entry.** `inventory_movement.reverses_movement_id`, per
movement rather than per group, bounded by

```
remaining = original.quantity − SUM(quantity WHERE reverses_movement_id = original.id)
```

That one expression refuses both "reverse 500 when 12 were written off" and "reverse the same 12
twice", lets the screen list what is actually reversible, and puts serial ABC-0042 back without
anybody retyping it — bin, lot and serial all come off the original.

**Not adjustments, because a document should exist:** transfers (Warehouse Ops), bin moves (the scan
screen — a bin move changes no bucket at all), and customer returns (a credit memo against the
invoice, #586, not built — which is why `returned` still has no producer).

## Commands

```
5  * * * * php bin/console app:inventory-depth:detail-check    # invariant drift — DETECTION ONLY
30 0 * * * php bin/console app:inventory-depth:expire-lots     # takes expired stock off sale
```

`detail-check` never auto-corrects, unlike its bucket siblings. A bucket is a derived cache whose
source of truth is a document, so recomputing it is always right. The physical total is not derived
from anything the computer knows: if `quantity` and the detail rows disagree, one of them is a
*record of reality* and the machine cannot tell which. It reports; a human decides, through Stock
Adjustment, so the correction carries a reason.

`expire-lots` exists because **expiry is not self-executing.** Nothing makes a lot stop being
sellable on its date; status only ever changes via a movement, so this is the sweep that writes them.
Filtering expiry inside the availability query instead would break `quantity == SUM(available)`,
which is the one thing holding this together. **Convention: `expiry` is the last usable day.**

## What it deliberately does not do

**Nothing here hooks the order or invoice lifecycle.** As of #539/#546 nothing in that lifecycle
decrements `product_inventory.quantity` at all — approving, invoicing and completing only move
amounts between the hold buckets, and Approved is released by an import/recount rather than by
shipment (see `ProductInventory::$approvedQuantity` and `InvoiceInventoryBucketResolver`). The #550
plan's "deduction must move detail rows" section assumes a deduction hook that does not exist in this
codebase; adding one would not be keeping the layers consistent, it would be *introducing* a
decrement, double-counting against a bucket still holding the same units, and changing what the
number means.

The `pick` and `ship` movement types exist and work. Wiring them to a fulfilment event is #552's job,
once there is a fulfilment event to wire them to.

Also not here: receiving with a vendor and PO (#555), pick lists and scanning (#552), barcode
printing, the visual bin map, transfer orders, FEFO as an operator-facing workflow, many-to-many
regions.

**Pack conversion is not assembly, kitting or a bill of materials.** One case SKU, one unit SKU, one
ratio. A finished good made from several components, a routing, a labour cost or a work order is
`#598`, and nothing here is a down payment on it: `inventory_pack_rule` has exactly one unit side and
could not express a second component without becoming a different table.

## What #552 and #555 build on

Both sit on this bundle, and both should reach it through the same three things rather than writing
detail rows themselves:

- **`Movement\MovementRequest`** — build one, hand it over whole. It is a plain value object with no
  entity manager, so a caller can construct, inspect and refuse one without touching the database.
  `clientOperationId` is the idempotency key; pass a natural one (a scan session, a receipt id) and
  a retry re-applies nothing.
- **`Movement\StockMovementService::apply()`** — the *only* thing that writes a detail row or
  `product_inventory.quantity`. It is atomic, it maintains the invariant, and it refuses any product
  that has not opted in. Do not add a second writer; add a caller.
- **`Movement\AutomaticSourcePicker`** — `plan()` returns which rows answer a withdrawal (earliest
  expiry, then lowest bin `sort_key`) *without applying it*, so a pick list can show it, an operator
  can override it, and a short pick can be recorded as the several facts it actually is.
  `addWithdrawal()` folds the plan into a request when nobody needs to see it.

Specifically:

- **#552 (warehouse operations)** wants `WarehouseLocation::$sortKey` — it is already the pick-path
  traversal order and `WarehouseLocationRepository::findByWarehouseInPickOrder()` walks it. A
  confirmed pick becomes `available → sold` through `apply()`. When a fulfilment event
  exists to hang it on, that is the wiring this bundle deliberately left undone — see below.
- **#555 (procurement)** wants receiving: a receipt is `MovementRequest::receive()` into a bin with a
  lot, and `InventoryLot` already carries `expiry`, `receivedAt` and a free-text `source` waiting for
  a real vendor to point at. Nothing about the lot shape has to change to give it an FK.

## Guards, and which layer actually enforces them

The migration adds three things Doctrine's mapping layer cannot express, so they exist in a migrated
database but **not** in the schema either test suite builds from entity metadata:

1. `uniq_inventory_detail` over `COALESCE(location_id,0), COALESCE(lot_id,0), COALESCE(serial,'')` —
   a plain unique index would not collapse NULLs, which SQLite treats as distinct.
2. `uniq_live_serial`, partial on `serial IS NOT NULL AND quantity > 0` — what makes double-selling a
   serial impossible at the database level.
3. `CHECK (quantity >= 0)`.

Because the tests cannot see any of them, the **enforcing** layer is the application:

- `Repository\InventoryDetailRepository::findOrCreate()` is the only way a row is made, so uniqueness
  is upheld by lookup in both schemas;
- the decrement is `UPDATE inventory_detail SET quantity = quantity - :n WHERE id = :id AND quantity >= :n`
  with its affected-row count checked.

The `CHECK` stops the write; the conditional `UPDATE` turns it into "someone got there first" instead
of a constraint exception in a picker's face. Both halves are needed, and the schema is the backstop
rather than the mechanism.

## Things that are easy to get wrong

- **`inventory_lot.code` cannot be unique per product.** Vendors reuse batch codes across production
  runs with different expiry dates; `UNIQUE(product_id, code)` would force two genuinely different
  batches into one row. A lot's identity is its row id, which is why the UI shows code *and* expiry.
- **Terminal rows accumulate.** `(product, warehouse, NULL, lot, NULL, 'sold')` is one row by
  definition, so shipping the same lot to two customers grows one row rather than making two. The row
  answers "how much"; only `InventoryMovementGroup::$reference` answers "to whom" — which is why
  `reference` is required on ship groups and why recall is a ledger query.
- **Mixed-lot bins are normal.** One bin holding two lots with different expiry dates is two rows.
  It means scanning a bin no longer identifies stock; the lot label has to be scanned too.
- **A short pick is more than one fact.** What was found, what is missing, and how the order was
  completed are separate movement groups with separate reasons. Writing one loses the discrepancy.
- **A bin move must not change `quantity`.** Both sides are `available`, so the recompute lands on
  the same number. It is the cheapest regression test there is for someone recomputing from the wrong
  status set, and there is one.
- **Deleting a warehouse takes its breakdown and its ledger with it.** `warehouse_location` and
  `inventory_detail` cascade on `warehouse_id`, and `inventory_movement` cascades on both detail
  sides, so `Admin\ConfigController::deleteWarehouse()` removes all of it. That matches what core
  already does there — it deletes the warehouse's `ProductInventory` rows explicitly — and the
  connection runs with `PRAGMA foreign_keys = ON` (see `App\Doctrine\SqliteWalMiddleware`), so there
  are no orphans and no constraint failure. It does mean a deleted warehouse's audit trail is gone;
  if that ever needs to survive, the change is to refuse the deletion, not to weaken the cascade.
