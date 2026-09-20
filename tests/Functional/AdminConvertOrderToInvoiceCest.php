<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Convert to Invoice, through the real screens (#539 stage 2).
 *
 * OrderInvoiceConversionTest proves what the service does with a set of quantities; what a unit test
 * cannot prove is that the screen offers the right ones, that the two documents link to each other,
 * and that a refusal reaches the admin as a flash rather than a 500.
 *
 * @group bundle-agnostic
 *
 * Tagged for the bundles-off run (#562): these assertions must hold identically with the
 * optional inventory bundles Inactive. If a change here can only pass with them Active, the
 * change has leaked out of its bundle.
 */
final class AdminConvertOrderToInvoiceCest
{
    private Company $company;
    private ProductCore $product;

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('convert-to-invoice@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->company = (new Company())
            ->setName('Part Invoiced Wholesale')
            ->setCode('PIW-' . uniqid());
        $I->haveInRepository($this->company);

        $this->product = (new ProductCore())
            ->setSku('PIW-SKU-' . uniqid())
            ->setName('Part Invoiced Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->product);
    }

    public function theOrderPageListsItsInvoicesAndOffersTheConversion(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, '10.00');

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Invoices');
        $I->see('Convert to Invoice');
        $I->seeLink('Convert to Invoice', '/admin/invoice/create?order_id=' . $order->getId());
        // The control lives in the top action bar (#650), not the Invoices table header any more —
        // one button doing this, not two.
        $I->seeElement('nav.order-tab-nav a#order-action-convert-invoice[href="/admin/invoice/create?order_id=' . $order->getId() . '"]');
    }

    public function aDraftOrderIsNotOfferedTheConversion(FunctionalTester $I): void
    {
        $order = $this->draftOrder($I, '10.00');

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        // Nothing may be invoiced against an order nobody has accepted — the service refuses it, and
        // the page does not offer a button that would only be turned away.
        $I->dontSee('Convert to Invoice');

        $I->amOnPage('/admin/invoice/create?order_id=' . $order->getId());
        $I->seeCurrentUrlEquals('/admin/order/detail/' . $order->getId());
        $I->see('Approve it before invoicing against it');
    }

    public function theScreenIsPrefilledWithTheUninvoicedQuantity(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, '10.00');
        $this->invoiceSomeOf($I, $order, '4.00');

        $I->amOnPage('/admin/invoice/create?order_id=' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Part Invoiced Widget');
        // 10 ordered, 4 already billed: what is left is what the form offers. Trimmed, matching
        // the Ordered column's own untrimmed "10" — a mismatched "6.00" beside it is the defect
        // 53b3e08c fixed.
        $I->seeInField('lines[0][qty]', '6');
    }

    public function raisingAPartInvoiceLeavesTheOrderPartiallyInvoiced(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, '10.00');
        $line = $order->getLines()->first();

        $I->amOnPage('/admin/invoice/create?order_id=' . $order->getId());
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [['product_id' => (string) $this->product->getId(), 'sales_order_line_id' => (string) $line->getId(), 'qty' => '4.00', 'price' => '5.00']],
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var SalesOrder $reloaded */
        $reloaded = $em->find(SalesOrder::class, $order->getId());

        $I->assertSame(SalesOrderStatus::PartiallyInvoiced->value, $reloaded->getStatus());
        $I->assertSame('6.00', $reloaded->uninvoicedQuantityFor($reloaded->getLines()->first()));

        $raised = null;
        foreach ($reloaded->getInvoices() as $invoice) {
            if ($invoice->getStatus() === 'Pending') {
                $raised = $invoice;
            }
        }

        $I->assertNotNull($raised, 'the conversion issues the invoice it raises');
        // Compared numerically: SQLite hands a decimal column back without its trailing zeros, so the
        // string shape here is the driver's business rather than the application's.
        $I->assertSame(4.0, (float) $raised->getLines()->first()->getQuantity());
    }

    public function aQuantityAboveTheRemainderIsRefusedWithAFlash(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, '10.00');
        $line = $order->getLines()->first();

        $I->amOnPage('/admin/invoice/create?order_id=' . $order->getId());
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [['product_id' => (string) $this->product->getId(), 'sales_order_line_id' => (string) $line->getId(), 'qty' => '11.00', 'price' => '5.00']],
        ]);

        // Not a re-render of the create screen: the invoice already exists as a Draft by this
        // point (its number is drawn before issue() is even attempted), so the refusal lands the
        // admin on THAT invoice's own edit screen — the same place a refused edit()-issue does —
        // rather than discarding what was typed and sending them back to start over.
        $I->seeResponseCodeIsSuccessful();
        $I->see('only 10 left to invoice');

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var SalesOrder $reloaded */
        $reloaded = $em->find(SalesOrder::class, $order->getId());
        // The save itself is not refused any more (#full-parity) — only OverInvoicingGuard's
        // issue-time check is. The line saves as a Draft the admin can still correct, and the
        // order's own status reflects that: nothing counts against it until it is issued.
        $I->assertSame(SalesOrderStatus::Approved->value, $reloaded->getStatus(), 'a draft invoice counts against nothing');
        $I->assertCount(1, $reloaded->getInvoices());
        $raised = $reloaded->getInvoices()->first();
        $I->assertSame('Draft', $raised->getStatus());
        $I->seeCurrentUrlEquals('/admin/invoice/edit/' . $raised->getId());
        $I->assertSame('10.00', $reloaded->uninvoicedQuantityFor($reloaded->getLines()->first()));
    }

    public function theScreenOffersTheOrdersChargesAsQuantifiedRows(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, '10.00');
        $order->setFeeLines(json_encode([
            ['slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'G', 'amount' => 10.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc'],
        ]));
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->amOnPage('/admin/invoice/create?order_id=' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Charges to invoice');
        $I->see('Shipping (Ground)');
        // A flat charge is one whole unit, and the whole of it is what is left to bill.
        $I->seeInField('charges[0][quantity]', '1.00');
        $I->seeInField('charges[0][amount]', '10.00');

        $line = $order->getLines()->first();
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [['product_id' => (string) $this->product->getId(), 'sales_order_line_id' => (string) $line->getId(), 'qty' => '4.00', 'price' => '5.00']],
            'charges' => [['slug' => 'shipping', 'quantity' => '0.40', 'amount' => '4.00']],
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var SalesOrder $reloaded */
        $reloaded = $em->find(SalesOrder::class, $order->getId());

        $I->assertSame('0.60', $reloaded->uninvoicedChargeQuantityFor('shipping'));
        // Numerically, for the reason the line-quantity assertion above is numeric: SQLite hands a
        // decimal column back without its trailing zeros.
        $I->assertSame(24.0, (float) $reloaded->getInvoices()->first()->getTotal());
    }

    public function theInvoicePageNamesTheOrderItBills(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, '10.00');
        $invoice = $this->invoiceSomeOf($I, $order, '10.00');

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($invoice->getDocumentNumber());
        $I->seeLink($order->getOrderNumber(), '/admin/order/detail/' . $order->getId());
        // And the order page links back, so the pair is navigable from either side.
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeLink($invoice->getDocumentNumber(), '/admin/invoice/detail/' . $invoice->getId());
    }

    public function anInvoiceIsMovedThroughItsNamedActionsFromItsOwnPage(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, '10.00');
        $invoice = $this->invoiceSomeOf($I, $order, '10.00');

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->see('Start processing');

        $I->sendFormPostRequest('/admin/invoice/' . $invoice->getId() . '/action/start-processing', ['_token' => $I->csrfToken()]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var Invoice $reloaded */
        $reloaded = $em->find(Invoice::class, $invoice->getId());
        $I->assertSame('Processing', $reloaded->getStatus());

        // The transition it cannot make is refused, and the refusal is a flash rather than a 500.
        $I->sendFormPostRequest('/admin/invoice/' . $invoice->getId() . '/action/issue', ['_token' => $I->csrfToken()]);
        $I->see('cannot go from Processing to Pending');
    }

    public function cancellingAnInvoiceNeverTouchesItsOrder(FunctionalTester $I): void
    {
        $order = $this->approvedOrder($I, '10.00');
        $invoice = $this->invoiceSomeOf($I, $order, '10.00');

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->sendFormPostRequest('/admin/invoice/' . $invoice->getId() . '/action/cancel', ['_token' => $I->csrfToken()]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        /** @var SalesOrder $reloaded */
        $reloaded = $em->find(SalesOrder::class, $order->getId());

        // Not Void. The goods are still owed, and taking an order out of reporting totals is a
        // judgement an admin makes deliberately — decision 3 in the plan.
        $I->assertSame(SalesOrderStatus::Approved->value, $reloaded->getStatus());
        $I->assertSame('10.00', $reloaded->uninvoicedQuantityFor($reloaded->getLines()->first()));
    }

    private function draftOrder(FunctionalTester $I, string $quantity): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('PIW-' . uniqid())
            ->setSubtotal('50.00')
            ->setTax('0.00')
            ->setTotal('50.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($this->product)
                ->setName('Part Invoiced Widget')
                ->setSku($this->product->getSku())
                ->setQuantity($quantity)
                ->setPrice('5.00')
                ->setSubtotal('50.00')
        );
        $I->haveInRepository($order);

        return $order;
    }

    private function approvedOrder(FunctionalTester $I, string $quantity): SalesOrder
    {
        $order = $this->draftOrder($I, $quantity);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    private function invoiceSomeOf(FunctionalTester $I, SalesOrder $order, string $quantity): Invoice
    {
        $line = $order->getLines()->first();

        $I->amOnPage('/admin/invoice/create?order_id=' . $order->getId());
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [['product_id' => (string) $this->product->getId(), 'sales_order_line_id' => (string) $line->getId(), 'qty' => $quantity, 'price' => '5.00']],
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $invoice = $em->getRepository(Invoice::class)->findOneBy(['salesOrder' => $order], ['id' => 'DESC']);
        $I->assertNotNull($invoice, 'guard: the conversion this fixture depends on actually raised an invoice');

        return $invoice;
    }
}
