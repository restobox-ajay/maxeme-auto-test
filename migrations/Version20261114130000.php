<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mockups 169b599 and a38e7a2: a repair order's recommendations for the customer (printed on the work
 * order, copied onto the invoice) and its pickup address (one of the client's address book, none by
 * default).
 */
final class Version20261114130000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Repair order: recommendations, pickup address';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('maxeme_repair_order', 'recommendations')) {
            $this->addSql('ALTER TABLE maxeme_repair_order ADD COLUMN recommendations CLOB DEFAULT NULL');
        }
        if (!$this->columnExists('maxeme_repair_order', 'pickup_address_id')) {
            $this->addSql('ALTER TABLE maxeme_repair_order ADD COLUMN pickup_address_id INTEGER DEFAULT NULL REFERENCES maxeme_client_address (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_MX_RO_PICKUP_ADDRESS ON maxeme_repair_order (pickup_address_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_MX_RO_PICKUP_ADDRESS');
        $this->addSql('ALTER TABLE maxeme_repair_order DROP COLUMN pickup_address_id');
        $this->addSql('ALTER TABLE maxeme_repair_order DROP COLUMN recommendations');
    }
}
