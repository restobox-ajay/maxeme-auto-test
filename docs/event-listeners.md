# Event listeners

Every listener in the app, what triggers it, what it reads, and what it does. Two mechanisms are used:

- **`EventSubscriberInterface`** (Symfony event dispatcher) — picked up automatically by `_defaults: autoconfigure: true` in `config/services.yaml`. No explicit service tag needed.
- **`#[AsDoctrineListener]`** (Doctrine ORM events) — one listener (`AuditLogSubscriber`) uses this instead, since it needs Doctrine's `onFlush`/`postFlush` lifecycle rather than an HTTP kernel event.

| Listener | Event(s) | Purpose |
|---|---|---|
| [AdminHostSubscriber](#adminhostsubscriber) | `kernel.request` (priority 100) | Splits admin vs customer traffic by hostname |
| [AuditLogSubscriber](#auditlogsubscriber) | Doctrine `onFlush`, `postFlush` | Records every entity create/update/delete to the audit log |
| [CartHoldSweepSubscriber](#cartholdsweepsubscriber) | `kernel.request` (default priority) | Releases expired cart holds; evicts stale items from the session cart |
| [CustomerLoginSubscriber](#customerloginsubscriber) | `LoginSuccessEvent` | Backfills a customer's company link and login timestamp |
| [ErrorLogSubscriber](#errorlogsubscriber) | `kernel.exception` | Persists uncaught exceptions to `ErrorLog` |
| [GuestCatalogAccessSubscriber](#guestcatalogaccesssubscriber) | `kernel.request` (default priority) | Blocks the product catalog from guests when the setting says so |
| [InactiveAccountLogoutSubscriber](#inactiveaccountlogoutsubscriber) | `kernel.request` (priority -10) | Force-logs-out a user whose account has since been blocked/deactivated |
| [InventoryReconciliationSubscriber](#inventoryreconciliationsubscriber) | Doctrine `onFlush`, `postFlush` | Keeps ProductInventory's hold buckets in sync with every SalesOrder and Invoice change |
| [InvoicePaymentStatusSubscriber](#invoicepaymentstatussubscriber) | Doctrine `onFlush`, `postFlush` | Re-derives each invoice's payment status from its payment rows |
| [InvoiceShippingStatusSubscriber](#invoiceshippingstatussubscriber) | Doctrine `onFlush`, `postFlush` | Re-derives each invoice's shipping status from whatever knows what has shipped |
| [MailerLogSubscriber](#mailerlogsubscriber) | `SentMessageEvent`, `FailedMessageEvent` | Persists every outbound email attempt to `EmailLog` |
| [SalesOrderDerivedStatusSubscriber](#salesorderderivedstatussubscriber) | Doctrine `onFlush`, `postFlush` | Re-derives each sales order's status from its invoice set |
| [WarrantyMonthsFieldSubscriber](#warrantymonthsfieldsubscriber) | `kernel.request` (default priority) | Example bundle listener — registers a custom field definition |

---

### AdminHostSubscriber

`src/EventSubscriber/AdminHostSubscriber.php`

**Event:** `KernelEvents::REQUEST`, priority `100` (runs early, before controllers resolve).
**Input:** `RequestEvent` (wraps the current `Request`).
**Output:** none returned — either calls `$event->setResponse(RedirectResponse)`, throws `NotFoundHttpException`, or does nothing (falls through to the normal controller).

Enforces the dual-firewall host split described in the top-level `CLAUDE.md`:
- On the admin host (`$adminHost`, or any host starting with `admin.` as a defensive fallback) hitting `/` or `''`, redirects to `/admin`.
- On any *non*-admin host, a request to `/admin` or `/admin/...` gets a 404 instead of reaching the admin firewall — admin routes are invisible from the customer domain, not just unauthenticated.

Skips sub-requests (`!$event->isMainRequest()`).

### CartHoldSweepSubscriber

`modules/CartHoldBundle/src/EventSubscriber/CartHoldSweepSubscriber.php`

**Event:** `KernelEvents::REQUEST`, default priority (must run after the security firewall's own listener, same reasoning as `GuestCatalogAccessSubscriber`).
**Input:** `RequestEvent`.
**Output:** none returned — releases expired `CartHold` rows and evicts stale items from the session cart; may add a `warning` flash message.

No-ops entirely if `CartHoldBundle` is Inactive (Bundle Management) or the request's route isn't in its gated list. Gated routes: customer catalog listing/detail, cart (all actions), checkout (all actions); admin product detail/pricing/inventory-create/inventory-update pages and the dedicated Inventory page. On every gated route, calls `CartHoldService::releaseExpired()` — a *global* sweep (any session's expired rows, not just the current visitor's), since there's no fast/frequent cron for this. On customer routes only, also calls `CartHoldService::reconcileExpiredForSession()`, which evicts any cart SKU with no matching (unexpired) hold row and flashes a notice if anything was evicted.

### AuditLogSubscriber

`src/EventSubscriber/AuditLogSubscriber.php`

**Events:** Doctrine `Events::onFlush` and `Events::postFlush`, via `#[AsDoctrineListener]` attributes (not `EventSubscriberInterface` — this is an ORM-level listener, not an HTTP kernel one).
**Input:** `OnFlushEventArgs` / `PostFlushEventArgs` (Doctrine gives access to the `UnitOfWork`).
**Output:** none returned — queues audit entries via `AuditLogger::queueEntityChange()` during `onFlush`, then writes them via `AuditLogger::flushQueued()` during `postFlush` (deferred so audit-log inserts don't themselves get caught in the same flush's change-tracking).

For every scheduled insertion/update/deletion in the current flush:
- Skips entities in `EXCLUDED` (`AuditLog`, `ErrorLog`, `EmailLog`, `SalesOrderLog`, `AppSetting` — the last because it stores secrets like Stripe keys under a generic `settingValue` column that field-name redaction can't catch).
- Redacts any field whose name contains `password`, `secret`, or `token` (case-insensitive substring match) to `***REDACTED***`.
- Resolves a human-readable label for the entity by trying `getName()`, `getSlug()`, `getSource()`, `getSku()`, `getOrderNumber()`, `getEmail()`, `getLabel()`, `getTitle()` in that order against the *live* entity (not the changeset, since the identifying field often isn't the one that changed).
- Normalizes values for storage: dates → `Y-m-d H:i:s` strings, enums → their value/name, Doctrine collections → `"N item(s)"` (using `count()`, not iterating — avoids waking a lazy collection), other objects → `ShortName#id`.

This is the mechanism that populates whatever admin screen shows entity change history — check `AuditLogger`/`AuditLog` for how queued entries are read back.

### CustomerLoginSubscriber

`src/EventSubscriber/CustomerLoginSubscriber.php`

**Event:** `Symfony\Component\Security\Http\Event\LoginSuccessEvent::class`.
**Input:** `LoginSuccessEvent` (`->getUser()` returns the authenticated `UserInterface`).
**Output:** none returned — mutates and flushes the `CustomerUser` via `EntityManagerInterface`.

Runs only for `CustomerUser` (ignores admin logins). If the user isn't yet linked to a `Company`, looks one up by matching the user's email (case-insensitive) against `Company::primaryEmail` where `status = 'Active'`, and links it if found. Always stamps `setLastLoginAt(now)`, then flushes.

### ErrorLogSubscriber

`src/EventSubscriber/ErrorLogSubscriber.php`

**Event:** `KernelEvents::EXCEPTION`.
**Input:** `ExceptionEvent` (`->getThrowable()`, `->getRequest()`).
**Output:** none returned — persists an `ErrorLog` row. Wrapped in try/catch so a logging failure can't cascade into a second unhandled exception.

Skips sub-requests. Builds a JSON payload (`method`, `path`, `route`, `ip`, exception class, message, file, line, and the first 20,000 chars of the stack trace) and stores it as `ErrorLog::message`, with `area` set to the matched route name (or `"METHOD /path"` if unrouted) and `level` fixed to `'error'`.

### GuestCatalogAccessSubscriber

`src/EventSubscriber/GuestCatalogAccessSubscriber.php`

**Event:** `KernelEvents::REQUEST`, default priority (must run after the security firewall's own listener so `Security::getUser()` is reliable).
**Input:** `RequestEvent`.
**Output:** none returned — either `$event->setResponse(RedirectResponse)` or falls through.

Gates only the `customer_catalog` and `customer_product_detail` routes. Logged-in `CustomerUser`s always pass. For guests, checks the `guest_catalog_visible` `AppSetting` (default `'Yes'`); if it's `'No'`, redirects to `guest_catalog_default_url` (another `AppSetting`) or, if that's blank, to `customer_login`.

Documented as intentionally coarser than and independent from the per-`FulfillmentRegion` `guestVisible`/`guestPriceList` rules elsewhere in the app (see region-aware pricing work) — this is a blanket catalog on/off switch, not a per-region one.

### InactiveAccountLogoutSubscriber

`src/EventSubscriber/InactiveAccountLogoutSubscriber.php`

**Event:** `KernelEvents::REQUEST`, priority `-10` (runs late).
**Input:** `RequestEvent`.
**Output:** none returned — clears the security token, invalidates the session, expires remember-me cookies, and calls `$event->setResponse(...)`.

Skips profiler/asset/build paths (`/_profiler`, `/_wdt`, `/assets`, `/build`). For any authenticated user (admin or customer), asks `AccountStatusResolver::getBlockMessage($user)` whether the account should be blocked (e.g. deactivated, suspended); if a message comes back, logs the user out mid-request and responds with either a `JsonResponse` (403, `{ok: false, message, redirect}`) for XHR/`Accept: application/json` requests, or a `RedirectResponse` to `admin_login`/`customer_login` otherwise. Expires both `REMEMBERME` and `remember_me` cookies on the appropriate path (`/admin` vs `/`) for the user's firewall.

### MailerLogSubscriber

`src/EventSubscriber/MailerLogSubscriber.php`

**Events:** `SentMessageEvent::class`, `FailedMessageEvent::class`.
**Input:** `SentMessageEvent` / `FailedMessageEvent` (message accessors differ slightly — see below).
**Output:** none returned — persists an `EmailLog` row per send attempt. Wrapped in try/catch so logging can never block actual mail delivery.

Only handles messages that are `Symfony\Component\Mime\Email` instances (ignores other `RawMessage` types). `onSent` reads `$event->getMessage()->getOriginalMessage()`; `onFailed` reads `$event->getMessage()` directly. Stores: `recipient` (comma-joined `To` addresses, or `'(none)'`), `templateCode` (the email `Subject`, or `'Email'` if blank — not an actual template identifier), `status` (`'Sent'` / `'Failed'`), and `body` (HTML body, falling back to text body).

### InventoryReconciliationSubscriber

`src/EventSubscriber/InventoryReconciliationSubscriber.php`

**Events:** Doctrine `Events::onFlush` and `Events::postFlush`, via `#[AsDoctrineListener]` (an ORM-level listener, not an HTTP kernel one — the same special case `AuditLogSubscriber` already is, used here for the same reason: reconciliation must run for every current and future code path that touches an order or an invoice, not just ones a developer remembers to wire up manually).
**Input:** `OnFlushEventArgs` / `PostFlushEventArgs`.
**Output:** none returned — during `onFlush`, collects every `SalesOrder` and `Invoice` scheduled for insert/update/delete, plus the parent document of every `SalesOrderLine`/`InvoiceLine`, into two in-memory sets; during `postFlush`, calls `App\Service\Inventory\InventoryReservationReconciler::reconcile()` for each queued document (which performs its own inner flush — the queues are cleared first so that inner flush's own `postFlush` firing is a safe no-op, not recursion).

An invoice write queues its **sales order** as well as the invoice (#539 stage 3). The order holds `sales_hold` on its uninvoiced remainder, so anything that changes what an invoice bills changes what its order still owes — and most such writes never touch the order row at all, which is exactly the case a listener watching only orders would miss.

An invoice whose `salesOrder` CHANGED queues the order it LEFT as well, read from the UnitOfWork's changeset (#539 stage 5). Unlinking an invoice, or relinking it to a different order, is the one write where `getSalesOrder()` cannot name the order that needs recomputing: it already answers with the new value, and the order left behind would otherwise keep holding `sales_hold` for quantity nobody is billing any more.

`reconcile()` diffs the document's current lines against a per-document ledger (`OrderInventoryReservation` for the order's `sales_hold`, `InvoiceInventoryReservation` for the invoice's `pending`/`approved`) to compute the exact delta to apply to `ProductInventory`, then dispatches `App\Event\InventoryBucketsReconciledEvent` (a plain Symfony event, the sanctioned extension point for other code to react to a reservation change — not this listener itself, which is plumbing). One reconciler serves both documents, parameterized by an `InventoryReservationSubject`; see its docblock for why it is shared rather than copied.

### SalesOrderDerivedStatusSubscriber

`src/EventSubscriber/SalesOrderDerivedStatusSubscriber.php`

**Events:** Doctrine `Events::onFlush` and `Events::postFlush`, via `#[AsDoctrineListener]` — an ORM-level listener for the same reason the two above are: apart from `Approved` and `Void`, a sales order's status is a function of its invoice set, and a function of something has to be recomputed on every write to that something rather than at the call sites someone remembered (#539 stage 2).
**Input:** `OnFlushEventArgs` / `PostFlushEventArgs`.
**Output:** none returned — during `onFlush`, collects the owning `SalesOrder` of every scheduled `SalesOrder`, `SalesOrderLine`, `Invoice`, `InvoiceLine` and `InvoicePayment`; during `postFlush`, calls `App\Service\SalesOrderStatusDeriver::recalculate()` for each queued order and flushes once if anything moved (the queue is cleared first, so the inner flush's own `postFlush` is a no-op rather than recursion).

Note the last three: issuing an invoice, re-quantifying it or paying it moves its ORDER between `Approved`, `Partially Invoiced`, `Invoiced` and `Closed` without touching the order row at all. That is exactly the case a call at each call site misses.

An invoice whose `salesOrder` changed also queues the **previous** order, read from the changeset (#539 stage 5) — see the note under `InventoryReconciliationSubscriber`, which needs the same value for the same reason.

### InvoicePaymentStatusSubscriber

`src/EventSubscriber/InvoicePaymentStatusSubscriber.php`

**Events:** Doctrine `Events::onFlush` and `Events::postFlush`, via `#[AsDoctrineListener]`. Same strategy and same justification as `SalesOrderDerivedStatusSubscriber` next door (#539 stage 4).
**Input:** `OnFlushEventArgs` / `PostFlushEventArgs`.
**Output:** none returned — during `onFlush`, collects every scheduled `Invoice` plus the parent invoice of every scheduled `InvoicePayment`; during `postFlush`, calls `App\Service\InvoicePaymentStatusDeriver::recalculate()` for each and flushes once if anything moved.

Invoices are watched as well as payments because the total is what a payment is measured against: repricing an invoice can turn a Paid one partially paid without a payment row being touched.

**How it composes with the order's status.** The two listeners may run in either order. `SalesOrderStatusDeriver` decides `Closed` from `Invoice::isFullyPaid()`, which sums the payment rows itself rather than reading the `payment_status` column this one writes — so the order reaches the same answer whether or not this listener has run yet, and the column stays a queryable projection of that sum rather than an input to it.

### InvoiceShippingStatusSubscriber

`src/EventSubscriber/InvoiceShippingStatusSubscriber.php`

**Events:** Doctrine `Events::onFlush` and `Events::postFlush`, via `#[AsDoctrineListener]`. Same strategy and same justification as `InvoicePaymentStatusSubscriber` above.
**Input:** `OnFlushEventArgs` / `PostFlushEventArgs`.
**Output:** none returned — during `onFlush`, collects every scheduled `Invoice`; during `postFlush`, calls `App\Service\InvoiceShippingStatusDeriver::recalculate()` for each and flushes once if anything moved.

**Why it watches invoices and nothing else.** The payment subscriber also watches `InvoicePayment`, because payments are core rows. There is no core equivalent here: core has no shipment table. Whoever owns the shipment rows watches its own and calls the same core deriver — `InventoryDepthBundle\EventSubscriber\ShipmentShippingStatusSubscriber` is the one that does today. The deriver stays the single writer of `invoice.shipping_status` whether or not a bundle is installed.

**Degradation.** `App\Contract\Inventory\ShippedQuantityProviderInterface` (tag `app.shipped_quantity`) is the seam, gated per provider on `BundleStatusRepository::isActiveForInstance()`. With nothing active the deriver answers from core facts alone: `Completed` means `Shipped`, anything else means `Not Shipped`, and never `Partially Shipped` — core has no partial information to derive a middle value from.

### WarrantyMonthsFieldSubscriber

`modules/CustomFieldExampleBundle/src/EventSubscriber/WarrantyMonthsFieldSubscriber.php`

**Event:** `KernelEvents::REQUEST`, default priority.
**Input:** `RequestEvent`.
**Output:** none returned — calls `CustomFieldDefinitionRepository::ensureBySlug()`, a lazy upsert (no-op after the first successful insert).

Not app-specific — this is the example/reference implementation for the Custom Field bundle pattern (see `docs/bundles/custom-fields.md`). Registers a `warranty_months` number field on `product` (slug `warranty_months`, source `CustomFieldExampleBundle`), visible on add/edit/listing and searchable. Runs on every main request rather than a one-time install step, since this app has no separate bundle-install hook.
