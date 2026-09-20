# Plan: Procurement — vendor, purchase order, bill, receiving

Status: **proposal, nothing written yet.**
Base: `main` @ `af4bfd1`
Issue: #555
Prerequisites: **#550** (inventory depth) for receiving to capture lot and serial.
**#539** (invoice as a separate entity) should land first — see below.

## The change in one line

The system knows how goods leave. It has no idea how they arrive. This adds the buy side:
who we buy from, what we ordered, what turned up, and what we were charged.

```
sell side:   Estimate ──accept──> SalesOrder ──convert──> Invoice
buy  side:   (RFQ) ─────────────> PurchaseOrder ─────────> Bill
                                        │
                                   Receipt ──> inventory_movement (type=receipt)
```

Receiving is the hinge. A PO says what is coming; receiving is where stock enters the building
and where lot, expiry and serial are captured for the first time.

## Why #539 comes first

`AbstractSalesDocument` is a `MappedSuperclass` shared by Cart, Estimate and SalesOrder. #539 is
actively restructuring it — pulling Invoice out as a fourth document, moving payment and
inventory onto it, deciding how partial invoices and charge apportionment work. Building against
it now means building against a moving target.

**But procurement should not extend it either.** The base is keyed to `Company` — the buyer —
and carries sales concepts: price list, fulfillment region, customer identity snapshot. A
purchase order's counterparty is a vendor, and none of those fields mean anything to it.

## One document base, or two? — share behaviour, not storage

The instinct to put every document under one ancestor is a good one and worth taking seriously.
It does not survive contact with the mapping layer.

`AbstractSalesDocument`'s own docblock records why: *a mapped superclass can't parametrize
`targetEntity`*, which is why `lines`, `logs` and `payments` are already excluded from it. The
counterparty has exactly that problem — `Company` on one side, `Vendor` on the other. A shared
base could hold `documentDate`, currency, totals, notes and timestamps, and **not** the
relationship that defines what the document is. Thin base, full coupling.

Two more signals:

- The existing base is already strained. Cart joining it forced two fields to widen, with the
  subclasses narrowing them back in their own mapping — the class comment says so. #539 adds
  Invoice as a fourth. Purchase types would make it six or seven across two counterparty types.
- **`poNumber` means opposite things.** On a sales document it is *the customer's* PO number. On
  a purchase order, the PO number is the document's own identity. Same word, two families.

**Recommendation: parallel storage, shared interface.**

```php
interface CommercialDocument
{
    public function getDocumentNumber(): string;
    public function getDocumentDate(): string;     // 'Y-m-d'
    public function getCounterpartyName(): string; // company or vendor, already snapshotted
    public function getCurrency(): string;
    public function getTotal(): string;
    public function getLines(): Collection;
}
```

PDF rendering, document numbering, email, status logs and a unified document search all take the
interface. That is every cross-cutting benefit of one document family, with no Doctrine
constraints, no nullable widening, and no pretending a vendor is a company. It is also the house
pattern — `src/Contract/` is built this way.

So: `AbstractPurchaseDocument` as a sibling superclass, both it and `AbstractSalesDocument`
implementing `CommercialDocument`. Declaring a dozen scalar fields twice is cheaper than
contorting one superclass to serve two counterparty types.

What *is* worth copying from the sales side is the patterns, which are already proven here:

- **Snapshot the counterparty**, don't join to it live. Same reason
  `docs/plans/2026-07-30-company-identity-snapshot.md` exists: renaming a vendor must not rewrite
  a three-year-old bill.
- **`documentDate` as a plain `Y-m-d` string.** A calendar date has no instant.
- **Line-level snapshots** of description, SKU and cost, for the same reason sales lines snapshot
  price.
- **Per-concrete-class document number**, not on the base. That footgun is documented in
  `AbstractSalesDocument` and cost someone a debugging session already.

## Schema

### Vendor

