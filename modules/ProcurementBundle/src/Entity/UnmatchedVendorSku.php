<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\ProductCore;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Repository\UnmatchedVendorSkuRepository;

/**
 * A vendor SKU a sheet import saw with no `our_sku` mapping and no existing VendorPrice to refresh
 * (docs/plans/2026-09-15-vendor-sheet-and-po-csv-import.md) — the worklist the owner asked for:
 * "any new vendor sku we don't recognize need to show up somewhere and get flagged for either
 * linking or new internal sku creation." Never silently dropped.
 *
 * Unique on (vendor, vendorSku): a repeat sighting refreshes the same row (lastSeen*) rather than
 * creating a second one, so the worklist shows one row per distinct unmatched SKU regardless of how
 * many sheets have named it.
 */
#[ORM\Entity(repositoryClass: UnmatchedVendorSkuRepository::class)]
#[ORM\Table(name: 'unmatched_vendor_sku')]
#[ORM\UniqueConstraint(name: 'uniq_unmatched_vendor_sku', fields: ['vendor', 'vendorSku'])]
class UnmatchedVendorSku
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_IGNORED = 'ignored';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Vendor $vendor;

    #[ORM\Column(name: 'vendor_sku', length: 80)]
    private string $vendorSku;

    #[ORM\Column(name: 'last_seen_name', length: 255, nullable: true)]
    private ?string $lastSeenName = null;

    #[ORM\Column(name: 'last_seen_price', type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $lastSeenPrice = null;

    /** `quantity` type (NUMERIC(14,4)), not a plain integer — see VendorPrice::$availableQuantity's own note (#601). */
    #[ORM\Column(name: 'last_seen_quantity', type: 'quantity', nullable: true)]
    private ?string $lastSeenQuantity = null;

    #[ORM\Column(name: 'first_seen_at')]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_seen_at')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'resolved_product_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ProductCore $resolvedProduct = null;

    #[ORM\Column(name: 'resolved_at', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(Vendor $vendor, string $vendorSku)
    {
        $this->vendor = $vendor;
        $this->vendorSku = $vendorSku;
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->firstSeenAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getVendor(): Vendor { return $this->vendor; }
    public function getVendorSku(): string { return $this->vendorSku; }
    public function getLastSeenName(): ?string { return $this->lastSeenName; }
    public function setLastSeenName(?string $lastSeenName): self { $this->lastSeenName = $lastSeenName; return $this; }
    public function getLastSeenPrice(): ?string { return $this->lastSeenPrice; }
    public function setLastSeenPrice(?string $lastSeenPrice): self { $this->lastSeenPrice = $lastSeenPrice; return $this; }
    public function getLastSeenQuantity(): ?string { return $this->lastSeenQuantity; }
    public function setLastSeenQuantity(string|int|float|null $lastSeenQuantity): self { $this->lastSeenQuantity = $lastSeenQuantity === null ? null : QuantityScale::canonical($lastSeenQuantity); return $this; }
    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function getStatus(): string { return $this->status; }

    /** Marks another sighting: refreshes lastSeen* without disturbing status/resolution. */
    public function touch(?string $name, ?string $price, string|int|float|null $quantity): self
    {
        $this->lastSeenName = $name;
        $this->lastSeenPrice = $price;
        $this->lastSeenQuantity = $quantity === null ? null : QuantityScale::canonical($quantity);
        $this->lastSeenAt = new \DateTimeImmutable();

        return $this;
    }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }

    /** Linking once keeps it linked: a later sheet naming the same vendor SKU resolves silently (see VendorSkuResolver). */
    public function resolveTo(ProductCore $product): self
    {
        $this->status = self::STATUS_RESOLVED;
        $this->resolvedProduct = $product;
        $this->resolvedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Kept for history rather than deleted. */
    public function ignore(): self
    {
        $this->status = self::STATUS_IGNORED;
        $this->resolvedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getResolvedProduct(): ?ProductCore { return $this->resolvedProduct; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
}
