<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unified import framework (docs/plans/2026-09-18-unified-import-framework.md): one ledger every
 * import type writes to instead of each feature keeping its own status file. Three tables, no
 * relation to any bundle entity — core may not depend on a bundle, so vendor/document context lives
 * in import_run.description (free text) rather than an FK column; a bundle wanting queryable
 * per-run context of its own FKs its own side table to import_run.id instead (bundles may depend on
 * core).
 *
 * Plain CREATE TABLE only — no generated columns, no RETURNING, nothing past SQLite 3.26 (see
 * CLAUDE.md).
 */
final class Version20260920170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create import_run, import_run_row, import_column_mapping (unified import framework).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE import_run (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                import_type VARCHAR(60) NOT NULL,
                entity_type VARCHAR(80) NOT NULL,
                description VARCHAR(255) NOT NULL,
                action VARCHAR(120) DEFAULT NULL,
                validation_only BOOLEAN NOT NULL,
                status VARCHAR(20) NOT NULL,
                row_count INTEGER NOT NULL,
                validated_count INTEGER NOT NULL,
                executed_count INTEGER NOT NULL,
                error_count INTEGER NOT NULL,
                error CLOB DEFAULT NULL,
                source VARCHAR(16) NOT NULL,
                attempt INTEGER NOT NULL,
                queued_at DATETIME DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_import_run_status ON import_run (status)');
        $this->addSql('CREATE INDEX idx_import_run_type ON import_run (import_type)');

        $this->addSql(<<<'SQL'
            CREATE TABLE import_run_row (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                row_number INTEGER NOT NULL,
                input_data CLOB NOT NULL,
                mapped_data CLOB NOT NULL,
                action VARCHAR(20) DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                error CLOB DEFAULT NULL,
                created_at DATETIME NOT NULL,
                import_run_id INTEGER NOT NULL,
                CONSTRAINT FK_import_run_row_import_run FOREIGN KEY (import_run_id) REFERENCES import_run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_import_run_row_status ON import_run_row (import_run_id, status)');

        $this->addSql(<<<'SQL'
            CREATE TABLE import_column_mapping (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                import_type VARCHAR(60) NOT NULL,
                scope VARCHAR(120) NOT NULL,
                mapping CLOB NOT NULL,
                updated_at DATETIME NOT NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_import_column_mapping ON import_column_mapping (import_type, scope)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE import_run_row');
        $this->addSql('DROP TABLE import_column_mapping');
        $this->addSql('DROP TABLE import_run');
    }
}
