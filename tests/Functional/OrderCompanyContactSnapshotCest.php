<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The Order Info card's Primary Email / Company Phone write to the ORDER, never to the customer
 * (#234).
 *
 * OrderController::edit() used to do `$company->setPrimaryEmail(...)->setPhoneNumber(...)`, which
 * failed two ways and both silently:
 *
 * A POST that omitted the fields nulled the customer's email and phone COMPANY-WIDE from an order
 * edit — every other document reading through the live record lost them, and so did the customer's
 * own account.
 *
 * And correcting a typo while editing one order rewrote the customer's record, so every other
 * order — including ones already invoiced under the old details — silently changed what it printed.
 *
 * Quotes have written their own frozen identity since CompanyIdentity landed. Orders now do too.
 */
final class OrderCompanyContactSnapshotCest
{
    private const LIVE_EMAIL = 'ap-live@contact-snapshot.example';
    private const LIVE_PHONE = '+1 250 555 0100';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('order-contact-snapshot@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Contact Snapshot Co')
            ->setCode('OCS-' . uniqid())
            ->setPrimaryEmail(self::LIVE_EMAIL)
            ->setPhoneNumber(self::LIVE_PHONE);
        $I->haveInRepository($company);
        // Creating an order needs an active fulfillment region since #237 — it resolves the
        // company's price list, and a company without one cannot be priced.
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('OCS-SKU-' . uniqid())
            ->setName('Contact Snapshot Widget')
            ->setUnit('EA')
            ->setWeight('1.000')
            ->setSalesTaxCode('E')
            ->setCostPrice('30.00')
            ->setDefaultPrice('50.00')
            ->setOriginalPrice('50.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        // A non-draft order reserves stock, and since #326 a save into a reserving status is
        // refused unless the line is actually covered. Unstocked here would have driven
        // ProductInventory negative, which is the defect that check exists to stop.
        $I->haveStockFor($product);

        return $product;
    }

    private function makeOrder(FunctionalTester $I, Company $company, ProductCore $product): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('OCS-' . uniqid())
            ->setSubtotal('100.00')
            ->setTotal('100.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setQuantity('2.00')
                ->setCost('30.00')
                ->setPrice('50.00')
                ->setSubtotal('100.00')
                ->setTaxCode('E')
        );
        $I->haveInRepository($order);
        // #539 stage 2: a live order is a persisted Draft that has been approved. No invoices, so
        // the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function savePost(Company $company, ProductCore $product, array $extra): array
    {
        return [$company, array_merge([
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '50.00', 'tax_code' => 'E'],
            ],
            'save_mode' => 'draft_recalc',
        ], $extra)][1];
    }

    /**
     * The sharpest case: a save that never carried the fields at all. This used to null the
     * customer's email and phone company-wide.
     */
    public function aSaveThatOmitsTheContactFieldsDoesNotTouchTheCompany(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), array_merge(
            ['_token' => $I->csrfToken()],
            $this->savePost($company, $product, [])
        ));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $live = $entityManager->find(Company::class, $company->getId());
        $I->assertSame(self::LIVE_EMAIL, $live->getPrimaryEmail());
        $I->assertSame(self::LIVE_PHONE, $live->getPhoneNumber());
    }

    /** And a save that DOES carry them still leaves the customer's record alone. */
    public function editingTheContactFieldsWritesTheOrderNotTheCompany(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), array_merge(
            ['_token' => $I->csrfToken()],
            $this->savePost($company, $product, [
                'primary_email' => 'ap-for-this-order@contact-snapshot.example',
                'company_phone' => '+1 604 555 7788',
            ])
        ));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        // The customer is untouched.
        $live = $entityManager->find(Company::class, $company->getId());
        $I->assertSame(self::LIVE_EMAIL, $live->getPrimaryEmail());
        $I->assertSame(self::LIVE_PHONE, $live->getPhoneNumber());

        // The order carries what was typed.
        $saved = $entityManager->find(SalesOrder::class, $order->getId());
        $I->assertSame('ap-for-this-order@contact-snapshot.example', $saved->getCompanyIdentity()->getEmail());
        $I->assertSame('+1 604 555 7788', $saved->getCompanyIdentity()->getPhone());
    }

    /** One order's correction must not reach another order for the same customer. */
    public function oneOrdersCorrectionDoesNotChangeAnother(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $first = $this->makeOrder($I, $company, $product);
        $second = $this->makeOrder($I, $company, $product);

        $I->amOnPage('/admin/order/edit/' . $first->getId());
        $I->sendFormPostRequest('/admin/order/edit/' . $first->getId(), array_merge(
            ['_token' => $I->csrfToken()],
            $this->savePost($company, $product, [
                'primary_email' => 'only-the-first@contact-snapshot.example',
                'company_phone' => '+1 604 555 0001',
            ])
        ));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        $I->assertSame(
            'only-the-first@contact-snapshot.example',
            $entityManager->find(SalesOrder::class, $first->getId())->getCompanyIdentity()->getEmail()
        );
        // The second order froze the company's details when it was created and still shows them.
        $I->assertSame(
            self::LIVE_EMAIL,
            $entityManager->find(SalesOrder::class, $second->getId())->getCompanyIdentity()->getEmail()
        );
    }

    /**
     * The form must render what the ORDER holds, not what the Company holds today — otherwise an
     * admin opens the page, sees today's details, saves, and silently overwrites the ones the order
     * was placed under.
     */
    public function theEditFormShowsTheOrdersOwnContactDetails(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $stored = $entityManager->find(SalesOrder::class, $order->getId());
        $snapshot = $stored->getCompanyIdentity()->toArray();
        $snapshot['email'] = 'as-ordered@contact-snapshot.example';
        $snapshot['phone'] = '+1 250 555 9999';
        $stored->setCompanySnapshot($snapshot);
        $entityManager->flush();

        // The customer's live details move on afterwards, which is the whole point.
        $company->setPrimaryEmail('rebranded@contact-snapshot.example')->setPhoneNumber('+1 778 555 2222');
        $entityManager->flush();

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement('input[name="primary_email"]', ['value' => 'as-ordered@contact-snapshot.example']);
        $I->seeElement('input[name="company_phone"]', ['value' => '+1 250 555 9999']);
    }

    /**
     * An empty snapshot value means "never recorded", not "recorded as blank", so the live company
     * fills in — covering orders that predate the snapshot carrying these fields.
     */
    public function anEmptySnapshotFallsBackToTheLiveCompany(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $order = $this->makeOrder($I, $company, $product);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $stored = $entityManager->find(SalesOrder::class, $order->getId());
        $snapshot = $stored->getCompanyIdentity()->toArray();
        $snapshot['email'] = null;
        $snapshot['phone'] = null;
        $stored->setCompanySnapshot($snapshot);
        $entityManager->flush();

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement('input[name="primary_email"]', ['value' => self::LIVE_EMAIL]);
        $I->seeElement('input[name="company_phone"]', ['value' => self::LIVE_PHONE]);
    }

    /** Creating an order keeps what was typed into the card rather than discarding it. */
    public function creatingAnOrderStoresTheTypedContactDetailsOnTheOrder(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', array_merge(
            ['_token' => $I->csrfToken()],
            $this->savePost($company, $product, [
                'primary_email' => 'typed-at-create@contact-snapshot.example',
                'company_phone' => '+1 604 555 3333',
            ])
        ));

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        $created = $entityManager->getRepository(SalesOrder::class)->findOneBy(['company' => $company], ['id' => 'DESC']);
        $I->assertNotNull($created);
        $I->assertSame('typed-at-create@contact-snapshot.example', $created->getCompanyIdentity()->getEmail());
        $I->assertSame('+1 604 555 3333', $created->getCompanyIdentity()->getPhone());

        // Still not the customer's record.
        $live = $entityManager->find(Company::class, $company->getId());
        $I->assertSame(self::LIVE_EMAIL, $live->getPrimaryEmail());
        $I->assertSame(self::LIVE_PHONE, $live->getPhoneNumber());
    }
}
