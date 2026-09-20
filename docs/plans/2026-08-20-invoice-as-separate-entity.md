# Plan: Invoice as a separate entity

Status: **proposal, nothing written yet.**
Base: `main` @ `721e946`
Issue: #539

## The change in one line

A Sales Order stops being its own invoice. `Invoice` becomes a fourth `AbstractSalesDocument`
subtype, and payment, inventory and the payment-driven statuses move onto it — leaving the SO
holding only the accounting question "how much of this has been invoiced?"

```
today:    Estimate ──accept──> SalesOrder (status + payment + inventory + prints as "invoice")

after:    Estimate ──accept──> SalesOrder ──convert──> Invoice  (1..n)
                               Draft                   Draft
                               Partially Invoiced       Pending
                               Invoiced                 Processing
                               Closed                   Completed
                               Void                     Cancelled
                               ↑ qty-driven, derived    ↑ + paymentStatus, + inventory
```

Ecom is unchanged in behaviour: checkout creates one SO and one Invoice atomically, so the 1:1
assumption the whole app is built on still holds for that path. The new machinery only becomes
visible when someone invoices an order in parts.

## Why (and why not the alternatives)

The app is ecom-biased: it assumes a sales order *is* an invoice, is paid all at once, and ships
all at once. That is true for a webstore and false for a distribution business taking manual
orders, where one accepted order is shipped and billed across several invoices over weeks.

**Why not a second status field on SalesOrder.** The first shape considered was keeping
fulfilment on the SO and adding an `invoicingStatus` beside it. It was rejected: partial
invoicing needs per-invoice line quantities, dates, numbers, totals and payments, and none of
those fit on a status column. Once you need the child rows you have the entity, and a status
field that shadows it is a second answer waiting to disagree.

**Why not reuse SalesOrder rows with a document-type discriminator.** Single-table inheritance
across SO and Invoice would put ~15 nullable columns on one table and make every existing query
ambiguous about which kind of row it means. `AbstractSalesDocument` already exists for exactly
this and already has three subtypes; adding a fourth is the established move.

**Facts that shaped this, worth re-checking if they change:**

- **No production instance holds any order data** (confirmed 2026-08-20). Every instance is a
  dev one. This is what makes an outright re-anchoring migration cheap, and it has a shelf life:
  the moment real order data exists, this plan's migration section needs re-confirming.
- `DocumentNumberAllocator` is already per-`(kind, prefix)` and atomic, so `INV-` needs no new
  concurrency work — only a new `kind` and a new prefix setting.
- `EstimateConversionService` is a working, tested conversion between two document subtypes,
  including the snapshot rules. SO → Invoice is the same shape and should follow it.
- Nothing in the app decrements Starting Inventory on shipment. Stock is held in buckets and
  released by import/recount. Invoicing does not change that.

## How the reference systems do it

| | Commits stock at | Deducts stock at | Invoice's role |
|---|---|---|---|
| Zoho Books / Inventory | Sales Order → Committed Stock | Invoice (accounting stock) | financial **and** stock-deducting |
| NetSuite | Sales Order → Committed | Item Fulfillment | financial only (Advanced Shipping) |
| Dynamics 365 Business Central | SO line `Qty. to Ship` / `Qty. to Invoice` | posting the Shipment | financial only; cannot invoice > shipped |

All three commit stock at the Sales Order and release it when goods move. They differ only on
which document deducts. Since this app has no shipment entity and is not gaining one here,
**Zoho is the model followed**: the SO commits, the Invoice deducts.

That is the reasoning behind the Sales Hold bucket below. Without it an accepted SO commits
nothing, and a rep can accept ten orders against the same 100 units with nothing flagging it —
the one behaviour none of the three reference systems permit.

## Entities

New, all following the existing per-subtype pattern (a mapped superclass cannot parametrize
`targetEntity`, which is why `Cart`/`Estimate`/`SalesOrder` each own their lines and addresses):

- `Invoice extends AbstractSalesDocument` — `documentNumber`, `status`, `paymentStatus`,
  `paymentMethod`, `paymentTerm`, `invoiceDate`, `dueDate`, nullable `salesOrder` FK.
- `InvoiceLine implements DocumentLine` — mirrors `SalesOrderLine`, plus a nullable
  `salesOrderLine` FK so invoiced quantity is attributable to the SO row it came from.
