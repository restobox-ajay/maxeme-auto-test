# Product Inventory Hub: a per-SKU page, the way Zoho/NetSuite/Dynamics all have one

## Status: built (2026-09-15)

Every build-order step landed in `StockController::byProduct()` and its template. One deviation from
the plan as written: the "Purchases" feed source (`PurchaseOrderLine`/`GoodsReceiptLine`) and the
identity card's "Vendors" line both live in ProcurementBundle, and this bundle may not import that
module's classes directly (`ThisBundleNamesNoOtherBundleTest`) — so both cross a new pair of
tagged-provider seams instead, mirroring `LotAvailabilityProviderInterface`'s existing pattern:
`App\Contract\Inventory\ProductActivityProviderInterface`/`ProductVendorProviderInterface`, resolved
by `App\Service\Inventory\ProductActivityFeedResolver`/`ProductVendorResolver`, answered by
ProcurementBundle's own `ProductPurchaseActivityProvider`. Absent or Inactive, both simply answer
"nothing" — the hub's own Activity type filter still offers the "Purchase" button, it just never has
rows under it.

## The gap

Owner: *"I wanted to lookup a SKU and don't have a place to go."*

`InventoryDepthBundle` has eleven independent top-level screens — Stock by Location, Low Stock,
Adjust, Break a Case, Cycle Count, Bins, Lots, Serial Lookup, Tracking Worklist, Movement History,
Shipments — and every one of them shows a slice across *all* products, filtered by whatever that
screen's own job is. There is no single place that answers "what does this one SKU's stock actually
look like right now" — an admin has to already know which of the eleven screens might mention it,
and cross-reference by hand.

Zoho Inventory, NetSuite, and Dynamics 365 Business Central all converge on the same answer: an
item/SKU has one detail page — on-hand/available/committed by warehouse, lot/serial breakdown,
reorder status, recent activity — and adjustments/transfers/shipments are quick actions launched
*from* there, not separate destinations.

## Revision note: reconciled with the owner's own layout sketch

The first draft of this plan under-scoped the page as "mostly presentation, not new backend work."
The owner's own section list — Recent orders, Recent purchases, Credit memo, in addition to
location/lot/serial/movements — pulls in three panels with **no existing product-scoped query at
all**, plus a value/sales-velocity summary that doesn't exist anywhere in the app yet. This revision
says so plainly rather than discovering it mid-build. It is still one page, still coherent, just a
real feature rather than a pure render of numbers that already exist.

## Revision note 2: what Zoho/NetSuite/Business Central actually do

Owner: *"my layout I just spitballed on the fly... can you take care of studying online sources for
zoho inventory netsuite and dynamics please."* Researched directly (sources at the bottom) rather
than assumed. Three things came back that changed the order below:

- **Availability by location is always the first, most prominent thing on the page** — Zoho's "Stock
  Locations" section, NetSuite's per-location on-hand/available/committed, Business Central's "Items
  by Location" / "Item Availability by Location" pages. Nobody buries it under transactions.
- **Lot/serial sits immediately next to availability**, one level of detail deeper, not off in its
  own tab — Zoho's "Serial Numbers" section right there on the same Overview page; Business Central's
  Item Tracking Lines pull from the exact same Item Ledger Entry table its availability view reads.
- **Transactions are ONE mixed, chronological feed — not one panel per document type.** Zoho's
  Overview page literally shows *"impending shipments, invoices, receives and bills"* together,
  inline. NetSuite's "Related Records" tab is the same idea — one unified transaction list for the
  item, not a tab each for sales orders vs. purchase orders vs. bills. This replaces the five-separate-
  panels shape (Recent orders / Recent purchases / Credit memos / Movements / Shipments) the previous
  revision had — same underlying queries, better organized, and it's what the real precedent actually
  does rather than something invented here.
- **Sales-over-time is a named, real feature**, not something this plan is inventing to fill space —
  Zoho explicitly ships *"a dedicated sales order summary graph for the item"* on that same page.
- **Reorder point is read right beside availability**, not buried in a settings tab — NetSuite
  cross-references "quantity available" against reorder point directly.

## Layout: copy `company-detail-layout` verbatim, don't invent new CSS

