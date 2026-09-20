<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vendor sheet import (docs/plans/2026-09-15-vendor-sheet-and-po-csv-import.md), on the unified
 * import framework: vendor_price gains available_quantity + vendor_item_name, and a new
 * unmatched_vendor_sku table is the worklist for a vendor SKU no sheet has ever mapped to a
 * product. Plain ADD COLUMN + CREATE TABLE only — no DROP COLUMN, nothing past SQLite 3.26.
 */
final class Version20260920190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vendor sheet import: vendor_price.available_quantity/vendor_item_name, unmatched_vendor_sku table.';
    }

    public function up(Schema $schema): void
    {
        // NUMERIC(14, 4), not INTEGER — #601's precision rule for anything named *quantity*,
        // checked app-wide by QuantityAndRateColumnPrecisionTest.
        $this->addSql('ALTER TABLE vendor_price ADD COLUMN available_quantity NUMERIC(14, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor_price ADD COLUMN vendor_item_name VARCHAR(255) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE unmatched_vendor_sku (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                vendor_sku VARCHAR(80) NOT NULL,
                last_seen_name VARCHAR(255) DEFAULT NULL,
                last_seen_price NUMERIC(18, 6) DEFAULT NULL,
                last_seen_quantity NUMERIC(14, 4) DEFAULT NULL,
                first_seen_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                status VARCHAR(20) NOT NULL,
                resolved_at DATETIME DEFAULT NULL,
                vendor_id INTEGER NOT NULL,
                resolved_product_id INTEGER DEFAULT NULL,
                CONSTRAINT FK_unmatched_vendor_sku_vendor FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_unmatched_vendor_sku_product FOREIGN KEY (resolved_product_id) REFERENCES product_core (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_unmatched_vendor_sku ON unmatched_vendor_sku (vendor_id, vendor_sku)');
        $this->addSql('CREATE INDEX idx_unmatched_vendor_sku_status ON unmatched_vendor_sku (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE unmatched_vendor_sku');
        $this->addSql('SELECT 1'); // ADD COLUMN has no down on SQLite 3.26 — see CLAUDE.md.
    }
}
