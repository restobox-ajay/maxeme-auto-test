# Plan: Split warehouse from fulfillment region

Status: **proposal, nothing written yet.**
Base: `main` @ `0705a89`
Issue: #546

## The change in one line

`FulfillmentRegion` is doing two jobs. Rename it to `Warehouse`, then carve the commercial half
back out into a new `FulfillmentRegion`, linked one-to-one by a join table.

```
today:   FulfillmentRegion ── stock is counted against it        (physical)
                           └─ companies may buy from it, guest    (commercial)
                              visibility, guest price list

after:   Warehouse ──── warehouse_fulfillment_region ──── FulfillmentRegion
         stock            UNIQUE(fulfillment_region_id)    companies, guest
                                                           visibility, pricing
```

Stock sits in a building, not in a sales territory. Until these are separate rows, a client
can't have two regions served by one warehouse, or one region served by two.

**The tables land many-to-many ready; the code assumes one-to-one.** A unique index holds the
1:1, so enabling m:m later is dropping an index plus an allocation decision — never a data
migration.

## Three steps

Separate PRs are fine. Nothing else is being worked on in this area.

### Step 1 — rename everything to Warehouse

Pure rename. `FulfillmentRegion` → `Warehouse`, `fulfillment_region` → `warehouse`,
`fulfillmentRegion` → `warehouse`. Entity, repository, table, properties, variables, methods.

Behaviour is identical because nothing but names changed.

On the database side, `ALTER TABLE fulfillment_region RENAME TO warehouse` rewrites the foreign
key references in all six tables that point at it. **Four of the six are correct.**

| Table | Meaning | After rename |
|---|---|---|
| `product_inventory` | stock counts | correct |
| `order_inventory_reservation` | reservations against stock | correct |
| `inventory_bucket_change_log` | audit of counter changes | correct |
| `inventory_reconciliation_discrepancy` | count discrepancies | correct |
| `company_fulfillment_region` | which regions a company may buy from | **wrong — step 2** |
| `cart_item` | which region the customer is shopping in | **wrong — step 2** |

**Admin screens follow the entity** and become "Warehouses" — page title, table headers,
filters, nav, route. A screen still titled "Fulfillment Regions" would have its name, its
columns and its backing object all disagreeing. It will temporarily show commercial fields on a
screen called Warehouses; that's honest, and step 2 moves them off.

**Customer-facing wording stays "Fulfillment Region."** A customer genuinely picks a region, and
`cart_item` goes back to the region table in step 2. Those labels are right now and right
afterwards — they're only pointed at the wrong table in between. A customer must never see the
word "Warehouse"; it's internal vocabulary.

### Step 2 — carve the commercial side back out

```sql
CREATE TABLE fulfillment_region (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    name                     VARCHAR(160) NOT NULL,
    status                   VARCHAR(32)  NOT NULL DEFAULT 'Active',
    guest_visible            BOOLEAN      NOT NULL DEFAULT 0,
    guest_price_list_id      INTEGER      NULL REFERENCES price_list(id),
    default_for_new_company  BOOLEAN      NOT NULL DEFAULT 0,
    created_at               DATETIME     NOT NULL
);

-- same ids, so existing foreign key values stay valid
INSERT INTO fulfillment_region (id, name, status, guest_visible, guest_price_list_id,
                                default_for_new_company, created_at)
SELECT id, name, status, guest_visible, guest_price_list_id,
       default_for_new_company, created_at
FROM warehouse;

CREATE TABLE warehouse_fulfillment_region (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    warehouse_id          INTEGER NOT NULL REFERENCES warehouse(id) ON DELETE CASCADE,
    fulfillment_region_id INTEGER NOT NULL REFERENCES fulfillment_region(id) ON DELETE CASCADE,
    -- which warehouse serves this region first, once a region can have more than one.
    -- Nothing reads it yet; every row gets 0.
    priority              INTEGER NOT NULL DEFAULT 0
);

-- this index is the ONLY thing making it one-to-one. Dropping it enables many-to-many.
CREATE UNIQUE INDEX uniq_wfr_region ON warehouse_fulfillment_region (fulfillment_region_id);
CREATE UNIQUE INDEX uniq_wfr_pair   ON warehouse_fulfillment_region (warehouse_id, fulfillment_region_id);

INSERT INTO warehouse_fulfillment_region (warehouse_id, fulfillment_region_id)
SELECT id, id FROM warehouse;
```

#### The shape must be many-to-many even though the logic isn't

This is the point of using a join table rather than an FK column. Get it wrong here and the
later change is a data migration; get it right and it's dropping an index.

| | Ready for m:m? | |
|---|---|---|
| `warehouse_fulfillment_region` | yes | already a real m:m table |
| `uniq_wfr_region` | — | the one constraint holding 1:1; drop it and the shape allows m:m |
| `priority` | yes | ordering exists for when a region has more than one warehouse |
| `product_inventory` keyed by warehouse | yes | stock is per building regardless of how many regions it serves |
| `company_fulfillment_region` | yes | company → region, unaffected by how regions map to warehouses |
| `cart_item.fulfillment_region_id` | yes | the region the customer shops in, unaffected |

