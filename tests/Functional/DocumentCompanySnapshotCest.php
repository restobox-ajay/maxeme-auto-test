<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\ProductCore;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Renames the customer after the order exists and then loads every document that prints their name,
 * over real HTTP.
 *
 * The entity-level cover is tests/Entity/CompanyIdentitySnapshotTest.php. This exists because the
 * address snapshot taught the lesson the hard way: removing the company fallback from PHP left two
 * templates re-implementing it in Twig (`order.billingAddress ?: order.company.defaultBillingAddress`),
 * so the bug survived a green unit suite. Only rendering the real page catches that.
 */
final class DocumentCompanySnapshotCest
{
    private const OLD_NAME = 'Acme Corp Snapshot Test';
    private const NEW_NAME = 'Globex Incorporated Snapshot Test';
    private const OLD_EMAIL = 'ap-old@acme-snapshot.example';
    private const NEW_EMAIL = 'ap-new@globex-snapshot.example';
    private const OLD_PHONE = '+1 604 555 0101';
    private const NEW_PHONE = '+1 778 555 9999';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('doc-company-snapshot@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    /**
     * The invoice raised from that order, which since #539 stage 6 is what the invoice document and
     * the packing slip render.
     *
     * Raised BEFORE the rename, exactly as the order is: setCompany() freezes the identity snapshot
     * at the moment it is called, and an invoice raised after the rebrand would legitimately carry
     * the new name. What is being tested is that a document raised before it keeps the old one.
     */
    private function invoiceWhoseCustomerWasRenamed(FunctionalTester $I): Invoice
    {
        return $this->orderAndInvoiceWhoseCustomerWasRenamed($I)[1];
    }

    /** Creates the order, then renames the company out from under it. */
    private function orderWhoseCustomerWasRenamed(FunctionalTester $I): SalesOrder
    {
        return $this->orderAndInvoiceWhoseCustomerWasRenamed($I)[0];
    }

    /** @return array{0: SalesOrder, 1: Invoice} */
    private function orderAndInvoiceWhoseCustomerWasRenamed(FunctionalTester $I): array
    {
        $company = (new Company())
            ->setName(self::OLD_NAME)
            ->setCode('SNAPCO-' . uniqid())
            ->setPrimaryEmail(self::OLD_EMAIL)
            ->setPhoneNumber(self::OLD_PHONE);
        $I->haveInRepository($company);

        $address = (new CompanyAddress())
            ->setCompany($company)
            ->setLabel('Main')
            ->setFirstName('Ada')
            ->setLastName('Lovelace')
            ->setCompanyName('Acme Receiving')
            ->setAddressLine1('1 Dock Road')
            ->setCity('Vancouver')
            ->setProvince('BC')
            ->setCountry('CA')
            ->setPostalCode('V5K0A1');
        $I->haveInRepository($address);

        $product = (new ProductCore())->setSku('SNAPCO-SKU-1')->setName('Snapshot Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('SNAPCO-' . uniqid())
            ->setSubtotal('20.00')
            ->setTax('0.00')
            ->setTotal('20.00');
        $order->setBillingAddressFrom($address);
        $order->setShippingAddressFrom($address);
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName('Snapshot Test Product')
                ->setSku('SNAPCO-SKU-1')
                ->setQuantity('1')
                ->setPrice('20.00')
                ->setSubtotal('20.00')
        );
        $I->haveInRepository($order);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        // A live order is an approved one since #539 stage 2, and approving is an action on the
        // persisted Draft rather than a status assignment. Flushed together with the rename below.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('SNAPCO-INV-' . uniqid())
            ->setDocumentDate('2026-08-20')
            ->setSubtotal('20.00')
            ->setTax('0.00')
            ->setTotal('20.00');
        $order->addInvoice($invoice);
        $invoice->setBillingAddressFrom($address);
        $invoice->setShippingAddressFrom($address);
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($order->getLines()->first())
                ->setName('Snapshot Test Product')
                ->setSku('SNAPCO-SKU-1')
                ->setQuantity('1')
                ->setPrice('20.00')
                ->setSubtotal('20.00'),
        );
        $entityManager->persist($invoice);
        $invoice->issue(DocumentActor::system());

        // The rebrand happens after both documents exist, which is the whole point.
        $company->setName(self::NEW_NAME)->setPrimaryEmail(self::NEW_EMAIL)->setPhoneNumber(self::NEW_PHONE);
        $entityManager->flush();

        return [$order, $invoice];
    }

    private function assertDocumentStillNamesTheOldCustomer(FunctionalTester $I, string $url): void
    {
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        $I->see(self::OLD_NAME);
        $I->dontSee(self::NEW_NAME);
    }

    public function theSalesOrderDetailPageNamesTheCustomerAsTheyWereBilled(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->orderWhoseCustomerWasRenamed($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see(self::OLD_NAME);
        $I->dontSee(self::NEW_NAME);

        // Detail also prints the company's email and phone, which drift the same way.
        $I->see(self::OLD_EMAIL);
        $I->dontSee(self::NEW_EMAIL);
        $I->see(self::OLD_PHONE);
        $I->dontSee(self::NEW_PHONE);

        // The link to the account is still the live one — an id cannot drift, and the admin wants
        // today's record.
        $I->seeElement('a[href*="/admin/company/"]');
    }

    /**
     * The invoice does not print the buyer's company name anywhere — Bill To / Ship To show the
     * address snapshot's own companyName. What it does read from the company is the contact phone and
     * email, as the fallback when the chosen address recorded none, and those drift identically.
     */
    public function theInvoiceShowsTheContactDetailsRecordedAtOrderTime(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $invoice = $this->invoiceWhoseCustomerWasRenamed($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->see(self::OLD_PHONE);
        $I->see(self::OLD_EMAIL);
        $I->dontSee(self::NEW_PHONE);
        $I->dontSee(self::NEW_EMAIL);
    }

    public function thePackingSlipNamesTheCustomerAsTheyWereBilled(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $invoice = $this->invoiceWhoseCustomerWasRenamed($I);

        $this->assertDocumentStillNamesTheOldCustomer($I, '/admin/invoice/packing-slip/' . $invoice->getId());
    }

    /** The order's own document drifts no more than the invoice's does. */
    public function theSalesOrderDocumentNamesTheCustomerAsTheyWereBilled(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->orderWhoseCustomerWasRenamed($I);

        $this->assertDocumentStillNamesTheOldCustomer($I, '/admin/order/document/' . $order->getId());
    }
}
