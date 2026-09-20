<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommerceProductMapping;
use WooCommerceBundle\Repository\WooCommerceProductMappingRepository;

/** WooCommerceBundle's reconciliation queue (#739) — confirming or merging auto-created products. */
final class WooCommerceReconciliationCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('woo-reconcile-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function connection(FunctionalTester $I): WooCommerceConnection
    {
        $connection = (new WooCommerceConnection())
            ->setName('Main Store')->setSlug('main-store-' . uniqid())->setStoreUrl('https://x.example.com')
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

        $I->amOnPage('/admin/bundles/woocommerce/reconciliation');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Reconciliation');
        $I->see('Nothing needs reconciliation right now.');
    }

    public function keepingAFlaggedMappingClearsTheFlag(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $connection = $this->connection($I);
        $product = (new ProductCore())->setSku('AUTO-1')->setName('Auto Created Widget');
        $I->haveInRepository($product);
        $mapping = (new WooCommerceProductMapping())
            ->setConnection($connection)->setWooSku('AUTO-1')->setProduct($product)->setFlagged(true);
        $I->haveInRepository($mapping);

        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/reconciliation');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/reconciliation/' . $mapping->getId() . '/keep', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $refreshed = $I->grabService(WooCommerceProductMappingRepository::class)->find($mapping->getId());
        $I->assertFalse($refreshed->isFlagged());

        $I->amOnPage('/admin/bundles/woocommerce/reconciliation');
        $I->see('Nothing needs reconciliation right now.');
    }

    public function mergingRepointsTheMappingAndDeactivatesTheOldProduct(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $connection = $this->connection($I);
        $autoCreated = (new ProductCore())->setSku('AUTO-2')->setName('Auto Created Gadget');
        $realProduct = (new ProductCore())->setSku('REAL-GADGET')->setName('Real Gadget');
        $I->haveInRepository($autoCreated);
        $I->haveInRepository($realProduct);
        $mapping = (new WooCommerceProductMapping())
            ->setConnection($connection)->setWooSku('AUTO-2')->setProduct($autoCreated)->setFlagged(true);
        $I->haveInRepository($mapping);

        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/reconciliation');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/reconciliation/' . $mapping->getId() . '/merge', [
            '_token' => $token,
            'sku' => 'REAL-GADGET',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $refreshedMapping = $I->grabService(WooCommerceProductMappingRepository::class)->find($mapping->getId());
        $I->assertFalse($refreshedMapping->isFlagged());
        $I->assertSame($realProduct->getId(), $refreshedMapping->getProduct()->getId());

        $refreshedAutoCreated = $I->grabService('doctrine.orm.entity_manager')->find(ProductCore::class, $autoCreated->getId());
        $I->assertSame('Inactive', $refreshedAutoCreated->getStatus());
    }

    public function mergingIntoAnUnknownSkuChangesNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $connection = $this->connection($I);
        $product = (new ProductCore())->setSku('AUTO-3')->setName('Auto Created Thing');
        $I->haveInRepository($product);
        $mapping = (new WooCommerceProductMapping())
            ->setConnection($connection)->setWooSku('AUTO-3')->setProduct($product)->setFlagged(true);
        $I->haveInRepository($mapping);

        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/reconciliation');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/reconciliation/' . $mapping->getId() . '/merge', [
            '_token' => $token,
            'sku' => 'DOES-NOT-EXIST',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $refreshed = $I->grabService(WooCommerceProductMappingRepository::class)->find($mapping->getId());
        $I->assertTrue($refreshed->isFlagged(), 'a merge into an unknown SKU must not clear the flag');
        $I->assertSame($product->getId(), $refreshed->getProduct()->getId());
    }

    public function turningTheBundleInactiveHidesTheScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->grabService(\App\Repository\BundleStatusRepository::class)->deactivate('WooCommerceBundle');

        $I->amOnPage('/admin/bundles/woocommerce/reconciliation');
        $I->seeResponseCodeIs(404);
    }
}
