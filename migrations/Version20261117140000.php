<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quantities round to 2 decimals: the "Quantity Decimal Places" setting is set to 2. It is
 * created if missing, because without a row QuantityScale falls back to its maximum, 4.
 * Admins can still change it under Settings (0 to 4).
 */
final class Version20261117140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quantity Decimal Places = 2';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE app_setting SET setting_value = '2', updated_at = CURRENT_TIMESTAMP WHERE setting_key = 'quantity_decimal_places'");
        $this->addSql(
            "INSERT INTO app_setting (setting_key, name, setting_value, description, created_at, visibility)
             SELECT 'quantity_decimal_places', 'Quantity Decimal Places', '2',
                    'How many decimals a quantity keeps (0 to 4), e.g. 1.5 hours of labour. Only totals are rounded to the cent. A product whose unit has its own rounding step uses that instead.',
                    CURRENT_TIMESTAMP, 'store'
             WHERE NOT EXISTS (SELECT 1 FROM app_setting WHERE setting_key = 'quantity_decimal_places')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE app_setting SET setting_value = '4', updated_at = CURRENT_TIMESTAMP WHERE setting_key = 'quantity_decimal_places'");
    }
}
