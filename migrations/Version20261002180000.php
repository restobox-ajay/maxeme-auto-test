<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** An appointment's own calendar colour, overriding its service's (blank: the service's colour). */
final class Version20261002180000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Appointments: own calendar colour';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('maxeme_appointment', 'colour')) {
            $this->addSql('ALTER TABLE maxeme_appointment ADD COLUMN colour VARCHAR(7) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maxeme_appointment DROP COLUMN colour');
    }
}
