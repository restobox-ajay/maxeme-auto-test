<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #601: point every product at the unit of measure its own typed label already named.
 *
 * ## Why a backfill is needed rather than "new products only"
 *
 * #643 added `product_core.unit_id` and left it NULL on every existing row, deliberately —
 * nothing read it yet. The free-text `product_core.unit` went on being the thing an admin typed
 * and the thing ~40 readers printed.
 *
 * The form now declares the unit through `unit_id` and derives the label from it. Without
 * this backfill every product that already exists would open showing "Not set" beside a label
 * reading `EA`, and an admin would have to re-declare, one product at a time, a fact the row
 * already states. That is a worse screen than the one being replaced.
 *
 * ## No value is invented, and nothing is guessed
 *
 * The match is EXACT against `unit_of_measure.code`, case-insensitively and ignoring surrounding
 * whitespace, and only where `unit_id` is still NULL. A product already pointing somewhere is
 * never repointed — that is the one thing this must not do, because a base unit that changes under
 * existing stock silently restates every figure denominated in it (see ProductBaseUnitService,
 * which refuses exactly that).
 *
 * The seeded codes are `EA`, `BOX`, `PR` and `BAG` — which is not a coincidence: #643 seeded
 * `unit_of_measure` FROM the distinct values this column already held, so an exact match is the
 * whole of the intended mapping and not a heuristic.
 *
 * ## What is deliberately left alone
 *
 * Values that match no code are NOT mapped, NOT cleared and NOT guessed at. They exist: the product
 * import documents its `unit` column with the example `12/Case`, which is a PACK SIZE and not a
 * unit of measure at all. Under #601 those are `product_packaging_unit` rows, a different table
 * with a different meaning, and turning "12/Case" into a base unit of `EA` would be inventing a
 * fact nobody stated.
 *
 * Those rows keep their label, keep a NULL base unit, and the product form names them on screen
 * ("this product's stored label is `12/Case`, which is not one of the units above") so a human
 * resolves them deliberately. A migration that silently made them `EA` would look tidier and be a
 * data-quality problem nobody could find afterwards.
 *
 * ## Reversibility
 *
 * `down()` clears only what `up()` set, and only where the label still agrees with the code it was
 * matched from — so a base unit a human declared or changed after this ran is left alone. The
 * label column is not touched in either direction: it held these values before this migration and
 * still holds them after.
 */
final class Version20260911120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#601: backfill product_core.unit_id from the free-text product_core.unit where it '
            . 'exactly matches a unit_of_measure code. Labels are not modified and unmatched values are left alone.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE product_core SET unit_id = ('
            . ' SELECT u.id FROM unit_of_measure u'
            . ' WHERE UPPER(TRIM(u.code)) = UPPER(TRIM(product_core.unit))'
            . ') WHERE unit_id IS NULL AND unit IS NOT NULL AND TRIM(unit) <> \'\''
            . ' AND EXISTS ('
            . ' SELECT 1 FROM unit_of_measure u2'
            . ' WHERE UPPER(TRIM(u2.code)) = UPPER(TRIM(product_core.unit))'
            . ')',
        );
    }

    public function down(Schema $schema): void
    {
        // Only rows this migration could have set: the pointer still agrees with the label it was
        // matched from. A product somebody has since declared differently keeps that declaration.
        $this->addSql(
            'UPDATE product_core SET unit_id = NULL'
            . ' WHERE unit_id IS NOT NULL AND unit IS NOT NULL'
            . ' AND EXISTS ('
            . ' SELECT 1 FROM unit_of_measure u'
            . ' WHERE u.id = product_core.unit_id'
            . ' AND UPPER(TRIM(u.code)) = UPPER(TRIM(product_core.unit))'
            . ')',
        );
    }
}
