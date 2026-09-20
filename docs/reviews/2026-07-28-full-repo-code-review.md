# Code review — full repository

**Date:** 2026-07-28
**Scope:** Full repository (`wholesale-b2b-core`), branch `fix/checkout-billing-duplicate-and-text` — entire codebase, not a diff
**Method:** Workflow-backed review. 12 subsystem finders raised 72 candidates; 20 adversarial verifiers (prompted to refute, and to downgrade overstated severity) confirmed 66 and refuted 6. Split into 34 security findings and 32 correctness/quality findings, then synthesized.

> Findings marked PLAUSIBLE have a confirmed code-level defect but an exploitation or failure path that depends on deployment specifics not observable from the repository. Confirm those in the target environment before acting on the stated severity.

---

## Executive summary

This is a review of the entire `wholesale-b2b-core` repository as it stands on `fix/checkout-billing-duplicate-and-text`, not of a diff. 28 distinct defects survived verification (duplicates merged, style nits excluded). Every entry below has a concrete failure mode and quoted evidence.

The headline defect is **critical and silent**: saving any product from the admin product form destroys that product's negotiated prices on every price list except the lowest-id one and rewrites the survivor at `0.00`, because the form no longer posts the fields the sync helper still reads. Three high-severity defects follow — an unattended hourly import that permanently zeroes approved-order inventory reservations, a card-payment failure path that bricks the submit button after the card is charged and enables a double charge on retry, and a headline B2B feature ("Reorder") that is completely non-functional because it writes to a cart implementation the server no longer reads.

Underneath the individual bugs sit three structural patterns: **no explicit transaction boundaries anywhere** in `src/` or `modules/`, **compensating cron jobs used in place of transactional invariants** (with the buckets of one table owned by three different bundles), and **two independent implementations of "compute an order total"** (customer checkout vs. admin order), which is where the coupon and fee-surcharge divergences come from.

Security boundaries held up under attack: `^/admin` is gated on `ROLE_ADMIN`, inactive accounts cannot log in, the address book is server-rendered per company, and cookies are `SameSite=lax`. Several admin findings that initially looked like tenancy bypasses turned out to require an already-privileged admin hand-crafting a request; they are reported as data-integrity defects at low severity, with reachability stated honestly.

### Findings table

| # | Severity | Location | Issue |
|---|---|---|---|
| F1 | **Critical** | `src/Controller/Admin/ProductController.php:1768` | Product save deletes ProductPricing on all other price lists and zeroes the survivor |
| F2 | ~~**High**~~ | ~~`modules/Number1RimImportBundle/src/Service/RimApiImportService.php:61` (+ `src/Controller/Admin/ProductImportController.php:30`)~~ | ~~`clear_approved_balance` defaults true; unattended sync permanently zeroes approved reservations~~ **— FIXED** (`fix/clear-approved-balance-default-false`) |
| F3 | **High** | `public/assets/js/app.js:2379` | Failed order-payment leaves submit permanently disabled after the card was charged; retry double-charges |
| F4 | **High** | `public/assets/js/app.js:2641` | "Reorder" writes to the dead localStorage cart; /cart is server-rendered — silent no-op |
| F5 | Medium | `src/Controller/Customer/CheckoutController.php:631` / `:591` | Coupon discount lives only in `total` + free text; re-pay and tax use the gross subtotal |
| F6 | Medium | `src/Controller/Customer/CheckoutController.php:164` | Checkout page computes fees with no payment method; order creation adds a fee never shown |
| F7 | Medium | `src/Service/CartService.php:216` | No stock check at order submission; cart-time check is an unlocked read |
| F8 | Medium | `src/Service/Inventory/OrderInventoryReservationService.php:119` | Inventory bucket read-modify-write races outside any transaction; no version, no lock |
| F9 | Medium | `src/Service/ProductImport/ProductImportService.php:190` | `inactive_missing` inactivates products whose rows merely failed validation |
| F10 | Medium | `src/EventSubscriber/ErrorLogSubscriber.php:55` | Exception listener flushes the shared EM, committing a failed request's pending changes |
| F11 | Medium | `src/Controller/Admin/ProductController.php:1874` + `src/Controller/Customer/AbstractCustomerController.php:282` | N+1 per grid/catalog row (~1900 queries per admin page) |
| F12 | Medium | `src/Controller/Admin/ProductController.php:353` | Sorting the pricing matrix by a price-list column hydrates the entire catalog |
| F13 | Medium | `src/Controller/Admin/UserController.php:802` | Null dereference: non-superadmin staff creation always 500s |
| F14 | Low | `modules/PaymentStripeBundle/src/Controller/StripeCheckoutIntentController.php:98` | Unpriced cart row passes `null` into a strict `float` param → 500 |
| F15 | Low | `src/Controller/Admin/PriceListController.php:163` | No in-use guard; deleting an assigned price list 500s on an FK violation |
| F16 | Low | `src/Controller/Admin/ProductController.php:1274` | Image files unlinked before a flush that the FK can abort |
| F17 | Low | `src/Entity/AdminOrderPayment.php:22` | Missing `onDelete: SET NULL`; any admin who recorded a payment can never be deleted |
| F18 | Low | `src/Controller/Admin/OrderController.php:693` | Order status written unvalidated; unknown value invoices the order and releases its stock |
| F19 | Low | `src/Controller/Admin/OrderController.php:1090` | Payment loaded by request id without scoping to the URL's order |
| F20 | Low | `src/Controller/Admin/OrderController.php:514` | `setBillingAddress()` called outside the same-company check |
| F21 | Low | `src/Controller/Admin/CompanyController.php:463` | Company user created with no role; company taken from POST body, not the route |
| F22 | Low | `src/Controller/Admin/EstimateController.php:433` | No status-transition rules; `Accepted` without a converted order is an unrecoverable dead end |
| F23 | Low | `src/Service/CartService.php:263` | Cart keyed on session id; login migration orphans the DB cart permanently |
| F24 | Low | `src/Entity/AuditLog.php:9` | `audit_log` has zero indexes and no retention; unbounded scans on three admin pages |
| F25 | Low | `src/Controller/Admin/ProductController.php:905` | Bulk price-rule apply commits per batch with no transaction and no time budget |
| F26 | Low | `src/Service/ProductImport/ProductImportService.php:163` | Two flushes per row, no `clear()`, no batching, no transaction |
| F27 | Low | `public/assets/js/app.js:504` | AJAX table loader has no `error` handler and no request sequencing |
| F28 | Low | `public/assets/js/app.js:2317` | `confirmCardPayment().then()` with no rejection handler leaves the form hung |

---

## Theme 1 — Pricing and money correctness

The most damaging class of defect in this repo. Prices and totals are computed in more than one place, and the pieces disagree.

### F1. Saving a product destroys its prices on every other price list — `src/Controller/Admin/ProductController.php:1768` — **Critical**

**What is wrong.** `syncProductPricing()` is called unconditionally on both product create (`:1226`) and product update (`:1149`). It reads `price_list_id` and `sale_price` from the request — but `templates/admin/product/form.html.twig` no longer posts either field (removed in commit `daa17e2`, "New FLow changes for the admin side", which deleted the `<input name="sale_price">` and `<select name="price_list_id">` while leaving the controller helper in place). Missing input is silently coalesced into a destructive default.

```php
// src/Controller/Admin/ProductController.php:1742-1773
private function syncProductPricing(ProductCore $product, Request $request, EntityManagerInterface $entityManager): void
{
    $priceList = $entityManager->find(PriceList::class, (int) $request->request->get('price_list_id'));
    if (!$priceList instanceof PriceList) {
        $priceList = $entityManager->getRepository(PriceList::class)->findOneBy([], ['id' => 'ASC']);
    }
    ...
    $pricing
        ->setPrice($this->nullableMoney($request->request->get('sale_price')) ?? '0.00')
        ->setCurrency($priceList->getCurrency());

    $entityManager->persist($pricing);

    foreach ($entityManager->getRepository(ProductPricing::class)->findBy(['product' => $product]) as $oldPricing) {
        if ($oldPricing instanceof ProductPricing && $oldPricing->getPriceList()->getId() !== $priceList->getId()) {
            $entityManager->remove($oldPricing);
        }
    }
}
```

There is a second, even broader branch: if no `PriceList` exists at all, `:1749-1755` removes **every** `ProductPricing` row for the product and returns.

`validateProductRequest()` (`:1436-1496`) validates `sale_price` only when it is non-empty, so nothing blocks the save; the removals are flushed at `:1237`.

**Failure scenario.** Product `SKU-1` carries negotiated rows on price lists 1 (Wholesale), 2 (Distributor) and 3 (Key Account), created through the pricing matrix at `/admin/product/price/index`. An admin opens `/admin/product/inventory/update/123` to fix a typo in the short description and clicks Save. `price_list_id` is absent → `(int) null === 0` → `find(PriceList, 0)` is null → fallback to `findOneBy([], ['id' => 'ASC'])` = price list 1. `sale_price` is absent → the row on list 1 is written `'0.00'`. The loop then deletes the Distributor and Key Account rows outright. Companies on lists 2 and 3 silently fall through to base price in `AbstractCustomerController::resolveCustomerPriceAmount()`; if the list-1 row was freshly created (no `ruleType`), `resolveCustomerPricing()` returns `number_format(max(0, 0.0), 2) = "0.00"` and companies on list 1 can add the product to cart and check out at **$0.00**.

Nuance worth recording: a *surviving* row that already carries a numeric `ruleType`/`ruleValue` (everything the matrix at `:646-659` or bulk-apply at `:898-899` writes) still recomputes its effective price from the rule, so the $0.00 exposure is limited to rule-less rows. The cross-price-list **deletion is unconditional and irreversible** regardless.

**Fix.** Make `syncProductPricing()` a no-op when the request does not carry pricing fields — e.g. `if (!$request->request->has('price_list_id') && !$request->request->has('sale_price')) { return; }` at the top — and delete the cross-price-list removal loop entirely; a single-price form has no business deleting rows on lists it does not represent. Longer term, pricing should be owned exclusively by the pricing matrix endpoints and removed from the product form save path. Add a regression test that saves a product with three pricing rows and asserts all three survive unchanged.

### F5. Coupon discounts exist only in `total` and free text — `src/Controller/Customer/CheckoutController.php:631` and `:591` — **Medium**

