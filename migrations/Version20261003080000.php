<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-line tax classes: the taxes an invoice's service line carries, frozen when it was added
 * (maxeme_invoice_service.charges_gst / charges_pst), and the invoice's GST apart from its sales tax
 * (maxeme_invoice.gst_amount; null on existing invoices). Every existing line carries both, as it
 * was taxed, so no total changes.
 */
final class Version20261003080000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Invoice service lines: the taxes each carries';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('maxeme_invoice_service', 'charges_gst')) {
            $this->addSql('ALTER TABLE maxeme_invoice_service ADD COLUMN charges_gst BOOLEAN DEFAULT 1 NOT NULL');
        }
        if (!$this->columnExists('maxeme_invoice_service', 'charges_pst')) {
            $this->addSql('ALTER TABLE maxeme_invoice_service ADD COLUMN charges_pst BOOLEAN DEFAULT 1 NOT NULL');
        }
        if (!$this->columnExists('maxeme_invoice', 'gst_amount')) {
            $this->addSql('ALTER TABLE maxeme_invoice ADD COLUMN gst_amount NUMERIC(10, 2) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maxeme_invoice_service DROP COLUMN charges_gst');
        $this->addSql('ALTER TABLE maxeme_invoice_service DROP COLUMN charges_pst');
        $this->addSql('ALTER TABLE maxeme_invoice DROP COLUMN gst_amount');
    }
}
