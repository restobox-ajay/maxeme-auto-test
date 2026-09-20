<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #659 step 2: which units a product may be expressed in, and which one a line starts on.
 *
 * ## Additive, and inert on every row that already exists
 *
 * `product_available_unit` starts empty and `product_core.default_unit_id` is NULL on every existing
 * product. Neither is read by anything shipped before this migration, so nothing changes meaning
 * when it applies. Blank is the legitimate resting state, not a half-configured one: a product may
 * declare no units at all, which is NetSuite's own behaviour (units are opt-in per item) and the
 * owner's explicit ruling.
 *
 * ## No ratio column, deliberately
 *
 * The join table holds availability and nothing else. `Box-12` holds twelve because
 * `unit_of_measure.factor_to_family_base` says so, once, for the whole instance. A factor here would
 * be the second place a conversion is defined — which is the exact defect #659 exists to remove, and
 * it would arrive in the table built to remove it.
 *
 * ## Why a join table rather than a column on the product
 *
 * A product is available in several units at once, and a comma-separated column is a foreign key
 * nobody can enforce. `RESTRICT` on `unit_id` is what makes "a unit some product is expressed in
 * cannot be deleted out from under it" a fact about the database rather than a rule the screen
 * happens to apply.
 *
 * Numbered above everything claimed on any ref today — main is at `Version20260911200000` and
 * `feat/standalone-invoice-create` holds `190000` unmerged. Two branches colliding on one stamp
 * earlier this week dropped columns with no failing test.
 */
final class Version20260911235000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#659 step 2: product_available_unit + product_core.default_unit_id — per-product scoping for global units.';
    }

    public function up(Schema $schema): void
    {
        // Availability only. The ratio lives on unit_of_measure and nowhere else.
        $this->addSql('CREATE TABLE product_available_unit (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            product_id INTEGER NOT NULL,
            unit_id INTEGER NOT NULL,
            CONSTRAINT FK_product_available_unit_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE,
            CONSTRAINT FK_product_available_unit_unit FOREIGN KEY (unit_id) REFERENCES unit_of_measure (id) ON DELETE RESTRICT
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_available_unit ON product_available_unit (product_id, unit_id)');
        $this->addSql('CREATE INDEX IDX_product_available_unit_product ON product_available_unit (product_id)');
        $this->addSql('CREATE INDEX IDX_product_available_unit_unit ON product_available_unit (unit_id)');

        // NULL on every existing row, and no UPDATE follows: NULL means "the picker opens on the
        // base unit", which is what every line already did.
        $this->addSql('ALTER TABLE product_core ADD COLUMN default_unit_id INTEGER DEFAULT NULL REFERENCES unit_of_measure (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_product_core_default_unit ON product_core (default_unit_id)');
    }

    /**
     * SQLite 3.26 has no `DROP COLUMN`, so undoing `default_unit_id` would mean rebuilding
     * `product_core` — a destructive table copy to remove a column that is inert when unused. This
     * blanks it instead and drops the join table, which is the reversal that cannot lose anything
     * the schema did not itself create.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE product_core SET default_unit_id = NULL');
        $this->addSql('DROP INDEX IF EXISTS IDX_product_core_default_unit');
        $this->addSql('DROP INDEX IF EXISTS uniq_product_available_unit');
        $this->addSql('DROP INDEX IF EXISTS IDX_product_available_unit_product');
        $this->addSql('DROP INDEX IF EXISTS IDX_product_available_unit_unit');
        $this->addSql('DROP TABLE IF EXISTS product_available_unit');
    }
}
