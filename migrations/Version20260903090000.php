<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `inventory_reorder_rule` — a reorder level per product per warehouse (#597). One new table. Nothing else.
 *
 * ## NO BACKFILL. NOT ONE ROW IS READ AND NOT ONE IS WRITTEN.
 *
 * The table is created EMPTY and stays empty until an admin types a level into
 * `/admin/bundles/inventory-depth/low-stock`. A database that runs this migration and stops computes
 * exactly the numbers it computed before, and its low-stock screen lists nothing.
 *
 * That is the point, not an omission. #597 is explicit:
 *
 *   - "**Nullable, and null must mean unmanaged.** Zero is a real reorder point (order when you hit
 *     nothing left); it is not the same as 'nobody has set one'. A default of 0 would put all 400
 *     products on the low-stock screen the day it ships."
 *
 * Here the unmanaged state is the ABSENCE OF A ROW rather than a NULL column, which says the same
 * thing with one fewer way to get it wrong — there is no row for a 0 to be written into by accident.
 *
 * Nor could a level be inferred. A reorder point is a commercial decision about how much money to
 * tie up in a shelf: it is a function of lead time, of what the vendor's minimum order is, of how
 * badly a stockout hurts, and of how much cash the buyer has this month. None of that is in this
 * database. Sales history is not a substitute — it would produce a number that looks like somebody's
 * decision and is nobody's, on 400 products at once, and the first person to trust one would be
 * ordering against it.
 *
 * ## Why a new table rather than columns on `product_inventory`
 *
 * `product_inventory` is core. Its columns are core's to render, migrate and interpret, and a
 * reorder level is not core's concern — nothing in core reorders anything. The precedent is
 * `procurement_product_rule` (Version20260826120000), which is procurement's policy about a product
 * and lives in procurement's table for exactly the same two reasons: core must be able to read
 * nothing the bundle writes, and the bundle must stay deletable.
 *
 * The grain is identical to `product_inventory`'s own — one row per (product_id, warehouse_id),
 * enforced here by `uniq_inventory_reorder_rule` as it is there by
 * `uniq_product_inventory_product_location`.
 *
 * ## CREATE TABLE, once, and no table is rebuilt
 *
 * A new table touches nothing that exists. In particular this is NOT a Doctrine-emulated column
 * change on `product_inventory`, which under SQLite would be a rebuild through a temp copy — a DROP
 * TABLE that cascades, the failure Version20260730150000 shipped once already while reporting
 * success (see Version20260902090000's docblock for the full account).
 *
 * Both foreign keys cascade, matching the entity mapping: this is live configuration about a product
 * and a warehouse that currently exist, not a snapshot of something that happened. A level for a
 * deleted product is meaningless rather than historical.
 */
final class Version20260903090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#597: add inventory_reorder_rule (product, warehouse, level). Created empty — no level is ever inferred.';
    }

    public function up(Schema $schema): void
    {
        // `reorder_point` is NOT NULL and carries no default: the row's existence is what says
        // "managed", so the column has no second state to hold. 0 is a real level and is reachable
        // only by typing it.
        //
        // `reorder_quantity` and `safety_stock_quantity` ARE nullable, and NULL is meaningful in
        // both: no standing order quantity (suggest the shortfall instead), and no second urgency
        // band. A 0 in either would be a decision nobody made.
        $this->addSql(
            'CREATE TABLE inventory_reorder_rule ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'product_id INTEGER NOT NULL, '
            . 'warehouse_id INTEGER NOT NULL, '
            . 'reorder_point INTEGER NOT NULL, '
            . 'reorder_quantity INTEGER DEFAULT NULL, '
            . 'safety_stock_quantity INTEGER DEFAULT NULL, '
            . 'CONSTRAINT FK_inventory_reorder_rule_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, '
            . 'CONSTRAINT FK_inventory_reorder_rule_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );

        // One pair, one level. Without this a second row for the same pair would be two answers to
        // one question, and which one the screen used would depend on row order.
        $this->addSql('CREATE UNIQUE INDEX uniq_inventory_reorder_rule ON inventory_reorder_rule (product_id, warehouse_id)');

        // The unique index already covers product_id; the warehouse filter on the screen is the
        // other way round and would scan without this.
        $this->addSql('CREATE INDEX IDX_inventory_reorder_rule_warehouse ON inventory_reorder_rule (warehouse_id)');
    }

    /**
     * Safe to reverse: this table is created by this migration and nothing outside it references
     * the table, so there is no cascade to get wrong and nothing that existed beforehand to lose.
     *
     * Levels typed since it ran DO go — they are this feature's own data, and rolling the feature
     * back is rolling them back.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inventory_reorder_rule');
    }
}
