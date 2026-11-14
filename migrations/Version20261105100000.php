<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mockup 986d771: a mileage is in KM or miles. The vehicle, the repair order's "Odometer in" and the
 * invoice's copy of the mileage each say which; every existing row is KM, as everything was printed.
 */
final class Version20261105100000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    private const COLUMNS = [
        'maxeme_vehicle' => 'mileage_unit',
        'maxeme_repair_order' => 'mileage_unit',
        'maxeme_invoice' => 'vehicle_mileage_unit',
    ];

    public function getDescription(): string
    {
        return 'Mileage unit (KM / miles) on vehicles, repair orders and invoices';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (!$this->columnExists($table, $column)) {
                $this->addSql(sprintf("ALTER TABLE %s ADD COLUMN %s VARCHAR(10) DEFAULT 'km' NOT NULL", $table, $column));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::COLUMNS as $table => $column) {
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $column));
        }
    }
}
