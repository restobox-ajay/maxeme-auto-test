<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Entity;

use App\Entity\DenominatedLine;
use App\Entity\DenominatedQuantity;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DisplayNumber;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Entity\WarehouseLocation;
use WarehouseOpsBundle\Repository\PickTaskRepository;

/**
 * One line of one order, on one pick round (#552).
 *
 * ## Attribution is the whole point of the row
 *
 * Batch picking means several orders are walked together, so **every task carries the order line it
 * is picking for**. Getting that wrong shows up as stock leaving against the wrong order, which is
 * very hard to unpick afterwards — so the order line is on the task rather than being inferred from
 * the product at confirmation time.
 *
 * ## Why the order and the product are nullable, and why the snapshot columns exist
 *
 * `sales_order_line` deliberately carries no foreign key to `product_core`: a line snapshots what
 * was sold and must outlive the product's deletion. A pick task copies an order line, so it inherits
 * exactly that problem. It is resolved the same way it is on documents — the row keeps `sku`,
 * `name` and `order_number` as text, and both associations are `SET NULL` rather than `CASCADE`. A
 * product deleted mid-round leaves a task that still says what it was and refuses to confirm,
 * instead of vanishing out of a round somebody is holding a printout of.
 *
 * ## Three quantities, because a short pick is three facts
 *
 *  - `quantity_requested` — what the list asked for.
 *  - `quantity_picked` — what was physically found and moved. A real `pick` movement.
 *  - `quantity_missing` — what the system expected and the shelf did not have. A separate
 *    `adjustment` group with a count reason.
 *
 * `requested - picked - missing` is what the order still needs: top it up from another lot, or
 * backorder it (#548). Collapsing these into one number is how a discrepancy disappears and stock
 * stays overstated forever, so there are three columns and no derived "short" column that could
 * disagree with them.
 */
#[ORM\Entity(repositoryClass: PickTaskRepository::class)]
#[ORM\Table(name: 'pick_task')]
#[ORM\Index(name: 'idx_pick_task_unit', fields: ['unitOfMeasure'])]
#[ORM\Index(name: 'idx_pick_task_list_route', fields: ['pickList', 'sortKey'])]
#[ORM\Index(name: 'idx_pick_task_order', fields: ['order'])]
class PickTask implements DenominatedLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PickList::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(name: 'pick_list_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PickList $pickList;

    #[ORM\ManyToOne(targetEntity: SalesOrder::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SalesOrder $order = null;

    #[ORM\ManyToOne(targetEntity: SalesOrderLine::class)]
    #[ORM\JoinColumn(name: 'order_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SalesOrderLine $orderLine = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ProductCore $product = null;

    /** The order number as text, so the task still says who it was for when the order is gone. */
    #[ORM\Column(name: 'order_number', length: 64)]
    private string $orderNumber = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    /**
     * The bin the compiler expects the stock to be in — a hint for the printout, never a claim.
     * Nullable, because a product with an opening balance and no bin has nowhere to suggest.
     */
    #[ORM\ManyToOne(targetEntity: WarehouseLocation::class)]
    #[ORM\JoinColumn(name: 'suggested_location_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?WarehouseLocation $suggestedLocation = null;

    /**
     * Route position, copied from `warehouse_location.sort_key` at compile time.
     *
     * Copied rather than joined because the sort has to work for tasks whose bin is null (they go
     * last, at PHP_INT-ish distance) and because a bin renumbered halfway through a round must not
     * reorder a printout somebody is already walking.
     */
    #[ORM\Column(name: 'sort_key', options: ['default' => 0])]
    private int $sortKey = 0;

    /**
     * All three are decimal STRINGS since `App\Doctrine\Type\QuantityType` became a decimal type.
     * A pick is a quantity like any other — 0.6 kg off a lot is picked, not refused — and only a
     * SERIAL is legitimately whole, which is `InventoryDetail`'s rule and not this row's.
     */
    #[ORM\Column(type: 'quantity', name: 'quantity_requested')]
    private string $quantityRequested = '0.0000';

    #[ORM\Column(type: 'quantity', name: 'quantity_picked', options: ['default' => 0])]
    private string $quantityPicked = '0.0000';

    #[ORM\Column(type: 'quantity', name: 'quantity_missing', options: ['default' => 0])]
    private string $quantityMissing = '0.0000';

    public function getId(): ?int { return $this->id; }
    public function getPickList(): PickList { return $this->pickList; }
    public function setPickList(PickList $pickList): self { $this->pickList = $pickList; return $this; }
    public function getOrder(): ?SalesOrder { return $this->order; }
    public function setOrder(?SalesOrder $order): self { $this->order = $order; return $this; }
    public function getOrderLine(): ?SalesOrderLine { return $this->orderLine; }
    public function setOrderLine(?SalesOrderLine $orderLine): self { $this->orderLine = $orderLine; return $this; }
    public function getProduct(): ?ProductCore { return $this->product; }
    public function setProduct(?ProductCore $product): self { $this->product = $product; return $this; }
    public function getOrderNumber(): string { return $this->orderNumber; }
    public function setOrderNumber(string $orderNumber): self { $this->orderNumber = $orderNumber; return $this; }
    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getSuggestedLocation(): ?WarehouseLocation { return $this->suggestedLocation; }
    public function setSuggestedLocation(?WarehouseLocation $location): self { $this->suggestedLocation = $location; return $this; }
    public function getSortKey(): int { return $this->sortKey; }
    public function setSortKey(int $sortKey): self { $this->sortKey = $sortKey; return $this; }
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
        $this->quantityRequested = self::atLeastZero($quantity);
        $this->forgetEnteredExpression();

        return $this;
    }
    public function getQuantityPicked(): string { return $this->quantityPicked; }
    public function setQuantityPicked(string|int|float $quantity): self { $this->quantityPicked = self::atLeastZero($quantity); return $this; }
    public function getQuantityMissing(): string { return $this->quantityMissing; }
    public function setQuantityMissing(string|int|float $quantity): self { $this->quantityMissing = self::atLeastZero($quantity); return $this; }

    /** What the order still needs after this round: neither found nor accounted for as missing. */
    public function outstanding(): string
    {
        $remaining = QuantityScale::sub(QuantityScale::sub($this->quantityRequested, $this->quantityPicked), $this->quantityMissing);

        return self::atLeastZero($remaining);
    }

    public function isSettled(): bool
    {
        return QuantityScale::compare($this->outstanding(), 0) <= 0;
    }

    private static function atLeastZero(string|int|float $quantity): string
    {
        $quantity = QuantityScale::canonical($quantity);

        return QuantityScale::compare($quantity, 0) > 0 ? $quantity : QuantityScale::canonical(0);
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf('%s × %s for %s', $this->sku ?: $this->name, (new DisplayNumber())->qty($this->quantityRequested), $this->orderNumber);
    }

    /** The base figure #601 denominates everything in: `quantityRequested`, unchanged by phase 3. */
    public function getQuantityBase(): string { return $this->quantityRequested; }

    /**
     * No rounding here any more — see `CartItem::storeQuantityBase()`, which lost the same cast for
     * the same reason. A pick task for 0.4 of a drum used to be written as a pick task for nothing.
     */
    protected function storeQuantityBase(string $base): void { $this->setQuantityRequested($base); }
}