- `InvoiceAddress extends AbstractDocumentAddress`
- `InvoiceLog` — mirrors `SalesOrderLog`
- `InvoicePayment` — `SalesOrderPayment` re-pointed at Invoice (rename + FK change, same columns)
- `InvoiceInventoryReservation` — the invoice's `pending`/`approved` rows

`OrderInventoryReservation` stays, pointed at `SalesOrder`, and holds only the new `sales_hold`
bucket.

**Why two reservation entities rather than one with nullable `order_id`/`invoice_id`.** The
nullable-pair shape is cheaper to write and was explicitly declined: it is the polymorphic FK
this codebase has consistently refused (lines, addresses and logs all take a concrete class per
subtype), and it needs a check constraint to express "exactly one of these is set" that the
two-entity shape gets from the type system for free. Each entity also ends up able to hold only
the buckets it can actually have, so `bucketForStatus()` stops being able to return a bucket the
row has no business in.

## Statuses

`SalesOrderStatus` is rewritten. The current cases (`Pending`, `On Hold`, `Processing`,
`Completed`, `Cancelled`) move to a new `InvoiceStatus` enum; `Draft` is the only survivor.

`Approved` sits between `Draft` and `Partially Invoiced`, following Zoho's terminology. It is the
one SO status a human sets: **an order must be approved before it holds inventory**, which is what
separates "still being typed up" from "accepted, awaiting fulfilment". Everything from `Approved`
onward counts as approved. It is also the only manual transition — every later status is derived.

| SalesOrder | Meaning |
|---|---|
| `Draft` | being written up; not yet accepted |
| `Approved` | accepted, nothing invoiced yet |
| `Partially Invoiced` | one or more invoices, but not all qty invoiced |
| `Invoiced` | all qty invoiced, one or more invoices not fully paid |
| `Closed` | Invoiced + every invoice fully paid |
| `Void` | soft delete — out of reporting totals, never backdated away |

| Invoice | Meaning |
|---|---|
| `Draft` | written but not issued — entirely inert |
| `On Hold` | issued, but waiting on an up-front payment that has not arrived |
| `Pending` | issued, awaiting fulfilment |
| `Processing` | being fulfilled |
| `Completed` | fulfilled |
| `Cancelled` | withdrawn; keeps its number forever |

Which bucket each of these holds is in one table in the next section — deliberately not repeated
here, so the two cannot drift apart.

`On Hold` is carried over from `SalesOrderStatus` rather than dropped. It is the abandoned-card-
checkout state, it holds no stock, and it is the only status `CancelStaleUnpaidOrdersCommand`
sweeps — no other case means that, so removing it would have left the ecom flow with nothing to
say. A customer on credit terms is a different thing entirely: they are legitimately unpaid for the
length of their term, so their invoice goes straight to `Pending` and gets fulfilled.

Invoice has no `Void`: cancelling is the equivalent, and a cancelled invoice keeps its `INV-`
number permanently. `DocumentNumberAllocator` only ever increments, so this holds for free as
long as invoice rows are never deleted — which the audit requirement in the issue demands anyway.

A draft invoice is entirely inert. It holds no stock and does not draw down the SO's uninvoiced
quantity, so the stock stays in `sales_hold` until the invoice leaves `Draft`. The alternative —
drafts consuming uninvoiced qty while holding no bucket — would make availability *rise* the moment
someone started invoicing, which is a hole rather than a policy.

Apart from `Approved`, SO statuses are **derived, never set by hand.** One service recalculates
from the invoice set, called on every write to an SO or any of its invoices. Cancelled invoices are
excluded from invoiced quantity, so cancelling every invoice on an order returns it to `Approved`
and its full quantity to `sales_hold` — the goods are still owed.

**An admin cancelling an invoice never touches its SO.** Voiding an order takes it out of reporting
totals, and that is a judgement about the order, not a consequence of what happened to one of its
invoices. The admin who wants it voided goes and voids it.

The stale-unpaid sweep does more than this, not something different from it — see below. Both go
through the **same invoice-cancellation path**; the sweep composes an SO void on top of it. So
cancelling an invoice is one function with one set of side effects, and what separates the two
situations is only what each does *afterwards*.

## Actions, not status writes

Every transition in this plan is a **named action** on the entity. `setStatus()` stops being part
of the public surface of `SalesOrder` and `Invoice`, and no caller anywhere assigns a status
directly.

