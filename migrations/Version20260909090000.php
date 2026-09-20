<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Units of measure, phase 1: the unit model (#601, #643).
 *
 * ## Purely additive, and deliberately incomplete
 *
 * Two new tables, one new column, four new rows. No table is rebuilt, no column is dropped, no
 * existing value is overwritten — so replaying the chain over real data cannot lose any of it, which
 * is the failure `bin/ci-migration-replay` exists to catch.
 *
 * Nothing relies on `PRAGMA foreign_keys = OFF` (silently ignored inside a transaction) because
 * there is nothing to cascade. The SQLite floor stays **3.26**: no `DROP COLUMN` (3.35), no
 * `ALTER COLUMN`, and the one `ADD COLUMN` takes a NULL default, which is the form SQLite accepts on
 * a table that already has rows.
 *
 * ## product_core.unit stays
 *
 * `product_core.unit` is a free-text VARCHAR holding EA / BOX / PR / BAG, and roughly a dozen call
 * sites still read it — the product grid's U/M column, the API presenter, every document line that
 * copies a unit label off the product. This migration ADDS `unit_id` beside it and drops nothing.
 * Removing the text column is a later cleanup, once nothing reads it (#601's phase 4).
 *
 * ## unit_id is left NULL on every existing product
 *
 * Not an oversight and not a TODO. Writing a unit onto twenty thousand existing products would be
 * deciding what their historical quantities meant, and that is a person's call: `EA` in the text
 * column is a label somebody typed, not a declaration anybody made. Nothing in phase 1 reads
 * `unit_id`, so NULL is inert. An admin sets it per product on the product form, where the refusal
 * in App\Service\Uom\ProductBaseUnitService can see the stock that product actually has.
 *
 * ## Four seeded units
 *
 * EA / BOX / PR / BAG — exactly the values `product_core.unit` holds today, so the screen has
 * something to show and the four labels have somewhere to point when a person decides they should.
 * They are ordinary rows: an admin can rename them, add `KG` (family weight, 1000 g to the family
 * base) or delete an unused one. All four ship `family = quantity`, `factor_to_family_base = 1` and
 * `rounding_precision = 1` — countable things, whole numbers only, which is what makes 2.5 EA
 * refusable while 2.5 KG is fine.
 *
 * ## Timestamp
 *
 * Above Version20260908120000, the previous end of the chain.
 */
final class Version20260909090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#643: unit_of_measure + product_packaging_unit, and product_core.unit_id beside the free-text unit.';
    }

    public function up(Schema $schema): void
    {
        // The measurement system. Global: `kg -> g` is universal and one row states it forever.
        $this->addSql("CREATE TABLE unit_of_measure (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            code VARCHAR(16) NOT NULL,
            name VARCHAR(80) NOT NULL,
            family VARCHAR(16) DEFAULT 'quantity' NOT NULL,
            factor_to_family_base NUMERIC(18, 6) DEFAULT '1.000000' NOT NULL,
            rounding_precision NUMERIC(18, 6) DEFAULT '1.000000' NOT NULL
        )");
        $this->addSql('CREATE UNIQUE INDEX uniq_unit_of_measure_code ON unit_of_measure (code)');

        // The packaging ladder. Per product: `case -> each = 12` is a fact about ONE product from
        // ONE supplier, and the day it moves to twenties no other product may move with it.
        // `factor` is relative to the PARENT; `factor_to_base` is resolved on save and read verbatim.
        $this->addSql('CREATE TABLE product_packaging_unit (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            product_id INTEGER NOT NULL,
            name VARCHAR(80) NOT NULL,
            factor NUMERIC(18, 6) DEFAULT \'1.000000\' NOT NULL,
            parent_id INTEGER DEFAULT NULL,
            factor_to_base NUMERIC(18, 6) DEFAULT \'1.000000\' NOT NULL,
            CONSTRAINT FK_product_packaging_unit_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE,
            CONSTRAINT FK_product_packaging_unit_parent FOREIGN KEY (parent_id) REFERENCES product_packaging_unit (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_packaging_unit_name ON product_packaging_unit (product_id, name)');
        $this->addSql('CREATE INDEX IDX_product_packaging_unit_product ON product_packaging_unit (product_id)');
        $this->addSql('CREATE INDEX IDX_product_packaging_unit_parent ON product_packaging_unit (parent_id)');

        $this->addSql("INSERT INTO unit_of_measure (code, name, family, factor_to_family_base, rounding_precision) VALUES ('EA', 'Each', 'quantity', '1.000000', '1.000000')");
        $this->addSql("INSERT INTO unit_of_measure (code, name, family, factor_to_family_base, rounding_precision) VALUES ('BOX', 'Box', 'quantity', '1.000000', '1.000000')");
        $this->addSql("INSERT INTO unit_of_measure (code, name, family, factor_to_family_base, rounding_precision) VALUES ('PR', 'Pair', 'quantity', '1.000000', '1.000000')");
        $this->addSql("INSERT INTO unit_of_measure (code, name, family, factor_to_family_base, rounding_precision) VALUES ('BAG', 'Bag', 'quantity', '1.000000', '1.000000')");

        // NULL on every existing row, and no UPDATE follows. See the class docblock.
        $this->addSql('ALTER TABLE product_core ADD COLUMN unit_id INTEGER DEFAULT NULL REFERENCES unit_of_measure (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_product_core_unit ON product_core (unit_id)');
    }

    /**
     * SQLite 3.26 has no `DROP COLUMN`, so undoing `product_core.unit_id` would mean rebuilding
     * `product_core` — a destructive table copy to remove a column that is inert when unused. This
     * blanks it instead and drops the two new tables, which is the reversal that cannot lose
     * anything: `product_core.unit` was never touched, so the unit labels survive either way.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE product_core SET unit_id = NULL');
        $this->addSql('DROP INDEX IF EXISTS uniq_product_packaging_unit_name');
        $this->addSql('DROP INDEX IF EXISTS IDX_product_packaging_unit_product');
        $this->addSql('DROP INDEX IF EXISTS IDX_product_packaging_unit_parent');
        $this->addSql('DROP TABLE IF EXISTS product_packaging_unit');
        $this->addSql('DROP INDEX IF EXISTS uniq_unit_of_measure_code');
        $this->addSql('DROP TABLE IF EXISTS unit_of_measure');
    }
}
