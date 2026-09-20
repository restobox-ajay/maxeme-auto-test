<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\Uom\LineDenomination;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'estimate_line')]
#[ORM\Index(name: 'idx_estimate_line_unit', fields: ['unitOfMeasure'])]
class EstimateLine implements CostedDocumentLine, DenominatedLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Estimate::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'estimate_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Estimate $estimate;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true)]
    private ?ProductCore $product = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $quantity = '1.00';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $weight = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $taxCode = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6)]
    private string $cost = '0.00';

    // Nullable — null means this line's price is still TBD, distinct from a real $0 price.
    #[ORM\Column(type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $subtotal = null;

    // No $batch here on purpose: a batch/lot is allocated at fulfillment, and a quote allocates
    // nothing, so any value a quote could carry would be speculative. It lives on SalesOrderLine,
    // where the allocation is real (#250).

    /**
     * Where this row sits on the document. Restated from the submitted order on every save
     * (EstimateController::applyLinesFromRequest()), and what Estimate::$lines is ordered by — the
     * collection had no ORDER BY at all, so a line added in the middle came back at the bottom, and
     * the per-line Tax $ and quantity-warning indexes (recorded by submission position) pointed at
     * whichever row the database happened to hand back at that position.
     */
    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }
    public function getEstimate(): Estimate { return $this->estimate; }
    public function setEstimate(Estimate $estimate): self { $this->estimate = $estimate; return $this; }
    public function getProduct(): ?ProductCore { return $this->product; }
    public function setProduct(?ProductCore $product): self { $this->product = $product; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self { $this->location = $location; return $this; }
    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }
    public function getQuantity(): string { return $this->quantity; }
    /**
     * Restating the base figure forgets how it was entered (#601, #646).
     *
     * Writing this column directly says "the row is this many BASE units", and the only
     * truthful entered figure for that is the same number in base units — which is what
     * `quantity_entered` NULL and `unit_id` NULL mean. See DenominatedQuantity.
     */
    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;
        $this->forgetEnteredExpression();

        return $this;
    }
    public function getWeight(): ?string { return $this->weight; }
    public function setWeight(?string $weight): self { $this->weight = $weight; return $this; }
    public function getUnit(): ?string { return $this->unit; }
    public function setUnit(?string $unit): self { $this->unit = $unit; return $this; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function setTaxCode(?string $taxCode): self { $this->taxCode = $taxCode; return $this; }
    public function getCost(): string { return $this->cost; }
    public function setCost(string $cost): self { $this->cost = $cost; return $this; }
    public function getPrice(): ?string { return $this->price; }
    public function setPrice(?string $price): self { $this->price = $price; return $this; }
    public function getSubtotal(): ?string { return $this->subtotal; }
    public function setSubtotal(?string $subtotal): self { $this->subtotal = $subtotal; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    // ── How the line READS (#601, phase 4 #644) ──────────────────────────────────────────────
    //
    // Everything below is derived at render from the three figures the row already stores —
    // the base quantity, the rung, and the per-base rate — and none of it is ever written back.
    // On a line entered in base units every one of them answers what the screens printed before
    // this phase existed, character for character.

    /**
     * The code of the unit this line was entered in, or the base unit's when it names none (#659).
     *
     * No bracketed pack size any more. A term carries its own count — `BOX-12` is twelve and
     * `BOX-24` is twenty-four, and they are two rows of `unit_of_measure` — so printing the ratio
     * beside the code would state the same fact twice on the same document. The brackets used to be
     * the only thing distinguishing a packaging rung from a base unit in this column, which #659
     * names as a defect rather than a convention.
     */
    public function getDisplayUnitLabel(): string
    {
        return LineDenomination::label($this->getUnitOfMeasure(), $this->unit, $this->product);
    }

    /** `EA` — what {@see getQuantityBase()} is counted in. */
    public function getBaseUnitLabel(): string
    {
        return LineDenomination::baseLabel($this->unit, $this->product);
    }

    /**
     * The stored per-base rate expressed per {@see getDisplayUnitLabel()} — `$0.500000/EA` reads as
     * `$6.00` per Case. Derived at render, never stored.
     *
     * An unpackaged line returns the stored figure untouched rather than a formatted copy of it:
     * there is no conversion to perform, and reformatting would put a second rounding point between
     * the column and the box the next save reads (#259).
     */
    public function getDisplayUnitPrice(): ?string
    {
        if ($this->price === null || $this->getUnitOfMeasure() === null) {
            return $this->price;
        }

        return LineDenomination::toUnitPrice($this->price, $this->getUnitOfMeasure(), LineDenomination::baseUnitOf($this->product));
    }

    /**
     * The stored per-base rate at its full six places — `0.500000`, not `0.5`.
     *
     * SQLite's NUMERIC affinity hands `0.500000` back as `0.5`, so the raw column value is not a
     * stable way to SHOW a rate: the resolved-price hint beside the price box would read
     * `= $0.5 / EA` on one line and `= $0.833333 / EA` on the next. Formatting only — the same
     * normalisation UnitOfMeasure::getFactorToFamilyBase() performs on the way out, and for the same
     * reason.
     */
    public function getBaseUnitRate(): ?string
    {
        return $this->price === null
            ? null
            : number_format((float) $this->price, LineDenomination::RATE_SCALE, '.', '');
    }

    /**
     * Base quantity x base rate, rounded once, at the line — what the Amount column prints.
     *
     * Computed from the two STORED figures rather than from the two displayed ones: `40 x $10.00`
     * and `480 x $0.833333` are not the same number, and the stored pair is what the document total
     * is built from.
     */
    public function getLineTotal(): ?string
    {
        return LineDenomination::lineTotal($this->getQuantityBase(), $this->price);
    }

    /** The base figure #601 denominates everything in: `quantity`, unchanged by phase 3. */
    public function getQuantityBase(): string { return (string) $this->quantity; }

    protected function storeQuantityBase(string $base): void { $this->setQuantity($base); }
}
