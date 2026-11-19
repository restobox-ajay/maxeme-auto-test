<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Entity\ProductCore;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Entity\Vendor;

/**
 * Who the shop buys a product from (mockup product form "Vendor", and the product list's Vendor
 * column): at most one vendor per product. Kept in the shop's own table rather than as a column on
 * product_core, so the core product schema is untouched; ProcurementBundle's vendor prices (a rate
 * card of any number of vendors per product) are a separate thing and are not changed by it.
 * Deleting the product or the vendor deletes the row, i.e. clears the product's vendor.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_product_vendor')]
#[ORM\UniqueConstraint(name: 'uniq_maxeme_product_vendor_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_maxeme_product_vendor_vendor', columns: ['vendor_id'])]
class ProductVendor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Vendor $vendor;

    public function __construct(ProductCore $product, Vendor $vendor)
    {
        $this->product = $product;
        $this->vendor = $vendor;
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ProductCore { return $this->product; }
    public function getVendor(): Vendor { return $this->vendor; }
    public function setVendor(Vendor $vendor): self { $this->vendor = $vendor; return $this; }

    /** The Activity Log's name for the record. */
    public function __toString(): string
    {
        return sprintf('%s: %s', $this->product->getName(), $this->vendor->getName());
    }
}
