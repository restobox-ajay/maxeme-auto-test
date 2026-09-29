<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Maxeme Auto, phase 3: parts, their stock history and the service catalogue
 * (App\Maxeme\Entity\Part, InventoryHistory, ServiceItem). New tables only;
 * `app:maxeme:import-legacy --only=parts,inventory-history,services` fills them, keeping legacy ids.
 *
 * inventory_history.invoice_id has no foreign key yet: the invoices table arrives with the invoices.
 */
final class Version20260929140000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Maxeme: add maxeme_part, maxeme_inventory_history and maxeme_service';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('maxeme_part')) {
            $this->addSql('CREATE TABLE maxeme_part (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, vin VARCHAR(255) DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, manufacturer VARCHAR(255) DEFAULT NULL, type VARCHAR(20) DEFAULT NULL, description CLOB DEFAULT NULL, vendor VARCHAR(255) DEFAULT NULL, unit_price NUMERIC(10, 2) DEFAULT NULL, sale_price NUMERIC(10, 2) DEFAULT NULL, quantity INTEGER DEFAULT 0 NOT NULL, notes CLOB DEFAULT NULL, active BOOLEAN DEFAULT 1 NOT NULL)');
        }

        if (!$this->tableExists('maxeme_inventory_history')) {
            $this->addSql('CREATE TABLE maxeme_inventory_history (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, created_on DATETIME NOT NULL, quantity INTEGER NOT NULL, unit_price NUMERIC(10, 2) DEFAULT NULL, sale_price NUMERIC(10, 2) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, po_number VARCHAR(255) DEFAULT NULL, invoice_id INTEGER DEFAULT NULL, part_id INTEGER NOT NULL, CONSTRAINT FK_60858A304CE34BEC FOREIGN KEY (part_id) REFERENCES maxeme_part (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_60858A304CE34BEC ON maxeme_inventory_history (part_id)');
            $this->addSql('CREATE INDEX idx_maxeme_inventory_history_invoice ON maxeme_inventory_history (invoice_id)');
        }

        if (!$this->tableExists('maxeme_service')) {
            $this->addSql('CREATE TABLE maxeme_service (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, preferred_name VARCHAR(255) DEFAULT NULL, price NUMERIC(10, 2) DEFAULT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_updated DATETIME NOT NULL)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS maxeme_inventory_history');
        $this->addSql('DROP TABLE IF EXISTS maxeme_part');
        $this->addSql('DROP TABLE IF EXISTS maxeme_service');
    }
}
