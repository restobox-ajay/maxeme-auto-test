# One-gate audit — every critical action, its designated method, and whether that method is real

**Date:** 2026-09-20
**Scope:** Full repository — every key entity's CRUD and state-changing actions, mapped to the method that is supposed to be the single gate for it, then each of those methods opened and judged on whether it actually behaves like a gate.
**Method:** Five Explore agents mapped entity → action → claimed designated method across Identity/Org/Product, sell-side documents, buy-side documents, warehouse ops/inventory/config. Five more agents then opened each claimed method and verified genuineness against three criteria (below). Not a diff review — a standing audit, meant to be revisited as gaps get closed.
**Trigger:** After `31fd242d` unified stock-count writes into `CoreInventoryTotalCountService` (closing what was reportedly the 4th independent "set stock" path, after a prior status-field consolidation), the question was asked: how much more of this hand-wiring exists? This document is the answer, as of this date.

## What "genuine gate" means here

A method counts as a real gate only if:
- **(a)** it is not itself an HTTP controller action requiring `Request`/`Response` — or if it is, it fully delegates all validation *and* the write to one plain method with zero business logic left behind in the controller;
- **(b)** it actually enforces the invariant itself (validates, checks state, throws) rather than trusting the caller already checked;
- **(c)** it's public and reasonably reusable — callable from a console command, an event subscriber, or a future API, not just from the one controller that currently calls it.

A method can be the *only* current caller of a rule and still fail this test, if nothing stops a second caller from writing the same field directly. That's exactly how the stock-count and (see below) status bugs happened — not "duplicated," but "one path validated, N other paths didn't know the validation existed."

## Recurring anti-pattern

The same shape shows up across Identity, Org, Product, and most config entities: a **private, `Request`-coupled `applyXRequest()` / `handleXForm()` method does the actual field-write with zero validation**, while the real rule (uniqueness, required fields, status legality) is a *separate* method called only by the one controller action that happens to invoke both in sequence. Nothing else in the codebase is stopped from calling the writer alone. Structurally identical to the stock-count bug — just not yet triggered because no second caller has shown up yet.

## Confirmed live bug (separate fix already in progress per owner)

**ProductCore stock count.** `ProductController::syncProductInventory()` (`src/Controller/Admin/ProductController.php:2489-2541`) still writes `ProductInventory::setQuantity()` directly on every product-form save and never calls `CoreInventoryTotalCountService::setCount()` — despite that service's own docblock naming this exact call site ("the product form's per-region quantity fields") as one of the three it was built to replace. Consequence: no matching `inventory_detail` row gets reconciled, so stock set this way reads as unavailable the moment a transfer or adjustment checks for it — the same bug class the service exists to prevent.

## Registry

Legend: **Yes** = genuine gate. **Partial** = validates, but Request-locked or split across two methods. **No** = real gap — either no invariant is enforced anywhere, or it's enforced only inside the one calling controller action with nothing stopping a second caller.

### Identity / Org / Product

