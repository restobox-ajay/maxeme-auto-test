<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Entity;

use App\Contract\Inventory\InventoryOperationGroupInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Repository\InventoryMovementGroupRepository;

/**
 * One operation: the reason, the actor, the reference, and the movements it caused (#550).
 *
 * The group is the audit unit, not the movement. "Who moved this and why" has to be answerable
 * without a database client, and `reference` is what makes a recall a ledger query — a terminal
 * `sold` detail row answers "how much left" but never "to whom", by design, because it is one
 * shared row per (product, warehouse, lot). So `reference` is REQUIRED on ship groups; see
 * StockMovementService::apply().
 *
 * **A short pick is more than one fact.** What was found, what is missing, and how the order was
 * completed are separate groups with separate reasons. Writing one group loses the discrepancy
 * entirely, so nothing here encourages folding them together.
 *
 * `client_operation_id` is a caller-supplied idempotency key with a UNIQUE index behind it: a
 * resubmitted adjustment form or a retried command re-applies nothing.
 *
 * ## A group CAN carry a movement of quantity zero, and you will meet one
 *
 * Written down here because it is invisible until it bites. `StockMovementService::apply()` never
 * produces one — `MovementRequest::move()` refuses a zero, correctly, since for every caller that
 * moves stock a zero is a bug. But a deliberate decision NOT to move stock is still an event in the
 * chronological record of what happened to that stock, and the ledger is where that record lives.
 * `ProcurementBundle\DebitMemo\DebitMemoStockService::void()` writes such a group: a debit memo
 * that took units out to a vendor, voided by somebody confirming the goods are never coming back.
 * One movement per product, quantity 0, both sides null, and the group's `reason` carrying why.
 *
 * What this does and does not affect:
 *
 *  - `SUM(quantity)` is unchanged by a zero, so every balance, bucket and total stays correct with
 *    no filtering whatsoever. This is the reason a zero row is safe at all.
 *  - A `COUNT(*)` of movements includes it, so "how many times did this product move" is one too
 *    many unless you exclude `quantity = 0`.
 *  - "Has movements" stops meaning "stock moved". Test the quantity, not the existence.
 *
 * Nothing in the codebase depends on the first two today; this note exists so that whoever writes
 * the query that would has the fact in front of them.
 *
 * ## It is also what core's bucket change log points at (#582)
 *
 * `inventory_bucket_change_log.group_id` names this row when a bucket moved because stock did, and
 * is NULL when it moved for any other reason — a hold, a reconcile, an import rebaseline. Core
 * cannot name this class to say so, because modules/InventoryDepthBundle is deletable and a core
 * entity mapping an association to a missing class does not fail gracefully. So core asks for the
 * id through App\Contract\Inventory\InventoryOperationGroupInterface, which is one method wide and
 * is implemented here for free: the group already has exactly that identifier.
 */
