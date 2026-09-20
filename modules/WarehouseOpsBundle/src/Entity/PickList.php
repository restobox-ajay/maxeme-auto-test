<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Entity;

use App\Entity\Warehouse;
use App\Service\QuantityScale;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Entity\WarehouseLocation;
use WarehouseOpsBundle\Repository\PickListRepository;

/**
 * A walking route: the lines of one or more orders, compiled into the order a picker walks them
 * (#552).
 *
 * ## It is a plan, not a claim on stock
 *
 * Compiling a pick list writes nothing to `inventory_detail` and reserves nothing. Allocation
 * happens at confirmation, not at compile — #550's picker chooses earliest-expiry-then-lowest-bin
 * at the moment the pick is confirmed, so a list compiled at 08:00 and walked at 11:00 draws on the
 * stock that is actually there at 11:00. The suggested bin on each task is a hint printed for the
 * picker; it is deliberately not frozen, because stock moves.
 *
 * That is also why deleting this whole table would lose no stock information: everything a pick
 * ever did is in `inventory_movement_group`, written by StockMovementService like every other
 * change.
 *
 * ## Statuses
 *
 * `draft` → `released` → `closed`, or `cancelled` from either of the first two. There is no
 * `picking` state: a partly-confirmed list is a released list whose tasks have different amounts
 * confirmed against them, and a second status saying the same thing is a second thing to keep true.
 */
#[ORM\Entity(repositoryClass: PickListRepository::class)]
#[ORM\Table(name: 'pick_list')]
#[ORM\UniqueConstraint(name: 'uniq_pick_list_number', fields: ['number'])]
#[ORM\Index(name: 'idx_pick_list_status', fields: ['status'])]
class PickList
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_RELEASED, self::STATUS_CLOSED, self::STATUS_CANCELLED];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $number = '';

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Warehouse $warehouse;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_DRAFT;

    /**
     * Where picked stock is put down, and the reason a confirmed pick does not change the product's
     * total (see PickConfirmationService).
     *
     * Picked stock has left the shelf and not the building, so it moves from its pick face to this
     * bin with its status still `available` — a bin-to-bin move, which recomputes to the identical
     * number. It is a real bin rather than "no bin" because "no bin" already means something else in
     * #550: an opening balance whose location was never recorded. Mixing the two in one detail row
     * is the ambiguity that makes a cycle count unanswerable.
     *
     * Chosen when the list is released, defaulting to the warehouse's first `staging` bin.
     */
    #[ORM\ManyToOne(targetEntity: WarehouseLocation::class)]
    #[ORM\JoinColumn(name: 'staging_location_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?WarehouseLocation $stagingLocation = null;

    /** Who is walking it. Free text — pickers are not necessarily admin users. */
    #[ORM\Column(name: 'assigned_to', length: 160, nullable: true)]
    private ?string $assignedTo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'released_at', nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\Column(name: 'closed_at', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, PickTask> */
    #[ORM\OneToMany(mappedBy: 'pickList', targetEntity: PickTask::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortKey' => 'ASC', 'id' => 'ASC'])]
    private Collection $tasks;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->tasks = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): string { return $this->number; }
    public function setNumber(string $number): self { $this->number = $number; return $this; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function setWarehouse(Warehouse $warehouse): self { $this->warehouse = $warehouse; return $this; }
    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): self
    {
        $this->status = \in_array($status, self::statuses(), true) ? $status : self::STATUS_DRAFT;

        return $this;
    }

    public function getStagingLocation(): ?WarehouseLocation { return $this->stagingLocation; }
    public function setStagingLocation(?WarehouseLocation $location): self { $this->stagingLocation = $location; return $this; }
    public function getAssignedTo(): ?string { return $this->assignedTo; }
    public function setAssignedTo(?string $assignedTo): self { $this->assignedTo = $assignedTo; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReleasedAt(): ?\DateTimeImmutable { return $this->releasedAt; }
    public function setReleasedAt(?\DateTimeImmutable $releasedAt): self { $this->releasedAt = $releasedAt; return $this; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function setClosedAt(?\DateTimeImmutable $closedAt): self { $this->closedAt = $closedAt; return $this; }

    /** @return Collection<int, PickTask> */
    public function getTasks(): Collection { return $this->tasks; }

    public function addTask(PickTask $task): self
    {
        if (!$this->tasks->contains($task)) {
            $this->tasks->add($task);
            $task->setPickList($this);
        }

        return $this;
    }

    public function isOpen(): bool
    {
        return \in_array($this->status, [self::STATUS_DRAFT, self::STATUS_RELEASED], true);
    }

    /** How many distinct orders this round is picking for — the batch-picking headline. */
    public function orderCount(): int
    {
        $numbers = [];
        foreach ($this->tasks as $task) {
            $numbers[$task->getOrderNumber()] = true;
        }

        return \count($numbers);
    }

    public function requestedUnits(): string
    {
        $total = QuantityScale::canonical(0);
        foreach ($this->tasks as $task) {
            $total = QuantityScale::add($total, $task->getQuantityRequested());
        }

        return $total;
    }

    public function pickedUnits(): string
    {
        $total = QuantityScale::canonical(0);
        foreach ($this->tasks as $task) {
            $total = QuantityScale::add($total, $task->getQuantityPicked());
        }

        return $total;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return $this->number;
    }
}
