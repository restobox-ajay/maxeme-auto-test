# Shipment: the sell side's missing counterpart to GoodsReceipt

## Status: implemented (2026-09-20)

`Shipment`/`ShipmentLine` entities and migration, `ShipmentService::ship()`, `ShipmentVoidService`,
`InvoiceShippingRemainderSubscriber` (Completed auto-ships the remainder), and the typed-form
`ShipmentController` screens (list, combined-invoice new form, detail, void) are all built and
tested. A few implementation notes worth recording against the plan below:

- **The lot/bin split the plan left open** ("`lotId`/`serial` on `ShipmentLine` only if
  partial-shipment allocation is needed for question 2 — decide when this is built") turned out to
  be needed regardless of partial shipments: a lot's own stock can be split across bins even for a
  single, full-quantity shipment line, and `ShipmentLine` never recorded which bin(s) a line drew
  from. `InventoryDetailRepository::availableRowsForLot()` (new) picks across a lot's bins in the
  same "no UI, no operator choice" spirit as `AutomaticSourcePicker`, scoped to the one lot the
  invoice line already named. `ShipmentVoidService`'s reversal lands unbinned rather than guessing
  at a bin the line never recorded — see its own docblock.
- **`ShipmentRequest` is a typed value object**, not the plain-array shape floated while this plan
  was still being designed — matching `ReceivingRequest`'s own precedent (a value object with no
  entity manager, built by whoever is asking and handed to the service whole) rather than
  introducing a second convention for the sell side.
- **`ShipmentService::remainingToShip()`** is the one shared computation of "how much of this
  invoice line is left" — both `ship()`'s own oversell guard and
  `InvoiceShippingRemainderSubscriber` read the same number, never two implementations of it.
- **Test plan**: covered by `ShipmentServiceTest` (ship-in-full, ship-partial-then-rest,
  ship-combining-two-invoices-for-one-company, refuse-a-different-company's-invoice,
  refuse-oversell, refuse-when-the-lot-doesn't-cover-the-quantity, a `simple` product ships
  paperwork-only, a serial-tracked product ships exactly one unit, idempotency), `ShipmentVoidServiceTest`
  (puts stock back, refuses a second void, requires a reason, refuses when the stock has moved
  since), `InvoiceShippingRemainderSubscriberTest` (both of the listener's named cases plus a
  fully-hand-shipped invoice generating nothing), and `ShipmentControllerTest` (the real,
  container-wired controller driven through new → create → show → void). Full `InventoryDepthBundle`/
  `ProcurementBundle`/`WarehouseOpsBundle` suites and core's `tests/Entity`, `tests/Service`,
  `tests/EventSubscriber`, `tests/Controller` all diffed against the pre-change baseline — zero new
  failures.

## Revision note (2026-09-20): question 2's pick moves from the invoice line to the shipment line

The "implemented" section above described `ShipmentLine.lotId`/`serial` as read straight off the
`InvoiceLine`, inherited "never chosen independently." That held for the common case and was wrong
as a universal rule, caught in review before it shipped to production:

- **A serial identifies one unit** (rule 3, `StockMovementService::assertSerialRowsStayAtOne()`), so
  `InvoiceLine::$serial` — one string field — was never capable of naming the serials for a line
  billing more than one unit. It could only ever answer for a qty-1 line.
- **A lot can run out mid-pick.** An invoice line billing 10 units may need a second, later-received
  batch to finish the pick once the first lot is down to 6 — `InvoiceLine::$lotId`, one field, cannot
  represent "6 from lot A, 4 from lot B."
- Owner: *"the shipment is the only place that record can even be made truthfully... how do we know
  how much of each lot/expiry/which serial we shipped [otherwise]?"* — the real identity can only be
  known and recorded at the moment stock is physically picked, which is ship time, not invoice time.

