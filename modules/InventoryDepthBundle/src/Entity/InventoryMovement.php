<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Entity;

use App\Entity\ProductCore;
use App\Service\DisplayNumber;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Repository\InventoryMovementRepository;

/**
 * One quantity moving from one detail row to another (#550).
 *
 * `from = NULL` means entering the system; `to = NULL` means leaving it. Everything else — a bin
 * move, a status change, a pick, a write-off — is the same two-sided shape, which is what lets one
 * code path serve all of them. A bin move has `available → available` on both sides and therefore
 * does NOT change `product_inventory.quantity`; that is the cheapest regression test there is for
 * someone recomputing the total from the wrong status set.
 *
 * `product_id` is denormalised off the detail rows so the ledger can be filtered by product without
 * joining two nullable sides. It carries no ON DELETE CASCADE of its own beyond the FK, because a
 * movement is only ever reachable through its group.
 */
#[ORM\Entity(repositoryClass: InventoryMovementRepository::class)]
#[ORM\Table(name: 'inventory_movement')]
#[ORM\Index(name: 'idx_movement_from', fields: ['fromDetail'])]
#[ORM\Index(name: 'idx_movement_to', fields: ['toDetail'])]
#[ORM\Index(name: 'idx_movement_product', fields: ['product'])]
#[ORM\Index(name: 'idx_movement_reverses', fields: ['reversesMovement'])]
class InventoryMovement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: InventoryMovementGroup::class, inversedBy: 'movements')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InventoryMovementGroup $group;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: InventoryDetail::class)]
    #[ORM\JoinColumn(name: 'from_detail_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?InventoryDetail $fromDetail = null;

    #[ORM\ManyToOne(targetEntity: InventoryDetail::class)]
    #[ORM\JoinColumn(name: 'to_detail_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?InventoryDetail $toDetail = null;

    /** A decimal string since `QuantityType` became a decimal type — see `InventoryDetail`. */
    #[ORM\Column(type: 'quantity')]
    private string $quantity = '0.0000';

    /**
     * The movement this one undoes, when it is a reversal (#585).
     *
     * ## Why per movement and not per group
     *
     * A group carries many lines. A write-off of 300 units spanning four bins is one group and four
     * movements, and somebody finding one of those pallets intact reverses ONE of them. Dynamics
     * ties a reversal to the original Item Ledger Entry for the same reason: the cost has to reverse
     * to the account the original entry posted to, and the group is not what posted.
     *
     * ## What it buys, which is more than provenance
     *
     * A reversal is defined as **the original movement with its sides swapped**, bounded by
     *
     *     remaining = original.quantity − SUM(quantity WHERE reverses_movement_id = original.id)
     *
     * and that one expression is the entire guard. It is why a reversal cannot claim 500 units when
     * 12 were written off, and why the same 12 cannot be reversed twice — neither of which any
     * amount of validation on a free-typed quantity box would have caught, because nothing else in
     * the schema knows the two entries are about the same goods.
     *
     * It also settles serials for this case without asking anybody to retype one. Bin, lot and
     * serial all come off the original's `from_detail`, so a write-off of serial ABC-0042 reverses
     * ABC-0042 and cannot reverse anything else. The old screen's single serial text box would have
     * needed 300 submissions to put 300 found units back; this needs none.
     *
     * ## Why nullable and unindexed-by-default
     *
     * Every movement ever written is NULL here and stays NULL — the migration adds the column and
     * touches no row. The index below exists because `remaining` is a SUM over this column and is
     * read once per candidate row on the reversal picker, which is the only query that filters on
     * it.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'reverses_movement_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $reversesMovement = null;

    public function getId(): ?int { return $this->id; }
    public function getGroup(): InventoryMovementGroup { return $this->group; }
    public function setGroup(InventoryMovementGroup $group): self { $this->group = $group; return $this; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getFromDetail(): ?InventoryDetail { return $this->fromDetail; }
    public function setFromDetail(?InventoryDetail $fromDetail): self { $this->fromDetail = $fromDetail; return $this; }
    public function getToDetail(): ?InventoryDetail { return $this->toDetail; }
    public function setToDetail(?InventoryDetail $toDetail): self { $this->toDetail = $toDetail; return $this; }
    public function getQuantity(): string { return $this->quantity; }
    public function setQuantity(string|int|float $quantity): self { $this->quantity = QuantityScale::canonical($quantity); return $this; }
    public function getReversesMovement(): ?self { return $this->reversesMovement; }
    public function setReversesMovement(?self $reversesMovement): self { $this->reversesMovement = $reversesMovement; return $this; }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf(
            // `%s` and not `%d` since the quantity became a decimal string: `%d` would print 0.4 as 0.
            '%s × %s: %s → %s',
            (new DisplayNumber())->qty($this->quantity),
            $this->product->getSku() ?: ('#' . ($this->product->getId() ?? '?')),
            $this->fromDetail?->getLabel() ?? '(outside)',
            $this->toDetail?->getLabel() ?? '(outside)',
        );
    }
}
