<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommercePushQueueItem;
use WooCommerceBundle\Entity\WooCommerceProductMapping;
use WooCommerceBundle\Repository\WooCommercePushQueueItemRepository;

/**
 * WooCommerceBundle's Push Queue admin screen (#739) — a peer tab, not a drill-down, so the queue
 * is debuggable on its own. The REST push itself always fails in this suite (store.example.com is
 * not reachable), which is a real, useful assertion in its own right: a failed push must land the
 * row on Failed with a real error message, never silently vanish or stay stuck Pending forever.
 */
final class WooCommercePushQueueCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('woo-pushqueue-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function connection(FunctionalTester $I): WooCommerceConnection
    {
        $connection = (new WooCommerceConnection())
            ->setName('Main Store')->setSlug('main-store-' . uniqid())->setStoreUrl('https://store.example.com')
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

    public function theQueueStartsEmpty(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/woocommerce/push-queue');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Push Queue');
        $I->see('Nothing is queued right now.');
    }

    public function pushingOneItemMarksItFailedWithAReason(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $connection = $this->connection($I);
        $product = (new ProductCore())->setSku('WIDGET-1')->setName('Widget');
        $I->haveInRepository($product);
        $item = (new WooCommercePushQueueItem())->setConnection($connection)->setProduct($product);
        $I->haveInRepository($item);

        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/push-queue');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/push-queue/' . $item->getId() . '/push', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $refreshed = $I->grabService(WooCommercePushQueueItemRepository::class)->find($item->getId());
        $I->assertTrue($refreshed->isFailed());
        $I->assertNotNull($refreshed->getErrorMessage());

        $I->amOnPage('/admin/bundles/woocommerce/push-queue');
        $I->see('Failed');
    }

    public function pushQueueNowProcessesEveryPendingRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $connection = $this->connection($I);
        $productA = (new ProductCore())->setSku('WIDGET-A')->setName('Widget A');
        $productB = (new ProductCore())->setSku('WIDGET-B')->setName('Widget B');
        $I->haveInRepository($productA);
        $I->haveInRepository($productB);
        $I->haveInRepository((new WooCommercePushQueueItem())->setConnection($connection)->setProduct($productA));
        $I->haveInRepository((new WooCommercePushQueueItem())->setConnection($connection)->setProduct($productB));

        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/push-queue');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/push-queue/push-all', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $pending = $I->grabService(WooCommercePushQueueItemRepository::class)->findAllPending();
        $I->assertCount(0, $pending, 'push-all must leave nothing Pending, success or failure');
    }

    public function filteringByStatusNarrowsTheGrid(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $connection = $this->connection($I);
        $product = (new ProductCore())->setSku('WIDGET-C')->setName('Widget C');
        $I->haveInRepository($product);
        $item = (new WooCommercePushQueueItem())->setConnection($connection)->setProduct($product)->markPushed();
        $I->haveInRepository($item);

        $I->amOnPage('/admin/bundles/woocommerce/push-queue?filters[status]=Pending');
        $I->dontSee('WIDGET-C');

        $I->amOnPage('/admin/bundles/woocommerce/push-queue?filters[status]=Pushed');
        $I->see('WIDGET-C');
    }

    public function aPrivateProductFailsWithAnEligibilityReasonRatherThanPushing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $connection = $this->connection($I);

        $region = (new \App\Entity\FulfillmentRegion())->setName('West-' . uniqid());
        $I->haveInRepository($region);
        $warehouse = $I->grabService(\App\Service\WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');
        $connection->setDefaultWarehouse($warehouse);
        $I->haveInRepository($connection);

        $company = (new Company())->setName('Some Other Co')->setCode('SOC-' . uniqid());
        $I->haveInRepository($company);
        $product = (new ProductCore())->setSku('WIDGET-PRIVATE')->setName('Private Widget');
        $product->addPrivateCompany($company);
        $I->haveInRepository($product);
        $I->haveInRepository((new WooCommerceProductMapping())->setConnection($connection)->setWooSku('WIDGET-PRIVATE')->setProduct($product)->setWooProductId(1));
        $item = (new WooCommercePushQueueItem())->setConnection($connection)->setProduct($product);
        $I->haveInRepository($item);

        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/push-queue');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/push-queue/' . $item->getId() . '/push', ['_token' => $token]);

        $refreshed = $I->grabService(WooCommercePushQueueItemRepository::class)->find($item->getId());
        $I->assertTrue($refreshed->isFailed());
        $I->assertStringContainsString('not eligible for sync', (string) $refreshed->getErrorMessage());
    }

    public function turningTheBundleInactiveHidesTheScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->grabService(BundleStatusRepository::class)->deactivate('WooCommerceBundle');

        $I->amOnPage('/admin/bundles/woocommerce/push-queue');
        $I->seeResponseCodeIs(404);
    }
}
