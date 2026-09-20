<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePayment;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The Invoices grid (#539 stage 6).
 *
 * Its reason to exist is the pair of statuses: InvoiceStatus answers "have the goods gone" and
 * InvoicePaymentStatus answers "has the money arrived", and an invoice can be Completed and Not Paid
 * without either being wrong. So the tests here are about the two columns being independently
 * readable and independently filterable, not about grid chrome.
 *
 * Deliberately unlike the Orders grid's payment column, which OrderPaymentRollup aggregates from
 * these very rows: one invoice's payment status is a column on the invoice, so filtering it is an
 * ordinary WHERE and there is no rollup to go wrong.
 */
final class AdminInvoiceGridCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-invoice-grid@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    public function theGridListsAnInvoiceWithBothItsStatusesAndItsOrder(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$order, $invoice] = $this->invoicedOrder($I, 'GRIDA');

        $I->amOnPage('/admin/invoice');
        $I->seeResponseCodeIsSuccessful();
        $I->see($invoice->getDocumentNumber());
        // The cross-link, from this side: the grid names the order each invoice bills.
        $I->see($order->getOrderNumber());
        // Scoped to the body: both status words also appear in the filter row's <select> options,
        // so an unscoped see() would pass on a grid that listed nothing at all.
        $I->see('Pending', 'tbody');
        $I->see('Not Paid', 'tbody');
    }

    /**
     * Fulfilment and payment move independently, and the grid has to show that rather than deriving
     * one from the other. A completed invoice nobody has paid is an ordinary, important row.
     */
    public function fulfilmentAndPaymentAreReportedSeparately(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $unpaidButShipped] = $this->invoicedOrder($I, 'GRIDB');
        [, $paidButPending] = $this->invoicedOrder($I, 'GRIDC');

        $em = $I->grabService(EntityManagerInterface::class);
        $unpaidButShipped->startProcessing(DocumentActor::system());
        $unpaidButShipped->setStatus('Completed', DocumentActor::system());

        $payment = (new InvoicePayment())->setMethod('Bank Transfer')->setAmount($paidButPending->getTotal());
        $paidButPending->recordPayment(DocumentActor::system(), $payment);
        $em->persist($payment);
        $em->flush();

        // Completed goods, no money: on the fulfilment filter, absent from the paid one.
        $I->amOnPage('/admin/invoice?filters[status]=Completed');
        $I->seeResponseCodeIsSuccessful();
        $I->see($unpaidButShipped->getDocumentNumber());
        $I->dontSee($paidButPending->getDocumentNumber());

        // Money in, goods not gone: the mirror image, from the other column.
        $I->amOnPage('/admin/invoice?filters[paymentStatus]=Paid');
        $I->seeResponseCodeIsSuccessful();
        $I->see($paidButPending->getDocumentNumber());
        $I->dontSee($unpaidButShipped->getDocumentNumber());
    }

    public function theGridFiltersByInvoiceNumberAndByTheOrderItBills(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$mineOrder, $mine] = $this->invoicedOrder($I, 'GRIDD');
        [, $theirs] = $this->invoicedOrder($I, 'GRIDE');

        $I->amOnPage('/admin/invoice?filters[documentNumber]=' . urlencode($mine->getDocumentNumber()));
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber());
        $I->dontSee($theirs->getDocumentNumber());

        $I->amOnPage('/admin/invoice?filters[orderNumber]=' . urlencode($mineOrder->getOrderNumber()));
        $I->seeResponseCodeIsSuccessful();
        $I->see($mine->getDocumentNumber());
        $I->dontSee($theirs->getDocumentNumber());
    }

    /** The grid is reachable without knowing the URL — it has a sidebar entry under Orders. */
    public function theSidebarLinksToTheInvoicesGrid(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/order');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#primary-navigation a[href="/admin/invoice"]');
    }

    /**
     * An approved order with one issued invoice for its whole value.
     *
     * @return array{0: SalesOrder, 1: Invoice}
     */
    private function invoicedOrder(FunctionalTester $I, string $prefix): array
    {
        $company = (new Company())
            ->setName('Invoice Grid Co')
            ->setCode($prefix . '-' . uniqid())
            ->setPrimaryEmail('buyer@invoice-grid.example');
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($prefix . 'SO-' . uniqid())
            ->setDocumentDate('2026-08-20')
            ->setSubtotal('50.00')
            ->setTax('0.00')
            ->setTotal('50.00');
        $order->addLine(
            (new SalesOrderLine())->setName('Grid Widget')->setQuantity('2.00')->setPrice('25.00')->setSubtotal('50.00'),
        );
        $I->haveInRepository($order);

        $em = $I->grabService(EntityManagerInterface::class);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber($prefix . 'INV-' . uniqid())
            ->setDocumentDate('2026-08-20')
            ->setInvoiceDate('2026-08-20')
            ->setSubtotal('50.00')
            ->setTax('0.00')
            ->setTotal('50.00');
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($order->getLines()->first())
                ->setName('Grid Widget')
                ->setQuantity('2.00')
                ->setPrice('25.00')
                ->setSubtotal('50.00'),
        );
        $em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $em->flush();

        return [$order, $invoice];
    }
}
