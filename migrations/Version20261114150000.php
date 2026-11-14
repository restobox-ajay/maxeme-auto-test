<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mockup 7bd9227: the stock adjustment reason "Purchase" (stock bought from a vendor and received),
 * first in the list. Like Stock found it enters the ledger into Available; the reference holds the
 * purchase order or vendor invoice number. An existing row (an admin may have added one) is left alone.
 */
final class Version20261114150000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Stock adjustment reason: Purchase, first';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('inventory_adjustment_reason')) {
            return;
        }
        $this->addSql(<<<'SQL'
            INSERT INTO inventory_adjustment_reason (code, label, from_status, to_status, reversal, help, sort_key, active)
            SELECT 'purchase', 'Purchase', NULL, 'available', 0,
                   'Stock bought from a vendor and received into the warehouse. Enter the quantity received and put the purchase order or vendor invoice number in Reference.',
                   5, 1
            WHERE NOT EXISTS (SELECT 1 FROM inventory_adjustment_reason WHERE code = 'purchase')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM inventory_adjustment_reason WHERE code = 'purchase' AND NOT EXISTS (SELECT 1 FROM inventory_movement_group g WHERE g.adjustment_reason_id = inventory_adjustment_reason.id)");
    }
}