**The fix is additive, not a rebuild** — owner: *"what you have sounds like a correct shipment
service for simple inventory, and step 1 for complex inventory... you are building the step 2 for
complex inventory, where step 1 is shared in both cases."*

- **Step 1 (unchanged)**: `ShipmentRequest::add($invoiceLine, $quantity)` with no breakdown — the
  plain quantity ledger this doc already describes, working identically for `simple` and complex
  inventory. This is the whole feature for a `simple` product and the *only* shape
  `InvoiceShippingRemainderSubscriber` ever sends, since it has no human to ask which lot.
- **Step 2 (new)**: `ShipmentRequest::add()` gains an optional `$allocations` — a list of
  `{lotId or serial, quantity}` pairs. Given, it is authoritative for that shipment and may name a
  *different* lot/serial than whatever the invoice line captured, because it was picked for real,
  against live stock. Each allocation becomes its own `ShipmentLine` row — the schema already allowed
  several rows per invoice line (`ShipmentLine.invoiceLine` is a plain, non-unique FK), so this needed
  no migration.
- **Invoice-time capture (`MandatoryCaptureGuard`) is unchanged, deliberately.** Owner: *"why? because
  there is the option to mark a invoice complete shipment with 1 button. if u drop the gate, u could
  literally bypass the whole OUT direction mandatory thing."* `InvoiceShippingRemainderSubscriber` has
  no picker to fall back on, so the gate is what guarantees the one-click Completed path can never be
  a silent way around mandatory outbound identity capture — it is not meant to be the literal
  shipped-unit record any more, just the guarantee that *something* real gets captured regardless of
  which path completes the invoice.
- **Reconciliation, two levels, both checked, neither assumed:** the allocations for one line must
  sum to exactly what's shipping for that line *in this shipment* (new — `assertRequestIsShippable()`)
  and the total shipped for that invoice line, across every shipment and every lot/serial split it was
  ever given, still can never exceed what was billed (unchanged — `shippedSoFar()`/
  `remainingToShip()`, already split-agnostic since it sums by invoice line, not by lot).
- **Picking philosophy, owner's ruling:** lot quantity splits auto-suggest FEFO with manual override
  (`ShipmentAllocationPlanner::planLotAllocations()`, same rule `AutomaticSourcePicker` already uses
  for adjustments); serials are always a manual dropdown/checklist pick, never auto-assigned
  (`availableSerials()`) — "the shipment form is where the admin picks specific serials off a
  dropdown of what's actually available right now."
