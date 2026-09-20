<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\DenominatedLine;
use App\Entity\DenominatedQuantity;
use App\Entity\ProductCore;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing the requirement asks for (#637) — vendor-agnostic, the way `EstimateLine` is
 * customer-agnostic: what is needed and how much, before anybody has said a price.
 *
 * No unit cost here. That is exactly what an `RfqVendorReplyLine` supplies, one per invited vendor,
 * which is the entire mechanism that makes N competing quotes against one requirement possible: the
 * requirement is asked once, and priced as many times as there are vendors.
 *
 * No FK to product_core — same rule as `PurchaseOrderLine`, for the same reason: a requirement
 * raised today has to keep meaning what it meant if the product is deleted before anyone quotes it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'rfq_line')]
#[ORM\Index(name: 'idx_rfq_line_unit', fields: ['unitOfMeasure'])]
#[ORM\Index(name: 'idx_rfq_line_rfq', fields: ['rfq'])]
class RfqLine implements DenominatedLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Rfq::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'rfq_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Rfq $rfq;

    /** Mapped, with no FK behind it — see the class docblock. */
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true)]
    private ?ProductCore $product = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $quantity = '1.00';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }
    public function getRfq(): Rfq { return $this->rfq; }
    public function setRfq(Rfq $rfq): self { $this->rfq = $rfq; return $this; }
    public function getProduct(): ?ProductCore { return $this->product; }
    public function setProduct(?ProductCore $product): self { $this->product = $product; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
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
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    /** The base figure #601 denominates everything in: `quantity`, unchanged by phase 3. */
    public function getQuantityBase(): string { return (string) $this->quantity; }

    protected function storeQuantityBase(string $base): void { $this->setQuantity($base); }
}