| Entity | Action | From → To |
|---|---|---|
| SalesOrder | `approve()` | `Draft` → `Approved` |
| SalesOrder | `void()` | any non-`Void` → `Void` |
| Invoice | `issue()` | `Draft` → `Pending` |
| Invoice | `startProcessing()` | `Pending` → `Processing` |
| Invoice | `complete()` | `Processing` → `Completed` |
| Invoice | `cancel()` | any non-`Cancelled` → `Cancelled` |

`approve()` and `void()` are the only SO transitions anyone calls; every other SO status is written
solely by the recalculation service, from the invoice set. Nothing else may write it.

**Why this, and what is *not* the argument for it.** A count of `setStatus()` call sites proves
nothing on its own. What matters is whether the side effects a transition requires happen
regardless of who triggers it — and in this codebase most of them already do:

- **Audit logging is automatic.** `AuditLogSubscriber` is a Doctrine `onFlush`/`postFlush`
  listener with an explicit `EXCLUDED` list, and `SalesOrder` is not on it. Every status change is
  recorded whatever wrote it.
- **Inventory reconciliation is automatic.** `OrderInventoryReconciliationSubscriber` reconciles
  buckets on any `SalesOrder`/`SalesOrderLine` change, no matter the code path. `Invoice` gets the
  same treatment in stage 3.

That is a deliberate design and a good one: the side effects live where no caller can forget them.
So "71 hand-wired `setStatus()` calls" is not, by itself, a defect — and this plan does not claim
it is. Three things genuinely are not covered, and they are the whole case for named actions:

- **Nothing rejects an illegal transition.** A string setter accepts `Closed` → `Draft`, or an
  invoice going `Cancelled` → `Completed`, and no layer objects. A subscriber is the wrong place to
  enforce this — by `onFlush` the change has already been made and the caller's intent is gone. An
  action validates its from-state at the point of the call and throws.
- **The derived-status invariant is unenforceable through a public setter.** Only `approve()`,
  `void()` and the recalculation service may write an SO status; everything else is derived from
  the invoice set. With `setStatus()` public, that rule is a convention a reviewer has to police.
  Without it, it is the type system.
- **The document log is genuinely hand-wired.** `SalesOrderLog` — the human-readable timeline on
  the order page, distinct from the audit log — is written at exactly four call sites, all in
  `EstimateConversionService` and `CheckoutController`. Admin status changes write no timeline
  entry at all. This one really is a per-caller responsibility today, and an action is where it
  stops being one.

The sweep is the worked example of the shape: it calls the same `Invoice::cancel()` an admin's
Cancel button calls, then `SalesOrder::void()`. Two actions composed, no third code path, and no
`if ($isSweep)` branch inside either.

Scope note: this applies to `SalesOrder` and `Invoice` — the entities this plan touches. The other
69 `setStatus()` call sites are a separate cleanup, not smuggled in here.

### The timeline entry belongs inside the action

`SalesOrderLog` (and `InvoiceLog` beside it) is the human-readable timeline on the document page.
Writing it is **part of the mutation, not a duty of the caller** — the entry is produced by the
method that makes the change, and there is no way to make the change without producing it.

This is not limited to status transitions. Any mutation a user would expect to see on the timeline
carries its own entry: lines added, removed or re-quantified, prices overridden, addresses
changed, the document converted from another. If an edit method exists, it writes its own history.

**Why this log and not the audit log's mechanism.** The two are different in kind, which is why
the codebase carries both and excludes one from the other. `AuditLogSubscriber` produces a
field-level diff automatically at flush — correct for an audit trail, where completeness matters
and intent does not. The timeline is narrative written for a person: "Cancelled by the stale-unpaid
sweep" and "Cancelled by Priya, customer changed their mind" are the same changeset and different
entries. A Doctrine subscriber cannot tell them apart, because by `onFlush` the intent that
distinguishes them is gone. That is precisely why this one has to be written at the point of the
action, and why doing so is not a regression to hand-wiring — the caller supplies *intent*, never
the bookkeeping.

