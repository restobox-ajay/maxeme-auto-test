<?php

declare(strict_types=1);

namespace ProcurementBundle\VendorPricing;

use App\Entity\ProductCore;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorPrice;
use ProcurementBundle\Repository\VendorPriceRepository;

/**
 * The one place "this vendor and product get a VendorPrice row" is decided — shared by the manual
 * Add/Edit screen (VendorPriceController::save(), when no id names an existing row to edit
 * directly) and the CSV importer (VendorSheetImportRowExecutor::upsertVendorPrice()), so a field
 * VendorPrice gains at construction cannot be set in one and forgotten in the other. Each caller
 * still applies its own field values afterward — a full-page form and a sparse CSV row genuinely
 * disagree about what a blank means (replace with nothing vs. leave untouched), and forcing one
 * semantic onto both would be the bug this class exists to prevent, not fix.
 */
final class VendorPriceUpserter
{
    public function __construct(
        private readonly VendorPriceRepository $prices,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Unique on (vendor, product) (see VendorPrice's own constraint), so finding one before making
     * one is not an optimisation — creating blind would let two calls in a row make two rows and
     * have the second refused by the database instead of quietly reusing the first.
     */
    public function forPair(Vendor $vendor, ProductCore $product): VendorPrice
    {
        $existing = $this->prices->findOneBy(['vendor' => $vendor, 'product' => $product]);
        if ($existing instanceof VendorPrice) {
            return $existing;
        }

        $price = (new VendorPrice())->setVendor($vendor)->setProduct($product);
        $this->em->persist($price);

        return $price;
    }
}
