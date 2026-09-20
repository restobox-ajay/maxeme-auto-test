<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `location` on `purchase_order_line` and `vendor_bill_line` — per-line override of the document's
 * own warehouse, full parity with `sales_order_line.location`/`invoice_line.location` (#full-parity,
 * 2026-09-13). NULL means "use the document's own warehouse", exactly as a blank line on the sell
 * side means "use Main" — see PurchaseOrderLine::$location's own docblock for why this picks from
 * warehouse names rather than the sell side's fulfillment regions.
 *
 * Renumbered from Version20260919190000 to Version20260919200001 when merging origin/main, whose
 * own Version20260919190000 (audit_log.recipient_notified) was raised independently at the same
 * timestamp — same content, new number, so both migrations keep their place in the chain.
 */
final class Version20260919200001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add location to purchase_order_line and vendor_bill_line (per-line warehouse override, buy-side line-item parity).';
    }

    public function up(Schema $schema): void
    {
        foreach (['purchase_order_line', 'vendor_bill_line'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN location VARCHAR(120) DEFAULT NULL', $table));
        }
    }

    public function down(Schema $schema): void
    {
        // SQLite's ALTER TABLE cannot drop a column without a full table rebuild — see the other
        // hand-written migrations in this directory that add nullable columns.
    }
}