**The actor.** `SalesOrderLog::$userName` needs to know who acted, and an entity cannot reach the
security context. The resolution already exists twice and should be extracted once:
`AuditLogger::resolveActor()` has `Security` injected and returns `[type, id, name]`, and
`CheckoutController::documentActorName()` rolls its own. One `DocumentActorResolver` serves both,
and every action takes an explicit actor rather than reaching for an ambient one — which is what
lets the stale-unpaid sweep pass a distinct **sweep cron** actor and satisfy the audit requirement
above using the ordinary mechanism instead of a special case.

## Inventory: the Sales Hold bucket

`product_inventory` gains `sales_hold_quantity`, a fourth hold bucket beside `cart_hold`,
`pending` and `approved`.

```
available = quantity − cart_hold − sales_hold − pending − approved
```

| Bucket | Fed by | Released when |
|---|---|---|
| `cart_hold` | live carts | hold expires, or cart converts |
| `sales_hold` | **SO uninvoiced qty** — (ordered − invoiced), for orders `Approved` or later | invoiced, or SO voided |
| `pending` | invoices in `Pending` | status moves on, or cancelled |
| `approved` | invoices in `Processing`/`Completed` | import/recount only |

### Status → bucket: the authoritative mapping

Every status of both documents, and exactly what it holds. This table is the single source of
truth for `bucketForStatus()` on either side; nothing else in this plan restates it.

| Document | Status | Bucket | Effect on Available | Counts as invoiced? |
|---|---|---|---|---|
| **SO** | `Draft` | *none* | nothing held | — |
| **SO** | `Approved` | `sales_hold` | −(ordered − invoiced) | — |
| **SO** | `Partially Invoiced` | `sales_hold` | −(ordered − invoiced) | — |
| **SO** | `Invoiced` | *none* | nothing left uninvoiced to hold | — |
| **SO** | `Closed` | *none* | — | — |
| **SO** | `Void` | *none* | released | — |
| **Invoice** | `Draft` | *none* | nothing held | **no** |
| **Invoice** | `On Hold` | *none* | nothing held | **yes** |
| **Invoice** | `Pending` | `pending` | −qty | yes |
| **Invoice** | `Processing` | `approved` | −qty | yes |
| **Invoice** | `Completed` | `approved` | −qty, held indefinitely, as today | yes |
| **Invoice** | `Cancelled` | *none* | released, qty returns to the SO's `sales_hold` | **no** |

The last column is not decoration — it is the other half of the answer, and the two columns
together are what make an abandoned checkout hold nothing without a special case:

| | invoiced qty | SO `sales_hold` | Invoice bucket | net held |
|---|---|---|---|---|
| ecom, unpaid (`On Hold`) | full | 0 | none | **nothing** |
| ecom, paid (`Pending`) | full | 0 | `pending` | qty |
| manual SO, nothing invoiced yet | 0 | full | — | qty |
| manual SO, half invoiced and issued | half | half | `pending` on half | full |

An `On Hold` invoice **counts** as invoiced, so the order's `sales_hold` is already zero, and the
invoice itself holds nothing. Two independent rules meeting at the right answer, rather than an
exclusion clause bolted onto one of them. A `Draft` invoice is the opposite case and does *not*
count, which is why drafting one leaves the quantity sitting in the order's `sales_hold` rather
than escaping into neither.

One consequence worth expecting: an ecom order derives to `Invoiced` the moment checkout completes,
since all its quantity is invoiced from the outset. That is accurate — it is fully invoiced —
and `Approved` remains the human action that got it there.

The SO rows are a single rule rather than six: `sales_hold` = uninvoiced qty for any order
`Approved` or later, and `0` for `Draft` and `Void`. `Invoiced` and `Closed` fall to zero on their
own because nothing is uninvoiced, so they need no case of their own.

**Why a fourth bucket rather than folding SO holds into `pending`.** `cart_hold` already exists
precisely because carts are a different source from orders — the codebase made this call once
already. Beyond consistency it buys two things. `InventoryRecalcCommand` recomputes each bucket
from source of truth; one bucket fed by two entities means a discrepancy cannot be attributed to
either side, where one source table per bucket makes every drift attributable. And SO-held and
invoice-held quantities are disjoint by construction, so in separate buckets a bug in the
uninvoiced calculation shows up as a bucket disagreeing with its source — in a shared bucket it
just looks like a slightly larger Pending, invisibly, forever.

`sales_hold` is `0` for a `Draft` or `Void` order, and otherwise the uninvoiced remainder — which
falls naturally to zero at `Invoiced` and `Closed` without needing a rule of its own. Cancelling
invoices puts quantity back into it.

