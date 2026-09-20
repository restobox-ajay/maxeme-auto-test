<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A row that records BOTH what a person said and what the app stores (#601 phase 3, #659).
 *
 * ```
 * quantity_entered    what the human said        50
 * unit_id             the unit they said it in   -> BOX-12   (NULL = the product's base unit)
 * <the existing quantity column>                 600         the only figure inventory ever sees
 * ```
 *
 * ## What #659 changed here, and what it did not
 *
 * The column used to be `packaging_unit_id` and pointed at a per-product packaging rung. It now
 * points at {@see UnitOfMeasure} — the global table that holds the terms AND their ratios, for every
 * family including quantity. `Box-12` and `Box-24` are two terms with two ratios, which is how a
 * count gets baked into a unit without a second table doing the same arithmetic with a second
 * vocabulary. Conversion is defined in exactly one place and nowhere else.
 *
 * What did NOT change is the principle underneath, which #659 restates rather than revisits:
 * **stock is always stored in the product's base unit, and the unit a line was entered in is an
 * entry-and-display convention that never reaches the inventory layer.** A pick list saying "50
 * boxes" is a different physical instruction from "600 eaches" — the picker takes boxes off a pallet
 * rather than unpacking one — so the entered figure has to survive onto the row rather than being
 * flattened at the door. What must NOT survive is that figure reaching `product_inventory` or
 * `inventory_detail`, and the way this interface guarantees it is by never offering the entered
 * figure where a base figure is wanted.
 *
 * ## Why the base column is the one that was already there
 *
 * `sales_order_line.quantity` was always denominated in the product's base unit — there was only
 * ever one unit down there. So nothing is renamed: the column keeps its name, its type and its every
 * reader, and gains two neighbours.
 *
 * ## Not "a line has exactly one quantity"
 *
 * #601 asks explicitly that these interfaces not hardcode that. They do not: this names the ONE
 * quantity the entered figure resolves into and says nothing about the others on the same row.
 * `pick_task` still has `quantity_picked` and `quantity_missing`, `purchase_order_line` still has
 * `quantity_received`, and none of them is an entered figure — they are outcomes, recorded in base
 * units by the warehouse, not typed into a unit selector.
 *
 * Implemented by {@see DenominatedQuantity}, which is where the resolution actually lives.
 */
interface DenominatedLine
{
    /**
     * The figure in the product's base unit — the existing quantity column, unchanged.
     *
     * A decimal string even where the column maps to a PHP `int` (`cart_item`, `pick_task`,
     * `transfer_order_line`), for the reason {@see DocumentLine::getQuantity()} states: the two were
     * always stored differently and widening the return type is cheaper than converting either.
     */
    public function getQuantityBase(): string;

    /**
     * What the person typed, denominated in {@see getUnitOfMeasure()}.
     *
     * Falls back to the base figure when the row never recorded one — which is every row written
     * before this phase, and every row written by a screen with no unit selector on it. That
     * fallback is not a guess: with `unit_id` NULL the row IS denominated in base units, so the
     * entered figure and the base figure are the same number by definition.
     */
    public function getQuantityEntered(): string;

    /**
     * The unit the entered figure was said in. NULL means the product's base unit.
     *
     * Named `getUnitOfMeasure()` rather than `getUnit()` because eleven of these rows already have a
     * `getUnit()` — the legacy free-text U/M snapshot, a string, which #659 does not remove and this
     * must never be confused with. The issue's own complaint is that the owner could not tell which
     * of two concepts a unit field held; two accessors one letter apart would be the same defect in
     * PHP.
     */
    public function getUnitOfMeasure(): ?UnitOfMeasure;

    /**
     * Records an entered figure and the unit it was said in, resolving the base figure from both.
     *
     * The only way to set a unit on a row: the resolution and the two columns it is derived from are
     * written in one call, so a row cannot say "3 BOX-12" while its base column reads 3.
     *
     * $base is the product's base unit, because the ratio between two units is a ratio of their two
     * factors to the family base and one of them is not on this row. Passing it rather than reading
     * it off the product is deliberate: a line can be resolved without the product association being
     * loaded, and a line whose product is gone still has to resolve to the figure it always meant.
     */
    public function setEnteredQuantity(string $entered, ?UnitOfMeasure $unit = null, ?UnitOfMeasure $base = null): static;
}