**What is wrong.** Two consequences of one root cause: a checkout coupon is never represented structurally on the order. `subtotal` is stored pre-discount, `total` is stored post-discount, and the discount itself survives only as appended text in `specialInstructions` (`:612-617`).

```php
// src/Controller/Customer/CheckoutController.php:630-636
$order
    ->setSubtotal($this->decimal($subtotal))        // undiscounted
    ->setShipping($this->decimal($totals['shipping']))
    ->setTax($this->decimal($totals['tax']))
    ->setDeposit('0.00')
    ->setTotal($this->decimal($totals['total']))    // discounted
    ->setTaxLines($taxBreakdownService->toJson($taxBreakdown));
```

(a) **Later card payment recharges the gross amount.** `OrderController::orderPaymentTotals()` rebuilds the payable figure from the stored pre-discount subtotal with no knowledge of the coupon:

```php
// src/Controller/Customer/OrderController.php:849-884
$subtotal = max(0.0, (float) $order->getSubtotal());
...
$originalPreTax = $subtotal + $shipping + $feeTotal;
$taxRate = $originalPreTax > 0 ? max(0.0, (float) $order->getTax()) / $originalPreTax : 0.0;
...
'total' => $preTax + $tax + $deposit,
```
and that figure is charged verbatim: `OrderController.php:593-594` `$amount = (int) round(max(0, $totals['total']) * 100);`.

(b) **Tax is computed on the undiscounted base.** The taxable lines come straight from the order lines, which were set undiscounted at `:565`:

```php
// src/Controller/Customer/CheckoutController.php:589-596
foreach ($order->getLines() as $line) {
    $taxableLines[] = ['subtotal' => (float) $line->getSubtotal(), 'taxCode' => $line->getTaxCode()];
}
$taxBreakdown = $taxBreakdownService->computeBreakdown(...);
```
while `checkoutTotals()` charges `$preTax = $discountedSubtotal + $shipping + $feeTotal` (`:1109-1122`).

The file's own comment at `:1057-1061` states the opposite intent for shipping — "tax on shipping should be $0 when shipping itself is waived", implemented via `checkoutEffectiveShipping()` — so the line-level omission is an internal inconsistency, not policy. `payOrder()` at `:651-655` also does `->setSubtotal($this->decimal($totals['discountedSubtotal']))` when a coupon *is* supplied, confirming the intended invariant.

**Failure scenario.** BC customer, $1,000 taxable ('S') cart, 50% coupon, shipping waived, "Pay Upon Delivery". Tax is computed on $1,000 → GST $50 + PST $70 = $120 instead of the $60 due on the $500 actually charged; the order is persisted with subtotal 1000.00, tax 120.00, total 620.00. The customer later opens the order and pays by card: `resolveOrderCouponFromRequest` returns null, `orderPaymentTotals` recomputes 1000 + tax = $1,120 and the card is charged **$1,120 against a $620 order**. `templates/customer/order/invoice_pdf.html.twig:221-252` prints Subtotal $1,000 + tax $120 and then Total $620, with no discount row — the invoice does not foot.

**Prerequisite (bounds severity, does not refute):** coupons come from the `checkout_coupons` AppSetting (`CheckoutController.php:992`), which is absent from the shipped DB and has no dedicated admin field — it is creatable through the generic setting CRUD (`ConfigController.php:374-380`).

**Fix.** Add `discount` (and ideally `couponCode`) columns to `AbstractSalesDocument`, persist `discountedSubtotal` into `subtotal` at `CheckoutController.php:631` exactly as `payOrder()` already does, and pass discount-adjusted line subtotals into `computeBreakdown()` (pro-rate the discount across taxable lines, mirroring `checkoutEffectiveShipping()`). Render a Discount row in `invoice_pdf.html.twig` and `detail.html.twig`, and assert `subtotal + shipping + fees + tax - discount == total` in a test.

### F6. Checkout page hides payment-method fees the order then charges — `src/Controller/Customer/CheckoutController.php:164` — **Medium**

**What is wrong.** The display path builds `FeeContext` without a payment method; the save path builds it with one.

```php
// Display — src/Controller/Customer/CheckoutController.php:164
$feeLines = $feeResolver->calculate(new FeeContext($province, $shippingCartItems, $pstNumber, companyId: $company->getId()));
```
The named `companyId:` argument skips the 4th positional parameter, `public readonly ?string $paymentMethod = null` (`src/Contract/Fee/FeeContext.php:33-38`).

```php
// Save — src/Controller/Customer/CheckoutController.php:578-586
$feeContext = new FeeContext(
    $shippingAddress->getProvince() ?? '',
    $feeCartItems,
    $company->getPstNumber() ?? '',
    $paymentMethod->getSlug(),
    companyId: $company->getId(),
);
```
Every payment-method fee calculator matches purely on that field (`src/Contract/Fee/AbstractFeeCalculator.php:17-20`: `return $context->paymentMethod === $this->supportedPaymentMethodSlug();`), and `PayUponDeliverySurchargeFeeCalculator` ships **Active** (bundle_status id 18) returning a $2.00 'G'-class line.

Verified there is no client-side compensation: `loadCheckoutFeeLines()` (`app.js:1712-1715`) returns immediately because `data-fee-lines-url` exists only on `templates/admin/order/form.html.twig:195`, not the customer form; and re-rendering after `customer_checkout_set_payment_method` still goes through line 164.

**Failure scenario.** A customer selects "Pay Upon Delivery". The page renders $105.00. On submit, the surcharge is added to `$feeTotal` and to the tax base, and the order is persisted and invoiced at $107.10 — a line item the customer was never shown. Any bundle adding a payment-method-dependent fee inherits the same mismatch.

**Fix.** Pass the currently selected payment method's slug into the display `FeeContext` at `:164` (it is already resolvable — `customer_checkout_set_payment_method` stores the selection), and extract a single `buildFeeContext(...)` helper used by both the render and the persist path so they cannot drift again.

### F14. Unpriced cart row 500s the Stripe intent endpoint — `modules/PaymentStripeBundle/src/Controller/StripeCheckoutIntentController.php:98` — **Low**

**What is wrong.** The intent controller builds taxable lines from every cart row, including rows where `priceResolved` is false and `subtotal` is `null`:

```php
// modules/PaymentStripeBundle/src/Controller/StripeCheckoutIntentController.php:95-99
foreach ($rows as $row) {
    $cartItems[] = ['product' => $row['product'], 'qty' => $row['qty']];
    $rawTaxCodes[] = $row['product']->getSalesTaxCode();
    $taxableLines[] = ['subtotal' => $row['subtotal'], 'taxCode' => $row['product']->getSalesTaxCode()];
}
```
`resolveCartRows()` deliberately keeps such rows: `src/Controller/Customer/AbstractCustomerController.php:107` `'subtotal' => $priceResolved ? $price * $qty : null,`. The consumer is strict-typed — `src/Service/OrderTaxBreakdownService.php` has `declare(strict_types=1)` and `:61` constructs `new TaxContext($province, $line['subtotal'], ...)` against `public readonly float $subtotal` (`src/Contract/Tax/TaxContext.php:31-35`). Verified empirically against the real class: `TypeError: App\Contract\Tax\TaxContext::__construct(): Argument #2 ($subtotal) must be of type float, null given`.

`CheckoutController` guards correctly (`:116-118` `if ($row['priceResolved'])`), which is why only this controller breaks. The `try/catch` at `:136-148` wraps only the `StripeClient` call.

**Failure scenario.** A cart with one normally-priced item plus one item on a "No Price" rule, Stripe selected. The JS POSTs `/checkout/payment/stripe-intent`, the TypeError escapes → HTTP 500, and `app.js:2337` shows the generic "Could not start card payment." The customer cannot submit the order at all — not even as the quote request it should become — and a stack trace lands in the logs.

**Fix.** Mirror `CheckoutController`'s guard: only push rows with `$row['priceResolved']` into `$taxableLines`, and return a structured `{ok: false, message: 'This cart must be submitted as a quote request.'}` when any row is unpriced, so the JS can surface the real reason.

---

## Theme 2 — Inventory and stock integrity

Availability is a derived cache (`quantity - cartHold - pending - approved`) maintained by event listeners and repaired by cron. Both the maintenance and the repair have holes.

### F2. Unattended imports permanently zero approved-order reservations — `modules/Number1RimImportBundle/src/Service/RimApiImportService.php:61` and `src/Controller/Admin/ProductImportController.php:30` — **High**

**What is wrong.** `ProductImportService::import()` defaults `clear_approved_balance` to **true**:

```php
// src/Service/ProductImport/ProductImportService.php:41-44
// Defaults true (checked) even if the option is omitted, matching the import UI's
// default-checked checkbox — ...
$clearApprovedBalance = (bool) ($options['clear_approved_balance'] ?? true);
```
The comment states the intent: the value should come from the import UI's checkbox. Two callers never pass it —

```php
// modules/Number1RimImportBundle/src/Service/RimApiImportService.php:61-67
$result = $this->importService->import($transformResult->canonicalCsv, $entityManager, [
    'primary_key' => 'sku',
    'missing_rows' => 'do_nothing',
]);
```
```php
// src/Controller/Admin/ProductImportController.php:30-34
$result = $importService->import($csv, $entityManager, [
    'primary_key' => $primaryKey,
    'missing_rows' => $missingRows,
    'progress_token' => $importToken,
]);
```
— while `modules/Number1ProductImportBundle/src/Controller/Admin/ProductImportController.php:211` does it correctly (`'clear_approved_balance' => $clearApprovedBalance === 'yes',`). So this is a **shared default**, not a rim-only oversight.

When the flag is on, `applyInventory()` both zeroes the bucket and stamps the reservations so the zero looks correct:

```php
// src/Service/ProductImport/ProductImportService.php:641-661
if ($clearApprovedBalance) {
    $previousApproved = $row->getApprovedQuantity();
    $row->setApprovedQuantity(0);
    ...
    foreach ($approvedReservations as $reservation) {
        $reservation->setSyncedQuantity($reservation->getQuantity())->touch();
        $entityManager->persist($reservation);
    }
```
The rim path reaches this branch for every row with a non-empty qty: `RimApiTransformer.php:50-82` emits a `fulfillment_region__<slug>` column, and `ProductImportService.php:620` filters on exactly that prefix. (Precondition: a `FulfillmentRegion` must be configured — `RimImportConfig.php:64` returns null when `region_id` is 0.)