| Entity | Action | Designated method | Genuine gate? | Notes |
|---|---|---|---|---|
| AdminUser/CustomerUser | set role | `UserController::applySelectedRole()` (private) | **No** | Zero invariant checks — no "keep one Owner/Super Admin" guard. Not just duplicated across 4+ call sites; doesn't validate at all, so redirecting the other callers here wouldn't fix anything by itself. |
| AdminUser/CustomerUser | set status | `{AdminUser,CustomerUser}::setStatus()` | **No** | Bare one-line setter (`$this->status = $status; return $this;`). No state machine, no guard. |
| AdminUser/CustomerUser | edit | `UserController::applyUserRequest` (protected) | No | Zero validation; `validateUserRequest()` is a separate call. |
| AdminUser/CustomerUser | delete | `UserController::delete` | Yes | Self-enforces "not last Super Admin" / "not self". |
| AdminUser/CustomerUser | password reset (email) | `UserController::resetPassword` | Yes | Self-enforces staff-management authorization. |
| AdminUser/CustomerUser | admin-set password | `UserController::setPasswordApply` | Partial | Auth check delegated properly; length/format validation inline in the Request action. |
| Company | create/edit | `applyCompanyRequest` | Partial | Writer has no validation; `validateCompany`/`ValidCompany` is separate. |
| Company | deactivate/reactivate | `CompanyController::delete/reactivate` | Yes | Request-free, checks current status, cascades to CustomerUser rows correctly. |
| Company | address/note CRUD | various `CompanyController` methods | Partial | Address methods delegate to a reusable validator; notes validate inline in the Request action. |
| Vendor | create/edit | `applyVendorRequest` (private) | **No** | Zero validation — not even "name required". |
| Vendor | activate/deactivate | `activateStatus/deactivateStatus` | Yes | Request-free, checks current status. |
| ProductCore | create/edit | `applyProductRequest` (private) | **No** | Zero validation. |
| ProductCore | CSV import | `ProductImportService::import()` | Yes | Genuine — already reused by independent import bundles. |
| ProductCore | **stock count** | `CoreInventoryTotalCountService::setCount()` | Yes, but bypassed | See "Confirmed live bug" above — fix in progress. |
| ProductCore | price / default price / suggested price | `updatePrice`/`updateCorePrice`/`updateSuggestedPrice` | Partial | Validates inline (`PriceNumber::isPrice`), but Request-bound end to end. |
| ProductCore | delete | `ProductController::delete` | Yes | Effectively Request-free, self-contained (image cleanup, FK-violation translation). |
| ProductCategory | create/edit/toggle/delete | `CategoryController::*` | Partial | toggle/delete are genuine gates on their own; create/update validate inline in the Request action. |
| ProductCategory | (import bundles) | — | **No — separate bypass** | `RimImportConfig`, `ProductCsvTransformer`, `CategoryPageConfig` each find-or-create `ProductCategory` rows directly, skipping `CategoryController`'s validation/parent/custom-field handling entirely. |
| Cart/CartItem | add/setQty/remove/clear | `CartService::*` | **Yes — cleanest gate in the registry** | Plain service, no Request coupling, enforces stock/region availability itself. |
| Cart | totals write-back | `CartController::recordCartTotals` (private) | No | No validation — persists whatever the caller already computed. Only writer today, but nothing enforces that. |
| Cart | checkout → order | `CheckoutController::submit` | Partial | Real invariants exist underneath (transactional stock re-check under lock, address/payment validation) but stay Request-coupled end to end — no plain method a future API could call. |

### Sell-side documents

| Entity | Action | Designated method | Genuine gate? | Notes |
|---|---|---|---|---|
| Estimate | create/edit | Controller → `EstimateLineReconciler` | **No** | Reconciler only diffs/prices lines; region, charge, tax and status rules live in the controller's `create()`/`edit()`, not the reconciler. |
| Estimate | lock/unlock | `DocumentLockService::lock/unlock` | Yes | Idempotent, no Request coupling, sole writer of `document_lock`. |
| Estimate | convert → order | `EstimateConversionService::convert()` | Yes | Validates status + fully-priced state, atomic claim via conditional UPDATE. |
| SalesOrder | create/edit | Controller → `SellSideLineReconciler` | **No** | Same shape as Estimate — stock-shortfall checks, invoiced-line-deletion refusal, optimistic-lock handling all stay in the controller. |
| SalesOrder | approve/void | `SalesOrder::setStatus()` | Yes | Delegates to `assertStatusChangeAllowed()`, throws on illegal transitions. |
| Invoice | create/edit | Controller → `InvoiceLineReconciler` | **No** | Same shape — province/tax refusal, stock checks, over-invoicing intent stay in the controller. |
| Invoice | status transitions | `Invoice::issue()/issueAwaitingPayment()/paymentReceived()/startProcessing()` | Yes | Runs `OverInvoicingGuard`/`MandatoryCaptureGuard` before `transitionTo()`. |
| Invoice | apply payment (capped) | `InvoicePayment::applyTo()` | Yes | Validates positive amount, caps at `getUnappliedBalance()`, runs `PaymentApplicationGuard`. |
| CreditMemo | issue/void | `CreditMemo::issue()` / `CreditMemoRestockResolver::void()` | Yes | `void()` requires a disposition when stock moved, throws otherwise. |
| CreditMemo | apply to invoice (capped) | `CreditMemo::applyTo()` | Yes | Caps against both this note's balance and the invoice's remaining balance — but see buy-side note, this is a second independent implementation of the same capping algorithm as `InvoicePayment::applyTo()`. |
| CreditMemo | refund | `CreditMemo::recordRefund()` | Yes | Caps at `getBalance()`. |
| SalesReturn | authorise/receive/decline/close | `SalesReturn::authorise()/receive()/decline()/close()` | Yes | Each enforces its own predecessor-status and quantity checks. |

