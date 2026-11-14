<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mockup aa322bb / 345c037: the customer's answer to a repair order's quote, recorded with Approve or
 * Decline on the quote page: when and how it was approved, or when it was declined.
 */
final class Version20261114100000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    private const COLUMNS = [
        'quote_approved_at' => 'DATETIME DEFAULT NULL',
        'quote_approved_via' => 'VARCHAR(20) DEFAULT NULL',
        'quote_declined_at' => 'DATETIME DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'Repair order: quote approved at / via, declined at';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as $column => $type) {
            if (!$this->columnExists('maxeme_repair_order', $column)) {
                $this->addSql(sprintf('ALTER TABLE maxeme_repair_order ADD COLUMN %s %s', $column, $type));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::COLUMNS) as $column) {
            $this->addSql(sprintf('ALTER TABLE maxeme_repair_order DROP COLUMN %s', $column));
        }
    }
}
