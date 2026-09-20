<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\VendorPricing;

use App\Entity\ProductCore;
use App\Tests\DoctrineIntegrationTestCase;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorPrice;
use ProcurementBundle\VendorPricing\VendorPriceUpserter;

/**
 * The one shared "find or create this vendor+product's VendorPrice row" — used by both
 * VendorPriceController::save() (the manual Add/Edit screen) and
 * VendorSheetImportRowExecutor::upsertVendorPrice() (the CSV importer), so the two no longer carry
 * independent copies of the same lookup-then-create logic.
 */
final class VendorPriceUpserterTest extends DoctrineIntegrationTestCase
{
    private function makeVendor(): Vendor
    {
        $vendor = (new Vendor())->setName('Upserter Test Vendor ' . uniqid())->setCurrency('CAD');
        $this->em->persist($vendor);
        $this->em->flush();

        return $vendor;
    }

    private function makeProduct(string $sku): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Upserter Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    public function testANewPairCreatesAndPersistsARow(): void
    {
        $vendor = $this->makeVendor();
        $product = $this->makeProduct('UPSERTER-NEW-1');
        $upserter = self::getContainer()->get(VendorPriceUpserter::class);

        $price = $upserter->forPair($vendor, $product);
        $price->setUnitCost('9.9900');
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(VendorPrice::class)->findOneBy(['vendor' => $vendor, 'product' => $product]);
        self::assertInstanceOf(VendorPrice::class, $reloaded);
        // Not assertSame: SQLite does not pad decimal-string trailing zeros on the way back out.
        self::assertEqualsWithDelta(9.99, (float) $reloaded->getUnitCost(), 0.0001);
    }

    public function testAnExistingPairIsReusedNotDuplicated(): void
    {
        $vendor = $this->makeVendor();
        $product = $this->makeProduct('UPSERTER-EXISTING-1');
        $existing = (new VendorPrice())->setVendor($vendor)->setProduct($product)->setUnitCost('5.0000');
        $this->em->persist($existing);
        $this->em->flush();
        $existingId = $existing->getId();

        $upserter = self::getContainer()->get(VendorPriceUpserter::class);
        $found = $upserter->forPair($vendor, $product);

        self::assertSame($existingId, $found->getId(), 'a pair that already has a row must be reused, never duplicated — the unique index would refuse a second one');

        $count = $this->em->getRepository(VendorPrice::class)->count(['vendor' => $vendor, 'product' => $product]);
        self::assertSame(1, $count);
    }
}
