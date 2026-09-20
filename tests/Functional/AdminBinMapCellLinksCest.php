<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\WarehouseLocation;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * #786: every Bin Map cell links into the Stock screen (#787, shipped first — see that issue's
 * step 1) pre-filtered to that bin and warehouse, rather than being a plain non-interactive `<div>`.
 */
final class AdminBinMapCellLinksCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('bin-map-link-' . uniqid() . '@example.test')->setStatus('Active');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function warehouse(FunctionalTester $I, string $suffix): Warehouse
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $region = (new FulfillmentRegion())->setName('Bin Map Region ' . $suffix);
        $em->persist($region);

        return $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
    }

    private function product(FunctionalTester $I, string $sku): ProductCore
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $product = (new ProductCore())->setSku($sku)->setName('Bin Map ' . $sku)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);

        return $product;
    }

    private function placedBin(FunctionalTester $I, Warehouse $warehouse, string $code, int $x, int $y): WarehouseLocation
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode($code)->setSortKey($x)->setMapX($x)->setMapY($y);
        $em->persist($bin);

        return $bin;
    }

    public function clickingAPlacedBinLandsOnJustThatBinsStockInThatWarehouse(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);

        $warehouse = $this->warehouse($I, 'W' . $suffix);
        $otherWarehouse = $this->warehouse($I, 'X' . $suffix);
        $productA = $this->product($I, 'BINMAPA-' . $suffix);
        $productB = $this->product($I, 'BINMAPB-' . $suffix);

        $binA = $this->placedBin($I, $warehouse, 'A-01-' . $suffix, 1, 1);
        $binB = $this->placedBin($I, $warehouse, 'B-01-' . $suffix, 2, 1);
        // Same code as binA, but in a different warehouse — must not leak into binA's link.
        $sameCodeOtherWarehouse = $this->placedBin($I, $otherWarehouse, 'A-01-' . $suffix, 1, 1);

        $em->persist((new InventoryDetail())->setProduct($productA)->setWarehouse($warehouse)->setLocation($binA)->setStatus(InventoryDetail::STATUS_AVAILABLE)->setQuantity('5'));
        $em->persist((new InventoryDetail())->setProduct($productB)->setWarehouse($warehouse)->setLocation($binB)->setStatus(InventoryDetail::STATUS_AVAILABLE)->setQuantity('9'));
        $em->persist((new InventoryDetail())->setProduct($productA)->setWarehouse($otherWarehouse)->setLocation($sameCodeOtherWarehouse)->setStatus(InventoryDetail::STATUS_AVAILABLE)->setQuantity('3'));
        $em->flush();

        $I->amOnPage('/admin/bundles/warehouse-ops/bin-map?filters%5Bwarehouse%5D=' . $warehouse->getId());
        $I->seeResponseCodeIsSuccessful();

        $href = $I->grabAttributeFrom('a.bin-map-cell[title^="' . $binA->getCode() . '"]', 'href');
        $I->assertStringContainsString('/admin/bundles/warehouse-ops/stock', $href);
        $I->assertStringContainsString('filters%5Bbin%5D=' . rawurlencode($binA->getCode()), $href);
        $I->assertStringContainsString('filters%5Bwarehouse%5D=' . $warehouse->getId(), $href);

        $I->amOnPage($href);
        $I->seeResponseCodeIsSuccessful();
        $I->see($productA->getSku());
        $I->dontSee($productB->getSku());
    }

    public function anEmptyPlacedBinLinksAndShowsTheEmptyState(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));

        $warehouse = $this->warehouse($I, 'E' . $suffix);
        $emptyBin = $this->placedBin($I, $warehouse, 'EMPTY-01-' . $suffix, 1, 1);
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->amOnPage('/admin/bundles/warehouse-ops/bin-map?filters%5Bwarehouse%5D=' . $warehouse->getId());
        $I->seeResponseCodeIsSuccessful();

        $href = $I->grabAttributeFrom('a.bin-map-cell[title^="' . $emptyBin->getCode() . '"]', 'href');
        $I->amOnPage($href);
        $I->seeResponseCodeIsSuccessful();
        $I->see('No stock matches.');
    }
}
