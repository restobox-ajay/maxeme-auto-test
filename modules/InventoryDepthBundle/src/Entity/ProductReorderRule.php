<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Entity;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\DisplayNumber;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Repository\ProductReorderRuleRepository;

/**
 * The level one product should not drop below at one warehouse, and what to order when it does (#597).
 *
 * ## One row per (product, warehouse), and the row IS the setting
 *
 * `product_inventory` is already one row per `(product_id, warehouse_id)` — enforced by
 * `uniq_product_inventory_product_location` — so that is the grain a reorder level belongs at, and
 * this table copies it exactly. What it does NOT do is add columns to `product_inventory` itself.
 *
 * That is the same call `ProcurementBundle\Entity\ProductReceivingRule` made, for the same two
 * reasons and with the same consequence:
 *
 *  - **Core must be able to read nothing this bundle writes.** A `reorder_point` column on
 *    `product_inventory` would be a CORE column: core would have to render it, migrate it, and
 *    decide what it means with this bundle gone. It is not core's decision — nothing in core
 *    reorders anything.
 *  - **Absence is a complete answer.** #597 is explicit that a reorder point must be nullable and
 *    that **null means unmanaged, not zero**: "a default of 0 would put all 400 products on the
 *    low-stock screen the day it ships". A missing ROW says that more strongly than a NULL column
 *    does, because there is no way to accidentally write a 0 into a row that does not exist.
 *
 * So: **no row means this (product, warehouse) is not managed** and it is on no screen. A row means
 * somebody decided a level for it, and `reorder_point` is therefore NOT NULL — 0 is a real level
 * ("tell me when there is nothing left"), and it is reachable only by typing it.
 *
 * Nothing here is ever written by a migration, an import or a recalculation. A level is a commercial
 * decision about how much money to tie up in a shelf; there is no signal in existing data that could
 * stand in for one, and a guessed level is worse than no level because it looks like a decision.
 *
 * ## What is deliberately NOT here
 *
 * Lead time, order multiple, minimum/maximum order quantity, a reordering-policy enum. #597 names
 * every one of them as out of scope and says why: they belong to a planning bundle that would model
 * demand and supply over TIME, which this app does not do, and half of that layer grown into this
 * table would make the real one a rewrite. This repo already carries three columns added "while we
 * are in there" — `incoming_quantity`, `reserved_quantity`, `manual_adjustment` — and the first of
 * those went four issues without a writer.
 *
 * `safety_stock_quantity` is here and is the exception that proves it: it changes no arithmetic
 * (see {@see \InventoryDepthBundle\Reorder\ReorderPointRule}), it is only a second, more urgent
 * band to show a row in.
 *
 * Both foreign keys cascade. This is live configuration about a product and a warehouse that
 * currently exist, not a snapshot of something that happened — when either goes, a policy about the
 * pair is meaningless rather than historical.
 */
#[ORM\Entity(repositoryClass: ProductReorderRuleRepository::class)]
#[ORM\Table(name: 'inventory_reorder_rule')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_reorder_rule', fields: ['product', 'warehouse'])]
class ProductReorderRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    /**
     * NOT nullable, unlike `product_inventory.warehouse_id`.
     *
     * Core allows a warehouse-less inventory row for historical reasons. A reorder level without a
     * warehouse would be a level for nowhere: the whole question this table answers is "should
     * THIS building order more", and two buildings holding the same product will not want the same
     * answer.
     */
    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Warehouse $warehouse;

    /**
     * The level. At or below it, this row wants attention.
     *
     * NOT NULL and no default: the existence of the row is what says "managed", so there is no
     * second state for this column to carry. 0 is legitimate and means "flag it when there is
     * nothing sellable left".
     */
    #[ORM\Column(type: 'quantity', name: 'reorder_point')]
    private string $reorderPoint = '0.0000';

    /**
     * How much to suggest ordering when it fires. NULL means "no standing answer" — the screen then
     * suggests the shortfall, which is the smallest order that clears the flag.
     *
     * A suggestion and nothing more. Nothing in this bundle raises a purchase order, and #597 is
     * explicit about why: "the moment software raises orders on its own somebody has to explain a
     * delivery nobody asked for".
     */
    #[ORM\Column(type: 'quantity', name: 'reorder_quantity', nullable: true)]
    private ?string $reorderQuantity = null;

    /**
     * A second, more urgent line UNDER the point — the buffer you were never supposed to eat into.
     *
     * It changes no arithmetic. Whether a row is short is decided against `reorder_point` alone;
     * this only decides whether the row is shown as urgent. Folding it into the point would make
     * two numbers mean one thing and lose the distinction between "time to order" and "we are into
     * the reserve".
     */
    #[ORM\Column(type: 'quantity', name: 'safety_stock_quantity', nullable: true)]
    private ?string $safetyStockQuantity = null;

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function setWarehouse(Warehouse $warehouse): self { $this->warehouse = $warehouse; return $this; }

    public function getReorderPoint(): string { return $this->reorderPoint; }

    /** Clamped at 0 — a negative level is not a level, and availability itself can go negative. */
    public function setReorderPoint(string|int|float $reorderPoint): self { $this->reorderPoint = QuantityScale::canonical(max(0, (float) $reorderPoint)); return $this; }

    public function getReorderQuantity(): ?string { return $this->reorderQuantity; }

    /** NULL passes through — it is the "no standing answer" state, not a zero. */
    public function setReorderQuantity(string|int|float|null $reorderQuantity): self
    {
        $this->reorderQuantity = $reorderQuantity === null ? null : QuantityScale::canonical(max(0, (float) $reorderQuantity));

        return $this;
    }

    public function getSafetyStockQuantity(): ?string { return $this->safetyStockQuantity; }

    public function setSafetyStockQuantity(string|int|float|null $safetyStockQuantity): self
    {
        $this->safetyStockQuantity = $safetyStockQuantity === null ? null : QuantityScale::canonical(max(0, (float) $safetyStockQuantity));

        return $this;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution, so a changed level is readable in the log. */
    public function getLabel(): string
    {
        return sprintf(
            'Reorder level %s for %s at %s',
            (new DisplayNumber())->qty($this->reorderPoint),
            $this->product->getSku() ?: ('#' . (string) $this->product->getId()),
            $this->warehouse->getName(),
        );
    }
}
