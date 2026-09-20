<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill `product_inventory.transfer_out_quantity` from the in-transit rows already there (#574).
 *
 * Version20260830100000 added the column defaulting to 0 and left it at that, on the assumption
 * that `StockMovementService::syncCoreTotal()` recomputes it. It does — but only for the (product,
 * warehouse) pairs a subsequent movement happens to touch. Stock dispatched before the column
 * existed sits in `in_transit` detail rows with a bucket of 0 behind it, so it stays sellable at the
 * source until something unrelated moves that product.
 *
 * Found on the dev database immediately after the first migration: three dispatched transfers, 15
 * units of in-transit detail, and a bucket reading 5. The ten-unit gap was exactly the pairs no
 * movement had touched since.
 *
 * That is the rule about migrations not leaving state the application would recompute, broken by
 * the migration that introduced the column. The fix is to compute it once here, from the same rows
 * syncCoreTotal() reads, so the two agree from the moment the column exists rather than converging
 * whenever a warehouse next happens to move something.
 */
final class Version20260830110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#574: backfill transfer_out_quantity from existing in-transit detail rows.';
    }

    public function up(Schema $schema): void
    {
        // Correlated rather than a join, because a ProductInventory row may have no in-transit rows
        // at all and must land on 0 rather than being skipped.
        $this->addSql(<<<'SQL'
            UPDATE product_inventory
            SET transfer_out_quantity = COALESCE((
                SELECT SUM(d.quantity)
                FROM inventory_detail d
                WHERE d.product_id = product_inventory.product_id
                  AND d.warehouse_id = product_inventory.warehouse_id
                  AND d.status = 'in_transit'
            ), 0)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to restore. The previous value was 0 for every row the application had not
        // touched yet, which is the state this exists to correct, and re-zeroing would put the
        // stock back on sale.
    }
}
