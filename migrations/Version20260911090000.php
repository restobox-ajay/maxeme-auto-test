<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Units of measure, phase 3: base-unit storage on document and pick lines (#601, #646).
 *
 * ```
 * quantity_entered    what the human said               50
 * packaging_unit_id   the unit they said it in          -> Case   (NULL = the base unit)
 * <the column already there>                            600       the only figure inventory sees
 * ```
 *
 * 28 columns across 14 tables, and **nothing is renamed, nothing is rebuilt, nothing is written**.
 *
 * ## The existing quantity column IS the base figure
 *
 * `sales_order_line.quantity` was always denominated in the product's base unit — there was only
 * ever one unit down there, which is the whole reason #601's design costs nothing to adopt. So this
 * migration does not rename it to `quantity_base`, and #646 says so outright. Reusing it keeps every
 * reader of that column correct by construction: `InventoryReservationSubject`, `StockMovementService`,
 * `PickConfirmationService` and the reconciler go on reading the same column, of the same type, and
 * therefore go on seeing base units and only base units.
 *
 * A rename would have been a SQLite table rebuild on fourteen tables — the create-copy-drop-recreate
 * that cascaded away every address snapshot in Version20260730150000 and reported success. This is
 * fourteen `ADD COLUMN`s instead.
 *
 * ## No UPDATE, and existing rows still read correctly
 *
 * Both new columns arrive NULL on every row, and there is no backfill because NULL already says the
 * true thing:
 *
 *   - `packaging_unit_id IS NULL` means "entered in the product's base unit". Every row that exists
 *     was entered that way — there has never been a unit selector — so NULL is the accurate value,
 *     not a placeholder.
 *   - `quantity_entered IS NULL` therefore means "the entered figure is the base figure", and
 *     App\Entity\PackagedQuantity::getQuantityEntered() reads it back through that identity rather
 *     than through a stored copy.
 *
 * The alternative — `UPDATE sales_order_line SET quantity_entered = quantity` — would write every
 * row of fourteen tables to record a number that is already there, and would leave two columns
 * holding one figure with two chances to disagree. `bin/ci-migration-replay` and the branch's
 * before/after content checksums both hold precisely because no `UPDATE` runs.
 *
 * ## No FOREIGN KEY on packaging_unit_id
 *
 * Deliberate, and the same decision `product_id` already carries on every one of these tables — see
 * the "No foreign key to product_core, deliberately" note on ProcurementBundle\Entity\PurchaseOrderLine.
 * A document line snapshots what was bought or sold and has to outlive the deletion of the product;
 * `product_packaging_unit.product_id` cascades from the product, so an FK here would turn a routine
 * import deletion into a constraint violation on a document written three years ago.
 *
 * `ON DELETE SET NULL` would be worse than either: with `quantity_entered` left at 50 it would
 * silently restate "50 Cases" as "50 base units", a factor-of-twelve change to a historical document
 * with nothing moved and nothing logged. The rung is protected where phase 1 put the protection —
 * App\Service\Uom\ProductPackagingUnitService refuses to delete or edit a referenced rung — and this
 * migration is what makes that guard bite on real document references rather than only on
 * `product_packaging_unit.parent_id`.
 *
 * ## SQLite floor stays 3.26
 *
 * `ADD COLUMN` only, each taking a NULL default, which is the form SQLite accepts on a table that
 * already has rows. No `DROP COLUMN` (3.35), no `ALTER COLUMN`, no rebuild — so `isTransactional()`
 * is left alone: there is nothing to cascade and therefore nothing that needs
 * `PRAGMA foreign_keys = OFF`, which is silently ignored inside a transaction anyway.
 *
 * ## The fourteen tables, and the three #646 did not name
 *
 * #646 lists eleven, one of which (`purchase_receipt_line`) is the name `goods_receipt_line` had
 * when the issue was written. The three added here landed after it (#637, #638):
 *
 *   - `vendor_return_line` — the buy-side mirror of `sales_return_line`, and it MOVES STOCK
 *     (VendorReturnShipService). Leaving it out would be the one remaining path by which a packaging
 *     figure could reach the movement layer unconverted in phase 4.
 *   - `debit_memo_line` — the buy-side mirror of `credit_memo_line`, which is on #646's list. #601
 *     names credit memos and returns explicitly: a partial return of loose units against a
 *     case-denominated bill is otherwise unreconcilable.
 *   - `rfq_line` — a document line a person types a product and a quantity into. #601's rule for
 *     documents is that they record what was said, not only what it resolved to.
 *
 * `rfq_vendor_reply_line` is deliberately left out: it has no product and no quantity of its own. It
 * prices somebody else's requirement, and the quantity it is priced against is `rfq_line`'s. Two
 * places stating one figure is how they come to disagree.
 *
 * The inventory layer — `product_inventory`, `inventory_detail`, `inventory_movement`,
 * `order_inventory_reservation`, `invoice_inventory_reservation` — gets NOTHING, which is the
 * property #646 exists to protect and the one the branch asserts hardest.
 */
final class Version20260911090000 extends AbstractMigration
{
    /**
     * table => the column the entered figure resolves INTO, which is the one already there.
     *
     * Listed rather than swept because a migration must say what it did to a database that no longer
     * matches the mappings — replaying this chain in three years has to produce the same 28 columns
     * whatever the entities look like by then.
     *
     * @var array<string, string>
     */
    private const LINE_TABLES = [
        // Core sales documents.
        'sales_order_line' => 'quantity',
        'invoice_line' => 'quantity',
        'estimate_line' => 'quantity',
        'credit_memo_line' => 'quantity',
        'sales_return_line' => 'quantity',
        'cart_item' => 'quantity',
        // ProcurementBundle — the buy side.
        'purchase_order_line' => 'quantity_ordered',
        'goods_receipt_line' => 'quantity',
        'vendor_bill_line' => 'quantity',
        'rfq_line' => 'quantity',
        'debit_memo_line' => 'quantity',
        'vendor_return_line' => 'quantity',
        // WarehouseOpsBundle — moving and picking.
        'transfer_order_line' => 'quantity_requested',
        'pick_task' => 'quantity_requested',
    ];

    /**
     * Index names, one per table, so the immutability guard's COUNT is a lookup and not a scan.
     *
     * Phase 1's reference sweep asks "what points at this rung?" once per referencing column, and
     * this migration takes that from one column to fifteen. The packaging-ladder list screen asks it
     * for a whole page at once (ProductPackagingUnitService::referenceCountsForPage()); without an
     * index that is fourteen full table scans of the document lines every time somebody opens it.
     *
     * @var array<string, string>
     */
    private const INDEXES = [
        'sales_order_line' => 'idx_sales_order_line_packaging_unit',
        'invoice_line' => 'idx_invoice_line_packaging_unit',
        'estimate_line' => 'idx_estimate_line_packaging_unit',
        'credit_memo_line' => 'idx_credit_memo_line_packaging_unit',
        'sales_return_line' => 'idx_sales_return_line_packaging_unit',
        'cart_item' => 'idx_cart_item_packaging_unit',
        'purchase_order_line' => 'idx_po_line_packaging_unit',
        'goods_receipt_line' => 'idx_receipt_line_packaging_unit',
        'vendor_bill_line' => 'idx_bill_line_packaging_unit',
        'rfq_line' => 'idx_rfq_line_packaging_unit',
        'debit_memo_line' => 'idx_debit_memo_line_packaging_unit',
        'vendor_return_line' => 'idx_vendor_return_line_packaging_unit',
        'transfer_order_line' => 'idx_transfer_order_line_packaging_unit',
        'pick_task' => 'idx_pick_task_packaging_unit',
    ];

    public function getDescription(): string
    {
        return 'UoM phase 3 (#646): quantity_entered and packaging_unit_id on 14 line-bearing tables; the existing quantity column becomes the base figure. ADD only.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::LINE_TABLES as $table => $baseColumn) {
            // A table whose bundle was never installed simply has no row here to alter. Skipping is
            // not defensive vagueness: modules/ProcurementBundle and modules/WarehouseOpsBundle are
            // optional, and bin/ci-bundles-off runs the suite with them off.
            if (!$this->tableExists($table)) {
                continue;
            }

            $this->abortIf(
                !$this->columnExists($table, $baseColumn),
                sprintf('%s has no %s column to use as its base figure — the mapping and this migration disagree.', $table, $baseColumn),
            );

            // NUMERIC(14, 4) and NULL: the scale #601 rules for every quantity, and the only default
            // SQLite accepts on ADD COLUMN against a table that already has rows. NULL is the value
            // that makes every existing row read as "entered in base units" without one being written.
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN quantity_entered NUMERIC(14, 4) DEFAULT NULL', $table));

            // No FOREIGN KEY — see the class docblock.
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN packaging_unit_id INTEGER DEFAULT NULL', $table));

            $this->addSql(sprintf('CREATE INDEX %s ON %s (packaging_unit_id)', self::INDEXES[$table], $table));
        }
    }

    /**
     * Drops the 28 columns again — which SQLite can only do below 3.35 by rebuilding the table.
     *
     * So it does not: `down()` drops the indexes and leaves the columns in place, NULL and unread.
     * A rebuild here would be the create-copy-drop-recreate that has already destroyed data once in
     * this chain, and it would be doing it to undo an addition that costs nothing to leave. The
     * columns are inert without the mappings above them.
     */
    public function down(Schema $schema): void
    {
        foreach (self::INDEXES as $table => $index) {
            if ($this->tableExists($table)) {
                $this->addSql(sprintf('DROP INDEX IF EXISTS %s', $index));
            }
        }
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        foreach ($this->connection->fetchAllAssociative(sprintf('PRAGMA table_info(%s)', $table)) as $row) {
            if ($row['name'] === $column) {
                return true;
            }
        }

        return false;
    }
}
