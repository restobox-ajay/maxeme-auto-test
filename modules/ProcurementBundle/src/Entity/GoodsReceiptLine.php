<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\DenominatedLine;
use App\Entity\DenominatedQuantity;
use App\Entity\ProductCore;
use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;

/**
 * One thing that came off the truck (#555): what it was, how many, which batch, which serial, and
 * which bin it went into.
 *
 * This is the first and only moment lot, expiry and serial are captured. Everything downstream —
 * a recall, an expiry sweep, a serial lookup — is answerable exactly to the extent this row is
 * right, which is why the capture rules are enforced by ReceivingService rather than left to a
 * form's `required` attribute.
 *
 * ## No foreign key to product_core
 *
 * Same rule as PurchaseOrderLine and `sales_order_line`: a document line snapshots what arrived and
 * has to outlive the product's deletion. The join column is mapped; the migration emits no
 * `FOREIGN KEY` for it. The plan's draft schema had this column `NOT NULL REFERENCES
 * product_core(id)` and it is neither here — non-null is enforced by ReceivingService, where the
 * error can say something useful, and the reference is left off for the reason the hard-won note on
 * Version20260823102000 gives.
 *
 * `lot` and `location` DO carry foreign keys, because they are live rows this app owns rather than
 * snapshots of someone else's paperwork, and #550 already cascades them the same way `inventory_
 * detail` does. `SET NULL` on both: losing a bin must not delete the record that goods arrived.
 *
 * ## movementApplied
 *
 * True when this line resolved to a NAMED bin/lot/serial. False when the product is on `simple`
 * inventory, whose identity carries none of that, so the line lands in the warehouse's unspecified
 * row instead — `received` is still credited and a detail row still written either way. It is a
 * column rather than a derivation from `$receipt->getMovementGroup()` because it is per line: one
 * receipt can carry both kinds.
 */
#[ORM\Entity]
#[ORM\Table(name: 'goods_receipt_line')]
#[ORM\Index(name: 'idx_receipt_line_unit', fields: ['unitOfMeasure'])]
#[ORM\Index(name: 'idx_receipt_line_receipt', fields: ['receipt'])]
#[ORM\Index(name: 'idx_receipt_line_product', fields: ['product'])]
#[ORM\Index(name: 'idx_receipt_line_po_line', fields: ['purchaseOrderLine'])]
class GoodsReceiptLine implements DenominatedLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GoodsReceipt::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'goods_receipt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private GoodsReceipt $receipt;

    /**
     * The PO row this delivery is against, when there is one.
     *
     * `SET NULL` rather than `CASCADE`, for the reason `invoice_line.sales_order_line_id` is:
     * losing the attribution must never silently delete the record of goods that actually arrived.
     */
    #[ORM\ManyToOne(targetEntity: PurchaseOrderLine::class)]
    #[ORM\JoinColumn(name: 'purchase_order_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PurchaseOrderLine $purchaseOrderLine = null;

    /** Mapped, with no FK behind it — see the class docblock. */
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true)]
    private ?ProductCore $product = null;

    /** Snapshots, so the line still reads correctly after the product is gone. */
    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sku = null;

    /** The batch these units belong to. Created or reused by ReceivingService, never by a form. */
    #[ORM\ManyToOne(targetEntity: InventoryLot::class)]
    #[ORM\JoinColumn(name: 'lot_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?InventoryLot $lot = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $serial = null;

    #[ORM\ManyToOne(targetEntity: WarehouseLocation::class)]
    #[ORM\JoinColumn(name: 'location_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?WarehouseLocation $location = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $quantity = '0.00';

    /**
     * What we were charged per unit on this delivery, when the paperwork said.
     *
     * **Recorded, never applied.** Nothing here updates a product's cost — inventory valuation and
     * costing (moving average, FIFO, standard) is a separate piece of work with accounting
     * consequences, and it is the one thing that would make core read something this bundle writes
     * and so break the removability rule this bundle is built on. See the plan's "Not in this job".
     */
    #[ORM\Column(name: 'unit_cost', type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $unitCost = null;

    /** Did this line reach stock — see the class docblock. */
    #[ORM\Column(name: 'movement_applied', options: ['default' => false])]
    private bool $movementApplied = false;

    /**
     * The exception row written when this line's expiry was inside the minimum shelf life and
     * somebody accepted it anyway (item 68).
     *
     * At most one: a line carries at most one expiry date, so it can be short by at most one
     * amount. Null for every ordinary line, which is almost all of them — the presence of this row
     * IS the fact that a decision was made here.
     *
     * The inverse side. {@see ShortDatedReceipt} owns the foreign key, holds the reason, the person
     * and the figures the decision was made against, and cascades from this row.
     */
    #[ORM\OneToOne(mappedBy: 'receiptLine', targetEntity: ShortDatedReceipt::class)]
    private ?ShortDatedReceipt $shortDated = null;

    public function getId(): ?int { return $this->id; }
    public function getReceipt(): GoodsReceipt { return $this->receipt; }
    public function setReceipt(GoodsReceipt $receipt): self { $this->receipt = $receipt; return $this; }
    public function getPurchaseOrderLine(): ?PurchaseOrderLine { return $this->purchaseOrderLine; }
    public function setPurchaseOrderLine(?PurchaseOrderLine $purchaseOrderLine): self { $this->purchaseOrderLine = $purchaseOrderLine; return $this; }
    public function getProduct(): ?ProductCore { return $this->product; }
    public function setProduct(?ProductCore $product): self { $this->product = $product; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }
    public function getLot(): ?InventoryLot { return $this->lot; }
    public function setLot(?InventoryLot $lot): self { $this->lot = $lot; return $this; }
    public function getSerial(): ?string { return $this->serial; }
    public function setSerial(?string $serial): self { $this->serial = $serial; return $this; }
    public function getLocation(): ?WarehouseLocation { return $this->location; }
    public function setLocation(?WarehouseLocation $location): self { $this->location = $location; return $this; }
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
    public function getUnitCost(): ?string { return $this->unitCost; }
    public function setUnitCost(?string $unitCost): self { $this->unitCost = $unitCost; return $this; }
    public function isMovementApplied(): bool { return $this->movementApplied; }
    public function setMovementApplied(bool $movementApplied): self { $this->movementApplied = $movementApplied; return $this; }
    public function getShortDated(): ?ShortDatedReceipt { return $this->shortDated; }
    public function setShortDated(?ShortDatedReceipt $shortDated): self { $this->shortDated = $shortDated; return $this; }

    /** The base figure #601 denominates everything in: `quantity`, unchanged by phase 3. */
    public function getQuantityBase(): string { return (string) $this->quantity; }

    protected function storeQuantityBase(string $base): void { $this->setQuantity($base); }
}