- New: `InventoryDetailRepository::availableSerialsFor()` (the dropdown's data),
  `ShipmentAllocationPlanner` (FEFO lot planning + the serial list — a suggestion only,
  `ship()` re-resolves and re-draws for real inside its own transaction), `ShipmentService::
  tracksOutbound()` made public (one shared gate, form and service asking the identical question).
- Test plan additions: `ShipmentServiceTest` (ship one invoice line across two lots in one shipment
  via an explicit allocation, refuse an allocation that doesn't sum to the line quantity, ship three
  separate serials for one invoice line via allocations), `ShipmentAllocationPlannerTest` (FEFO
  order, warehouse-scoped, every candidate lot gets a row including zero-suggested ones, available
  serials excludes anything already sold), `ShipmentControllerTest` (the form renders an editable
  FEFO breakdown for a lot-tracked line, records a shipment from an explicit two-lot allocation,
  records a shipment from ticked serials with no separately typed quantity).

## Superseded in part by `docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md` — implemented

That plan is done: `InvoiceInventoryBucketResolver::bucketForStatus('Completed')` now returns
`shipped` instead of `approved`, and `InventoryReservationReconciler` — unchanged — moves the whole
invoice's held quantity there automatically, the moment Completed is reached, **whether or not this
plan's `Shipment`/`ShipmentLine` ever exists.** No "transfer primitive" was built for this plan to
call; there is nothing here to call. That changes this plan's job in a way worth being precise
about:

- **The accrual side (`approved` → `shipped`) is already correct and already automatic, for every
  invoice, core-only.** This plan never touches it, never needs to, and must not duplicate it.
- **What this plan is actually for** is everything the core plan explicitly does not do: the
  itemized paperwork (`ShipmentLine`, which shipment carried how much of which invoice line, on
  what date) and, for a dimensional line, the physical `available` → `sold` movement — neither of
  which the core bucket transfer produces on its own. An invoice can reach Completed today with a
  perfectly correct `shipped` bucket and *zero* `ShipmentLine` rows and (for a dimensional product)
  a physical `InventoryDetail` row that still says `available` — that gap, not the bucket, is what
  this plan closes.
- **Partial shipments *before* Completed are real, independent business events** — `ship()` records
  and moves stock for less than the full invoice, ahead of and unrelated to whatever Completed will
  eventually do. **Completed itself, for whatever's still unshipped at that point, should trigger
  the exact same `ship()` call** with the remaining balance — see "Completed auto-ships the
  remainder" below — so paperwork and physical movement stay complete even for an admin who never
  touches the Shipment screen. That trigger lives entirely in this bundle, as a listener on the
  Invoice completing, never in core: core's own job (the bucket transfer) already happened by the
  time this listener runs, and does not run again.

## Combined shipments: the invoice moves from the header to the line

Owner: *"we allow partial invoice shipment but we also allow combined shipment - a shipment having
stuff from 2 invoices to the same customer."* That changes the entity shape below —
`Shipment.invoice` as a single, NOT NULL header field only works if one shipment always maps to
exactly one invoice, and it no longer does:

- **`Shipment` (header)**: `company` (NOT nullable) instead of `invoice`. Every line on one shipment
  must belong to invoices for this one company — a truck goes to one address.
- **`ShipmentLine.invoiceLine`** is what now enforces "always against an invoice, never standalone"
  — NOT nullable (this is the one field on `ShipmentLine` that differs from the shape further down,
  which lists it as `SET NULL`-on-delete-but-nullable; nullable-on-delete and mandatory-at-creation
  are not in tension — the column stays nullable so losing the invoice line later doesn't delete the
  record that goods left, but `ship()` refuses to create a line with no invoice line in the first
  place). Different lines on one `Shipment` may point at different invoices' lines, as long as every
  invoice shares the header's `company`.
- **`ShipmentService::ship()` gains a same-customer guard**, a whole-request check in the same shape
  as `assertSerialsAreDistinct()` (refuse the whole submission, not just the offending line) —
  refuse if any invoice line named in the request belongs to an invoice for a different company than
  the shipment's own (or than the first line's, when creating a new shipment).
- **"Completed auto-ships the remainder" stays single-invoice.** The listener only ever calls
  `ship()` for the one invoice that just completed — it never reaches out and pulls in other
  invoices to combine. Combining is only ever an explicit choice made on the shipment form.

## Revision note (2026-09-15)

The first version of this plan mirrored `GoodsReceipt` field-for-field and status-machinery-for-
machinery, and got overcomplicated doing it. Owner: *"the whole plan is wrong. we overcomplicated."*
followed by the actual spec, verbatim:

> Shipment tracking literally is: we sold X of sku 1, Y of sku 2.
>
> question 1: each shipment how much did each ship out.
>
> question 2: if and only if inventory is complex AND there is information OUT direction on
> lot/expiry/serial data THEN we have to pick which lot/expiry/serial got shipped in what shipment
> reconciling to question 1 above.
>
> that is it and that is all.

This revision throws out the GoodsReceipt-parity ceremony (status dimension, injection-point panel,
lot-picker-against-live-stock) and rebuilds the plan around those two questions directly. The
architectural rulings that were never about the ceremony — which bundle owns this, what base class
it extends, typed-form-only v1 — still hold and are kept below with their original reasoning.

## The gap, trimmed to what's actually still open

