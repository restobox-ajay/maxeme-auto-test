<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;

/**
 * A product row on a credit note (#586) — the fifth DocumentLine, beside CartItem, EstimateLine,
 * SalesOrderLine and InvoiceLine.
 *
 * Its own entity for the reason DocumentLine states: a mapped superclass cannot parametrize
 * targetEntity, so each document subtype owns its rows and only the shape is shared.
 *
 * ## Quantities are POSITIVE
 *
 * A credit note is not a negative invoice, and this row is not a negative invoice line. It says
 * "four of these came back", and four is four. Every quantity aggregate in the app assumes positive
 * quantities — SalesOrder::uninvoicedQuantityFor(), the sales-hold split, the backorder arithmetic,
 * InvoiceInventoryBucketResolver's mapping of a status onto a bucket — so a sign flip would not
 * express "release" anywhere, it would push negatives into `pending_quantity` and
 * `approved_quantity`. Releasing a hold is a different operation from holding a negative amount,
 * and this is the row that describes how much to release.
 *
 * ## $invoiceLine is what makes the release possible
 *
 * Null when nothing is being credited back against a specific billed row — a goodwill credit, a
 * pricing correction, a note raised before anyone decided which invoice it lands against. When it
 * IS set, it is the whole mechanism behind the inventory net-out: InvoiceLine::getCreditedUnits()
 * sums these rows and InvoiceReservationSubject::stockedQuantityFor() subtracts the total from what
 * that invoice line holds. No second reservation entity, no second copy of the reconciler's diff —
 * see InvoiceReservationSubject for the argument in full.
 *
 * SET NULL rather than CASCADE, matching InvoiceLine::$salesOrderLine: losing the attribution must
 * never silently delete the record of a credit that was actually given. It does mean the units stop
 * netting out of that invoice's hold, which is correct — the row they were netted against is gone.
 */
#[ORM\Entity]
#[ORM\Table(name: 'credit_memo_line')]
#[ORM\Index(name: 'idx_credit_memo_line_unit', fields: ['unitOfMeasure'])]
class CreditMemoLine implements DocumentLine, DenominatedLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CreditMemo::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'credit_memo_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CreditMemo $creditMemo;

    /** The billed row this one credits, when it credits one. See the class docblock. */
    #[ORM\ManyToOne(targetEntity: InvoiceLine::class, inversedBy: 'creditLines')]
    #[ORM\JoinColumn(name: 'invoice_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?InvoiceLine $invoiceLine = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true)]
    private ?ProductCore $product = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sku = null;

    /**
     * The region name this row's goods belong to, snapshotted as a label exactly as it is on an
     * order or invoice line.
     *
     * Load-bearing for a restock: it is what CreditMemoRestockSubscriber resolves a warehouse from,
     * through the same OrderInventoryBucketResolver::resolveLineWarehouse() the reconciler uses, so
     * returned stock lands in the building the credit note names and not in a guess.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $quantity = '1.00';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $taxCode = null;

    /** Named `rate` on the issue's sketch; `price` here, so a line reads the same on every document. */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 6)]
    private string $price = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $subtotal = '0.00';

    /** Where this row sits on the document — see EstimateLine::$sortOrder, same reason. */
    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }

    public function getCreditMemo(): CreditMemo { return $this->creditMemo; }

    /**
     * Set by CreditMemo::addLine(), which is the only thing that should call it.
     *
     * @internal
     */
    public function setCreditMemo(CreditMemo $creditMemo): self { $this->creditMemo = $creditMemo; return $this; }

    public function getInvoiceLine(): ?InvoiceLine { return $this->invoiceLine; }

    /**
     * Both sides are maintained, because the inverse side is READ: InvoiceLine::getCreditedUnits()
     * walks the collection to net credits out of the invoice's inventory hold, and a line attached
     * to only one side of the association would be invisible to it until the entity manager was
     * cleared and everything re-fetched from the database. That is precisely the "works in
     * production, fails in the test that never clears" split this codebase has no appetite for.
     */
    public function setInvoiceLine(?InvoiceLine $invoiceLine): self
    {
        $previous = $this->invoiceLine;
        if ($previous instanceof InvoiceLine && $previous !== $invoiceLine) {
            $previous->getCreditLines()->removeElement($this);
        }

        $this->invoiceLine = $invoiceLine;

        if ($invoiceLine instanceof InvoiceLine && !$invoiceLine->getCreditLines()->contains($this)) {
            $invoiceLine->getCreditLines()->add($this);
        }

        return $this;
    }

    public function getProduct(): ?ProductCore { return $this->product; }
    public function setProduct(?ProductCore $product): self { $this->product = $product; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self { $this->location = $location; return $this; }
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
    public function getUnit(): ?string { return $this->unit; }
    public function setUnit(?string $unit): self { $this->unit = $unit; return $this; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function setTaxCode(?string $taxCode): self { $this->taxCode = $taxCode; return $this; }
    public function getPrice(): ?string { return $this->price; }
    public function setPrice(?string $price): self { $this->price = (string) ($price ?? '0.00'); return $this; }
    public function getSubtotal(): ?string { return $this->subtotal; }
    public function setSubtotal(?string $subtotal): self { $this->subtotal = (string) ($subtotal ?? '0.00'); return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    /** The quantity, canonicalised and clamped at zero — never negative. */
    public function getUnits(): string
    {
        $quantity = QuantityScale::canonical($this->quantity);

        return QuantityScale::compare($quantity, 0) > 0 ? $quantity : QuantityScale::canonical(0);
    }

    /** The base figure #601 denominates everything in: `quantity`, unchanged by phase 3. */
    public function getQuantityBase(): string { return (string) $this->quantity; }

    protected function storeQuantityBase(string $base): void { $this->setQuantity($base); }
}
