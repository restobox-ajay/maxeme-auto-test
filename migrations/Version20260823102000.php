<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-line fulfilment tracking on sales_order_line (#548).
 *
 * The line had none at all: it carried what was ordered and nothing about whether any of it could
 * be sent. Three columns, landing together because two of them are one fact — `fulfillment_status`
 * is a cache of what `backordered_quantity` and `quantity` say, recomputed on every write by
 * SalesOrderLine::setBackorderedQuantity(), and shipping the cache without the number it caches
 * would leave a column nothing can recompute.
 *
 * `backordered_quantity` matches `quantity`'s type and precision because it is a partial split of
 * it. `'Fulfilled'` is the right default for every existing row for the same reason 0.00 is: no row
 * that predates this has any part of it waiting for stock.
 *
 * ## No foreign key here, still
 *
 * Nothing in this migration adds one, and specifically nothing adds one to `product_core`. A
 * document line snapshots what was sold and has to outlive the product's deletion — product
 * imports delete rows routinely, and this database holds two thousand order lines naming products
 * that no longer exist, each a correct record of something that was sold. `sales_order_line` has no
 * product foreign key by design, and this feature does not give it one; the queue table that DOES
 * reference the product (backorder_fulfillment_entry, two migrations later) is live state rather
 * than a snapshot, which is why it can afford to cascade.
 */
final class Version20260823102000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#548: backordered quantity, fulfilment status and restock ETA on sales_order_line.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sales_order_line ADD COLUMN backordered_quantity NUMERIC(12, 2) DEFAULT '0.00' NOT NULL");
        $this->addSql("ALTER TABLE sales_order_line ADD COLUMN fulfillment_status VARCHAR(32) DEFAULT 'Fulfilled' NOT NULL");
        $this->addSql('ALTER TABLE sales_order_line ADD COLUMN restock_eta DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite cannot drop a column at the version this app supports, so this is the usual
        // create-copy-drop-rename. sales_order_line IS referenced — invoice_line.sales_order_line_id
        // points at it — and that reference is ON DELETE SET NULL, so dropping the table here would
        // quietly detach every invoice line from the order line it bills. So the child is repointed
        // by hand: the copy preserves ids, and the reference is restored afterwards by rebuilding
        // nothing at all, because SQLite's legacy_alter_table behaviour rewrites the REFERENCES
        // clause on RENAME to follow the table.
        $this->addSql('CREATE TABLE sales_order_line_pre548 (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, location VARCHAR(120) DEFAULT NULL, sku VARCHAR(80) DEFAULT NULL, quantity NUMERIC(12, 2) NOT NULL, weight VARCHAR(80) DEFAULT NULL, unit VARCHAR(80) DEFAULT NULL, tax_code VARCHAR(80) DEFAULT NULL, cost NUMERIC(12, 2) NOT NULL, price NUMERIC(12, 2) NOT NULL, subtotal NUMERIC(12, 2) NOT NULL, batch VARCHAR(120) DEFAULT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, order_id INTEGER NOT NULL, product_id INTEGER DEFAULT NULL, CONSTRAINT FK_9CE58EE18D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO sales_order_line_pre548 (id, name, location, sku, quantity, weight, unit, tax_code, cost, price, subtotal, batch, sort_order, order_id, product_id) SELECT id, name, location, sku, quantity, weight, unit, tax_code, cost, price, subtotal, batch, sort_order, order_id, product_id FROM sales_order_line');
        $this->addSql('DROP TABLE sales_order_line');
        $this->addSql('ALTER TABLE sales_order_line_pre548 RENAME TO sales_order_line');
        $this->addSql('CREATE INDEX IDX_9CE58EE18D9F6D38 ON sales_order_line (order_id)');
        $this->addSql('CREATE INDEX IDX_9CE58EE14584665A ON sales_order_line (product_id)');
    }

    /**
     * The down() above drops a table that another table's foreign key points at, and
     * `PRAGMA foreign_keys = OFF` is silently ignored inside a transaction — the exact hazard
     * bin/ci-migration-replay was written to catch, where a rebuild reported success while
     * cascading away the rows it had just copied.
     */
    public function isTransactional(): bool
    {
        return false;
    }
}
