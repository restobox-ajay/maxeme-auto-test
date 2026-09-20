<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #659 step 3: `unit_id` on the fourteen line-bearing tables — the denomination points at the
 * global unit table.
 *
 * ```
 * quantity_entered   what the human said        50        (added by #646, unchanged here)
 * unit_id            the unit they said it in   -> BOX-12  (NULL = the product's base unit)
 * <the base column>  what inventory reads       600
 * ```
 *
 * ## ADD only, and no UPDATE at all
 *
 * `unit_id` arrives NULL on every row, which means "entered in the product's base unit" — the same
 * encoding `packaging_unit_id` used and, before that, the state every row was already in. Nothing is
 * copied across from `packaging_unit_id`: there is no production data anywhere (the owner's ruling
 * on #659), every row in these tables is test or demo data, and a backfill that mapped rungs to
 * terms would be inventing per-product history the terms cannot express. The old column is dropped
 * in step 4 with nothing carried out of it.
 *
 * ## No FOREIGN KEY, deliberately
 *
 * The same decision `product_id` already carries on every one of these tables: a document line
 * snapshots what was bought or sold and has to outlive the deletion of things it names.
 *
 * `ON DELETE SET NULL` would be worse than either: with `quantity_entered` left at 50 it would
 * silently restate "50 BOX-12" as "50 base units", a factor-of-twelve change to a historical
 * document with nothing moved and nothing logged. The unit is protected where step 1 put the
 * protection — `App\Service\Uom\UnitOfMeasureService` refuses to restate or delete a referenced unit
 * — and this migration is what makes that guard bite on real document references rather than only on
 * a product's own declaration.
 *
 * ## SQLite floor stays 3.26
 *
 * `ADD COLUMN` only, each taking a NULL default, which is the form SQLite accepts on a table that
 * already has rows. No `DROP COLUMN` (3.35), no `ALTER COLUMN`, no rebuild — so `isTransactional()`
 * is left alone: there is nothing to cascade and therefore nothing needing
 * `PRAGMA foreign_keys = OFF`, which is silently ignored inside a transaction anyway.
 *
 * Numbered above everything claimed on any ref today; step 2 is `Version20260911235000`.
 */
final class Version20260911235200 extends AbstractMigration
{
    /**
     * The fourteen tables, listed rather than swept.
     *
     * A migration must say what it did to a database that no longer matches the mappings — replaying
     * this chain in three years has to produce the same fourteen columns whatever the entities look
     * like by then. `Version20260911090000` lists the same fourteen for the same reason.
     *
     * @var array<string, string> table => the index name for its new column
     */
    private const LINE_TABLES = [
        // Core sales documents.
        'sales_order_line' => 'idx_sales_order_line_unit',
        'invoice_line' => 'idx_invoice_line_unit',
        'estimate_line' => 'idx_estimate_line_unit',
        'credit_memo_line' => 'idx_credit_memo_line_unit',
        'sales_return_line' => 'idx_sales_return_line_unit',
        'cart_item' => 'idx_cart_item_unit',
        // ProcurementBundle — the buy side.
        'purchase_order_line' => 'idx_po_line_unit',
        'goods_receipt_line' => 'idx_receipt_line_unit',
        'vendor_bill_line' => 'idx_bill_line_unit',
        'rfq_line' => 'idx_rfq_line_unit',
        'debit_memo_line' => 'idx_debit_memo_line_unit',
        'vendor_return_line' => 'idx_vendor_return_line_unit',
        // WarehouseOpsBundle — moving and picking.
        'transfer_order_line' => 'idx_transfer_order_line_unit',
        'pick_task' => 'idx_pick_task_unit',
    ];

    public function getDescription(): string
    {
        return '#659 step 3: unit_id on 14 line-bearing tables, pointing at unit_of_measure. ADD only, no backfill.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::LINE_TABLES as $table => $index) {
            // A table whose bundle was never installed simply has no row here to alter. Skipping is
            // not defensive vagueness: modules/ProcurementBundle and modules/WarehouseOpsBundle are
            // optional, and bin/ci-bundles-off runs the suite with them off.
            if (!$this->tableExists($table)) {
                continue;
            }

            // No FOREIGN KEY — see the class docblock.
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN unit_id INTEGER DEFAULT NULL', $table));

            // Indexed because the freeze guard asks "what points at this unit?" once per referencing
            // column, and the Units of Measure list screen asks it for a whole page at once
            // (UnitOfMeasureService::referenceCountsForPage()). Without an index that is fourteen
            // full table scans of the document lines every time somebody opens the screen.
            $this->addSql(sprintf('CREATE INDEX %s ON %s (unit_id)', $index, $table));
        }
    }

    /**
     * Drops the indexes and leaves the columns, for the reason `Version20260911090000::down()` gives.
     *
     * SQLite below 3.35 can only drop a column by rebuilding the table, and a rebuild here would be
     * the create-copy-drop-recreate that has already destroyed data once in this chain — to undo an
     * addition that costs nothing to leave. The columns are inert without the mappings above them.
     */
    public function down(Schema $schema): void
    {
        foreach (self::LINE_TABLES as $table => $index) {
            if ($this->tableExists($table)) {
                $this->addSql(sprintf('DROP INDEX IF EXISTS %s', $index));
            }
        }
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
        ) > 0;
    }
}
