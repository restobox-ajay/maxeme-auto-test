<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\DenominatedLine;
use App\Entity\DenominatedQuantity;
use App\Entity\ProductCore;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;

/**
 * One kind of goods going back on one vendor return (#638) — the mirror of `App\Entity\SalesReturnLine`.
 *
 * No bin, lot or serial here, no disposition either — see `VendorReturn`'s class docblock. A
 * disposition on `SalesReturnLine` records what an inspector SAW on arrival; there is no symmetrical
 * fact here, because these goods are not being inspected on the way out, they are being sent back
 * for a reason already decided at authorisation.
 *
 * `$product` is required, matching `SalesReturnLine::$product` and for the identical reason: this
 * line is goods travelling in a box, not money with no SKU behind it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vendor_return_line')]
#[ORM\Index(name: 'idx_vendor_return_line_unit', fields: ['unitOfMeasure'])]
#[ORM\Index(name: 'idx_vendor_return_line_return', fields: ['vendorReturn'])]
class VendorReturnLine implements DenominatedLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VendorReturn::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'vendor_return_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private VendorReturn $vendorReturn;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    /** The receipt row these units came off, when the return was raised from one. SET NULL, matching SalesReturnLine::$invoiceLine. */
    #[ORM\ManyToOne(targetEntity: GoodsReceiptLine::class)]
    #[ORM\JoinColumn(name: 'goods_receipt_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?GoodsReceiptLine $goodsReceiptLine = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $quantity = '1.00';

    /** Snapshot, so a renamed or deleted product does not erase what went back. */
    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sku = null;

    /** Why THIS row is going back. See SalesReturnLine's docblock for why it is not on the header alone. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }
    public function getVendorReturn(): VendorReturn { return $this->vendorReturn; }
    public function setVendorReturn(VendorReturn $vendorReturn): self { $this->vendorReturn = $vendorReturn; return $this; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getGoodsReceiptLine(): ?GoodsReceiptLine { return $this->goodsReceiptLine; }
    public function setGoodsReceiptLine(?GoodsReceiptLine $goodsReceiptLine): self { $this->goodsReceiptLine = $goodsReceiptLine; return $this; }
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
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): self { $this->reason = $reason; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    /**
     * The quantity, canonicalised and clamped at zero — same shape SalesReturnLine::getUnits()
     * returns. It used to be `(int) round((float) $this->quantity)`, which is a different rounding
     * rule than SalesReturnLine's despite the docblock's claim: 0.4 read as 0 here instead of
     * carrying through, and 1.6 rounded up to 2 instead of staying 1.6.
     */
    public function getUnits(): string
    {
        $quantity = QuantityScale::canonical($this->quantity);

        return QuantityScale::compare($quantity, 0) > 0 ? $quantity : QuantityScale::canonical(0);
    }

    /** The base figure #601 denominates everything in: `quantity`, unchanged by phase 3. */
    public function getQuantityBase(): string { return (string) $this->quantity; }

    protected function storeQuantityBase(string $base): void { $this->setQuantity($base); }
}
