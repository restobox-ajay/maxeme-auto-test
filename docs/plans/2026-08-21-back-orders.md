# Plan: Back orders

Status: **proposal, nothing written yet.**
Base: `main` @ `2f52341`
Issue: #548

## Prerequisite

**This plan assumes the planned warehouse/fulfillment-region split lands first**, owned separately from this work and already scoped as its own plan doc on `main`: `docs/plans/2026-08-21-warehouse-fulfillment-region-split.md` (issue #546, landed via PR #547). That doc renames today's `FulfillmentRegion` to `Warehouse` (the physical stock-keeping construct `ProductInventory` is keyed on) and carves a new `FulfillmentRegion` entity back out for the sales side (companies, guest visibility, pricing), linked **one-to-one** via a `warehouse_fulfillment_region` join table — its own words: "a client can't have two regions served by one warehouse, or one region served by two" *yet*; many-to-many is an explicitly separate, later step. That doc's own "Not in this job" list excludes both backorders and many-to-many regions, confirming this is clean, non-overlapping scope. Nothing below should start until that split has merged; at that point, §1's schema needs a short pass to repoint at `Warehouse` (see §10 — with the mapping 1:1, this is close to a pure rename, not the aggregation problem earlier drafts of this plan anticipated).

## Context

The wholesale-b2b-core order system (Symfony + Doctrine, SQLite) currently has **no backorder concept at all**: any order line that would exceed available stock is hard-refused — `AdminOrderStockValidator::errorFor()` blocks the admin save with HTTP 422, and `CartService::capOrRemove()` silently caps or removes over-quantity cart lines for customers, with `CheckoutController::buildAndPersistOrder()` re-checking and aborting the whole order at persist time as a final guard. There's no way for a customer or admin to place an order for more than is currently in stock, even when the business wants to accept that order and fulfill the rest later.

The goal is to let specific products (per fulfillment region) accept orders beyond current stock — with an admin-controlled on/off switch, a per-line partial-fulfillment split (ship what's in stock now, backorder the rest), a manually-entered restock ETA, and a hard ceiling on how much can be backordered at all so a product can't be oversold without limit.

Decisions confirmed with the user:
1. **Opt-in per (product, fulfillment region)** — not global.
2. **Per-line partial fulfillment** — one order line can be split fulfilled/backordered.
3. **Manual restock-ETA field**, admin-entered, no supplier/PO integration.
4. **A max-backorder-quantity cap per (product, region)** — backorder capacity itself is bounded, not unlimited.
5. **Both manual and automatic restock release, per (product, region)** — an admin-driven queue screen by default, with an opt-in toggle to auto-release on restock instead; both share one release mechanism and audit trail so the queue shows resolved entries the same way regardless of which mode handled them.
6. **An independent per-(product, region) toggle for whether the backorder cap shrinks as stock is replenished** — supports using backorder as a bounded preorder, without forcing that behavior on every SKU.

This repo has a mature, actively-used reservation/bucket system (`ProductInventory` cartHold/pending/approved buckets, `OrderInventoryReservation` per-order ledger, `OrderInventoryReservationService::reconcile()` driven automatically by `OrderInventoryReconciliationSubscriber` on every order flush, `InventoryBucketChangeLog`/`InventoryReconciliationDiscrepancy` audit trail, hourly `InventoryRecalcCommand` drift check). The design below extends that exact machinery with a fourth bucket rather than inventing a parallel system.

---

## 1. Schema changes

### `product_inventory` (`src/Entity/ProductInventory.php`)
All columns below live on `ProductInventory`, which post-split (§10/Prerequisite) is keyed on `(product, Warehouse)` — so `allowBackorder`/`maxBackorderQuantity`/`backorderCapShrinksOnRestock`/`autoReleaseOnRestock`/`backorderedQuantity` are all **per-warehouse-per-product**, same as `quantity`/`cartHoldQuantity`/`pendingQuantity`/`approvedQuantity` already are. A sales-side `FulfillmentRegion` fed by several warehouses will see a different combination of these per warehouse; deriving one region-level backorder answer from N warehouse-level ones is the aggregation design explicitly deferred to §10, not solved here.

Three config columns, landing together (one migration — they're meaningless independently):
- **`allow_backorder` BOOLEAN NOT NULL DEFAULT 0** — `isAllowBackorder()`/`setAllowBackorder()`, same style as `FulfillmentRegion::$guestVisible`. Kept as its own column (not merged into the cap field) specifically so an admin can flip backordering off temporarily without losing the configured cap number for later.
- **`max_backorder_quantity` INTEGER NULL** — `getMaxBackorderQuantity()`/`setMaxBackorderQuantity()`. `NULL` = no cap (only meaningful once `allowBackorder` is true); a set integer bounds the total units of this product+region that can ever sit in the `backordered` bucket at once.
- **`backorder_cap_shrinks_on_restock` BOOLEAN NOT NULL DEFAULT 0** — admin-configurable per (product, region). `false` (default): `max_backorder_quantity` is a static ceiling, unaffected by restocks — matches how the existing pending/approved buckets behave (nothing else in this app auto-shrinks a limit). `true`: preorder-style — every restock (§6) decrements `max_backorder_quantity` by the quantity that arrived, so incoming stock doesn't quietly reopen room for more backordering. See §6 for exactly where this is applied.
- **`auto_release_on_restock` BOOLEAN NOT NULL DEFAULT 0** — admin-configurable per (product, region), independent of the cap-shrink flag (a row can have either, both, or neither). `false` (default): restocking never touches the backorder queue by itself — an admin must release stock to waiting orders explicitly (§6, manual mode). `true`: the moment this product+region is restocked, the system immediately runs the same release logic an admin would run by hand, FIFO by order age, up to whatever just became available. Both modes write through the same release primitive and the same audit trail (§6) — the only difference is who/what triggers it and whose name shows up as the resolver.
- **`backordered_quantity` INTEGER NOT NULL DEFAULT 0** (separate migration, since it's a bucket-math column, not a config column) — a fourth bucket parallel to `cartHoldQuantity`/`pendingQuantity`/`approvedQuantity`, with `adjustBackordered(int $delta)` clamped at 0 exactly like `adjustPending`/`adjustApproved`. Written exclusively by `OrderInventoryReservationService::reconcile()`.
- **`getAvailableQuantity()`** becomes `quantity - cartHoldQuantity - pendingQuantity - approvedQuantity - backorderedQuantity`. Once a unit is promised to a backordered line, a later customer/admin must not be able to "cut in line" and consume it as fresh availability — net availability stays at or below zero until an admin explicitly releases stock to the queue (§6). This changes what `getAvailableQuantity()` means everywhere it's read (`AdminOrderStockValidator`, `CartService`, the admin inventory grid) — confirmed with you as the intended behavior.
- `manualAdjustment` stays untouched/unused (separate effort, #425 stage 2) — no interaction with this feature.

**Invariant (critical):** every one of these columns defaults to "off"/zero, and every code path that branches on backorder behavior (§3, §4) checks `allowBackorder` first. For any product+region where `allow_backorder = false` (the default, and the state of every existing row after migration), `BackorderSplitResolver` always resolves `backordered = 0`, so refusal/capping behaves byte-for-byte as it does today. Nothing about this feature changes behavior for a product that hasn't opted in.

### `sales_order_line` (`src/Entity/SalesOrderLine.php`)
Currently has zero fulfillment/status tracking. New columns (one migration):
- **`backordered_quantity` DECIMAL(12,2) NOT NULL DEFAULT '0.00'** — same type/precision as `quantity`, since it's a partial split of it.
- **`fulfillment_status` VARCHAR(32) NOT NULL DEFAULT 'Fulfilled'** — plain string column (this repo's convention — see `SalesOrder::status`), validated in code via a new backed enum (§2). A denormalized cache, recomputed every time `backorderedQuantity` changes, never set independently.
- **`restock_eta` DATE NULL** — admin-entered, meaningful only while `fulfillment_status !== Fulfilled`.

### `backorder_fulfillment_entry` (`src/Entity/BackorderFulfillmentEntry.php`, **new entity**)

This is what makes the queue screen (§6/§7) durable rather than derived-and-disappearing: once `SalesOrderLine::backorderedQuantity` returns to 0, nothing else in the schema remembers that a line was ever backordered or how it got resolved. One row per "backorder episode" for a line — opened the moment a line first goes non-`Fulfilled`, closed the moment it returns to `Fulfilled` — so both open and completed entries (manual or automatic) show up in one list, exactly as described.

- `id` PK
- `order`: M:1 `SalesOrder`, `onDelete: CASCADE`
- `line`: M:1 `SalesOrderLine`, `onDelete: CASCADE`
- `product`: M:1 `ProductCore` — denormalized onto the entry (not read through `line.product`) for cheap queue listing/filtering, matching how `OrderInventoryReservation` already denormalizes product+region rather than joining through the line.
- `fulfillmentRegion`: M:1 `FulfillmentRegion`, nullable — same denormalization reasoning.
- `status` VARCHAR(20) NOT NULL DEFAULT `'Open'` — `'Open'` | `'Complete'`. Not a duplicate of `SalesOrderLine::fulfillmentStatus` (which distinguishes Fulfilled/PartiallyBackordered/Backordered for order-facing display) — this is queue-facing: has this episode finished or not.
- `createdAt` DateTimeImmutable
- `completedAt` DateTimeImmutable NULL — set once, when `status` flips to `Complete`.
- No `quantity`/`restockEta`/mode columns here — those stay single-sourced on `SalesOrderLine` (already has `backorderedQuantity`/`restockEta`) so nothing can drift between the two tables. The *how it was resolved* (manual admin name vs. `'System'`) lives in the `SalesOrderLog` note each release already writes (§6) — the queue screen links out to that per-order log rather than duplicating it here.
- Not DB-enforced, checked in code: only one `Open` entry may exist per `line` at a time (a line can't be "backordered again" while already mid-episode) — SQLite has no partial unique index in this repo's existing patterns, so this is an application-level invariant, consistent with how e.g. `SalesOrderLine`'s own status logic is enforced in code rather than constraints.

**Where it's opened/closed**: `OrderInventoryReconciliationSubscriber` already inspects every changed `SalesOrderLine` on `onFlush` (that's how bucket reconciliation triggers automatically regardless of which controller made the change). Extend it to also compare each line's old vs. new `fulfillmentStatus`: transition *into* non-Fulfilled with no existing Open entry → create one; transition *into* Fulfilled with an Open entry → close it (`completedAt = now`). This reuses the one existing choke point that already sees every line mutation (admin create/edit, checkout, and both release paths in §6) instead of duplicating open/close calls at each of those call sites.

### `order_inventory_reservation` (`src/Entity/OrderInventoryReservation.php`)
- Add `BUCKET_BACKORDERED = 'backordered'` constant (fits existing `bucket VARCHAR(20)`).
- **Relax the unique constraint** from `(order, product, fulfillmentRegion)` to `(order, product, fulfillmentRegion, bucket)`. Required: a split line needs *both* a pending/approved reservation row (for its fulfilled portion) and a backordered reservation row (for its backordered portion) simultaneously, for the same `(order, product, region)` — today's unique index can't hold both. This is a SQLite table-rebuild migration (recreate/copy/drop/rename), not a simple `ADD COLUMN` — follow whatever recreate-table pattern the existing structural migrations in `migrations/` already use.

### Audit tables
No schema change — `inventory_bucket_change_log` and `inventory_reconciliation_discrepancy` both have free-string `bucket` columns already; they just gain a new `'backordered'` value.

---

## 2. New enum

`src/Enum/SalesOrderLineFulfillmentStatus.php`, mirroring `src/Enum/SalesOrderStatus.php` (backed string enum, DB column stays plain string, `tryFrom()` accessor so old/legacy rows never fail to hydrate):

```php
enum SalesOrderLineFulfillmentStatus: string
{
    case Fulfilled = 'Fulfilled';
    case PartiallyBackordered = 'Partially Backordered';
    case Backordered = 'Backordered';
}
```

Derived purely from the numbers (`backorderedQuantity <= 0` → Fulfilled; `0 < backorderedQuantity < quantity` → PartiallyBackordered; `backorderedQuantity >= quantity` → Backordered), recomputed every time `backorderedQuantity` is set.

---

## 3. The split + cap decision: `BackorderSplitResolver`

New `src/Service/Inventory/BackorderSplitResolver.php` — the single place that decides, for a requested quantity on one (product, region), how much is fulfilled vs. backordered vs. impossible. Reused by every refusal point in §4 so they can't drift, mirroring `AdminOrderStockValidator`'s existing "region resolution and rounding go through the same helpers reconcile() uses" philosophy.

Inputs: `requested`, `available` (from `getAvailableQuantity()`, plus the order's own hold added back — see below), `allowBackorder`, `remainingBackorderCapacity` (see cap math below).

```
fulfilled = min(requested, max(0, available))
remainder = requested - fulfilled
if !allowBackorder:
    backordered = 0            // remainder is a hard refusal, unchanged from today
else:
    backordered = min(remainder, max(0, remainingBackorderCapacity))
uncovered = remainder - backordered   // > 0 means refuse, even with backorder enabled
```

**Cap math** (`remainingBackorderCapacity`): `maxBackorderQuantity === null ? PHP_INT_MAX : maxBackorderQuantity - (inventory.backorderedQuantity - ownBackorderHold)`, where `ownBackorderHold` is this order's *own* current backordered reservation for this (product, region) — added back for the same reason `AdminOrderStockValidator::currentHoldFor()` already adds back pending/approved holds: re-saving an unchanged order must not self-block against its own prior reservation. `currentHoldFor()` today sums *all* of an order's `OrderInventoryReservation` rows for a key regardless of bucket — that already includes backordered rows once §1c ships, so a **bucket-filtered sibling** (or an added `bucket` param) is needed specifically to isolate the backordered contribution for this cap check, separate from the combined value used to add back against `getAvailableQuantity()`.

`uncovered > 0` is a hard refusal, using the same message shape as today's refusal, e.g.:
`"%s: %d requested in %s but only %d available and %d backorder capacity remaining."`

---

## 4. Where refusal logic changes

- **`AdminOrderStockValidator::errorFor()`**: replace the flat `$row['quantity'] > $available` check with a call through `BackorderSplitResolver` (available + ownHold as today, plus `allowBackorder`/cap from the `ProductInventory` row already being loaded). Refuse only when `uncovered > 0`. `shortfallsForOrder()` gets the same treatment so a backorder-eligible-and-within-cap shortfall isn't reported as a hard problem on quote→order conversion — though it's worth a distinct "N will be backordered" note rather than silence.
- **Line persistence** (`OrderController::createOrderFromRequest()` ~line 1661 and the edit() equivalent ~line 438): for each line, after validation passes, call `BackorderSplitResolver::split()` again to get the actual `fulfilled`/`backordered` numbers and set `SalesOrderLine::backorderedQuantity`/`fulfillmentStatus`/`restockEta` (new posted field `lines[i][restock_eta]`) before flush.
- **`CartService::capOrRemove()`**: before capping/removing, resolve the item's `ProductInventory` and run the same split. If `uncovered <= 0` (fully covered including backorder), don't cap or remove — replace the current "reduced/removed" message with an informational one ("N will ship now, M backordered"). If `uncovered > 0`, cap to `fulfilled + backordered` (today it caps to `available`) rather than removing outright when there's a nonzero backorder amount, or removes as today when there's genuinely nothing coverable. `CartItem` is unmapped/live — nothing persisted here, just display math re-run at checkout.
- **`CheckoutController::buildAndPersistOrder()`**: the pre-persist re-check (`reconcileAgainstRegion()` → `capOrRemove()`) is fixed for free once `capOrRemove()` has the above. Wherever it builds `SalesOrderLine` rows from cart items, each line runs through `BackorderSplitResolver::split()` the same way the admin path does. `restockEta` stays `null` at checkout (no admin present) — set later via order edit.

---

## 5. Reservation/bucket changes — `OrderInventoryReservationService::reconcile()`

Today: one `$targetBucket` (pending/approved/null from order status), one sum of each line's whole `quantity` per (product,region) key, one diff-against-ledger loop. Change to **two parallel sums**, both gated by the same "does this order hold anything" check (`bucketForStatus() !== null` — Draft/OnHold/Cancelled hold neither real stock nor a backorder promise):

```
for each line (only when targetBucket !== null):
    qty = round(line.quantity)
    backordered = round(line.backorderedQuantity)
    fulfilled = max(0, qty - backordered)
    stockByKey[key]        += fulfilled      // -> targetBucket (pending/approved), as today
    backorderByKey[key]    += backordered    // -> BUCKET_BACKORDERED
```

Extract the existing single-bucket "diff previous ledger vs target, apply deltas, log, persist/remove reservation row" loop into a private `reconcileBucket(order, targetByKey, bucket, bucketConstant, em)`, call it twice (once for stock, once for backorder), merge the returned changes into one `InventoryBucketsReconciledEvent` dispatch. The `syncedQuantity` import-recount carry-forward is approved-bucket-specific and doesn't apply to `backordered` — a restock release (§6), not an import sync, is what shrinks a backorder.

`applyBucketDelta()` gains a third arm calling `$inventory->adjustBackordered($delta)`.

`OrderInventoryBucketResolver::bucketForStatus()` and `resolveLineRegion()` are reused unchanged — no new status mapping needed.

---

## 6. Restock release — manual and automatic, both v1, sharing one primitive

Both modes exist from the start, chosen per (product, region) via `autoReleaseOnRestock` (§1). Manual is the default (`false`); an admin flips it on for SKUs they trust to auto-clear. Both paths — and every future path — funnel through one shared primitive so the bucket math, audit trail, and queue-entry lifecycle (§1's `BackorderFulfillmentEntry`) behave identically no matter who or what triggered the release.

### `src/Service/Inventory/BackorderReleaseService.php` (new)

- **`applyRelease(SalesOrderLine $line, int $amount, string $resolvedBy, EntityManagerInterface $em): void`** — the one primitive. Validates `$amount` against the line's current `backorderedQuantity`, reduces it, recomputes `fulfillmentStatus`, appends a `SalesOrderLog` note (`"Backorder release: %d unit(s) of %s assigned by %s (%d still backordered)."`, `$resolvedBy` either the admin's username or the literal `'System'`), persists the line. **Never touches `ProductInventory` buckets or the `backorder_fulfillment_entry` row directly** — `OrderInventoryReconciliationSubscriber` already watches every `SalesOrderLine` change via `onFlush`; the same flush that persists this line change re-runs `reconcile()` (grows pending/approved, shrinks `backorderedQuantity`, writes `InventoryBucketChangeLog`) and, per §1's extension, closes the entry if this release brought the line back to `Fulfilled`. One primitive, one downstream reaction, regardless of trigger.
- **`releaseAutomatically(ProductCore $product, FulfillmentRegion $region, EntityManagerInterface $em): void`** — computes current `getAvailableQuantity()`; if `<= 0`, no-op. Otherwise pulls open `SalesOrderLine`s for this product (via their `BackorderFulfillmentEntry`, `status = 'Open'`) whose *resolved* region matches, **FIFO by order id then line `sortOrder`** (no other priority signal exists in this schema — customer tier, SLA, none of that is modeled), and calls `applyRelease($line, min($remaining, $available), 'System', $em)` per line until availability is exhausted.

### Manual mode — admin queue screen

- **`src/Controller/Admin/BackorderController.php`** (new), **`templates/admin/backorder/`** (new):
  - **`index()`** (`/admin/backorders`) — lists `BackorderFulfillmentEntry` rows, default-filtered to `status = 'Open'` with a toggle/tab to also show `Complete` (both manually- and automatically-resolved — the "auto ones show up too, just already complete" requirement). Columns: order #, customer, SKU, region, backordered qty remaining, `restockEta`, and — for Complete rows — resolved-by/when (read from the order's `SalesOrderLog`, filtered to backorder-type notes, or a lightweight join, not duplicated onto the entry itself per §1).
  - **`show(SalesOrder $order)`** (`/admin/backorders/{id}`) — opens one order's open entries. For each: ordered qty, fulfilled so far, remaining backordered qty, live `getAvailableQuantity()` for that line's product+region, and an editable release-amount input defaulting to `min(remainingBackordered, available)`.
  - **`release(SalesOrder $order)`** (POST) — re-validates each submitted amount against *live* `backorderedQuantity` and `getAvailableQuantity()` at submit time (not trusted from the form — availability can move between page load and submit, same posture as `CheckoutController`'s existing re-check), then calls `BackorderReleaseService::applyRelease($line, $amount, $adminUsername, $em)` per line, single flush.

### Automatic mode — wired at the restock call sites

At the two places that grow `ProductInventory.quantity` (`InventoryController::update()`, `ProductImportService::applyInventory()`), **after `setQuantity()`, in this order, before the method's single flush**:
1. If `backorderCapShrinksOnRestock` is true, decrement `maxBackorderQuantity` by the arrived amount (`newQuantity - previousQuantity`, clamped at 0 — governs *new* backordering only, may drop below currently-outstanding `backorderedQuantity`).
2. If `autoReleaseOnRestock` is true, call `BackorderReleaseService::releaseAutomatically($product, $region, $em)`.

Both are independent per-row toggles (§1) — a SKU can shrink its cap without auto-releasing, auto-release without shrinking the cap, both, or neither (today's default: neither, i.e. today's behavior exactly, per the §1 invariant).

**Hourly `InventoryRecalcCommand`**: extend with a backordered-bucket drift check (sum `SalesOrderLine::backorderedQuantity` across Pending+Processing+Completed orders per product+region, compare to `ProductInventory::backorderedQuantity`, log via existing `InventoryReconciliationDiscrepancy`/`logDiscrepancy()` path). This stays **detection-only** — for a manual-mode SKU, positive availability sitting alongside open backorders is expected (nobody's released it yet) and must never be "corrected" by the cron; only genuine bucket-vs-ledger disagreement is a bug worth logging.

---

## 7. Admin/customer UX touchpoints (file paths only, not full UI)

- **Admin inventory grid** (`src/Controller/Admin/InventoryController.php`, `templates/admin/inventory/_rows.html.twig`, `index.html.twig`): new `Backordered` readonly column, plus four config controls — `Allow Backorder`, `Max Backorder`, `Shrink cap on restock`, `Auto-release on restock` — same inline-edit convention as the existing `Starting` column. `update()` action extended to accept all four.
- **Admin backorder fulfillment queue** (`src/Controller/Admin/BackorderController.php`, `templates/admin/backorder/index.html.twig`, `show.html.twig` — all new, §6): Open/Complete entry list (both manual and auto-resolved entries appear, per §1's `BackorderFulfillmentEntry`) + per-order manual release screen.
- **Admin order form** (`templates/admin/order/form.html.twig`, `OrderController`): existing "N available" hint (~line 2623-2628 built, ~128-133 rendered) gets a sibling backordered-amount hint and, when eligible, a `restock_eta` date input.
- **Admin order detail** (`templates/admin/order/detail.html.twig` ~227-236): backordered badge/column reading the new line fields.
- **Customer cart** (`templates/customer/cart/index.html.twig`): "N ship now, M backordered" messaging from the new `capOrRemove()` informational path.
- **Customer order history/detail** (`templates/customer/order/detail.html.twig`, `_list_rows.html.twig`): read-only surfacing of the same fields.
- Not committing to, but worth raising later: a dedicated "open backorders" admin report and a bulk-apply-ETA action.

---

## 8. Migrations (ordered, following `migrations/VersionYYYYMMDDHHMMSS.php` / `addSql()` convention)

1. `product_inventory`: add `allow_backorder` (BOOLEAN DEFAULT 0 NOT NULL) + `max_backorder_quantity` (INTEGER NULL) + `backorder_cap_shrinks_on_restock` (BOOLEAN DEFAULT 0 NOT NULL) + `auto_release_on_restock` (BOOLEAN DEFAULT 0 NOT NULL) together — all four are config, meaningless independently.
2. `product_inventory`: add `backordered_quantity` (INTEGER DEFAULT 0 NOT NULL).
3. `sales_order_line`: add `backordered_quantity` (NUMERIC(12,2) DEFAULT '0.00' NOT NULL), `fulfillment_status` (VARCHAR(32) DEFAULT 'Fulfilled' NOT NULL), `restock_eta` (DATE NULL) — one migration, all three land together.
4. `order_inventory_reservation`: rebuild the unique index to include `bucket` — SQLite table-rebuild (create-new/copy/drop/rename), must land in the same release as the code that starts writing `BUCKET_BACKORDERED` rows since it changes uniqueness semantics, not just adds a column. Every pre-existing row's `bucket` is `pending`/`approved`, so the copy is unambiguous.
5. `backorder_fulfillment_entry`: new table (`CREATE TABLE`) — `id` PK, `order_id` FK → `sales_order` CASCADE, `line_id` FK → `sales_order_line` CASCADE, `product_id` FK → `product_core`, `fulfillment_region_id` FK → `fulfillment_region` NULL, `status` VARCHAR(20) DEFAULT `'Open'` NOT NULL, `created_at`, `completed_at` NULL. Index on `(status)` and on `(line_id)` for the open-entry lookup `releaseAutomatically()`/`BackorderController` both need.

---

## 9. Decisions confirmed (v2 of this plan, after discussion)

- `getAvailableQuantity()` subtracts `backorderedQuantity` (§1) — prevents new orders from consuming stock already promised to a backordered line.
- `allow_backorder` and `max_backorder_quantity` are separate columns, not merged — lets an admin disable backordering temporarily without losing the configured cap.
- `backorder_cap_shrinks_on_restock` defaults to **off** (static cap) per product+region, but is always admin-configurable — some catalogs want backorder-as-preorder (cap shrinks as stock arrives), most don't.
- **Restock release supports both manual and automatic modes from v1**, chosen per (product, region) via `autoReleaseOnRestock` (default off). Both funnel through one shared `BackorderReleaseService::applyRelease()` primitive so bucket math, audit trail, and queue lifecycle are identical regardless of trigger (§6).
- A durable **`BackorderFulfillmentEntry`** record (§1) makes the admin queue show both open and already-resolved entries — including auto-resolved ones — in one place, since `SalesOrderLine::backorderedQuantity` alone loses that history once it returns to 0.
- Hitting the max-backorder cap is a **hard refusal** for the uncovered remainder (same as today's plain oversell refusal), not a queued/waitlisted state — nothing in the confirmed requirements asked for a waitlist beyond the cap.
- **Invariant**: a product+region with `allow_backorder = false` (the default) behaves identically to today, everywhere (§1).

---

## 10. Sequencing: the fulfillment-region / warehouse split lands first

Confirmed: `docs/plans/2026-08-21-warehouse-fulfillment-region-split.md` (issue #546) is already on `main`, scoped as three steps — rename `FulfillmentRegion`→`Warehouse`, carve a new sales-side `FulfillmentRegion` back out linked **one-to-one** via `warehouse_fulfillment_region`, then a customer-facing-wording check. This backorder plan does not implement that split and does not race it — see the Prerequisite section at the top.

**Why sequence this way rather than build backorder against today's single-entity model and rework it after:**
- All four buckets (`cartHold`/`pending`/`approved`/`backordered`) live as siblings on one `ProductInventory` row, diffed and applied together by `reconcile()`. `ProductInventory` is on the split doc's own "stays Warehouse" list, alongside `OrderInventoryReservation`/`InventoryBucketChangeLog`/`InventoryReconciliationDiscrepancy`/`OrderInventoryReservationService`/`AdminOrderStockValidator`/`InventoryController`/`InventoryRecalcCommand` — every one of them a file this backorder plan also touches (§ Critical files). Building against the pre-rename names now means touching all of them again right after, for no reason beyond timing.
- Two independent SQLite table-rebuild migrations (`product_inventory`'s region FK, `order_inventory_reservation`'s unique index) landing back-to-back on the same tables, once for the rename and once for backorder, is avoidable rework versus rebuilding once into the final shape.
- What's *not* at risk either way, and safe to treat as stable design regardless of which lands first: the enum (§2), the `SalesOrderLine` columns, `BackorderFulfillmentEntry`'s order/line relationship, `BackorderSplitResolver`'s split/cap arithmetic, and `BackorderReleaseService::applyRelease()`'s core mechanics — none of that cares what the region-ish key is called.

**Once the split has merged**, before starting implementation here: repoint §1's `ProductInventory` columns/FK at `Warehouse`. Note the split doc's schema is already **shaped** for many-to-many — `warehouse_fulfillment_region` is a proper junction table (`warehouse_id`, `fulfillment_region_id`), and the *only* thing holding it to one-to-one today is `CREATE UNIQUE INDEX uniq_wfr_region ON warehouse_fulfillment_region (fulfillment_region_id)`, called out in that doc as "what keeps it one-to-one — dropping it later is what enables many-to-many." So enabling M:M later is an index drop plus turning on aggregation logic, not a relationship migration — this backorder plan doesn't need to do anything now to keep that door open, it's already open. Because the mapping is enforced one-to-one for the foreseeable future (the split doc lists both "many-to-many regions" and "backorders" under its own "Not in this job"), this is close to a pure rename for backorder's purposes today: resolving "availability/backorder state for this sales region" is one lookup through `warehouse_fulfillment_region` to the one `Warehouse` row backing it, not an aggregation across several. Still worth stating the rule now, in case many-to-many ever does ship, so whoever builds it isn't guessing:
- **Region-level `allowBackorder`** would become `true` if **any** feeder warehouse has it enabled.
- **Region-level `maxBackorderQuantity`** would become the **sum** of the caps across feeder warehouses that have backordering enabled.
- `backorderCapShrinksOnRestock`/`autoReleaseOnRestock` wouldn't need a region-level rule even then — a restock event is inherently warehouse-scoped, so each warehouse applies its own toggle independent of how many regions it feeds.

This is documented as forward-looking guidance only — not something to build now, since it isn't in scope for the currently-planned split either.

---

## Verification

No automated test suite run is implied by this plan alone (it's a design document), but once implemented: exercise via `tests/Functional/` Codeception cests (there's already an analogous `QuoteAcceptanceStockShortfallCest.php` to pattern-match), specifically: (a) admin order create with backorder-enabled SKU exceeding stock but under cap → order saves, line split correctly, buckets updated, `BackorderFulfillmentEntry` opened; (b) same but requested amount exceeds available+cap → refused; (c) restock via `InventoryController::update()` with `backorderCapShrinksOnRestock`/`autoReleaseOnRestock` in all four on/off combinations → cap and queue behave per-flag independently; (d) `BackorderController::release()` against a live backordered order → line(s) shrink by exactly the assigned amount, entry closes when the line reaches Fulfilled, over-release attempts (beyond current availability or beyond remaining backordered qty) are rejected server-side; (e) an auto-released line produces an entry that shows up in `index()` already `Complete`, attributed to `'System'`; (f) customer checkout path parallel to (a)/(b); (g) a product+region with `allow_backorder = false` behaves identically to a pre-feature build (the invariant in §1); (h) hourly recalc command flags a manually-induced drift without altering it.

### Critical files
- `src/Entity/ProductInventory.php`
- `src/Entity/SalesOrderLine.php`
- `src/Entity/BackorderFulfillmentEntry.php` (new)
- `src/Entity/OrderInventoryReservation.php`
- `src/Enum/SalesOrderLineFulfillmentStatus.php` (new)
- `src/Service/Inventory/BackorderSplitResolver.php` (new)
- `src/Service/Inventory/BackorderReleaseService.php` (new)
- `src/Controller/Admin/BackorderController.php` (new)
- `templates/admin/backorder/` (new)
- `src/EventSubscriber/OrderInventoryReconciliationSubscriber.php`
- `src/Service/Inventory/OrderInventoryReservationService.php`
- `src/Service/Inventory/AdminOrderStockValidator.php`
- `src/Service/CartService.php`
- `src/Controller/Customer/CheckoutController.php`
- `src/Controller/Admin/OrderController.php`
- `src/Controller/Admin/InventoryController.php`
- `src/Service/ProductImport/ProductImportService.php`
- `src/Command/InventoryRecalcCommand.php`
