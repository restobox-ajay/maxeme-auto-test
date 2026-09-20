<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds import_run.context — run-level options an executor needs beyond per-row mapped data (e.g.
 * ProductImportRowExecutorFactory's missing_rows/clear_approved_balance). See ImportRun's own
 * docblock on that column for why it's a plain JSON blob rather than a relation.
 */
final class Version20260920180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add import_run.context (JSON, nullable) for run-level executor options.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_run ADD COLUMN context CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // ALTER TABLE ... DROP COLUMN is SQLite 3.35+; production runs 3.26 (see CLAUDE.md).
        // No down migration for an additive, nullable column — nothing reads it if this reverts.
        $this->addSql('SELECT 1');
    }
}