**Neither repair path can undo it**, because both honour the stamped baseline:
- `src/Service/Inventory/OrderInventoryReservationService.php:112-116` — `max(0, $newQuantity - $syncedQuantityCarried)`
- `modules/Number1ProductImportBundle/src/Command/ApprovedInventoryRecalcCommand.php:155-156` — `$effective = max(0, $rawQty - $synced);`

**Failure scenario.** A customer orders 40 units of `RA-1801` in the Main region and an admin approves it: `approvedQuantity = 40`, available = quantity − 40. The hourly `number1-rim-import:sync` cron runs (`SyncRimProductsCommand`, no way to pass the flag). The feed still reports qty 100, so `applyInventory()` sets quantity = 100 **and** `approvedQuantity = 0`, stamping `syncedQuantity = quantity` on the approved reservation. Available jumps from 60 back to 100 for stock that is committed to an approved, unshipped order. The next `app:product-import:approved-recalc` computes `max(0, 40 - 40) = 0` and agrees. Every subsequent hourly run repeats this for every order approved in between, so the storefront persistently oversells by the entire approved backlog.

**Fix.** Flip the default to `false` in `ProductImportService.php:44` (an omitted option should never mean "destroy reservation state") and have the UI pass `true` explicitly from its checkbox; pass `'clear_approved_balance' => false` explicitly in `RimApiImportService.php:61` since an API feed is not a physical stock count. Add an assertion test: approve an order, run an import that omits the flag, assert `approvedQuantity` is unchanged.

### F7. Order submission performs no stock check at all — `src/Service/CartService.php:216` — **Medium**

**What is wrong.** The only availability gate in the customer flow is at cart-mutation time, and it is an unlocked read:

```php
// src/Service/CartService.php:213-243
private function capOrRemove(Cart $cart, CartItem $item, FulfillmentRegion $region, int $requested): ?string
{
    $available = $this->availableQuantity($item->getProduct(), $region);
    ...
    if ($requested > $available) {
        $item->setQuantity($available)->touch();
...
private function availableQuantity(ProductCore $product, FulfillmentRegion $region): int
{
    $inventory = $this->entityManager->getRepository(ProductInventory::class)->findOneBy([...]);
    return $inventory === null ? 0 : max(0, $inventory->getAvailableQuantity());
}
```
`CheckoutController::submit()` (`:293-345`) calls `processCheckoutSubmission()` directly and — unlike `index()` at `:80-81` — never calls `reconcileAgainstRegion()`. `processCheckoutSubmission()` (`:374-476`) validates region, cart emptiness, shipping address, province, payment method, shipping option and pricing, and never touches `ProductInventory`. `OrderInventoryReconciliationSubscriber` contains no `throw` and no availability check; it only moves quantities into the Pending bucket. `ProductInventory::getAvailableQuantity()` is deliberately unclamped and can go negative.

**Failure scenarios.** Three reachable paths, in increasing likelihood:
1. **Expired hold (routine).** Cart holds default to 300s (`CartHoldService::durationSeconds`, `:41-44`) and are swept globally by `releaseExpired()`. A customer who leaves the checkout page open past that window POSTs `/checkout` and the order is accepted with zero stock validation.
2. **CartHoldBundle Inactive.** `isEnabled()` (`modules/CartHoldBundle/src/Service/CartHoldService.php:36-39`) is a bundle toggle. Switched off, `syncForCurrentCart` releases everything and `cartHoldQuantity` stays 0 — nothing reserves anything, and any number of customers can each check out the full quantity.
3. **Check-then-write race.** Two `/cart/add?qty=10` POSTs milliseconds apart both read `available = 10` before either `CartHold` row is committed; `recomputeCache` then sums both and `availableQuantity()` goes to −10.

Note: Cart Hold is Active by default (`BundleStatusRepository::isActive` — "a bundle is Active until a status row explicitly says otherwise"), so the naive sequential two-shopper case *is* blocked. Paths 1–3 are not.

**Fix.** Re-validate availability inside `processCheckoutSubmission()` before persisting the order — call `reconcileAgainstRegion()` (as `index()` does) and reject the submission with a clear per-line message if any quantity was capped. That is the single highest-value change here; the locking issue (F8) is what makes it fully correct.

### F8. Inventory bucket updates race outside any transaction — `src/Service/Inventory/OrderInventoryReservationService.php:119` (entity: `src/Entity/ProductInventory.php:70`) — **Medium**

**What is wrong.** Bucket counters are read-modify-write with no optimistic version and no pessimistic lock, and the writer runs **after** the order transaction has already committed.

```php
// src/Service/Inventory/OrderInventoryReservationService.php:119-128
$inventory = $entityManager->getRepository(ProductInventory::class)->findOneBy([
    'product' => $product,
    'fulfillmentRegion' => $region,
]) ?? (new ProductInventory())->setProduct($product)->setFulfillmentRegion($region);
...
if ($previousEffective !== $newEffective) {
    $this->applyBucketDelta($inventory, $targetBucket, $newEffective - $previousEffective);
```
```php
// src/Entity/ProductInventory.php:72
public function adjustPending(int $delta): self { $this->pendingQuantity = max(0, $this->pendingQuantity + $delta); return $this; }
```
The mutation is relative in memory but Doctrine emits `UPDATE product_inventory SET pending_quantity = <absolute>`. `OrderInventoryReconciliationSubscriber` registers `#[AsDoctrineListener(event: Events::postFlush)]` (`:29-30`) and calls `reconcile()` at `:57-70`; Doctrine commits at `UnitOfWork.php:434` and dispatches postFlush at `:472`. I confirmed repo-wide that **no** locking or transaction primitives exist: `grep -rn "beginTransaction\|wrapInTransaction\|LockMode\|ORM\\Version" src/ modules/ --include=*.php` returns zero matches.

**Failure scenarios.**
- *Lost update.* Two checkouts for the same SKU/region: A reads `pending = 0` and computes 4; B reads 0 and computes 6. A writes 4, B writes 6. No conflict is raised because both reads preceded both write transactions. The reservation ledger holds both rows totalling 10 while the cache says 6 — availability over-reports by 4 and the store keeps selling.
- *Post-commit failure.* `reconcile()` can insert a brand-new `ProductInventory`, guarded by `uniq_product_inventory_product_location`. Two concurrent approvals for a product/region with no row yet: both orders commit, both insert, the second flush fails on the unique index. The order is committed and visible as Approved with its quantities never applied, and the admin gets a 500 on a successful operation. The same window swallows the reservation for any request aborted between commit and `:170`.

Mitigating context (why this is Medium, not High): the drift is an explicitly anticipated, compensated condition — `src/Command/InventoryRecalcCommand.php:23-27` documents `app:inventory-recalc` as an hourly job that recomputes Pending from source of truth and emails discrepancies, and `ApprovedInventoryRecalcCommand` does the same for Approved. `CartHoldService::recomputeCache()` (`:194-207`) converges by design and is *not* affected. The dev DB is SQLite (serialized writers), so the race needs a MySQL/Postgres deployment.

**Fix.** Wrap `reconcile()` in `$entityManager->wrapInTransaction(...)` and acquire a row lock on the `ProductInventory` row before reading it (`find(..., LockMode::PESSIMISTIC_WRITE)`), or add `#[ORM\Version]` to `ProductInventory` and retry on `OptimisticLockException`. Also move reconciliation out of `postFlush` into the same transaction as the order write (e.g. `onFlush` with `recomputeSingleEntityChangeSet`, or an explicit service call inside a controller-owned transaction) so a reconciliation failure rolls the order back instead of orphaning it.

### F9. Import inactivates products whose rows merely failed validation — `src/Service/ProductImport/ProductImportService.php:190` — **Medium**

**What is wrong.** With `missing_rows=inactive_missing`, a row that fails validation `continue`s before its key is recorded, so the sweep treats it as absent from the file.

```php
// src/Service/ProductImport/ProductImportService.php:150-159
$rowErrors = $this->validateRowData($data, $isNew);
if ($rowErrors !== []) {
    ...
    $result->skipped++;
    continue;
}
```
```php
// :175  — only reached by rows that fully succeeded
$importedKeys[$importKey] = true;
```
```php
// :190-192
if ($missingRows === 'inactive_missing' && $importedKeys !== []) {
    $result->inactivated = $this->inactivateMissing($entityManager, $primaryKey, $importedKeys);
}
```
```php
// :748-752
if (!isset($importedKeys[$primaryKey . ':' . $keyValue])) {
    if ($product->getStatus() !== 'Inactive') {
        $product->setStatus('Inactive')->touch();
```
The trip condition is trivial — `isNonNegativeNumeric()` (`:889-893`) only strips commas, so Excel currency formatting (`$12.50`) fails `:545-547`. The `$importedKeys !== []` guard only covers the all-rows-failed case; one good row arms the sweep. The option is offered in the UI at `templates/admin/product/import.html.twig:66-69`, and `Number1ProductImportBundle`'s controller maps its own 'inactivate' option onto the same value (`:210`).

**Failure scenario.** A 5,000-SKU catalog export with 40 rows carrying `$12.50` in `cost_price`. Those 40 SKUs are skipped, then inactivated — pulled from the storefront (both `CatalogQueryService` and the customer catalog filter on `status = 'Active'`) — despite the admin explicitly including them. The only feedback is "Import completed with 40 error(s)" plus a separate "N set inactive" count, which reads as intended behaviour.

**Fix.** Record the key for every row that parsed a primary key, regardless of validation outcome — move `$importedKeys[$importKey] = true;` to immediately after the key is extracted, before the validation `continue`. Optionally also refuse to run the sweep when `$result->skipped > 0` and surface an explicit "N rows had errors; missing-row inactivation was skipped" message.

---

## Theme 3 — Payment flow integrity (client-driven checkout)

The card flow is confirmed client-side and then reported to the server in a second request. Both failure paths between those two steps are mishandled.

### F3. Failed order-payment leaves the submit button dead after the card is charged — `public/assets/js/app.js:2379` — **High**

**What is wrong.** `submitCheckoutAjax`'s `.always()` re-enables the button based on the *localStorage* cart, which is structurally always empty on the order-payment page:

