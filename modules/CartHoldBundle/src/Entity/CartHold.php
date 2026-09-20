<?php

declare(strict_types=1);

namespace CartHoldBundle\Entity;

use App\Entity\CartItem;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use CartHoldBundle\Repository\CartHoldRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per CartItem currently reserving inventory. References the item by FK rather than
 * duplicating its session/product/region/quantity — a hold either exists for a given CartItem
 * or it doesn't, and everything about what it's holding (product, region, quantity) is always
 * read live through that association, so there's nothing to keep manually in sync when the
 * cart item changes (including its region converting when the session switches regions).
 */
#[ORM\Entity(repositoryClass: CartHoldRepository::class)]
#[ORM\Table(name: 'cart_hold')]
class CartHold
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CartItem::class)]
    #[ORM\JoinColumn(name: 'cart_item_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private CartItem $cartItem;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCartItem(): CartItem { return $this->cartItem; }
    public function setCartItem(CartItem $cartItem): self { $this->cartItem = $cartItem; return $this; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /** Convenience passthroughs — never duplicated, always read live through the cart item. */
    public function getProduct(): ProductCore { return $this->cartItem->getProduct(); }
    public function getFulfillmentRegion(): FulfillmentRegion { return $this->cartItem->getFulfillmentRegion(); }
    public function getQuantity(): string { return $this->cartItem->getQuantity(); }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        $product = $this->cartItem->getProduct();

        return sprintf(
            '%s held for session %s @ %s',
            $product->getSku() ?: ('#' . ($product->getId() ?? '?')),
            substr($this->cartItem->getCart()->getSessionId(), 0, 12),
            $this->cartItem->getFulfillmentRegion()->getName(),
        );
    }
}
