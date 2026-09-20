# Plan: Warehouse operations — barcodes, scanning, pick lists, bin map, transfers

Status: **proposal, nothing written yet.**
Base: `main` @ `44137d6`
Issue: #552
Prerequisite: **#550** (inventory depth) must land first.

## The change in one line

#550 makes it possible to record where stock is. This makes it fast to actually work — print a
label, scan a bin, walk a pick round in shelf order, move a pallet between warehouses.

Nothing here invents a new way to change stock. Every screen below is a faster front end on the
movement layer #550 builds.

## The rule that keeps this safe

**Scanning is a fast path into the same service, never a second write path.**

If a scanner writes to `inventory_detail` by its own route, there are two implementations of
"move stock" and they will drift — different validation, different reservation handling,
different audit. One movement service, and the handheld UI, the desktop adjustment screen and
the pick confirmation all call it.

The same applies to pick lists and transfers: they decide *what should move*, then hand it to
the same code that a manual adjustment uses.

## What gets built

### 1. Barcodes and labels

The one piece with no dependency on #550 — SKU labels can be printed today. It's here because
it's operationally useless until there are bins to stick them on.

Label targets:

| Target | Encodes | Why |
|---|---|---|
| Product | existing UPC/EAN if present, else internal SKU | scan to identify what you're holding |
| Bin | `warehouse_location.code` | scan to say where you are |
| Lot | lot id, with code and expiry printed human-readable | scan to capture lot at receipt or pick |
| Serial | the serial | scan to commit one specific unit |
| Container | container id (see §6) | scan to move a whole pallet |

**Symbology:** Code 128 for everything internal. Respect a product's existing UPC/EAN where one
is set rather than overprinting it — warehouse staff scan whatever the vendor put on the box.
GS1-128 is worth considering for lot labels because it can carry lot and expiry in one scan, but
it's only worth the complexity if trading partners require it.

Output is a PDF sized for the label stock, not a browser print of a web page. Sheet labels
(Avery-style grids) and continuous label printers are different page geometries; support both by
making the sheet a template, not a hardcoded layout.

Reprinting must be free and unlimited — a smudged label is the most common reason anyone opens
this screen.

### 2. Scanning

A scan-first interface for a phone or handheld, not a desktop screen made smaller. Assumes a
keyboard-wedge scanner (it types the barcode and presses Enter), which covers almost all cheap
hardware and needs no drivers.

Flows: receive, put away, pick, move, count, transfer.

Shape is the same throughout: **scan where → scan what → confirm how many.** Bin first, because
knowing where you are is what makes the rest unambiguous.

Three things that matter more than they look:

- **The idempotency key earns its keep here.** `inventory_movement_group.client_operation_id`
  already exists from #550. Warehouse wi-fi is bad; a submit that times out and gets retried
  must not move stock twice. The key is generated on the device before the request.
- **A failed scan must be loud.** Silent failure is how stock goes missing. Wrong bin, unknown
  barcode, quantity exceeding what's there — all block with a message the person can act on.
- **No free-text quantity where a scan will do.** Scanning ten serials is ten scans, not typing
  "10". That's the whole point of serial tracking.

### 3. Pick lists

Compile the lines of one or more orders into a walking route.

- **Ordered by `warehouse_location.sort_key`** — already on the table from #550. That column is
  the pick path, which is why it exists.
- **Batch picking** — several orders in one round, with the sort applied across all of them, and
  each pick attributed to the right order line.
- **Allocation happens at pick, not at compile.** #550 has deduction choose earliest-expiry then
  lowest bin. A pick list shows that choice; it doesn't freeze it hours ahead. Stock moves.
- **Confirm what was actually picked**, which is where the interesting case lives.

**A short pick is three facts, not one.** List says 20, picker finds 16:

