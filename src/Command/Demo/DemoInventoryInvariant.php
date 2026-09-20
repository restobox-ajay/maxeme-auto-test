<?php

declare(strict_types=1);

namespace App\Command\Demo;

use Doctrine\DBAL\Connection;

/**
 * The one arithmetic statement that decides whether seeded stock is real or merely looks real.
 *
 * ```
 * SUM(inventory_detail.quantity WHERE status = 'available')
 *   == product_inventory.quantity
 *      + received_quantity + transfer_in_quantity
 *      - transfer_out_quantity - write_off_quantity - quarantine_quantity
 *      - SUM(inventory_detail.quantity WHERE status = 'sold')
 * ```
 *
 * ## Why the seeders assert it themselves
 *
 * Because this is the exact failure a demo database is prone to and screens cannot show. Every
 * inventory screen renders one side or the other — the bin list reads `inventory_detail`, the
 * inventory grid reads `product_inventory` — so a seeder that INSERTed detail rows directly would
 * produce a perfectly plausible bin list, a perfectly plausible grid, and two numbers that disagree.
 * Nobody reviewing a screen would notice; the first person to notice would be whoever tried to sell
 * the stock.
 *
 * Running the check inside the seeder turns "we went through StockMovementService" from an intention
 * into a fact. If a section is ever rewritten to take a shortcut, the seeder fails on its own output
 * rather than shipping a database that disagrees with itself.
 *
 * ## This is the application's own definition, not a new one
 *
 * It is copied from InventoryDepthBundle\Command\InventoryDetailRecalcCommand
 * (`app:inventory-depth:detail-check`), which states it in the same order and is the app's standing
 * drift detector for this pair of tables. The seeders run their own copy rather than shelling out to
 * that command for two reasons: a seeder must fail inside its own transaction boundary while it can
 * still say which section did it, and the check has to run on a partially built database where the
 * command's own "iterate every dimensional product" sweep would be reporting on rows the seeder has
 * not written yet.
 *
 * `app:seed-warehouse-data` also prints the command name at the end, because a second opinion from
 * the app's own detector is worth having and it costs the reviewer one line to run.
 *
 * ## Why SQL rather than InventoryDetailRepository
 *
 * The repository lives in an optional bundle, and this helper is core. Its `availableTotal()`,
 * `writeOffTotal()`, `quarantineTotal()` and `soldTotal()` are the same sums; the status values are
 * quoted here as literals because they are the persisted contract (InventoryDetail::statuses()), and
 * on a build with the bundle switched off the correct answer to "does the depth layer reconcile" is
 * "there are no detail rows", which this returns without needing the bundle to be loadable at all.
 */
final class DemoInventoryInvariant
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Every product/warehouse pair that has detail rows, checked.
     *
     * Only pairs that HAVE detail rows are in scope, deliberately. A simple-mode product's stock is a
     * number an admin typed into `product_inventory.quantity`; it has no detail rows and there is
     * nothing for the two sides to disagree about. The invariant is a statement about the depth
     * layer, and it only means anything where the depth layer is in use.
     *
     * @return list<array{product: string, warehouse: string, available: int, expected: int}>
     *         one row per pair that FAILED; an empty list is a pass
     */
    public function failures(): array
    {
        $sql = <<<'SQL'
            SELECT
                p.sku                        AS sku,
                w.name                       AS warehouse,
                d.available                  AS available,
                pi.quantity
                  + pi.received_quantity
                  + pi.transfer_in_quantity
                  - pi.transfer_out_quantity
                  - pi.write_off_quantity
                  - pi.quarantine_quantity
                  - d.sold                   AS expected
            FROM (
                SELECT
                    product_id,
                    warehouse_id,
                    COALESCE(SUM(CASE WHEN status = 'available' THEN quantity ELSE 0 END), 0) AS available,
                    COALESCE(SUM(CASE WHEN status = 'sold'      THEN quantity ELSE 0 END), 0) AS sold
                FROM inventory_detail
                GROUP BY product_id, warehouse_id
            ) d
            JOIN product_core p ON p.id = d.product_id
            JOIN warehouse    w ON w.id = d.warehouse_id
            LEFT JOIN product_inventory pi
                   ON pi.product_id = d.product_id AND pi.warehouse_id = d.warehouse_id
            ORDER BY p.sku, w.name
            SQL;

        $failures = [];

        foreach ($this->connection->fetchAllAssociative($sql) as $row) {
            // A NULL `expected` means there are detail rows but no product_inventory row for the
            // pair. That is itself a violation, and a worse one than a mismatch: StockMovementService
            // creates the row inside syncCoreTotal(), so its absence means stock arrived by some
            // other route entirely. Reported as expected = 0 so the message reads sensibly.
            $expected = $row['expected'] === null ? 0 : (int) $row['expected'];
            $available = (int) $row['available'];

            if ($available !== $expected) {
                $failures[] = [
                    'product' => (string) $row['sku'],
                    'warehouse' => (string) $row['warehouse'],
                    'available' => $available,
                    'expected' => $expected,
                ];
            }
        }

        return $failures;
    }

    /** How many product/warehouse pairs the check actually covered — a pass over nothing is not a pass. */
    public function pairsChecked(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT 1 FROM inventory_detail GROUP BY product_id, warehouse_id)',
        );
    }

    /** @param list<array{product: string, warehouse: string, available: int, expected: int}> $failures */
    public static function describe(array $failures): string
    {
        return implode("\n", array_map(
            static fn (array $f): string => sprintf(
                '  %s @ %s: available detail rows sum to %d, buckets say %d (off by %d)',
                $f['product'],
                $f['warehouse'],
                $f['available'],
                $f['expected'],
                $f['available'] - $f['expected'],
            ),
            $failures,
        ));
    }
}
