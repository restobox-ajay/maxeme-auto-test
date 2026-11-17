<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteTableRebuild;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Service-line quantities keep core's quantity scale (4 decimals), not money's 2: only a line's
 * TOTAL is rounded to the cent. 1.333 hours of labour stays 1.333 instead of becoming 1.33.
 *
 * The quantity columns of maxeme_service_line, maxeme_repair_order_job_line and
 * maxeme_invoice_service are re-declared NUMERIC(14, 4). SQLite stores the value as written either
 * way (NUMERIC affinity ignores the scale), so every row keeps its value; the rebuild only makes the
 * declaration match the mapping. It runs outside a transaction with foreign keys off, so the
 * rebuild's DROP TABLE does not cascade into the rows that point at these tables.
 */
final class Version20261117120000 extends AbstractMigration
{
    private const TABLES = [
        'maxeme_service_line' => 'quantity NUMERIC(14, 4) NOT NULL',
        'maxeme_repair_order_job_line' => 'quantity NUMERIC(14, 4) NOT NULL',
        'maxeme_invoice_service' => 'quantity NUMERIC(14, 4) DEFAULT 1 NOT NULL',
    ];

    private const PREVIOUS = [
        'maxeme_service_line' => 'quantity NUMERIC(10, 2) NOT NULL',
        'maxeme_repair_order_job_line' => 'quantity NUMERIC(10, 2) NOT NULL',
        'maxeme_invoice_service' => 'quantity NUMERIC(10, 2) DEFAULT 1 NOT NULL',
    ];

    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Service-line quantities at 4 decimals (only totals round to the cent)';
    }

    public function up(Schema $schema): void
    {
        $this->rebuild(self::TABLES);
    }

    public function down(Schema $schema): void
    {
        $this->rebuild(self::PREVIOUS);
    }

    /** @param array<string, string> $definitions table => new quantity column definition */
    private function rebuild(array $definitions): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');
        foreach ($definitions as $table => $definition) {
            foreach (SqliteTableRebuild::statements($this->connection, $table, ['quantity' => $definition]) as $sql) {
                $this->addSql($sql);
            }
        }
        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