```js
// public/assets/js/app.js:2378-2385
.always(function () {
    var disableForEmptyCart = readCart().length <= 0;
    $submit.data('submitting', false)
        .prop('disabled', disableForEmptyCart)
        .toggleClass('is-disabled', disableForEmptyCart)
        .attr('aria-disabled', disableForEmptyCart ? 'true' : 'false')
        .text(originalSubmitText);
});
```
The same flow already knows the localStorage cart is irrelevant here: `app.js:2825` `var cart = isOrderPaymentCheckout($form) ? [] : readCart();`. `.js-checkout-form` exists on exactly one page — `templates/customer/order/detail.html.twig:233`, carrying `data-order-payment="1"` (`:236`) — and nothing on that page ever writes `wcCart`.

The retry is worse than the hang, because the server has no idempotency:
```php
// src/Controller/Customer/OrderController.php:601-611 — new intent on every call, no idempotency key
$intent = $stripe->paymentIntents->create(['amount' => $amount, 'currency' => 'cad', ...]);
```
```php
// src/Controller/Customer/OrderController.php:747-751 — dedupe keyed on intent id only
foreach ($order->getPayments() as $existingPayment) {
    if ($existingPayment->getStripePaymentIntentId() === $intentId) { return; }
}
```

**Failure scenario.** Customer opens `/orders/{id}?payment=1`, enters a card, clicks Confirm. `confirmStripeCheckoutPayment` succeeds — **the card is charged** (`app.js:2181-2196` confirms `succeeded`). The follow-up POST to `customer_order_payment` then fails (400 from `payOrder`, `OrderController.php:632-649` — expired CSRF token, or `validateOrderStripePayment` returning "Your card payment amount does not match the order total" at `:946-949` because an admin edited the total). `.fail` shows a toast; `.always` disables the button. The order is unpaid, the customer is charged, and the only recovery is a reload — after which the order still shows "Make Payment" (`canMakePayment` at `:710-718` still returns true), so the customer pays again, a *second* PaymentIntent is minted, dedupe by intent id does not match, and the card is charged twice. A customer who has ever clicked Reorder has a non-empty `wcCart`, so the button *is* re-enabled and an immediate second click produces the double charge with no reload at all.

**Fix.** Three changes: (1) in `.always()`, use `isOrderPaymentCheckout($form) ? false : readCart().length <= 0` — i.e. never leave the order-payment button disabled; (2) pass a stable idempotency key (`order-{id}-{total}`) to `paymentIntents->create()` and reuse an existing unconsumed intent for the order rather than minting a new one per POST; (3) on a failed follow-up POST where a confirmed `payment_intent_id` exists, record the payment server-side anyway (or surface an explicit "your card was charged — contact support / retry with this reference" state) instead of a generic toast.

### F28. `confirmCardPayment().then()` with no rejection handler — `public/assets/js/app.js:2317` and `:2183` — **Low**

**What is wrong.** Both Stripe confirmation flows attach only a fulfilment handler:

```js
// public/assets/js/app.js:2317-2335 (#checkout-order-form)
newCheckoutStripe.stripe.confirmCardPayment(res.client_secret, {
    payment_method: { card: newCheckoutStripe.cardNumber }
}).then(function (result) {
    if (result.error) { ... $submitBtn.prop('disabled', false); return; }
    ...
    $form.trigger('submit');
});
```
```js
// public/assets/js/app.js:2183-2196 (order-payment)
}).then(function (result) {
    if (result.error) { deferred.reject(result.error.message || 'Card payment failed.'); return; }
    ...
    deferred.resolve(result.paymentIntent);
});
```
No `.catch`, no second `then` argument, after `$submitBtn.prop('disabled', true)` (`:2291`) / `.text('Processing...')` (`:2868-2873`). There is no safety net: no `$.ajaxSetup`/`ajaxError`, and `app.js` is the only script (`templates/base.html.twig:79`).

**Failure scenario.** Any rejection (rather than an `{error}` resolution) leaves the `#checkout-order-form` button permanently disabled with an empty `[data-stripe-error]` panel, or leaves the jQuery deferred unsettled so neither `.done` nor `.fail` at `:2877-2888` runs. The page appears hung with zero feedback and the customer cannot tell whether the card was charged.

**Honest caveat on reachability.** Stripe.js resolves with an `{error}` object for card, API and connection failures, which the existing branches already handle; the realistic throw is a synchronous `IntegrationError` on a malformed `client_secret`, which the guard at `:2313-2316` makes unlikely. The mechanism is real, the trigger is unproven, and the impact is a hung form recoverable by reload.

**Fix.** Add `.catch(function (err) { ... })` to both calls: re-enable the button, render the message into `[data-stripe-error]`, and `deferred.reject(...)` in the order-payment case so the existing `.fail` handler runs.

---

## Theme 4 — Broken customer-facing features

### F4. "Reorder" writes into a cart the server no longer reads — `public/assets/js/app.js:2641` — **High**

**What is wrong.** Reorder populates the legacy localStorage cart and then navigates to a page rendered exclusively from the DB-backed `CartService`.

```js
// public/assets/js/app.js:2612-2625
function addOrderLinesToCart(lines) { (lines || []).forEach(function (line) { ... addToCart({ sku: ..., qty: ... }); }); }
// :2641-2642
addOrderLinesToCart(parsedLines);
window.location.href = '/cart';
```
`addToCart` ends at `writeCart(cart); updateCartBadge(); ...` (`:1432-1435`), where `writeCart` touches only `localStorage['wcCart']` (`:1278-1287`, `var CART_KEY = 'wcCart'` at `:1243`) and `updateCartBadge` is an explicit no-op whose own comment states the migration:

```js
// public/assets/js/app.js:1298-1302
function updateCartBadge() { /* No-op: the cart badge is now server-rendered from the session cart ... since /cart moved to a no-JS, server-backed flow instead of localStorage. */ }
```
`/cart` is fully server-rendered: `src/Controller/Customer/CartController.php:45` `$rows = ($company instanceof Company && !$blocked) ? $this->resolveCartRows(...) : [];` → `templates/customer/cart/index.html.twig:56` `{% for row in rows %}`. The JS cart renderer is dead code — `renderCartPage()` bails at `:1474-1475` on `$('.js-cart-rows')`, and that selector appears nowhere in `templates/`, `modules/` or `public/` except `app.js` itself. There is no server sync of the localStorage cart (no `cart_payload` anywhere) and no server-side reorder route; real add-to-cart is a plain form POST to `customer_cart_add`.

**Failure scenario.** A customer opens Orders and clicks REORDER on `templates/customer/order/_list_rows.html.twig:89`. They are redirected to `/cart`, which shows exactly what it showed before — none of the reordered SKUs, and no error. If they had clicked Reorder previously, `beginReorder` first pops the "Your cart contains existing items" confirmation modal based on stale localStorage, then still adds nothing. `wcCart` also grows monotonically, since `clearCart()` is only reachable from `submitCheckoutAjax` when `!isOrderPaymentCheckout($form)` and the only remaining `#checkout-form` (`templates/customer/order/detail.html.twig:236`) carries `data-order-payment="1"`.

**Fix.** Add a server route (e.g. `POST /orders/{id}/reorder`) that adds the order's lines through `CartService` with the normal availability capping, and change the REORDER button to a form POST to it. Then delete the dead localStorage cart machinery (`addToCart`, `writeCart`, `renderCartPage`, `readCart`, `clearCart`) so a future change cannot resurrect the same class of bug.

### F27. AJAX table loader has no error handler and no request sequencing — `public/assets/js/app.js:504` — **Low**

**What is wrong.**

```js
// public/assets/js/app.js:502-552
$card.addClass('is-loading').attr('aria-busy', 'true');
$.ajax({
    url: ajaxUrl,
    data: $.extend({}, extraParams, { page: page, limit: limit, q: q, sort: sort, dir: dir, filters: filters }),
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    success: function (res) { ... $tbody.html(res.html); ... renderPaginationInternal($card, res.page, pages); },
    complete: function () { $card.removeClass('is-loading').attr('aria-busy', 'false'); }
});
```
No `error`, no abort of the in-flight XHR, and no global `ajaxError`. The page pointer is mutated *before* the request is issued (`:798-801` and `:253-257`: `$card.data('current-page', target); $card.trigger('wc:paginate');`). The live configuration reaching this path is `templates/customer/company_user/index.html.twig:14-15` (a `.table-card ... ajax-only` with `data-ajax-url`, for which `useFullReload` at `:357` is false).

**Failure scenario.** On `/company-users`, clicking page 2 when the request 500s (or the session expired and Symfony returns HTML) leaves the tbody, the `.table-count` and the pager showing page 1 with no error — while `current-page` is now 2, so ">" jumps to page 3 and page 2 is never seen. Separately, two rapid clicks render whichever response lands last: a slow page-2 response overwrites page-3 rows while `renderPaginationInternal` re-highlights page 2.

**Fix.** Add an `error` handler that shows a toast and restores the previous `current-page`, and store the jqXHR on the card so a new request aborts the previous one (or tag each request with a monotonically increasing token and ignore stale responses).

---

## Theme 5 — Transaction boundaries and data-layer integrity

### F10. The exception listener flushes the failed request's pending changes — `src/EventSubscriber/ErrorLogSubscriber.php:55` — **Medium**

**What is wrong.** The `kernel.exception` listener persists its log through the **shared** EntityManager and flushes, committing whatever the failed request had already scheduled in the UnitOfWork:

```php
// src/EventSubscriber/ErrorLogSubscriber.php:48-58
$log = (new ErrorLog())->setLevel('error')->setArea($area)->setMessage(...);
$this->entityManager->persist($log);
$this->entityManager->flush();
} catch (\Throwable) {
    // Avoid cascading failures if DB is down or logging itself errors.
}
```
The `catch` only swallows the failure; it does not prevent it. There is no `clear()`, no separate EM, and — confirmed repo-wide — no enclosing transaction to roll back. `src/EventSubscriber/MailerLogSubscriber.php:62` has the identical unscoped flush.

A concrete throw site exists upstream: `applyProductImages()` deletes files and schedules row removals at `src/Controller/Admin/ProductController.php:1650-1656` (`$this->removeImageFile($filename); $product->removeImage($img); $entityManager->remove($img);`) and then calls `$imageFile->move($this->uploadDir(), $filename);` at `:1670`, which throws `FileException` and is caught nowhere up the stack to the action (`:1219-1247`).

