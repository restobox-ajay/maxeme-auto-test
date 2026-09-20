<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #422: custom_field_value was one shared table for all five object types, keyed on a bare
 * unconstrained object_id int — no FK meant no ON DELETE CASCADE, so a deleted order/product/
 * company/company_address/product_category left its custom field values behind as orphan rows.
 *
 * Splits it into one physical table per object type, each with a real FK (ON DELETE CASCADE) to
 * its target entity: custom_field_value_product -> product_core, custom_field_value_company ->
 * company, custom_field_value_order -> sales_order, custom_field_value_company_address ->
 * company_address, custom_field_value_product_category -> product_category. custom_field_definition
 * is untouched — it's metadata with no entity-row FK to gain, so splitting it would only fragment
 * the admin custom-field index for no benefit.
 *
 * App\Repository\CustomFieldValueRepository still exposes the same objectType-agnostic API
 * (getValue/setValue/getValuesForObject(s)) it always did, dispatching to the right table
 * internally, so every bundle that registers or reads a custom field keeps working unchanged.
 */
final class Version20260805040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split custom_field_value into one table per object type, each FK-constrained to its target entity (#422).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('custom_field_value_product')) {
            $this->addSql('CREATE TABLE custom_field_value_product (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, value CLOB DEFAULT NULL, definition_id INTEGER NOT NULL, product_id INTEGER NOT NULL, CONSTRAINT FK_CFV_PRODUCT_DEFINITION FOREIGN KEY (definition_id) REFERENCES custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CFV_PRODUCT_PRODUCT FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_CFV_PRODUCT_DEFINITION ON custom_field_value_product (definition_id)');
            $this->addSql('CREATE INDEX IDX_CFV_PRODUCT_PRODUCT ON custom_field_value_product (product_id)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_CUSTOM_FIELD_VALUE_PRODUCT ON custom_field_value_product (definition_id, product_id)');
        }

        if (!$schema->hasTable('custom_field_value_company')) {
            $this->addSql('CREATE TABLE custom_field_value_company (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, value CLOB DEFAULT NULL, definition_id INTEGER NOT NULL, company_id INTEGER NOT NULL, CONSTRAINT FK_CFV_COMPANY_DEFINITION FOREIGN KEY (definition_id) REFERENCES custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CFV_COMPANY_COMPANY FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_CFV_COMPANY_DEFINITION ON custom_field_value_company (definition_id)');
            $this->addSql('CREATE INDEX IDX_CFV_COMPANY_COMPANY ON custom_field_value_company (company_id)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_CUSTOM_FIELD_VALUE_COMPANY ON custom_field_value_company (definition_id, company_id)');
        }

        if (!$schema->hasTable('custom_field_value_order')) {
            $this->addSql('CREATE TABLE custom_field_value_order (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, value CLOB DEFAULT NULL, definition_id INTEGER NOT NULL, order_id INTEGER NOT NULL, CONSTRAINT FK_CFV_ORDER_DEFINITION FOREIGN KEY (definition_id) REFERENCES custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CFV_ORDER_ORDER FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_CFV_ORDER_DEFINITION ON custom_field_value_order (definition_id)');
            $this->addSql('CREATE INDEX IDX_CFV_ORDER_ORDER ON custom_field_value_order (order_id)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_CUSTOM_FIELD_VALUE_ORDER ON custom_field_value_order (definition_id, order_id)');
        }

        if (!$schema->hasTable('custom_field_value_company_address')) {
            $this->addSql('CREATE TABLE custom_field_value_company_address (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, value CLOB DEFAULT NULL, definition_id INTEGER NOT NULL, company_address_id INTEGER NOT NULL, CONSTRAINT FK_CFV_COMPADDR_DEFINITION FOREIGN KEY (definition_id) REFERENCES custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CFV_COMPADDR_COMPADDR FOREIGN KEY (company_address_id) REFERENCES company_address (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_CFV_COMPADDR_DEFINITION ON custom_field_value_company_address (definition_id)');
            $this->addSql('CREATE INDEX IDX_CFV_COMPADDR_COMPADDR ON custom_field_value_company_address (company_address_id)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_CUSTOM_FIELD_VALUE_COMPANY_ADDRESS ON custom_field_value_company_address (definition_id, company_address_id)');
        }

        if (!$schema->hasTable('custom_field_value_product_category')) {
            $this->addSql('CREATE TABLE custom_field_value_product_category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, value CLOB DEFAULT NULL, definition_id INTEGER NOT NULL, product_category_id INTEGER NOT NULL, CONSTRAINT FK_CFV_PRODCAT_DEFINITION FOREIGN KEY (definition_id) REFERENCES custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CFV_PRODCAT_PRODCAT FOREIGN KEY (product_category_id) REFERENCES product_category (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_CFV_PRODCAT_DEFINITION ON custom_field_value_product_category (definition_id)');
            $this->addSql('CREATE INDEX IDX_CFV_PRODCAT_PRODCAT ON custom_field_value_product_category (product_category_id)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_CUSTOM_FIELD_VALUE_PRODUCT_CATEGORY ON custom_field_value_product_category (definition_id, product_category_id)');
        }
    }

    /** Runs after the new tables above are committed, so it's safe to copy out of the old table and then drop it. */
    public function postUp(Schema $schema): void
    {
        if (!$schema->hasTable('custom_field_value')) {
            return;
        }

        $copies = [
            'product' => ['custom_field_value_product', 'product_id'],
            'company' => ['custom_field_value_company', 'company_id'],
            'order' => ['custom_field_value_order', 'order_id'],
            'company_address' => ['custom_field_value_company_address', 'company_address_id'],
            'product_category' => ['custom_field_value_product_category', 'product_category_id'],
        ];

        foreach ($copies as $objectType => [$table, $column]) {
            $this->connection->executeStatement(
                "INSERT INTO {$table} (definition_id, {$column}, value)
                 SELECT v.definition_id, v.object_id, v.value
                 FROM custom_field_value v
                 INNER JOIN custom_field_definition d ON d.id = v.definition_id
                 WHERE d.object_type = ?",
                [$objectType],
            );
        }

        $this->connection->executeStatement('DROP TABLE custom_field_value');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('custom_field_value')) {
            $this->addSql('CREATE TABLE custom_field_value (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, object_id INTEGER NOT NULL, value CLOB DEFAULT NULL, definition_id INTEGER NOT NULL, CONSTRAINT FK_EC7B05D11EA911 FOREIGN KEY (definition_id) REFERENCES custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_EC7B05D11EA911 ON custom_field_value (definition_id)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_CUSTOM_FIELD_VALUE ON custom_field_value (definition_id, object_id)');
        }
    }

    public function postDown(Schema $schema): void
    {
        $copies = [
            ['custom_field_value_product', 'product_id'],
            ['custom_field_value_company', 'company_id'],
            ['custom_field_value_order', 'order_id'],
            ['custom_field_value_company_address', 'company_address_id'],
            ['custom_field_value_product_category', 'product_category_id'],
        ];

        foreach ($copies as [$table, $column]) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            $this->connection->executeStatement(
                "INSERT INTO custom_field_value (definition_id, object_id, value)
                 SELECT definition_id, {$column}, value FROM {$table}"
            );
            $this->connection->executeStatement("DROP TABLE {$table}");
        }
    }
}
