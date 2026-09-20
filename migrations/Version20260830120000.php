<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `product_inventory.write_off_quantity` — gone or unsellable for good: damaged, expired, scrapped,
 * lost (#581). Alongside it, `quarantine_quantity` starts being used: quarantined and returned
 * units, physically here and unsellable until somebody rules on them.
 *
 * The client's external system has not been told about any of it, so `quantity` still includes it
 * and availability has to take it off.
 *
 * `in_transit` is excluded — `transfer_out` (#574) carries that, and a unit in both would be
 * withheld twice. `sold` and `staged` are excluded too: the invoice that billed those units holds
 * them in `pending`/`approved`, which SellingADimensionalProductTest demonstrates by conducting the
 * sale.
 *
 * Both columns are backfilled here, deliberately. Defaulting to 0 while StockMovementService
 * recomputes them lazily would leave existing damaged and expired stock sellable until something
 * unrelated moved that product — the mistake #574's first migration made and its second had to
 * correct. The backfill is a pure recomputation from the detail rows, the same expression
 * InventoryDetailRepository uses, so it is deterministic and re-runnable. `quarantine_quantity`
 * existed already but had no formula, no reader and no writer (#564), so nothing meaningful can be
 * overwritten.
 *
 * WHAT THIS MIGRATION DELIBERATELY DOES NOT DO
 * --------------------------------------------
 * `received_quantity` is left exactly as it is, and on existing rows it is wrong.
 *
 * Until now every departure from the available pool was netted into `received` — damaging a
 * pallet, picking an order, a lot expiring. Those units are now ALSO in the buckets above, so an
 * affected row withholds them twice and reads short by that amount. The arithmetic fix looks
 * obvious: add the two new buckets back into `received`.
 *
 * It is not safe. An import run with `clear_received_balance` zeroes `received` and re-plugs the
 * detail table against a fresh count — the admin declaring that the new figure already includes
 * everything booked since. Detail rows that survive such a rebaseline were already accounted for
 * in `quantity`, so adding them back to `received` would overstate the row instead. Nothing in
 * `product_inventory` says which side of the last rebaseline a given detail row falls on.
 *
 * So the correction is left to a human who knows the answer per instance, rather than guessed at
 * across everyone's data by a migration that cannot tell the two cases apart. Rows are identified
 * by `write_off_quantity <> 0 OR quarantine_quantity <> 0` after this runs.
 */
final class Version20260830120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#581: add write_off_quantity and backfill it and quarantine_quantity from the detail rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN write_off_quantity INTEGER DEFAULT 0 NOT NULL');

        $this->addSql(<<<'SQL'
            UPDATE product_inventory
            SET write_off_quantity = COALESCE((
                SELECT SUM(d.quantity)
                FROM inventory_detail d
                WHERE d.product_id = product_inventory.product_id
                  AND d.warehouse_id = product_inventory.warehouse_id
                  AND d.status IN ('damaged', 'expired', 'scrapped', 'lost')
            ), 0),
            quarantine_quantity = COALESCE((
                SELECT SUM(d.quantity)
                FROM inventory_detail d
                WHERE d.product_id = product_inventory.product_id
                  AND d.warehouse_id = product_inventory.warehouse_id
                  AND d.status IN ('quarantine', 'returned')
            ), 0)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo. `write_off_quantity` stays — this SQLite predates DROP COLUMN, and
        // nothing reads the column once the code is rolled back. `quarantine_quantity` goes back to
        // being inert for the same reason. No row's `received_quantity` was touched on the way in,
        // so there is none to put back.
    }
}