Owner: *"Kinda like the customer screen."* Checked directly: `CompanyController::detail()` /
`templates/admin/company/detail.html.twig` already IS a two-column main+sidebar layout —
`.company-detail-layout { display: grid; grid-template-columns: minmax(0,2fr) minmax(340px,1fr); }`,
collapsing to one column under 900px, with `.company-detail-main`/`.company-detail-side` as the two
grid children. It has already been reused once, mechanically, for the Vendor detail screen (that
file's own comment calls itself *"a mechanical, data-slot-for-data-slot port... replacing
`company`/`Company*` with `vendor`/`Vendor*`"*). The product hub is the third port of the same
pattern — reuse `company-detail-layout`/`-main`/`-side` verbatim, not new layout classes.

### A real discipline worth inheriting, not just the CSS

Company's page only puts a "View more" link on its Sales Orders tab, not Estimates or Invoices,
because only `/admin/order` can filter by `company_id` — Estimates and Invoices only filter by
company *name* (LIKE), and a drill-through link would silently widen to a different company that
happens to share a name. The exact same trap applies here: **Orders, Invoices, and Purchase Orders
have no product filter today at all** (checked their list screens' filter params directly). So every
"View more" this plan wants on a document panel needs a small, real addition first — a product-id
filter on that target screen — not just a link. Listed per-panel below.

## Layout

### Sidebar — read first, the "at a glance" numbers

Sidebar comes before main column in this doc because that's the reading order the research settled:
availability and its two immediate follow-up questions (should I reorder, is it selling) belong
where the eye lands first, same as Zoho and NetSuite both put them ahead of any transaction list.

1. **Identity card** — mirrors Company's "Customer Details" card: SKU, name, category, status,
   inventory mode (Simple/Dimensional badge), tracking policy, image if any. *Cheap — `ProductCore`
   fields directly.*
2. **Availability & value** — on-hand across all warehouses, available, a short held/committed
   summary, and **value** (cost price × on-hand quantity — *cheap*, both numbers already exist, just
   multiply and sum). The one number everything else on the page explains or breaks down further —
   first thing after identity, ahead of any list, matching every reference system checked.
3. **Reorder status**, directly below availability — `ProductReorderRuleRepository::forPair()` +
   `ReorderPointRule::assess()`, already a clean reusable single-pair computation. *Cheap.* Placed
   here rather than lower on the page because it's the natural next question after "how much do I
   have," the same adjacency NetSuite's reorder-point cross-reference makes.
4. **Sales snapshot** — units sold in the last N days, the number Zoho names outright as its own
   graphed feature on this exact page. *New aggregation* — nothing like it exists anywhere in the app
   today (checked directly: no velocity/analytics service of any kind). A small trend number for v1;
   a chart is a natural v2 once the number itself is proven.
5. **Notes** — open question, see below. `ProductCore::$remarks` is a single free-text column, not a
   dated/authored timeline the way Company's sidebar Notes card is (`CompanyNote` entity, its own
   add/update/delete routes). Rendering `remarks` as an editable textarea is the cheap v1 answer;
   matching Company's real note-taking (multiple dated, authored notes) means porting `CompanyNote`'s
   shape to products, a real small feature of its own.

### Main column — the detail behind the summary, then activity

1. **Where it is, and how much.** `ProductInventory`'s full bucket set per warehouse (not just the
   final `available` number `stock_by_product` shows today), plus a bin-level breakdown
   (`stock_by_location`'s existing row shape, already product-filterable). This is the detail behind
   sidebar panel #2, one click down. *Cheap — existing data, existing queries.*
2. **Lot / serial / expiry**, immediately after — one level deeper than "which warehouse," not off in
   a separate tab, matching Zoho's Serial Numbers section sitting right on the same page as Stock
   Locations. Gated the same way `ShipmentService::tracksOutbound()` already gates question 2 —
   dimensional AND tracked. Lots via `InventoryLotRepository::withAvailableStock()` (FEFO order) +
   `availableRowsForLot()`; serials via `availableSerialsFor()`. A lot within its product's own
   tracking policy's shelf-life window is flagged inline (same `is-oversold`-style row highlight the
   Lots screen already uses for an expired lot), rather than a separate warning panel — one list, the
   risky rows marked, not two lists to reconcile by eye. *Small new work*:
   `InventoryLotRepository::expiringThrough()` exists but is catalog-wide, not product-scoped — needs
   a `$product` parameter (or a product-scoped sibling method).
