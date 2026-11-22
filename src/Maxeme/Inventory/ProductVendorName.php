<?php

declare(strict_types=1);

namespace App\Maxeme\Inventory;

use App\Contract\Inventory\ProductVendorProviderInterface;
use App\Entity\ProductCore;
use App\Maxeme\Service\ProductVendors;
use App\Repository\BundleStatusRepository;

/**
 * The stock page's "Vendors" line (C8): the vendor set on the product (Maxeme's ProductVendor), as
 * the mockup shows it, ahead of the vendors it was bought from on a purchase order.
 */
final class ProductVendorName implements ProductVendorProviderInterface
{
    public function __construct(private readonly ProductVendors $vendors)
    {
    }

    public function getSource(): string
    {
        return BundleStatusRepository::CORE_SOURCE;
    }

    public function vendorNames(ProductCore $product, int $limit): array
    {
        $vendor = $this->vendors->vendorOf($product);

        return $vendor !== null ? [$vendor->getName()] : [];
    }
}
