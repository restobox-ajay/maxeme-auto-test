<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add invoice.shipping_status — the derived "have the goods gone" column behind the Invoices grid's
 * Shipping Status column.
 *
 * The counterpart of `invoice.payment_status`, which Version20260820150000 turned into a derived
 * column for the money side. Same width, same NOT NULL DEFAULT shape, same discipline about who
 * writes it: `App\Service\InvoiceShippingStatusDeriver` and nothing else.
 *
 * ## ADD-only, and why this is safe on a populated table
 *
 * `invoice` holds production rows, so this migration writes over nothing. `ALTER TABLE ... ADD
 * COLUMN` with a literal default is a metadata-only operation on SQLite — it does not rewrite
 * existing rows, and it goes nowhere near the table rebuild DROP COLUMN needs below SQLite 3.35
 * (docs/QUEUE.md: production is 3.26). No rebuild means no DROP TABLE, which means none of the ON
 * DELETE CASCADE hazards that made Version20260730150000 silently delete every row it had just
 * backfilled. `invoice_line`, `invoice_payment`, `invoice_log` and `shipment_line` all reference
 * this table or its children, and none of them is touched.
 *
 * NOT NULL DEFAULT 'Not Shipped' rather than nullable, for the reason `payment_status` is: the
 * column is mapped `enumType:`, so a NULL is a value the enum cannot hydrate and every grid render
 * would fail on the first legacy row. The default is what keeps existing rows USABLE rather than
 * merely present.
 *
 * ## No backfill, on purpose
 *
 * Every existing invoice lands on Not Shipped, including the Completed ones that plainly did ship.
 * Backfilling them is a data decision, not a schema one, and it is not this migration's to make:
 *
 *  - the Completed ones are easy — Completed is Shipped whatever is installed, which is the rule
 *    `InvoiceShippingStatusDeriver` reads before it asks any provider — but every invoice SHORT of
 *    Completed needs the per-line, per-non-void-shipment answer that only an active provider can
 *    give, and a migration cannot know whether one is installed and Active on this instance;
 *  - and no row stays wrong for long by itself. The column is derived: the next write to an
 *    invoice, or the next shipment recorded against one, puts it right through
 *    `InvoiceShippingStatusSubscriber`, exactly as `payment_status` re-derives.
 *
 * The corrective statement for an instance that wants its history restated at once is in the
 * branch's handover notes rather than here, unrun, so that it is a decision somebody takes rather
 * than one a deploy takes for them.
 *
 * ## Why the guards read PRAGMA rather than the injected Schema
 *
 * Touching the injected `Schema` makes DBAL introspect the whole database, which since
 * Version20260821160000 throws a TypeError out of `Index::_addColumn()` on
 * `uniq_inventory_detail`'s COALESCE terms. `$schema` stays in the signature because
 * `AbstractMigration` declares it, and is never read. The whole explanation, and this trait, live
 * on {@see \App\Doctrine\SqliteMigrationIntrospection}; Version20260917090000 is the worked example
 * that first wrote these guards out inline.
 */
final class Version20260920110000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Add invoice.shipping_status, the derived column behind the Invoices grid Shipping Status filter and sort.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('invoice') || $this->columnExists('invoice', 'shipping_status')) {
            return;
        }

        $this->addSql("ALTER TABLE invoice ADD COLUMN shipping_status VARCHAR(40) DEFAULT 'Not Shipped' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // Dropping a column needs SQLite 3.35+ and a table rebuild below that — a lot of machinery,
        // and a DROP TABLE against a table with cascading children, to undo a derived column that
        // does no harm sitting unread. Resetting it puts every row back on the starting point a
        // fresh install has, and the deriver restates each one on its next write. Version20260917090000's
        // down() makes the same call, for the same reason.
        if ($this->tableExists('invoice') && $this->columnExists('invoice', 'shipping_status')) {
            $this->addSql("UPDATE invoice SET shipping_status = 'Not Shipped'");
        }
    }
}
