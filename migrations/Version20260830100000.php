<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `product_inventory.transfer_out_quantity` — stock that left on an internal transfer (#574).
 *
 * #552 shipped transfer orders before the bucket model existed, so a dispatch recorded nothing at
 * the source: `WarehouseOpsCest`'s "the source cannot sell what is on the truck" has been failing
 * on exactly that. The units are still in `quantity` — the client's external system has not been
 * told they moved — but they are on a truck and the source cannot sell them, which is precisely
 * the shape a hold has.
 *
 * There is no matching `transfer_in` column. Goods arriving at the destination are `received`:
 * arrived, sellable, not yet in the external system's file is what that bucket already means, and
 * whether they came from a vendor or another warehouse does not change the fact.
 *
 * Additive and defaulted, so an instance that never raises a transfer is unaffected — the column
 * stays 0 and the term contributes nothing.
 */
final class Version20260830100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#574: add product_inventory.transfer_out_quantity for stock in transit between warehouses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN transfer_out_quantity INTEGER DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite gained DROP COLUMN in 3.35 and this project's floor is older, so the column is
        // left in place. It defaults to 0 and nothing reads it once the code is rolled back, which
        // is the same call every other additive migration here makes.
    }
}