```sql
CREATE TABLE vendor (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            VARCHAR(200) NOT NULL,
    account_number  VARCHAR(80)  NULL,     -- our account number with them
    email           VARCHAR(180) NULL,
    phone           VARCHAR(60)  NULL,
    payment_term    VARCHAR(80)  NULL,     -- matches the existing payment_term table
    currency        VARCHAR(3)   NOT NULL DEFAULT 'CAD',
    status          VARCHAR(16)  NOT NULL DEFAULT 'Active',
    notes           TEXT         NULL,
    created_at      DATETIME     NOT NULL
);

CREATE TABLE vendor_address (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_id   INTEGER NOT NULL REFERENCES vendor(id) ON DELETE CASCADE,
    label       VARCHAR(80)  NULL,
    address_1   VARCHAR(200) NOT NULL,
    address_2   VARCHAR(200) NULL,
    city        VARCHAR(120) NOT NULL,
    province    VARCHAR(8)   NULL,   -- code, per the province single-source-of-truth work
    postal_code VARCHAR(20)  NULL,
    country     VARCHAR(2)   NOT NULL,
    is_default  BOOLEAN      NOT NULL DEFAULT 0
);
```

`payment_term` already exists as a raw-SQL admin-managed table (it is one of the tables
`RawSqlTablesSurviveTheChainTest` protects). Reuse it rather than inventing vendor-specific terms.

### Purchase order

```sql
CREATE TABLE purchase_order (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    po_number         VARCHAR(32)  NOT NULL,
    vendor_id         INTEGER      NOT NULL REFERENCES vendor(id),
    warehouse_id      INTEGER      NOT NULL REFERENCES warehouse(id),  -- where it's going
    status            VARCHAR(20)  NOT NULL,  -- Draft|Issued|PartiallyReceived|Received
                                              -- |Closed|Cancelled
    document_date     VARCHAR(10)  NOT NULL,  -- 'Y-m-d', see note above
    expected_date     VARCHAR(10)  NULL,
    currency          VARCHAR(3)   NOT NULL,
    vendor_name       VARCHAR(200) NOT NULL,  -- snapshot
    vendor_address    TEXT         NULL,      -- snapshot
    payment_term      VARCHAR(80)  NULL,      -- snapshot
    subtotal          DECIMAL(12,2) NOT NULL DEFAULT '0.00',
    tax               DECIMAL(12,2) NOT NULL DEFAULT '0.00',
    total             DECIMAL(12,2) NOT NULL DEFAULT '0.00',
    notes             TEXT         NULL,
    created_at        DATETIME     NOT NULL
);
CREATE UNIQUE INDEX uniq_po_number ON purchase_order (po_number);

CREATE TABLE purchase_order_line (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    purchase_order_id  INTEGER NOT NULL REFERENCES purchase_order(id) ON DELETE CASCADE,
    product_id         INTEGER NULL REFERENCES product_core(id),
    name               VARCHAR(255)  NOT NULL,   -- snapshot
    sku                VARCHAR(80)   NULL,       -- snapshot
    vendor_sku         VARCHAR(80)   NULL,       -- what THEY call it
    quantity_ordered   DECIMAL(12,2) NOT NULL,
    quantity_received  DECIMAL(12,2) NOT NULL DEFAULT '0.00',
    unit_cost          DECIMAL(12,4) NOT NULL,   -- 4dp: unit costs divide
    subtotal           DECIMAL(12,2) NOT NULL,
    sort_order         INTEGER NOT NULL DEFAULT 0
);
```

`vendor_sku` is not decoration. Vendors send confirmations and packing slips in their own part
numbers, and matching by hand is where receiving errors come from.

### Receipt