#[ORM\Entity(repositoryClass: InventoryMovementGroupRepository::class)]
#[ORM\Table(name: 'inventory_movement_group')]
#[ORM\UniqueConstraint(name: 'uniq_movement_group_op', fields: ['clientOperationId'])]
#[ORM\Index(name: 'idx_movement_group_occurred', fields: ['occurredAt'])]
#[ORM\Index(name: 'idx_movement_group_type', fields: ['type'])]
class InventoryMovementGroup implements InventoryOperationGroupInterface
{
    public const TYPE_RECEIPT = 'receipt';
    public const TYPE_PUTAWAY = 'putaway';
    public const TYPE_PICK = 'pick';
    public const TYPE_SHIP = 'ship';
    public const TYPE_TRANSFER = 'transfer';
    public const TYPE_MOVE = 'move';
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * Goods leaving this building back to a supplier (#638) — directionally the mirror of
     * TYPE_RECEIPT, and its own code rather than a reuse of TYPE_SHIP or TYPE_ADJUSTMENT: a report
     * grouping by type must be able to tell "sold and shipped to a customer" apart from "sent back
     * to a vendor", and neither existing code means that today.
     */
    public const TYPE_VENDOR_RETURN = 'vendor_return';

    /**
     * A case SKU broken open into a unit SKU, or units re-formed into a case (#22).
     *
     * Two movements under one group — out of one product, into another — which is why it needed no
     * new ledger and no new movement table: it is the shape this class already describes. It is NOT
     * a unit of measure and must never become one; {@see ProductPackRule} holds the whole of that
     * argument, and the short form is that a unit converts a quantity WITHIN one SKU and moves
     * nothing, while this moves stock between two SKUs and is therefore an event with a date and an
     * actor, exactly like a transfer.
     *
     * ## One code for both directions
     *
     * Not `case_break` and `case_rebuild`. Unlike {@see TYPE_VENDOR_RETURN}, which exists because
     * "sold to a customer" and "sent back to a vendor" are different commercial events that a report
     * must not add together, breaking and rebuilding are one operation applied in two directions —
     * the same declaration, the same two SKUs, the same arithmetic. Splitting them would make "how
     * much repacking happens here" a two-code query, and the direction is already recorded
     * unambiguously and in two places: `inventory_pack_conversion.direction`, and structurally by
     * which side of which movement the case SKU sits on.
     */
    public const TYPE_PACK_CONVERT = 'pack_convert';

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::TYPE_RECEIPT,
            self::TYPE_PUTAWAY,
            self::TYPE_PICK,
            self::TYPE_SHIP,
            self::TYPE_TRANSFER,
            self::TYPE_MOVE,
            self::TYPE_STATUS_CHANGE,
            self::TYPE_ADJUSTMENT,
            self::TYPE_VENDOR_RETURN,
            self::TYPE_PACK_CONVERT,
        ];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'client_operation_id', length: 64)]
    private string $clientOperationId = '';

    #[ORM\Column(length: 16)]
    private string $type = self::TYPE_ADJUSTMENT;

    /**
     * The free-text note, unchanged and untouched by #585.
     *
     * It keeps every value it has ever held — including the 84 opening balances and the 45 "Received
     * without a purchase order" rows on dev — because classifying them into the new codes would be
     * inventing a fact about somebody else's warehouse. What changes is that it is no longer the
     * ONLY answer to "why": $adjustmentReason sits beside it and carries the part that reports.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    /**
     * WHAT HAPPENED, as a row rather than a sentence (#585).
     *
     * Beside `reason`, not instead of it. The code says which class of event this was and is what a
     * report groups by and what a G/L account will eventually hang off; the free text says what the
     * operator wanted the next reader to know about this particular one. Neither substitutes for the
     * other, and merging them would lose whichever half was picked.
     *
     * **Nullable, and NULL is the ordinary answer for most groups.** Every group written before this
     * column existed carries NULL and keeps it — no backfill, in any environment. So does every
     * group written by something that is not the adjustment screen: an opening balance, a transfer,
     * a cycle count, a receipt. Those have a document or a command explaining them, which is exactly
     * the argument for not inventing a reason code to describe them.
     *
     * `ON DELETE SET NULL` rather than a cascade, because deleting a reason must never delete
     * history. The supported way to retire one is InventoryAdjustmentReason::$active, and the
     * repository's lookup refuses an inactive code on the way in.
     */
    #[ORM\ManyToOne(targetEntity: InventoryAdjustmentReason::class)]
    #[ORM\JoinColumn(name: 'adjustment_reason_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?InventoryAdjustmentReason $adjustmentReason = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $actor = null;

    /** Order number, RMA, count sheet. Required on ship groups — see the class docblock. */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    /** @var Collection<int, InventoryMovement> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: InventoryMovement::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $movements;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
        $this->movements = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getClientOperationId(): string { return $this->clientOperationId; }
    public function setClientOperationId(string $clientOperationId): self { $this->clientOperationId = $clientOperationId; return $this; }
    public function getType(): string { return $this->type; }

    public function setType(string $type): self
    {
        $this->type = \in_array($type, self::types(), true) ? $type : self::TYPE_ADJUSTMENT;

        return $this;
    }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): self { $this->reason = $reason; return $this; }
    public function getAdjustmentReason(): ?InventoryAdjustmentReason { return $this->adjustmentReason; }
    public function setAdjustmentReason(?InventoryAdjustmentReason $adjustmentReason): self { $this->adjustmentReason = $adjustmentReason; return $this; }
    public function getActor(): ?string { return $this->actor; }
    public function setActor(?string $actor): self { $this->actor = $actor; return $this; }
    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): self { $this->reference = $reference; return $this; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(\DateTimeImmutable $occurredAt): self { $this->occurredAt = $occurredAt; return $this; }

    /** @return Collection<int, InventoryMovement> */
    public function getMovements(): Collection { return $this->movements; }

    public function addMovement(InventoryMovement $movement): self
    {
        if (!$this->movements->contains($movement)) {
            $this->movements->add($movement);
            $movement->setGroup($this);
        }

        return $this;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf('%s %s', $this->type, $this->reference ?? $this->clientOperationId);
    }
}