`docs/QUEUE.md`: *"`sold` = shipped. No dispatch event today; a dispatch layer writes it later
(#593)."* Today "shipped" is a status jump (`Invoice::startProcessing()` / `setStatus('Completed',
...)`), not a document, so there is:

1. **No partial fulfillment record.** An invoice is one shot — Processing or Completed, nothing
   records that half of it physically left yesterday and half leaves next week.
2. **No line-by-line record of what left the building**, on what date, in what quantity — the
   symmetrical fact `GoodsReceipt` already records for what arrived.

A third gap this plan originally listed — no real lot/serial linkage on the way out, only
`InvoiceLine::$batch`, a placeholder free-text string — **is already closed**, by the sibling
`docs/plans/2026-09-14-lot-serial-expiry-reservation-tracking.md` plan, implemented and merged
separately. `InvoiceLine` now carries a real `lotId`/`serial` pair, validated at issue time by
`MandatoryCaptureGuard`. `$batch` itself is untouched and still rendered alongside the real picker
in `sales_line_row.html.twig` (`showBatchUi` / `js-lot-select`, see that template's docblock) —
retiring the free-text box is a real, still-open loose end, but it is a UI cleanup independent of
Shipment, not something Shipment needs to solve or wait on.

So what's left for Shipment to answer is exactly the owner's two questions — nothing about lot
identity is Shipment's to invent, because the sibling plan already made it real on the document that
Shipment ships *against*.

## Already proven, not a design question

Before writing another line of this plan, three things were checked directly against the code
rather than assumed, because the first version of this plan got them wrong or left them open:

- **The movement primitive already exists and is already tested end-to-end.**
  `InventoryDetail::STATUS_SOLD` and `InventoryMovementGroup::TYPE_SHIP` both exist today; the only
  gap is a caller. `InventoryDepthBundle\Tests\Movement\SellingADimensionalProductTest` conducts the
  exact transaction Shipment needs — sell 5 of a dimensional product through a real invoice
  (`approved` hold begins at `startProcessing()`), then move 5 units from `available` to `sold` via
  `MovementRequest::of(TYPE_SHIP, ...)->move(...)` — and asserts the only correct outcome: available
  drops from 30 to 25 (accounted for exactly once, not twice, not zero times), `approved` **stays at
  5** after the ship movement, `received` stays at 30, nothing is written off. `ShipmentService::ship()`
  is a thin caller of exactly this, per line, nothing new to invent at the movement layer.
- **The reservation hold does not change at ship time — settled, not open.** The previous revision
  of this plan flagged "does shipping need to release the invoice's `approved` reservation row in the
  same transaction" as *"not resolved here — worth settling before ShipmentService::ship() is
  implemented."* The test above settles it: `approved` holds indefinitely once an invoice reaches
  Processing/Completed (documented invariant: this app has no mechanism that decrements Starting
  Inventory on shipment, so a later write must not quietly hand stock back), and shipping is that
  later write. **`ShipmentService::ship()` never touches `InvoiceInventoryReservation`.** It only
  moves `InventoryDetail` rows from `available` to `sold`.
- **Question 2's lot/serial is read off the invoice line, never picked independently.** The
  previous revision proposed a "lot picker reading live `InventoryDetail` rows at the shipment's
  warehouse" as though shipment made its own choice. Corrected: the choice was already made, and
  validated, at invoice time — `MandatoryCaptureGuard` refuses to let a tracked line leave Draft
  without a real `lotId`/`serial`. A shipment line for a tracked product inherits that identity from
  its `InvoiceLine`; it does not offer a second, independently-chosen picker. The only thing
  `ShipmentService::ship()` still checks against live stock is *quantity* — does that lot still have
  enough left via `LotAvailabilityResolver::availableForLot()` — never *which* lot.

## The two questions, as the actual spec

### Question 1 — always, every product: how much of each SKU shipped, on which shipment

This is a plain quantity ledger and needs no inventory-mode gate at all:

- `Shipment` (header): `company` (**NOT nullable** — every line's invoice must belong to this
  company; see "Combined shipments" above for why this replaced a single `invoice` field),
  `shippedAt`, `shippedBy`, a `shipmentNumber` for reference, `notes`.
- `ShipmentLine`: `shipment`, `invoiceLine` (column nullable with `SET NULL` on delete — losing the
  attribution must never delete the record that goods actually left, same reasoning as
  `invoice_line.sales_order_line_id` — but `ship()` itself refuses to create a line with none; see
  "Combined shipments" above), `product` (mapped, no FK — snapshot survives product deletion),
  `quantity`.

That's a complete, correct answer to question 1 for every product, tracked or not — recording it
has **zero `ProductInventory` effect**, because nothing in this app decrements Starting Inventory on
shipment for any product (see the invariant above). For a `simple`-inventory product, this is the
entire feature: the row is written, nothing else happens, same as `GoodsReceiptLine::$movementApplied
= false` for a `simple` product on the way in.

### Question 2 — only when both are true: which lot/serial, reconciling to question 1

Gate is the same composite check used everywhere else in the lot/serial plan —
`TrackingPolicy::tracksLotsOutbound()`/`tracksSerialsOutbound()` **and**
`InventoryModeResolver::isDimensional($product)`. When either is false, `ShipmentLine` carries no
lot/serial columns at all and there is nothing further to reconcile — question 1's row already says
everything there is to say.

When both are true:

- `ShipmentLine` gets `lotId`/`serial` columns, mirroring `InvoiceLine`'s own.
- **The value is inherited from the `InvoiceLine` being shipped, never chosen independently.** In
  the common case (one shipment per invoice line, full quantity), it is a straight copy.
- **Partial shipments are the only reason these columns exist on `ShipmentLine` at all.** If an
  invoice line for qty 10 ships as 6 then 4 in two separate shipments, each `ShipmentLine` records
  how much of *that already-recorded lot* went out in *this* shipment, and the two must sum back to
  the invoice line's quantity — an allocation of one prior decision, not a second decision. A
  product this session doesn't need partial/split shipments for can skip even this: `ShipmentLine`
  can join `InvoiceLine` and read its lot/serial straight off it, with no extra columns.
- At write time, `ShipmentService::ship()` calls `LotAvailabilityResolver::availableForLot()` to
  confirm the invoice line's lot still covers the quantity being shipped, then performs the
  `available` → `sold` move via `StockMovementService`, keyed to that lot/serial. It refuses if the
  lot doesn't cover it; it never substitutes a different lot.

## Completed auto-ships the remainder

Owner: *"u basically have a shipment doc that claimed the entire thing in 1 click. Nothing wrong
with that."* One function either way — there is no second bucket-writing path:

- **Explicit**: an admin records a `Shipment`/`ShipmentLine` for some or all of an invoice's
  remaining quantity, any time, including before Completed — partial, split, or combined across
  invoices for one company, as many times as needed.
- **Implicit, via Completed**: a Doctrine listener in this bundle (`InvoiceShippingRemainderSubscriber`
  or similar — core has and needs zero awareness of it) reacts to an `Invoice` reaching `Completed`
  and, for whatever quantity on it was never covered by an explicit `ShipmentLine`, calls the exact
  same `ShipmentService::ship()` with that remaining balance. A user who never touches the Shipment
  screen still gets a real, quantity-accurate `ShipmentLine` for the full amount, generated for
  them, and (for a dimensional line) the physical `available` → `sold` movement actually happens
  instead of silently never being triggered.

This listener does not — and must not — touch `ProductInventory.approvedQuantity`/`shippedQuantity`
or `InvoiceInventoryReservation` at all. That transfer already happened, unconditionally, by the
time this listener runs (`InventoryReservationReconciler`, driven by the resolver change in
`shipment-approved-to-shipped-bucket.md`, itself triggered by the same status write). This listener's
entire job is the paperwork and the physical movement `ship()` already does for an explicit
shipment — nothing about calling it from Completed instead of a form makes it a different operation.

## Rulings from the first pass that were never about the ceremony — kept

- **Mirrors `GoodsReceipt`'s class shape, not `AbstractSalesDocument`.** A shipment carries no
  subtotal/tax/total or commercial status seam — the money is already on the invoice. Owner: *"I
  think goods receipt is probably a better mirror."* This ruling was about class hierarchy, not
  about copying every field; it still holds against the trimmed shape above.
- **Lives in `InventoryDepthBundle`, NOT `ProcurementBundle`.** `GoodsReceipt` sits in
  ProcurementBundle because it's bound to `PurchaseOrder`; Shipment is bound to `Invoice` and its
  substance (`InventoryLot`, `StockMovementService`, `TrackingPolicy` consumption) is entirely
  InventoryDepthBundle's domain already — precedent: `InventoryDepthBundle\EventSubscriber\
  SalesReturnReceiptSubscriber` already imports `App\Entity\SalesReturn` directly for the same
  reason. Not `WarehouseOpsBundle` either — off the table per the session's standing rule against
  touching warehouse ops.
- **The typed-form path only. No scan flow in v1.** Unrelated to the ceremony being cut — this was
  always about not building a second console/resolver machinery before the plain path is proven.
- **No `InvoiceStatus` vocabulary change.** `Processing`/`Completed` stay exactly what they are.
  Shipment is additive record-keeping, answering "what actually left" without the status seam
  caring how it got there. Gating `Completed` on full shipment is a real, sensible v2 — not this
  phase.

## Cut from the first pass — was ceremony, not substance

- **`InvoiceShippingStatus` enum + deriver + subscriber, mirroring `InvoicePaymentStatus`.** A
  derived status dimension answers a question nobody asked for v1. Question 1 already answers "how
  much has shipped" by summing `ShipmentLine::quantity` per invoice line — a query, not a maintained
  column. If a list-screen filter or a completion gate genuinely needs `NotShipped`/
  `PartiallyShipped`/`Shipped` later, it can be added then; deriving it up front before there's a
  reader is exactly the kind of unread field this codebase's own standing rule warns against.
- **The injection-point panel (`InvoiceShipmentsPanelProvider`).** Same reasoning — a real,
  reusable pattern (`InjectionPointProviderInterface`), but wiring it in is UI polish for showing
  shipments on the invoice screen, not part of answering either question. Build it when the
  Shipment screens themselves are built, as ordinary controller/template work, not as a
  core-vs-bundle integration exercise up front.
- **"Lot picker reading live `InventoryDetail` rows at the shipment's warehouse."** Replaced above:
  the lot isn't picked at ship time at all, it's read off the invoice line.
- **The reservation-release step.** Replaced above: settled by `SellingADimensionalProductTest` as
  a non-event. `ShipmentService::ship()` never writes to `InvoiceInventoryReservation`.
- **`shipToAddress` snapshot, `trackingNumber`, `warehouse`-as-a-first-class-header-field.** None of
  these are needed to answer either question. `warehouse` in particular can be read off the
  `InvoiceLine`'s own fulfillment region/warehouse when a movement needs one, rather than asked for
  again on the header — revisit only if a real need for an independent ship-from warehouse surfaces
  (e.g. splitting one invoice's shipment across two sites). Address snapshotting and carrier
  tracking numbers are real features, just not this one; add them if and when there's a reader.

## Void

Symmetrical with the model above, not with `ReceiptVoidService`'s full shape: a void is a second
fact, not an unwrite. It reverses exactly what `ship()` wrote — the `ShipmentLine` rows are voided
(kept, not deleted, same discipline as everywhere else in this codebase), and for question-2 lines,
a second `MovementRequest` moves the same quantity back from `sold` to `available` at the same
lot/serial, refusing (via `StockMovementService::assertSourcesCanCover()`) if that stock has moved
again since. No reservation interaction here either, for the same reason `ship()` has none.

## Controller surface — typed form only, v1

Route prefix follows `AdjustmentController`'s convention (`/admin/bundles/inventory-depth`):

| Route | Purpose |
|---|---|
| `GET /admin/bundles/inventory-depth/shipments` | list |
| `GET /admin/bundles/inventory-depth/shipments/new?invoice_id=X` | typed form |
| `POST /admin/bundles/inventory-depth/shipments/new` | submit |
| `GET /admin/bundles/inventory-depth/shipments/{id}` | detail |
| `POST /admin/bundles/inventory-depth/shipments/{id}/void` | void |

## Explicitly out of scope for v1

- Scan-to-ship flow.
- `InvoiceShippingStatus` (derived status dimension) and the invoice-detail injection-point panel —
  add later, driven by an actual reader, not up front.
- Gating `InvoiceStatus::Completed` on full shipment (v2, separate owner ruling).
- `ShortDatedReceipt`-equivalent for outbound.
- Retiring the legacy `$batch` free-text cell on the Order/Invoice line — already a known loose end
  from the sibling lot/serial plan, independent of Shipment, not blocking it either direction.
- Carrier tracking numbers, ship-to address snapshotting, a first-class ship-from warehouse field.

## Build order

1. `Shipment` (header: `company`, not `invoice`) / `ShipmentLine` (`invoiceLine` nullable-on-delete,
   required-at-creation) entities + migration — question 1's shape; `lotId`/`serial` on
   `ShipmentLine` only if partial-shipment allocation is needed for question 2 — decide when this is
   built, not here.
2. `ShipmentService::ship()` + unit tests, mirroring the transaction
   `SellingADimensionalProductTest` already conducts by hand, now behind a real service: validate
   (including the same-customer guard across every line in the request), write `ShipmentLine` rows,
   and for question-2 lines call `StockMovementService` via `TYPE_SHIP` with the invoice line's own
   lot/serial. Never writes `InvoiceInventoryReservation` or either approved/shipped bucket column —
   that transfer already happened, elsewhere, unconditionally.
3. `ShipmentVoidService` + tests (the reversal described above).
4. The Completed-transition listener (`InvoiceShippingRemainderSubscriber`): calls `ship()` with
   whatever's left uncovered by explicit `ShipmentLine`s for that one invoice. Bundle-only, core has
   no knowledge of it.
5. Controller + templates — the actual screens, including picking more than one invoice for one
   customer onto a single shipment.

## Test plan

- A unit/integration test per `ShipmentService::ship()` behavior: ship-in-full, ship-partial-then-
  ship-the-rest, ship-combining-two-invoices-for-one-company, refuse-a-second-companys-invoice-on-
  the-same-shipment, refuse-oversell-beyond-invoice-line, refuse-when-the-invoice-line's-own-lot-
  doesn't-cover-the-quantity, a `simple`-inventory product ships with a `ShipmentLine` row and no
  movement at all.
- Reuse `SellingADimensionalProductTest`'s assertions as the acceptance bar for the movement side:
  after shipping, `approved`/`shipped` are unaffected by `ship()` itself, `available` drops by
  exactly the shipped quantity, `sold` detail rows exist for exactly that quantity.
- `CompletingAnInvoiceWithNothingExplicitlyShippedGeneratesTheFullShipmentTest` and
  `CompletingAnInvoiceAfterAPartialShipmentOnlyShipsTheRestTest` — the listener's two real cases.
- Void: puts stock back, refuses when the stock has moved since, un-voids nothing twice.
- Do **not** run the full suite to confirm any of this — name the classes (standing rule,
  `docs/QUEUE.md`).
