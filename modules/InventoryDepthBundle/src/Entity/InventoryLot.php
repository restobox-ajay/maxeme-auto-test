<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Entity;

use App\Entity\ProductCore;
use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Repository\InventoryLotRepository;

/**
 * A batch of one product, with the expiry date that batch carries (#550).
 *
 * **`code` is deliberately NOT unique per product.** `UNIQUE(product_id, code)` is the obvious
 * index and it is wrong: vendors reuse batch codes across production runs with different expiry
 * dates, and forcing two genuinely different batches into one row loses the earlier one's date.
 * A lot's identity is its row id. That is why every screen here shows code AND expiry together —
 * the code alone is ambiguous to the person holding the box.
 *
 * Convention: `expiry` is the **last usable day**. Stock dated the 30th is sellable through the
 * 30th and unsellable from the 1st. Nothing here makes that happen on its own — see
 * InventoryDepthBundle\Command\ExpireLotsCommand, which writes real `status_change` movements.
 * Filtering expiry inside an availability query instead would break `quantity == SUM(available)`,
 * which is the one thing holding this layer together.
 */
#[ORM\Entity(repositoryClass: InventoryLotRepository::class)]
#[ORM\Table(name: 'inventory_lot')]
#[ORM\Index(name: 'idx_lot_lookup', fields: ['product', 'code'])]
#[ORM\Index(name: 'idx_lot_expiry', fields: ['product', 'expiry'])]
class InventoryLot
{
    /** Sellable and pickable, subject to nothing but ordinary availability. The default. */
    public const STATUS_AVAILABLE = 'available';

    /** Held pending a decision — not yet declared a recall, but not to be sold in the meantime. */
    public const STATUS_ON_HOLD = 'on_hold';

    /**
     * Declared unfit to sell or ship (#725). Excluded from the Order/Invoice line's lot picker
     * ({@see \InventoryDepthBundle\Repository\InventoryLotRepository::withAvailableStock()}) and
     * from {@see \InventoryDepthBundle\Inventory\LotAvailabilityProvider::availableForLot()}, so a
     * save naming this lot directly (bypassing the now-empty picker) measures it as having nothing
     * available — the same shortfall-with-required-reason path every other stock constraint in this
     * app already goes through, rather than a second, untested hard-refusal mechanism built for
     * this one case.
     */
    public const STATUS_RECALLED = 'recalled';

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_AVAILABLE, self::STATUS_ON_HOLD, self::STATUS_RECALLED];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\Column(length: 64)]
    private string $code = '';

    /** Last usable day, not the first unusable one. Null means "does not expire". */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiry = null;

    #[ORM\Column(name: 'received_at', nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;

    /** Free text until #555 gives receiving a real vendor to point at. */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_AVAILABLE])]
    private string $status = self::STATUS_AVAILABLE;

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }
    public function getExpiry(): ?\DateTimeImmutable { return $this->expiry; }
    public function setExpiry(?\DateTimeImmutable $expiry): self { $this->expiry = $expiry; return $this; }
    public function getReceivedAt(): ?\DateTimeImmutable { return $this->receivedAt; }
    public function setReceivedAt(?\DateTimeImmutable $receivedAt): self { $this->receivedAt = $receivedAt; return $this; }
    public function getSource(): ?string { return $this->source; }
    public function setSource(?string $source): self { $this->source = $source; return $this; }
    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): self
    {
        $this->status = \in_array($status, self::statuses(), true) ? $status : self::STATUS_AVAILABLE;

        return $this;
    }

    public function isAvailable(): bool { return $this->status === self::STATUS_AVAILABLE; }
    public function isRecalled(): bool { return $this->status === self::STATUS_RECALLED; }

    /** Expired as of $on, where the expiry date itself is still a usable day. */
    public function isExpired(\DateTimeImmutable $on): bool
    {
        return $this->expiry !== null && $this->expiry->format('Y-m-d') < $on->format('Y-m-d');
    }

    /**
     * Code and expiry, always together — the code alone does not identify a batch. Used everywhere
     * a lot is displayed, for exactly that reason.
     */
    public function getLabel(): string
    {
        return $this->expiry === null
            ? $this->code
            : sprintf('%s (exp %s)', $this->code, $this->expiry->format('Y-m-d'));
    }
}
