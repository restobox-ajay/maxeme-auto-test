<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `product_inventory.transfer_in_quantity` — everything that has ever arrived at this warehouse on
 * an internal transfer (#584).
 *
 * Its partner `transfer_out_quantity` already exists and keeps its name, but not its meaning. It
 * used to be "on a truck right now", a cached sum of the `in_transit` detail rows; it is now
 * "everything that has ever left", summed from `transfer_order_line.quantity_dispatched`. The pair
 * are two readings of one document:
 *
 *     transfer_out = SUM(quantity_dispatched) where this warehouse is the transfer's FROM
 *     transfer_in  = SUM(quantity_received)   where this warehouse is the transfer's TO
 *
 * The old definition fell back to zero when a receipt consumed the in-transit rows, so the source
 * warehouse sprang back to full availability for stock now standing in another building. The loss
 * was pushed into `received_quantity` as a negative to keep the arithmetic straight, which put a
 * WarehouseOpsBundle fact into a bucket BundleBucketAvailabilityGate gates on ProcurementBundle.
 * With procurement Inactive the negative left the sum and the source read 50 of 50 sellable while
 * its own detail rows said 40.
 *
 * NO BACKFILL. THE COLUMN AND NOTHING ELSE.
 * ----------------------------------------
 * Both halves of the redefinition leave some existing rows wrong, and only one of them could be
 * corrected here safely:
 *
 *   - `transfer_in` starts at 0 everywhere. Every arrival booked before this ran was credited to
 *     the destination's `received_quantity` instead, by the old
 *     MovementRequest::receivedDelta(). The units are therefore already counted, once, in the right
 *     direction and the wrong column. Backfilling `transfer_in` from `transfer_order_line` without
 *     also removing those credits from `received` would count every historic arrival TWICE.
 *
 *   - Rows that are the SOURCE of an already-arrived transfer carry `transfer_out = 0` and a
 *     negative in `received`. Under the new definition they should carry the dispatched total in
 *     `transfer_out` and nothing of it in `received` — the same two-sided correction, entangled
 *     with the same column.
 *
 *   - Rows for transfers still IN FLIGHT need nothing at all. While nothing has arrived, dispatched
 *     equals in-transit, so the old and new definitions agree by arithmetic.
 *
 * So the correction is not a schema change; it is a decision about `received_quantity`, which #581
 * deliberately left open and which is not the same decision at every instance. An import run with
 * `clear_received_balance` zeroes `received` and re-plugs the detail table against a fresh count —
 * the admin declaring that the new figure already includes everything booked since. Whether a given
 * transfer falls before or after that rebaseline is not recorded anywhere in `product_inventory`,
 * and a migration that guessed would overstate exactly the instances that keep their data tidiest.
 *
 * The SQL that WOULD do it, for an instance whose owner knows the answer, is in the pull request
 * rather than here, precisely so that it is run by somebody who has checked which case they are in.
 *
 * Affected rows are identifiable after this runs: any `product_inventory` row whose (product,
 * warehouse) appears as the FROM or TO of a `transfer_order` with a non-zero `quantity_received`.
 *
 * A fresh instance is unaffected — no transfers, both columns 0, and every later transfer computed
 * correctly from its own document.
 */
final class Version20260830140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#584: add product_inventory.transfer_in_quantity. No backfill — see the class docblock.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN transfer_in_quantity INTEGER DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo. The column stays — this SQLite predates DROP COLUMN, and nothing reads it
        // once the code is rolled back. No existing row was touched on the way in, so there is none
        // to put back.
    }
}