**Failure scenario.** An admin edits a product, removing an old image and uploading a new one. `move()` throws because `public/uploads/products` is unwritable or the disk is full. The request 500s and the admin concludes the save failed — but `kernel.exception` fires, `flush()` runs, and the scheduled `ProductImage` DELETEs are committed. The product is now live with rows deleted for a request the admin was told had failed, while `syncProductPricing()`/`syncProductInventory()` (which run *after* the throw point) never executed. The same flush also triggers `OrderInventoryReconciliationSubscriber::postFlush`, so an exception mid-order-edit commits the order change and adjusts inventory buckets for an operation the user was told failed.

**Fix.** Write error and mailer logs through a dedicated, separate `EntityManager` (or a raw DBAL connection) so logging can never commit application state. If a shared EM must be used, call `$this->entityManager->clear()` before `persist($log)` — but the separate-connection approach is the correct one, since a broken UnitOfWork may not be flushable at all.

### F16. Product hard-delete unlinks image files before the flush that can abort — `src/Controller/Admin/ProductController.php:1274` — **Low**

**What is wrong.** Files are destroyed outside the transaction, before the DB operation that may fail:

```php
// src/Controller/Admin/ProductController.php:1268-1283
#[Route('/product/delete/{id}', name: 'admin_product_delete', methods: ['POST'])]
public function delete(int $id, EntityManagerInterface $entityManager): JsonResponse
{
    $product = $entityManager->find(ProductCore::class, $id);
    if ($product instanceof ProductCore) {
        $name = $product->getName();
        foreach ($product->getImages() as $img) {
            if ($img instanceof ProductImage) {
                $filename = $img->getFilename();
                if ($filename !== '') { $this->removeImageFile($filename); }
            }
        }
        $entityManager->remove($product);
        $entityManager->flush();
```
`removeImageFile()` (`:1922-1928`) `@unlink`s immediately. The FK really blocks: `admin_order_line` and `estimate_line` both declare `FOREIGN KEY (product_id) REFERENCES product_core (id)` with **no** `ON DELETE` clause, and enforcement is on for every connection (`src/Doctrine/SqliteWalMiddleware.php:21` `PRAGMA foreign_keys = ON`; verified live via `dbal:run-sql "SELECT * FROM pragma_foreign_keys"` → 1). Reproduced on a `/tmp` copy of the dev DB: `DELETE FROM product_core` → `SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed`.

**Failure scenario.** A POST for a product that appears on any historical order line unlinks all its JPEGs, then `flush()` raises an unhandled `ForeignKeyConstraintViolationException`. The transaction rolls back so `product_core` and `product_image` survive — but the files are gone. The product stays live in the catalog with every image row pointing at a missing file, and the JSON endpoint returns a 500.

**Reachability caveat.** `grep -rn 'product/delete\|admin_product_delete' templates/ public/assets/js/ modules/ src/ tests/` yields only the route attribute — no template or JS ever emits this URL; the UI's removal mechanism is the soft-delete `<select name="deleted">` at `templates/admin/product/form.html.twig:202`. This requires an admin hand-crafting a POST to an unlinked endpoint. (The related claim that a spec requires retaining the inventory audit trail across deletion is *not* supported — `docs/inventory-calculation.md:1-6` says the audit trail "does not exist yet".)

**Fix.** Move the file deletions after a successful `flush()`, and wrap the whole action in `wrapInTransaction()` with a `catch (ForeignKeyConstraintViolationException)` returning HTTP 409 "This product appears on existing orders or quotes and cannot be deleted." Better still, delete the endpoint — the app uses soft-delete everywhere else.

### F15. Deleting an in-use price list 500s instead of reporting it — `src/Controller/Admin/PriceListController.php:163` — **Low**

**What is wrong.** No in-use guard before remove/flush:

```php
// src/Controller/Admin/PriceListController.php:157-167
$priceList = $entityManager->find(PriceList::class, $id);
if ($priceList instanceof PriceList) {
    $name = $priceList->getName();
    $entityManager->remove($priceList);
    $entityManager->flush();
    return new JsonResponse(['ok' => true, 'message' => sprintf('Price list "%s" was deleted successfully.', $name)]);
}
```
Two RESTRICT parents exist in the live schema — `company_fulfillment_region.price_list_id` (`CONSTRAINT FK_B3B99B1F5688DED7 ... REFERENCES price_list (id) NOT DEFERRABLE INITIALLY IMMEDIATE`, no `ON DELETE`) and `fulfillment_region.guest_price_list_id` — and both are populated in normal operation (`CompanyController.php:693`, `CompanyFulfillmentRegionController.php:160`, `AuthController.php:226` on customer registration, `ConfigController.php:1029`). `PriceList` has no inverse associations, so Doctrine emits a bare `DELETE FROM price_list`.

**Failure scenario.** An admin clicks Delete on a price list assigned to any company/region. `flush()` raises an uncaught `ForeignKeyConstraintViolationException` from the JSON endpoint (only `ErrorLogSubscriber` listens on `kernel.exception`, and it merely logs) → HTTP 500. Nothing is deleted; the grid falls back to a generic "Could not delete … Please try again." (`app.js:3023-3028`) with no indication *why*. Contrast `CategoryController.php:190-215`, which returns HTTP 409 with an "in use" message.

