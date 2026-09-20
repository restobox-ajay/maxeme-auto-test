# Parity audit: wholesale-b2b-core vs wholesale-inventory-core (+ Product Hub review)

## Status: audit complete, every actionable finding filed as a GitHub issue, owner-triaged (2026-09-17)

**Part 1 (Product Hub, 10 items):** #703 tags/labels · #704 export · #705 bulk row actions · #709
custom fields (tracks its own plan) · #710 Ship/Receive/Transfer quick actions (tracks its own
plan) · #711 inventory valuation, decision needed (tracks its own plan). #4/#5/#9/#10 needed no
issue (confirmed not gaps or already deferred correctly).

**Part 2 (wholesale-inventory-core comparison, owner-triaged item by item):**

| # | Finding | Owner's call | Issue |
|---|---|---|---|
| 1 | No optimistic concurrency | Queue — after base logic proven | [#712](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/712) |
| 2 | No audit-actor columns on rows | Needs human decision | [#713](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/713) |
| 3 | No idempotency keys | Queue — same as #1, all docs later | [#714](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/714) |
| 4 | No typed problem+json errors | Easy fix — ready to build | [#715](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/715) |
| 5 | Auto-increment IDs, no GUID upsert | Needs human decision | [#716](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/716) |
| 6 | No per-UoM purchase/sale restriction | Confirmed real, narrower than it read — appeal addressed | [#717](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/717) |
| 7 | No location-scoped admin access / sales-rep flag | Fix now — reference a real AdminUser | [#718](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/718) |
| 8 | No product variant/option-axis model | Queue — after inventory count; attribute+matrix, independent SKUs | [#719](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/719) |
| 9 | No cost/value ledger, no costing strategy | Queue — finance module after inventory | [#720](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/720) |
| 10 | No landed-cost allocation | Queue — same as #9 | [#721](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/721) |
| 11 | No cart-level hold | **Retracted — `CartHoldBundle` already exists.** See the correction inline below. | none |
| 12 | Hand-rolled status enums, not a workflow engine | Needs human decision (ELI5 given) | [#723](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/723) |
| 13 | No customer credit limits | Add now — ready to build | [#724](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/724) |
| 14 | No quantity-break pricing | Queue | [#722](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/722) |
| 15 | Reorder engine doesn't auto-draft | Confirmed intentional (already #625) | none — already tracked |
| 16 | Reorder doesn't net inbound transfers | Fix — real bug | [#706](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/706) |
| 17 | Transfer in-transit ATP | Not a gap | none |
| 18 | No lot recall/trace-forward | Confirmed scope — add as specced | [#725](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/725) |
| 19 | No scan-to-add on documents | Queue | [#707](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/707) |
| 20 | No Document Designer | Queue — v3 | [#726](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/726) |
| 21 | Cycle Count not blind, no approval gate | Queue — v2 | [#727](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/727) |
| 22 | Single-invoice-only payments | (not yet responded to) | [#708](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/708) already filed |

25 issues total across both parts. "Needs human decision" issues (#2/#713, #5/#716, #12/#723) are
explanatory rather than build-ready — they lay out the tradeoff and wait for a call, they don't
assume one.

## What this is

Two separate reviews, merged into one list because the owner asked "do we only have 10 gaps?" after
the first one landed and the real answer was no.

1. **The Product Hub review** (10 items, all scoped to `StockController::byProduct()` and the
   Product admin screens specifically) — answered by the owner item-by-item; 6 of the 10 need
   issues/plans, 4 were confirmed as already-handled or deliberate.
2. **The wholesale-inventory-core comparison** — a real, side-by-side, code-vs-code audit against
   `/home/user/wholesale-inventory-core`, a separate spec-driven build (95/109 features actually
   implemented, not just specced) covering 17 domain areas: platform conventions, catalog/UoM/
   variants, costing ledger, reservations, order lifecycles, credit control, pricing, quantity
   discounts, reorder engine, stock transfers, lot/serial + recall, barcode/labeling (Sortly is
   referenced specifically in its `13-barcode-labeling.md` spec and `00-open-decisions.md`),
   document generation, stock counts, and store credit/payments. Read across 5 parallel passes,
   each one verifying the *reference repo's own code*, not just its spec prose, before comparing to
   wholesale-b2b-core's actual code. **21 further gaps** came out of this pass, on top of the
   original 10.

Every item below is evidence-based — file:line citations from both repos where relevant, not
assumed from either repo's documentation alone. Items the reviewing agents checked and found were
**not** gaps (equivalent, or wholesale-b2b-core is actually more advanced) are also recorded, so
nobody re-flags them later.

Phase-2/v2-gated items on the reference side (storefront integration §12, manufacturing §14,
LotGenealogy, catch-weight, webhooks, Postgres swap, bulk/rate-limit API tooling, event replay) are
excluded throughout — those are deliberately unbuilt in the reference system too, so they are not
counted as gaps here.

---

## Part 1 — Product Hub review (10 items, owner-triaged)

| # | Gap as stated | Owner's call | Disposition |
|---|---|---|---|
| 1 | No tags/labels on products at all | Write an issue, scope it | **File issue** — see below |
| 2 | No product/inventory export (import only) | Write an issue, scope it | **File issue** — see below |
| 3 | No self-service custom-fields admin UI | Big one, plan. Wants it for **all objects**, Shopify-style: add a field through the UI, it shows on the edit page, usable in output/themes | **Big plan** — see below |
| 4 | No dated/authored notes timeline on products (deferred v1) | "Product has one now, it's tucked away in bundles/inventory-depth/stock/product/x" | **Not a gap** — confirmed correct, `remarks` textarea already ships (`docs/plans/2026-09-15-product-inventory-hub.md` Decision #2). No action. |
| 5 | No sales/stock trend chart (deferred v2) | "v2 correct" | **Not a gap right now** — confirmed already deliberately deferred. No action. |
| 6 | No Ship/Receive/Transfer quick actions from the SKU hub | "Scope this, it's big, need to do" | **Big plan** — see below |
| 7 | No inventory valuation/aging/analytics dashboard | "Finance, scope it" | **Scoped, with a real blocker flagged** — see below |
| 8 | No bulk row actions on the Product grid | "Yes we have in grid edit ... put an issue, maybe we missed" | **File issue** — see below (confirmed: what exists is price-column bulk-apply, not row-select bulk actions, and this is true of every grid in the app, not just Product) |
| 9 | Camera barcode scanning is Chrome-only, not a dedicated scan app | Skip | No action. |
| 10 | Bin/lot/serial/barcode/warehouse-ops bundles are opt-in, off by default | Deliberate | No action. |

### #1 — Product tags/labels

**Confirmed genuinely absent.** `src/Entity/ProductCore.php` (full 456-line entity) has no `tags`
field and no join table to any tag-like entity. The only `ManyToMany` anywhere in `src/Entity` is
`ProductCore`'s private-catalog visibility list (Company access), which is not tagging. No other
entity in the app (Company, Order, Invoice) has a tag/label system either — this would be the
first. `ProductCategory` is a single, self-referencing hierarchical tree (one category per
product) — the architectural opposite of tags (multiple, non-hierarchical, admin-defined) and
worth stating explicitly in the issue so scope doesn't drift into "just use categories."

Closest prior art: the `CustomFieldDefinition`/`CustomFieldValueProduct*` system, but that's
single-valued per-field metadata, not a multi-select tag mechanism.

### #2 — Product/inventory export

**Confirmed greenfield — import-only today, no export pattern anywhere to reuse.** Import is real
and substantial (`ProductImportController`, `ProductImportService`, plus vendor-specific bundles
Number1ProductImportBundle/RimImportBundle/CustomerImportBundle that transform vendor CSVs into
the same canonical format). Grepped `src/` and `modules/` for `StreamedResponse`,
`Content-Disposition`, `text/csv`, `fputcsv`: the only hits are (a) blank CSV **import templates**
for download, not exports of existing data, and (b) single-document PDF downloads (Order, Quote,
PO, Invoice, shipping labels) — one record at a time, not grid/bulk export. `InventoryDepthBundle`
has zero export hits anywhere. This is genuinely new work, closest reusable pattern is the PDF
`Content-Disposition` mechanism, not a CSV-grid one.

### #3 — Self-service custom-fields admin UI (all objects, Shopify-style)

**Full plan:** [`2026-09-17-custom-fields-self-service-admin-ui.md`](2026-09-17-custom-fields-self-service-admin-ui.md).

Short version: a real definitions entity + a real admin CRUD screen already exist, but three
hardcoded registries in `src/` (`CustomFieldDefinition::OBJECT_TYPE_*`,
`CustomFieldValueRepository::MAP`, `CustomFieldController::OBJECT_TYPES`) block adding any new
object type — Vendor is explicitly, documentedly blocked by this today
(`modules/ProcurementBundle/README.md:187-189`). Adding a field to an object type that DOES have
these registered still requires a bundle to write an `EventSubscriber`, not a form submission.
Nothing renders custom fields outside `/admin` at all, so "usable in output and themes" is a
second, separate half of the ask. The dedicated plan works through the storage-strategy decision
(EAV fallback vs. dynamic per-type tables) this needs before a build order makes sense.

### #6 — Ship/Receive/Transfer quick actions from the SKU hub

**Full plan:** [`2026-09-17-sku-hub-ship-receive-transfer-quick-actions.md`](2026-09-17-sku-hub-ship-receive-transfer-quick-actions.md).

Short version: Receive is the cheap win — the PO detail page already launches Receiving
pre-scoped to itself (`_purchase_order_tab_nav.html.twig:39`), so a product-scoped launcher just
needs to find the right PO line and feed the same form. Transfer needs a new "quick transfer"
wrapper around the existing two-step `TransferOrderController` (no new persistence). Ship has no
natural product-first hook at all — `ShipmentController::new()` is invoice-first by nature, since a
SKU can sit on several open unshipped invoices at once — and is deferred within the plan pending a
genuinely new "unshipped invoice lines by product" query.

### #7 — Inventory valuation/aging/analytics dashboard

**Full plan:** [`2026-09-17-inventory-valuation-dashboard.md`](2026-09-17-inventory-valuation-dashboard.md)
— **status: blocked on an owner decision**, not ready to build.

Short version: `docs/QUEUE.md:406-407` names inventory valuation explicitly as still-parked
accounting scope. The only "valuation" that exists anywhere today is one line
(`StockController::availabilityAndValue()`, `costPrice × onHand` for a single product), the
movement ledger is quantity-only with no cost capture at all, and even the reference comparison
system hasn't got a working cost ledger either. The dedicated plan lays out a small
(unpark-a-snapshot-report-only) option and a large (real cost ledger) option and asks the owner to
pick, rather than silently building around the parking decision.

### #8 — Bulk row actions on the Product grid

**Confirmed, and confirmed app-wide, not Product-specific.** What exists on the Product side today
is real, but it's a different thing: the main product list/grid (`admin_product_detail_index`) has
no row selection at all. The **Price grid** (`admin_product_price_index`) has genuine inline cell
editing (`updatePrice()`/`updateCorePrice()`/`updateSuggestedPrice()`) plus a column-scoped
"bulk-apply" (`bulkApplyPriceRule()`/`bulkApplySuggestedPrice()`) triggered by header icons —
apply one price rule to every product on the page, or every product matching the active filters
(re-queried server-side, not a client-trusted ID list). That's what "yes we have in grid edit"
correctly refers to.

But it is not row-checkbox multi-select + an arbitrary bulk action (status change, category
change, delete) applied to just the rows you picked. Grepped the whole app for
`checkbox`/`select-all`/`row-checkbox`/`bulk-action`: no admin grid anywhere (Orders, Companies,
Invoices, Carts, Product) has that pattern. So this genuinely was missed — but as an app-wide gap,
not something Product alone lacks. Worth deciding in the issue whether to scope it Product-first or
build the pattern once, generically, for reuse.

---

## Part 2 — wholesale-inventory-core comparison (21 further gaps)

Grouped by the reference's own spec numbering. Each includes the confirming evidence; items marked
**Not a gap** are recorded so they don't get re-flagged.

### Platform & entity conventions (specs 01–02)

1. **No general optimistic-concurrency convention.** Reference: every aggregate carries
   `#[ORM\Version]` + a clean 409 by design. wholesale-b2b-core has it on exactly 3 entities
   (SalesOrder, Estimate, Invoice — confirmed via `OrderController.php:462-483`'s real
   `EntityManager::lock(OPTIMISTIC)` flow). Product, Company, Warehouse, UnitOfMeasure, Vendor and
   everything else has no version column — two admins editing the same product/warehouse/company
   simultaneously silently last-write-wins with no conflict detection.
2. **No audit-actor columns denormalized on rows.** Reference carries createdBy/modifiedBy on every
   aggregate. wholesale-b2b-core has createdAt/updatedAt timestamps but attribution lives entirely
   in the separate `AuditLog` table (actorId/actorName/dataBefore/dataAfter) — a real but partial
   gap, since the data exists, just not on the row itself.
3. **No idempotency keys on writes.** Reference has a full `Idempotency-Key` replay/conflict
   listener. Zero matches for "Idempotency" anywhere in wholesale-b2b-core — safe retries aren't
   supported.
4. **No typed `application/problem+json` error envelope.** wholesale-b2b-core's API returns
   `{"error": "<message>"}` with correct HTTP status codes, but no machine-parseable error
   type/code.
5. **Auto-increment PKs, no GUID/PUT-upsert convention.** Every entity checked uses
   `#[ORM\GeneratedValue]` int PKs. No client-suppliable-GUID create-or-update pattern anywhere.
6. **No per-UoM purchase/sale restriction.** Reference's UnitOfMeasure has independent
   `usableForPurchase`/`usableForSale` flags (e.g. "pallet" can be purchase-only).
   wholesale-b2b-core's available-units-per-product list has no such distinction — every available
   unit works both directions.
7. **No location-scoped admin access or sales-rep-eligibility flag.** Reference's TeamMember has
   `canBeSalesRep` + `accessAllLocations`/`accessLocationIds`. wholesale-b2b-core's AdminUser has
   only email/roles/status; `Company.salesRep` is free text, not a real relation to a flagged
   staff member.
8. **No product variant / option-axis model at all.** The sharpest one in this group. Reference has
   a `ProductGroup` (axes + variant matrix). wholesale-b2b-core's `ProductCore` has no
   `productGroupId` or axis-value concept anywhere — confirmed via grep for
   `axes|variant|ProductGroup` across `src/`: zero hits. There is no way today to model "T-Shirt in
   S/M/L × Red/Blue" as a variant family under one parent — each combination is an unrelated
   standalone SKU with no shared grouping, matrix generation, or cross-variant pricing hook.

**Not gaps, confirmed equivalent or better:** the pluggable-extension pattern (a real
per-capability `Interface` + `supports()` + tagged services + BundleDescriptor pattern exists, just
not unified under one `StrategyRegistry` class); UoM conversion (a genuinely more robust
global-vocabulary + per-product-available-units + family-based-conversion model than the
reference's simpler ratio table); barcode/GTIN model (`ProductBarcode` supports many typed
barcodes per product — UPC/EAN/GTIN/vendor-part/customer-part/internal — richer than the
reference's single barcode+type field); locations/bins (Warehouse + WarehouseLocation exist);
address-book depth (CompanyAddress/VendorAddress with purpose flags and defaults exist); category
hierarchy (self-referencing parent chain exists).

### Costing, ledger, reservations (specs 03–04)

9. **No value/cost dimension in the movement ledger, and no costing strategy of any kind.**
   `InventoryMovement` is quantity-only. `ProductCore::$costPrice` is a single mutable scalar, no
   moving-average/FIFO/LIFO/manual/specific-ID costing exists anywhere. This is the same fact as
   item #7 in Part 1 — confirmed independently by a second research pass, so it's stated once here
   and cross-referenced rather than duplicated as a separate finding. **Caveat carried over:** even
   the reference system has only one costing strategy partially wired, not five working ones —
   scope this as "no cost ledger at all" on both counts, not "missing 4 of 5 methods."
10. **No landed-cost allocation** (by value/weight/volume) anywhere in wholesale-b2b-core. Same
    parking as #9/valuation.

**CORRECTED 2026-09-17 — #11 was a false positive, not a gap.** The original pass read only
`CartService::capOrRemove()` (a live availability check, correctly described as "not a promise" in
its own docblock) and concluded cart-level holds don't exist. They do: `modules/CartHoldBundle` is
a complete, separate feature — `CartHold` entity (one row per `CartItem`, `expiresAt`, atomic
upsert-on-cart-mutation), `CartHoldService::syncForCurrentCart()` (whole-cart timer reset on every
mutation and cart/checkout view, releases holds when disabled or no region resolved), a sweep
subscriber, and an admin config screen — toggleable as an optional bundle exactly like every other
opt-in feature in this app, which is almost certainly why the first pass missed it (it read the
always-on cart code path, not the optional bundle layered over it). Confirmed directly by the owner
("pretty sure we have carthold, please check more closely") and then by reading
`CartHoldBundle/src/Entity/CartHold.php` and `CartHoldService.php` in full. **No issue filed for
this — retracted.**

**Not gaps, confirmed equivalent or better:** wholesale-b2b-core's reservation/hold system
(`OrderInventoryReservation`/`InvoiceInventoryReservation`/`InventoryReservationReconciler`/
backorder-queue-with-auto-release-on-restock) is more advanced than the reference on this specific
piece — the reference explicitly defers backorder auto-fill to Phase 2, and wholesale-b2b-core
already has it built. `AdminOrderStockValidator`'s override-with-recorded-reason approach (surface
the shortfall, let the operator decide, require a reason) is more sophisticated than the
reference's simple block/backorder toggle, not less. Stock-transfer in-transit ATP is correctly
excluded from availability at both ends and not double-counted — matches spec, not a gap (see #17
below, same finding from a different pass). Cart-level holds (see correction above) — not a gap.

### Order lifecycle, credit, pricing (specs 05–08)

12. **Order lifecycles are hand-rolled enums app-wide, not a formal state-machine component.**
    `symfony/workflow` isn't wired into any config anywhere in wholesale-b2b-core (only appears as
    a transitive dependency version constraint). Every document status (PurchaseOrderStatus,
    VendorBillStatus, SalesOrderStatus) is a backed enum with hand-written transition
    methods/predicate helpers and ad-hoc derivation logic, confirmed across both the buy and sell
    sides — not a procurement-specific pattern.
13. **No customer credit-limit concept anywhere.** `Company.php` (full 307-line entity) has no
    `creditLimit`, no `creditHold`, no AR-exposure concept — only a payment-term reference. Grepped
    "credit" across `src/Controller` and `src/Service`: only hits are `CreditMemo*` (a
    refund/credit-note feature, unrelated) and unrelated inventory "credit" hits. Zero credit-hold
    logic anywhere in order placement. This is a genuine, complete absence.
14. **No quantity-break/volume pricing.** `ProductPricing` is one row per (product, price list)
    with a single price/discount rule — no quantity field anywhere. Grepped for
    `minQty|breakQty|quantityBreak|volumeDiscount|tierPrice|priceTier`: zero hits. Buying more
    never changes the unit price automatically today.

**Not a gap:** base pricing itself (`PriceList` × `ProductPricing`, resolved per company) is a real
functional equivalent of the reference's "one price per (product × scheme)" model — the gap is
specifically the missing quantity dimension (#14), not the pricing model as a whole.

### Reorder, transfers, lot/serial, barcode (specs 09, 10, 11, 13)

15. **Reorder engine computes a suggestion but never acts on it — known and already parked**
    (`docs/QUEUE.md` #625, deliberately deferred as a separate future bundle). Recorded here for
    completeness, not as a new finding.
16. **New finding: reorder doesn't net inbound stock-transfer remainders, only open-PO incoming.**
    `ProductInventory::getIncomingQuantity` is written only by `IncomingStockReconciler`
    (PO-driven). A warehouse awaiting an inbound transfer can still show "Short" and get a PO
    raised on top of stock already rolling toward it. This is a real, distinct gap the existing
    QUEUE.md note doesn't cover, separate from the "no auto-draft PO" item above.
17. Stock-transfer in-transit ATP is correctly implemented (see #11's cross-reference above) —
    **not a gap.**
18. **No recall/trace-forward capability at all for lots — the sharpest, most food-safety-relevant
    finding in this whole audit.** `InventoryLot` has no `status` field (no Available/OnHold/
    Recalled) and no structured vendor/source-receipt link (`source` is a free-text field; the
    entity's own comment says "Free text until #555 gives receiving a real vendor to point at").
    `MovementController`'s own docblock states outright that movement history "is also the only
    place a recall can be answered from... and never 'to whom' — only the group's `reference`
    does." There is expiry/shelf-life tracking (FEFO, expiring-soon view) but no hold/quarantine
    status, no structured trace-forward (which customers/orders received units of a given lot), no
    recall-by-lot-or-serial action. Given lots and expiry are already tracked, "given a bad lot,
    who received it" is a question the system genuinely cannot answer today.
19. **No scan-to-add embedded directly on Sales Order / Purchase Order / Transfer document forms.**
    The scan console itself (`ScanController`) is real and broader than previously assumed —
    receive/putaway/pick/move/count all go through the same `StockMovementService` write path as
    manual entry, and it does cover a scan-driven cycle-count workflow. But there's no
    "start scanning" mode embedded on a document's own line-entry screen that adds/increments a
    line as you scan. (Note: even the reference system only wired this for SO-quote-stage and
    count, deferring PO/Transfer scan-to-add itself — so this specific sub-gap is smaller in
    practice than the full spec describes, but still real for SO/PO/Transfer document entry.)

**Not gaps, confirmed:** label printing (`LabelController`/`LabelCatalog`/`LabelSheetRenderer`,
product/bin/lot/serial labels, PDF preview parity) is solid and matches the spec — thermal/ZPL
output specifically wasn't verified either way, flagged as unconfirmed rather than a gap.
Manufacturing genealogy correctly absent from both systems (Phase-2-gated on both sides).

### Document generation, stock counts, payments (specs 15–17)

20. **No Document Designer — PDF layouts are hardcoded Twig, not admin-configurable.** The
    reference has a structured (non-WYSIWYG) designer: section toggles, field selection, label
    overrides, per-doc-type custom-field printing, logo placement — a real `DocumentTemplate`
    entity, editable via a service. wholesale-b2b-core's Order/Quote/Invoice/packing-slip PDFs are
    rendered straight from hardcoded `.twig` files through Dompdf; a layout or field change needs a
    developer. There is a company logo-URL branding setting, but it's confirmed not wired into any
    transaction PDF today (`packing_slip.html.twig:37` literally comments "no logo tile").
21. **Cycle Count is not blind and has no variance-approval gate.** The count form shows the
    system's expected quantity right next to the entry field (`cycle_count.html.twig:38` labels a
    column "System says" beside "Found") — the opposite of a blind count. There's also no
    review/approval stage: submitting posts the adjustment movement in the same request. The
    controller's own docblock confirms this is a direct-adjustment flow by design, not a staged
    Open→InProgress→InReview→Completed two-person gate the way the reference implements it.
22. **Payment application is single-invoice only — no batch/spread-across-invoices payment.**
    `InvoicePayment` has a non-nullable one-to-one link to exactly one invoice, written from one
    call site. `VendorBillPaymentMover` (buy side) only moves one payment 1:1 between two bills,
    not a spread across many. There's no way to record one payment and allocate it across several
    open invoices/bills in one action today.

**Not gaps, confirmed equivalent or better:** the pick-list document/screen is real and distinct
from the packing slip (matches spec); the Email Designer has a genuine, differently-modeled
equivalent (`EmailTemplate` CRUD with override-a-shipped-default semantics); `CreditMemo` is a real
store-credit equivalent (a document with a balance, applicable to any invoice for the company, not
limited to the invoice it originated from — functionally matches the reference's ledger-based
model, just document-shaped instead); a configurable payment-method list already exists
(`PaymentMethod` + `CompanyPaymentMethod`).

---

## Rollup — final state, 2026-09-17

26 findings total (27 minus the retracted CartHold false-positive). Every actionable one has a
filed GitHub issue and an owner disposition — see the two status tables at the top of this
document for the full list. Grouped by what happens next:

**Ready to build now:** #4/#715 (typed error envelope), #7/#718 (real AdminUser sales-rep
relation), #13/#724 (customer credit limits), #16/#706 (reorder/transfer netting fix), #18/#725
(lot recall/trace-forward) — the sharpest finding in the whole audit, a food-distribution app with
real lot/expiry tracking and no way to answer "who received the bad lot."

**Needs a human decision before it's build-ready:** #2/#713 (audit-actor columns), #5/#716
(GUID/PUT-upsert), #12/#723 (formal workflow engine vs. hand-rolled enums) — each issue lays out
the tradeoff in plain terms rather than assuming a direction.

**Blocked on an explicit unparking decision:** #7-part1/#711, #9/#720, #10/#721 (inventory
valuation, cost ledger, landed cost) — all three collide with `docs/QUEUE.md`'s standing "accounting
is parked" ruling; #711 asks for a small-vs-large call.

**Queued, sequenced behind other work, no build order needed yet:** #1/#712 (optimistic
concurrency, after base logic proven), #3/#714 (idempotency keys, same timing), #8/#719 (product
variants, after inventory count — attribute+matrix model, independent SKUs per the owner's ruling),
#14/#722 (quantity-break pricing), #19/#707 (scan-to-add on documents), #20/#726 (Document
Designer, v3), #21/#727 (Cycle Count blind+approval, v2).

**Confirmed not gaps, no action:** #6/#717 was narrower than it first read (availability-per-product
already works; only the purchase/sale direction-restriction was missing, and that's what got
filed) · #11 (cart-level hold — `CartHoldBundle` already exists, retracted) · #15 (reorder
auto-draft, already correctly tracked as `docs/QUEUE.md` #625) · #17 (transfer in-transit ATP).
