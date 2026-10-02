<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Vehicle notes: author, message, timestamp, as a client's. */
final class Version20261002200000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Vehicle notes';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('maxeme_vehicle_note')) {
            $this->addSql('CREATE TABLE maxeme_vehicle_note (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                vehicle_id INTEGER NOT NULL,
                user_name VARCHAR(255) DEFAULT NULL,
                text CLOB NOT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT FK_MX_VEHICLE_NOTE_VEHICLE FOREIGN KEY (vehicle_id) REFERENCES maxeme_vehicle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )');
            $this->addSql('CREATE INDEX idx_maxeme_vehicle_note_vehicle ON maxeme_vehicle_note (vehicle_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE maxeme_vehicle_note');
    }
}
