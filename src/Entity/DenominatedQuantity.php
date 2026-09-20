<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\Uom\LineDenomination;
use Doctrine\ORM\Mapping as ORM;

/**
 * The two columns {@see DenominatedLine} describes, and the one method that resolves them (#659).
 *
 * ## Why a trait and not a mapped superclass
 *
 * The fourteen rows that carry these columns are core entities, ProcurementBundle entities and
 * WarehouseOpsBundle entities, each already extending nothing and each with a differently NAMED base
 * column — `quantity`, `quantity_ordered`, `quantity_requested`. A mapped superclass would have to
 * own that column, which would mean renaming it on eleven tables. So the base column stays where it
 * is and the implementor wires it up through the two abstract hooks below. Doctrine maps attributes
 * declared in a trait exactly as it maps them on the class, so `quantity_entered` and `unit_id` are
 * declared once here and nowhere else.
 *
 * ## The encoding, and why no row had to be written to get it
 *
 * ```
 * quantity_entered   NULL      "entered in base units" — the entered figure IS the base figure
 * unit_id            NULL      the product's base unit
 * ```
 *
 * NULL is not "unknown" here: with no unit named, the row is denominated in the base unit, and a
 * figure entered in base units is the base figure. {@see getQuantityEntered()} reads it back through
 * that identity rather than through a stored copy — which is why the migration runs no `UPDATE` at
 * all and still leaves every row reading correctly. A stored copy would have needed one, and it
 * would be a copy that can go stale: two columns holding the same number is two chances to disagree.
 *
 * ## Drift is impossible in the other direction too
 *
 * Writing the base column directly — `setQuantity('40')` — says "this row is 40 base units", and the
 * only truthful entered figure for that is 40 in base units. So every base setter calls
 * {@see forgetEnteredExpression()} and the pair goes back to NULL. Without it, a line entered as 3
 * boxes and later cut to 40 units would keep claiming it was three boxes of something, and the
 * printed document would contradict its own arithmetic.
 *
 * ## What #659 changed
 *
 * `packaging_unit_id -> product_packaging_unit` became `unit_id -> unit_of_measure`. The ratio it
 * resolves through is no longer a per-product `factor_to_base` but the two global factors to the
 * family base — `BOX-12` at 12 against a base of `EA` at 1 — which is the same multiplication with
 * the number coming from the one table entitled to hold it. See {@see LineDenomination::factorToBase()}.
 */
trait DenominatedQuantity
{
    /**
     * What the person typed. NULL means "in base units" — see the class docblock.
     *
     * `NUMERIC(14, 4)` like every quantity #601 rules on. It is NOT the figure inventory reads, and
     * deliberately has no reader outside this trait: the base column is the one the reservation
     * reconciler, the stock movement service and the pick confirmation already read, and #659
     * changes neither their name nor their type.
     */
    #[ORM\Column(name: 'quantity_entered', type: 'decimal', precision: 14, scale: 4, nullable: true)]
    private ?string $quantityEntered = null;

    /**
     * The unit the entered figure was said in, or NULL for the product's base unit.
     *
     * No `onDelete`. `SET NULL` would be worse than a refusal here: it would turn "50 BOX-12" into
     * "50 base units" silently, restating a document by a factor of twelve without moving a thing.
     * A unit any row references cannot be restated or deleted at all — that is
     * {@see \App\Service\Uom\UnitOfMeasureService}, and this column is one of the fifteen its
     * metadata sweep finds without being told about them.
     */
    #[ORM\ManyToOne(targetEntity: UnitOfMeasure::class)]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: true)]
    private ?UnitOfMeasure $unitOfMeasure = null;

    /** The existing quantity column, unchanged — see {@see DenominatedLine::getQuantityBase()}. */
    abstract public function getQuantityBase(): string;

    /**
     * Writes the existing quantity column through the implementor's own public setter.
     *
     * Through the setter and not the property, so whatever else that setter maintains still runs:
     * `SalesOrderLine::setQuantity()` re-clamps the backordered split, `PickTask` and
     * `TransferOrderLine` floor at zero. A resolution that bypassed them would be a second way to
     * write a quantity, with different rules.
     */
    abstract protected function storeQuantityBase(string $base): void;

    public function getUnitOfMeasure(): ?UnitOfMeasure
    {
        return $this->unitOfMeasure;
    }

    public function getQuantityEntered(): string
    {
        return $this->quantityEntered ?? $this->getQuantityBase();
    }

    /** Whether this row was said in the product's base unit — true for every row written so far. */
    public function isEnteredInBaseUnits(): bool
    {
        return $this->unitOfMeasure === null;
    }

    /**
     * The one writer of the triple: entered figure, unit, and the base figure resolved from both.
     *
     * `entered x (unit.factorToFamilyBase / base.factorToFamilyBase)`, and both factors are read
     * verbatim off rows that are frozen the moment anything points at them — which is what makes
     * this a multiplication rather than a traversal, and what makes a document written in March keep
     * meaning what it said.
     *
     * The base is written FIRST, because that setter clears the pair; writing the pair first would
     * have it wiped by the very call that resolves it.
     */
    public function setEnteredQuantity(string $entered, ?UnitOfMeasure $unit = null, ?UnitOfMeasure $base = null): static
    {
        $factor = LineDenomination::factorToBase($unit, $base);

        $this->storeQuantityBase(self::formatQuantity((float) $entered * $factor));
        $this->quantityEntered = self::formatQuantity((float) $entered);
        $this->unitOfMeasure = $unit;

        return $this;
    }

    /**
     * Back to "entered in base units" — called by every setter of the base column.
     *
     * Protected rather than public: forgetting how a row was entered is a consequence of restating
     * it, never an operation somebody performs on its own.
     */
    protected function forgetEnteredExpression(): void
    {
        $this->quantityEntered = null;
        $this->unitOfMeasure = null;
    }

    /** Four decimals, the scale #601 rules for every quantity in the app. */
    private static function formatQuantity(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
