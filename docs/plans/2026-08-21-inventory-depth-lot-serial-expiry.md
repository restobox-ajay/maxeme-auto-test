# Plan: Inventory depth — warehouse, bin, lot, serial, expiry

Status: **proposal, nothing written yet.**
Base: `main` @ `37565fb`
Issue: #550
Prerequisite: **#546** (warehouse / fulfillment region split) must land first.

## The change in one line

`product_inventory` keeps one number per product per warehouse. This adds a second layer that
says *where that number physically is* — which bin, which lot, which serial, what condition —
without changing what the number means.

```
today:    product_inventory(product, warehouse).quantity = 47

after:    product_inventory(product, warehouse).quantity = 47      ← unchanged, still the truth
          inventory_detail: A-12 / lot L1 / available  30
                            B-03 / lot L2 / available  17
                            B-03 / lot L1 / damaged     2          ← not counted
```

Per product, opt-in. A client with one warehouse and no bins never sees any of it.

## How this fits what already exists

There is already a mature bucket and reservation system, and this plan extends it rather than
running beside it:

| Existing | |
|---|---|
| `ProductInventory` | `quantity` plus `cartHold` / `pending` / `approved` / `backordered` buckets |
| `getAvailableQuantity()` | `quantity − cartHold − pending − approved` |
| `OrderInventoryReservation` | per-order ledger of what each order holds |
| `OrderInventoryReconciliationSubscriber` | recomputes buckets on every flush |
| `InventoryBucketChangeLog` / `InventoryReconciliationDiscrepancy` | audit and drift records |
| `InventoryRecalcCommand` | hourly drift check |

**The two layers stack, they don't compete.**

- `quantity` is the **physical total** — what's in the building and sellable.
- The buckets are **claims** against it — who has spoken for some of it.
- `inventory_detail` explains **where the physical total is** — bin, lot, serial, condition.

So detail rows reconcile to `quantity`, never to `getAvailableQuantity()`. The bucket machinery
is untouched by this work and keeps behaving exactly as it does now.

## The invariant

```
product_inventory(product, warehouse).quantity
  == SUM(inventory_detail.quantity) WHERE status = 'available'
                                      AND warehouse = that warehouse
```

Only `available` counts. `staged`, `in_transit`, `damaged`, `quarantine`, `sold`, `scrapped`,
`lost`, `returned` do not — they are physically present or accounted for, but not sellable.

Enforced in the same transaction as the movement that changes it. That's only possible because
it's one database, and it is the whole reason this is safe.

## The three rules

1. **A detail row is a unique `(product, warehouse, location, lot, serial, status)` combination.
   Its identity never changes — only its `quantity` moves.** Rows are never deleted; a row at 0
   is history.
2. **A movement moves quantity from one detail row to another.** `from = NULL` means entering
   the system. Serialized and non-serialized use the identical code path; a serial simply never
   exceeds 1.
3. **`product_inventory.quantity` is what is sellable, not what is physically present.** Core
   knows "you can sell 30," never why the other 4 aren't sellable. That's this layer's job.

## Schema

```sql
CREATE TABLE warehouse_location (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    warehouse_id INTEGER NOT NULL REFERENCES warehouse(id) ON DELETE CASCADE,
    code         VARCHAR(64) NOT NULL,
    type         VARCHAR(16) NOT NULL,        -- pick | receiving | staging | transit | hold
    sort_key     INTEGER NOT NULL DEFAULT 0,  -- pick-path traversal order
    status       VARCHAR(16) NOT NULL DEFAULT 'Active'
);
CREATE UNIQUE INDEX uniq_wh_location ON warehouse_location (warehouse_id, code);

CREATE TABLE inventory_lot (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id  INTEGER NOT NULL REFERENCES product_core(id) ON DELETE CASCADE,
    code        VARCHAR(64) NOT NULL,
    expiry      DATE NULL,
    received_at DATETIME NULL,
    source      VARCHAR(160) NULL
);
-- deliberately NOT unique on (product_id, code) — see "easy to get wrong" below
CREATE INDEX idx_lot_lookup ON inventory_lot (product_id, code);
CREATE INDEX idx_lot_expiry ON inventory_lot (product_id, expiry);

CREATE TABLE inventory_detail (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id   INTEGER NOT NULL REFERENCES product_core(id) ON DELETE CASCADE,
    warehouse_id INTEGER NOT NULL REFERENCES warehouse(id),
    location_id  INTEGER NULL REFERENCES warehouse_location(id),
    lot_id       INTEGER NULL REFERENCES inventory_lot(id),
    serial       VARCHAR(120) NULL,
    status       VARCHAR(16) NOT NULL,
    quantity     INTEGER NOT NULL DEFAULT 0 CHECK (quantity >= 0),
    updated_at   DATETIME NOT NULL
);

CREATE UNIQUE INDEX uniq_inventory_detail ON inventory_detail (
    product_id, warehouse_id, COALESCE(location_id,0),
    COALESCE(lot_id,0), COALESCE(serial,''), status);

-- a serial may have many rows across its life, but only ever ONE with quantity > 0
CREATE UNIQUE INDEX uniq_live_serial ON inventory_detail (product_id, serial)
    WHERE serial IS NOT NULL AND quantity > 0;

CREATE INDEX idx_detail_available ON inventory_detail (product_id, warehouse_id)
    WHERE status = 'available' AND quantity > 0;

CREATE TABLE inventory_movement_group (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    client_operation_id VARCHAR(64) NOT NULL,
    type                VARCHAR(16) NOT NULL,   -- receipt | putaway | pick | ship | transfer
                                                -- | move | status_change | adjustment
    reason              VARCHAR(255) NULL,
    actor               VARCHAR(160) NULL,
    reference           VARCHAR(160) NULL,      -- order no, RMA — required on ship
    occurred_at         DATETIME NOT NULL
);
CREATE UNIQUE INDEX uniq_movement_group_op ON inventory_movement_group (client_operation_id);

CREATE TABLE inventory_movement (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id       INTEGER NOT NULL REFERENCES inventory_movement_group(id) ON DELETE CASCADE,
    product_id     INTEGER NOT NULL REFERENCES product_core(id),
    from_detail_id INTEGER NULL REFERENCES inventory_detail(id),
    to_detail_id   INTEGER NULL REFERENCES inventory_detail(id),
    quantity       INTEGER NOT NULL CHECK (quantity > 0)
);
CREATE INDEX idx_movement_from ON inventory_movement (from_detail_id);
CREATE INDEX idx_movement_to   ON inventory_movement (to_detail_id);

-- opt-in, per product
ALTER TABLE product_core ADD COLUMN inventory_mode VARCHAR(16) NOT NULL DEFAULT 'simple';
```

