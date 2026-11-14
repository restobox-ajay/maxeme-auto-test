<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mockup 6611dd0, partial invoicing: on a later invoice of a repair order, a service already charged
 * that still has charge-through lines left comes along at 0 as their heading, marked continued (it
 * is not charged twice).
 */
final class Version20261114180000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Invoice service line: continued (a service heading at 0 on a later invoice)';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('maxeme_invoice_service', 'continued')) {
            $this->addSql('ALTER TABLE maxeme_invoice_service ADD COLUMN continued BOOLEAN DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maxeme_invoice_service DROP COLUMN continued');
    }
}
