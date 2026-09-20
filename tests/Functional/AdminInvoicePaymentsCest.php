<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoicePaymentStatus;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The admin payments screen, which moved from the order to the invoice in #539 stage 4, and the
 * Orders grid's rolled-up payment column.
 *
 * Driven through the real pages rather than the entities: what these cover is that recording a
 * payment on the invoice reaches the grid — a screen, a derivation and an aggregate query away —
 * without anything storing an order-level answer in between.
 */
final class AdminInvoicePaymentsCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-invoice-payments-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    public function recordingAPaymentDerivesTheInvoiceAndClosesTheOrder(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$order, $invoice] = $this->invoicedOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/invoice/' . $invoice->getId() . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Not Paid');

        $this->recordPayment($I, $invoice, 'Bank Transfer', '100.00', 'wire from the buyer');

        $invoice = $this->reloadInvoice($I, $invoice);
        $order = $this->reloadOrder($I, $order);

        $I->assertSame(InvoicePaymentStatus::Paid, $invoice->getPaymentStatus());
        $I->assertSame('0.00', $invoice->getBalance());
        // The order was not written by the payments screen at all: its status is derived from its
        // invoice set, and the invoice's from its payment rows.
        $I->assertSame(SalesOrderStatus::Closed->value, $order->getStatus());
    }

    public function aPartPaymentReadsAsPartiallyPaidOnBothDocuments(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$order, $invoice] = $this->invoicedOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $this->recordPayment($I, $invoice, 'Cheque', '40.00', '');

        $invoice = $this->reloadInvoice($I, $invoice);
        $order = $this->reloadOrder($I, $order);

        $I->assertSame(InvoicePaymentStatus::PartiallyPaid, $invoice->getPaymentStatus());
        $I->assertSame('60.00', $invoice->getBalance());
        $I->assertSame(SalesOrderStatus::Invoiced->value, $order->getStatus());

        // And the grid says so, rolled up from the invoice rather than from a column on the order.
        $I->amOnPage('/admin/order?filters[orderNumber]=' . $order->getOrderNumber());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Partially Paid');
    }

    public function thePaymentIsOnTheInvoicesTimeline(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $this->recordPayment($I, $invoice, 'Cash', '25.00', 'over the counter');

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Payment of $25.00 via Cash recorded. Comment: over the counter');
        $I->see('Payment status changed from Not Paid to Partially Paid.');
    }

    /**
     * An invoice with money against it cannot be cancelled (client decision, #539 stage 4): it is a
     * real accounting record, credited or refunded rather than withdrawn.
     */
    public function anInvoiceWithAPaymentAgainstItRefusesToCancel(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $invoice] = $this->invoicedOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $this->recordPayment($I, $invoice, 'Cash', '25.00', '');

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/invoice/' . $invoice->getId() . '/action/cancel', ['_token' => $token]);

        // The redirect is followed, so this is the invoice page rendering the refusal — reported to
        // the admin rather than swallowed.
        $I->see('cannot be cancelled');

        $I->assertFalse(
            $this->reloadInvoice($I, $invoice)->isCancelled(),
            'An invoice with money against it must survive a Cancel.',
        );
    }

    public function theOrdersGridPaymentColumnFiltersOnTheRollup(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [, $paidInvoice] = $this->invoicedOrder($I);
        [$unpaidOrder] = $this->invoicedOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $this->recordPayment($I, $paidInvoice, 'Bank Transfer', '100.00', '');

        $paidOrder = $paidInvoice->getSalesOrder();

        $I->amOnPage('/admin/order?filters[paymentStatus]=Paid');
        $I->seeResponseCodeIsSuccessful();
        $I->see($paidOrder->getOrderNumber());
        $I->dontSee($unpaidOrder->getOrderNumber());

        $I->amOnPage('/admin/order?filters[paymentStatus]=Not+Paid');
        $I->seeResponseCodeIsSuccessful();
        $I->see($unpaidOrder->getOrderNumber());
        $I->dontSee($paidOrder->getOrderNumber());
    }

    /**
     * The document as the DATABASE now holds it.
     *
     * find() rather than refresh(): the request under test wrote through its own entity manager and
     * this one has been cleared since, so the fixture object is detached and refresh() refuses it.
     */
    private function reloadInvoice(FunctionalTester $I, Invoice $invoice): Invoice
    {
        return $I->grabService(EntityManagerInterface::class)
            ->getRepository(Invoice::class)
            ->find($invoice->getId());
    }

    private function reloadOrder(FunctionalTester $I, SalesOrder $order): SalesOrder
    {
        return $I->grabService(EntityManagerInterface::class)
            ->getRepository(SalesOrder::class)
            ->find($order->getId());
    }

    /**
     * Records a payment through the real screen.
     *
     * A relative-path POST rather than submitForm(): with a custom Host header the browser module
     * resolves the crawled form action as an absolute URL and trips its external-URL guard, which
     * AdminCompanyInfoRegionCest ran into first. The token is scraped from the page, so this still
     * goes through the app's CSRF check rather than around it.
     */
    private function recordPayment(FunctionalTester $I, Invoice $invoice, string $method, string $amount, string $comment): void
    {
        $I->amOnPage('/admin/invoice/' . $invoice->getId() . '/payments');
        $I->seeResponseCodeIsSuccessful();
        // Scoped to the form, which is now possible: #539 stage 6 moved the <form> out of the <tr>
        // it used to wrap the <td>s of and wired the cells to it by the HTML5 `form` attribute. The
        // old markup was invalid, so DomCrawler foster-parented the form away from its own inputs
        // and '#document-payment-form input' matched nothing — this selector passing is the proof the markup
        // parses as written.
        $token = $I->grabAttributeFrom('#document-payment-form input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/invoice/' . $invoice->getId() . '/payments', [
            '_token' => $token,
            'payment_id' => '',
            'received_at' => '2026-08-20',
            'method' => $method,
            'amount' => $amount,
            'comment' => $comment,
        ]);
    }

    /**
     * An approved order with one issued invoice for its whole value.
     *
     * @return array{0: SalesOrder, 1: Invoice}
     */
    private function invoicedOrder(FunctionalTester $I): array
    {
        $company = (new Company())
            ->setName('Payments Test Co')
            ->setCode('PAY-' . uniqid())
            ->setPrimaryEmail('buyer@payments.example');
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('PAYSO-' . uniqid())
            ->setDocumentDate('2026-08-20')
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');
        $order->addLine(
            (new SalesOrderLine())->setName('Widget')->setQuantity('4.00')->setPrice('25.00')->setSubtotal('100.00'),
        );
        $I->haveInRepository($order);

        $em = $I->grabService(EntityManagerInterface::class);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('PAYINV-' . uniqid())
            ->setDocumentDate('2026-08-20')
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($order->getLines()->first())
                ->setName('Widget')
                ->setQuantity('4.00')
                ->setPrice('25.00')
                ->setSubtotal('100.00'),
        );
        $em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $em->flush();

        return [$order, $invoice];
    }
}
