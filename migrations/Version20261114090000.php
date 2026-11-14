<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mockup f556ee8: the work order prints "First Printed On", set the first time it is printed (or
 * saved as a PDF) and never changed after that: on the repair order, and on an invoice with no repair
 * order (its own work order). Nothing existing has been printed here yet.
 */
final class Version20261114090000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Work order first printed at, on repair orders and invoices';
    }

    public function up(Schema $schema): void
    {
        foreach (['maxeme_repair_order', 'maxeme_invoice'] as $table) {
            if (!$this->columnExists($table, 'wo_first_printed_at')) {
                $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN wo_first_printed_at DATETIME DEFAULT NULL', $table));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maxeme_repair_order DROP COLUMN wo_first_printed_at');
        $this->addSql('ALTER TABLE maxeme_invoice DROP COLUMN wo_first_printed_at');
    }
}
