<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Technicians are staff users (mockups 858860a, 169b599, 986d771):
 * - the repair order's assigned technician (master_technician_id) now points at admin_user (a user
 *   holding ROLE_TECHNICIAN) instead of maxeme_technician, which is dropped with the Settings ›
 *   Technicians tab. No repair order referred to a maxeme_technician row, so nothing is lost; any
 *   value that does not name a user is cleared;
 * - each service on a repair order has its own technician and work status (not_started,
 *   in_progress, done);
 * - an appointment has an assigned technician, kept the same as its repair order's.
 *
 * SQLite cannot change a foreign key in place, so maxeme_repair_order is rebuilt from its own CREATE
 * statement with the constraint re-pointed, outside a transaction with foreign keys off (so DROP
 * TABLE does not cascade into the jobs, appointments and invoices pointing at its rows).
 */
final class Version20261114110000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Technicians are staff users; per-service technician and work status; appointment technician';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');

        $create = (string) $this->connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'maxeme_repair_order'");
        if (str_contains($create, 'REFERENCES maxeme_technician')) {
            $this->addSql('UPDATE maxeme_repair_order SET master_technician_id = NULL WHERE master_technician_id IS NOT NULL AND master_technician_id NOT IN (SELECT id FROM admin_user)');
            $columns = implode(', ', array_map(static fn (array $c): string => $c['name'], $this->connection->fetchAllAssociative('PRAGMA table_info(maxeme_repair_order)')));
            $indexes = $this->connection->fetchFirstColumn("SELECT sql FROM sqlite_master WHERE type IN ('index', 'trigger') AND tbl_name = 'maxeme_repair_order' AND sql IS NOT NULL");
            $this->addSql("CREATE TEMPORARY TABLE __temp__maxeme_repair_order AS SELECT $columns FROM maxeme_repair_order");
            $this->addSql('DROP TABLE maxeme_repair_order');
            $this->addSql(str_replace('REFERENCES maxeme_technician (id)', 'REFERENCES admin_user (id)', $create));
            $this->addSql("INSERT INTO maxeme_repair_order ($columns) SELECT $columns FROM __temp__maxeme_repair_order");
            $this->addSql('DROP TABLE __temp__maxeme_repair_order');
            foreach ($indexes as $index) {
                $this->addSql($index);
            }
        }
        if (!$this->columnExists('maxeme_repair_order_job', 'technician_id')) {
            $this->addSql('ALTER TABLE maxeme_repair_order_job ADD COLUMN technician_id INTEGER DEFAULT NULL REFERENCES admin_user (id) ON DELETE SET NULL');
            $this->addSql("ALTER TABLE maxeme_repair_order_job ADD COLUMN work_status VARCHAR(20) DEFAULT 'not_started' NOT NULL");
            $this->addSql('CREATE INDEX IDX_MX_RO_JOB_TECHNICIAN ON maxeme_repair_order_job (technician_id)');
        }
        if (!$this->columnExists('maxeme_appointment', 'technician_id')) {
            $this->addSql('ALTER TABLE maxeme_appointment ADD COLUMN technician_id INTEGER DEFAULT NULL REFERENCES admin_user (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_MX_APPOINTMENT_TECHNICIAN ON maxeme_appointment (technician_id)');
        }
        if ($this->tableExists('maxeme_technician')) {
            $this->addSql('DROP TABLE maxeme_technician');
        }

        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('maxeme_technician is dropped; restore a backup to go back.');
    }
}
