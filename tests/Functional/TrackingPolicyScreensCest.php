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
use InventoryDepthBundle\Entity\WarehouseLocation;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The two screens #573 adds, end to end through the real kernel.
 *
 * **Tracking Policies** is core, so it renders with every bundle removed — the declaration of what
 * identity a product's stock carries is a fact about the product, not about the layer that reads
 * it. **The tracking worklist** is the bundle's, because it is a list of `inventory_detail` rows,
 * and it is what replaces refusing a delivery: tracking never blocks, so control is a worklist.
 */
final class TrackingPolicyScreensCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('tracking-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        // The shipped reference rows used to be created by RENDERING the screen below. They are
        // now created once, on LoginSuccessEvent, and amLoggedInAs() does not dispatch that event —
        // it installs a token and never runs the authenticator. So this stands in for the real
        // login's side effect. ReferenceDataSeedingCest posts the actual login form.
        $I->haveSeededReferenceData();
    }

    /** Opening the screen seeds the default, so a fresh install has something to point products at. */
    public function theListRendersAndSeedsTheDefaultPolicy(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/product/tracking-policies');
        $I->seeResponseCodeIs(200);
        $I->see('Tracking Policies');

        $em = $I->grabService(EntityManagerInterface::class);
        $default = $em->getRepository(TrackingPolicy::class)->findOneBy(['name' => TrackingPolicy::DEFAULT_NAME]);

        $I->assertNotNull($default);
        $I->assertSame(TrackingPolicy::MODE_NONE, $default->getMode());
    }

    public function aPolicyCanBeAddedAndEdited(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        // The form is on its own page now — the list screen is the grid and nothing else. Same form,
        // same `save()` action, one page along.
        $I->amOnPage('/admin/product/tracking-policies/new');

        // A relative-path POST rather than submitForm(): with a custom Host header the browser
        // module resolves a crawled form action as an absolute URL and trips its external-URL guard.
        $I->sendAjaxPostRequest('/admin/product/tracking-policies/save', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'id' => 0,
            'name' => 'Serial (warranty)',
            'mode' => TrackingPolicy::MODE_SERIAL,
            'track_in' => '1',
            'sentinel_in' => '  ',
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $policy = $em->getRepository(TrackingPolicy::class)->findOneBy(['name' => 'Serial (warranty)']);

        $I->assertNotNull($policy);
        $I->assertSame(TrackingPolicy::MODE_SERIAL, $policy->getMode());
        $I->assertTrue($policy->isTrackIn());
        $I->assertFalse($policy->isTrackOut(), 'Active-but-not-Physical: captured on the way in, not matched on the way out');
        $I->assertNull($policy->getSentinelIn(), 'a whitespace sentinel is blank, and blank is NULL');
    }

    /** Deleting a policy in use would silently untrack its products, so it is refused. */
    public function aPolicyInUseCannotBeDeleted(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $policy = (new TrackingPolicy())->setName('In Use ' . uniqid())->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true);
        $em->persist($policy);

        $product = (new ProductCore())->setSku('TRKUI-' . strtoupper(substr(uniqid(), -6)))->setName('Tracked');
        $product->setTrackingPolicy($policy);
        $em->persist($product);
        $em->flush();

        $policyId = $policy->getId();

        $I->amOnPage('/admin/product/tracking-policies');
        // The page-wide token from the <meta>, not a field scraped out of a form: the list screen no
        // longer renders a create form, and the only _token inputs left on it are the per-row delete
        // forms — which this policy, being in use, correctly does not get one of.
        $I->sendAjaxPostRequest(sprintf('/admin/product/tracking-policies/%d/delete', $policyId), [
            '_token' => $I->csrfToken(),
        ]);

        $em->clear();
        $I->assertNotNull($em->find(TrackingPolicy::class, $policyId), 'a policy products point at must not be deletable');
    }

    /** The product form offers the policy, and saving one sticks. */
    public function theProductFormSetsAPolicy(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $policy = (new TrackingPolicy())->setName('Form Lot ' . uniqid())->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true);
        $em->persist($policy);

        $product = (new ProductCore())->setSku('TRKFORM-' . strtoupper(substr(uniqid(), -6)))->setName('Form Product');
        $em->persist($product);
        $em->flush();

        $productId = $product->getId();

        $I->amOnPage('/admin/product/inventory/update/' . $productId);
        $I->seeResponseCodeIs(200);
        $I->seeElement('select[name="tracking_policy_id"]');

        $I->sendAjaxPostRequest('/admin/product/inventory/update/' . $productId, [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => 'Form Product',
            'sku' => $product->getSku(),
            'tracking_policy_id' => (string) $policy->getId(),
        ]);

        $em->clear();
        $saved = $em->find(ProductCore::class, $productId);

        $I->assertSame($policy->getId(), $saved->getTrackingPolicy()?->getId());
    }

    /** A flagged row appears on the worklist, and the flag can be cleared from it. */
    public function theWorklistListsFlaggedRowsAndTheFlagCanBeCleared(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService(EntityManagerInterface::class);

        $region = (new FulfillmentRegion())->setName('Tracking Region ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('WORK-' . strtoupper(substr(uniqid(), -6)))
            ->setName('Worklist Product')
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $em->persist($product);

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('W-01');
        $em->persist($bin);
        $em->flush();

        $row = (new InventoryDetail())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setLocation($bin)
            ->setStatus(InventoryDetail::STATUS_AVAILABLE)
            ->setExpectResolution(true);
        $row->setQuantity(12)->touch();
        $em->persist($row);
        $em->flush();

        $rowId = $row->getId();

        $I->amOnPage('/admin/bundles/inventory-depth/tracking');
        $I->seeResponseCodeIs(200);
        $I->see($product->getSku());

        // The filter is a GET param, so a copied URL reproduces the result — standing requirement.
        $I->amOnPage('/admin/bundles/inventory-depth/tracking?filters%5Bexpect%5D=no');
        $I->seeResponseCodeIs(200);
        $I->dontSee($product->getSku(), 'tbody');

        $I->amOnPage('/admin/bundles/inventory-depth/tracking');
        $I->seeElement(sprintf('form[action="/admin/bundles/inventory-depth/tracking/%d/expect"]', $rowId));
        $I->sendAjaxPostRequest(sprintf('/admin/bundles/inventory-depth/tracking/%d/expect', $rowId), [
            '_token' => $I->grabAttributeFrom('#expect-' . $rowId . ' input[name="_token"]', 'value'),
            'expect' => '0',
        ]);

        $em->clear();
        $I->assertFalse($em->find(InventoryDetail::class, $rowId)->isExpectResolution());
    }

    /**
     * A sentinel *serial* is a value the worklist searches on, exactly like a sentinel lot or bin.
     *
     * This is the `d.serial IN (:sentinels)` half of the union (#578), and it is deliberately
     * isolated from the other three ways a row can reach this screen so that only that clause can
     * account for the result:
     *
     *  - `expect_resolution` is **false**, and the page is then re-read under `filters[expect]=no`,
     *    which ANDs `d.expectResolution = false` on top. The flag disjunct is contradicted, so a row
     *    that still appears did so on a *value*.
     *  - the row has **no lot and no bin**, so `l.code` and `loc.code` are NULL and match nothing.
     *
     * The control row is the same product, warehouse, lot (none), bin (none) and quantity, differing
     * only in that its serial is a real one rather than the policy's sentinel. It must stay off the
     * list — which is what separates "the serial is a sentinel" from "the row has a serial at all".
     */
    public function theWorklistFindsARowByItsSentinelSerial(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService(EntityManagerInterface::class);

        // Unique per run: `uniq_live_serial` is global, and a distinctive value makes the
        // see/dontSee pair unambiguous against anything else the fixture set leaves lying around.
        $suffix = strtoupper(substr(uniqid(), -6));
        $sentinelSerial = '[UNSCANNED-' . $suffix . ']';
        $realSerial = 'SN-REAL-' . $suffix;

        $policy = (new TrackingPolicy())
            ->setName('Serial Sentinel ' . $suffix)
            ->setMode(TrackingPolicy::MODE_SERIAL)
            ->setTrackIn(true)
            ->setSentinelIn($sentinelSerial);
        $em->persist($policy);

        $region = (new FulfillmentRegion())->setName('Serial Region ' . $suffix);
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('SERWORK-' . $suffix)
            ->setName('Unscanned Serial Product')
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $product->setTrackingPolicy($policy);
        $em->persist($product);
        $em->flush();

        // No lot, no bin, and the flag left off: the sentinel serial is the only thing about this
        // row that the worklist query can match on.
        $placeholder = (new InventoryDetail())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setStatus(InventoryDetail::STATUS_AVAILABLE)
            ->setExpectResolution(false)
            ->setSerial($sentinelSerial);
        $placeholder->setQuantity(1)->touch();
        $em->persist($placeholder);

        $identified = (new InventoryDetail())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setStatus(InventoryDetail::STATUS_AVAILABLE)
            ->setExpectResolution(false)
            ->setSerial($realSerial);
        $identified->setQuantity(1)->touch();
        $em->persist($identified);
        $em->flush();

        $I->amOnPage('/admin/bundles/inventory-depth/tracking');
        $I->seeResponseCodeIs(200);
        $I->see($sentinelSerial, 'tbody');
        $I->dontSee($realSerial, 'tbody', 'a row whose serial is a real one is identified, not a placeholder');

        // The flag disjunct is now contradicted, so appearing here is proof of a value match — and
        // with lot and bin both NULL, `d.serial IN (:sentinels)` is the only clause left.
        $I->amOnPage('/admin/bundles/inventory-depth/tracking?filters%5Bexpect%5D=no');
        $I->seeResponseCodeIs(200);
        $I->see($sentinelSerial, 'tbody');
        $I->dontSee($realSerial, 'tbody');
    }

    /** The worklist is the bundle's, so it reads as absent when the bundle is Inactive. */
    public function theWorklistIsAbsentWithoutTheBundle(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $status = $I->grabService(\App\Repository\BundleStatusRepository::class)->ensureBySource('InventoryDepthBundle');
        $status->setStatus(\App\Entity\BundleStatus::STATUS_INACTIVE);
        $em->flush();

        $I->amOnPage('/admin/bundles/inventory-depth/tracking');
        $I->seeResponseCodeIs(404);

        // But the policy screen is core, and stays.
        $I->amOnPage('/admin/product/tracking-policies');
        $I->seeResponseCodeIs(200);
    }
}