```sql
CREATE TABLE purchase_receipt (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    receipt_number     VARCHAR(32) NOT NULL,
    purchase_order_id  INTEGER NULL REFERENCES purchase_order(id),  -- null = receipt with no PO
    vendor_id          INTEGER NOT NULL REFERENCES vendor(id),
    warehouse_id       INTEGER NOT NULL REFERENCES warehouse(id),
    movement_group_id  INTEGER NULL REFERENCES inventory_movement_group(id),
    packing_slip       VARCHAR(80) NULL,
    received_at        DATETIME NOT NULL,
    received_by        VARCHAR(160) NULL,
    notes              TEXT NULL
);
CREATE UNIQUE INDEX uniq_receipt_number ON purchase_receipt (receipt_number);

CREATE TABLE purchase_receipt_line (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    purchase_receipt_id  INTEGER NOT NULL REFERENCES purchase_receipt(id) ON DELETE CASCADE,
    purchase_order_line_id INTEGER NULL REFERENCES purchase_order_line(id),
    product_id           INTEGER NOT NULL REFERENCES product_core(id),
    lot_id               INTEGER NULL REFERENCES inventory_lot(id),
    serial               VARCHAR(120) NULL,
    location_id          INTEGER NULL REFERENCES warehouse_location(id),
    quantity             DECIMAL(12,2) NOT NULL CHECK (quantity > 0),
    unit_cost            DECIMAL(12,4) NULL
);
```

`movement_group_id` is the join to #550. **The receipt does not write stock itself** — it calls
the same movement service the adjustment screen and the scanner call, and stores the group id it
got back. One implementation of "stock entered," three ways to trigger it.

A receipt with `purchase_order_id` null is legitimate: goods arrive that nobody raised a PO for,
and refusing to record them means the warehouse is wrong. Flag it rather than block it.

### Bill

```sql
CREATE TABLE vendor_bill (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    bill_number       VARCHAR(32)  NOT NULL,   -- ours
    vendor_invoice_no VARCHAR(80)  NULL,       -- theirs
    vendor_id         INTEGER      NOT NULL REFERENCES vendor(id),
    purchase_order_id INTEGER      NULL REFERENCES purchase_order(id),
    status            VARCHAR(20)  NOT NULL,   -- Draft|Open|PartiallyPaid|Paid|Void|Disputed
    document_date     VARCHAR(10)  NOT NULL,
    due_date          VARCHAR(10)  NULL,
    currency          VARCHAR(3)   NOT NULL,
    vendor_name       VARCHAR(200) NOT NULL,   -- snapshot
    subtotal          DECIMAL(12,2) NOT NULL DEFAULT '0.00',
    tax               DECIMAL(12,2) NOT NULL DEFAULT '0.00',
    total             DECIMAL(12,2) NOT NULL DEFAULT '0.00',
    amount_paid       DECIMAL(12,2) NOT NULL DEFAULT '0.00',
    created_at        DATETIME     NOT NULL
);
CREATE UNIQUE INDEX uniq_bill_number ON vendor_bill (bill_number);
CREATE INDEX idx_bill_vendor_invoice ON vendor_bill (vendor_id, vendor_invoice_no);
```

Plus `vendor_bill_line`, mirroring the PO line and optionally pointing at one.

The index on `(vendor_id, vendor_invoice_no)` is a **duplicate-payment guard**. Paying the same
invoice twice is the single most expensive clerical error in AP, and it happens because the same
PDF arrives twice by email. Warn on a match; don't hard-block, because vendors do reuse numbers.

## Three-way match

The control that makes this worth building rather than keeping a spreadsheet:

```
ordered (PO)  ↔  received (receipt)  ↔  charged (bill)
```

A bill line that matches its PO line in quantity and price, and has been received, is approvable
without anyone thinking. Everything else needs a human. Surface the exceptions:

| Exception | |
|---|---|
| Billed but not received | pay for goods you don't have |
| Received but not billed | accrual — you owe money nobody has asked for |
| Price variance | vendor charged more than quoted |
| Quantity variance | over- or under-shipped |

Tolerances are configuration: a small percentage or absolute variance auto-approves, anything
beyond is an exception. Start with zero tolerance and loosen it when the noise becomes clear —
it's much easier to loosen than to discover what was auto-approved for six months.

## Partial receipts and vendor backorders

PO for 240, 210 arrive. This is the mirror of #548's customer backorder and should read the same
way to a user, but it is **not the same mechanism** — nothing is reserved, no customer is
promised anything, and no bucket on `product_inventory` is involved.

`quantity_ordered − quantity_received` on the PO line is the whole model. When it reaches zero
the line is complete; when the PO's lines are all complete, it is Received. A short shipment that
will never be completed is closed manually, which is a decision (write it off, chase it, cancel
the remainder) and must not happen automatically.

