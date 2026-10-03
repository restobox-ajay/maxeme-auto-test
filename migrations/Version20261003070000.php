<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Numbers typed in: maxeme_invoice.custom_number (Change Invoice #) and
 * maxeme_repair_order.work_order_number (Change Work Order #), each unique; null = the automatic number.
 */
final class Version20261003070000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Editable invoice and work order numbers';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('maxeme_invoice', 'custom_number')) {
            $this->addSql('ALTER TABLE maxeme_invoice ADD COLUMN custom_number VARCHAR(40) DEFAULT NULL');
            $this->addSql('CREATE UNIQUE INDEX uniq_maxeme_invoice_custom_number ON maxeme_invoice (custom_number)');
        }
        if (!$this->columnExists('maxeme_repair_order', 'work_order_number')) {
            $this->addSql('ALTER TABLE maxeme_repair_order ADD COLUMN work_order_number VARCHAR(40) DEFAULT NULL');
            $this->addSql('CREATE UNIQUE INDEX uniq_maxeme_repair_order_wo_number ON maxeme_repair_order (work_order_number)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_maxeme_invoice_custom_number');
        $this->addSql('DROP INDEX uniq_maxeme_repair_order_wo_number');
        $this->addSql('ALTER TABLE maxeme_invoice DROP COLUMN custom_number');
        $this->addSql('ALTER TABLE maxeme_repair_order DROP COLUMN work_order_number');
    }
}
