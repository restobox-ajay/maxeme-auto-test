<?php

declare(strict_types=1);

namespace App\Service\Uom;

use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;

/**
 * The arithmetic and the wording of a document line's denomination (#601 phase 4, #659).
 *
 * ## One selector, two directions
 *
 * A line carries a quantity, the unit it was said in, and a price. The unit is real — it is
 * persisted, it prints, and it reaches the pick task. Its effect on the PRICE is entry-only:
 *
 * ```
 * entered   40  BOX-12   $6.00 per BOX-12
 * stored    quantity 480   quantity_entered 40   unit_id -> BOX-12   price 0.500000 per EA
 * printed   40   BOX-12   $6.00   $240.00
 * ```
 *
 * So every method here is one of two conversions — up into base units for storage, or back down
 * into the line's own denomination for a person to read. Nothing derived here is ever stored except
 * the two figures the line actually persists.
 *
 * ## Where the ratio comes from after #659
 *
 * From {@see UnitOfMeasure::$factorToFamilyBase} on TWO rows, not from a per-product `factor_to_base`
 * on one. Every unit states how many of its family's base it holds — `BOX-12` says 12, `EA` says 1,
 * `G` says 1 where `KG` says 1000 — so the ratio between the line's unit and the product's base unit
 * is the quotient of their two factors. That is the whole of what retiring `product_packaging_unit`
 * costs and buys: one table defines conversion, and `Box-12` versus `Box-24` is a difference between
 * two terms rather than a number typed onto each product.
 *
 * Both factors are frozen the moment anything references the unit ({@see UnitOfMeasureService}), so
 * a line resolved in March keeps resolving to the same base figure forever. That is what makes this
 * a multiplication and not a lookup that can change under a document.
 *
 * ## Why the price divides on the way IN and multiplies on the way OUT
 *
 * The owner's decision, settled on #601 (2026-09-09): one denomination everywhere, the base unit.
 * Division loses something — `10.00 / 12 = 0.833333` — and multiplying back gives `9.999996`, which
 * prints as `$10.00`. Six decimals is what puts that residue below a cent. The residual is about
 * 3e-7 per base unit, so a single line would need roughly 30,000 units before it moved a cent.
 * Known, accepted, not designed around.
 *
 * ## Rounding
 *
 * Once, at the line, then the rounded lines are summed. {@see lineTotal()} is the only rounding
 * point this class owns and it rounds to the cent. The running document subtotal is
 * App\Service\SalesDocumentMoney's business and is untouched — the two flows disagree about where
 * the document total rounds, that is #258, and it is not this issue.
 *
 * ## The label is now the code, and the brackets are gone
 *
 * A packaged line used to print `CASE(12)` and a base-unit line `EA`, and #659 names that as a
 * defect: the brackets were the only thing distinguishing two concepts sharing one column, and they
 * were accidental. With one vocabulary there is one kind of label — the unit's own code — and a term
 * that needs to say twelve says it in its code, `BOX-12`, because that is what makes `Box-12` and
 * `Box-24` two terms in the first place.
 */
final class LineDenomination
{
    /** Quantities are `NUMERIC(14, 4)` since #645. */
    public const QUANTITY_SCALE = 4;

    /** Unit prices and rates are `NUMERIC(18, 6)` since #645. */
    public const RATE_SCALE = 6;

    /** A total is money, and money has two places. */
    public const MONEY_SCALE = 2;

    /**
     * How many of the product's base unit one of $unit holds; 1 when the line is in the base unit.
     *
     * `unit.factorToFamilyBase / base.factorToFamilyBase`. Both rows are frozen once referenced, so
     * the quotient is stable for the life of the document.
     *
     * Falls back to 1 wherever the answer cannot be computed — no unit named, no base declared, or a
     * non-positive factor that no service can write. That is a guard against a hand-edited row and
     * not a rule: it makes such a line read as a base-unit line instead of dividing by zero halfway
     * through rendering an invoice.
     *
     * A missing base is deliberately NOT treated as "the unit's own factor". A product with no
     * declared base unit has no scale to convert INTO, and inventing one would silently multiply its
     * stock figure by twelve.
     */
    public static function factorToBase(?UnitOfMeasure $unit, ?UnitOfMeasure $base): float
    {
        if ($unit === null || $base === null) {
            return 1.0;
        }

        $unitFactor = (float) $unit->getFactorToFamilyBase();
        $baseFactor = (float) $base->getFactorToFamilyBase();

        if ($unitFactor <= 0.0 || $baseFactor <= 0.0) {
            return 1.0;
        }

        return $unitFactor / $baseFactor;
    }

    /** The product's declared base unit, or null — the second half of every ratio above. */
    public static function baseUnitOf(?ProductCore $product): ?UnitOfMeasure
    {
        return $product?->getBaseUnit();
    }

    /**
     * What the human said, in base units — the only figure the inventory layer ever sees.
     *
     * A line with no unit — or a product with no declared base to convert into — is handed back
     * verbatim rather than reformatted. Every conversion here follows that rule: where there is
     * nothing to convert there is nothing to restate either, and a formatting pass on the way to a
     * box the next save reads is how a figure nobody typed gets written (#259).
     */
    public static function toBaseQuantity(string $entered, ?UnitOfMeasure $unit, ?UnitOfMeasure $base): string
    {
        if ($unit === null || $base === null) {
            return $entered;
        }

        return number_format((float) $entered * self::factorToBase($unit, $base), self::QUANTITY_SCALE, '.', '');
    }

