<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Maxeme Auto, phase 2: clients and their vehicles (App\Maxeme\Entity\Client, Vehicle). New
 * tables only; `app:maxeme:import-legacy --only=clients,vehicles` fills them, keeping legacy ids.
 */
final class Version20260929120000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Maxeme: add maxeme_client and maxeme_vehicle';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('maxeme_client')) {
            $this->addSql('CREATE TABLE maxeme_client (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, first_name VARCHAR(255) DEFAULT NULL, last_name VARCHAR(255) DEFAULT NULL, preferred_name VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, home_number VARCHAR(255) DEFAULT NULL, work_number VARCHAR(255) DEFAULT NULL, cell_number VARCHAR(255) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, note CLOB DEFAULT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_updated DATETIME NOT NULL)');
            $this->addSql('CREATE INDEX idx_maxeme_client_active_name ON maxeme_client (active, first_name, last_name)');
        }

        if (!$this->tableExists('maxeme_vehicle')) {
            $this->addSql('CREATE TABLE maxeme_vehicle (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, manufacturer VARCHAR(255) DEFAULT NULL, model VARCHAR(255) DEFAULT NULL, year INTEGER DEFAULT NULL, vin VARCHAR(255) DEFAULT NULL, license_plate VARCHAR(255) DEFAULT NULL, mileage VARCHAR(255) DEFAULT NULL, color VARCHAR(255) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_updated DATETIME NOT NULL, client_id INTEGER NOT NULL, CONSTRAINT FK_23BD9A719EB6921 FOREIGN KEY (client_id) REFERENCES maxeme_client (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_23BD9A719EB6921 ON maxeme_vehicle (client_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS maxeme_vehicle');
        $this->addSql('DROP TABLE IF EXISTS maxeme_client');
    }
}
