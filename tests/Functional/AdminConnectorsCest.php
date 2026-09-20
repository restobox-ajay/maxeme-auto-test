<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Repository\BundleStatusRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers Admin/ConnectorController — the /admin/connectors directory (#741). WooCommerceBundle
 * (#741) is now a real, Active-by-default-in-tests connector type, so the empty-directory case is
 * exercised by explicitly deactivating it rather than by there being nothing registered at all.
 */
final class AdminConnectorsCest
{
    private function login(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-connectors-functional-test@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    public function theDirectoryRendersEmptyWithNoConnectorBundleActive(FunctionalTester $I): void
    {
        $this->login($I);
        $I->grabService(BundleStatusRepository::class)->deactivate('WooCommerceBundle');

        $I->amOnPage('/admin/connectors');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Connectors');
        $I->see('No connector types are registered and Active yet.');
        $I->dontSeeElement('table.data-table tbody tr td strong');
    }

    public function woocommerceAppearsOnceActiveWithNoConnectionsYet(FunctionalTester $I): void
    {
        $this->login($I);

        $I->amOnPage('/admin/connectors');
        $I->seeResponseCodeIsSuccessful();

        $I->see('WooCommerce');
        $I->see('No connections yet');
    }
}
