<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\DisplayNumber;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per mismatch a periodic recalculation finds between a ProductInventory bucket's
 * cached value and what it recomputes from source of truth. A mismatch here always indicates
 * a bug in the incremental sync path (cart-hold sync or order reconciliation) — the cache and
 * source of truth should never disagree if that path is working correctly — so every row is
 * both a corrective action (the cache gets overwritten) and a signal worth alerting on.
 *
 * Shared schema, core-owned (not bundle-owned) so any recalc job — core's app:inventory-recalc
 * (cart-hold/pending) or Number1ProductImportBundle's own approved recalc — can write to it
 * without needing to depend on each other or on a specific bundle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_reconciliation_discrepancy')]
class InventoryReconciliationDiscrepancy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Warehouse $warehouse;

    #[ORM\Column(length: 20)]
    private string $bucket;

    #[ORM\Column(type: 'quantity')]
    private string $cachedQuantity;

    #[ORM\Column(type: 'quantity')]
    private string $recomputedQuantity;

    /** Which recalc job found this, e.g. 'inventory_recalc_cron', 'product_import_approved_recalc'. */
    #[ORM\Column(length: 60)]
    private string $source;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function setWarehouse(Warehouse $warehouse): self { $this->warehouse = $warehouse; return $this; }
    public function getBucket(): string { return $this->bucket; }
    public function setBucket(string $bucket): self { $this->bucket = $bucket; return $this; }
    public function getCachedQuantity(): string { return $this->cachedQuantity; }
    public function setCachedQuantity(string|int|float $cachedQuantity): self { $this->cachedQuantity = QuantityScale::canonical($cachedQuantity); return $this; }
    public function getRecomputedQuantity(): string { return $this->recomputedQuantity; }
    public function setRecomputedQuantity(string|int|float $recomputedQuantity): self { $this->recomputedQuantity = QuantityScale::canonical($recomputedQuantity); return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $source): self { $this->source = $source; return $this; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }

    public function getLabel(): string
    {
        return sprintf(
            '%s @ %s — %s: cached %s, recomputed %s (%s)',
            $this->product->getSku() ?: ('#' . ($this->product->getId() ?? '?')),
            $this->warehouse->getName(),
            $this->bucket,
            (new DisplayNumber())->qty($this->cachedQuantity),
            (new DisplayNumber())->qty($this->recomputedQuantity),
            $this->source,
        );
    }
}
