# Plan: incoming and received — the positive half of the bucket ledger

Status: **proposal, nothing written yet.**
Base: `main` @ `fd9d9418`
Supersedes: nothing. Corrects an assumption in `2026-08-21-inventory-depth-lot-serial-expiry.md` (#550)
and a defect in `2026-08-21-procurement-vendor-po-bill-receiving.md` (#555) as built.

## The fact everything here follows from

**`product_inventory.quantity` is not this application's number.** It is a figure imported from the
client's own inventory system — a spreadsheet, QuickBooks, whatever they actually run — and there is
no real-time sync with it. The import is periodic and manual.

That single fact explains the whole existing design, which reads as a gap until you know it:

- Nothing in the order or invoice lifecycle decrements `quantity`. It cannot: the app does not own
  that number, and the next import would overwrite whatever it wrote.
- Stock that is spoken for is **held in a bucket** instead — `cart_hold`, `sales_hold`, `pending`,
  `approved`, `backordered`. A hold is the app's record of a claim made *since* the last snapshot.
- `approved` holds indefinitely, and the only release valve is an import with
  `clear_approved_balance` ticked. That is not a leak, it is the app deferring to the authority.

So `quantity` is a **snapshot from an external authority**, and the buckets are **the app's deltas
since that snapshot**. Read that way the design is coherent and complete — for the sell side.

## What is missing: the deltas only go one way

Every bucket today is negative — stock leaving. Nothing represents stock **arriving**, because until
#555 the app had no notion of goods arriving at all. Now it does, and the model has no place to put
them.

Two positive deltas are needed, and they are not the same thing:

| Bucket | Sign | Means | Sellable? | Cleared by |
|---|---|---|---|---|
| `incoming` | — | on a purchase order, not yet arrived | **no** | converting to `received` on receipt |
| `received` | + | arrived and on the shelf, not yet in the external system's file | **yes** | an import with the box ticked |
| `quarantine` | − | physically present, unsellable until somebody rules on it — quarantined or returned-and-uninspected | **no** | a human decision, per unit (#581) |
| `write_off` | − | gone or unsellable for good — damaged, expired, scrapped, lost | **no** | nothing; the units do not come back (#581) |

Availability becomes:

```
available = quantity + received
          − transfer_out − write_off − quarantine
          − cart_hold − sales_hold − pending − approved − backordered
```

`incoming` sits outside availability entirely. It is a forecast, not stock — the same distinction
`backordered` already makes on the negative side, which is why the two belong together (see below).

### transfer_out

Stock that has left this warehouse on an internal transfer and has not arrived anywhere yet.

**Negative** — the units are still counted in `quantity`, because the external system has not been
told they moved, but they are on a truck and this warehouse cannot sell them. Exactly the shape a
hold has.

**Transfer IN needs no bucket of its own: it is `received` at the destination.** Goods that have
arrived and are sellable but are not yet in the external system's file is precisely what `received`
already means, and where they came from — a vendor or another warehouse — does not change that
fact. One less concept.

So a transfer is two entries in the same two buckets a purchase already uses:

| | source warehouse | destination warehouse |
|---|---|---|
| dispatch | `transfer_out` += qty | — |
| receipt | `transfer_out` −= qty | `received` += qty |

Both clear the same way everything else does: the external system's next import states the true
figure for each warehouse, and `clear_received_balance` baselines what it now includes.

**Why not widen `incoming`.** It was the other candidate, and it would have worked arithmetically.
But `incoming` means "on a purchase order, expected from a vendor", and a transfer is a different
fact about goods you already own. Reusing it would make "what is on order" unanswerable without
also asking "and is it actually just ours, in transit". A column is cheaper than that ambiguity.

#552 shipped transfer orders (its plan is titled "…bin map, transfers", section 5) before any of
this was settled, so a dispatch currently records nothing at the source — which is what
`WarehouseOpsCest`'s "the source cannot sell what is on the truck" case is failing on (#574).

### quarantine and write_off — RESOLVED (#581)

This section previously left `quarantine`'s sign and clearing rule open. They are settled, and a
second bucket came out of the same conversation.

Every `inventory_detail.status` is allocated to exactly one thing that subtracts it. Nothing is
subtracted twice and nothing is subtracted by nobody:

| Detail status | Subtracted by | Why |
|---|---|---|
| `available` | nothing — it IS the sellable stock | |
| `damaged`, `expired`, `scrapped`, `lost` | `write_off` | gone or unsellable for good; will not come back |
| `quarantine`, `returned` | `quarantine` | present and countable, unsellable pending a human decision, may return to sale |
| `in_transit` | `transfer_out` | on a truck between this firm's own warehouses (#574) |
| `sold` | **the invoice that billed the units**, via `pending`/`approved` | see below |

`staged` was on this table until #581 and is not a status any more. Nothing ever wrote it — the pick
list it was reserved for does not exist — so rather than carry a row that no query could return, the
constant was deleted from `InventoryDetail` outright. Deleting it changed no total, which is the
strongest available evidence that no row held it.

Both new buckets are **negative**, and both are **cached sums recomputed from the detail rows** —
the same self-maintaining shape `transfer_out` has, not an accumulated counter. That is what lets
either half of a movement be booked without the other half remembering to adjust a total.

Neither is cleared by an import option. A write-off is not a counting disagreement, and quarantined
stock is released by somebody inspecting it, not by a spreadsheet.

**Why `sold` gets no bucket.** It is already accounted for. An invoice that bills units holds them
in `pending`/`approved`, and that hold never releases, because this app has no mechanism that
decrements Starting Inventory on shipment. A bucket here would subtract the same units a second
time.

That was not reasoned from the development database — where, as it happens, no product has both an
`approved` hold and a `sold` detail row, which proves nothing about a system under active
development. It was settled by conducting the transaction:
`InventoryDepthBundle\Tests\Movement\SellingADimensionalProductTest` receives 30 units of a
dimensional product, sells 5 through a real `Invoice`, moves the invoice to Processing, ships the
units through `StockMovementService`, and reads back every bucket. 25 sellable, held once, by the
invoice.

**The corollary: an adjustment may not produce those statuses.** `AdjustmentController` used to
offer `sold`, `staged` and `in_transit`, which let an admin move stock straight to `sold` with no
invoice anywhere — a second door out of the warehouse with no paperwork behind it, and nothing
holding the units. (`staged` was among the options offered; it has since been deleted as a status
altogether, so only `sold` and `in_transit` remain to be refused.)

The screen has **two** routes that write a status, and both had the hole:

> **Superseded by #585, and the rule survives.** `POST /withdraw` no longer exists — its four
> destination statuses are four of the reasons on the reason-first screen — and neither route offers
> a status dropdown any more. The refusal below did not go with it: the statuses now come from a
> configurable `inventory_adjustment_reason` row, so `AdjustmentController::submit()` re-checks both
> derived sides against `InventoryDetail::documentBackedStatuses()` before applying anything. The
> table below is kept as the record of what the hole was.

| route | what it is | destinations now |
| --- | --- | --- |
| `POST /withdraw` | N units leave, source picked automatically | `damaged`, `quarantine`, `scrapped`, `lost` — `ADJUSTABLE_STATUSES` |
| `POST /adjust` | the general from → to mover | `InventoryDetail::adjustableDestinations()`, i.e. every status less the document-backed two |

**Both sides are narrowed, not just the `to` side.** An earlier draft of this plan said the opposite
— that the `from` side keeps every status, because moving stock *out* of `sold` or `in_transit` is
how a mis-shipment gets corrected. That was wrong, and for the same reason the `to` side rule is
right: if a `sold` row exists because an invoice billed those units, an admin moving that row
elsewhere from this screen is unbilling them with no credit note behind it. It is the identical
forgery run backwards, and it leaves the invoice claiming units the detail rows no longer show.

A mis-shipment is corrected through the invoice that recorded it, and stuck in-transit stock through
the transfer's own receiving screen. Each of those leaves a document; this screen cannot.

The cost is worth stating plainly: while no dispatch layer exists to write `sold`, rows already
carrying it cannot be moved from this screen at all. That is the correct shape of the problem —
those rows want an invoice action, not a stock edit — but it does mean the adjustment screen is not
an escape hatch for them.

`documentBackedStatuses()` is `soldStatuses()` plus `in_transit` — composed, not restated, since
`sold` gets no bucket for the same reason it cannot be hand-written: a document already accounts
for it. `adjustableDestinations()` is derived by subtraction, so a newly added
physical status is offered automatically and a newly added document-backed one has to be declared.
`EveryStatusIsAllocatedTest` asserts the two lists partition `statuses()` exactly.

Both routes refuse server-side as well as omitting the option, and
`InventoryDepthCest::anAdjustmentCannotSellOrDispatchStock` drives raw POSTs at both — the dropdown
is a courtesy, the refusal is the rule. Its companion,
`anAdjustmentCannotMoveStockOutOfASoldRowEither`, seeds a real `sold` row through the movement
service and then tries to adjust it back to `available`, which is the from-side half of the same
rule and the one a to-side-only test cannot fail on.

**`sold` means shipped. Ruled, not open.** For the simple case there is no separate dispatch event
and none is wanted for now: a detail row reaching `sold` IS the record that the goods left. If a
dispatch/shipping module is built later, that is where `sold` gets written from, against the invoice
that bills it.

What this does NOT reopen: the adjustment screen still may not write it. The objection was never to
the status, it was to producing the status with no invoice behind it — an admin marking stock sold
when nothing billed it. Every one of the 29 `sold` rows on dev arrived through
`inventory_movement_group.type = 'adjustment'`, which is exactly the door that got closed. So
nothing writes `sold` today, deliberately, until there is a document to write it from.

**What `received` means now.** It used to absorb every change to the available pool, so a pick, a
shipment or a lot expiring wrote a bucket named "stock arrived" — which is why
`clear_received_balance` cleared a mixture of arrivals and departures while an admin believed they
were baselining deliveries. It now takes only what no other bucket records: genuine arrivals, and
withdrawals that leave the ledger with no destination at all. `MovementRequest::receivedDelta()`
makes that decision, from `InventoryDetail::statusesAccountedForElsewhere()`.

**Known consequence on existing data.** Rows that recorded departures before this still have those
units netted out of `received` AND now sitting in a bucket, so they read short by that amount. The
migration deliberately does not correct it: an import rebaseline zeroes `received` against a fresh
count, and nothing in the row says which side of the last rebaseline a detail row falls on, so the
arithmetic fix is right for some instances and wrong for others. See
`migrations/Version20260830120000.php`.

## The eight tables stay — ruled, do not re-open

Reviewed against the live schema on 2026-08-23 and settled: `product_inventory`, `inventory_detail`,
`inventory_movement_group`, `inventory_movement`, `order_inventory_reservation`,
`invoice_inventory_reservation`, `inventory_bucket_change_log` and
`inventory_reconciliation_discrepancy` all keep their own shape. No merges.

Three merges look tempting from the column lists alone. All three were considered and declined:

| tempting merge | why not |
| --- | --- |
| `order_inventory_reservation` + `invoice_inventory_reservation` | declined earlier in #539 stage 3 — a nullable `order_id`/`invoice_id` pair is the polymorphic FK this codebase refuses everywhere. The shape is already shared through the `InventoryReservation` interface. |
| `inventory_bucket_change_log` into `inventory_movement` | different grain. A movement is a crossing between two `inventory_detail` rows; a bucket change is a column changing value. Sell-side buckets have no detail rows, and on a simple product neither does anything else — 9,907 log rows against 236 detail rows says most bucket changes happen where no detail exists. |
| one operation table parenting both effect kinds | would make `client_operation_id` nullable, weakening the replay guarantee the warehouse flows depend on, to accommodate cart syncs that have no operation identity. |

The reservations are not logs and do not belong in any history consolidation: they are current-state
ledgers updated in place, the same role `inventory_detail` plays for the warehouse buckets.

## Clearing is symmetrical with what already exists

`received` clears on import, **as an option**, exactly the way `approved` does today.

`ProductImportService::applyInventory()` already implements this shape for `approved`: with
`clear_approved_balance` ticked it sets `approvedQuantity = 0` **and** stamps each contributing
reservation's `syncedQuantity = quantity`. That second step is what makes the reset stick — what
contributes is `max(0, quantity − syncedQuantity)`, so baselining stops existing documents
contributing without touching the documents themselves. Without it the hourly
`app:product-import:approved-recalc` would re-sum them and silently undo the reset within the hour.

`clear_received_balance` is the mirror: one more checkbox on the same screen, zeroing `received` and
baselining the receipts behind it.

**Why a checkbox and not a date.** The obvious alternative is for the import to carry an "as of"
date and baseline every receipt booked before it. That is not available: **the import carries no
count date at all** — there is no such field on the file or the form, and nothing records when the
external figure was true. Adding one would mean trusting whoever types it. Only the person running
the import knows whether their file already includes last week's deliveries, which is exactly what a
checkbox asks them.

## What this corrects in #550

`StockMovementService::syncCoreTotal()` sums the available detail rows and **writes the result into
`product_inventory.quantity`**, in the same transaction as every movement.

The direction is arguably semantics — the details have to reconcile to the total either way. The
problem is that it makes the movement layer a **second writer** of a number the import owns:

| | `quantity` | detail sum |
|---|---|---|
| after import | 100 | 100 |
| receive 5 | 105 | 105 |
| their file lands, still says 100 | **100** | **105** |

They now disagree, and worse, the disagreement is ambiguous: either their count predates the
delivery and our 5 are real, or it already includes it and something was booked twice. Nothing in
the data distinguishes those.

With `received` the app's delta is explicit rather than hidden inside the total, and the sum still
reconciles:

```
SUM(available detail) == quantity + received
```

Their number is never overwritten; ours sits beside it. `syncCoreTotal()` stops writing `quantity`
and either writes `received` or becomes a check.

**This has not bitten yet, and it is worth knowing why.** The only movements built so far are picks
and transfers, which move units between bins while leaving them `available` — the sum does not
change, so `syncCoreTotal()` writes back the identical number. #552 leaned on that deliberately.
Receiving is the first movement that changes the sum.

## What this fixes in #555

`ReceivingService` builds a `MovementRequest` and calls `StockMovementService::apply()`. Booking a
delivery therefore raises `quantity` today. Under this model it raises `received` instead, and
`incoming` for the same units falls by the received amount.

Nothing else about #555 changes. It was right to route receiving through the movement service rather
than writing quantities itself; the bucket it lands in is what changes.

## What this gives #548 for free

#548's backorder ETA is a **restock date typed by an admin**. With `incoming`, it is derivable: an
open purchase order for that product in that warehouse *is* the expected arrival, with a date already
on it. The backorder release already triggers on stock arriving, so the two features meet properly
instead of each guessing separately.

Worth keeping the manual field as an override — a supplier's verbal promise is not a PO — but the
default should come from the PO.

## Required acceptance test — write this one first

Whoever implements this must write a Cest that toggles the bundle and asserts availability across
the toggle. It is listed here, in this much detail, because **the wrong implementation passes every
other test.** Both plausible mistakes — always adding the column, or zeroing it on deactivation —
produce a system that behaves correctly in every state except the transition, and the transition is
the only place the difference is visible.

The scenario, in one test:

1. Bundle **Active**. Product with `quantity = 100`, no holds. Assert available is **100**.
2. Book a receipt of **50**. Assert `received = 50` and available is **150**.
3. Turn the bundle **Inactive**. Assert available is **100** — the 50 is out of the sum.
4. **Assert `received` is still 50 in the database.** This is the assertion that catches
   zero-on-deactivation. Read the column directly; do not infer it from availability, because a
   zeroed column and an excluded term give the same availability and the test would pass either way.
5. Turn the bundle **Active** again. Assert available is **150** again, with no recount, no
   re-import and no manual step.

Step 4 and step 5 are the point. Step 3 alone is satisfied by zeroing the column, and steps 1–2 are
satisfied by always-adding. Only the full sequence separates "the term is excluded from the sum"
from "the data was destroyed" and from "the data is always counted".

Do the same for `incoming`, which must be absent from availability in **both** states — it is a
forecast, not stock — so its test is that toggling the bundle changes nothing about availability at
all, while the column still holds its value.

One more, because it is the case a reviewer will ask about: with the bundle Inactive, book nothing
and change nothing, then run the import with `clear_received_balance` **absent from the form**
(because the checkbox is gated off). Assert the import succeeds and leaves `received` untouched. A
gated-off checkbox must not become a required field, and a stale POST carrying it must be a no-op
rather than an error.

## Importing a dimensional product

The import declares a total per product per warehouse. A dimensional product's stock is spread
across bins, lots and serials, so a file that says "47" cannot say *which* 47. That is a real gap
and it is solved by not pretending the file knows.

**When the receiving bundle is Active, the import screen gains an advanced mode** on top of the
simple one: optional columns for bin, lot, serial and expiry. Simple-inventory products ignore it
entirely.

**Every unknown field takes a configured default rather than blocking the row.** A configurable
string per field — `[PENDING]`, `default`, whatever the client wants — plus a far-future expiry
(9999 days out) so an unknown date is obviously not a real one and sorts last under FEFO. The value
does not matter; that it is consistent and greppable does, because the admin's worklist is then a
single filter: everything still carrying a sentinel.

This matters most for a warehouse mid-transition, which has real stock on shelves and no lot codes
for any of it. Refusing those rows would make the import unusable on exactly the day they need it.

### Reconciling against existing detail rows

The count is authoritative for the **total**; the identities catch up afterwards. Every case falls
out of that one sentence.

**No bins specified in the file.** The difference lands on the default row and is allowed to go
**negative**. Before: bin A = 40, bin B = 7. Import declares 40. After: A = 40, B = 7,
default = **−7**. Existing rows are untouched, the total is right immediately, and the discrepancy is
a visible number rather than a silent deletion — it clears itself the moment someone finds the
missing seven. Worth a warning on the import summary; not worth blocking.

Negative works for serial-tracked stock too, because the negative row carries the sentinel serial.
It is not "minus seven of ABC123" — it is "seven units short, serials not yet identified", which is
exactly what a count that got the total right and the identities wrong actually knows.

**Bins specified in the file.** An import option decides what happens to rows the file does not
mention:

| Option | Before A=40, B=7, file says A=39 | After |
|---|---|---|
| unspecified bins deleted | | A = 39, B = 0 |
| unspecified bins untouched | | A = 39, B = 7 |

Same rule for lot, serial and expiry — they are all just fields on a detail row.

**A product may not carry both a total and bin rows in the same file.** If bin rows are given, they
are the declaration and the total is their sum. A product-level total sitting alongside them is two
answers to one question.

That product is **refused outright** — none of its rows are applied, its existing detail rows and
total are left exactly as they were, and the import summary names it and says why. The rest of the
file proceeds normally: this is a per-product refusal, not a failed run, which is the shape the
importer already uses for row issues.

Refusing beats resolving. Deriving a winner silently would let a typo'd total through unnoticed, and
a wrong total nobody was told about is the one outcome that cannot be spotted afterwards. Refusing a
product costs someone a re-upload; accepting a wrong number costs a stock count.

### Required tests for the import

A Cest per case. These are cheap to write and each one pins a decision that is easy to
"simplify" away later.

| Case | Setup | Assert |
|---|---|---|
| unknown fields take the sentinel | advanced import, no lot/serial/expiry columns | detail rows carry the configured sentinel and the far-future expiry; the import succeeds |
| growing, no bins in the file | A = 40, B = 7; file declares 47 | default row = 0, A and B untouched |
| **shrinking, no bins in the file** | A = 40, B = 7; file declares 40 | **default row = −7**, A = 40, B = 7 untouched, total = 40, warning on the summary |
| shrinking, serial-tracked | same, product is serial-tracked | still one negative row carrying the sentinel serial — **not** seven rows, and not a refusal |
| unspecified bins deleted | A = 40, B = 7; file lists A = 39, option = delete | A = 39, B = 0 |
| unspecified bins untouched | A = 40, B = 7; file lists A = 39, option = untouch | A = 39, B = 7, total = 46 |
| **total and bin rows for one product** | file gives that product a total *and* bin rows | that product's detail rows and total are **unchanged**; the summary names it; **every other product in the file still imports** |
| simple-mode product | advanced columns present, product is `simple` | advanced columns ignored, behaves exactly as the simple import does today |

The two bolded rows are the ones worth guarding hardest. The negative default row will look like a
bug to whoever reads it next and the temptation will be to clamp it at zero — which silently deletes
the discrepancy and is precisely what the design is avoiding. And the refusal has to be
**per-product**: an implementation that aborts the whole run on one contradictory product passes a
naive "it was rejected" assertion while being unusable on a 5,000-row file.

### Shrinking is not an import problem

Reducing a total below the detail sum is a write-off wearing an import's clothes. The negative
default row is deliberately how that surfaces: the import always succeeds, the books balance
immediately, and the unexplained quantity sits somewhere an admin can see it. Refusing the import
instead would fail on precisely the day someone discovers stock is missing, which is when they most
need the number corrected.

## For whoever implements this

Everything below the design is here so an agent can pick this up cold. Read the design above first,
then `2026-08-21-inventory-programme-sequencing.md` for where this sits.

**Already merged and on `main`:** #539 (invoice as its own entity), #546 (warehouse / fulfillment
region split), #548 (back orders), #550 (inventory depth, `modules/InventoryDepthBundle/`), #552
(warehouse operations, `modules/WarehouseOpsBundle/`), #555 (procurement,
`modules/ProcurementBundle/`). **Nothing in this document is built.**

**Files you will be changing**, so read them before designing anything:

- `src/Entity/ProductInventory.php` — the four existing hold buckets and `getAvailableQuantity()`
- `src/Service/ProductImport/ProductImportService.php` — `applyInventory()`, and the
  `clear_approved_balance` handling around line 805 that `clear_received_balance` mirrors
- `modules/InventoryDepthBundle/src/Movement/StockMovementService.php` — `syncCoreTotal()`, the
  thing that must stop writing `quantity`
- `modules/ProcurementBundle/` — `ReceivingService`, which books into `quantity` today
- `src/Repository/BundleStatusRepository.php` — `isActiveForInstance()`, the gate
- `modules/Number1ProductImportBundle/src/Controller/Admin/ProductImportController.php` — the import
  screen and its existing checkbox

**Write the toggle Cest first.** It is specified step by step above, including which assertion
catches which mistake. Both plausible wrong implementations pass everything else.

### Constraints that have each already cost real time here

- **Never add a foreign key to `product_core` from a table that copies document lines.**
  `sales_order_line` deliberately has none: a line snapshots a product and must outlive its
  deletion, and imports delete rows routinely. The dev database has 2000 order lines pointing at
  products that no longer exist, every one a correct record of something sold. An FK there made a
  migration unrunnable.
- **Any rebuild reading lines and writing reservations needs
  `AND <line>.product_id IN (SELECT id FROM product_core)`** — both reservation ledgers cascade on
  `product_id`.
- **Do not reconcile or query-bind a document being deleted.** `InventoryReconciliationSubscriber`
  filters deleted orders and invoices out of its queue in `postFlush`; by then Doctrine has stripped
  the identifier and binding it as a query parameter throws.
- **A migration must not leave state its own application would immediately recompute.** #539 shipped
  a status derived from a column a later migration dropped — invisible until the next write flipped
  it.
- **SQLite here is old**: no `DROP COLUMN` before 3.35, and `PRAGMA foreign_keys = OFF` is silently
  ignored inside a transaction. Read `Version20260821090000` before writing a table rebuild.
- **The sidebar snapshot** `tests/Support/Fixtures/admin_default_sidebar_nav.html` covers the whole
  rendered sidebar **including the `docs/` tree**. Any new document or nav change turns all three
  assertions of `AdminMenuDefaultSidebarCest` red. This has broken `main` five times in two days.
- **Copy `vendor/` into a worktree, never symlink it** — Composer resolves `__DIR__` through the
  symlink, so `App\` loads from the main checkout's `src/`. **Run `composer dump-autoload` after
  adding a bundle namespace**, or the container cannot find its descriptor and hundreds of unrelated
  tests error.
- **Do not run migrations against `var/data_dev.db`.** It is live and fully migrated; a pre-#539
  backup sits beside it.
- Check the highest `migrations/Version*.php` before choosing a timestamp, and leave a gap if
  another agent is working in parallel.

### Machine limits

**This VM sustains two agents, not three.** It has under 4 GB of RAM against a Codeception memory
limit of 3 GB, so two full suites at once thrash swap and the harness kills them — that has happened
twice. Rules that follow:

- At most **two** agents running at a time.
- **One suite at a time across the whole machine.** Before starting a suite check `pgrep -f codecept`,
  not just `free -m` — memory looks fine right up until the second run ramps.
- A `database is locked` or `var/cache/test` error means contention, not a real failure. Wait and
  retry rather than treating it as a bug.
- A full Codeception run takes about 14 minutes uncontended and roughly 2.4 GB at peak.

## Sequencing

1. `received` and `incoming` columns, availability formula, `clear_received_balance` on the import
   screen with the baselining that makes it stick.
2. `syncCoreTotal()` stops writing `quantity`.
3. `ReceivingService` books into `received`; PO confirmation books into `incoming`.
4. #548's ETA reads an open PO, manual field becomes the override.

1 and 2 must land together — 2 alone leaves receiving with nowhere to put stock, and 1 alone leaves
two writers on the total.

## Where each piece lives, and what happens when a bundle is off

**The columns are core. The logic is the bundle.** That is the split this codebase already uses —
#550 put `product_core.inventory_mode` and the provider interface in core and implemented nothing
there, and the negative buckets have always been core columns.

So `received` and `incoming` are columns on `product_inventory`, defaulting to `0`, and the
availability formula in core reads them unconditionally. With no bundle installed they are
permanently `0` and `+ received` adds nothing — the formula is correct without a single conditional,
and nothing can fatal on a missing service because nothing asks for one.

**The `clear_received_balance` checkbox must be gated on the bundle that writes the bucket.** The
import screen lives in `Number1ProductImportBundle`; the bucket is written by `ProcurementBundle`'s
receiving. Those are different bundles and neither may hard-depend on the other. The checkbox is
shown only when the writing bundle is Active, via `BundleStatusRepository::isActiveForInstance()` —
the same gate `ProductController` already uses for fee field providers and
`FrontendMenuManagementController` for menu items.

Gating matters for a reason beyond tidiness: a checkbox that clears a bucket nothing fills is not
merely useless, it is a lie about what the import did. And the handler behind it must be a no-op
rather than an error when the box arrives on a request anyway — a stale form, a bookmarked POST —
because clearing a bucket that is already `0` genuinely is a no-op, and refusing it would turn a
harmless stale submit into a failed import.

The same gate applies to anything that *shows* the numbers: an inventory breakdown that lists
Received and Incoming rows on an instance where neither can ever be non-zero is noise.

**The availability arithmetic gates too.** `received` and `incoming` count only while the bundle
that writes them is Active:

```
bundle on:   available = quantity + received − cart_hold − sales_hold − pending − approved
bundle off:  available = quantity            − cart_hold − sales_hold − pending − approved
```

The tempting shortcut is "the column defaults to 0, so just always add it and skip the conditional".
That is only safe if the bundle was *never* on. Enable receiving, book 50 units, disable it — the
column still says 50, and always-adding keeps 50 units of a switched-off feature in everyone's
availability, invisibly. The schema lives in core so that enabling a bundle needs no migration, not
so its data can keep acting on the system after it is gone.

Nothing is zeroed on deactivation and nothing is required on re-enable. Off means the terms are not
in the sum; on means they are; the rows sit untouched in between. There is no state to reconcile
because nothing was destroyed.
