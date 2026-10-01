<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invoice part lines point at a product of core's catalogue (`product_id`). ADD-only: the legacy
 * part link (`part_id`) stays as history; `app:maxeme:convert-parts` copies the legacy parts into
 * products and fills in `product_id` for the lines that used them.
 */
final class Version20261001140000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Invoice part lines link to core products';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('maxeme_invoice_part', 'product_id')) {
            $this->addSql('ALTER TABLE maxeme_invoice_part ADD COLUMN product_id INTEGER DEFAULT NULL REFERENCES product_core (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_E7218C824584665A ON maxeme_invoice_part (product_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_E7218C824584665A');
        $this->addSql('ALTER TABLE maxeme_invoice_part DROP COLUMN product_id');
    }
}
