<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Movement\PackCost;
use InventoryDepthBundle\Repository\PackConversionRepository;

/**
 * What one break — or one unbreak — was: which way, how many cases, and what it was worth (#22).
 *
 * ## This is NOT a second ledger
 *
 * The ledger is `inventory_movement`, and a pack conversion writes exactly two rows in it under one
 * {@see InventoryMovementGroup}: out of the case SKU, into the unit SKU. Every quantity question is
 * answered there and nothing here restates one. This row is the operation's own HEADER, the way
 * `transfer_order` is a header over the movements a transfer causes — one row per group, joined
 * `1:1`, and it exists to carry the three facts the movements cannot:
 *
 *  - **which way round it was.** Derivable from which side of which movement the case SKU sits on,
 *    but only while the rule still exists to say which of the two products IS the case. The rule is
 *    live configuration and may be retired; the record of what happened may not change when it is.
 *  - **the pack size at the time.** Frozen here, so a rule later corrected from 12 to 24 does not
 *    silently restate what a break in March did.
 *  - **the cost.** Nothing else in this database records what a conversion was worth, and without it
 *    "a case costing $24 breaks into 12 units of $2" is a sentence in a docblock rather than a fact
 *    on a row.
 *
 * ## The cost, and what it deliberately is not
 *
 * `caseCost` is `product_core.cost_price` of the case SKU, copied at the moment of the conversion;
 * `unitCost` is that figure divided by `unitsPerCase`, rounded half-up at six places by
 * {@see PackCost}. Both are nullable together, and NULL means the case SKU carried no cost price —
 * the conversion still happens, the stock still moves, and the screen says the value is unknown
 * rather than inventing a zero.
 *
 * **No product's cost price is written by a conversion.** `product_core.cost_price` on the unit SKU
 * is a catalogue figure somebody maintains and this operation has no business overwriting it; the
 * derived figure lives here, on the operation that derived it. Inventory valuation and COGS are
 * parked (`#600`) and this is not the start of them: there is no account, no layer and no running
 * balance, only a record of what one movement carried — exactly as `invoice_line` records a price
 * with no general ledger behind it.
 *
 * ## Why a break-then-unbreak round trip preserves value exactly
 *
 * The case is the anchor in both directions. See {@see PackCost} for the arithmetic and the worked
 * `$25.00 / 12` example; the short version is that the unit figure is the only one that rounds, the
 * residue is recorded by {@see roundingResidue()}, and the reverse conversion restores it because it
 * derives the same two numbers from the same anchor.
 */
#[ORM\Entity(repositoryClass: PackConversionRepository::class)]
#[ORM\Table(name: 'inventory_pack_conversion')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_pack_conversion_group', fields: ['group'])]
#[ORM\Index(name: 'idx_inventory_pack_conversion_rule', fields: ['rule'])]
class PackConversion
{
    /** A case was opened: cases leave the case SKU, `cases × unitsPerCase` arrive at the unit SKU. */
    public const DIRECTION_BREAK = 'break';

    /** The reverse: whole cases' worth of units leave the unit SKU and cases arrive at the case SKU. */
    public const DIRECTION_REBUILD = 'rebuild';

