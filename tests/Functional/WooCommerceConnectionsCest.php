<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Repository\BundleStatusRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use WooCommerceBundle\Repository\WooCommerceConnectionRepository;

/**
 * WooCommerceBundle's connections screen (#739/#741), end to end through the real kernel. The
 * bundle is Active in every functional run (tests/_bootstrap.php runs app:bundle:activate
 * --all-present), so no per-test activation call is needed — only the deactivation test below
 * switches it off, on purpose.
 */
final class WooCommerceConnectionsCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('woo-connections-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function connections(FunctionalTester $I): WooCommerceConnectionRepository
    {
        return $I->grabService(WooCommerceConnectionRepository::class);
    }

    private function tokenOn(FunctionalTester $I, string $url): string
    {
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();

        return (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    public function theListStartsEmpty(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/woocommerce/connections');
        $I->seeResponseCodeIsSuccessful();
        $I->see('WooCommerce');
        $I->see('No WooCommerce connections yet.');
    }

    public function savingANewConnectionStoresItWithAllFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/connections/new');

        $I->sendFormPostRequest('/admin/bundles/woocommerce/connections/save', [
            '_token' => $token,
            'id' => '0',
            'name' => 'Main Store',
            'slug' => 'main-store',
            'store_url' => 'https://store.example.com',
            'consumer_key' => 'ck_abc123',
            'consumer_secret' => 'cs_secret123',
            'webhook_secret' => 'whsec_xyz',
            'active' => '1',
            'default_warehouse_id' => '0',
            'default_price_list_id' => '0',
            'availability_buffer' => '5',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $rows = $this->connections($I)->findAllOrderedByName();
        $I->assertCount(1, $rows);
        $I->assertSame('Main Store', $rows[0]->getName());
        $I->assertSame('main-store', $rows[0]->getSlug());
        $I->assertSame('https://store.example.com', $rows[0]->getStoreUrl());
        $I->assertSame('ck_abc123', $rows[0]->getConsumerKey());
        $I->assertSame('cs_secret123', $rows[0]->getConsumerSecret());
        $I->assertSame('whsec_xyz', $rows[0]->getWebhookSecret());
        $I->assertTrue($rows[0]->isActive());
        $I->assertSame(5, $rows[0]->getAvailabilityBuffer());
    }

    /** The password-shaped secret field is never echoed back, so a blank post must not blank it out. */
    public function editingWithABlankSecretKeepsTheCurrentOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $newToken = $this->tokenOn($I, '/admin/bundles/woocommerce/connections/new');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/connections/save', [
            '_token' => $newToken,
            'id' => '0',
            'name' => 'Trade Portal',
            'slug' => 'trade-portal',
            'store_url' => 'https://trade.example.com',
            'consumer_key' => 'ck_1',
            'consumer_secret' => 'cs_original',
            'webhook_secret' => 'whsec_1',
        ]);

        $connection = $this->connections($I)->findAllOrderedByName()[0];

        $editToken = $this->tokenOn($I, '/admin/bundles/woocommerce/connections/' . $connection->getId() . '/edit');
        $I->dontSeeElement('input[name="consumer_secret"][value]');

        $I->sendFormPostRequest('/admin/bundles/woocommerce/connections/save', [
            '_token' => $editToken,
            'id' => (string) $connection->getId(),
            'name' => 'Trade Portal',
            'slug' => 'trade-portal',
            'store_url' => 'https://trade.example.com',
            'consumer_key' => 'ck_1',
            'consumer_secret' => '',
            'webhook_secret' => 'whsec_1',
        ]);

        $I->assertSame('cs_original', $this->connections($I)->findAllOrderedByName()[0]->getConsumerSecret());
    }

    /** A store admins want offline stays in the list — the switch just says so, plainly. */
    public function togglingActiveOffIsVisibleOnBothScreens(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/connections/new');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/connections/save', [
            '_token' => $token,
            'id' => '0',
            'name' => 'Clearance Outlet',
            'slug' => 'clearance-outlet',
            'store_url' => 'https://clearance.example.com',
            'consumer_key' => 'ck_2',
            'consumer_secret' => 'cs_2',
            'webhook_secret' => 'whsec_2',
            // 'active' omitted — an unchecked checkbox sends no field at all.
        ]);

        $connection = $this->connections($I)->findAllOrderedByName()[0];
        $I->assertFalse($connection->isActive());

        $I->amOnPage('/admin/bundles/woocommerce/connections');
        $I->see('Off');

        $I->amOnPage('/admin/bundles/woocommerce/connections/' . $connection->getId() . '/edit');
        $I->dontSeeCheckboxIsChecked('input[name="active"]');
    }

    /** The Active/Inactive kill switch: the screens read as absent, and every row stays put. */
    public function turningTheBundleInactiveHidesItsScreensWithoutLosingARow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $token = $this->tokenOn($I, '/admin/bundles/woocommerce/connections/new');
        $I->sendFormPostRequest('/admin/bundles/woocommerce/connections/save', [
            '_token' => $token,
            'id' => '0',
            'name' => 'Main Store',
            'slug' => 'main-store',
            'store_url' => 'https://store.example.com',
            'consumer_key' => 'ck_1',
            'consumer_secret' => 'cs_1',
            'webhook_secret' => 'whsec_1',
        ]);
        $I->assertCount(1, $this->connections($I)->findAllOrderedByName());

        $I->grabService(BundleStatusRepository::class)->deactivate('WooCommerceBundle');

        $I->amOnPage('/admin/bundles/woocommerce/connections');
        $I->seeResponseCodeIs(404);

        $I->assertCount(1, $this->connections($I)->findAllOrderedByName(), 'switching the bundle off must not delete anything');
    }
}
