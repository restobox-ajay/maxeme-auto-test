<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\WarehouseFulfillmentRegionService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommercePushQueueItem;
use WooCommerceBundle\Entity\WooCommerceProductMapping;
use WooCommerceBundle\Repository\WooCommercePushQueueItemRepository;
use WooCommerceBundle\Repository\WooCommerceSyncRunRepository;

/** WooCommerceBundle's Bulk Resync admin screen (#739) — the scheduled backstop, not the event-driven queue. */
final class WooCommerceBulkResyncCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('woo-resync-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function connection(FunctionalTester $I, string $name = 'Main Store'): WooCommerceConnection
    {
        $connection = (new WooCommerceConnection())
            ->setName($name)->setSlug(strtolower(str_replace(' ', '-', $name)) . '-' . uniqid())
            ->setStoreUrl('https://x.example.com')
            ->setConsumerKey('ck')->setConsumerSecret('cs')->setWebhookSecret('whsec')->setActive(true);
        $I->haveInRepository($connection);

        return $connection;
    }

    private function tokenOn(FunctionalTester $I, string $url): string
    {
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();

        return (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    public function theHistoryStartsEmpty(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/woocommerce/bulk-resync');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Bulk Resync');
        $I->see('No resync runs yet.');
    }

    public function runningForOneStoreQueuesOnlyItsMappedProducts(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $storeA = $this->connection($I, 'Store A');
        $storeB = $this->connection($I, 'Store B');
        $productA = (new ProductCore())->setSku('A-1')->setName('Product A');
        $productB = (new ProductCore())->setSku('B-1')->setName('Product B');
        $I->haveInRepository($productA);
        $I->haveInRepository($productB);
        $I->haveInRepository((new WooCommerceProductMapping())->setConnection($storeA)->setWooSku('A-1')->setProduct($productA)->setWooProductId(1));
        $I->haveInRepository((new WooCommerceProductMapping())->setConnection($storeB)->setWooSku('B-1')->setProduct($productB)->setWooProductId(2));

        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/bulk-resync');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/bulk-resync/run', ['_token' => $token, 'connection_id' => $storeA->getId()]);
        $I->seeResponseCodeIsSuccessful();

        $runs = $I->grabService(WooCommerceSyncRunRepository::class)->findAllNewestFirst();
        $I->assertCount(1, $runs);
        $I->assertSame(1, $runs[0]->getQueuedCount());
        $I->assertSame($storeA->getId(), $runs[0]->getConnection()->getId());

        $itemA = $I->grabService(WooCommercePushQueueItemRepository::class)->findOneForConnectionAndProduct($storeA, $productA);
        $I->assertNotNull($itemA);
        $itemB = $I->grabService(WooCommercePushQueueItemRepository::class)->findOneForConnectionAndProduct($storeB, $productB);
        $I->assertNull($itemB, 'a run scoped to Store A must not touch Store B');
    }

    public function runningForEveryStoreCoversAllMappedProducts(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $storeA = $this->connection($I, 'Store A');
        $storeB = $this->connection($I, 'Store B');
        $productA = (new ProductCore())->setSku('A-2')->setName('Product A2');
        $productB = (new ProductCore())->setSku('B-2')->setName('Product B2');
        $I->haveInRepository($productA);
        $I->haveInRepository($productB);
        $I->haveInRepository((new WooCommerceProductMapping())->setConnection($storeA)->setWooSku('A-2')->setProduct($productA)->setWooProductId(1));
        $I->haveInRepository((new WooCommerceProductMapping())->setConnection($storeB)->setWooSku('B-2')->setProduct($productB)->setWooProductId(2));

        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/bulk-resync');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/bulk-resync/run', ['_token' => $token, 'connection_id' => '0']);
        $I->seeResponseCodeIsSuccessful();

        $runs = $I->grabService(WooCommerceSyncRunRepository::class)->findAllNewestFirst();
        $I->assertSame(2, $runs[0]->getQueuedCount());
        $I->assertNull($runs[0]->getConnection());
    }

    public function anIneligibleMappedProductIsNotQueued(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $connection = $this->connection($I);

        $eligible = (new ProductCore())->setSku('ELIGIBLE-1')->setName('Eligible Product');
        $inactive = (new ProductCore())->setSku('INACTIVE-1')->setName('Inactive Product')->deactivate();
        $I->haveInRepository($eligible);
        $I->haveInRepository($inactive);
        $I->haveInRepository((new WooCommerceProductMapping())->setConnection($connection)->setWooSku('ELIGIBLE-1')->setProduct($eligible)->setWooProductId(1));
        $I->haveInRepository((new WooCommerceProductMapping())->setConnection($connection)->setWooSku('INACTIVE-1')->setProduct($inactive)->setWooProductId(2));

        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/bulk-resync');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/bulk-resync/run', ['_token' => $token, 'connection_id' => $connection->getId()]);
        $I->seeResponseCodeIsSuccessful();

        $runs = $I->grabService(WooCommerceSyncRunRepository::class)->findAllNewestFirst();
        $I->assertSame(1, $runs[0]->getQueuedCount(), 'only the eligible product is queued');

        $itemA = $I->grabService(WooCommercePushQueueItemRepository::class)->findOneForConnectionAndProduct($connection, $eligible);
        $I->assertNotNull($itemA);
        $itemB = $I->grabService(WooCommercePushQueueItemRepository::class)->findOneForConnectionAndProduct($connection, $inactive);
        $I->assertNull($itemB, 'an inactive product must not be queued for push');
    }

    /** No price list picked on the connection: falls back to its region's guest price list, and a "Hide" rule there is still honoured. */
    public function withNoPriceListPickedItFallsBackToTheRegionsGuestListForHiding(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $guestList = (new PriceList())->setName('Guest-' . uniqid());
        $I->haveInRepository($guestList);
        $region = (new FulfillmentRegion())->setName('West-' . uniqid())->setStatus('Active')->setGuestPriceList($guestList);
        $I->haveInRepository($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $connection = $this->connection($I);
        $connection->setDefaultWarehouse($warehouse);
        $I->haveInRepository($connection);

        $hidden = (new ProductCore())->setSku('HIDDEN-1')->setName('Hidden Product');
        $shown = (new ProductCore())->setSku('SHOWN-1')->setName('Shown Product');
        $I->haveInRepository($hidden);
        $I->haveInRepository($shown);
        $I->haveInRepository((new ProductPricing())->setProduct($hidden)->setPriceList($guestList)->setRuleType('Hide'));
        $I->haveInRepository((new WooCommerceProductMapping())->setConnection($connection)->setWooSku('HIDDEN-1')->setProduct($hidden)->setWooProductId(1));
        $I->haveInRepository((new WooCommerceProductMapping())->setConnection($connection)->setWooSku('SHOWN-1')->setProduct($shown)->setWooProductId(2));

        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/bulk-resync');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/bulk-resync/run', ['_token' => $token, 'connection_id' => $connection->getId()]);
        $I->seeResponseCodeIsSuccessful();

        $runs = $I->grabService(WooCommerceSyncRunRepository::class)->findAllNewestFirst();
        $I->assertSame(1, $runs[0]->getQueuedCount());

        $shownItem = $I->grabService(WooCommercePushQueueItemRepository::class)->findOneForConnectionAndProduct($connection, $shown);
        $I->assertNotNull($shownItem);
        $hiddenItem = $I->grabService(WooCommercePushQueueItemRepository::class)->findOneForConnectionAndProduct($connection, $hidden);
        $I->assertNull($hiddenItem, 'hidden on the region\'s guest list, which is what an unset connection price list falls back to');
    }

    /**
     * Adversarial pair: an explicit price list choice must strictly override the guest fallback,
     * not merge with it. Two products, each Hidden on exactly one of the two lists, prove both
     * directions in one run — a false positive on either would flip one assertion.
     */
    public function anExplicitPriceListChoiceOverridesTheGuestFallbackRatherThanMergingWithIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $chosenList = (new PriceList())->setName('Chosen-' . uniqid());
        $guestList = (new PriceList())->setName('Guest-' . uniqid());
        $I->haveInRepository($chosenList);
        $I->haveInRepository($guestList);
        $region = (new FulfillmentRegion())->setName('West-' . uniqid())->setStatus('Active')->setGuestPriceList($guestList);
        $I->haveInRepository($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $connection = $this->connection($I);
        $connection->setDefaultWarehouse($warehouse)->setDefaultPriceList($chosenList);
        $I->haveInRepository($connection);

        // Positive: Hidden on the CHOSEN list — must be excluded.
        $hiddenOnChosen = (new ProductCore())->setSku('HIDDEN-CHOSEN')->setName('Hidden On Chosen');
        // Negative: Hidden only on the GUEST list, which no longer applies once a list is chosen — must still sync.
        $hiddenOnGuestOnly = (new ProductCore())->setSku('HIDDEN-GUEST-ONLY')->setName('Hidden On Guest Only');
        $I->haveInRepository($hiddenOnChosen);
        $I->haveInRepository($hiddenOnGuestOnly);
        $I->haveInRepository((new ProductPricing())->setProduct($hiddenOnChosen)->setPriceList($chosenList)->setRuleType('Hide'));
        $I->haveInRepository((new ProductPricing())->setProduct($hiddenOnGuestOnly)->setPriceList($guestList)->setRuleType('Hide'));
        $I->haveInRepository((new WooCommerceProductMapping())->setConnection($connection)->setWooSku('HIDDEN-CHOSEN')->setProduct($hiddenOnChosen)->setWooProductId(1));
        $I->haveInRepository((new WooCommerceProductMapping())->setConnection($connection)->setWooSku('HIDDEN-GUEST-ONLY')->setProduct($hiddenOnGuestOnly)->setWooProductId(2));

        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/bulk-resync');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/bulk-resync/run', ['_token' => $token, 'connection_id' => $connection->getId()]);
        $I->seeResponseCodeIsSuccessful();

        $runs = $I->grabService(WooCommerceSyncRunRepository::class)->findAllNewestFirst();
        $I->assertSame(1, $runs[0]->getQueuedCount(), 'only the one NOT hidden on the chosen list should queue');

        $chosenHiddenItem = $I->grabService(WooCommercePushQueueItemRepository::class)->findOneForConnectionAndProduct($connection, $hiddenOnChosen);
        $I->assertNull($chosenHiddenItem, 'positive: the chosen list\'s own Hide rule is honoured');

        $guestHiddenItem = $I->grabService(WooCommercePushQueueItemRepository::class)->findOneForConnectionAndProduct($connection, $hiddenOnGuestOnly);
        $I->assertNotNull($guestHiddenItem, 'negative: the guest list\'s Hide rule must NOT apply once an explicit choice exists');
    }

    public function theDetailPageShowsCurrentlyFailingRows(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $connection = $this->connection($I);

        // A failing row for an UNMAPPED product: bulk resync only re-queues mapped products, so
        // this one is untouched by the run below and stays genuinely "currently failing" —
        // distinct from a row the run itself resets back to Pending to retry.
        $failingProduct = (new ProductCore())->setSku('FAIL-1')->setName('Failing Product');
        $I->haveInRepository($failingProduct);
        $failedItem = (new WooCommercePushQueueItem())->setConnection($connection)->setProduct($failingProduct)->markFailed('Store unreachable');
        $I->haveInRepository($failedItem);

        // Something for the run to actually queue, so it produces a real run row.
        $otherProduct = (new ProductCore())->setSku('OTHER-1')->setName('Other Product');
        $I->haveInRepository($otherProduct);
        $I->haveInRepository((new WooCommerceProductMapping())->setConnection($connection)->setWooSku('OTHER-1')->setProduct($otherProduct)->setWooProductId(9));

        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/bulk-resync');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/bulk-resync/run', ['_token' => $token, 'connection_id' => $connection->getId()]);

        $run = $I->grabService(WooCommerceSyncRunRepository::class)->findAllNewestFirst()[0];

        $I->amOnPage('/admin/bundles/woocommerce/bulk-resync/' . $run->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('FAIL-1');
        $I->see('Store unreachable');
        // The row the run itself queued is Pending now, not Failed, so it must not appear here.
        $I->dontSee('OTHER-1', '.data-table');
    }

    public function turningTheBundleInactiveHidesTheScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->grabService(BundleStatusRepository::class)->deactivate('WooCommerceBundle');

        $I->amOnPage('/admin/bundles/woocommerce/bulk-resync');
        $I->seeResponseCodeIs(404);
    }
}
