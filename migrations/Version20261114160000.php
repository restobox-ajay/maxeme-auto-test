<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mockups 649ae8e / d2fbfc8: an invoice's Remark, internal and never printed (shown to staff above the
 * invoice and in the Payment report).
 */
final class Version20261114160000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Invoice: remark (admin note)';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('maxeme_invoice', 'admin_note')) {
            $this->addSql('ALTER TABLE maxeme_invoice ADD COLUMN admin_note CLOB DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maxeme_invoice DROP COLUMN admin_note');
    }
}
