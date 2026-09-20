<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Relating an existing invoice to an existing sales order, through the real screens (#539 stage 5).
 *
 * InvoiceOrderLinkingTest proves the rule. What a unit test cannot prove is that the mismatches
 * reach the admin as readable sentences on the screen, that the link button is absent while they
 * stand, and that a forged POST is refused by the same rule the screen rendered.
 */
final class AdminLinkInvoiceToOrderCest
{
    private Company $company;
    private ProductCore $widget;
    private ProductCore $gadget;

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('link-invoice@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->company = (new Company())
            ->setName('Linked Invoices Wholesale')
            ->setCode('LIW-' . uniqid());
        $I->haveInRepository($this->company);

        $this->widget = (new ProductCore())
            ->setSku('LIW-WID-' . uniqid())
            ->setName('Linkable Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->widget);

        $this->gadget = (new ProductCore())
            ->setSku('LIW-GAD-' . uniqid())
            ->setName('Linkable Gadget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->gadget);
    }

    public function anUnlinkedInvoiceOffersTheLinkScreen(FunctionalTester $I): void
    {
        $invoice = $this->standaloneInvoice($I, [[$this->widget, '10.00']]);

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Not raised against an order.');
        $I->seeLink('Link to a sales order', '/admin/invoice/' . $invoice->getId() . '/link');
    }

    public function aMatchingInvoiceLinksAndDrawsTheOrderDown(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, [[$this->widget, '10.00']]);
        $invoice = $this->standaloneInvoice($I, [[$this->widget, '10.00']]);

        $I->amOnPage('/admin/invoice/' . $invoice->getId() . '/link?order_id=' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('matches a line on ' . $order->getOrderNumber());

        $I->sendFormPostRequest('/admin/invoice/' . $invoice->getId() . '/link', [
            '_token' => $I->csrfToken(),
            'order_id' => (string) $order->getId(),
        ]);

        $I->seeCurrentUrlEquals('/admin/invoice/detail/' . $invoice->getId());

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var SalesOrder $reloaded */
        $reloaded = $em->find(SalesOrder::class, $order->getId());

        $I->assertSame(SalesOrderStatus::Invoiced->value, $reloaded->getStatus());
        $I->assertSame('0.00', $reloaded->uninvoicedQuantityFor($reloaded->getLines()->first()));
        // The per-row attribution is what made the deduction possible at all.
        $I->assertNotNull($reloaded->getInvoices()->first()->getLines()->first()->getSalesOrderLine());
    }

    public function aMismatchedSkuIsNamedOnScreenAndTheLinkButtonIsAbsent(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, [[$this->widget, '10.00']]);
        $invoice = $this->standaloneInvoice($I, [[$this->widget, '6.00'], [$this->gadget, '2.00']]);

        $I->amOnPage('/admin/invoice/' . $invoice->getId() . '/link?order_id=' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        // Specific and actionable, not "lines do not match".
        $I->see('SKU ' . $this->gadget->getSku());
        $I->see('has no line with that SKU');
        $I->dontSee('Link to ' . $order->getOrderNumber());

        // And the same rule refuses a POST that skipped the screen.
        $I->sendFormPostRequest('/admin/invoice/' . $invoice->getId() . '/link', [
            '_token' => $I->csrfToken(),
            'order_id' => (string) $order->getId(),
        ]);
        $I->seeCurrentUrlEquals('/admin/invoice/' . $invoice->getId() . '/link?order_id=' . $order->getId());
        $I->see('has no line with that SKU');

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var Invoice $reloadedInvoice */
        $reloadedInvoice = $em->find(Invoice::class, $invoice->getId());
        $I->assertNull($reloadedInvoice->getSalesOrder(), 'a refused link changes nothing');
    }

    public function anOrderSkuTheInvoiceDoesNotBillIsReportedAndStillLinks(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, [[$this->widget, '10.00'], [$this->gadget, '4.00']]);
        $invoice = $this->standaloneInvoice($I, [[$this->widget, '10.00']]);

        $I->amOnPage('/admin/invoice/' . $invoice->getId() . '/link?order_id=' . $order->getId());
        $I->see('Worth knowing:');
        $I->see('bills none of it');
        // A part-invoice is the ordinary case, so this is a notice and the link is still offered.
        $I->see('Link to ' . $order->getOrderNumber());

        $I->sendFormPostRequest('/admin/invoice/' . $invoice->getId() . '/link', [
            '_token' => $I->csrfToken(),
            'order_id' => (string) $order->getId(),
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var SalesOrder $reloaded */
        $reloaded = $em->find(SalesOrder::class, $order->getId());
        $I->assertSame(SalesOrderStatus::PartiallyInvoiced->value, $reloaded->getStatus());
    }

    public function unlinkingReturnsTheQuantityToTheOrder(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, [[$this->widget, '10.00']]);
        $invoice = $this->standaloneInvoice($I, [[$this->widget, '10.00']]);

        $I->sendFormPostRequest('/admin/invoice/' . $invoice->getId() . '/link', [
            '_token' => $I->csrfToken(),
            'order_id' => (string) $order->getId(),
        ]);

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->see('Unlink from this order');

        $I->sendFormPostRequest('/admin/invoice/' . $invoice->getId() . '/unlink', ['_token' => $I->csrfToken()]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var SalesOrder $reloaded */
        $reloaded = $em->find(SalesOrder::class, $order->getId());
        $I->assertSame(SalesOrderStatus::Approved->value, $reloaded->getStatus());
        $I->assertSame('10.00', $reloaded->uninvoicedQuantityFor($reloaded->getLines()->first()));
    }

    /** @param list<array{0: ProductCore, 1: string}> $lines */
    private function approvedOrder(FunctionalTester $I, array $lines): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('LIW-SO-' . uniqid())
            ->setSubtotal('0.00')
            ->setTax('0.00')
            ->setTotal('0.00');

        $total = 0.0;
        foreach ($lines as [$product, $quantity]) {
            $order->addLine(
                (new SalesOrderLine())
                    ->setProduct($product)
                    ->setName($product->getName())
                    ->setSku($product->getSku())
                    ->setQuantity($quantity)
                    ->setPrice('5.00')
                    ->setSubtotal(number_format((float) $quantity * 5.0, 2, '.', ''))
            );
            $total += (float) $quantity * 5.0;
        }

        $order->setSubtotal(number_format($total, 2, '.', ''))->setTotal(number_format($total, 2, '.', ''));
        $I->haveInRepository($order);

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    /** @param list<array{0: ProductCore, 1: string}> $lines */
    private function standaloneInvoice(FunctionalTester $I, array $lines): Invoice
    {
        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice
            ->setDocumentNumber('LIW-INV-' . uniqid())
            ->setDocumentDate('2026-08-20')
            ->setInvoiceDate('2026-08-20');

        $total = 0.0;
        foreach ($lines as [$product, $quantity]) {
            $invoice->addLine(
                (new InvoiceLine())
                    ->setProduct($product)
                    ->setName($product->getName())
                    ->setSku($product->getSku())
                    ->setQuantity($quantity)
                    // A price the order never quoted, deliberately: prices are editable at invoice
                    // level and must not affect whether the two documents match.
                    ->setPrice('7.50')
                    ->setSubtotal(number_format((float) $quantity * 7.5, 2, '.', ''))
            );
            $total += (float) $quantity * 7.5;
        }

        $invoice->setSubtotal(number_format($total, 2, '.', ''))->setTotal(number_format($total, 2, '.', ''));
        $I->haveInRepository($invoice);

        $invoice->issue(DocumentActor::system());
        $I->grabService(EntityManagerInterface::class)->flush();

        return $invoice;
    }
}