Note the ecom path is unaffected: its SO is approved and fully invoiced the instant it exists, so
`sales_hold` is 0 and all the stock sits on the invoice exactly as it does today.

One thing not to get wrong while building it: `OrderInventoryReservationService` is 183 lines of
diff-against-a-durable-ledger reconciliation, and stage 3 introduces a second reservation entity
that needs the same logic. Share it — parameterized by which lines to read, which bucket resolver
to ask and which reservation class to write — rather than copying it. Two divergent copies of
reconciliation is how the pending and approved buckets end up disagreeing with their own ledger.
`applyBucketDelta()`'s `if/elseif` and `getAvailableQuantity()`'s hardcoded subtractions both need
the new bucket adding too, along with `InventoryRecalcCommand` and the two bundle recalc commands;
missing one is silent, since a bucket nothing applies simply drops the reservation.

Touch points: `InventoryBucketChangeLog` gains `BUCKET_SALES_HOLD` (its docblock says "three
reservation buckets" and needs updating to four), `getAvailableQuantity()`,
`AbstractCustomerController::availableQuantityForProduct()`, `AdminOrderStockValidator`,
`InventoryRecalcCommand`, and the two bundle recalc commands. 19 references across 13 files.

## The stale-unpaid sweep

`CancelStaleUnpaidOrdersCommand` currently sweeps stale `On Hold` orders to reclaim stock. It now
sweeps stale unpaid **invoices**, and for an ecom 1:1 pair it cancels the invoice **and voids the
SO**.

An abandoned checkout is junk on both sides. Nobody accepted the order, no goods are owed, and no
human is ever going to come back and tidy it up — which is precisely what distinguishes this from
an admin cancelling an invoice, where a person is present, making a decision about a real order,
and can void it themselves if that is what they want. Leaving the SO here would strand its full
quantity in `sales_hold` indefinitely, which is the exact leak the current `On Hold` sweep exists
to prevent.

Implementation follows from that: the sweep calls the same invoice-cancellation service an admin's
Cancel button calls, and then voids the SO. Cancelling an invoice must not grow a "was this the
sweep?" branch inside it — one function, one behaviour, with the extra step living in the caller
that wants it.

Because this is an automated void, it must be unmistakable in the audit trail.
Both the invoice cancellation and the SO void are logged **explicitly attributed to the sweep
cron**, not to a user and not to "system" — an admin looking at a voided order must be able to see
at a glance that a scheduled job did it and why. `InventoryBucketAuditLogger` and the document logs
both need a distinct actor for this.

## The Orders grid payment column

The Orders grid keeps a payment column, rolled up from the order's invoices. It is **view-only and
has no entity or column of its own** — it is an aggregate over child invoices, computed at query
time, so it can never drift from the invoices it summarises.

Implementation note: it must be one grouped subquery joined into the existing list query, not a
per-row lookup. The grid pages orders and a naive accessor would issue a query per row.

## Quantities

Uninvoiced qty is **derived, never stored**: `SO line qty − Σ(invoice line qty)` across all
non-cancelled invoices. A stored column is a second answer that drifts the first time an invoice
is edited.

Rules settled with the client:

- An invoice line's qty **may not exceed** the SO's remaining qty (revisitable later).
- An invoice **may** carry a SKU that is not on the SO — added at ship time, fees, extras.
- Prices are editable at invoice level, so an invoice total need not match its share of the SO.
- `Invoiced` is therefore decided **by quantity only**, never by amount.
- Shipping and other fee lines are exempt from any matching rule.

## Charges on a partial invoice

**A charge is a quantified row like any other, and the user says how much of it this invoice
bills.** A fixed fee is simply a row with quantity `1`. Bill `0.4` of it on one invoice and `0.6`
remains — the same uninvoiced-quantity derivation product lines already use, applied to charges.

There is **no apportionment logic**. The system does not divide a charge across instalments and
does not decide what share this invoice deserves. It offers the remaining quantity as the default,
the user edits it, and the only thing the system enforces is that it adds back up:

- the sum of a charge row's invoiced quantity across non-cancelled invoices may not exceed the
  order's quantity for that row
- the order is not `Invoiced` until every row, charges included, has reached its ordered quantity
- cancelling an invoice returns its share to the remainder, exactly as it does for product lines

Rounding disappears as a concern with it. Nothing divides, so there is no remainder to place — the
figures the user entered sum to the order's own, or the order is not fully invoiced yet.

Most rows still need no thought at all, because the charge model is already per-unit: tax is a rate
against lines, `ProductFee` carries a per-unit value, and percentage coupons rebuild from whatever
subtotal they are handed. Those fall out of quantity on their own. What this rule adds is an answer
for rows with no per-unit value — shipping, fixed-amount discounts, and **flat lines emitted by fee
calculators**, which are ordinary and shipping today: `PayUponDeliverySurchargeFeeCalculator`
returns a flat `SURCHARGE_AMOUNT` with no quantity in it at all. Flat charges are not an
admin-typed edge case, and a rule that only covered manual entry would have missed them.

**Why not proportional apportionment.** An earlier draft had the system split every charge by the
invoiced quantity ratio, with the completing invoice absorbing the rounding remainder. It was
rejected: it invents a policy the business may not want, it needs a rounding rule to stay
self-consistent, and it takes a decision away from the person raising the invoice, who knows
whether this delivery carried the freight and the previous one did not. Quantified rows need none
of that — they reuse machinery that already exists and leave the judgement where it belongs.

**Implementation note.** Charges are held today as a JSON snapshot (`fee_lines`) of `FeeLine`
objects carrying an amount and no quantity. Billing part of one therefore needs charge rows on an
invoice to carry a quantity, which is a real change to how charges are represented on a document —
not a calculation to be added. Whichever stage builds the Convert screen's charge handling owns it.

## What is deliberately not guarded

Several rules were considered and explicitly rejected, on the principle that the **invoice is the
accounting record and the sales order is an operations document**. An order that disagrees with its
invoices is an ops discrepancy, not a books problem, so the system reports rather than refuses.

**An order may be edited freely after it has been invoiced.** Cut a line from 100 to 30 when 40 are
already billed, or delete a billed line outright — nothing stops it. What must hold is that the
status keeps telling the truth, and it does, with no new code: raising the quantity on an
`Invoiced` order reopens it as `Partially Invoiced`, and cutting below what is invoiced floors the
remainder at zero rather than going negative, which would let the next invoice borrow quantity
back. Inventory stays correct either way, because the invoice's own quantity is what its bucket
holds.

**An invoice may carry a SKU the order never had**, and may carry a charge the order never quoted —
freight for one specific delivery, say. Neither is blocked. An off-order row draws down nothing,
because uninvoiced quantity is attributed per order line.

**Tax may sum a cent or two off across instalments.** Each invoice computes its own tax from its
own lines, so three instalments need not add exactly to what one invoice would have charged. No
rule reconciles this, deliberately: every available answer picks a loser, and the amounts are
immaterial.

The one thing that IS refused: **an invoice with payments against it cannot be cancelled.** An
admin deletes the payments first. Money against a document makes it a real accounting record, and
a real record is credited or refunded, never withdrawn.

## Credit memos

A credit memo is **financial only. It never un-invoices anything.**

It either refunds the customer — matched against a cash-out — or credits the balance to apply
against a future invoice as payment. Either way the original invoice and its order stay done.
Concretely, a credit memo does not return quantity to the order's uninvoiced remainder, does not
return anything to `sales_hold` or any other bucket, does not change the order's derived status,
and does not affect `Invoice::countsTowardInvoicedQuantity()`.

The client's framing: spoiled milk you already sold is gone. You do not get to undo the sale; you
credit the customer. Anything that made a credit memo behave like a partial cancellation would be
modelling a return, which is a different document this issue does not introduce.

## An admin-raised order carries no invoice

Only **ecom checkout** raises an invoice automatically, preserving the 1:1 pair the app was built
on for a customer who has already been billed. An order raised through the admin form — or cloned —
starts with **zero** invoices, and the first one comes from Convert to Invoice. Zoho Books behaves
the same way.

This corrects stage 1, which raised an invoice on every creation path because the brief was written
around the migration's "every order has exactly one invoice" invariant rather than around the
workflow. An admin order that arrives fully invoiced has nothing left to convert, which is the
feature. The invariant still holds everywhere anything depends on it: for every order that existed
at migration time, and for every ecom order. The deriver has always handled an order with no
invoices — that is `Approved`.

## Staging

Six PRs. The ordering exists so that each stage leaves the data consistent, and specifically so
that the 1:1 invariant is true everywhere before anything is migrated off the SO.

1. **Invoice entity, numbering, and the 1:1 shadow.** Entities, `INV-` prefix setting, `kind =
   'invoice'`, migration creating exactly one invoice per existing order, and checkout writing
   SO+Invoice atomically. Nothing moves off SalesOrder yet — the invoice is a shadow. Ends with
   every order in every environment having exactly one invoice.
2. **The action surface, then Convert to Invoice.** `approve()`, `void()`, `issue()`,
   `startProcessing()`, `complete()`, `cancel()`, each writing its own timeline entry;
   `DocumentActorResolver` extracted from `AuditLogger::resolveActor()` and
   `CheckoutController::documentActorName()`; `setStatus()` off the public surface of both
   entities. Then the create-invoice screen pre-filled with uninvoiced qty, the SO↔Invoice
   cross-links on both detail pages, and the derived SO statuses. The actions come first only
   because the screen calls them.
3. **Inventory.** `sales_hold_quantity`, `InvoiceInventoryReservation`, re-anchoring migration,
   recalc commands. `OrderInventoryReservationService` is 183 lines of reconciliation and
   must be shared between the two reservation entities rather than copied — see below.
4. **Payments.** `SalesOrderPayment` → `InvoicePayment`, payment status and allocation onto
   Invoice, credit memos re-attached, Stripe applier re-pointed.
5. **Link an existing invoice to an SO.** The SKU-matching rule and its mismatch reporting.
6. **Presentation.** SO's new PDF template, invoice PDF and packing slip on Invoice, customer
   portal showing both documents, admin grids.

Stage 5 is deliberately last and deliberately alone. The matching rule — "SKU/product lines must
match so it can deduct properly, and show clearly what does not match" — is the subtlest thing
in the issue, and it should be pinned by tests before any UI is written against it.

## Migration

Authorised outright by the client on the grounds that no production instance holds order data.
One migration, in stage order:

- one `Invoice` per existing `SalesOrder`, copying header, lines, addresses and snapshots, with
  status mapped from the order's current fulfilment status and `invoiceDate` carried across
- `sales_order_payment` → `invoice_payment`, re-pointed at the new invoice
- `order_inventory_reservation` rows in `pending`/`approved` → `invoice_inventory_reservation`
- existing SO status strings → the derived invoicing status
- legacy status strings (`Waiting for Quote`, `Accepted Quotes`) map to `Draft`

`SalesOrder.status` stays a plain string column, as it is today, so any row carrying a value the
enum no longer knows still hydrates. `getStatusEnum()` keeps absorbing that.

## Decisions taken

All five questions raised during review are settled and written into the sections above.
Nothing in this plan is awaiting an answer.

1. Draft invoices do **not** consume the SO's uninvoiced quantity.
2. The SO gains an **`Approved`** status between `Draft` and `Partially Invoiced`. An order must be
   approved before it holds stock; `Approved` and later all count as approved.
3. Cancelling invoices never *voids* an SO automatically. The derived status does recompute:
   cancelling every invoice returns the order from `Invoiced`/`Partially Invoiced` to `Approved`,
   with its full quantity back in `sales_hold`. Taking an order out of reporting totals stays a
   deliberate act an admin performs.
4. The stale-unpaid sweep cancels the invoice **and** voids the SO, both logged explicitly as the
   sweep cron. It shares the invoice-cancellation path with 3 and adds the void on top — the same
   function doing the same thing, with one extra step in the caller.
5. The Orders grid keeps a rolled-up payment column, view-only, aggregated from child invoices.
7. The timeline entry is written by the method that makes the change — for edits as much as for
   status transitions — never by the caller. The audit log stays automatic; the timeline cannot be,
   because it records intent a changeset does not carry.
6. Every transition is a named action on the entity — `approve()`, `void()`, `issue()`, `cancel()`
   and so on. `setStatus()` leaves the public surface of both entities. The case for this is
   transition validity, the derived-status invariant, and the document log — **not** the raw count
   of existing `setStatus()` calls, since audit logging and inventory reconciliation already happen
   path-independently via Doctrine subscribers. Shared paths compose actions rather than
   reimplementing them.
