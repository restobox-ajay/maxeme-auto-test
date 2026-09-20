<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Entity;

use App\Entity\DenominatedLine;
use App\Entity\DenominatedQuantity;
use App\Entity\ProductCore;
use App\Service\DisplayNumber;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Entity\InventoryLot;
use WarehouseOpsBundle\Repository\TransferOrderLineRepository;

/**
 * One product, one lot, on one transfer (#552).
 *
 * ## Three quantities, and the gap between two of them is the point
 *
 * `quantity_requested` is what the transfer asked for, `quantity_dispatched` what actually left, and
 * `quantity_received` what actually arrived. Dispatched and received are separate columns because
 * they genuinely differ: that gap is stock lost in transit, and it has to be visible rather than
 * reconciled away by a single "quantity" that quietly becomes whatever arrived.
 *
 * ## The lot is named, not chosen
 *
 * `lot_id` is nullable only because a product with no lot tracking has no lot to name. Where lots
 * exist the line names one, and the "fill this for me" helper prefers the LONGEST-dated batch —
 * the opposite of the earliest-expiry rule a customer shipment uses. See TransferOrder's docblock.
 *
 * ## Why product_id cascades here and not on a pick task
 *
 * A transfer line is not a copy of a sold line; it is an instruction about physical stock, and if
 * the product is deleted its `inventory_detail` rows and lots go with it (both cascade on
 * `product_id` from #550), so the instruction has nothing left to refer to. Cascading is therefore
 * consistent with the layer beneath it. The sku/name snapshot is kept anyway, because a line that
 * has already dispatched is read as history.
 */
#[ORM\Entity(repositoryClass: TransferOrderLineRepository::class)]
#[ORM\Table(name: 'transfer_order_line')]
#[ORM\Index(name: 'idx_transfer_order_line_unit', fields: ['unitOfMeasure'])]
#[ORM\Index(name: 'idx_transfer_line_product', fields: ['product'])]
class TransferOrderLine implements DenominatedLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TransferOrder::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'transfer_order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private TransferOrder $transferOrder;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: InventoryLot::class)]
    #[ORM\JoinColumn(name: 'lot_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?InventoryLot $lot = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $serial = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    /** Decimal STRINGS since `App\Doctrine\Type\QuantityType` became a decimal type — see `PickTask`. */
    #[ORM\Column(type: 'quantity', name: 'quantity_requested')]
    private string $quantityRequested = '0.0000';

    #[ORM\Column(type: 'quantity', name: 'quantity_dispatched', options: ['default' => 0])]
    private string $quantityDispatched = '0.0000';

    #[ORM\Column(type: 'quantity', name: 'quantity_received', options: ['default' => 0])]
    private string $quantityReceived = '0.0000';

    public function getId(): ?int { return $this->id; }
    public function getTransferOrder(): TransferOrder { return $this->transferOrder; }
    public function setTransferOrder(TransferOrder $transferOrder): self { $this->transferOrder = $transferOrder; return $this; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getLot(): ?InventoryLot { return $this->lot; }
    public function setLot(?InventoryLot $lot): self { $this->lot = $lot; return $this; }
    public function getSerial(): ?string { return $this->serial; }
    public function setSerial(?string $serial): self { $this->serial = ($serial === null || trim($serial) === '') ? null : trim($serial); return $this; }
    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getQuantityRequested(): string { return $this->quantityRequested; }
    /**
     * Restating the base figure forgets how it was entered (#601, #646).
     *
     * Writing this column directly says "the row is this many BASE units", and the only
     * truthful entered figure for that is the same number in base units — which is what
     * `quantity_entered` NULL and `unit_id` NULL mean. See DenominatedQuantity.
     */
    public function setQuantityRequested(string|int|float $quantity): self
    {
        $this->quantityRequested = QuantityScale::canonical(max(0, (float) $quantity));
        $this->forgetEnteredExpression();

        return $this;
    }
    public function getQuantityDispatched(): string { return $this->quantityDispatched; }
    public function setQuantityDispatched(string|int|float $quantity): self { $this->quantityDispatched = QuantityScale::canonical(max(0, (float) $quantity)); return $this; }
    public function getQuantityReceived(): string { return $this->quantityReceived; }
    public function setQuantityReceived(string|int|float $quantity): self { $this->quantityReceived = QuantityScale::canonical(max(0, (float) $quantity)); return $this; }

    /** Still on the truck: dispatched and not yet receipted. */
    public function outstandingInTransit(): string
    {
        return QuantityScale::canonical(max(0.0, (float) $this->quantityDispatched - (float) $this->quantityReceived));
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf('%s × %s', $this->sku ?: $this->name, (new DisplayNumber())->qty($this->quantityRequested));
    }

    /** The base figure #601 denominates everything in: `quantityRequested`, unchanged by phase 3. */
    public function getQuantityBase(): string { return $this->quantityRequested; }

    /** No rounding here any more — see `CartItem::storeQuantityBase()` for the same removal. */
    protected function storeQuantityBase(string $base): void { $this->setQuantityRequested($base); }
}
