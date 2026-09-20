<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\DenominatedLine;
use App\Entity\DenominatedQuantity;
use App\Entity\ProductCore;
use Doctrine\ORM\Mapping as ORM;

/**
 * One product row on a debit memo (#638) — the mirror of `App\Entity\CreditMemoLine`.
 *
 * `$product` is nullable, matching `CreditMemoLine::$product` and not `VendorReturnLine::$product`:
 * a debit memo line may be a pricing correction or a restocking-fee deduction with no SKU behind
 * it, exactly as a credit note line may be freight or a fee.
 */
#[ORM\Entity]
#[ORM\Table(name: 'debit_memo_line')]
#[ORM\Index(name: 'idx_debit_memo_line_unit', fields: ['unitOfMeasure'])]
class DebitMemoLine implements DenominatedLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DebitMemo::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'debit_memo_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private DebitMemo $debitMemo;

    /** The billed row this one debits, when it debits one. SET NULL, matching CreditMemoLine::$invoiceLine. */
    #[ORM\ManyToOne(targetEntity: VendorBillLine::class)]
    #[ORM\JoinColumn(name: 'vendor_bill_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?VendorBillLine $vendorBillLine = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true)]
    private ?ProductCore $product = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $quantity = '1.00';

    #[ORM\Column(name: 'unit_cost', type: 'decimal', precision: 18, scale: 6)]
    private string $unitCost = '0.0000';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $subtotal = '0.00';

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }
    public function getDebitMemo(): DebitMemo { return $this->debitMemo; }

    /** @internal set by DebitMemo::addLine() */
    public function setDebitMemo(DebitMemo $debitMemo): self { $this->debitMemo = $debitMemo; return $this; }

    public function getVendorBillLine(): ?VendorBillLine { return $this->vendorBillLine; }
    public function setVendorBillLine(?VendorBillLine $vendorBillLine): self { $this->vendorBillLine = $vendorBillLine; return $this; }
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
    public function getUnitCost(): string { return $this->unitCost; }
    public function setUnitCost(string $unitCost): self { $this->unitCost = $unitCost; return $this; }
    public function getSubtotal(): string { return $this->subtotal; }
    public function setSubtotal(string $subtotal): self { $this->subtotal = $subtotal; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    /** The base figure #601 denominates everything in: `quantity`, unchanged by phase 3. */
    public function getQuantityBase(): string { return (string) $this->quantity; }

    protected function storeQuantityBase(string $base): void { $this->setQuantity($base); }
}
