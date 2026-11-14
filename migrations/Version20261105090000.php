<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mockup 8ef5e05: the unit of measure L (Litre), in the volume family, as its base unit, to three
 * decimals. Added only when no unit with that code exists yet.
 */
final class Version20261105090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unit of measure L (Litre)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO unit_of_measure (code, name, family, factor_to_family_base, rounding_precision)
            SELECT 'L', 'Litre', 'volume', '1.000000', '0.001000'
            WHERE NOT EXISTS (SELECT 1 FROM unit_of_measure WHERE code = 'L')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM unit_of_measure WHERE code = 'L' AND NOT EXISTS (SELECT 1 FROM product_core WHERE unit_id = unit_of_measure.id)");
    }
}
