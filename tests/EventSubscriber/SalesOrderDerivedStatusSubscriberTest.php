<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePayment;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * The derived status is recalculated on every write, whatever performed it (#539 stage 2).
 *
 * SalesOrderStatusDeriverTest covers the rules; this covers the guarantee that they are applied —
 * and specifically that a write to an INVOICE moves its order, even though such a write never
 * touches the order row at all. That is the case a call at each call site would miss, and it is the
 * reason this is a Doctrine listener rather than a service somebody remembers to call.
 *
 * Against the real kernel so the real listener is registered, for the reason
 * InventoryReconciliationSubscriberTest is.
 */
final class SalesOrderDerivedStatusSubscriberTest extends DoctrineIntegrationTestCase
{
    private Company $company;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testApprovingAnOrderWithNoInvoicesLeavesItApproved(): void
    {
        $order = $this->persistedOrder('4.00');
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        self::assertSame(SalesOrderStatus::Approved, $order->getStatusEnum());
    }

    public function testIssuingAnInvoiceMovesItsOrderWithoutTheOrderBeingTouched(): void
    {
        $order = $this->persistedOrder('4.00');
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        $invoice = $this->invoiceFor($order, '4.00');
        $this->em->persist($invoice);
        // Nothing on the order is written here — only the invoice. The order still moves.
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());
    }

    public function testAPartialInvoiceLandsTheOrderInPartiallyInvoiced(): void
    {
        $order = $this->persistedOrder('10.00');
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        $invoice = $this->invoiceFor($order, '3.00');
        $this->em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        self::assertSame(SalesOrderStatus::PartiallyInvoiced, $order->getStatusEnum());
    }

    public function testPayingTheLastInvoiceClosesTheOrder(): void
    {
        $order = $this->persistedOrder('4.00');
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $invoice = $this->invoiceFor($order, '4.00');
        $this->em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());

        // The payment is the write that closes the order, and it touches neither the order row nor
        // the invoice row — which is why InvoicePayment is watched too (#539 stage 4).
        $payment = (new InvoicePayment())->setMethod('Bank Transfer')->setAmount($invoice->getTotal());
        $invoice->recordPayment(DocumentActor::system(), $payment);
        $this->em->persist($payment);
        $this->em->flush();

        self::assertSame(SalesOrderStatus::Closed, $order->getStatusEnum());
    }

    public function testCancellingTheOnlyInvoiceReturnsTheOrderToApproved(): void
    {
        $order = $this->persistedOrder('4.00');
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $invoice = $this->invoiceFor($order, '4.00');
        $this->em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        $invoice->setStatus('Cancelled', DocumentActor::system());
        $this->em->flush();

        // Not Void: the goods are still owed, and taking an order out of reporting totals is a
        // judgement an admin makes rather than a consequence of one invoice being withdrawn.
        self::assertSame(SalesOrderStatus::Approved, $order->getStatusEnum());
    }

    public function testAddingALineToAFullyInvoicedOrderReopensIt(): void
    {
        $order = $this->persistedOrder('4.00');
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $invoice = $this->invoiceFor($order, '4.00');
        $this->em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());

        $order->addLine((new SalesOrderLine())->setName('Extra')->setQuantity('2.00')->setPrice('3.00'));
        $this->em->flush();

        self::assertSame(SalesOrderStatus::PartiallyInvoiced, $order->getStatusEnum());
    }

    public function testAVoidedOrderIsNeverDerivedBackOut(): void
    {
        $order = $this->persistedOrder('4.00');
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $invoice = $this->invoiceFor($order, '4.00');
        $this->em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        $order->setStatus('Void', DocumentActor::system());
        $this->em->flush();

        $invoice->setStatus('Cancelled', DocumentActor::system());
        $this->em->flush();

        self::assertSame(SalesOrderStatus::Void, $order->getStatusEnum());
    }

    public function testTheTransitionIsOnTheOrdersTimeline(): void
    {
        $order = $this->persistedOrder('4.00');
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $invoice = $this->invoiceFor($order, '4.00');
        $this->em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        $comments = array_map(
            static fn (AuditLog $log): string => $log->getSummary(),
            $this->em->getRepository(AuditLog::class)->findBy(
                ['entityType' => 'SalesOrder', 'entityId' => $order->getId(), 'actorType' => 'document'],
            ),
        );

        self::assertContains('Order approved.', $comments);
        self::assertContains('Order status changed from Approved to Invoiced.', $comments);
    }

    public function testAStandaloneInvoiceWithNoOrderIsNotAProblem(): void
    {
        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('INV-STANDALONE')
            ->setDocumentDate('2026-08-20');
        $invoice->addLine((new InvoiceLine())->setName('Consulting')->setQuantity('1.00')->setPrice('500.00'));
        $this->em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        self::assertNull($invoice->getSalesOrder());
    }

    private function persistedOrder(string $quantity): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('SO-' . ++$this->sequence)
            ->setDocumentDate('2026-08-20');
        $order->addLine((new SalesOrderLine())->setName('Widget')->setQuantity($quantity)->setPrice('5.00'));

        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }

    private function invoiceFor(SalesOrder $order, string $quantity): Invoice
    {
        $invoice = (new Invoice())
            ->setCompany($order->getCompany())
            ->setDocumentNumber('INV-' . $order->getOrderNumber() . '-' . $order->getInvoices()->count())
            ->setDocumentDate('2026-08-20');
        $order->addInvoice($invoice);

        $orderLine = $order->getLines()->first();
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($orderLine)
                ->setName($orderLine->getName())
                ->setQuantity($quantity)
                ->setPrice($orderLine->getPrice()),
        );
        // Totalled, because since #539 stage 4 an invoice is fully paid when its payments cover its
        // total — one left at 0.00 is settled from birth and closes its order immediately.
        $invoice->setTotal(number_format((float) $quantity * (float) $orderLine->getPrice(), 2, '.', ''));

        return $invoice;
    }
}
