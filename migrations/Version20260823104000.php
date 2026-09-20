<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The durable backorder queue (#548).
 *
 * One row per backorder episode: opened when a line first goes short, closed when it is whole
 * again. It exists because the line forgets — once `sales_order_line.backordered_quantity` returns
 * to zero nothing in the schema remembers it was ever short — and the queue screen has to show a
 * released episode next to a waiting one, an automatically-released one especially, since no human
 * ever saw that one open.
 *
 * ## The foreign keys, and the one that is deliberately here
 *
 * `product_id` is a real foreign key with ON DELETE CASCADE, which `sales_order_line` pointedly
 * does not have. The difference is what the row IS: an order line is a snapshot of something that
 * was sold and must outlive the product's deletion (this database holds two thousand of them
 * naming products that are gone), while this is live queue state, and a queue entry for a product
 * that no longer exists is not a state worth keeping. Both reservation ledgers make the same
 * choice for the same reason.
 *
 * Nothing is backfilled. Every `backordered_quantity` in the database is 0.00 — the column that
 * holds it was created two migrations ago and defaulted — so there is no episode in existence for
 * this table to record, and inventing one would be recording a fact the lines do not state.
 */
final class Version20260823104000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#548: the backorder_fulfillment_entry queue table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE backorder_fulfillment_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(20) DEFAULT \'Open\' NOT NULL, created_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, order_id INTEGER NOT NULL, line_id INTEGER NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER DEFAULT NULL, CONSTRAINT FK_B55B0B828D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B55B0B824D7B7542 FOREIGN KEY (line_id) REFERENCES sales_order_line (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B55B0B824584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B55B0B825080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_B55B0B828D9F6D38 ON backorder_fulfillment_entry (order_id)');
        $this->addSql('CREATE INDEX IDX_B55B0B824584665A ON backorder_fulfillment_entry (product_id)');
        $this->addSql('CREATE INDEX IDX_B55B0B825080ECDE ON backorder_fulfillment_entry (warehouse_id)');
        // The two lookups every read of this table makes: the queue listing filters on status, and
        // both the release paths and the queue synchroniser ask "is there an open episode for this
        // line" on every write to an order.
        $this->addSql('CREATE INDEX idx_backorder_entry_status ON backorder_fulfillment_entry (status)');
        $this->addSql('CREATE INDEX idx_backorder_entry_line ON backorder_fulfillment_entry (line_id)');
    }

    public function down(Schema $schema): void
    {
        // Nothing references it, so this cascades to nothing.
        $this->addSql('DROP TABLE backorder_fulfillment_entry');
    }
}
