<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Delete;

use Doctrine\ORM\EntityManagerInterface;

/**
 * What is still pointing at a bin or a lot, so a delete can refuse instead of stranding it (#613).
 *
 * ## Why this exists at all
 *
 * Every column that names a `warehouse_location` or an `inventory_lot` is `ON DELETE SET NULL`.
 * That is right — losing a bin must never delete the record that goods arrived — and it is exactly
 * why a delete cannot simply be issued and left to the database: SET NULL succeeds silently and
 * turns a detail row standing in A-01-02 into a detail row standing nowhere, which is the one state
 * #550 says a cycle count cannot answer. The refusal has to happen before the DELETE, and it has to
 * name what is holding the row.
 *
 * The same reasoning is already written down twice in this bundle — BinController's "bins are never
 * deleted, only deactivated", InventoryLot's "a lot's identity is its row" — and neither is weakened
 * here. A bin or a lot that ANYTHING points at still cannot be deleted; closing the bin remains the
 * answer for one that has held stock. What this makes possible is the one case those docblocks never
 * covered: the row created by mistake that nothing has ever referred to. Two hundred bins bulk-created
 * under a typo'd prefix are two hundred rows of permanent litter today.
 *
 * ## Raw SQL, and a table-exists guard on every query
 *
 * `goods_receipt_line`, `pick_list`, `pick_task` and `transfer_order_line` belong to
 * ProcurementBundle and WarehouseOpsBundle. Those bundles depend on this one; importing their
 * entities here would invert that, and this bundle has to keep working when neither is installed.
 * So each table is checked for existence first and queried by name — the same device
 * VendorController uses to read core's `payment_term` without mapping an entity onto it.
 *
 * `inventory_movement` is deliberately absent from both lists. It names no bin and no lot: it points
 * at `inventory_detail` rows, which carry both dimensions, so a bin or lot any movement ever touched
 * necessarily still has a detail row and is already refused by the first entry in each list.
 */
final class ReferenceCounter
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Rows still naming this `warehouse_location`, keyed by the table they are in.
     *
     * @return array<string, int> only tables with at least one row; empty means nothing points at it
     */
    public function holdersOfBin(int $binId): array
    {
        return $this->count([
            'inventory_detail' => 'location_id',
            'goods_receipt_line' => 'location_id',
            'pick_list' => 'staging_location_id',
            'pick_task' => 'suggested_location_id',
        ], $binId);
    }

    /**
     * Rows still naming this `inventory_lot`, keyed by the table they are in.
     *
     * @return array<string, int> only tables with at least one row; empty means nothing points at it
     */
    public function holdersOfLot(int $lotId): array
    {
        return $this->count([
            'inventory_detail' => 'lot_id',
            'goods_receipt_line' => 'lot_id',
            'transfer_order_line' => 'lot_id',
        ], $lotId);
    }

    /**
     * One sentence naming every table and how many rows it holds, for the refusal flash.
     *
     * `table.column` on purpose: whoever reads this refusal has to go and find those rows, and the
     * table name is the only part of the answer that survives being read by somebody who does not
     * know which screen shows them.
     *
     * @param array<string, int> $holders
     */
    public static function describe(array $holders): string
    {
        $parts = [];
        foreach ($holders as $table => $rows) {
            $parts[] = sprintf('%d row(s) in %s', $rows, $table);
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, string> $columnsByTable
     *
     * @return array<string, int>
     */
    private function count(array $columnsByTable, int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        $connection = $this->em->getConnection();
        $schema = $connection->createSchemaManager();

        $holders = [];
        foreach ($columnsByTable as $table => $column) {
            // The bundle that owns this table may not be installed. An absent table holds nothing,
            // which is a real answer and not a failure.
            if (!$schema->tablesExist([$table])) {
                continue;
            }

            $rows = (int) $connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE %s = :id', $table, $column),
                ['id' => $id],
            );

            if ($rows > 0) {
                $holders[$table] = $rows;
            }
        }

        return $holders;
    }
}
