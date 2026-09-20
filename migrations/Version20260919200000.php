<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Full line-item parity with the sell side (#full-parity, 2026-09-13): `purchase_order_line` and
 * `vendor_bill_line` gain the same `unit`/`weight`/`batch` snapshot columns `sales_order_line` and
 * `invoice_line` already carry.
 *
 * The real Unit-of-Measure columns (`quantity_entered`, `unit_id`) need no migration here — the
 * DenominatedQuantity trait was already mapped onto both entities and this database already carries
 * both columns on both tables. What was missing was the legacy free-text `unit` label
 * (LineDenomination::label()'s fallback, and the free-text box a blank line types into), the
 * per-line `weight`, and the `batch` snapshot — none of which any buy-side screen ever exposed.
 *
 * Renumbered from Version20260919180000 to Version20260919200000 when merging origin/main, whose
 * own Version20260919180000 (the invoice email template fix) was raised independently at the same
 * timestamp — same content, new number, so both migrations keep their place in the chain.
 */
final class Version20260919200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unit/weight/batch columns to purchase_order_line and vendor_bill_line (buy-side line-item parity with the sell side).';
    }

    public function up(Schema $schema): void
    {
        foreach (['purchase_order_line', 'vendor_bill_line'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN unit VARCHAR(80) DEFAULT NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN weight VARCHAR(80) DEFAULT NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN batch VARCHAR(120) DEFAULT NULL', $table));
        }
    }

    public function down(Schema $schema): void
    {
        // SQLite's ALTER TABLE cannot drop a column without a full table rebuild, and nothing in
        // this app's own migration history has ever done one for a down() — see the other
        // hand-written migrations in this directory that add nullable columns.
    }
}