`ADD COLUMN` is cheap in SQLite. Floor is **3.26** — no `DROP COLUMN`, no `ALTER COLUMN`.

## The mode flag

`simple` (default) — nothing changes. Admin edits a quantity directly, as today.

`dimensional` — the quantity field is replaced by a link to the adjustment screen, and the
number becomes derived. Nobody types it.

Switching `simple → dimensional` needs an **opening balance movement**: current quantity in,
location and lot unspecified. Honest — "we have 47, we don't yet know where." Switching back
keeps the detail rows dormant rather than deleting them.

## Deduction must move detail rows

This is the part that can't be deferred. If an order deducts 5 from a dimensional product and
no detail row moves, the invariant breaks immediately.

So deduction picks a source automatically: **earliest expiry first, then lowest `sort_key`**.
No UI, no pick list, no operator choice. That's enough to keep the two layers consistent.

The efficient version — pick lists, scanning, confirming what was actually taken — is warehouse
operations, a later job. This job only needs deduction not to lie.

**Reservation release is the one handshake.** When stock physically moves out for an order, the
`approved` bucket must release the same amount in the same flush, or the units are subtracted
twice. `OrderInventoryReconciliationSubscriber` already watches every `SalesOrderLine` change on
`onFlush` — that's the existing choke point and where this belongs. It needs a test of its own.

## The UI

Data this shape is only worth having if someone can work with it. These are part of the job,
not a follow-up.

**Stock by product.** The detail breakdown for one product — every warehouse, bin, lot, serial
and status, with the total reconciling visibly to `product_inventory.quantity`. For a
`dimensional` product this replaces the editable quantity field on the current inventory screen.

**Stock by location.** What is in bin A-12 right now, across every product. The counterpart view,
and what a cycle count is run from.

**Stock adjustment.** Create, move between bins, change status, write off. Full form: product,
warehouse, bin, lot, serial, status, quantity, reason. Every submission writes one
`inventory_movement_group` with its movements, so the reason and the actor are on the record.
This is also how stock enters until receiving exists.

**Bin management.** CRUD over `warehouse_location` — code, type, sort key, status. Bulk creation
of a range (aisle, rack, shelf) matters; nobody types 200 bins one at a time.

**Lot management.** CRUD and list over `inventory_lot` — code, expiry, source, received date —
plus an expiring-soon view with a configurable window. Lot code and expiry always display
together, because the code alone is ambiguous.

**Serial lookup.** Where is serial X now, and its full history: received when, put away where,
shipped on which order.

**Movement history.** The ledger, filterable by product, warehouse, bin, lot, date range, type
and actor. This is the audit surface — "who moved this and why" has to be answerable without a
database client.

**Cycle count.** Count a bin, enter what was actually found, and have the differences written as
adjustment movements with a count reason. Discrepancies are movements like any other, so no
separate reconciliation table is needed.

**Mode toggle.** `simple | dimensional` on the product edit screen, with the opening-balance
prompt on switching.

**Filter state lives in URL GET params** on every list above, so a copied URL reproduces the
exact result. Standing requirement in this codebase.

These extend the existing admin inventory area rather than forming a separate section.

## Drift detection

