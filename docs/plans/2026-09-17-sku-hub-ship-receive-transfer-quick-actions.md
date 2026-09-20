# Ship/Receive/Transfer quick actions from the Product Inventory Hub

## Status: plan (2026-09-17), not started

## The ask

Owner: *"scope this, its big, need to do"* — item #6 of the Product Hub gap review. Already flagged
as deferred in `docs/plans/2026-09-15-product-inventory-hub.md`'s own "Quick actions" section:
*"Adjust stock (exists, keep). Ship/Receive/Transfer are real Zoho/NetSuite quick actions too, but
each needs a document context (which invoice, which PO, which transfer order) this page doesn't
carry — still deferred... none of those screens accept a product-scoped entry point today outside
Shipment's own invoice-first flow."*

The owner now wants it built. This plan is the "each needs a document context" problem worked out
for real, per flow.

## The pattern to mirror: Adjust stock

`StockController::byProduct()`'s "Adjust stock" is a plain link, not a modal —
`stock_by_product.html.twig:76`:
`<a href="{{ path('admin_bundle_inventory_depth_adjust', {product: product.id}) }}">Adjust stock</a>`.
It lands on `AdjustmentController::form()` (line 149), which reads `?product=` via
`$request->query->getInt('product', 0)` (line 158) to pre-select the product, then the admin walks
reason → source → quantity → paperwork on that otherwise-normal, separate full page.

This is the reusable shape: **query-param-driven pre-fill into an existing document-first screen,
no new modal infrastructure.** Every flow below is scoped against how close it can get to this
same shape.

## Receive — the cheap win, and the pattern already half-exists

`ReceivingController::form()` (line 110-127) takes `?po=` to pre-scope a receiving session to one
PO. Crucially, this exact launcher pattern is **already built and shipping** for a different entry
point: `_purchase_order_tab_nav.html.twig:39` —
`<a href="{{ path('admin_bundle_procurement_receive', {po: order.id}) }}">Receive against this</a>`
— the PO detail page already launches Receiving pre-scoped to itself. A product-scoped launcher
needs the identical shape, just arriving from a different page.

Receiving also already supports a standalone path with no PO at all
(`ReceivingRequest::unordered()`, vendor+warehouse only — line 914-923), which is the fallback for
a SKU with nothing open to receive against.

**Build:**
1. A product-scoped query — "open PO lines carrying this SKU" — off
   `PurchaseOrderRepository::openForReceiving()` (line 161), filtered to lines naming this product.
2. On the SKU hub: if that query returns rows, a "Receive" button opens a small picker (which open
   PO, which line) that feeds straight into the existing `?po=` form — no new receiving logic, just
   a new way to arrive at the existing one.
3. If it returns nothing, "Receive" falls straight through to the existing unordered/standalone
   path, with the product pre-selected on the line entry (this is the one real addition to
   `ReceivingController` itself: `?product=` pre-fill on the standalone path, mirroring how
   Adjustment already reads `?product=`).

This is the smallest, safest piece of this plan — ship it first.

## Transfer — needs new plumbing, but the underlying service already supports it

`TransferOrderController` requires `POST /new` to create a draft, two-warehouse `TransferOrder`
before any product line can be added via `POST /{id}/lines` (line 136-160, 265).
`TransferOrderService::dispatch()`/`receive()` (line 90, 184) both operate on an already-lined
document — there is no one-shot "move product X from warehouse A to B" today.

**Build — a "quick transfer" convenience wrapper, not a new document type:**
1. From the SKU hub, a "Transfer" button opens a small form: from-warehouse, to-warehouse, quantity
   (pre-filled to this product, using the hub's own per-warehouse on-hand numbers so the picker can
   show what's actually available at the source).
2. On submit, the wrapper does exactly what a human would do today — `POST /new` (from/to) then
   `POST /{id}/lines` (this product, this quantity) — as one server-side action, landing the admin
   on the resulting `TransferOrder`'s own detail page rather than a blank multi-line form.
3. This is NOT a new persistence shape. `TransferOrder`/`TransferOrderService` are untouched; the
   wrapper is purely a controller-level convenience that calls the two existing steps in sequence.

## Ship — confirmed no natural product-first hook, deferred within this plan

`ShipmentController::new()` (line 114-204) only accepts `?invoice[]=`/`?invoice_number=`; there is
no `?product=` anywhere in it. This isn't a missing pre-fill parameter the way Receive's gap was —
picking *which invoice* is intrinsic to what's being shipped. A product doesn't identify a shipment
the way it identifies a PO line or a transfer route; a SKU can appear on several open, unshipped
invoices at once, and "ship this SKU" doesn't reduce to a single launch target the way "receive
this SKU against this PO" does.

**What a real version of this needs, as a separate follow-up, not folded into this plan:**
a product-scoped "which open invoice lines for this SKU are unshipped" view — genuinely new
surface area (nothing today answers that question at all, product-scoped or otherwise), not a
one-line controller change. Recommend shipping Receive + Transfer first, then deciding whether Ship
gets its own follow-up plan once there's a real invoice-line-level "unshipped by product" query to
build it on.

## Build order

1. Receive — cheapest, pattern already exists elsewhere, smallest surface area.
2. Transfer — new wrapper around existing service calls, no new persistence.
3. Ship — explicitly deferred pending a separate "unshipped invoice lines by product" query;
   revisit as its own plan once 1 and 2 are shipped and proven.

## Test plan

- Receive: a SKU with an open PO line shows the picker and lands on the right `?po=` form; a SKU
  with none falls through to the standalone path with the product pre-selected; the picker only
  offers PO lines that actually carry this product (not the whole open PO).
- Transfer: the quick-transfer wrapper produces the identical `TransferOrder`/`TransferOrderLine`
  state a manual two-step create-then-add-line would have produced — same service calls, same
  bucket recomputation, just one form instead of two page loads.
- Both: the SKU hub's own quick-action buttons only appear when the underlying flow can actually
  succeed (e.g. don't offer "Transfer" from a warehouse with zero on-hand of this product).
