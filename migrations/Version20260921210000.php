<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `woocommerce_sync_run` (#739) — Bulk Resync's own audit trail: one row per run, recording what
 * was kicked off (how many mappings got re-queued, which connection or "every connection", and who
 * or what triggered it). See WooCommerceSyncRun's own docblock for why this is an audit record of
 * what was queued rather than a live job-status row tracking each push back to its run.
 *
 * `connection_id` is nullable: a run with no connection covered every active one in one pass, the
 * nightly scheduled shape.
 *
 * One CREATE TABLE, which touches nothing that exists — no ALTER TABLE, no rebuild.
 */
final class Version20260921210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add woocommerce_sync_run (Bulk Resync audit trail).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE woocommerce_sync_run ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'connection_id INTEGER DEFAULT NULL, '
            . 'queued_count INTEGER DEFAULT 0 NOT NULL, '
            . 'triggered_by VARCHAR(190) NOT NULL, '
            . 'started_at DATETIME NOT NULL, '
            . 'CONSTRAINT FK_woo_sync_run_connection FOREIGN KEY (connection_id) REFERENCES woocommerce_connection (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );
        $this->addSql('CREATE INDEX idx_woo_sync_run_connection ON woocommerce_sync_run (connection_id)');
    }

    /**
     * Reversible outright: this migration creates one table and nothing else, so dropping it loses
     * only this feature's own data.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE woocommerce_sync_run');
    }
}