`InventoryRecalcCommand` already runs hourly and logs bucket-vs-ledger disagreement through
`InventoryReconciliationDiscrepancy`. Extend it with a detail-vs-quantity check for dimensional
products, using the same path. Detection only — never auto-correct.

## The four inventory write paths

All four must route through the movement layer for dimensional products:

| Writer | |
|---|---|
| `OrderInventoryReservationService` | reservations and deduction |
| `InventoryController` | admin manual edit — replaced by the adjustment screen |
| `ProductController` | product save |
| `ProductImportService` | **product import** |

The import one is the dangerous one: a CSV setting a flat quantity would wipe out dimensional
stock unnoticed, because bulk operations don't get read line by line. Either it routes through
the movement layer or dimensional products are skipped with a warning.

## Acceptance

- **Every existing test passes unchanged.** Nothing here alters behaviour for a `simple`
  product, which is every product until someone opts one in.
- The invariant holds after every operation — a test that receives, moves, picks, damages and
  scraps, checking `quantity == SUM(available)` at each step.
- A bin move does **not** change `quantity` — both rows are `available`. Cheapest regression
  test there is for someone recomputing from the wrong status set.
- Two concurrent decrements of the same row cannot go negative.
- Every list screen reproduces its exact result from a copied URL.

## This can be a bundle

Build it as one. The rule that makes it possible, and which every piece here must respect:

> **Core reads nothing this writes.**

Core reads `product_inventory.quantity`. It never reads `inventory_detail`.

That works because **`quantity` is maintained, never derived on read.** Every movement writes the
detail rows and the core total in the same transaction. The number is always sitting in
`product_inventory`, correct, whether or not anything is looking at the detail behind it.

So removing the bundle loses the breakdown, not the number. A product at 47 across three bins is
still a product at 47; it just goes back to an editable field. **No precondition, no conversion,
no migration, no data loss.**

The `inventory_mode` flag stays in core — core has to know whether to render an editable quantity
field, and it can't ask the bundle that without depending on it. But `dimensional` is only
selectable when the bundle is installed. No bundle, only `simple`.

The asymmetry is worth noticing because it falls straight out of the invariant:

| | needs |
|---|---|
| `simple → dimensional` | an opening balance movement — detail rows have to come from somewhere |
| `dimensional → simple` | nothing at all — the total is already there |

House precedent for the bundle shape: `FeeBundle`, `ShippingBundle`, `TaxBundle`, with contracts
in `src/Contract/Bundle/`.

## Not in this job

Receiving (vendor, PO, bill), pick lists, scanning and handheld terminals, barcode creation and
printing, the visual bin map, transfer orders between warehouses, FEFO as an operator-facing
workflow, many-to-many regions.

The line: this job is the **records and the screens to manage them**. Warehouse operations —
making a picker's round efficient, putting a scanner in someone's hand, drawing the floor — is
the next job and builds on exactly these tables.

## Easy to get wrong

**Nothing guards non-serial quantity.** `uniq_live_serial` makes double-selling a serial
impossible at the database level. Quantity has no equivalent — two pickers each taking 20 from a
row of 25 reaches −15 with nothing complaining. Both halves are needed:

```sql
CHECK (quantity >= 0)                                    -- structural
UPDATE inventory_detail SET quantity = quantity - :n
 WHERE id = :id AND quantity >= :n                       -- conditional, check affected rows
```

The `CHECK` stops the write; the conditional `UPDATE` turns it into "someone got there first"
instead of a constraint exception in a picker's face.

**Expiry is not self-executing.** Nothing makes a lot stop being sellable on its date. Status
only changes via a movement, so expiry needs a **scheduled sweep** writing real `status_change`
groups. Until it runs, expired stock is on sale. Filtering expiry inside the availability query
instead would break `quantity == SUM(available)`, which is the one thing holding this together.

Convention: `expiry` is the **last usable day**. Stock dated the 30th is sellable through the
30th and unsellable from the 1st.

**`inventory_lot.code` cannot be unique per product.** Vendors reuse batch codes across
production runs with different expiry dates. `UNIQUE(product_id, code)` is the obvious index and
it would force two genuinely different batches into one row. A lot's identity is its row id, so
the UI must show code *and* expiry together — the code alone is ambiguous to the person holding
the box.

**Terminal rows accumulate.** `(product, warehouse, NULL, lot, NULL, 'sold')` is one row by
definition, so shipping the same lot to two customers grows one row rather than making two. The
row answers "how much"; only `inventory_movement_group.reference` answers "to whom." Recall is
therefore a ledger query, which is why `reference` is required on ship groups.

**Sold, scrapped and lost rows keep their warehouse but drop their location.** That's what makes
"which site shipped it" and "where was it lost" answerable. `location_id` is nullable for
exactly this reason.

**Mixed-lot bins are normal.** One bin holding two lots with different expiry dates is two rows,
and the schema handles it — but it means scanning a bin no longer identifies stock. The lot
label has to be scanned too.

**A short pick is more than one fact.** What was found, what is missing, and how the order was
completed are separate movement groups with separate reasons. Writing one group loses the
discrepancy entirely.
