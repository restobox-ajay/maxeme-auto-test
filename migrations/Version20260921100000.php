<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `custom_field_value_vendor` (#745) — the eighth `custom_field_value_*` table, and the first one a
 * bundle owns rather than core. Same shape as `custom_field_value_company` in
 * `Version20260805040000`: one row per (definition, vendor) pair, FK'd to both, CASCADE on either
 * side deleting.
 *
 * `ProcurementBundle\Entity\VendorCustomFieldValue` maps this table. Pure ADD — no existing table is
 * touched, and `vendor` carries no rows this depends on either way.
 */
final class Version20260921100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add custom_field_value_vendor, so Vendor can be a custom-field object type (#745).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE custom_field_value_vendor ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'value CLOB DEFAULT NULL, '
            . 'definition_id INTEGER NOT NULL, '
            . 'vendor_id INTEGER NOT NULL, '
            . 'CONSTRAINT FK_CFV_VENDOR_DEFINITION FOREIGN KEY (definition_id) REFERENCES custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, '
            . 'CONSTRAINT FK_CFV_VENDOR_VENDOR FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')',
        );
        $this->addSql('CREATE INDEX IDX_CFV_VENDOR_DEFINITION ON custom_field_value_vendor (definition_id)');
        $this->addSql('CREATE INDEX IDX_CFV_VENDOR_VENDOR ON custom_field_value_vendor (vendor_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CUSTOM_FIELD_VALUE_VENDOR ON custom_field_value_vendor (definition_id, vendor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE custom_field_value_vendor');
    }
}
