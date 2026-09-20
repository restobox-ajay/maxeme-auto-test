<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Entity;

use App\Entity\Warehouse;
use App\Service\QuantityScale;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use WarehouseOpsBundle\Repository\TransferOrderRepository;

/**
 * Stock moving between warehouses, as a tracked document rather than two unrelated adjustments
 * (#552).
 *
 * ## Two movements, never one
 *
 * **Dispatch** moves stock to `in_transit` owned by the SOURCE warehouse. **Receipt** moves it from
 * there to `available` at the destination. Stock on a truck is therefore unsellable at both ends —
 * `in_transit` is not `available`, so it is out of `product_inventory.quantity` at the source — and
 * still locatable, because the row names the warehouse that shipped it. Nothing falls into a gap
 * between the two, which is exactly what a single "move A to B" movement would create if the truck
 * took three days.
 *
 * ## Transfers do not inherit FEFO
 *
 * #550's automatic picker takes earliest expiry first, which is right for a customer shipment and
 * wrong here: sending the earliest-expiring stock to a slower site is how it expires there. A
 * transfer line therefore names its lot explicitly, and the "fill this line for me" helper prefers
 * the LONGEST-dated batch. The allocation rule is a property of the operation, not a global
 * setting — the opposite choice from a shipment, deliberately.
 *
 * ## Data outlives the bundle
 *
 * Deleting this bundle takes away the screens that manage transfers. It does not take away the
 * transfers, and it cannot take away what they did to stock: every dispatch and every receipt is an
 * `inventory_movement_group` written by StockMovementService, and stays visible in #550's movement
 * history like any other change.
 *
 * ## Deliberately outside CommercialDocument (#636)
 *
 * #636 made every document in the application implement `App\Contract\Document\CommercialDocument`.
 * This one does not, and the reason is not that it was missed — it is the clearest non-commercial
 * document in the estate.
 *
 * A commercial document states money owed between two parties. A transfer has **no counterparty**:
 * both of its ends are warehouses this company owns, so there is nobody to owe anything to. It
 * follows that it has **no currency** and **no total**, and the columns above show it — quantities
 * and warehouses, not amounts. Implementing the contract would mean inventing a '0.00' total and a
 * currency purely to satisfy an interface, which asserts that a transfer settles at zero rather
 * than that money is not what a transfer is about.
 *
 * It is a document all the same — a number, rows, a status, a lifecycle — so
 * `App\Tests\Architecture\EveryDocumentDeclaresItsContractTest` discovers it and names it as a
 * deliberate exclusion rather than passing over it in silence. A goods/logistical contract to hold
 * documents like this one is parked, not declined; that test's exclusion list is where it attaches.
 */
#[ORM\Entity(repositoryClass: TransferOrderRepository::class)]
#[ORM\Table(name: 'transfer_order')]
#[ORM\UniqueConstraint(name: 'uniq_transfer_order_number', fields: ['number'])]
#[ORM\Index(name: 'idx_transfer_order_status', fields: ['status'])]
class TransferOrder
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_DISPATCHED, self::STATUS_RECEIVED, self::STATUS_CANCELLED];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $number = '';

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'from_warehouse_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Warehouse $fromWarehouse;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'to_warehouse_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Warehouse $toWarehouse;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'dispatched_at', nullable: true)]
    private ?\DateTimeImmutable $dispatchedAt = null;

    #[ORM\Column(name: 'received_at', nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, TransferOrderLine> */
    #[ORM\OneToMany(mappedBy: 'transferOrder', targetEntity: TransferOrderLine::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): string { return $this->number; }
    public function setNumber(string $number): self { $this->number = $number; return $this; }
    public function getFromWarehouse(): Warehouse { return $this->fromWarehouse; }
    public function setFromWarehouse(Warehouse $warehouse): self { $this->fromWarehouse = $warehouse; return $this; }
    public function getToWarehouse(): Warehouse { return $this->toWarehouse; }
    public function setToWarehouse(Warehouse $warehouse): self { $this->toWarehouse = $warehouse; return $this; }
    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): self
    {
        $this->status = \in_array($status, self::statuses(), true) ? $status : self::STATUS_DRAFT;

        return $this;
    }

    public function getDispatchedAt(): ?\DateTimeImmutable { return $this->dispatchedAt; }
    public function setDispatchedAt(?\DateTimeImmutable $at): self { $this->dispatchedAt = $at; return $this; }
    public function getReceivedAt(): ?\DateTimeImmutable { return $this->receivedAt; }
    public function setReceivedAt(?\DateTimeImmutable $at): self { $this->receivedAt = $at; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, TransferOrderLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(TransferOrderLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setTransferOrder($this);
        }

        return $this;
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Units dispatched but not yet received — the stock sitting on the truck, unsellable at both
     * ends. A non-zero value on a `received` transfer is stock lost in transit, which is exactly
     * why dispatched and received are separate columns rather than one reconciled number.
     */
    public function inTransitUnits(): string
    {
        $total = QuantityScale::canonical(0);
        foreach ($this->lines as $line) {
            $gap = QuantityScale::sub($line->getQuantityDispatched(), $line->getQuantityReceived());
            if (QuantityScale::compare($gap, 0) > 0) {
                $total = QuantityScale::add($total, $gap);
            }
        }

        return $total;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return $this->number;
    }
}
