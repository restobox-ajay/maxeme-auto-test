<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\ProductCore;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Repository\VendorPriceRepository;

/**
 * What THIS vendor charges for THIS product (#637), so a purchase order line can price itself
 * instead of being typed from memory or a supplier's last email.
 *
 * The buy-side mirror of `App\Entity\ProductPricing` — one current row per (vendor, product), no
 * history — and deliberately not a document: it is a rate card an admin maintains directly, the way
 * ProductPricing is, not something raised, issued or converted. Real foreign keys to both sides,
 * also matching ProductPricing, because a rate card is live reference data an admin edits in place,
 * not a snapshot of paperwork that has to outlive either side's deletion.
 *
 * ## $orderMultiple: a deliberately narrow stand-in for #601/#625
 *
 * #637 asks for this to interact with units of measure ("a vendor price is per unit of something,
 * and 'case of 12' is the unit that makes both real"), but #601 (real units of measure) is CORE
 * work and explicitly blocked under the current freeze, and #625 (order-multiple reordering policy)
 * is filed ASPIRATIONAL — do not start. Waiting on either means this ships nothing.
 *
 * So this is the "buildable now" shape #601's own writeup names as the alternative to the blocked
 * one: a plain bundle-owned integer, keyed to `product_core.id` with no core schema change, meaning
 * nothing more than "round a purchase quantity up to a multiple of this many". It is NOT a real
 * unit-of-measure conversion — there is no distinct unit label, no base-unit factor, nothing that
 * answers "how many eaches is one of these". `1` (the default) means no rounding at all, which is
 * every vendor price that exists before this column is ever touched.
 *
 * When #601 lands for real, this column is upgraded or retired then, with a real writer attached to
 * it — not carried "for symmetry" the way `product_inventory.manual_adjustment` was.
 */
#[ORM\Entity(repositoryClass: VendorPriceRepository::class)]
#[ORM\Table(name: 'vendor_price')]
#[ORM\UniqueConstraint(name: 'uniq_vendor_price_vendor_product', fields: ['vendor', 'product'])]
#[ORM\Index(name: 'idx_vendor_price_product', fields: ['product'])]
class VendorPrice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Vendor $vendor;

    /**
     * A real, required foreign key — unlike `purchase_order_line.product_id`, which is a frozen
     * snapshot that has to outlive the product's deletion. This row is never frozen onto anything;
     * it is live reference data, exactly like `ProductPricing::$product`, and is deleted along with
     * the product it prices rather than surviving as a dangling record of a rate nobody can act on.
     */
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    /** What THEY call it — same field PurchaseOrderLine::$vendorSku carries, kept here as the master value. */
    #[ORM\Column(name: 'vendor_sku', length: 80, nullable: true)]
    private ?string $vendorSku = null;

    #[ORM\Column(name: 'unit_cost', type: 'decimal', precision: 18, scale: 6)]
    private string $unitCost = '0.0000';

    #[ORM\Column(length: 3, options: ['default' => 'CAD'])]
    private string $currency = 'CAD';

    /** See the class docblock. `1` means no case-rounding at all. */
    #[ORM\Column(type: 'quantity', name: 'order_multiple', options: ['default' => 1])]
    private string $orderMultiple = '1.0000';

    /** An inactive price is kept (for history on the screen) but never offered to price a new line. */
    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * The vendor's own reported quantity we can draw against for backorders (vendor sheet import,
     * docs/plans/2026-09-15-vendor-sheet-and-po-csv-import.md) — NOT the same thing as
     * `ProductInventory::allowBackorder`/`maxBackorderQuantity`, which still govern whether/how much
     * *we* let customers backorder. Null means no vendor sheet has ever reported a figure.
     *
     * `quantity` type (NUMERIC(14,4)), not a plain integer — #601's rule, checked app-wide by
     * QuantityAndRateColumnPrecisionTest: anything named *quantity* carries four decimals, since a
     * vendor's real-world available count is not guaranteed to be a whole number any more than ours is.
     */
    #[ORM\Column(name: 'available_quantity', type: 'quantity', nullable: true)]
    private ?string $availableQuantity = null;

    /**
     * The vendor's own name/description for THIS ITEM (not the vendor's own name — see
     * Vendor::$name for that), carried for audit/sanity-check purposes from a vendor sheet import.
     */
    #[ORM\Column(name: 'vendor_item_name', length: 255, nullable: true)]
    private ?string $vendorItemName = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVendor(): Vendor { return $this->vendor; }
    public function setVendor(Vendor $vendor): self { $this->vendor = $vendor; return $this; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getVendorSku(): ?string { return $this->vendorSku; }
    public function setVendorSku(?string $vendorSku): self { $this->vendorSku = $vendorSku; return $this; }
    public function getUnitCost(): string { return $this->unitCost; }
    public function setUnitCost(string $unitCost): self { $this->unitCost = $unitCost; return $this; }
    public function getCurrency(): string { return $this->currency; }

    public function setCurrency(string $currency): self
    {
        $currency = strtoupper(trim($currency));
        $this->currency = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'CAD';

        return $this;
    }

    public function getOrderMultiple(): string { return $this->orderMultiple; }

    /** Anything less than 1 is nonsense — "round up to a multiple of zero" has no answer — so it floors at 1. */
    public function setOrderMultiple(string|int|float $orderMultiple): self { $this->orderMultiple = QuantityScale::canonical(max(1, (float) $orderMultiple)); return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function getAvailableQuantity(): ?string { return $this->availableQuantity; }
    public function setAvailableQuantity(string|int|float|null $availableQuantity): self { $this->availableQuantity = $availableQuantity === null ? null : QuantityScale::canonical($availableQuantity); return $this; }
    public function getVendorItemName(): ?string { return $this->vendorItemName; }
    public function setVendorItemName(?string $vendorItemName): self { $this->vendorItemName = $vendorItemName; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /**
     * A requested quantity, rounded up to the nearest whole multiple this vendor sells in.
     *
     * Ceiling, not rounding to nearest: a purchaser who needs 13 units of a case-of-12 product needs
     * 24, not 12 — under-ordering to the nearest multiple would silently short the requirement.
     */
    public function roundUpToOrderMultiple(string $quantity): string
    {
        $units = (int) ceil((float) $quantity);
        $multiple = max(1, $this->orderMultiple);
        $rounded = (int) (ceil($units / $multiple) * $multiple);

        return number_format(max($rounded, $multiple), 2, '.', '');
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf('%s / %s', $this->vendor->getName(), $this->product->getSku() ?: $this->product->getName());
    }
}
