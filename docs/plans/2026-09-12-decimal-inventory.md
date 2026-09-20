# Decimal quantities: prove it, then fix what the proof catches

## The job

**Write six Cests. That is the task.** Everything else in this file is background.

Each one types a decimal quantity into a real screen, saves, and reads the figures back out of
the database columns. If the stored number is not the number typed, that is the bug.

| # | screen | route |
|---|---|---|
| 1 | estimate — create | `/admin/estimate/create` |
| 2 | estimate — edit | `/admin/estimate/edit/{id}` |
| 3 | sales order — create | `/admin/order/create` |
| 4 | sales order — edit | `/admin/order/edit/{id}` |
| 5 | invoice — create | one screen, order optional |
| 6 | invoice — edit | does not exist yet |

### Read this before writing 5 and 6

**There is one create screen and one edit screen per document. The invoice currently has neither.**

- It has **two create routes** — `admin_invoice_create` and `admin_order_invoice_new` — sharing a
  single template. The templates were folded together; the routes were not. They should be one
  route that takes an optional order. Whether the save draws down an order's uninvoiced quantities
  is a branch inside the save, not a second screen.
- It has **no edit route at all**. `admin_invoice_edit` does not exist.

Fix that first, then write 5 and 6 against the result. Do not write two create tests to match the
two routes — that pins the shape we are removing.

### What each Cest does

1. Type something like `2.5` as the quantity. Save.
2. Read `quantity` back **from the column**, not off the page.
3. Assert it is `2.5`. Not `2`. Not `3`.
4. Then assert what the save *moved*: the reserved / hold figures on `product_inventory`.
   This is where the truncation actually lives.
5. Assert the row that should not have changed did not.

That is it. Surgical. Six files or one file with six methods.

### Why step 4 is the real test

Sell-side documents already store `decimal(14,4)`, so the line will probably come back correct.
Inventory stores `int`. So the interesting assertion is not "the line says 2.5" — it is
"`sales_hold_quantity` says 2.5 and not 3".

Expect some of the six to fail. **That is the deliverable** — it tells us exactly where the
boundary truncates, instead of guessing.

---

## Background

Sell-side documents store `decimal(14,4)`. `ProductInventory` stores `int`. Somewhere in between,
`2.5` becomes `2` or `3` and nobody is told.

Two wrong numbers this has already produced:

- a demand for `50.5` reserved `51` — **more than was asked for**
- 4 units arrived, 8 were released; `sales_hold_quantity` read 13 against a `quantity` of 9

Decimals are real here: distributors set the base unit to CASE and open cases at their own
discretion. A half-open case is 2.5 cases. There is no ratio to model — the client decides what a
unit means and types a number, and we store it.

## Explicitly NOT in scope

- **Overselling.** Different problem. Don't touch the policy.
- **Warnings about fractions.** None exist, none should. `2.5` is just a quantity.
- **Cart and checkout integer validation.** Customers buy whole numbers *for now*; that rule is
  enforced on those two screens and is right where it is. Once saved, a customer's document is
  decimal like any other.
- **Admin intent.** An admin who types 2.5 meant 2.5. No gate, no prompt.

---

## If the tests fail, here is where to look

Don't sweep. Fix what a failing test points at.

**The columns** — `src/Entity/ProductInventory.php`, five `int` properties: `$quantity` (27),
`$reservedQuantity` (30), `$salesHoldQuantity` (49), `$backorderedQuantity` (78),
`$maxBackorderQuantity` (98). Setters at 309/311/318/330/337, including `max(0, …)` clamps.

Plus `backorder_capacity` on `SalesOrderLineStockOverride` and `InvoiceLineStockOverride`, left
`int` because its source was.

**The casts** — the boundary where demand meets inventory:

```
src/Doctrine/Type/QuantityType.php                     ← look here FIRST
src/Service/Inventory/AdminOrderStockValidator.php
src/Service/Inventory/*ReservationSubject.php
src/Service/Inventory/BackorderSplitResolver.php
src/Service/Uom/ProductAvailableUnitService.php
```

Leave display rounding alone: `src/Twig/CartExtension.php`,
`src/Api/ProductPresenter.php`, `src/Service/Inventory/InventoryGridColumns.php`.

**Do not try to solve this with the display filters.** Rounding for display is already centralised
and already correct — `src/Service/DisplayNumber.php` and the `|qty` / `|price` / `|amount` Twig
filters own it. The scales it enforces:

| filter | column | places |
|---|---|---|
| `qty` | `decimal(14,4)` | max 4, min 0, no thousands separator |
| `price` | `decimal(18,6)` | max 6, min 2 |
| `amount` | `decimal(12,2)` | 2 |

The casts this task removes are **arithmetic before a write**, not formatting for a screen —
`(int) round((float) $qty)` on the way into a reservation. `DisplayNumber` never sees them, which
is precisely why they survived.

**The sentinel** — `remainingBackorderCapacity()` returns `PHP_INT_MAX` for "no cap" (line 445).
Fine as an int, nonsense as a decimal: `PHP_INT_MAX` does not survive a round trip through a
`decimal(14,4)` column, and arithmetic on it overflows into float garbage.

Replace it with a **reasonable ceiling that the column can actually hold**:

```
9999999999.9999    // decimal(14,4) max: 10 digits, then 4
```

It stores, compares and subtracts without blowing up, and nobody backorders ten billion cases. Use
a named constant, not a literal. Caller to update: `BackorderSplitResolver.php:52`, whose docblock
currently says "PHP_INT_MAX when uncapped".

## Repo rules that bite

- Migrations **may not read `$schema`** (SQLite introspection fatals here). Use
  `App\Doctrine\SqliteMigrationIntrospection`. `Version20260919164500` is the worked example of a
  SQLite column-type rebuild that preserves values.
- A green suite proves **nothing** about migrations. Only `bin/ci-migration-replay` runs the chain.
- `LineDenomination::boxUntouched($qty, $qty_rendered)` compares hidden twins **byte for byte**.
  Never reformat a value on its way back to a form.
- SQLite renders `decimal(14,4)` as `2.5000` from memory and `2.5` re-read from the database.
  Assert accordingly.
- `DenominationStaysOutOfTheInventoryLayerTest` forbids `LineDenomination` inside
  `src/Service/Inventory/`. Formatting belongs in the entity.
- Don't run the full Codeception suite (~59 min). `php vendor/bin/phpunit tests` is ~2 min.