Note: the `ON DELETE CASCADE` on `product_pricing` is deliberate schema semantics (a list's own price rows) behind a confirm modal, not data loss.

**Fix.** Adopt the `CategoryController` pattern: count referencing `CompanyFulfillmentRegion` and `FulfillmentRegion` rows first and return HTTP 409 with the names of the companies/regions still using the list.

### F17. Admins who recorded a payment can never be deleted — `src/Entity/AdminOrderPayment.php:22` — **Low**

**What is wrong.** A unidirectional nullable ManyToOne with no `onDelete`:

```php
// src/Entity/AdminOrderPayment.php:20-23
// Nullable: customer-initiated Stripe payments have no admin who recorded them.
#[ORM\ManyToOne(targetEntity: AdminUser::class)]
#[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
private ?AdminUser $user = null;
```
Live schema: `CONSTRAINT FK_4590F221A76ED395 FOREIGN KEY (user_id) REFERENCES admin_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE` — no `ON DELETE`, and it is the only FK in the schema pointing at `admin_user`. The delete path is a bare remove with no try/catch:

```php
// src/Controller/Admin/UserController.php:522-524
$email = $user->getEmail();
$entityManager->remove($user);
$entityManager->flush();
```
Rows really do get a user: `OrderController.php:1103` `$payment->setUser($this->getUser())`, and even customer Stripe payments attach one — `src/Controller/Customer/OrderController.php:758` `->setUser($this->paymentRecordingAdminUser($entityManager))`, which picks the lowest-id Active admin (`:768-772`), permanently pinning that account too.

**Failure scenario.** A staff member leaves; an admin clicks Delete. The flush raises `ForeignKeyConstraintViolationException`; the route declares `JsonResponse` and the JS expects JSON, so the admin sees a raw 500 with no message, and the account can never be removed through the UI. (The nullable column shows the intent was "keep the payment, forget the admin".)

Not a lingering-access issue: `src/Security/AdminUserChecker.php` + `AccountStatusResolver.php:13-17` refuse login for any non-Active `AdminUser`, so the account can still be neutralised.

**Fix.** Add `onDelete: 'SET NULL'` to the JoinColumn and regenerate the constraint; wrap the delete in a try/catch returning a JSON error for any remaining constraint failure.

### F23. Carts are keyed on the session id, which login rotates — `src/Service/CartService.php:263` — **Low**

**What is wrong.**

```php
// src/Service/CartService.php:261-264
private function sessionId(): string { return $this->requestStack->getSession()->getId(); }
// :245-248
private function findCart(): ?Cart
{
    return $this->entityManager->getRepository(Cart::class)->findOneBy(['sessionId' => $this->sessionId()]);
}
```
`src/Entity/Cart.php:37-38` makes `sessionId` the unique key; the `company` column is never populated (`setCompany` at `:62` has no caller anywhere in `src/` or `modules/`), so an orphaned row carries no other identity. `config/packages/security.yaml` sets no `session_authentication_strategy`, so Symfony's default `migrate` rotates the id on every login, and `src/EventSubscriber/InactiveAccountLogoutSubscriber.php:60` calls `$session->invalidate()`. Session id reuse cannot rescue it: `NativeSessionStorage.php:87` sets `use_strict_mode => 1` by default. The class docblock claims the opposite guarantee (`CartService.php:16-19`: "a cart was lost the moment the session expired").

**Failure scenario.** A buyer adds 12 SKUs, closes the browser, returns and logs in. Symfony migrates the session, `findCart()` looks up the new id and finds nothing — the cart appears empty although the `Cart` and `CartItem` rows still exist. The `cart` table then grows without bound with unreachable rows, polluting `/admin/carts`. No cleanup command exists (`src/Command/` holds only Backfill, CreateAdmin and InventoryRecalc).

Mitigating: `src/Entity/Cart.php:12-16` documents session-scoped identity as deliberate, orphans leak no inventory (holds expire after 300s and are swept globally), and ids are unguessable so there is no cross-tenant exposure.

**Fix.** Populate `Cart::company` and the owning `CustomerUser`, and on `LoginSuccessEvent` (`src/EventSubscriber/CustomerLoginSubscriber.php` already exists) re-key or merge the pre-login cart onto the new session id. Add a scheduled command to delete carts with no items older than N days. Correct or delete the misleading docblock at `:16-19`.

### F24. `audit_log` has no index and no retention — `src/Entity/AuditLog.php:9` — **Low**

**What is wrong.** The entity declares only `#[ORM\Entity]` and `#[ORM\Table(name: 'audit_log')]` — no `#[ORM\Index]` — and the live table is the only one in the database with **zero** indexes:

```sql
CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, occurred_at DATETIME NOT NULL, actor_type VARCHAR(16) NOT NULL, actor_id INTEGER DEFAULT NULL, actor_name VARCHAR(255) NOT NULL, area VARCHAR(80) NOT NULL, entity_type VARCHAR(80) NOT NULL, entity_id INTEGER DEFAULT NULL, action VARCHAR(64) NOT NULL, summary CLOB NOT NULL, data_before CLOB DEFAULT NULL, data_after CLOB DEFAULT NULL);
```
```php
// src/Repository/AuditLogRepository.php:20-30
return $this->createQueryBuilder('a')
    ->andWhere('a.entityType = :entityType')
    ->andWhere('a.entityId = :entityId')
    ...
    ->orderBy('a.occurredAt', 'DESC')
```
No `setMaxResults`. Called unbounded on three detail pages: `CompanyController.php:250` and `:264`, `OrderController.php:407`, `EstimateController.php:171`. Write volume is high — `AuditLogSubscriber.php:29-36` excludes only seven log/config entities, so `ProductInventory`, `ProductPricing`, `CartItem`, `CustomFieldValue` and `OrderInventoryReservation` are all audited, and `:64-75` queues every insert/update/delete. No pruning command exists.

**Failure scenario.** A single import over a 10,000-row catalog writes ~10k `ProductCore` + ~10k `ProductInventory` + N pricing/custom-field audit rows in one pass (imports persist through the ORM). After a few months, every open of an order, company or estimate detail page executes a full table scan plus filesort over millions of rows, monotonically worsening, with nothing to prune it. (Current row count is 67 — this is forward-looking scalability, not a live outage.)

**Fix.** Add `#[ORM\Index(name: 'idx_audit_log_entity', columns: ['entity_type', 'entity_id', 'occurred_at'])]` to the entity plus the corresponding migration, add `setMaxResults()` to `findForEntity()` with a "show more" affordance, and ship a retention command (`app:audit-log:prune --older-than=`).

---

## Theme 6 — Admin endpoint validation and scoping

These are all behind `^/admin` + `ROLE_ADMIN` (`config/packages/security.yaml:88`) with `SameSite=lax` cookies. None is an authorization bypass; several are missing defensive checks whose only trigger is a hand-crafted request from an already-privileged actor. Reachability is stated explicitly for each.

### F13. Non-superadmin staff creation always 500s on a null dereference — `src/Controller/Admin/UserController.php:802` — **Medium**

**What is wrong.** `validateUserRequest()` dereferences `$existingUser` in a branch reachable on the create path, where it is `null`:

```php
// src/Controller/Admin/UserController.php:781-806
if (($normalizedType === 'admin' || $existingUser instanceof AdminUser) && !($existingUser instanceof CustomerUser)) {
    ...
    } elseif (
        $actorRole === self::ROLE_ADMIN
        && $existingUser->getId() === $actor->getId()      // <-- :802, null on create
        && $role === self::ROLE_SUPERADMIN
    ) {
```
Caller: `:250` `$errors = $this->validateUserRequest($request, $entityManager, null, $roleOptions, 'admin');`. With `$existingUser === null` and `$actorRole === 'Admin'`, the preceding branches all evaluate false, so PHP evaluates `:800-804`, whose first operand is true, forcing the dereference. Reproduced in isolation: `Error: Call to a member function getId() on null`. The branch is also logically dead — it requires `$role === 'Super Admin'`, which `:795-798` already caught — so an Admin-role actor hits the fatal **regardless of which role they select**.

**Failure scenario.** A staff user whose role label is 'Admin' opens `/admin/user/staff/create` and submits with either of the two roles `staffRoleOptionsForActor()` offers them (`:1013-1017`). HTTP 500; no user is created. Only Super Admin works, which is why the shipped dev DB (sole `admin_user` row is `ROLE_SUPER_ADMIN`) hides it. The other paths are safe — `staffUpdate` passes an `AdminUser`, and the customer paths skip the `:781` guard.

**Fix.** Guard the branch with `$existingUser instanceof AdminUser &&` before the `getId()` call (matching branch 2), or delete the branch outright since it is subsumed by `:795-798`. Add a test that creates a staff user as an Admin-role actor.

### F18. Order status written unvalidated — `src/Controller/Admin/OrderController.php:693` — **Low**

**What is wrong.** The raw request string is written straight to the entity, unlike the estimate sibling:

```php
// src/Controller/Admin/OrderController.php:689-696
$status = $request->request->get('status');
$notify = $request->request->getBoolean('notify_client');
$oldStatus = $order->getStatus();

$order->setStatus($status);
if ($order->getInvoiceDate() === null && $this->isApprovedStatus($status)) {
    $order->setInvoiceDate(new \DateTimeImmutable('today'));
}
```
The two consumers disagree about unknown values — anything unrecognised counts as *approved*:
```php
// :744-749
return $normalized !== '' && !in_array($normalized, ['draft', 'cancelled', 'canceled', 'pending', 'awaiting payment'], true);
```
but holds *no* inventory:
```php
// src/Service/Inventory/OrderInventoryBucketResolver.php:36-43
return match (true) {
    in_array($normalized, self::PENDING_STATUSES, true) => OrderInventoryReservation::BUCKET_PENDING,
    in_array($normalized, self::APPROVED_STATUSES, true) => OrderInventoryReservation::BUCKET_APPROVED,
    default => null,
};
```
An `App\Enum\SalesOrderStatus` exists, and `EstimateController.php:433-436` validates with `tryFrom()` and 400s.

**Failure scenario.** (a) Omitting `status` entirely makes `$status` null and `setStatus(string $status)` (`src/Entity/AdminOrder.php:64`) raises a TypeError → HTTP 500 — trivially reachable. (b) A POST with `status=Shipped` stamps an invoice date as if approved, while `bucketForStatus('Shipped')` returns null, so `reconcile()` — invoked automatically from `postFlush` — diffs every reservation to 0 and releases the stock. The order is invoiced holding zero inventory and is no longer matched by `isOrderLockedForEditing()` (`:69-74`) or the recalc command's status pre-filter, so it is never repaired.

**Reachability:** no in-app caller can send a non-enum status — both JS callers post either the modal `<select>` value (Pending/Processing/Completed/Cancelled/Draft) or a template-fixed `data-status`. The corruption path needs a scripted admin request; the 500 does not.

**Fix.** `$status = SalesOrderStatus::tryFrom((string) $request->request->get('status', ''));` with a 400 on null, mirroring `EstimateController.php:433-436`.

### F19. Order payment loaded by request id without scoping to the order — `src/Controller/Admin/OrderController.php:1090` — **Low**

**What is wrong.** Only *new* payments are bound to the URL's order:

```php
// src/Controller/Admin/OrderController.php:1074-1113
$paymentId = $request->request->get('payment_id');
$isNew = true;
if ($paymentId) {
    $payment = $entityManager->find(\App\Entity\AdminOrderPayment::class, $paymentId);
    if ($payment) { $isNew = false; }
}
if (!isset($payment) || !$payment) {
    $payment = new \App\Entity\AdminOrderPayment();
    $payment->setOrder($order);      // only NEW payments get bound to $order
    $payment->setUser($this->getUser());
}
$payment->setReceivedAt(...)->setMethod(...)->setAmount($amount)->setComment(...);
```
The sibling delete action *does* check: `:2192-2193` `if ($payment && $payment->getOrder()->getId() === $id) {`.

**Failure scenario.** A POST to `/admin/order/payments/200` with `payment_id=7` (belonging to order 100, company A) rewrites company A's $5,000 payment to $1.00 while the audit entry is written to order 200's log via `logOrderAction($order, ...)` at `:1123`. Order 100's `effectivePaymentStatus()` (`:358-365`, which recomputes from `SUM(payments)`) silently flips from Paid to unpaid, with the only record under a different company's order.

**Reachability:** the form posts to its own order (`templates/admin/order/payments.html.twig:68`), the hidden field is populated only from that order's own rows, and every successful POST redirects to a fresh form with `payment_id` empty (`:1134`) — so stale-field and back-button replays can only ever hit the same order. Only a scripted POST reaches the cross-order case.

**Fix.** Copy the delete action's check: after `find()`, require `$payment->getOrder()?->getId() === $order->getId()` and otherwise treat it as a new payment (or 400).

### F20. Address attached outside the same-company check — `src/Controller/Admin/OrderController.php:514` — **Low**

**What is wrong.** The ownership check gates the inline field edits but not the assignment:

```php
// src/Controller/Admin/OrderController.php:506-515
$billingAddressId = (int) $request->request->get('billing_address_id');
$order->setBillingAddress(null);
if ($billingAddressId > 0) {
    $billingAddress = $entityManager->find(CompanyAddress::class, $billingAddressId);
    if ($billingAddress instanceof CompanyAddress) {
        if ($billingAddress->getCompany()->getId() === $company->getId()) {
            $this->applyAddressEditsFromRequest($billingAddress, 'billing', $request);
        }
        $order->setBillingAddress($billingAddress);   // <-- outside the check
    }
} else { ... }
```
Identical for shipping at `:521-530`. The create path (`:1652-1666`) has **no** ownership check at all. Only an FK is stored (`src/Entity/AbstractSalesDocument.php:53`), and `getEffectiveBillingAddress()` (`:178-180`) is what renders on customer-visible invoices (`templates/admin/order/invoice.html.twig`, `src/Controller/Customer/OrderController.php:1023`).

**Failure scenario.** A POST attaching company B's address id to company A's order permanently prints company B's street address, phone and email on company A's invoice — a cross-company isolation break in a customer-visible document.

**Reachability:** I could not find a non-crafted trigger. There is no AJAX company switcher; the address `<select>` is server-rendered from `addressBookRows($company)` for the order's own company (`templates/admin/order/form.html.twig:288-307`; `OrderController.php:2118-2129`), company selection on create is a separate form plus full reload, cloning preserves the company (`:867, :883-888`), and a back-button render of another order posts to that order's own URL. This is a missing defensive check, not a live exploit — but the invariant is central enough to the product to be worth enforcing.

**Fix.** Move `$order->setBillingAddress($billingAddress)` inside the same-company branch (and reject/ignore otherwise), and add the equivalent check to the create path at `:1652-1666`. Consider snapshotting the address onto the document at order time rather than storing an FK, so later address edits cannot rewrite historical invoices either.

### F21. Company user created with no role, on a company taken from the request body — `src/Controller/Admin/CompanyController.php:463` — **Low**

**What is wrong.** Two defects in one action (`/admin/company/{id}/user/create`):

```php
// src/Controller/Admin/CompanyController.php:452-485
$company = $entityManager->find(Company::class, $id);
...
if ($request->isMethod('POST')) {
    $user = new CustomerUser();
    $this->applyUserRequest($user, $request, $entityManager);
    ...
    $user->setPassword(password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));
    $entityManager->persist($user);
    $entityManager->flush();
    $this->addFlash('success', sprintf('User "%s" was added to "%s".', $user->getEmail(), $company->getName()));
```
No `setRoles()`/`applySelectedRole()` anywhere in the action — although the form *does* submit a `role` select (`templates/admin/user/form.html.twig`, posting back to this same URL), so an explicit "Owner" choice is silently discarded. Contrast `UserController.php:298-300`, which calls `$this->applySelectedRole($user, $role);`. And the company comes from the body, not the route:

```php
// src/Controller/Admin/AbstractAdminController.php:306-312
$companyId = trim((string) $request->request->get('company', ''));
$company = ($companyId !== '' && ctype_digit($companyId) && (int) $companyId > 0)
    ? $entityManager->find(Company::class, (int) $companyId)
    : null;
$user->setCompany($company);
```

**Failure scenario.** An admin adds the first user to a new company and selects Owner. The row is stored with `roles = []`; `CustomerUser::getRoles()` (`:88-96`) returns only `['ROLE_CUSTOMER']`, so the company has **no owner**: the account shows as "Company Staff" everywhere, and the owner-deletion guard at `src/Controller/Customer/CompanyUserController.php:411` (`in_array('ROLE_COMPANY_OWNER', $user->getRoles(), true)`) no longer shields it — any other user in that company, staff included, can delete it. Separately, if the posted `company` field is absent or non-numeric, the account is created orphaned (`company_id` is `nullable: true, onDelete: 'SET NULL'`, so no constraint rescues it) while the flash still says it was added to the URL's company. That the reactivate path auto-promotes (`:435-438` `if (!$hasOwner) { $user->setRoles(['ROLE_COMPANY_OWNER']); }`) and `CustomerImportService.php:100` does the same confirms the intended invariant.

**Fix.** Call `$this->applySelectedRole($user, $role)` in this action as `UserController::customerCreate` does, and set the company from the `{id}` route parameter (`$user->setCompany($company)` after `applyUserRequest`), ignoring the body value. Add a server-side check that a company always retains at least one `ROLE_COMPANY_OWNER`.

### F22. Estimate status transitions unvalidated, creating an unrecoverable state — `src/Controller/Admin/EstimateController.php:433` — **Low**

**What is wrong.** Any enum case can be written, including the terminal one:

```php
// src/Controller/Admin/EstimateController.php:429-441
if ($estimate->getStatus() === EstimateStatus::Accepted) {
    return $this->json(['success' => false, 'message' => 'This estimate has already been converted to an order.'], Response::HTTP_FORBIDDEN);
}
$status = EstimateStatus::tryFrom((string) $request->request->get('status', ''));
if ($status === null) { return $this->json([...], Response::HTTP_BAD_REQUEST); }
$oldStatus = $estimate->getStatus();
$estimate->setStatus($status);
```
`Accepted` is otherwise only ever set together with the order it produced (`src/Service/EstimateConversionService.php:77-79` sets status and `setConvertedOrder($order)` together), and it is a hard lock elsewhere (`:328-331`).

**Failure scenario.** A POST with `status=Accepted` sets the terminal state with `convertedOrder` NULL and no `AdminOrder` row. The estimate is then unrecoverable: `edit()` bounces with "This estimate is locked", `updateStatus()` bounces with "already been converted", the customer routes require `Priced`/`Submitted`, and there is no admin estimate delete route (`debug:router`). Only a manual `UPDATE` fixes it.

**Reachability:** the UI's only caller hardcodes `$.post(url, { status: 'Rejected' })` (`public/assets/js/app.js:4791-4801`), so this requires an admin deliberately hand-crafting a request against a record they already control. The missing-CSRF sub-claim is not a differentiator — the sibling `OrderController::updateStatus` has none either, and cookies are `SameSite=lax`.

**Fix.** Add an allowed-transition table and reject `Accepted` from this endpoint entirely; `Accepted` should only ever be reachable through `EstimateConversionService`, which sets it atomically with `convertedOrder`.

---

## Theme 7 — Performance and scalability

### F11. N+1 queries on the two most-used listing paths — `src/Controller/Admin/ProductController.php:1874` and `src/Controller/Customer/AbstractCustomerController.php:282` — **Medium**

**What is wrong.** Both the admin product grid and the storefront catalog build each row inside a loop, issuing per-row queries that the codebase already knows how to batch.

Admin (`productToRow`, called per row from `details()` at `:246-249` and from `productToPriceRow` at `:1005`):
```php
// src/Controller/Admin/ProductController.php:1307-1348
$inventory = $this->inventoryMap($product, $entityManager);
$privateCompanyNames = [];
foreach ($product->getPrivateCompanies() as $company) {
...
    'customFields' => $this->customFieldRenderer->renderListingFragment($product),
```
```php
// :1871-1874 — one SELECT per product
$rows = $entityManager->getRepository(ProductInventory::class)->findBy(['product' => $product]);
```
```php
// src/Service/CustomFieldRenderer.php:87-101 — one SELECT per product per definition
foreach ($this->definitionRepo->findByObjectType(CustomFieldDefinition::OBJECT_TYPE_PRODUCT) as $definition) {
    ...
    $value = $this->valueRepo->getValue($definition, $objectId);
```
The dev DB has **16** product custom-field definitions with `visible_on_listing = 1`, so a default `limit=100` page issues roughly `100 × (1 inventory + 1 privateCompanies + 1 definitions) + 100 × 16 values ≈ 1,900` round trips.

Customer (`customerProductRow`, 24 rows per page — `CatalogController.php:20 PER_PAGE = 24`):
```php
// src/Controller/Customer/AbstractCustomerController.php:271-277
$products = $qb->getQuery()->getResult();
foreach ($products as $product) {
    if (!$product instanceof ProductCore) { continue; }
    $rows[] = $this->customerProductRow($entityManager, $product, $region);
}
```
Per row: `currentCompanyForPricing()` (`:806-814`, an unconditional DQL each call), `activeCompanyFulfillmentRegions()` (`:604-607`, `findBy`), a `ProductPricing` `findOneBy`, a `ProductInventory` `findBy` (`:286`), and a lazy `product_image` SELECT (the catalog QB fetch-joins only `category`). When no explicit rule matches, `:581-584` repeats the company + price-list lookups a second time for the same row. ~5–7 extra queries × 24 ≈ 120–170 round trips per page, repeated on every search XHR and category click. Doctrine's identity map does not suppress repeat `findBy`/`findOneBy` execution, and no result cache applies (`doctrine.yaml` wires a pool only under `when@prod`, and none of these calls use `enableResultCache()`).

Compounding: `product_core` has only `uniq_product_core_sku` and `IDX_product_core_category` — nothing on the `(visible, deleted, status)` predicate or on `name` used for `ORDER BY` — so the driving query scans and filesorts.

**Failure scenario.** Under the shared SQLite file (WAL, single writer), the two most-used admin grids take seconds per page and serialize badly against concurrent checkout writes; paging a large catalog at 100 rows/page is unusable, and every storefront search keystroke that hits the XHR endpoint pays the full N+1.

**Fix.** Batch all three per-row lookups: use the `IN (:productIds)` pattern the same file already applies to pricing (`:360-385`, `:410-433`) and that `InventoryController::buildRows` uses for inventory (`src/Controller/Admin/InventoryController.php:137-142`); switch `CustomFieldRenderer` to the existing but unused `CustomFieldValueRepository::getValuesForObjects()` (`:76-94`) and memoise `findByObjectType()` per request. On the customer side, memoise `currentCompanyForPricing()`/`priceListForCompanyRegion()` per request, batch the pricing and inventory lookups, and add `addSelect('images')` with a fetch join. Add indexes on `product_core(visible, deleted, status)` and `product_core(name)`.

### F12. Sorting the pricing matrix by a price-list column hydrates the whole catalog — `src/Controller/Admin/ProductController.php:353` — **Medium**

**What is wrong.** When the sort key is a dynamic price-list column, SQL pagination is dropped entirely:

```php
// src/Controller/Admin/ProductController.php:334-356
$dynamicPricingSort = $this->pricingMatrixDynamicSortMeta($sort);
...
if ($dynamicPricingSort === null) {
    $productsQb->orderBy($orderExpr, $dir)->addOrderBy('p.id', 'ASC')
        ->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
} else {
    $productsQb->orderBy('p.id', 'ASC');
}
$products = $productsQb->getQuery()->getResult();
```
```php
// :481-490
if (!preg_match('/^pl_(type|value|effective)_(\d+)$/', $sort, $matches)) { return null; }
```
The only filter on `$qb` is `->where('p.deleted = false')` plus optional id/sku/name/price filters, so an unfiltered grid means the entire catalog. Every hydrated product then goes through `productToPriceRow` → `productToRow` and its per-row queries (F11). The page slice is taken in PHP afterwards:
```php
// :444-448
$rows = $this->sortPricingMatrixRows($rows, ...);
...
$rows = array_slice($rows, ($page - 1) * $limit, $limit);
```
Reachable straight from the UI, not just a crafted URL: `templates/admin/product/prices.html.twig:137` emits `path('admin_product_price_index', {sort: 'pl_effective_' ~ priceList.id, ...})`, and `:89`/`:114` emit the `pl_type_`/`pl_value_` links.

**Failure scenario.** On a large wholesale catalog (the repo ships bulk importers, so six-figure SKU counts are the design target), clicking the "Effective" header of a price-list column hydrates every `ProductCore` plus thousands of per-product queries before discarding all but 100 rows. `memory_limit = 128M` / `max_execution_time = 30` in the FPM config make a 500 the expected outcome, and since the sort link is a plain GET the admin can trivially repeat it and pin the pool.

**Fix.** Push the dynamic sort into SQL — join `product_pricing` for the selected price list and order by its columns (a `LEFT JOIN … WITH pp.priceList = :plId` plus `ORDER BY pp.price`), keeping `setFirstResult`/`setMaxResults`. If an in-PHP effective-price computation is unavoidable, cap the hydrated set and tell the operator the sort is limited.

### F25. Bulk price-rule apply commits per batch with no transaction or time budget — `src/Controller/Admin/ProductController.php:905` — **Low**

**What is wrong.** The whole catalog is walked in one synchronous HTTP request, committing per 250-product batch:

```php
// src/Controller/Admin/ProductController.php:704-736
$page = 1;
$batchSize = 250;
// Apply to ALL products in the dataset (deleted=false), regardless of current UI pagination.
while (true) {
    $batchQb = clone $baseQb;
    $rows = $batchQb->setFirstResult(($page - 1) * $batchSize)->setMaxResults($batchSize)
```
Three independent exit points each `flush()` + `clear()` (`:803-806`, `:817-820` — the `$clearing` branch that `remove()`s existing rows — and `:905-909`), with no `beginTransaction`, no iteration cap and no time budget anywhere in `:666-925`.

**Failure scenario.** Applying "Discount% = 15" across a large catalog trips `max_execution_time = 30` at batch 97 of 240. Batches 1–96 are committed; the rest are not. The response never arrives, so the admin has no idea how far it got, and customers on that price list see a mix of new prices, old prices and base-price fallback until the operator notices.

Mitigating: for the only field the all-pages button actually sends (`field='both'`, `prices.html.twig:851-853`), the write is idempotent (`:898-899` set `ruleType`/`ruleValue` unconditionally), so re-clicking repairs a partial run. This is a robustness/UX gap, not unrecoverable corruption.

**Fix.** Move the operation to a background command (the repo already has a `Command/` directory and cron-driven jobs) with a progress token like the import path uses, or at minimum add `set_time_limit(0)`, a persisted progress cursor, and a completion/resume UI so a partial run is visible and resumable.

### F26. Import flushes twice per row with no batching or transaction — `src/Service/ProductImport/ProductImportService.php:163` — **Low**

**What is wrong.**

```php
// src/Service/ProductImport/ProductImportService.php:163-173 (inside foreach ($file as $row))
$entityManager->persist($product);
$entityManager->flush();

if ($isNew) { $this->applyFeeImportDefaults($product, $entityManager); }

$this->applyInventory(...);
$this->applyPricing(...);

$entityManager->flush();
```
`grep` over the 1,070-line service and its controller returns zero hits for `clear()`, `beginTransaction`, `wrapInTransaction`, `set_time_limit` or `ini_set`. The file is parsed twice (`:45` `$totalRows = $this->countDataRows($csv);` → full `SplFileObject` pass at `:967-994`), `inactivateMissing()` hydrates the entire catalog at `:737`, and the run is synchronous inside the HTTP request (`src/Controller/Admin/ProductImportController.php:16-33`). Note the rim path *does* call `set_time_limit(0)` (`RimApiImportService.php:51`); the admin CSV path does not.

**Failure scenario.** Nothing is ever detached, so the UnitOfWork grows monotonically and every flush walks all managed entities in `computeChangeSets`. If the request dies mid-run, each row was already committed on its own flush with no surrounding transaction, so the catalog is left half-updated with no row count reported and no resume point.

**Honest caveat.** The originally claimed 30,000-row scenario is not reachable: `upload_max_filesize = 2M` caps a realistic multi-column product CSV at roughly 7–10k rows. The mechanism (non-atomic partial commits, unbounded UnitOfWork) is real; the concrete timeout at the stated scale is unproven.

**Fix.** Flush and `clear()` every N rows (e.g. 100) with re-fetched references, call `set_time_limit(0)` in the controller as the rim path does, and reuse the already-computed row count instead of a second full parse. For atomicity, either wrap the run in a transaction or record a resumable cursor so a died import can be continued rather than restarted.

---

## Architecture observations

These are structural patterns behind multiple findings, not separate defects.

**1. There are no transaction boundaries anywhere.** `grep -rn "beginTransaction\|wrapInTransaction\|LockMode\|ORM\\Version" src/ modules/ --include=*.php` returns **zero** matches across the entire application. Every write relies on Doctrine's implicit per-flush transaction. Combined with (a) side effects registered on `postFlush` — which Doctrine dispatches *after* the commit (`UnitOfWork.php:434` commit, `:472` dispatch) — and (b) an exception listener that flushes the shared EntityManager, the request is not a unit of work in any meaningful sense. F8, F10, F16, F17 and F25 are all direct consequences.

**2. Correctness is delegated to compensating cron jobs, and ownership of one table is split across three bundles.** `ProductInventory` has four counters; Pending is repaired by core's `app:inventory-recalc`, Approved by `Number1ProductImportBundle`'s `app:product-import:approved-recalc`, and Cart Hold by `CartHoldBundle` — with `InventoryRecalcCommand.php:31-35` explicitly documenting that core "deliberately does NOT touch Cart Hold or Approved". This is a deliberate, documented design that mostly works (it is why F8's drift self-heals within the hour), but it has no owner of the *whole* invariant, which is exactly why F2's stamped `syncedQuantity` baseline defeats both repair paths simultaneously — every recalc subtracts the corrupted baseline it was supposed to detect.

**3. "Compute an order total" is implemented twice.** The customer checkout path (`CheckoutController::checkoutTotals`) and the admin/re-payment path (`OrderController::orderPaymentTotals`) independently derive subtotal, fees, tax and total from the same order. F5 (coupon lost on re-payment) and F6 (fee context differs between render and persist) are both drift between these implementations; F6 is drift *within a single file*, between two `FeeContext` constructions 400 lines apart. Extracting one `OrderTotalsCalculator` consumed by every render, persist and charge path would close this class of bug rather than the two instances found.

**4. Request fields are read ad hoc, with "absent" coalescing into destructive defaults.** There is no form/DTO layer; controllers read `$request->request->get(...)` and coalesce. F1 is the extreme case — a form field removal in commit `daa17e2` silently turned "not submitted" into "set price to 0.00 and delete every other price list" — but the same shape produces F18 (`get('status')` → null → TypeError), F21 (company from the body rather than the route), and F9 (an omitted import option defaulting to a destructive true). Symfony Forms or explicit request DTOs with `has()` checks would make field removal a compile-time-visible change rather than a silent behaviour change.

**5. Two cart implementations coexist, one of them dead.** `/cart` moved to a server-backed `CartService` (the no-op `updateCartBadge()` at `app.js:1298-1302` documents the migration), but the localStorage `wcCart` machinery — `readCart`, `writeCart`, `addToCart`, `renderCartPage` — was left in place, targeting a `.js-cart-rows` container that no longer exists in any template. F4 (Reorder writes to the dead cart) and F3 (the submit button's enabled state derived from the dead cart) are both consequences of leaving it in the tree. Removing the dead path is a prerequisite for trusting the checkout JS.

---

## Checked and clean

The following were examined during this review — in most cases as attempted refutations of a finding — and found sound:

- **Authorization and session boundaries.** `config/packages/security.yaml:88-95` gates `^/admin` on `ROLE_ADMIN` and `^/company-users` on `ROLE_CUSTOMER`; cookies are `SameSite=lax`, which blocks the cross-site form POSTs that would otherwise turn several missing-CSRF endpoints into real vulnerabilities. `AdminUserChecker` + `AccountStatusResolver.php:13-17` correctly refuse login for any non-Active account, so a de-provisioned account cannot be used even where deletion is broken (F17).
- **Cross-company address rendering.** The admin order address book is server-rendered strictly from `addressBookRows($company)` for the order's own company (`templates/admin/order/form.html.twig:288-307`; `OrderController.php:2118-2129`), company selection on create is a separate form plus full page reload, and `cloneOrder` preserves the company (`:867, :883-888`). There is no AJAX company switcher — the tenancy invariant holds through the UI (F20 is a missing server-side check with no UI trigger).
- **Ownership checks that *are* present.** `OrderController::deletePayment` (`:2192-2193`) correctly scopes the payment to the URL's order — it is the model F19 should follow. `CategoryController::delete` (`:190-215`) correctly returns HTTP 409 when referenced — the model F15 should follow. `UserController::customerCreate` (`:298-300`) correctly calls `applySelectedRole()` — the model F21 should follow.
- **Estimate conversion.** `EstimateConversionService.php:77-79` sets `Accepted` and `setConvertedOrder($order)` together in one operation, so the terminal state is consistent whenever it is reached through the intended path.
- **`CheckoutController`'s own guards.** The `priceResolved` filter at `:116-118` correctly excludes unpriced rows from the tax base (its absence in the Stripe bundle is F14), and `checkoutEffectiveShipping()` (`:1077-1085`, used at `:171` and `:588`) correctly zeroes tax on waived shipping — the right pattern, just not applied to line subtotals.
- **`CartHoldService::recomputeCache()`** (`modules/CartHoldBundle/src/Service/CartHoldService.php:194-207`) recomputes from source of truth via `sumHeldQuantity()` rather than applying a delta, so it converges under concurrency and is *not* subject to the lost-update problem in F8. Expired holds are swept globally by `releaseExpired()`, so orphaned carts leak no inventory.
- **Bundle activation and module wiring.** `config/bundles.php:18-27` auto-registers every `modules/*` bundle by glob; `BundleStatusRepository::isActive` (`:26-31`) treats a missing status row as Active; `FeeCalculatorResolver` (`modules/FeeBundle/src/Fee/FeeCalculatorResolver.php:52-55`) and `PaymentMethodResolver` (`:53-57`) both consult it consistently. The extension mechanism itself behaves as documented — the defects found in modules are in their callers, not in the discovery/tagging machinery.
- **SQLite operational setup.** `SqliteWalMiddleware` is correctly registered (`debug:container --tag=doctrine.middleware`) and does enable WAL and `PRAGMA foreign_keys = ON` on every connection — verified live via `dbal:run-sql "SELECT * FROM pragma_foreign_keys"` → 1. Referential integrity is genuinely enforced, which is what makes several findings above surface as 500s rather than silent corruption.
- **Batched query patterns already in the codebase.** `ProductController`'s chunked `IN (:productIds)` pricing queries (`:360-385`, `:410-433`), `InventoryController::buildRows` (`:137-142`), and the unused-but-correct `CustomFieldValueRepository::getValuesForObjects()` (`:76-94`) show the right approach exists — the N+1 sites in F11 are inconsistencies, not constraints.
- **Compensating recalc commands.** `InventoryRecalcCommand` and `ApprovedInventoryRecalcCommand` do what their docblocks claim: recompute from source of truth (order statuses/lines and reservations respectively), overwrite drift, and log/email discrepancies. They are the reason F8's window is bounded — and their correct behaviour is precisely what F2 subverts.