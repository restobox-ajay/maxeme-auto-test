<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mockup 8b30420 / 34181b0: a repair order is Invoiced only while it has a paid invoice, reached from
 * Completed or Completed, Picked Up; that status is kept here so it goes back to it when no paid
 * invoice is left (cancelled, unpaid or deleted). Existing Invoiced ones go back to Completed.
 */
final class Version20261114120000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Repair order: status before invoiced';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('maxeme_repair_order', 'status_before_invoiced')) {
            $this->addSql('ALTER TABLE maxeme_repair_order ADD COLUMN status_before_invoiced VARCHAR(24) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maxeme_repair_order DROP COLUMN status_before_invoiced');
    }
}
