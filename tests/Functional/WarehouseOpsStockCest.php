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
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * #787 step 1 — the read-only Warehouse → Stock table. Each case seeds only the rows it needs to
 * tell a match from a non-match apart, and asserts the row that should NOT appear alongside the
 * one that should, the same discipline as every other list-screen Cest in this suite.
 */
final class WarehouseOpsStockCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('warehouse-stock-' . uniqid() . '@example.test')->setStatus('Active');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function warehouse(FunctionalTester $I, string $suffix): Warehouse
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $region = (new FulfillmentRegion())->setName('Stock Region ' . $suffix);
        $em->persist($region);

        return $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
    }

    private function product(FunctionalTester $I, string $sku): ProductCore
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $product = (new ProductCore())->setSku($sku)->setName('Stock Screen ' . $sku)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);

        return $product;
    }

    private function bin(FunctionalTester $I, Warehouse $warehouse, string $code): WarehouseLocation
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode($code);
        $em->persist($bin);

        return $bin;
    }

    private function lot(FunctionalTester $I, ProductCore $product, string $code, ?\DateTimeImmutable $expiry = null): InventoryLot
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $lot = (new InventoryLot())->setProduct($product)->setCode($code)->setExpiry($expiry);
        $em->persist($lot);

        return $lot;
    }

    private function detail(FunctionalTester $I, ProductCore $product, Warehouse $warehouse, ?WarehouseLocation $bin, ?InventoryLot $lot, string $quantity, string $status = InventoryDetail::STATUS_AVAILABLE, ?string $serial = null): InventoryDetail
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $detail = (new InventoryDetail())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setLocation($bin)
            ->setLot($lot)
            ->setSerial($serial)
            ->setStatus($status);
        $detail->setQuantity($quantity)->touch();
        $em->persist($detail);

        return $detail;
    }

    // ----------------------------------------------------------------- filters, one at a time

    public function eachFilterAloneShowsMatchesAndHidesNonMatches(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));

        $warehouseA = $this->warehouse($I, 'A' . $suffix);
        $warehouseB = $this->warehouse($I, 'B' . $suffix);
        $productA = $this->product($I, 'STOCKA-' . $suffix);
        $productB = $this->product($I, 'STOCKB-' . $suffix);
        $binA = $this->bin($I, $warehouseA, 'BIN-A-' . $suffix);
        $binB = $this->bin($I, $warehouseA, 'BIN-B-' . $suffix);
        $lotA = $this->lot($I, $productA, 'LOT-A-' . $suffix);
        $lotB = $this->lot($I, $productA, 'LOT-B-' . $suffix);

        $this->detail($I, $productA, $warehouseA, $binA, $lotA, '1', InventoryDetail::STATUS_AVAILABLE, 'SER-A-' . $suffix);
        $this->detail($I, $productB, $warehouseB, $binB, $lotB, '20', InventoryDetail::STATUS_QUARANTINE);
        $I->grabService(EntityManagerInterface::class)->flush();

        // Product filter.
        $I->amOnPage('/admin/bundles/warehouse-ops/stock?filters%5Bproduct%5D=' . $productA->getSku());
        $I->seeResponseCodeIsSuccessful();
        $I->see($productA->getSku());
        $I->dontSee($productB->getSku());

        // Warehouse filter.
        $I->amOnPage('/admin/bundles/warehouse-ops/stock?filters%5Bwarehouse%5D=' . $warehouseA->getId());
        $I->see($productA->getSku());
        $I->dontSee($productB->getSku());

        // Bin filter — exact code match, and the SAME code in a different warehouse must not leak in.
        $I->amOnPage('/admin/bundles/warehouse-ops/stock?filters%5Bbin%5D=' . $binA->getCode());
        $I->see($productA->getSku());
        $I->dontSee($productB->getSku());

        // Lot filter.
        $I->amOnPage('/admin/bundles/warehouse-ops/stock?filters%5Blot%5D=' . $lotA->getCode());
        $I->see($productA->getSku());
        $I->dontSee($productB->getSku());

        // Serial filter (substring).
        $I->amOnPage('/admin/bundles/warehouse-ops/stock?filters%5Bserial%5D=SER-A-' . $suffix);
        $I->see($productA->getSku());
        $I->dontSee($productB->getSku());

        // Status filter.
        $I->amOnPage('/admin/bundles/warehouse-ops/stock?filters%5Bstatus%5D=' . InventoryDetail::STATUS_QUARANTINE);
        $I->see($productB->getSku());
        $I->dontSee($productA->getSku());
    }

    public function zeroQuantityRowsNeverAppear(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));

        $warehouse = $this->warehouse($I, 'ZQ' . $suffix);
        $product = $this->product($I, 'STOCKZQ-' . $suffix);
        $this->detail($I, $product, $warehouse, null, null, '0');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->amOnPage('/admin/bundles/warehouse-ops/stock?filters%5Bproduct%5D=' . $product->getSku());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee($product->getSku());
    }

    public function nonAvailableRowsHaveNoCheckboxAndSayWhyNotMovable(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));

        $warehouse = $this->warehouse($I, 'QC' . $suffix);
        $product = $this->product($I, 'STOCKQC-' . $suffix);
        $available = $this->detail($I, $product, $warehouse, null, null, '5', InventoryDetail::STATUS_AVAILABLE);
        $quarantine = $this->detail($I, $product, $warehouse, null, null, '3', InventoryDetail::STATUS_QUARANTINE);
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->amOnPage('/admin/bundles/warehouse-ops/stock?filters%5Bproduct%5D=' . $product->getSku());
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('input[type="checkbox"][name="rows[]"][value="' . $available->getId() . '"]');
        $I->dontSeeElement('input[type="checkbox"][name="rows[]"][value="' . $quarantine->getId() . '"]');
        // The reason is on the cell's title attribute, not visible text.
        $I->seeElement('span[title="quarantine: not movable"]');
    }

    // ----------------------------------------------------------------- narrowing

    public function chosenBinNarrowsLotOptionsWithCountsAndTheReverse(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));

        $warehouse = $this->warehouse($I, 'N' . $suffix);
        $product = $this->product($I, 'STOCKN-' . $suffix);
        $binX = $this->bin($I, $warehouse, 'BIN-X-' . $suffix);
        $binY = $this->bin($I, $warehouse, 'BIN-Y-' . $suffix);
        $lotInX = $this->lot($I, $product, 'LOT-INX-' . $suffix);
        $lotInY = $this->lot($I, $product, 'LOT-INY-' . $suffix);

        $this->detail($I, $product, $warehouse, $binX, $lotInX, '5');
        $this->detail($I, $product, $warehouse, $binX, $lotInX, '3');
        $this->detail($I, $product, $warehouse, $binY, $lotInY, '7');
        $I->grabService(EntityManagerInterface::class)->flush();

        // Choosing bin X narrows the Lot select to exactly LOT-INX, with a count of 2 rows.
        $I->amOnPage('/admin/bundles/warehouse-ops/stock?filters%5Bbin%5D=' . $binX->getCode());
        $I->seeResponseCodeIsSuccessful();
        $I->see($lotInX->getCode() . ' (2)');
        $I->dontSee($lotInY->getCode());

        // And the reverse: choosing lot Y narrows the Bin select to exactly BIN-Y, with a count of 1.
        $I->amOnPage('/admin/bundles/warehouse-ops/stock?filters%5Blot%5D=' . $lotInY->getCode());
        $I->see($binY->getCode() . ' (1)');
        $I->dontSee($binX->getCode());
    }

    public function aChangedFilterThatInvalidatesAnotherClearsItAndShowsANotice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));

        $warehouse = $this->warehouse($I, 'C' . $suffix);
        $product = $this->product($I, 'STOCKC-' . $suffix);
        $binX = $this->bin($I, $warehouse, 'BIN-CX-' . $suffix);
        $binY = $this->bin($I, $warehouse, 'BIN-CY-' . $suffix);
        $lotInX = $this->lot($I, $product, 'LOT-CX-' . $suffix);

        $this->detail($I, $product, $warehouse, $binX, $lotInX, '4');
        $this->detail($I, $product, $warehouse, $binY, null, '6');
        $I->grabService(EntityManagerInterface::class)->flush();

        // Lot LOT-CX only exists in bin X. Asking for lot=LOT-CX AND bin=BIN-CY together is a
        // combination no row satisfies — the bin filter (the one that makes it impossible) wins,
        // and the lot filter is the one cleared with a notice.
        $I->amOnPage(sprintf(
            '/admin/bundles/warehouse-ops/stock?filters%%5Bbin%%5D=%s&filters%%5Blot%%5D=%s',
            $binY->getCode(),
            $lotInX->getCode(),
        ));
        $I->seeResponseCodeIsSuccessful();
        $I->see('cleared');
        // The bin filter survives, and its row (bin Y, no lot) is shown rather than an empty result.
        $I->see($product->getSku());
    }

    // ----------------------------------------------------------------- expiry

    public function expiryFilterIsInclusiveOfTheBoundaryAndExcludesLaterAndUndated(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));

        $warehouse = $this->warehouse($I, 'E' . $suffix);
        $product = $this->product($I, 'STOCKE-' . $suffix);
        $bin = $this->bin($I, $warehouse, 'BIN-E-' . $suffix);

        $onBoundary = $this->lot($I, $product, 'LOT-ON-' . $suffix, new \DateTimeImmutable('2026-06-15'));
        $later = $this->lot($I, $product, 'LOT-LATER-' . $suffix, new \DateTimeImmutable('2026-06-16'));
        $undated = $this->lot($I, $product, 'LOT-UNDATED-' . $suffix, null);

        $this->detail($I, $product, $warehouse, $bin, $onBoundary, '1');
        $this->detail($I, $product, $warehouse, $bin, $later, '1');
        $this->detail($I, $product, $warehouse, $bin, $undated, '1');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->amOnPage('/admin/bundles/warehouse-ops/stock?filters%5Bexpiry%5D=2026-06-15');
        $I->seeResponseCodeIsSuccessful();
        $I->see($onBoundary->getCode());
        $I->dontSee($later->getCode());
        $I->dontSee($undated->getCode());
    }
}
