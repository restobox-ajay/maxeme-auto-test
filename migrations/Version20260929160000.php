<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Maxeme Auto, phase 4: appointments (App\Maxeme\Entity\Appointment). New table only;
 * `app:maxeme:import-legacy --only=appointments` fills it, keeping legacy ids.
 */
final class Version20260929160000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Maxeme: add maxeme_appointment';
    }

    public function up(Schema $schema): void
    {
        if ($this->tableExists('maxeme_appointment')) {
            return;
        }

        $this->addSql('CREATE TABLE maxeme_appointment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, start_time DATETIME NOT NULL, end_time DATETIME NOT NULL, status VARCHAR(20) NOT NULL, note CLOB DEFAULT NULL, last_updated DATETIME NOT NULL, client_id INTEGER NOT NULL, vehicle_id INTEGER NOT NULL, CONSTRAINT FK_A95F996F19EB6921 FOREIGN KEY (client_id) REFERENCES maxeme_client (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A95F996F545317D1 FOREIGN KEY (vehicle_id) REFERENCES maxeme_vehicle (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_A95F996F19EB6921 ON maxeme_appointment (client_id)');
        $this->addSql('CREATE INDEX IDX_A95F996F545317D1 ON maxeme_appointment (vehicle_id)');
        $this->addSql('CREATE INDEX idx_maxeme_appointment_start ON maxeme_appointment (start_time)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS maxeme_appointment');
    }
}
