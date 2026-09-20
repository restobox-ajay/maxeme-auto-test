<?php

declare(strict_types=1);

namespace ProcurementBundle\Import;

use App\Entity\ProductCore;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorPrice;
use ProcurementBundle\Repository\VendorPriceRepository;

/**
 * The one piece of logic both vendor-sheet and PO-line CSV import need (see
 * docs/plans/2026-09-15-vendor-sheet-and-po-csv-import.md, §1): given a vendor and a row carrying
 * an optional our_sku and/or vendor_sku, resolve to a ProductCore (and that vendor's existing
 * VendorPrice for it, if any), or say precisely why it could not.
 */
final class VendorSkuResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VendorPriceRepository $vendorPrices,
    ) {
    }

    public function resolve(Vendor $vendor, ?string $ourSku, ?string $vendorSku): VendorSkuResolution
    {
        $ourSku = trim((string) $ourSku);
        $vendorSku = trim((string) $vendorSku);

        if ($ourSku !== '') {
            $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => $ourSku]);
            if (!$product instanceof ProductCore) {
                return VendorSkuResolution::failure(sprintf('our_sku "%s" not found.', $ourSku));
            }

            // Matched by product, not by the vendor_sku string, so a VendorPrice belonging to a
            // DIFFERENT product that happens to share a vendor_sku value is never returned here —
            // see the class docblock of the bug this fixed before it ever shipped.
            $existing = $this->vendorPrices->findOneActiveFor($vendor, $product)
                ?? $this->em->getRepository(VendorPrice::class)->findOneBy(['vendor' => $vendor, 'product' => $product]);

            return VendorSkuResolution::success($product, $existing);
        }

        if ($vendorSku !== '') {
            $existing = $this->vendorPrices->findOneByVendorSku($vendor, $vendorSku);
            if ($existing instanceof VendorPrice) {
                return VendorSkuResolution::success($existing->getProduct(), $existing);
            }

            return VendorSkuResolution::unmatched($vendorSku);
        }

        return VendorSkuResolution::failure('Row names neither our_sku nor vendor_sku.');
    }
}
