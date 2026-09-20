# Inventory valuation/aging/analytics dashboard

## Status: BLOCKED on an owner decision (2026-09-17) — scoped as far as it honestly can be without one

## The ask

Owner: *"finance, scope it"* — item #7 of the Product Hub gap review ("No inventory
valuation/aging/analytics dashboard").

## The blocker, stated plainly before anything else

`docs/QUEUE.md:406-407` — the app's own standing ruling: *"What 'accounting is parked' actually
covers, because it was being over-applied: general ledger, COGS, **inventory valuation** and
landed cost. Nothing else."*

This ruling exists precisely because the parking decision was being *mis-applied* elsewhere (it
was wrongly used to defer purchase-side payments and tax breakdown, and that was explicitly
withdrawn on 2026-09-09 — see `#655`). But inventory valuation itself is named as correctly
in-scope for the parking, not an example of over-application. So "finance, scope it" and "inventory
valuation is parked" are two standing instructions that currently disagree, and this plan can't
resolve that disagreement on its own — it needs an explicit call from the owner: either formally
unpark inventory valuation (all of it, or a named narrower slice), or this plan stays a document,
not a build order.

## What exists today (the honest floor to build from, if unparked)

- `ProductCore::$costPrice` (getter line 324) — a single scalar per product. No average cost, no
  FIFO/LIFO layers, no cost-state entity of any kind.
- `InventoryMovement` (`modules/InventoryDepthBundle/src/Entity/InventoryMovement.php`) is
  quantity-only: `id, group, product, fromDetail, toDetail, quantity, reversesMovement`. No value
  field, no unit-cost-at-time-of-movement capture. A movement ledger that tracks quantity
  perfectly and value not at all.
- The entire "valuation" capability that exists anywhere in the app today is one line:
  `StockController::availabilityAndValue()` computing `value = costPrice × onHand` for a single
  product on the SKU hub page. That's it — no report, no by-location or by-category rollup, no
  history.
- **No inventory aging** anywhere. (AP Aging exists and is unrelated — `ApAgingReport` pivots
  vendor-bill balances by due date; it never joins `ProductInventory` or lots. Confirmed distinct,
  not a false positive.)
- **No COGS** anywhere — grepped `src/`/`modules/` for "cogs"/"cost of goods"/"averageCost"/"landed
  cost": zero hits in application code (only a docblock on `GoodsReceiptLine.php:106` naming
  moving-average/FIFO/standard costing as explicitly deferred, separate work).

## Scoping honesty check: even the reference comparison doesn't have this working either

A separate audit against `wholesale-inventory-core` (a spec-driven reference build) confirmed its
costing module has a `MovingAverageCostingStrategy` and a `LandedCostAllocator` present as classes,
but the strategy's own docblock states plainly that *"the other four [costing methods] ship in v1
behind the same interface, but no strategy implementation is registered yet"* and that even
moving-average costing *"is not wired into `StockMovementRecorder::post()` yet"* — no real
order/receiving path drives cost end-to-end there either.

So the honest framing for "scope it" is: **neither this app nor the more advanced reference system
has a working cost ledger today.** This is not "add a report on top of data we already track
correctly" — it's "build the cost-tracking mechanism from zero, then build a report on top of it."
That's a materially bigger ask than the phrase "inventory valuation dashboard" suggests on its own,
and worth the owner seeing plainly before deciding whether to unpark it.

## If unparked, the two possible sizes of this work

### Small: a valuation SNAPSHOT report, no ledger changes

Group the existing `costPrice × onHand` computation by warehouse/category instead of by single
product, using data that's already correct today (on-hand quantities are solid; only the cost side
is thin). This is buildable in isolation, ships a real, useful number, and doesn't touch anything
parked — **except that `costPrice` being a single mutable scalar with no history means this report
can only ever say "what's it worth right now," never "what was it worth last month" or "what did
this movement actually cost us."** That limitation is inherent to the current data model, not a
build shortcut.

**This is the version I'd recommend asking the owner to unpark specifically**, since it's an
honest, bounded slice that doesn't require solving costing methods or a value ledger at all.

### Large: a real cost ledger + valuation history + aging

This is the actual "inventory valuation" the parking decision names: per-movement cost capture,
at least one real costing method wired end-to-end (moving-average is the standard choice — it's
also what the reference system picked), a value-over-time history, and inventory aging (how long
has this lot/batch been sitting, distinct from AP aging). This is the work `docs/QUEUE.md` parks,
and building it means either the full landed-cost/COGS conversation happens now, or this dashboard
ships with a known, stated gap (no true historical valuation) that will need revisiting the moment
someone asks "what was our inventory worth at month-end."

## Recommendation

Put the small/large choice to the owner directly, framed exactly as above, rather than picking one
silently. If the answer is "small, snapshot only, unpark just that" — this plan is buildable now,
no further scoping needed, and should move straight to a GitHub issue. If the answer is "the real
thing" — that's a costing-ledger project on the scale of `spec/03` in the reference system, and
deserves its own dedicated plan (and probably its own decision about whether it's worth building
before purchasing/receiving is fully trustworthy, which is the same condition `#661` — landed cost
— was already parked behind).

## Test plan (small/snapshot version only — the large version needs its own plan first)

- A valuation-by-warehouse report sums correctly against the same on-hand figures
  `PurchaseToInvoiceCompletedBucketLifecycleTest` already proves correct elsewhere — this must not
  become a second place those numbers could disagree with the ledger they're read from.
- A product with no cost price set renders as "TBD"/excluded rather than silently valuing at zero.
- The report explicitly labels itself as a current-snapshot, not a historical valuation, so nobody
  reads more into it than the data supports.
