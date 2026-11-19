<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A product's vendor (App\Maxeme\Entity\ProductVendor, mockup product form "Vendor"): at most one
 * vendor per product, in the shop's own table, so product_core is unchanged. Deleting the product
 * or the vendor deletes the row.
 */
final class Version20261119120000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Maxeme: add maxeme_product_vendor (a product\'s vendor)';
    }

    public function up(Schema $schema): void
    {
        if ($this->tableExists('maxeme_product_vendor')) {
            return;
        }
        $this->addSql('CREATE TABLE maxeme_product_vendor (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, product_id INTEGER NOT NULL, vendor_id INTEGER NOT NULL, CONSTRAINT FK_maxeme_product_vendor_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_maxeme_product_vendor_vendor FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_maxeme_product_vendor_product ON maxeme_product_vendor (product_id)');
        $this->addSql('CREATE INDEX idx_maxeme_product_vendor_vendor ON maxeme_product_vendor (vendor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS maxeme_product_vendor');
    }
}
