<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #22: `inventory_pack_rule` and `inventory_pack_conversion` — breaking a case, and rebuilding one.
 *
 * Two new tables. Nothing else: no column is added to `product_core`, `product_inventory`,
 * `inventory_detail`, `inventory_movement` or `inventory_movement_group`, and no existing row is
 * read or written by this migration.
 *
 * ## What they are for
 *
 * A distributor's case and its unit are two SKUs — two `product_core` rows, two `product_inventory`
 * balances. `inventory_pack_rule` declares the relationship (one case SKU holds N of one unit SKU);
 * `inventory_pack_conversion` records one application of it, joined `1:1` to the movement group
 * holding the two movements the operation wrote. The MOVEMENTS are the ledger and they already
 * existed; this header carries only what they cannot — which way round it was, the pack size at the
 * time, and what the conversion was worth.
 *
 * ## What this is NOT, and the column that is deliberately absent
 *
 * There is no factor column on any product, and no unit of measure is touched. A unit of measure
 * converts a quantity WITHIN one SKU and moves no stock; this moves stock BETWEEN two SKUs and is a
 * recorded event. `App\Entity\UnitOfMeasure` keeps the first job and this keeps the second, and
 * nothing in either table references the other — see `InventoryDepthBundle\Entity\ProductPackRule`
 * for why merging them would let a mis-picked dropdown relocate inventory.
 *
 * ## CREATE TABLE only, and no table is rebuilt
 *
 * Two new tables touch nothing that exists, so this is transactional and needs no
 * `PRAGMA foreign_keys` dance: there is no Doctrine-emulated column change, therefore no SQLite
 * table rebuild, therefore none of the cascading `DROP TABLE` hazard `Version20260730150000` shipped
 * once already (see `Version20260911235400` for that account). Nothing declares a foreign key
 * POINTING AT either new table either, so a later rebuild of `product_core` or
 * `inventory_movement_group` cannot cascade into them beyond the `ON DELETE` rules written here.
 *
 * ## Created empty, and never populated by inference
 *
 * Both tables start with zero rows and stay that way until somebody declares a pack on
 * `/admin/bundles/inventory-depth/pack-conversion`. There is no signal in existing data that could
 * stand in for a declaration — two SKUs whose names differ by the word "case" is a guess, not a
 * fact — and a guessed pack size is worse than none, because the first break would move the wrong
 * number of units and look deliberate. Same reasoning as `Version20260903090000`'s reorder levels.
 *
 * ## Numbering
 *
 * Above every stamp claimed on ANY ref at the time of writing — main tops out at
 * `Version20260911235400` and the highest across all branches and remotes was the same — and
 * deliberately off the round-hour pattern the other branches use, so two agents landing on one
 * stamp cannot silently drop each other's columns.
 */
final class Version20260912064215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#22: add inventory_pack_rule and inventory_pack_conversion. Two empty tables; no existing row is touched.';
    }

    public function up(Schema $schema): void
    {
        // `units_per_case` is NOT NULL with no default: the row's existence is the declaration, and
        // a default would be a pack size nobody stated. The floor of 2 is enforced in the service
        // rather than by a CHECK — SQLite 3.26 supports table CHECKs, but a constraint that can only
        // be explained in a sentence belongs where the sentence is, and a rule of 1 must produce an
        // error an admin can read rather than a driver exception.
        //
        // `rebuild_allowed` defaults to 0. Twelve loose units are not necessarily a case that can be
        // resealed, so re-forming one is opt-in per pack: a database that runs this migration can
        // break cases the moment a rule is typed and cannot rebuild any until somebody says the pack
        // can be re-formed.
        $this->addSql(
            'CREATE TABLE inventory_pack_rule ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'case_product_id INTEGER NOT NULL, '
            . 'unit_product_id INTEGER NOT NULL, '
            . 'units_per_case INTEGER NOT NULL, '
            . 'rebuild_allowed BOOLEAN DEFAULT 0 NOT NULL, '
            . 'CONSTRAINT FK_inventory_pack_rule_case FOREIGN KEY (case_product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, '
            . 'CONSTRAINT FK_inventory_pack_rule_unit FOREIGN KEY (unit_product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );

        // The directional decision, in the schema. One declaration per CASE SKU, because "what does
        // this case hold" has exactly one answer; the unit side is indexed but NOT unique, because
        // "which case does this unit belong to" legitimately has several — the same bottle ships in
        // a twelve and in a twenty-four.
        $this->addSql('CREATE UNIQUE INDEX uniq_inventory_pack_rule_case ON inventory_pack_rule (case_product_id)');
        $this->addSql('CREATE INDEX idx_inventory_pack_rule_unit ON inventory_pack_rule (unit_product_id)');

        // `case_cost` and `unit_cost` are nullable TOGETHER: a case SKU with no cost price still
        // breaks, and the record says the value is unknown instead of claiming zero. Both are
        // NUMERIC(18, 6), the scale every money column in this application carries, which is what
        // puts the division residue on a $25 case of twelve four MICRO-units out rather than a cent.
        $this->addSql(
            'CREATE TABLE inventory_pack_conversion ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'group_id INTEGER NOT NULL, '
            . 'rule_id INTEGER DEFAULT NULL, '
            . 'direction VARCHAR(16) NOT NULL, '
            . 'cases INTEGER NOT NULL, '
            . 'units_per_case INTEGER NOT NULL, '
            . 'case_cost NUMERIC(18, 6) DEFAULT NULL, '
            . 'unit_cost NUMERIC(18, 6) DEFAULT NULL, '
            . 'CONSTRAINT FK_inventory_pack_conversion_group FOREIGN KEY (group_id) REFERENCES inventory_movement_group (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, '
            . 'CONSTRAINT FK_inventory_pack_conversion_rule FOREIGN KEY (rule_id) REFERENCES inventory_pack_rule (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );

        // One conversion is one operation. Without this, two header rows could describe one group
        // and the screen would show the same break twice with different costs.
        $this->addSql('CREATE UNIQUE INDEX uniq_inventory_pack_conversion_group ON inventory_pack_conversion (group_id)');

        // `SET NULL` on the rule means this column goes null rather than the row going away when a
        // declaration is retired — so it is nullable, sparse, and read by "everything ever broken
        // under this declaration", which is the only query that filters on it.
        $this->addSql('CREATE INDEX idx_inventory_pack_conversion_rule ON inventory_pack_conversion (rule_id)');
    }

    /**
     * Safe to reverse. Both tables are created here, nothing outside them references either, and
     * neither held a row before this ran — so there is no cascade to get wrong and nothing that
     * existed beforehand to lose.
     *
     * Declarations typed since it ran DO go, and so do the conversion HEADERS. The movement groups
     * and the two movements under each of them stay exactly where they are: they are the ledger,
     * they were written by the layer that already existed, and the stock they moved is on the shelf
     * either way. That is the right split — rolling this feature back removes the feature's own
     * record of WHY two movements happened together, not the fact that they did.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inventory_pack_conversion');
        $this->addSql('DROP TABLE inventory_pack_rule');
    }
}