**Write the code assuming 1:1.** Availability and deduction resolve region → warehouse and take
the single result. Enabling m:m later means changing that resolution to handle a list and
deciding an allocation policy — that's logic, and it is not part of this job.

What matters now is that no table has to change shape and no data has to move when that day
comes.

Rebuild `company_fulfillment_region` and `cart_item` so their `fulfillment_region_id` points at
the new table again. **Only these two.** SQLite can't alter a foreign key in place, so it's
create-copy-drop-rename — follow whatever pattern existing migrations here already use. Values
don't change: same integers, same rows.

The commercial columns then need dropping from `warehouse`. **SQLite floor is 3.26, so there is
no `DROP COLUMN`** (that arrived in 3.35). Either rebuild the table without them, or leave them
unmapped — if you leave them, say so in the PR and make sure no entity maps them.

Split the admin UI to match: Warehouses sheds the commercial fields, and a Fulfillment Regions
screen appears for the new entity.

Move the code. Company assignment, guest visibility, pricing, registration and customer region
selection go back to `FulfillmentRegion`. Availability, deduction, reservations, stock counts
and recalc stay on `Warehouse`.

**The rule for anything ambiguous:** does this mean *where stock physically is* (warehouse) or
*who may buy it and at what price* (region)?

Misclassifying is harmless while it stays one-to-one — both resolve to the same row — so prefer
shipping over agonising. It only becomes a bug the day many-to-many is enabled.

### Step 3 — check the customer-facing surface

Confirm no email, invoice, PDF, checkout page or account screen has started saying "Warehouse."

## Why rename first rather than create a new table

The alternative is creating `warehouse` as a new table and moving four foreign keys onto it,
leaving `fulfillment_region` untouched. That avoids SQLite's rename-rewrites-FKs behaviour —
but that behaviour does four of the six correctly, so avoiding it means four table rebuilds
instead of two.

More importantly, a rename is mechanical and reviewable as a pure identifier diff. Classifying
116 references into physical and commercial in a single commit is judgment, and judgment is
where bugs come from. After the rename the system is behaviourally identical, so the carve-out
can be done incrementally.

## Acceptance

**Every existing test passes without changing a single expected value.**

That's the whole proof this was behaviour-preserving, and it's only available because the
mapping is forced one-to-one. If you're editing an assertion to make it pass, stop — something
got classified wrong.

Renamed identifiers in test code are fine and expected, as are admin-screen label assertions
that legitimately change from "Fulfillment Region" to "Warehouse." Changed *values* —
quantities, prices, availability, counts — are not.

Also run `bin/ci-migration-replay`.

## Not in this job

Inventory dimensions, bins, lots, serials, movement ledger, backorders, many-to-many regions,
transfer orders, warehouse capacity or address fields.

## Where to look

54 files in `src/` reference `FulfillmentRegion`, plus 44 functional tests and 24 templates.
Step 1 touches all of them mechanically. Step 2 touches only the commercial ones.

Physical — stays `Warehouse`:

```
src/Entity/ProductInventory.php
src/Entity/OrderInventoryReservation.php
src/Entity/InventoryBucketChangeLog.php
src/Entity/InventoryReconciliationDiscrepancy.php
src/Service/Inventory/OrderInventoryReservationService.php
src/Service/Inventory/OrderInventoryBucketResolver.php
src/Service/Inventory/AdminOrderStockValidator.php
src/Service/Inventory/InventoryBucketAuditLogger.php
src/Controller/Admin/InventoryController.php
src/Command/InventoryRecalcCommand.php
```

Commercial — moves back to `FulfillmentRegion` in step 2:

```
src/Entity/CompanyFulfillmentRegion.php
src/Entity/CartItem.php
src/Service/CompanyFulfillmentRegionService.php
src/Service/Pricing/CustomerPricingResolver.php
src/Service/Onboarding/Checks/PricingGroupAssignedCheck.php
src/Controller/Admin/CompanyFulfillmentRegionController.php
src/Controller/Admin/CompanyController.php
src/Controller/Customer/AuthController.php
src/Controller/Customer/HomeController.php
src/Command/BackfillCompanyFulfillmentRegionsCommand.php
```

## Two things that are easy to miss

**Four places write inventory quantities**, not one: `OrderInventoryReservationService`,
`InventoryController`, `ProductController`, `ProductImportService`. All four need to land on the
right warehouse. The import one is easiest to miss because bulk operations don't get read line
by line.

**`order.fulfillmentRegion` is a string snapshot, not a foreign key.** Orders store the region
*name* (`#[ORM\Column(length: 160, nullable: true)]`), so `sales_order`, `estimate` and `cart`
don't take part in this at all. Leave that property and its column name alone — it holds a
customer-facing region name, not a warehouse.
