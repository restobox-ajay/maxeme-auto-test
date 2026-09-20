<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Support\FunctionalTester;

/**
 * An adversarial pass at the Product Inventory Hub's sentinel flagging (see
 * `StockController::lotSerialBreakdown()` / `::pendingIdentificationCount()`), through the real
 * kernel rather than the controller unit tests that cover the happy path.
 *
 * Four ways the flag could quietly lie to an admin, each isolated with a control row so only the
 * mechanism under test can explain the result:
 *
 *  1. A lot code that merely LOOKS like the sentinel — missing a bracket, or differently cased —
 *     is a real identity somebody typed, not a placeholder. `isSentinelIn()`/`sentinelValues()`
 *     compare with `===`, so this must stay unflagged and out of the pending count while the
 *     exact string right beside it is flagged.
 *  2. A sentinel-coded row that nets to zero quantity (received, then fully written off) is not
 *     stock an admin needs to go identify — `pendingIdentificationCount()`'s own `d.quantity <>
 *     0` guard exists for exactly this, and nothing before this feature exercised it.
 *  3. A policy can set its OWN sentinel string, independent of `TrackingPolicy::DEFAULT_SENTINEL`
 *     — the panel has to catch that one too, not just the universal `[PENDING]`.
 *  4. The Hub and the Tracking Worklist are two windows onto the same `sentinelValues()`; clearing
 *     a placeholder on the worklist must make the Hub agree on the very next load, with no cache
 *     or denormalized count sitting in between to go stale.
 */
final class StockHubSentinelVisibilityAdversarialCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('hub-sentinel-' . uniqid() . '@example.test')->setStatus('Active');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function warehouse(FunctionalTester $I, string $suffix): Warehouse
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $region = (new FulfillmentRegion())->setName('Hub Sentinel Region ' . $suffix);
        $em->persist($region);

        return $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
    }

    /**
     * The near-miss lot ('PENDING', no brackets, and '[pending]', wrong case) sits right next to
     * the real sentinel ('[PENDING]') on the same product. Only the exact string may be flagged,
     * and only its quantity may count toward "still needs identification".
     */
    public function aLotThatMerelyResemblesTheSentinelIsNotFlaggedOnlyTheExactStringIs(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);
        $warehouse = $this->warehouse($I, $suffix);

        $policy = (new TrackingPolicy())->setName('Lot Precision ' . $suffix)->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true);
        $em->persist($policy);

        $product = (new ProductCore())
            ->setSku('HUBLOT-' . $suffix)
            ->setName('Lot Precision Product')
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $product->setTrackingPolicy($policy);
        $em->persist($product);
        $em->flush();

        $sentinelLot = (new InventoryLot())->setProduct($product)->setCode('[PENDING]');
        $noBracketsLot = (new InventoryLot())->setProduct($product)->setCode('PENDING');
        $wrongCaseLot = (new InventoryLot())->setProduct($product)->setCode('[pending]');
        $em->persist($sentinelLot);
        $em->persist($noBracketsLot);
        $em->persist($wrongCaseLot);
        $em->flush();

        foreach ([[$sentinelLot, 4], [$noBracketsLot, 6], [$wrongCaseLot, 9]] as [$lot, $qty]) {
            $row = (new InventoryDetail())
                ->setProduct($product)
                ->setWarehouse($warehouse)
                ->setLot($lot)
                ->setStatus(InventoryDetail::STATUS_AVAILABLE);
            $row->setQuantity($qty)->touch();
            $em->persist($row);
        }
        $em->flush();

        $I->amOnPage('/admin/bundles/inventory-depth/stock/product/' . $product->getId());
        $I->seeResponseCodeIsSuccessful();

        // Only the exact sentinel's quantity (4) is "still needs identification" — the 6 and the 9
        // beside it are real, if unusually-shaped, lot codes.
        $I->see('4 units still need identification', 'p.lead');
        $I->see('[PENDING]', 'tbody');
        $I->see('PENDING', 'tbody');
        $I->see('[pending]', 'tbody');

        // Exactly one "Needs ID" badge on the page — the near-misses must not each earn their own.
        $I->assertSame(1, substr_count((string) $I->grabPageSource(), 'Needs ID'));
    }

    /**
     * A sentinel-coded row that has since been fully consumed nets to zero quantity. It is not
     * stock waiting on an admin — `pendingIdentificationCount()`'s `d.quantity <> 0` guard must
     * keep it out of the count, leaving only the real, resolved lot's presence on the page.
     */
    public function aZeroQuantitySentinelRowDoesNotInflateThePendingCount(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);
        $warehouse = $this->warehouse($I, $suffix);

        $policy = (new TrackingPolicy())->setName('Lot ZeroQty ' . $suffix)->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true);
        $em->persist($policy);

        $product = (new ProductCore())
            ->setSku('HUBZERO-' . $suffix)
            ->setName('Zero Quantity Sentinel Product')
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $product->setTrackingPolicy($policy);
        $em->persist($product);
        $em->flush();

        $depleted = (new InventoryLot())->setProduct($product)->setCode('[PENDING]');
        $resolved = (new InventoryLot())->setProduct($product)->setCode('LOT-REAL-' . $suffix);
        $em->persist($depleted);
        $em->persist($resolved);
        $em->flush();

        $depletedRow = (new InventoryDetail())
            ->setProduct($product)->setWarehouse($warehouse)->setLot($depleted)->setStatus(InventoryDetail::STATUS_AVAILABLE);
        $depletedRow->setQuantity(0)->touch();
        $em->persist($depletedRow);

        $resolvedRow = (new InventoryDetail())
            ->setProduct($product)->setWarehouse($warehouse)->setLot($resolved)->setStatus(InventoryDetail::STATUS_AVAILABLE);
        $resolvedRow->setQuantity(3)->touch();
        $em->persist($resolvedRow);
        $em->flush();

        $I->amOnPage('/admin/bundles/inventory-depth/stock/product/' . $product->getId());
        $I->seeResponseCodeIsSuccessful();

        // A zeroed-out placeholder is not stock waiting on identification.
        $I->dontSee('still need');
        $I->dontSee('Needs ID');

        // A zero-quantity lot never carried AVAILABLE stock, so withAvailableStock() excludes it
        // from the Lots panel entirely — scoped to that section specifically, since the "Where It
        // Is" table above it lists every detail row regardless of quantity and legitimately still
        // shows the depleted [PENDING] row there.
        $lotsSection = '//h2[normalize-space(text())="Lots"]/ancestor::section[1]';
        $I->seeElement($lotsSection . '//td[@data-label="Lot"][contains(text(), "LOT-REAL-' . $suffix . '")]');
        $I->dontSeeElement($lotsSection . '//td[@data-label="Lot"][contains(text(), "[PENDING]")]');
    }

    /**
     * The default `[PENDING]` is not the only sentinel in play — a policy may set its own
     * `sentinel_in`. `sentinelValues()` aggregates every policy's string, so a serial equal to
     * THIS policy's custom sentinel must be flagged exactly like the universal default would be,
     * while an ordinary scanned serial on the same product stays clean.
     */
    public function aPolicySpecificSentinelDifferentFromTheGlobalDefaultIsCaughtOnTheHub(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $sentinelSerial = '[UNSCANNED-' . $suffix . ']';
        $realSerial = 'SN-REAL-' . $suffix;

        $em = $I->grabService(EntityManagerInterface::class);
        $warehouse = $this->warehouse($I, $suffix);

        $policy = (new TrackingPolicy())
            ->setName('Serial Custom Sentinel ' . $suffix)
            ->setMode(TrackingPolicy::MODE_SERIAL)
            ->setTrackIn(true)
            ->setSentinelIn($sentinelSerial);
        $em->persist($policy);

        $product = (new ProductCore())
            ->setSku('HUBSER-' . $suffix)
            ->setName('Custom Sentinel Serial Product')
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $product->setTrackingPolicy($policy);
        $em->persist($product);
        $em->flush();

        $placeholder = (new InventoryDetail())
            ->setProduct($product)->setWarehouse($warehouse)->setStatus(InventoryDetail::STATUS_AVAILABLE)->setSerial($sentinelSerial);
        $placeholder->setQuantity(1)->touch();
        $em->persist($placeholder);

        $identified = (new InventoryDetail())
            ->setProduct($product)->setWarehouse($warehouse)->setStatus(InventoryDetail::STATUS_AVAILABLE)->setSerial($realSerial);
        $identified->setQuantity(1)->touch();
        $em->persist($identified);
        $em->flush();

        $I->amOnPage('/admin/bundles/inventory-depth/stock/product/' . $product->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('1 unit still needs identification', 'p.lead');
        $I->see($sentinelSerial, 'tbody');
        $I->see($realSerial, 'tbody');
        $I->assertSame(1, substr_count((string) $I->grabPageSource(), 'Needs ID'), 'only the custom-sentinel row earns the badge');
    }

    /**
     * The Hub's pending count and the Tracking Worklist read the same `sentinelValues()`/
     * `expectResolution` union — clearing a row on the worklist must make the Hub agree on its
     * very next load, proving there is no separate, staleness-prone count kept for the Hub.
     */
    public function resolvingAPlaceholderOnTheWorklistClearsItFromTheHubOnNextLoad(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);
        $warehouse = $this->warehouse($I, $suffix);

        $policy = (new TrackingPolicy())->setName('Cross Screen ' . $suffix)->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true);
        $em->persist($policy);

        $product = (new ProductCore())
            ->setSku('HUBCROSS-' . $suffix)
            ->setName('Cross Screen Product')
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $product->setTrackingPolicy($policy);
        $em->persist($product);

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('X-01');
        $em->persist($bin);
        $em->flush();

        $row = (new InventoryDetail())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setLocation($bin)
            ->setStatus(InventoryDetail::STATUS_AVAILABLE)
            ->setExpectResolution(true);
        $row->setQuantity(5)->touch();
        $em->persist($row);
        $em->flush();
        $rowId = $row->getId();

        $I->amOnPage('/admin/bundles/inventory-depth/stock/product/' . $product->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('5 units still need identification', 'p.lead');
        $I->seeElement(sprintf(
            'a[href="%s"]',
            $I->grabService(UrlGeneratorInterface::class)
                ->generate('admin_bundle_inventory_depth_tracking_worklist', ['filters' => ['product' => $product->getSku()]]),
        ));

        // Resolve it exactly the way TrackingWorklistController's own screen does.
        $I->amOnPage('/admin/bundles/inventory-depth/tracking');
        $I->sendAjaxPostRequest(sprintf('/admin/bundles/inventory-depth/tracking/%d/expect', $rowId), [
            '_token' => $I->grabAttributeFrom('#expect-' . $rowId . ' input[name="_token"]', 'value'),
            'expect' => '0',
        ]);

        $I->amOnPage('/admin/bundles/inventory-depth/stock/product/' . $product->getId());
        $I->seeResponseCodeIsSuccessful();
        // The worklist cleared the only reason this product had a pending count.
        $I->dontSee('still need');
        $I->dontSee('Needs ID');
    }
}