    /**
     * A base figure re-expressed in $unit — how the selector RE-EXPRESSES a line instead of
     * reinterpreting it.
     *
     * Switching `BOX-12` to `PALLET-240` on a line of 480 base units gives 2 pallets, not 40 of them.
     * Whole units are not forced: 480 base against a unit of 200 is 2.4, which is a true statement
     * about an order that has not changed size. Forcing it to 2 or 3 would change the order.
     */
    public static function toEnteredQuantity(string $baseQuantity, ?UnitOfMeasure $unit, ?UnitOfMeasure $base): string
    {
        if ($unit === null || $base === null) {
            return $baseQuantity;
        }

        return number_format((float) $baseQuantity / self::factorToBase($unit, $base), self::QUANTITY_SCALE, '.', '');
    }

    /** The price box's figure, converted to the per-base-unit rate that is actually stored. */
    public static function toBasePrice(string $enteredPrice, ?UnitOfMeasure $unit, ?UnitOfMeasure $base): string
    {
        if ($unit === null || $base === null) {
            return $enteredPrice;
        }

        return number_format((float) $enteredPrice / self::factorToBase($unit, $base), self::RATE_SCALE, '.', '');
    }

    /**
     * The stored rate, back in the line's own denomination — `$0.500000/EA` reads as `$6.00` per
     * `BOX-12`. Derived at render, never stored.
     */
    public static function toUnitPrice(string $basePrice, ?UnitOfMeasure $unit, ?UnitOfMeasure $base): string
    {
        if ($unit === null || $base === null) {
            return $basePrice;
        }

        return number_format((float) $basePrice * self::factorToBase($unit, $base), self::MONEY_SCALE, '.', '');
    }

    /**
     * Base quantity x base rate, rounded to the cent — the line's own amount.
     *
     * Deliberately computed from the BASE pair rather than from the displayed pair: `40 x $10.00`
     * and `480 x $0.833333` differ by 0.00016, and the stored figures are the ones the document
     * total is built from. Null when the line has no price at all, which on a quote means "TBD" and
     * is not the same as zero.
     */
    public static function lineTotal(string $baseQuantity, ?string $basePrice): ?string
    {
        if ($basePrice === null) {
            return null;
        }

        return number_format((float) $baseQuantity * (float) $basePrice, self::MONEY_SCALE, '.', '');
    }

    /**
     * What the U/M column prints: the unit's code, or the base unit's when the line names none.
     *
     * No brackets and no pack size beside the name. A term carries its own count — `BOX-12` is
     * twelve and `BOX-24` is twenty-four, and they are two rows — so printing the ratio as well
     * would state the same fact twice on the same document.
     */
    public static function label(?UnitOfMeasure $unit, ?string $legacyUnit, ?ProductCore $product): string
    {
        if ($unit === null) {
            return self::baseLabel($legacyUnit, $product);
        }

        $code = trim($unit->getCode());

        return $code !== '' ? $code : self::baseLabel($legacyUnit, $product);
    }

    /**
     * What the BASE figure is counted in — `EA`.
     *
     * The line's own `unit` string is preferred over the product's live base unit code because it is
     * a snapshot taken when the line was written, and a document says what it said. The product is
     * the fallback for a line that never carried one, and `-` is what every U/M cell in this app
     * already prints for a line with neither.
     */
    public static function baseLabel(?string $legacyUnit, ?ProductCore $product): string
    {
        $legacy = trim((string) $legacyUnit);
        if ($legacy !== '') {
            return $legacy;
        }

        $code = $product?->getBaseUnit()?->getCode() ?? '';

        return $code !== '' ? $code : '-';
    }

    /**
     * Whether a posted box came back exactly as the server rendered it — the test that makes
     * "changing the selector RE-EXPRESSES the line, it does not reinterpret it" true.
     *
     * ## The bug this exists to refuse
     *
     * The selector is a plain `<select>` and the app works with scripting off, so switching `BOX-12`
     * to `PALLET-240` is: change the dropdown, press save. The quantity box still says 40, because
     * nobody touched it. Reading that 40 in the newly-selected unit turns 480 eaches into 9,600 —
     * the order multiplied by twenty, silently, by a person who changed one dropdown.
     *
     * So each row also posts what the server put in the box. An untouched box means the line's SIZE
     * did not change, whatever unit is now selected, and the save keeps the stored base figure and
     * re-expresses it. A box that differs is a figure the admin typed, and it is read in the unit
     * they selected — which is the only reading of "40" that can mean 40 pallets.
     *
     * A row the browser built, a spare no-JS row, and any post that carries no rendered value at all
     * all answer false: with nothing to compare against there is no evidence the box was untouched,
     * and the caller falls back to reading the box, exactly as this form always did.
     *
     * Compared numerically as well as textually, because `40` and `40.0000` are the same figure and
     * the round trip through a decimal column changes which of the two comes back.
     */
    public static function boxUntouched(mixed $typed, mixed $rendered): bool
    {
        $renderedRaw = is_scalar($rendered) ? trim((string) $rendered) : '';
        if ($renderedRaw === '') {
            return false;
        }

        $typedRaw = is_scalar($typed) ? trim((string) $typed) : '';
        if ($typedRaw === $renderedRaw) {
            return true;
        }

        return is_numeric($typedRaw) && is_numeric($renderedRaw) && (float) $typedRaw === (float) $renderedRaw;
    }

    /** `12.000000` reads as `12`, `2.500000` as `2.5`. Display only. */
    public static function trimZeros(string $value): string
    {
        return str_contains($value, '.')
            ? (rtrim(rtrim($value, '0'), '.') ?: '0')
            : $value;
    }
}
