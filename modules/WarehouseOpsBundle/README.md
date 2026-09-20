# Warehouse Operations (#552)

Barcodes and labels, a scan console, pick lists, a bin map and transfer orders.

**Nothing here invents a new way to change stock.** Every screen is a faster front end on
`InventoryDepthBundle\Movement\StockMovementService` (#550), which is the only thing in the
application that writes `inventory_detail` and the only thing in the bundle layer that writes
`product_inventory.quantity`. A stock change made by a scanner produces the same movement rows as
the equivalent change made on the desktop adjustment screen, because it is the same call.

Requires **Inventory Depth** (#550). Every screen refuses with a 404 when either bundle is Inactive
on App Management.

## The rule that keeps this safe

> Scanning is a fast path into the same service, never a second write path.

If a scanner wrote to `inventory_detail` by its own route there would be two implementations of
"move stock" and they would drift — different validation, different reservation handling, different
audit. There is one movement service, and the handheld console, the pick confirmation, the transfer
dispatch and the manual adjustment screen all call it.

## What a pick does to the number, and why

This is the ordering constraint the plan calls the most consequential one in the whole warehouse
stack: *miss the reservation handshake and the units are subtracted twice.*

A confirmed pick moves stock from its pick face to the list's **staging bin, with its status still
`available`**. Both sides are `available` in the same warehouse, so `syncCoreTotal()` recomputes to
the identical number:

* `product_inventory.quantity` does not move. Picked stock has left the shelf, not the building.
* The order's `sales_hold` goes on standing on exactly those units, and remains the only claim on
  them.
* Nothing is subtracted twice.

### Why the handshake is a placement and not a write

The obvious alternative is to move picked stock to a non-available status and release the document's
hold to compensate. That is not available to a bundle, and the reason is structural:
`InventoryReservationReconciler` does not decrement a ledger. It recomputes each document's target
from its own lines and diffs against what is stored, so **a release written from outside is restored
on the very next flush that touches the document.**

A genuine release would mean core netting a picked quantity out of
`SalesOrderReservationSubject::heldLines()` — a column core reads and this bundle writes, which is
exactly the dependency "core reads nothing this writes" forbids. What the plan actually requires is
that the units are not subtracted twice, and keeping the pick inside `available` gets that with no
core change at all.

### Which shelf a pick is recorded against

Two questions that look like one, and the bundle keeps them apart:

* **Where SHOULD this be picked from?** `InventoryDetailRepository::pickableRows()` — earliest
  expiry, then lowest `warehouse_location.sort_key`. It routes the round, and it is right for that.
* **Where WAS it picked from?** Only the picker knows, so the confirm form asks:
  `picked_from[{task}]` beside `picked[{task}]`, pre-filled with `pick_task.suggested_location_id`
  so agreeing with the printout costs nothing.

Using the first as an answer to the second is #591: a picker sent to A-01, who found five there and
took them, had those five decremented off B-02 whenever B-02 held an earlier-expiring lot. The total
stayed right and every unit stayed `available`, so nothing on any screen disagreed.

Everything a confirmation writes is now scoped to the place the form names — the pick movement and
the short-pick write-off both:

* **the pick** comes off that bin, in `pickableRows()` order within it, and nowhere else. Type more
  than the bin holds and the difference is reported, not sourced from another aisle.
* **the write-off** is capped at what that bin still holds after the pick (#589). A confirmation
  that names no bin at all — and whose task names none either — writes nothing off and reports the
  shortfall as unattributed, so somebody goes and counts.

And one compile no longer sends two pickers to one shelf (#592): `PickListCompiler::compile()`
carries a claim map of what earlier tasks in the same compile have already been promised, per
product per bin, and steps over a bin those claims have used up. It is still a hint and not a
reservation — nothing is written to `inventory_detail`, and a round compiled at 08:00 draws on the
stock that is there at 11:00. When the claims run out of bins the task keeps
`suggested_location_id` NULL and sorts last, which is the honest answer and a safe one.

## The screens

| Screen | Route | What it writes |
|---|---|---|
| Dashboard | `/admin/bundles/warehouse-ops` | nothing |
| Labels | `…/labels` | **nothing at all** — printing is not moving |
| Scan console | `…/scan` | one movement group per confirmed scan |
| Pick lists | `…/pick-lists` | pick + short-pick adjustment groups |
| Scan out | `…/pick-lists/{id}/scan` | **nothing** — it tallies in the URL and confirms through `…/pick-lists/{id}/confirm` |
| Bin map | `…/bin-map` | `warehouse_location` zone/aisle/coordinates |
| Transfers | `…/transfers` | dispatch and receipt movement groups |
| Discrepancies | `…/discrepancies` | **nothing** — it lists what the checks found |

Filter state lives in URL GET params on every list screen.

## A short pick is three facts

The list says 20, the picker finds 16:

1. **The pick moved 16** — a `pick` movement group of what physically happened.
2. **Four units the system expected are not there** — a *separate* `adjustment` group with a count
   reason. Writing one movement of 20 loses the discrepancy entirely and leaves stock overstated;
   writing one of 16 and stopping leaves the four sitting in the system forever.
3. **The order still needs 4** — `pick_task.outstanding()`, for a top-up from another lot or a
   backorder (#548). Not a movement, because nothing moved.

Only the part the system still believes is on the shelf can be written off. Units that were never
in the system have nothing to adjust, so the order simply still needs them.

## Transfers

Two movements, never one. Dispatch moves stock to `in_transit` owned by the **source** warehouse;
receipt moves it from there to `available` at the destination. Between the two the stock is out of
`SUM(available)` at the source and has not arrived at the destination — **sellable at neither end**,
and still locatable.

`quantity_dispatched` and `quantity_received` are separate columns because they genuinely differ:
that gap is stock lost in transit, and it stays visible on the source warehouse's in-transit row
rather than being reconciled away.

**Transfers do not inherit FEFO.** #550's picker takes earliest expiry first, which is right for a
customer shipment and wrong here — sending the earliest-expiring stock to a slower site is how it
expires there. A transfer line names its lot, and the suggestion prefers the *longest*-dated batch.
The allocation rule is a property of the operation, not a global setting.

### The line table is the entry form

A draft's lines are typed as a grid, the way a sales order's are: every line is a row of controls
and a blank row sits at the bottom. **Add line** posts, appends a `transfer_order_line` row and
comes back with the row in the table and a fresh blank row underneath — a plain form post, because
this application works with JavaScript switched off and a cloned `<tr>` is not a mechanism.

The forms those controls belong to are emitted *outside* the table and joined to it by the HTML5
`form=` attribute; a `<form>` inside a `<form>` is invalid HTML and every parser drops the inner one.

Lot and serial are dropdowns of what the **source** warehouse actually holds, labelled
`SEA-2609 · exp 2026-09-10 · 40 available` rather than by primary key, built by
`TransferSourceStock`. The first option is *auto — the longest-dated batch*, which is how the rule
above became a choice on screen instead of a hint you had to know. Beside each dropdown is a box for
a typed pattern (`SEA-26*`), matched server-side: it is the escape hatch when the list is long and
the only search a browser with no scripting can run. `js-searchable-select` layers type-to-filter on
top when scripting is on and changes nothing when it is not.

The refusal is the rule, not the dropdown: a lot that is not a batch of the line's product is
refused, and a pattern matching nothing — or more than one thing — is refused with the candidates
named. A quantity larger than the source holds is **stated**, not refused: a draft is a plan, and
`dispatch()` is where the stock has to be there.

## The transfer drift check (#587)

`transfer_out_quantity` and `transfer_in_quantity` are **cumulative** and derived from
`transfer_order_line` — everything that has ever left or arrived on a transfer, not what is on a
truck now. That fixed a real bug (#584), and it left the buckets coming from the *document* while
the `in_transit` rows come from the *movements*, with nothing comparing them.

    php bin/console app:warehouse-ops:transfer-check

compares three things for every (product, warehouse) any transfer has touched:

| reported as | one side | other side |
|---|---|---|
| `transfer_out` | `product_inventory.transfer_out_quantity` | `SUM(quantity_dispatched)` out of W |
| `transfer_in` | `product_inventory.transfer_in_quantity` | `SUM(quantity_received)` into W |
| `in_transit` | `SUM(dispatched − received)` per line, on transfers **from** W | `SUM(inventory_detail.quantity)` in transit at W |

**It never corrects anything.** Findings go to `inventory_reconciliation_discrepancy` through core's
`InventoryBucketAuditLogger` — the same table and the same admin email the other four recalc
commands use — and are listed at `…/discrepancies`. A discrepancy is a finding for a human, who
resolves it on the transfer document or through Stock Adjustment, where the correction carries a
reason. Two of its three comparisons pit a document against physical rows, and when those disagree
the machine cannot tell which is the record of reality.

Not everything it reports is corruption, and the command's own output says so: units lost in transit
and then written off leave the document saying they are in flight forever, which is permanent and
correct. A short arrival is **not** drift — dispatched 12 / received 10 leaves `transfer_out` 12,
`transfer_in` 10 and 2 units on the source's in-transit row, and all three comparisons balance.

With the bundle **Inactive** the check stands down entirely: the availability gate already excludes
both transfer terms, so nothing it could report is reaching anybody, and no transfer can be raised
to change it. Nothing is zeroed on the way past — switch the bundle back on and the same findings
are still there.

Run it beside the hourly bucket recalcs:

    10 * * * * cd /path/to/app && php bin/console app:warehouse-ops:transfer-check >> /var/log/transfer-check.log 2>&1

The screen lists **every** source that writes that table, not only this one — core's
`app:inventory-recalc`, Cart Hold's, the product import's and Inventory Depth's detail check all
write it and none of them had a reader. It lives in this bundle because this bundle is what made it
necessary; the cost is that switching Warehouse Operations off hides the other bundles' findings too.

## Barcodes

**The symbologies and the `product_barcode` table live in BarcodeBundle**, which this bundle depends
on the way it depends on InventoryDepthBundle. See `modules/BarcodeBundle/README.md` for why they are
there and not here: ProcurementBundle needs the same lookup at goods-in, and these two bundles are
peers that never import each other.

What stays here is what a label *looks like*: geometry, stock, page breaks, and which value each
target encodes.

| Target | Encodes |
|---|---|
| Product | its **primary barcode**, or the SKU when it has none |
| Bin | `warehouse_location.code` |
| Lot | `LOT-{id}`, with code and expiry printed human-readable |
| Serial | the serial itself |

Lots are namespaced because a lot's identity is its row id — batch codes are reused across
production runs with different expiry dates — and a bare id is indistinguishable from a numeric SKU.
`ScanResolver::LOT_PREFIX` is the only other place that prefix is known.

**The product label used to encode the SKU because there was nowhere else to look** — `product_core`
had no UPC, EAN, GTIN or barcode column anywhere in the codebase. #607 added `product_barcode`, so
`LabelCatalog::forProduct()` — the one method the old note said would have to change — now asks for
the product's primary barcode first and falls back to the SKU. **The fallback is not a detail:** a
product nobody has attached a code to prints byte-for-byte the label it printed before, so this
change alters nothing about a catalogue that has no barcodes in it yet.

Both symbologies are chosen per print, on the `symbology` field of the label form:

| | Code 128 | QR |
|---|---|---|
| Default | **yes** | no |
| Best at | a wedge scanner on a receiving line | a phone camera over a shelf |
| Floor | `LabelTemplate::MIN_MODULE_MM` = 0.25 mm | `MIN_QR_MODULE_MM` = 0.18 mm |

Neither floor is negotiable and neither is bypassed: below it the renderer prints the explicit
"unprintable" cell instead of the symbol, because a label that looks right and does not scan is
worse than one that was never printed.

Output is a PDF with an explicit `@page size` in millimetres, not a browser print of a web page — a
browser print's scaling is the operator's print dialog, and a barcode at 94% does not scan. Sheet
grids and continuous rolls are the same `LabelTemplate` with different numbers. A QR is drawn as one
rectangle per horizontal run of dark modules rather than one per module, which is what keeps a sheet
of thirty of them the same order of elements as a sheet of thirty linear codes.

## Scanning

Scan where → scan what → confirm how many. Bin first, always.

* **The idempotency key.** Warehouse wi-fi is bad. The confirm form carries an `op_id` minted when it
  is rendered, and `inventory_movement_group.client_operation_id` has a UNIQUE index behind it, so a
  submit that times out and gets retried applies once. A device that mints its own key sends it in
  the same field.
* **A failed scan is loud.** Unknown barcode, wrong warehouse, ambiguous code, quantity exceeding
  what is there — each blocks with a sentence the person can act on.
* **No free-text quantity where a scan will do.** A serial resolves to one specific unit and fixes
  the quantity at one.
* **Offline blocks.** No local queue, no background sync — deliberately. Queuing needs the
  idempotency key to be watertight across devices, and at this scale a scanner that says "nothing was
  recorded" is honest where one that silently drops a scan is not.

An ambiguous barcode — one value that is both a bin code and a SKU — resolves to *nothing* and says
which two things collided. Trying kinds in a fixed order would pick one and be wrong about half the
time, silently.

### A product is found by its SKU or by any barcode on it (#607)

`ScanResolver` asks `BarcodeBundle\Barcode\ProductLookup`, so a vendor's UPC, an EAN-13, a case
GTIN and each supplier's own part number all land on the same product. That is what makes the scan
console usable at a goods-in dock: before it, the only product identifier in the database was
`product_core.sku`, so scanning a label this business had printed worked and scanning the one
actually on the carton matched nothing.

Barcodes add a second shape of ambiguity and it refuses the same way. Two vendors legitimately use
one part number for two different things, so a scanned string can now name two **products** rather
than two kinds — and it names both and refuses rather than picking one.

A product with no barcode rows behaves exactly as it did before #607.

### Scanning at dispatch (#609)

`…/pick-lists/{id}/scan` is the outbound scanner: scan the shelf, then scan each carton as it comes
off it. It is what dispatch means in this application today — there is no customer shipment screen,
for the reasons in "The fulfilment-deduction gap" below, so the pick round is the last moment
anybody has a carton in their hands before it goes.

It writes nothing. The tally lives in GET parameters and the Confirm button posts to
`…/pick-lists/{id}/confirm` — the same route, with the same `picked[taskId]` and
`picked_from[taskId]` fields, that the typed screen has always posted to. A scanned round and a
typed round produce identical movement rows because they are the same code. When #593 lands, a
shipment screen scans the same way against shipment lines and nothing here has to be undone.

Goods-in has the mirror of it at `/admin/bundles/procurement/receiving/scan`, in ProcurementBundle,
posting to that bundle's own `receiving/new`.

### The camera

`@Barcode/_camera.html.twig` sits beside the typed field on all three scan screens. It renders
`hidden` and is revealed only when `window.BarcodeDetector` exists *and* reports a readable format,
and on a successful read it does exactly what a wedge scanner does: fills the same `<input
name="code">` and submits the same form. There is no fetch(), no second route and no JSON endpoint.
Delete the file and every screen still works — which is the test.

## Data outlives the bundle

Removing this bundle takes away the screens that manage pick lists and transfers. It does not take
away the transfers, and it cannot take away what they did to stock: every
dispatch, receipt, pick and short-pick adjustment is an `inventory_movement_group` written by `StockMovementService`, and stays visible in
#550's movement history like any other change. `product_inventory.quantity` was maintained in the
same transaction as each of those movements and is already sitting there correct.

The bin map's four columns (`zone`, `aisle`, `map_x`, `map_y`) live on `warehouse_location`, which
#550 owns. All four are nullable and only this bundle reads them; removing this bundle leaves four
unread columns on a table that still works. That is the deliberate cost of not building a parallel
table keyed on `location_id`, which would be a second place a bin can exist.

## Deliberately not built

* **Containers / license plates.** The plan recommends skipping them and it is right at this scale: a
  transfer naming its lots does the same work with no new concept, and adding `container_id` later is
  an `ADD COLUMN`. If they are ever built, a container is a *grouping*, not a location — making it an
  alternative to `location_id` puts the same stock in two places.
* **A label print log.** The plan says "a print log if anything". Reprints must be free and
  unlimited, so a log of them is noise, and a table whose only reader is a screen nobody asked for
  outlives its usefulness. Printing writes nothing.
* **Receiving against a purchase order.** That is procurement (#555). Manual receipt through the
  adjustment screen and the scan console's receive flow both already exist.
* **Any fulfilment deduction — including a ship button.** See below.

## The fulfilment-deduction gap, and why it is still open

#550 reported that nothing in the order or invoice lifecycle decrements `product_inventory.quantity`
at all, and left the wiring to this issue. **This issue does not wire it either.** That is a
deliberate decision, and here is the whole of the reasoning.

The first version of this bundle had a ship action on the pick list, guarded so that it refused
while the order's `sales_hold` still stood. That guard is not sufficient, and the reason is written
on `InvoiceInventoryBucketResolver` and `ProductInventory::$approvedQuantity` in as many words:

> Committed to invoices currently in the Approved bucket (Processing/Completed). A Completed invoice
> keeps holding this until the next inventory import/recount clears it — **this app has no other
> mechanism that decrements Starting Inventory on shipment.**

So the invoice's `pending`/`approved` hold *is* this application's shipment accounting. Invoicing an
order releases the order's `sales_hold` and immediately replaces it with the invoice's own hold on
the same units. A shipment deduction added on top would subtract them a second time, and unlike the
order hold, the invoice hold is never released by anything the warehouse does — only by an import or
a recount. Availability would be permanently understated by everything ever shipped.

Two designs would close the gap properly, and both are changes to **core**, not to a bundle:

1. **Release the invoice hold on fulfilment** — a new case in the status-to-bucket table, changing
   what `approved` means for every product, `simple` ones included.
2. **Have core stop holding `pending`/`approved` for products whose stock a depth layer maintains** —
   `InventoryModeResolver` already answers exactly that question, and answers "simple" with the
   bundle gone, so the switch would be inert for every document that exists today. But it still
   changes what an invoice reserves, which is core's rule to change.

Either one is a re-timing of stock deduction and needs its own justification, its own issue and its
own read of the reconciliation tests — not a bundle slipping it in behind a warehouse screen. Until
then, stock leaves the system the way it has always left it: on the Inventory Depth adjustment
screen, which writes a `ship` movement with a reference. The pick list screen says so where an
operator will look for a ship button.
