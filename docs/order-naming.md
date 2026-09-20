# Order naming: `sales_order` / `SalesOrder`

**Status:** done. The rename landed in `Version20260731160000` (issue #152). This document is kept as
the record of *why* the old name existed and *what the rename touched*, because both questions come
back every time someone reads the migration history or an old branch.

## What the tables and entities are called

| Table | Entity |
|---|---|
| `sales_order` | `App\Entity\SalesOrder` |
| `sales_order_address` | `App\Entity\SalesOrderAddress` |
| `sales_order_line` | `App\Entity\SalesOrderLine` |
| `sales_order_log` | `App\Entity\SalesOrderLog` |

`sales_order_payment` / `App\Entity\SalesOrderPayment` was in this list until #539 stage 4, which
moved it to `invoice_payment` / `App\Entity\InvoicePayment`. Only the foreign key changed: money is
received against an INVOICE now, because an order billed across three invoices is paid across three
invoices and a payment pointing at the order could not say which of them it cleared.

One table holds **every** order, admin-created and customer-checkout alike. Origin is tracked by the
`source` column — `AbstractSalesDocument::$source` defaults to `'Admin'`, and `CheckoutController`
overrides it with `'Customer'`.

The `sales_` prefix is not decoration: **`ORDER` is a reserved SQL keyword**, so a bare `order` table
would have to be quoted at every use site. Some prefix is mandatory; this one is at least accurate.

Related but separate: `estimate` (a quote converts *into* a `sales_order` row) and
`order_inventory_reservation`.

## Why it used to be `admin_order`

It was built first as the admin order-management feature. The storefront later — correctly — reused
the same table rather than starting a second order stream with its own numbering and statuses. The
name simply never caught up, and for a long time the rest of the codebase was already saying
`SalesOrderStatus` and `AbstractSalesDocument` while the table and entities said `AdminOrder`.

It was never an admin-only table. That was the whole problem: the name implied a scope restriction
that did not exist.

## What the rename touched

- **5 tables**, renamed via `ALTER TABLE … RENAME TO`. SQLite ≥3.25 rewrites the `REFERENCES`
  clauses of inbound foreign keys automatically — including the two from tables that were *not*
  renamed, `order_inventory_reservation.order_id` and `estimate.converted_order_id`.
- **5 indexes** whose names embedded `admin_order`. SQLite has no `ALTER INDEX`, so each was dropped
  and recreated against the renamed table. The Doctrine-hash index names (`IDX_55036862979B1AD6` and
  friends) don't contain the table name and were left alone.
- **~367 code references across 61 files** — `use` imports, `::class`, type hints, docblocks. All
  mechanical; a missed one is a hard fatal at boot rather than a silent bug.
- **13 raw SQL lines** in `DashboardController`, `OrderNumberGenerator`, and one command test.
- **`audit_log.entity_type`**, see below.
- **`bin/ci-migration-replay`**, see below.

## Two things that were *not* mechanical

### 1. Audit log history

`AuditLogSubscriber` derives the audit row's entity type by reflection:

```php
$entityType = (new \ReflectionClass($entity))->getShortName();
```

That short class name is **persisted as a string** into `audit_log.entity_type`, and it is
user-facing — the Audit Log screen searches and sorts on it. Without a data migration, order history
would split into two buckets: rows written before the rename saying `AdminOrder`, rows after saying
`SalesOrder`, with neither search finding the other. No refactoring tool catches this, because it is
data rather than code.

`Version20260731160000` therefore carries `UPDATE audit_log SET entity_type = …` for all five
classes. It happened to be a no-op at the time (no order rows had been audited yet), which is
exactly why the rename was done when it was — that is the only cost here that grows over time.

### 2. The CI migration-replay gate is name-phase-sensitive

`bin/ci-migration-replay` replays the whole chain against an empty database *with data in it*, to
catch migrations that silently destroy rows. It seeds rows **mid-chain**, before the destructive
migrations run — and at that point the table is still called `admin_order`, because the rename is
still ahead of it.

So that script deliberately uses **both** names:

- `REQUIRED_TABLES` and the `seedRow()` call use `admin_order` — the name at seed time. `CREATE TABLE
  sales_order` appears in no migration, so scanning for it would fail.
- The assertions after the full chain use `sales_order`.

If you ever rename these tables again, that distinction is the thing to get right.

## What deliberately still says `admin_order`

- **Route names** — `admin_order_index`, `admin_order_detail`, and ~165 others in Twig `path()`
  calls. These are URLs, not the table. Renaming them would change admin URLs and break bookmarks for
  no terminology gain.
- **The injection point `admin_order_detail_info`** — an identifier tied to the admin *screen*, not
  the entity. (The `admin_order_send_invoice_<id>` CSRF token id it used to be paired with is gone:
  #539 stage 6 moved sending an invoice onto the invoice, as `admin_invoice_send`.)
- **`BCTireAdminOrderDetailProvider`** (`modules/FeeBCTireBundle`) — its "Admin" means the admin-side
  injection point, paired with a `BCTireCustomerOrderDetailProvider` sibling. Renaming it would
  destroy that distinction.
- **Test classes** like `AdminSalesOrderFormCest`, `AdminSalesOrderDetailCest` — named after the admin order
  *screens* they exercise.
- **`migrations/`** — ~130 references across 20 files. These must never be rewritten: the chain has
  to keep creating `admin_order` and renaming it at `Version20260731160000`, or the replay gate
  breaks. `docs/plans/` and `docs/reviews/` are likewise dated records and were left as written.

**This is the grep trap.** A naive `grep admin_order` is dominated by route names and is not a useful
measure of anything. Anchor to SQL context instead:

```
grep -rnE "(FROM|JOIN|INTO|UPDATE|TABLE)[[:space:]]+admin_order" src/ modules/ templates/ tests/
```

## Known cosmetic drift

`doctrine:schema:validate`'s database half reports the schema as out of sync. **This pre-dates the
rename** — 194 statements on the commit before it — and is the SQLite comparator asking to redefine
tables identically.

The rename adds a small amount to that count for one reason: SQLite's `ALTER TABLE … RENAME TO`
writes the new name **quoted** into other tables' FK clauses (`REFERENCES "sales_order" (id)`), and
the comparator treats that as a difference. The foreign keys themselves are intact and correct —
`PRAGMA foreign_key_check` reports zero violations, and every inbound FK resolves to `sales_order`.

Rebuilding each table to get unquoted DDL would remove the noise, but table rebuilds are exactly the
pattern that previously caused silent data loss under SQLite (see the `bin/ci-migration-replay`
docblock). Not worth it for a cosmetic gain.
