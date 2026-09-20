<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A real `shipped` bucket, transferred from `approved` at Completed
 * (docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md).
 *
 * `approved` was already correct for the availability math — it removes a unit from `available`
 * permanently the moment an invoice reaches Processing, and nothing about that changes here. What it
 * could not be, on its own, is an independent figure for `InventoryDetail`'s `sold`-status rows to
 * reconcile against later, without conflating two different invoice stages (Processing and
 * Completed) in one bucket. `shipped` is that independent figure: `approved` decreases by exactly
 * the amount `shipped` increases by — a transfer, not an addition, the same shape `pending ->
 * approved` already uses — so `available`'s subtraction is unaffected either way.
 *
 * One column. `InvoiceInventoryReservation.bucket` needs no schema change for its half — `shipped`
 * is simply a new value of the existing string column, the same way `approved` already is one.
 */
final class Version20260919220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A real shipped bucket: product_inventory.shipped_quantity, transferred from approved at Completed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN shipped_quantity INTEGER DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // The column stays. SQLite gained DROP COLUMN in 3.35 and this project's floor is 3.26, so
        // removing it means create-copy-drop-rename of the whole table to delete a column that
        // rolled-back code does not read — the same call every other bucket-column migration in this
        // project has already made (see Version20260827090000's quarantine_quantity, for one).
    }
}
