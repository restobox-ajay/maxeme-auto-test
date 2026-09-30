<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * audit_log.actor_role: the actor's role at the time of the action (Maxeme's Activity Log filters
 * on it). ADD-only; existing rows read NULL, which is right: nobody recorded a role for them.
 */
final class Version20260930120000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Add audit_log.actor_role';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('audit_log', 'actor_role')) {
            $this->addSql('ALTER TABLE audit_log ADD COLUMN actor_role VARCHAR(40) DEFAULT NULL');
        }
        if (!$this->indexExists('idx_audit_log_actor_role')) {
            $this->addSql('CREATE INDEX idx_audit_log_actor_role ON audit_log (actor_role)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_audit_log_actor_role');
        $this->addSql('ALTER TABLE audit_log DROP COLUMN actor_role');
    }
}
