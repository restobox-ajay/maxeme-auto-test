<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Services get a tax class (Config › Settings › Tax Classes) and their own calendar colour, which
 * overrides the category's. Both optional: existing services keep none and show their category's colour.
 */
final class Version20261002100000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Services: tax class and own calendar colour';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('maxeme_service', 'tax_class_id')) {
            $this->addSql('ALTER TABLE maxeme_service ADD COLUMN tax_class_id INTEGER DEFAULT NULL REFERENCES maxeme_tax_class (id)');
            $this->addSql('CREATE INDEX IDX_F826A7F3A94AAAE ON maxeme_service (tax_class_id)');
        }
        if (!$this->columnExists('maxeme_service', 'own_colour')) {
            $this->addSql('ALTER TABLE maxeme_service ADD COLUMN own_colour VARCHAR(7) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_F826A7F3A94AAAE');
        $this->addSql('ALTER TABLE maxeme_service DROP COLUMN tax_class_id');
        $this->addSql('ALTER TABLE maxeme_service DROP COLUMN own_colour');
    }
}
