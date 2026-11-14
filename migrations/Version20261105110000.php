<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use App\Doctrine\SqliteTableRebuild;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Appointment types (mockup 689a4a1, 9ba2951): what an appointment is for (maxeme_appointment.appt_type:
 * repair, bodywork, buy_sell, other; every existing one is a repair-order appointment), and a vehicle
 * that may be none (Buying/selling cars, Other).
 *
 * SQLite cannot drop NOT NULL in place, so maxeme_appointment is rebuilt (SqliteTableRebuild),
 * outside a transaction with foreign keys off, so the rebuild's DROP TABLE does not cascade into
 * the invoices that point at its rows. Every row keeps its values.
 */
final class Version20261105110000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Appointment type, and an appointment vehicle that may be none';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');
        if (!$this->columnExists('maxeme_appointment', 'appt_type')) {
            foreach (SqliteTableRebuild::statements($this->connection, 'maxeme_appointment', ['vehicle_id' => 'vehicle_id INTEGER DEFAULT NULL']) as $sql) {
                $this->addSql($sql);
            }
            $this->addSql("ALTER TABLE maxeme_appointment ADD COLUMN appt_type VARCHAR(20) DEFAULT 'repair' NOT NULL");
        }
        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maxeme_appointment DROP COLUMN appt_type');
    }
}