3. **Recent Activity — one unified, chronological feed**, not five separate panels. This replaces
   what the previous revision drafted as independent Recent Orders / Recent Purchases / Credit Memos
   / Movements / Shipments boxes — the same underlying data, merged and sorted by date, each row
   tagged by type (Order, Purchase, Shipment, Credit Memo, Adjustment/Transfer) with its own link,
   mirroring Zoho's own inline *"impending shipments, invoices, receives and bills"* and NetSuite's
   single "Related Records" tab rather than a tab per document type. Sources, still each their own
   small new query:
   - Orders/Invoices: `SalesOrderLine`/`InvoiceLine` by product (`ProductCore` FK on both).
   - Purchases: `PurchaseOrderLine`/`GoodsReceiptLine` by product.
   - Credit memos: `CreditMemoLine.product` — already a real FK, no custom repository exists yet for
     `CreditMemoLine` so this is a first one.
   - Movements/transfers: `InventoryMovementRepository::filteredQueryBuilder(['product' => $product])`
     — already works, though by SKU/name text match today rather than product id; tightening that to
     id-based filtering is the one precision fix worth making while here.
   - Shipments: new small query on `ShipmentLine.product` (mapped column), same shape
     `InvoiceShipmentsPanelProvider` already used for invoice-scoping.

   Assembly is "fetch each source's recent rows, map each into one common `{date, type, label,
   quantity, url}` shape, merge, sort by date, take the top N" — no harder than building five separate
   panels, since every source still needs its own query; the difference is composition, not new data.
   "View more" per row-type links to that document's own list screen once it has a product-id filter
   (see the discipline below) — until then that type's rows still show on the feed, just without a
   working drill-through, same as Company's Estimates tab today.

   **Owner: "give guys a filter, I'd say that's useful."** Agreed — a merged feed with no way to
   narrow it becomes a wall of mixed rows the moment you actually want just the credit memos, or just
   what shipped. Since every row is already tagged by `type` for the merge/display, a type filter
   (All / Orders / Purchases / Shipments / Credit Memos / Movements) costs nothing extra to add — it
   reads the same tag the feed already carries, just also used to narrow rather than only to label.
   Implementation: a GET query param on the hub's own route (`?activity=shipment`, following this
   bundle's own "filter state lives in URL params" standing rule — see
   `AbstractInventoryDepthController::filtersFromRequest()`), so a filtered view is a copyable,
   bookmarkable URL like every other list screen in this bundle, not a client-side toggle that a
   fresh page load forgets. Filtering narrows the SAME merged query rather than switching to a
   per-type query — no second code path to keep in sync with the merge logic.
4. **Reconciliation** (demoted from `stock_by_product`'s current headline spot, kept as the last
   thing on the page) — the bucket-vs-detail-sum check, still useful for whoever debugs a drift.

### Quick actions

"Adjust stock" (exists, keep). Ship/Receive/Transfer are real Zoho/NetSuite quick actions too, but
each needs a document context (which invoice, which PO, which transfer order) this page doesn't
carry — **still deferred**, unchanged from the first draft, for the same reason: none of those
screens accept a product-scoped entry point today outside Shipment's own invoice-first flow.

## Decisions — settled

1. **Route: expand `StockController::byProduct()` in place.** Already linked from Lots, and
   Company/Vendor's own precedent is one canonical detail page, not two competing ones. No fork.
2. **Notes: plain `remarks` textarea for v1**, not a `CompanyNote`-style dated/authored timeline.
   Cheap, ships with the rest of the page, doesn't block the hub on standing up a new entity + CRUD
   routes for a feature nobody's asked for yet by name. A real notes timeline is a legitimate later
   feature once someone actually wants dated attribution on product notes specifically.
3. **Credit Memo list screen — checked directly, not assumed: it exists** (`admin_credit_memo_index`,
   `CreditMemoController::index()`) but filters only at the header level (company, document number,
   status, type) — it never joins `CreditMemoLine`, so **no product filter exists today**, same gap
   as Orders/Invoices/POs. Gets the identical treatment: add one, same step as the others below.
4. **Recency window: last-20, unpaged, flat list for v1** — consistent with what Movement History's
   own panel already settled on. The full history per source is exactly what each source's own list
   screen (with its new product filter) is for; the hub's feed is a recent-activity glance, not a
   replacement for those screens.
5. **Sequencing: filters first, as their own step 1.** Every "View more" link works from day one
   instead of the hub shipping with some rows silently dead-ended — the same "don't ship a known lie"
   reasoning behind filing #697 on the Company screen's own version of this gap.

## Build order

1. Product-id filters on `OrderController::orders()`, `PurchaseOrderController::index()`,
   `CreditMemoController::index()`, and `InventoryMovementRepository::filteredQueryBuilder()`
   (currently name/SKU text match) — the prerequisite every "View more" link depends on, per the
   Company-screen discipline above (and the fix tracked in
   [#697](https://github.com/axcelmediacorp/wholesale-b2b-core/issues/697) for Invoices specifically).
2. Restructure `StockController::byProduct()` into the two-column `company-detail-layout` shape;
   demote the existing reconciliation table to the bottom of the main column.
3. Sidebar: identity card, availability & value, reorder status card (all cheap, existing data).
4. Main column, cheap panels: location/bin breakdown, lot/serial/expiry.
5. The unified Recent Activity feed: each source query first (orders, purchases, credit memos,
   movements, shipments), then the common row shape + merge/sort, then the template.
6. Sales-in-last-N-days aggregation (sidebar) — last, since it's the one genuinely new analytics
   computation and shouldn't block the rest of the page from shipping.
7. Notes panel, per whichever answer #2 above settles on.
8. Tests per panel (a dimensional+lot-tracked product, a serial-tracked one, a `simple` product with
   no detail rows at all, a product with no reorder rule, a product with an empty activity feed —
   each panel's empty state, not just its populated one).

## Test plan

- One integration test per panel's real-data case and its empty-state case, mirroring the session's
  own `DoctrineIntegrationTestCase` style throughout `InventoryDepthBundle`.
- A `simple`-inventory product renders every panel except lot/serial, with the same bucket numbers
  `PurchaseToInvoiceCompletedBucketLifecycleTest` already proved correct elsewhere — this page must
  not become a second place those numbers could disagree with the ledger they're read from.
- Each new list-screen product filter (Orders, Purchase Orders, Movement History) gets its own test
  proving it actually narrows to the named product and doesn't silently widen — the specific failure
  mode the Company-screen precedent was built to avoid.
- The unified Recent Activity feed gets its own test asserting merge/sort order is correct across
  mixed source types (an order line and a shipment on the same day sort correctly against each
  other), not just that each source's rows individually appear.
- The feed's type filter gets a test proving `?activity=shipment` narrows to only that type without
  dropping or reordering the others, and that an unfiltered load still shows the full merge.
- Full `InventoryDepthBundle` suite plus whatever else touches `StockController`/`OrderController`/
  `PurchaseOrderController` diffed against the pre-change baseline, same discipline as every plan
  this session.

## References

Researched directly before writing the revised layout above, not assumed from memory:

- [Item Details Page — Zoho Inventory User Guide](https://www.zoho.com/us/inventory/help/items/item-details.html)
- [Create Items — Zoho Inventory User Guide](https://www.zoho.com/us/inventory/help/items/items-overview.html)
- [Bin Locations — Zoho Inventory User Guide](https://www.zoho.com/us/inventory/help/bin-locations/)
- [NetSuite Applications Suite — Using Item Records](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/chapter_N2164525.html)
- [NetSuite Applications Suite — Displaying Items and Information](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2585301.html)
- [NetSuite Applications Suite — Ordering Items (Reorder Point Items)](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2403352.html)
- [Introduction to Tabs on the Item Card — Dynamics 365 Business Central](https://usedynamics.com/business-central/product-dev/item-card-tabs/)
- [Get an availability overview — Business Central | Microsoft Learn](https://learn.microsoft.com/en-us/dynamics365/business-central/inventory-how-availability-overview)
- [Design details — Item tracking availability — Business Central | Microsoft Learn](https://learn.microsoft.com/en-us/dynamics365/business-central/design-details-item-tracking-availability)
- [Track items with serial, lot, and package numbers — Business Central | Microsoft Learn](https://learn.microsoft.com/en-us/dynamics365/business-central/inventory-how-work-item-tracking)
