<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #326 warn-and-override: the record of a document line deliberately sold beyond the shelf.
 *
 * Two new tables and nothing else:
 *
 *   sales_order_line_stock_override   NEW TABLE
 *   invoice_line_stock_override       NEW TABLE
 *
 * ## ADD only, and it means it
 *
 * `docs/QUEUE.md`'s ADD-only rule is in force and nothing here goes near it. No column is dropped,
 * widened or renamed, no DEFAULT is added to an existing table, and **not one byte is written to
 * any row that already exists** — no backfill, no repair UPDATE. Two CREATE TABLEs and four
 * indexes. The sell-side tables this feature reads (`sales_order_line`, `invoice_line`,
 * `product_inventory`) are in the DEPLOYED application and are not touched at all.
 *
 * ## Why two tables rather than one with a nullable pair of keys
 *
 * The shape `App\Entity\InventoryReservation` states and this codebase applies everywhere: lines,
 * addresses and logs all take a concrete class per document subtype. One table with a nullable
 * `order_line_id` and a nullable `invoice_line_id` needs a CHECK constraint to express "exactly one
 * of these is set" that two tables get from `NOT NULL` for free — and SQLite 3.26 would carry that
 * constraint in the table definition, so changing it later would mean the table rebuild this
 * project avoids on principle.
 *
 * ## What is deliberately NOT a column
 *
 * The shortfall. It is `requested_quantity − available_quantity − backorder_capacity` and all three
 * of those are stored, so a fourth column would be one fact in two places — the shape behind #589,
 * #590, #591. `SalesOrderLineStockOverride::getShortfallQuantity()` derives it, with the clamps that
 * make it agree exactly with `BackorderSplit::$uncovered`, which is the figure the operator was
 * shown.
 *
 * ## available_quantity is SIGNED, on purpose
 *
 * A second override against a SKU that is already oversold measures a NEGATIVE availability, and
 * that is the honest record of how far past the shelf this one went. Same decision as
 * `procurement_short_dated_receipt.remaining_days`, which is signed so a pallet that arrived already
 * expired reads negative instead of clamping to zero and losing how bad it was.
 *
 * ## The foreign keys
 *
 * Real keys, ON DELETE CASCADE, to rows this application owns: the override is a fact ABOUT that
 * line and is meaningless without it. Cascade rather than SET NULL because there is no such thing as
 * an override of nothing — contrast `invoice_line.sales_order_line_id`, which is SET NULL precisely
 * because losing an attribution must never delete the record of goods that were actually billed.
 *
 * `UNIQUE(order_line_id)` and `UNIQUE(invoice_line_id)`: a line is one product in one region and can
 * therefore be short by one amount. A line saved again and still short UPDATES its row — the
 * decision is re-taken against the new figures — so two rows about one line would be two answers to
 * one question. The index is what makes that unrepresentable rather than merely unwritten.
 *
 * ## SQLite 3.26
 *
 * `CREATE TABLE` and `CREATE INDEX` need nothing modern. No `DROP COLUMN` (3.35), no `RETURNING`
 * (3.35), no generated column (3.31), no `IIF()` (3.32). Nothing here rebuilds a table, so
 * `App\Doctrine\SqliteTableRebuild` is not involved and the `PRAGMA foreign_keys` hazard that made
 * `isTransactional(): false` necessary in `Version20260730150000` does not arise.
 *
 * ## The stamp
 *
 * `Version20260917153000` is above every stamp claimed on any ref, tag, working tree or reflog at
 * the time of writing — the highest found anywhere was `Version20260916104500`. Deliberately off the
 * daily `090000` pattern for the reason `Version20260912161500` gives: two agents reaching for
 * "tomorrow at 09:00" is how two branches collide on one stamp and silently drop each other's
 * tables, and neither suite would see it — both build their schema from Doctrine metadata and never
 * run the chain, so only `bin/ci-migration-replay` ever would.
 */
final class Version20260917153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#326: record a sales order or invoice line deliberately sold beyond available stock.';
    }

    public function up(Schema $schema): void
    {
        // One sales order line sold beyond what could cover it, and why. Written the moment an
        // operator explains a shortfall, and it SNAPSHOTS what they were looking at rather than
        // pointing at `product_inventory` — availability moves every time anything sells, and this
        // row has to go on saying what the decision was actually measured against.
        $this->addSql(<<<'SQL'
            CREATE TABLE sales_order_line_stock_override (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                order_line_id INTEGER NOT NULL,
                region_name VARCHAR(120) NOT NULL,
                requested_quantity INTEGER NOT NULL,
                available_quantity INTEGER NOT NULL,
                backorder_capacity INTEGER NOT NULL,
                reason VARCHAR(255) NOT NULL,
                overridden_by VARCHAR(180) DEFAULT NULL,
                overridden_at DATETIME NOT NULL,
                CONSTRAINT FK_stock_override_order_line FOREIGN KEY (order_line_id)
                    REFERENCES sales_order_line (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_stock_override_order_line ON sales_order_line_stock_override (order_line_id)');
        $this->addSql('CREATE INDEX idx_stock_override_order_line_at ON sales_order_line_stock_override (overridden_at)');

        // The same, for a standalone invoice line. `backorder_capacity` is carried here too and is
        // always 0: capacity is never cover on an invoice — an invoice line has no split to fall
        // back on — so the column says "nothing but stock stood behind this", which is a statement
        // rather than an omission, and it keeps the two tables one shape for one recorder to write.
        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_line_stock_override (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                invoice_line_id INTEGER NOT NULL,
                region_name VARCHAR(120) NOT NULL,
                requested_quantity INTEGER NOT NULL,
                available_quantity INTEGER NOT NULL,
                backorder_capacity INTEGER NOT NULL,
                reason VARCHAR(255) NOT NULL,
                overridden_by VARCHAR(180) DEFAULT NULL,
                overridden_at DATETIME NOT NULL,
                CONSTRAINT FK_stock_override_invoice_line FOREIGN KEY (invoice_line_id)
                    REFERENCES invoice_line (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_stock_override_invoice_line ON invoice_line_stock_override (invoice_line_id)');
        $this->addSql('CREATE INDEX idx_stock_override_invoice_line_at ON invoice_line_stock_override (overridden_at)');
    }

    /**
     * Drops both tables and touches nothing else.
     *
     * Safe to run, unlike most downs in this chain: every row in these tables is a record this
     * feature created, nothing else references them, and no existing table was altered on the way
     * up, so there is nothing to rebuild and nothing to restore.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_stock_override_invoice_line_at');
        $this->addSql('DROP INDEX IF EXISTS uniq_stock_override_invoice_line');
        $this->addSql('DROP TABLE IF EXISTS invoice_line_stock_override');
        $this->addSql('DROP INDEX IF EXISTS idx_stock_override_order_line_at');
        $this->addSql('DROP INDEX IF EXISTS uniq_stock_override_order_line');
        $this->addSql('DROP TABLE IF EXISTS sales_order_line_stock_override');
    }
}