    /** @return list<string> */
    public static function directions(): array
    {
        return [self::DIRECTION_BREAK, self::DIRECTION_REBUILD];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The group holding the two movements. UNIQUE — one conversion is one operation.
     *
     * `CASCADE`, because this row describes that group and is meaningless without it. Nothing
     * deletes a movement group in this application; the cascade is what a deleted PRODUCT causes,
     * and a conversion of a product that no longer exists is not history anybody can read.
     */
    #[ORM\OneToOne(targetEntity: InventoryMovementGroup::class)]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InventoryMovementGroup $group;

    /**
     * The declaration this conversion was run against, for provenance only.
     *
     * `SET NULL` and nullable: retiring a pack rule must not delete or alter the record of the
     * conversions done under it, and every figure this row needs is already copied onto it. The
     * pointer is a convenience for "show me everything ever broken under this declaration", not a
     * dependency.
     */
    #[ORM\ManyToOne(targetEntity: ProductPackRule::class)]
    #[ORM\JoinColumn(name: 'rule_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ProductPackRule $rule = null;

    #[ORM\Column(length: 16)]
    private string $direction = self::DIRECTION_BREAK;

    /** How many cases were broken or rebuilt. Always the CASE count — see ProductPackRule. */
    #[ORM\Column(type: 'integer')]
    private int $cases = 0;

    /** The pack size as it stood, frozen — a later correction to the rule must not restate this. */
    #[ORM\Column(name: 'units_per_case', type: 'integer')]
    private int $unitsPerCase = 0;

    /** `product_core.cost_price` of the CASE SKU at the time. NULL when the product carried none. */
    #[ORM\Column(name: 'case_cost', type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $caseCost = null;

    /** The derived per-unit cost: `caseCost / unitsPerCase`, half-up at six places. */
    #[ORM\Column(name: 'unit_cost', type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $unitCost = null;

    public function getId(): ?int { return $this->id; }
    public function getGroup(): InventoryMovementGroup { return $this->group; }
    public function setGroup(InventoryMovementGroup $group): self { $this->group = $group; return $this; }
    public function getRule(): ?ProductPackRule { return $this->rule; }
    public function setRule(?ProductPackRule $rule): self { $this->rule = $rule; return $this; }
    public function getDirection(): string { return $this->direction; }

    public function setDirection(string $direction): self
    {
        $this->direction = \in_array($direction, self::directions(), true) ? $direction : self::DIRECTION_BREAK;

        return $this;
    }

    public function getCases(): int { return $this->cases; }
    public function setCases(int $cases): self { $this->cases = $cases; return $this; }
    public function getUnitsPerCase(): int { return $this->unitsPerCase; }
    public function setUnitsPerCase(int $unitsPerCase): self { $this->unitsPerCase = $unitsPerCase; return $this; }
    public function getCaseCost(): ?string { return $this->caseCost; }
    public function setCaseCost(?string $caseCost): self { $this->caseCost = $caseCost; return $this; }
    public function getUnitCost(): ?string { return $this->unitCost; }
    public function setUnitCost(?string $unitCost): self { $this->unitCost = $unitCost; return $this; }

    public function isBreak(): bool
    {
        return $this->direction === self::DIRECTION_BREAK;
    }

    /** How many units of the unit SKU this conversion moved. */
    public function units(): int
    {
        return $this->cases * $this->unitsPerCase;
    }

    /** What the case side of this conversion was worth: `cases × caseCost`. NULL with no cost. */
    public function caseSideValue(): ?string
    {
        if ($this->caseCost === null) {
            return null;
        }

        return PackCost::format(PackCost::toMicros($this->caseCost) * $this->cases);
    }

    /** What the unit side was worth: `cases × unitsPerCase × unitCost`. NULL with no cost. */
    public function unitSideValue(): ?string
    {
        if ($this->unitCost === null) {
            return null;
        }

        return PackCost::format(PackCost::toMicros($this->unitCost) * $this->units());
    }

    /** The value this conversion took OUT of stock: the case side on a break, the unit side on a rebuild. */
    public function valueOut(): ?string
    {
        return $this->isBreak() ? $this->caseSideValue() : $this->unitSideValue();
    }

    /** The value it put back IN. */
    public function valueIn(): ?string
    {
        return $this->isBreak() ? $this->unitSideValue() : $this->caseSideValue();
    }

    /**
     * `valueIn − valueOut` — what the division could not express, signed.
     *
     * Negative on a break of a case that does not divide evenly (a $25.00 case of twelve loses four
     * micro-units), positive by the same amount on the unbreak that puts it back. A round trip of
     * the same case count therefore sums to exactly zero, which is the property the feature's
     * sharpest test asserts.
     */
    public function roundingResidue(): ?string
    {
        $in = $this->valueIn();
        $out = $this->valueOut();

        if ($in === null || $out === null) {
            return null;
        }

        return PackCost::format(PackCost::toMicros($in) - PackCost::toMicros($out));
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf('%s %d case(s) of %d', $this->direction, $this->cases, $this->unitsPerCase);
    }
}
