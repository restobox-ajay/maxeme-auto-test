<?php

declare(strict_types=1);

namespace WooCommerceBundle\Entity;

use App\Entity\ProductCore;
use Doctrine\ORM\Mapping as ORM;

/**
 * Remembers how one connection's Woo SKU resolves to an internal product (#739), so the next order
 * carrying the same Woo SKU resolves automatically instead of minting a second private product for
 * it. Written once when a line first resolves — whether that resolution matched an existing product
 * outright or auto-created a new one — and can be repointed later if an admin merges the
 * auto-created product into one that already existed.
 */
#[ORM\Entity(repositoryClass: \WooCommerceBundle\Repository\WooCommerceProductMappingRepository::class)]
#[ORM\Table(name: 'woocommerce_product_mapping')]
#[ORM\UniqueConstraint(name: 'uniq_woo_product_mapping_connection_sku', columns: ['connection_id', 'woo_sku'])]
class WooCommerceProductMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WooCommerceConnection::class)]
    #[ORM\JoinColumn(name: 'connection_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?WooCommerceConnection $connection = null;

    #[ORM\Column(name: 'woo_sku', length: 120)]
    private string $wooSku = '';

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ProductCore $product = null;

    /**
     * Woo's own numeric id for this line item's product (always sent, even when sku is blank) and,
     * for a variable product, its variation id. Needed for the OUTBOUND direction this mapping row
     * doesn't otherwise carry: pushing a stock update calls PUT /products/{id} for a simple product
     * or PUT /products/{parent}/variations/{variation} for a variation — wooSku alone (which can be
     * a synthetic key, see WooCommerceProductResolver) is not a Woo API endpoint identifier.
     */
    #[ORM\Column(name: 'woo_product_id', options: ['default' => 0])]
    private int $wooProductId = 0;

    #[ORM\Column(name: 'woo_variation_id', nullable: true)]
    private ?int $wooVariationId = null;

    /**
     * True until an admin decides the auto-created product is the permanent one, or merges this
     * line into a product that already existed. False for a mapping that matched an existing
     * product outright at import time — that was never flagged in the first place.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $flagged = false;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConnection(): ?WooCommerceConnection
    {
        return $this->connection;
    }

    public function setConnection(WooCommerceConnection $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function getWooSku(): string
    {
        return $this->wooSku;
    }

    public function setWooSku(string $wooSku): self
    {
        $this->wooSku = trim($wooSku);

        return $this;
    }

    public function getProduct(): ?ProductCore
    {
        return $this->product;
    }

    public function setProduct(ProductCore $product): self
    {
        $this->product = $product;

        return $this;
    }

    public function getWooProductId(): int
    {
        return $this->wooProductId;
    }

    public function setWooProductId(int $wooProductId): self
    {
        $this->wooProductId = $wooProductId;

        return $this;
    }

    public function getWooVariationId(): ?int
    {
        return $this->wooVariationId;
    }

    public function setWooVariationId(?int $wooVariationId): self
    {
        $this->wooVariationId = $wooVariationId;

        return $this;
    }

    /** What the outbound stock-update endpoint targets: the variation if this is one, else the product itself. */
    public function pushTargetId(): int
    {
        return $this->wooVariationId ?? $this->wooProductId;
    }

    public function isVariation(): bool
    {
        return $this->wooVariationId !== null && $this->wooVariationId > 0;
    }

    public function isFlagged(): bool
    {
        return $this->flagged;
    }

    public function setFlagged(bool $flagged): self
    {
        $this->flagged = $flagged;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
