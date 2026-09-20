<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceShippingStatus;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActor;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * The derived shipping status is recalculated on every write to an invoice, whatever performed it.
 *
 * InvoiceShippingStatusDeriverTest covers the rules; this covers the guarantee that they are
 * applied, against the real kernel so the real listeners are registered — for the reason
 * InvoicePaymentStatusSubscriberTest is an integration test.
 *
 * ## Every case here runs with the shipment bundle switched OFF
 *
 * Deliberately, and it is the point of the file rather than a convenience. `DoctrineIntegrationTestCase`
 * activates every installed bundle, so the default here would be InventoryDepthBundle answering
 * core's `app.shipped_quantity` seam — which is a different subject, covered where that bundle's
 * code lives. What core owes on its own is that the column is written at all, and written from core
 * facts alone, on an instance where no shipment bundle exists or one exists and is switched
 * Inactive. Deactivating it is exactly what the App Management screen does, through the same method.
 *
 * Read back by COLUMN throughout: the derived column is what the subscriber writes, and an assertion
 * made through the same object graph the subscriber worked from cannot tell a written column from an
 * unwritten one.
 */
final class InvoiceShippingStatusSubscriberTest extends DoctrineIntegrationTestCase
{
    private Company $company;
    private int $sequence = 0;

    /** The invoice every case asserts did NOT move. Nothing in any test touches it. */
    private Invoice $bystander;

    protected function setUp(): void
    {
        parent::setUp();

        // No shipped-quantity provider answering, so the deriver is on core facts alone. Through the
        // repository rather than by deleting a row, so this is the state an admin can actually put
        // the application into.
        self::getContainer()->get(BundleStatusRepository::class)->deactivate('InventoryDepthBundle');
        $this->em->flush();

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);
        $this->em->flush();

        [, $this->bystander] = $this->invoicedOrder();
    }

    public function testAnInvoiceIsBornNotShipped(): void
    {
        [, $invoice] = $this->invoicedOrder();

        self::assertSame('Not Shipped', $this->shippingStatusColumn($invoice));
        self::assertSame(InvoiceShippingStatus::NotShipped, $invoice->getShippingStatus());
    }

    public function testStartingFulfilmentDoesNotClaimTheGoodsHaveGone(): void
    {
        [, $invoice] = $this->invoicedOrder();

        $invoice->setStatus('Processing', DocumentActor::system());
        $this->em->flush();

        // Processing is "somebody is picking it", not "it left". With nothing able to say how much
        // of it has gone, the honest answer is still Not Shipped.
        self::assertSame('Not Shipped', $this->shippingStatusColumn($invoice));
    }

    public function testCompletingAnInvoiceShipsItWithNoProviderListening(): void
    {
        [, $invoice] = $this->invoicedOrder();

        $invoice->setStatus('Processing', DocumentActor::system());
        $invoice->setStatus('Completed', DocumentActor::system());
        $this->em->flush();

        // The whole degraded contract, end to end: nobody set this column, no shipment row exists,
        // no bundle answered — and the grid still has a truthful value to show and sort on.
        self::assertSame('Shipped', $this->shippingStatusColumn($invoice));
        self::assertSame('Not Shipped', $this->shippingStatusColumn($this->bystander));
    }

    public function testCancellingAnInvoiceThatNeverShippedLeavesItNotShipped(): void
    {
        [, $invoice] = $this->invoicedOrder();

        $invoice->setStatus('Cancelled', DocumentActor::system());
        $this->em->flush();

        // Same discipline as the payment status on a cancelled invoice: the column keeps saying what
        // actually happened to the goods, which is that they never left.
        self::assertSame('Not Shipped', $this->shippingStatusColumn($invoice));
    }

    public function testTheTransitionIsWrittenOntoTheInvoicesTimeline(): void
    {
        [, $invoice] = $this->invoicedOrder();

        $invoice->setStatus('Processing', DocumentActor::system());
        $invoice->setStatus('Completed', DocumentActor::system());
        $this->em->flush();

        $comments = array_map(
            static fn (AuditLog $log): string => $log->getSummary(),
            $this->em->getRepository(AuditLog::class)->findBy(
                ['entityType' => 'Invoice', 'entityId' => $invoice->getId(), 'actorType' => 'document'],
            ),
        );

        self::assertContains('Shipping status changed from Not Shipped to Shipped.', $comments);
    }

    public function testANoOpFlushWritesNoSecondTimelineEntry(): void
    {
        [, $invoice] = $this->invoicedOrder();
        $invoice->setStatus('Processing', DocumentActor::system());
        $invoice->setStatus('Completed', DocumentActor::system());
        $this->em->flush();

        $before = $this->shippingLogCount($invoice);

        // An unrelated write to the same invoice. The status it derives to has not changed, so the
        // deriver must recompute, find nothing moved, and write nothing — otherwise every save on a
        // shipped invoice adds a line to its history saying it is still shipped.
        $invoice->setPoNumber('PO-' . $this->sequence);
        $this->em->flush();

        self::assertSame($before, $this->shippingLogCount($invoice));
        self::assertSame('Shipped', $this->shippingStatusColumn($invoice));
    }

    /**
     * `invoice.shipping_status` as the DATABASE holds it for $invoice.
     *
     * Raw SQL rather than the entity, for the reason InvoicePaymentStatusSubscriberTest reads its
     * own column that way.
     */
    private function shippingStatusColumn(Invoice $invoice): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT shipping_status FROM invoice WHERE id = ?',
            [$invoice->getId()],
        );
    }

    private function shippingLogCount(Invoice $invoice): int
    {
        $comments = array_map(
            static fn (AuditLog $log): string => $log->getSummary(),
            $this->em->getRepository(AuditLog::class)->findBy(
                ['entityType' => 'Invoice', 'entityId' => $invoice->getId(), 'actorType' => 'document'],
            ),
        );

        return \count(array_filter(
            $comments,
            static fn (string $comment): bool => str_starts_with($comment, 'Shipping status changed'),
        ));
    }

    /**
     * @return array{0: SalesOrder, 1: Invoice}
     */
    private function invoicedOrder(): array
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('SO-' . ++$this->sequence)
            ->setDocumentDate('2026-08-20')
            ->setTotal('100.00');
        $order->addLine((new SalesOrderLine())->setName('Widget')->setQuantity('4.00')->setPrice('25.00'));
        $this->em->persist($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('INV-' . $order->getOrderNumber())
            ->setDocumentDate('2026-08-20')
            ->setTotal('100.00');
        $order->addInvoice($invoice);

        $orderLine = $order->getLines()->first();
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($orderLine)
                ->setName($orderLine->getName())
                ->setQuantity('4.00')
                ->setPrice($orderLine->getPrice()),
        );

        $this->em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        return [$order, $invoice];
    }
}