**Over-receipt** needs a deliberate answer: 250 arrive against a PO for 240. Recommendation:
allow it, record the variance, flag it on the match. Refusing means the warehouse can't record
what is physically on the dock, which is worse.

## Screens

- Vendor index, detail, addresses
- Purchase order index, detail, issue, print/email to vendor
- Receiving — against a PO, or standalone; captures lot, expiry, serial, destination bin
- Bill index, detail, match against PO and receipts, approve
- Exception views: billed-not-received, received-not-billed, price and quantity variances
- Expected-arrivals view — what is due in, by date, per warehouse

Filter state in URL GET params on every list. Standing requirement in this codebase.

## Acceptance

- Every existing test passes unchanged. Nothing here touches the sales side.
- Receiving produces the same `inventory_detail` rows and movement group as the equivalent manual
  adjustment. Same service, provably.
- A partial receipt leaves the PO `PartiallyReceived` with the correct remaining quantity.
- A receipt of a lot-tracked product without a lot is refused; with one, it creates or reuses the
  `inventory_lot` row correctly, including the case where the vendor reuses a lot code with a
  different expiry.
- A second bill carrying a vendor invoice number already on file warns.
- A three-way match with a price variance inside tolerance approves; outside it, doesn't.

## This can be a bundle

Build it as one, on the same rule as #550 and #552:

> **Core reads nothing this writes.**

Nothing in core reads `vendor`, `purchase_order`, `purchase_receipt` or `vendor_bill`. Receiving
calls #550's movement service and stores the group id it gets back — it never writes stock
itself. That is the same discipline that makes it removable and the same discipline that stops
receiving becoming a second path into `inventory_detail`.

Remove it and stock still enters the building, through the adjustment screen, which #550 already
specifies as the way stock enters before receiving exists. No precondition, nothing to migrate.

**Data outlives the bundle**, same as #552: the receipts and the movements they created remain,
and remain visible in #550's movement history. Only the screens that manage them go away.

The one thing that would break this rule is **valuation** — if a receipt's cost fed product cost,
core would start reading something procurement writes. That is a further reason it belongs in its
own job rather than drifting in here.

## Not in this job

**Inventory valuation and costing.** Whether `unit_cost` on a receipt updates the product's cost,
and by what rule — moving average, FIFO, standard — is a genuinely separate piece of work with
accounting consequences. This job **records** what was paid on the receipt line and changes no
product cost automatically. Do not let it drift in.

**Landed cost.** Freight, duty and brokerage apportioned onto unit cost. Real, and it matters
for an importing wholesaler, but it depends on the valuation decision above and belongs with it.

Also out: payments to vendors and AP aging, vendor RFQ / quote comparison (the buy-side Estimate
— add it if buyers actually shop around, skip it if they reorder from one source), EDI, vendor
portals, drop-ship, and automatic reorder-point purchasing.

## Easy to get wrong

**Receiving that writes stock directly.** It must go through #550's movement service. A second
path into `inventory_detail` diverges on validation and audit, and receiving is exactly where
lot, expiry and serial capture must be enforced identically to everywhere else.

**Vendor identity joined live instead of snapshotted.** Renaming a vendor would rewrite every
historical bill. The repo already learned this on the sales side and has a plan document about it.

**Quantities as integers.** Purchase quantities divide — cases, weights, partial units. The PO
and receipt lines use `DECIMAL(12,2)` to match `sales_order_line.quantity`, and `unit_cost` uses
four decimal places because a case cost divided by units per case rarely lands on two.

**Currency.** A vendor billing in USD against a CAD catalogue needs a rate somewhere. This plan
stores currency per document and does no conversion. That's a deliberate deferral, not an
oversight — but if any real vendor bills in another currency, it stops being deferrable and
should be settled before the schema is written rather than after.

**Assuming a PO exists.** Goods arrive without one. Receipts allow a null PO deliberately.

**Closing a PO automatically on a short shipment.** The remaining quantity is a decision — chase,
cancel, or write off — and quietly closing it hides money.
