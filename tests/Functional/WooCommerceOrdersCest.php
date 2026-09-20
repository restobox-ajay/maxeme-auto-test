<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\SalesOrder;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommerceOrderImport;

/**
 * WooCommerceBundle's "All orders" screen (#739) — the consolidated, flagged-first grid.
 */
final class WooCommerceOrdersCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('woo-orders-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function connection(FunctionalTester $I, string $name = 'Main Store'): WooCommerceConnection
    {
        $connection = (new WooCommerceConnection())
            ->setName($name)
            ->setSlug(strtolower(str_replace(' ', '-', $name)) . '-' . uniqid())
            ->setStoreUrl('https://store.example.com')
            ->setConsumerKey('ck')->setConsumerSecret('cs')->setWebhookSecret('whsec')
            ->setActive(true);
        $I->haveInRepository($connection);

        return $connection;
    }

    public function theListStartsEmpty(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/woocommerce/orders');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Orders');
        $I->see('No WooCommerce orders yet.');
    }

    public function flaggedOrdersSortFirstByDefault(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $connection = $this->connection($I);

        $I->haveInRepository((new WooCommerceOrderImport())
            ->setConnection($connection)->setWooOrderId(1001)->setWooOrderNumber('1001'));

        $I->haveInRepository((new WooCommerceOrderImport())
            ->setConnection($connection)->setWooOrderId(1002)->setWooOrderNumber('1002')
            ->markError('Could not resolve a product for SKU X'));

        $I->amOnPage('/admin/bundles/woocommerce/orders');
        $I->seeResponseCodeIsSuccessful();

        $source = $I->grabPageSource();
        $errorPosition = strpos($source, '1002');
        $importedPosition = strpos($source, '1001');
        $I->assertNotFalse($errorPosition);
        $I->assertNotFalse($importedPosition);
        $I->assertLessThan($importedPosition, $errorPosition, 'the flagged order must render before the clean one');

        $I->see('Needs attention');
        $I->see('Imported');
    }

    public function filteringByStoreNarrowsTheGrid(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $storeA = $this->connection($I, 'Store A');
        $storeB = $this->connection($I, 'Store B');

        $I->haveInRepository((new WooCommerceOrderImport())->setConnection($storeA)->setWooOrderId(2001)->setWooOrderNumber('2001'));
        $I->haveInRepository((new WooCommerceOrderImport())->setConnection($storeB)->setWooOrderId(2002)->setWooOrderNumber('2002'));

        $I->amOnPage('/admin/bundles/woocommerce/orders?filters[connection_id]=' . $storeA->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('2001');
        $I->dontSee('2002');
    }

    public function anImportedRowLinksToTheRealInvoice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $connection = $this->connection($I);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $company = (new Company())->setName('Rivera Hardware')->setAccountType('Business');
        $em->persist($company);

        $order = (new SalesOrder())->setCompany($company)->setOrderNumber('SO-TEST-' . uniqid())
            ->setSubtotal('10.00')->setTax('0.00')->setTotal('10.00');
        $em->persist($order);

        $invoice = (new Invoice())->setCompany($company)->setDocumentNumber('INV-TEST-' . uniqid())
            ->setSubtotal('10.00')->setTax('0.00')->setTotal('10.00');
        $em->persist($invoice);
        $em->flush();

        $import = (new WooCommerceOrderImport())
            ->setConnection($connection)->setWooOrderId(3001)->setWooOrderNumber('3001')
            ->markImported($company, $order, $invoice);
        $I->haveInRepository($import);

        $I->amOnPage('/admin/bundles/woocommerce/orders');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('a[href="' . $I->grabService('router')->generate('admin_invoice_detail', ['id' => $invoice->getId()]) . '"]');
    }

    public function aSkippedRowShowsWhyRatherThanAsImported(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $connection = $this->connection($I);

        $I->haveInRepository((new WooCommerceOrderImport())
            ->setConnection($connection)->setWooOrderId(4001)->setWooOrderNumber('4001')
            ->markSkipped('Woo order status is "pending" — not invoiceable yet.'));

        $I->amOnPage('/admin/bundles/woocommerce/orders');
        $I->seeResponseCodeIsSuccessful();
        $I->see('4001');
        $I->see('Skipped', 'tbody');
        $I->dontSee('Needs attention', 'tbody');
    }

    public function turningTheBundleInactiveHidesTheScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->grabService(\App\Repository\BundleStatusRepository::class)->deactivate('WooCommerceBundle');

        $I->amOnPage('/admin/bundles/woocommerce/orders');
        $I->seeResponseCodeIs(404);
    }
}