1. The pick moved 16 — a movement of what physically happened.
2. Four units the system expected aren't there — a separate adjustment group with a count reason.
3. The order still needs 4 — top up from another lot, or backorder it (#548).

Writing one movement of 20 loses the discrepancy entirely and leaves stock overstated. Writing
one of 16 without (2) leaves the four sitting in the system forever.

**Reservation release is the handshake.** When stock is picked for an order, the `approved`
bucket releases the same amount in the same flush.
`OrderInventoryReconciliationSubscriber` is the existing choke point. Miss it and the units are
subtracted twice. This needs its own test — it is the single most consequential ordering
constraint in the whole warehouse stack.

### 4. Bin map

A visual layout of a warehouse. Needs coordinates, which `warehouse_location` doesn't have yet:

```sql
ALTER TABLE warehouse_location ADD COLUMN zone   VARCHAR(32) NULL;
ALTER TABLE warehouse_location ADD COLUMN aisle  VARCHAR(32) NULL;
ALTER TABLE warehouse_location ADD COLUMN map_x  INTEGER NULL;
ALTER TABLE warehouse_location ADD COLUMN map_y  INTEGER NULL;
```

All nullable. A warehouse that never opens the map never fills them, and `sort_key` continues to
drive picking on its own.

Uses: see occupancy at a glance, find a product's bins, spot empty space, sanity-check that
`sort_key` actually matches the physical walk (a pick path that zigzags is a real and invisible
cost).

Keep it flat. Zone and aisle are labels for grouping and filtering, not a hierarchy with its own
tables — three warehouses of a few hundred bins do not need a tree.

### 5. Transfer orders

Move stock between warehouses as a tracked document rather than two unrelated adjustments.

```sql
CREATE TABLE transfer_order (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    number             VARCHAR(32) NOT NULL,
    from_warehouse_id  INTEGER NOT NULL REFERENCES warehouse(id),
    to_warehouse_id    INTEGER NOT NULL REFERENCES warehouse(id),
    status             VARCHAR(16) NOT NULL,   -- draft|dispatched|received|cancelled
    dispatched_at      DATETIME NULL,
    received_at        DATETIME NULL,
    notes              TEXT NULL,
    created_at         DATETIME NOT NULL
);
CREATE UNIQUE INDEX uniq_transfer_order_number ON transfer_order (number);

CREATE TABLE transfer_order_line (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    transfer_order_id  INTEGER NOT NULL REFERENCES transfer_order(id) ON DELETE CASCADE,
    product_id         INTEGER NOT NULL REFERENCES product_core(id),
    lot_id             INTEGER NULL REFERENCES inventory_lot(id),
    serial             VARCHAR(120) NULL,
    quantity_requested INTEGER NOT NULL CHECK (quantity_requested > 0),
    quantity_dispatched INTEGER NOT NULL DEFAULT 0 CHECK (quantity_dispatched >= 0),
    quantity_received  INTEGER NOT NULL DEFAULT 0 CHECK (quantity_received >= 0)
);
```

Two movements, not one, exactly as #550's walkthroughs traced: dispatch moves stock to
`in_transit` owned by the **source** warehouse, receipt moves it to `available` at the
destination. Stock on a truck is correctly unsellable at both ends and still locatable, and
nothing falls into a gap between the two.

**Transfers must not inherit FEFO.** Sending earliest-expiring stock to a slower site is how it
expires there. Transfers name their lots explicitly, or prefer the longest-dated. The allocation
rule is a property of the operation, not a global setting — the opposite choice from customer
shipment, deliberately.

`quantity_dispatched` and `quantity_received` are separate columns because they genuinely differ:
that gap is stock lost in transit, and it needs to be visible rather than reconciled away.

### 6. Containers (license plates) — decide before building

A pallet holding mixed stock, moved and scanned as one unit. Scan the LP, the whole pallet moves.

Not in #550, so it's a decision here rather than an assumption:

```sql
CREATE TABLE inventory_container (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    code         VARCHAR(64) NOT NULL,
    warehouse_id INTEGER NOT NULL REFERENCES warehouse(id),
    location_id  INTEGER NULL REFERENCES warehouse_location(id),
    status       VARCHAR(16) NOT NULL DEFAULT 'open',
    created_at   DATETIME NOT NULL
);
ALTER TABLE inventory_detail ADD COLUMN container_id INTEGER NULL REFERENCES inventory_container(id);
```

**Recommendation: skip it for now.** It pays off when pallets move as units repeatedly —
cross-docking, full-pallet transfers, bulk storage. At 1–3 warehouses of this size, a transfer
naming its lots does the same work with no new concept. Adding `container_id` later is
`ADD COLUMN`, which is cheap, so deferring costs almost nothing.

If it is built, the container is a **grouping, not a location** — stock is still in a bin, the
container just says several rows move together. Making it an alternative to `location_id` puts
the same stock in two places.

## Screens

- Label printing — pick target, quantity, template; batch print for a bin or a receipt
- Scan console — the six flows above, phone-shaped
- Pick list index, detail, and confirmation
- Bin map, per warehouse
- Transfer order index, detail, dispatch, receive
- Picker performance and short-pick rate, if the data is there anyway

Filter state in URL GET params on every list. Standing requirement in this codebase.

## Acceptance

- **Every existing test passes unchanged.** Nothing here alters behaviour for a `simple`
  product, which is every product until someone opts one in.
- Every stock change made by a scanner produces the same movement rows as the equivalent change
  made on the desktop adjustment screen. Same service, provably.
- Replaying a submit with the same `client_operation_id` moves stock once.
- A short pick produces both the movement and the adjustment, and releases exactly what was
  picked.
- A pick list is ordered by `sort_key`, ascending, across a batch of several orders.
- A dispatched-but-not-received transfer leaves stock sellable at neither end.

## This can be a bundle

Build it as one, on the same rule as #550:

> **Core reads nothing this writes.**

Everything here is a faster front end. Scanning, pick lists and transfers all call #550's
movement service; nothing in core reads `transfer_order`, the bin map coordinates, or a label
template.

Remove it and stock still moves — by hand, on the desktop adjustment screen. Slower, not broken.
There is no precondition and nothing to migrate.

**Data outlives the bundle.** Transfer orders and the movements they created stay in the
database and stay visible in #550's movement history, because they are history and history
doesn't stop being true when a screen is uninstalled. Only the screens that *manage* them go
away. Worth being deliberate about rather than discovering.

## Not in this job

Receiving against a purchase order — that's procurement, and it needs a vendor and a PO to
receive against. Manual receipt through the adjustment screen already exists from #550.

Also out: inventory valuation and costing, carrier integration and shipping labels (different
thing from product labels), automated replenishment between warehouses, voice picking, and
anything requiring a native mobile app.

## Easy to get wrong

**Two pickers, one bin.** Nothing prevents two people picking the same row at once. #550's
`CHECK (quantity >= 0)` and conditional decrement stop the corruption; this job owes the second
scanner a sensible message rather than a constraint violation.

**The pick path is a guess until someone walks it.** `sort_key` is entered by a person and is
wrong the day a shelf is renumbered. The bin map exists partly to make that visible.

**A scanner that works only online will be used offline.** Decide deliberately: block with a
clear message, or queue locally and sync. Queuing is more work and needs the idempotency key to
be watertight. Blocking is honest and probably right at this scale — but it must block, not
silently drop.

**Label reprints are not audit events.** Printing is not moving. Don't write movement rows for
it; a print log if anything.

**Batch picking attribution.** Picking for four orders in one round means every scan carries an
order line. Getting this wrong shows up as stock leaving against the wrong order, which is very
hard to unpick afterwards.