### Buy-side documents

| Entity | Action | Designated method | Genuine gate? | Notes |
|---|---|---|---|---|
| PurchaseOrder | create/edit | Controller → `PurchaseSideLineReconciler` | **No** | Same reconciler-insufficiency pattern as the sell side: charge normalization, lock/guard checks, vendor/warehouse resolution stay in the controller. |
| PurchaseOrder | issue/close/cancel | `PurchaseOrder::issue()/closeShort()/cancel()` | Yes | Self-validates via `DomainException`, then calls shared `transitionTo()`. |
| PurchaseOrder | derived status | `PurchaseOrderStatusDeriver::recalculate()` | Yes | Delegates the state guard to `PurchaseOrder::applyDerivedStatus()`. |
| VendorBill | create/edit | Controller → `VendorBillLineReconciler` | **No** | Same shape — vendor cross-check, charge-line handling, status/lock checks live in the ~330-line controller action. |
| VendorBill | approve/dispute/void | `VendorBill::approve()/dispute()/void()` | Yes | Each throws on its own (tax-province check, required reason, paid-anything guard). |
| VendorBill | record/amend/move payment | `recordPayment`/`amendApplication`/`withdrawApplication`, `VendorBillPaymentMover` | Yes | Validates via `assertHolds()`/`assertPositive()`/`PaymentApplicationGuard`. |
| VendorBill | balance calc | `VendorBill::getBalance()` | Partial | Pure computed getter, nothing to bypass — but see next row, formula is inconsistent with its sibling. |
| DebitMemo | balance calc | `DebitMemo::getBalance()` | — | Three-term (`total − applied − refunded`) vs VendorBill's two-term (`total − amountPaid`) — self-flagged in DebitMemo's own docblock as an unresolved divergence, explicitly paralleling a known sell-side issue (#603). |
| DebitMemo | issue/void (disposition) | `DebitMemoStockService::issue()/void()` | Yes | Wraps in a transaction, throws on missing warehouse/product line/disposition. |
| DebitMemo | apply/withdraw/refund | `applyTo`/`withdrawApplication`/`recordRefund` | Yes | Checks status, cross-vendor mismatch, positivity, balance. |
| VendorReturn | authorise/decline/close | `VendorReturn::authorise()/decline()/close()` | Yes | No `setStatus()` escape hatch exists. |
| VendorReturn | ship (stock-moving) | `VendorReturnShipService::ship()` | Yes | Calls the state guard first, then moves stock transactionally via `StockMovementService`. |
| GoodsReceipt | receive (typed + scan) | `ReceivingService::receive()` | Yes | Takes a domain `ReceivingRequest`, not an HTTP Request; enforces `ProductReceivingRule` itself. |
| GoodsReceipt | void | `ReceiptVoidService::void()` | Yes | Refuses double-void, refuses insufficient stock via `StockMovementService::assertSourcesCanCover()`. |
| Rfq | create/send/cancel | mixed | Partial | `send()`/`cancel()` genuine; `save()` (create/edit) mutates `Rfq`/`RfqLine` fields directly in the controller. |
| RfqVendorReply | respond/decline/award | mixed | Partial | `decline()`/`RfqConversionService::convert()` genuine; `save()` (respond/pricing) does line-pricing and tax logic in the controller. |

### Warehouse ops / Inventory / Config

| Entity | Action | Designated method | Genuine gate? | Notes |
|---|---|---|---|---|
| TransferOrder | create/dispatch/receive/cancel | mixed | Partial | dispatch/receive genuine (delegate to `StockMovementService`); create/cancel are inline controller logic — note `TransferOrderService` has no `cancel()` method at all, that logic lives only in `TransferOrderController::cancel()`. |
| PickList/PickTask | compile/release/confirm/close | mixed | Partial | compile/confirm genuine; release/close inline in the controller — `PickConfirmationService` has no `close()` method, that lives only in `PickListController::close()`. |
| Shipment | ship/void | `ShipmentService::ship` / `ShipmentVoidService::void` | Yes | Both plain services, fully self-enforcing (idempotency, oversell guard, re-void refusal). Two legitimate callers of `ship()` — admin UI and `InvoiceShippingRemainderSubscriber` auto-ship — same gate, worth keeping that way deliberately. |
| InventoryAdjustment / all stock movements (any bundle) | post/apply | `StockMovementService::apply` | **Yes — universal, confirmed sole writer** | Every writer (Transfer, Pick, Adjustment, Shipment, Receiving, VendorReturn, PackConversion, CreditMemo) routes through this one method; maintains the `quantity + received == SUM(available)` invariant in one transaction. |
| Warehouse | create/update/delete | `handleWarehouseForm` (private) | **No** | All real validation (name required, region-name collision, province cross-check) is inline in a Request-coupled private method; `deleteWarehouse`'s region-in-use check is also inline with no delegation. |
| FulfillmentRegion | create/update/delete | `handleFulfillmentRegionForm` (private) | **No** | Same pattern as Warehouse. |
| PriceList | create/update/delete | `PriceListController::*` | Partial | create/update delegate validation to `ValidPriceList`; write stays inline; delete's only invariant is a caught FK-violation exception, not an explicit rule. |
| TrackingPolicy | save/delete | `TrackingPolicyController::*` | **No** | Name-uniqueness, `contradiction()` check, and delete's default-policy/in-use refusal are all inline; `contradiction()` is `private static` — unreachable outside this controller. |
| UnitOfMeasure | save/delete | `UnitOfMeasureService::add/update/delete` | Yes | Plain public methods, explicitly documented as the one path every write must take; enforces "freeze once referenced" itself. |
| SalesTax | create/update/delete | `handleSalesTaxForm` (private) | **No** | Same pattern as Warehouse/FulfillmentRegion. |
| CustomFieldDefinition | create/update/delete | `CustomFieldController::*` | Partial | Validation delegated to `ValidCustomFieldRequest` (reusable); mutation (`applyRequest`) stays inline. |
| BundleStatus | activate/deactivate | `BundleStatusRepository::activate()/deactivate()` | Yes | Routes through shared `setStatusForSource()`; explicitly documented as the one path both admin UI and console commands use. |

## Tally

- **Yes (genuine gate):** ~27
- **Partial (validated, but Request-locked or split across two methods):** ~16
- **No (real gap):** ~12

## Priority order for the next pass

1. **User status/role** — no validation exists anywhere yet, not even in the "canonical" method. Needs the guard logic written once (keep-one-Owner, keep-one-active-Super-Admin), not just a redirect of the 4+ existing call sites.
2. **`applyXRequest`-style zero-validation writers** — Vendor, ProductCore, User. Move the existing separate validation call *inside* the writer so it can't be called without it.
3. **Config forms trapped behind private Request-coupled handlers** — Warehouse, FulfillmentRegion, SalesTax, TrackingPolicy. Extract the validation+write into a plain public method the private form-handler calls, same shape as `UnitOfMeasureService`.
4. **Payment/credit capping duplicated** — `InvoicePayment::applyTo()` and `CreditMemo::applyTo()` independently reimplement the same "cap at both balances" algorithm. Candidate for one shared applier.
5. **Balance formula inconsistency** — `VendorBill::getBalance()` (two-term) vs `DebitMemo::getBalance()` (three-term). Decide the correct shape once, apply to both (mirrors open sell-side issue #603).
6. **CreditMemo line-save** — only sell-side document not on `SellSideLineReconciler`; hand-rolled delete/recreate in the controller.
7. **ProductCategory import-bundle bypass** — three import services create category rows directly, skipping `CategoryController`'s validation.
8. **Sell-side/buy-side document create/edit** — Estimate/SalesOrder/Invoice/PurchaseOrder/VendorBill all fail the gate test the same way: line reconcilers only handle lines, real invariants (region/tax/stock/lock rules) stay in ~150-300 line controller actions. Lower urgency than the above since today's only caller is the controller itself, but the biggest single structural item if a console command or API ever needs to create/edit one of these documents.
9. **Misattributions to fix in this doc itself** if revisited: `TransferOrderService`/`PickConfirmationService` don't have `cancel()`/`close()` methods — that logic is controller-only.
