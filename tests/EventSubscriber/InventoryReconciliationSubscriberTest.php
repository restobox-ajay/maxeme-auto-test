<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\InventoryBucketChangeLog;
use App\Entity\Invoice;
use App\Entity\InvoiceInventoryReservation;
use App\Entity\InvoiceLine;
use App\Entity\OrderInventoryReservation;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * InventoryReservationReconcilerTest already proves the onFlush/postFlush wiring fires at all
 * (persist-a-document triggers reconcile()). This file targets the subscriber's own
 * queueFromEntity()/dedup logic specifically — behavior that a test only ever adding one line per
 * flush wouldn't distinguish from the service just being called directly:
 *  - a SalesOrderLine deletion (orphan-removed from the collection, order itself untouched) still
 *    queues and reconciles the parent order;
 *  - order + multiple line changes scheduled in the *same* flush collapse to one reconcile() call
 *    per order (spl_object_id-keyed queue), not one per changed entity;
 *  - an invoice write queues the invoice AND its order, which is what keeps the order's sales hold
 *    true when the invoice moves and the order row is never written (#539 stage 3).
 */
final class InventoryReconciliationSubscriberTest extends DoctrineIntegrationTestCase
{
    private FulfillmentRegion $region;
    private Warehouse $warehouse;
    private ProductCore $product;
    private ProductInventory $inventory;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($this->region);

        // The order names a REGION and its stock comes out of the WAREHOUSE serving it (#546).
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');

        $this->product = (new ProductCore())->setSku('SKU-1')->setName('Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($this->product);

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);

        $this->inventory = (new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(100);
        $this->em->persist($this->inventory);

        $this->em->flush();
    }

    /**
     * A Draft order — what a new one always is. It reaches Approved through the named action
     * below, because SalesOrder has no status setter any more (#539 stage 2).
     */
    private function newOrder(): SalesOrder
    {
        return (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setFulfillmentRegion($this->region->getName());
    }

    /** An invoice for the whole of $order, left Draft for the caller to issue. */
    private function invoiceFor(SalesOrder $order): Invoice
    {
        $invoice = new Invoice();
        $order->addInvoice($invoice);
        $invoice
            ->setCompany($this->company)
            ->setDocumentNumber('INV-' . uniqid())
            ->setFulfillmentRegion($order->getFulfillmentRegion());

        foreach ($order->getLines() as $line) {
            $invoice->addLine(
                (new InvoiceLine())
                    ->setSalesOrderLine($line)
                    ->setProduct($line->getProduct())
                    ->setName($line->getName())
                    ->setQuantity($line->getQuantity()),
            );
        }

        $this->em->persist($invoice);

        return $invoice;
    }

    private function addLine(SalesOrder $order, string $quantity): SalesOrderLine
    {
        $line = (new SalesOrderLine())
            ->setProduct($this->product)
            ->setName($this->product->getName())
            ->setQuantity($quantity);
        $order->addLine($line);

        return $line;
    }

    private function refreshInventory(): ProductInventory
    {
        $this->em->refresh($this->inventory);

        return $this->inventory;
    }

    public function testRemovingLineViaOrphanRemovalReconcilesWithoutOrderItselfChanging(): void
    {
        $order = $this->newOrder();
        $keptLine = $this->addLine($order, '3');
        $removedLine = $this->addLine($order, '5');
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        self::assertSame('8.0000', $this->refreshInventory()->getSalesHoldQuantity());

        // Only the collection changes (orphanRemoval schedules a SalesOrderLine deletion) —
        // no scalar field on SalesOrder itself is touched in this flush.
        $order->getLines()->removeElement($removedLine);
        $this->em->flush();

        self::assertSame('3.0000', $this->refreshInventory()->getSalesHoldQuantity());

        $reservations = $this->em->getRepository(OrderInventoryReservation::class)->findBy(['order' => $order]);
        self::assertCount(1, $reservations);
        self::assertSame('3.0000', $reservations[0]->getQuantity());
        self::assertSame($keptLine->getProduct()->getId(), $reservations[0]->getProduct()->getId());
    }

    public function testOrderAndMultipleLineChangesInOneFlushProduceOneReconcilePass(): void
    {
        $order = $this->newOrder();
        $lineA = $this->addLine($order, '3');
        $lineB = $this->addLine($order, '5');
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        $entriesAfterCreate = $this->em->getRepository(InventoryBucketChangeLog::class)->findBy(['product' => $this->product]);
        self::assertCount(1, $entriesAfterCreate, 'creating an order with two lines in one flush should net to a single reconciled bucket change, not one per line');
        self::assertSame('8.0000', $entriesAfterCreate[0]->getNewQuantity());

        // Change the order's own row AND both of its lines' quantities in the same flush — the
        // queue is keyed by spl_object_id(order), so this must still call reconcile() exactly once
        // for this order rather than once per scheduled entity.
        //
        // The order's own field is a plain header edit rather than a status change, because from
        // #539 stage 2 an order's status is derived from its invoices and cannot be written by
        // hand. The bucket move that used to ride along with it is asserted below instead, through
        // the transition that actually causes it.
        $order->setSpecialInstructions('Deliver before noon');
        $lineA->setQuantity('4');
        $lineB->setQuantity('6');
        $this->em->flush();

        $inventory = $this->refreshInventory();
        self::assertSame('10.0000', $inventory->getSalesHoldQuantity());
        self::assertSame('0.0000', $inventory->getPendingQuantity());

        $entriesAfterUpdate = $this->em->getRepository(InventoryBucketChangeLog::class)->findBy(['product' => $this->product]);
        // One net change for the one bucket actually affected — not duplicated per scheduled
        // entity in that second flush.
        self::assertCount(2, $entriesAfterUpdate);

        // Issuing an invoice for the whole order moves the same hold out of sales hold and into
        // pending. The order row is never touched by this test: the invoice write is what queues it.
        $this->invoiceFor($order)->issue(DocumentActor::system());
        $this->em->flush();

        $inventory = $this->refreshInventory();
        self::assertSame('0.0000', $inventory->getSalesHoldQuantity());
        self::assertSame('10.0000', $inventory->getPendingQuantity());

        // Two more: sales hold released, pending gained. Still one reconcile pass per document.
        self::assertCount(4, $this->em->getRepository(InventoryBucketChangeLog::class)->findBy(['product' => $this->product]));
    }

    /**
     * The stage 3 case a listener watching only orders would miss entirely: an invoice line changes,
     * the order's status stays exactly where it is, and its sales hold still has to move — because
     * the remainder it holds is a function of what the invoices bill.
     */
    /**
     * Deleting an order must not reconcile the order being deleted.
     *
     * The deletions loop queues a deleted entity's parent deliberately — dropping a line changes
     * what its order holds. But by postFlush a deleted ORDER has no row, its reservations went with
     * it on cascade, and Doctrine has stripped its identifier, so anything binding it as a query
     * parameter throws "does not have an identifier". #548's backorder queue synchroniser does
     * exactly that, which is how this surfaced.
     */
    public function testDeletingAnOrderDoesNotReconcileTheDeletedOrder(): void
    {
        $order = $this->newOrder();
        $this->em->persist($order);
        $this->addLine($order, '4.00');
        $this->em->flush();
        $orderId = $order->getId();
        self::assertNotNull($orderId, 'guard: the order was persisted');

        $this->em->remove($order);
        $this->em->flush();

        self::assertNull(
            $this->em->getRepository(SalesOrder::class)->find($orderId),
            'the order is gone and the flush completed without throwing',
        );
    }

    public function testAnInvoiceLineWriteQueuesItsOrderAsWellAsTheInvoice(): void
    {
        $order = $this->newOrder();
        $this->addLine($order, '10');
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        $invoice = $this->invoiceFor($order);
        $invoice->getLines()->first()->setQuantity('4');
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        $statusBefore = $order->getStatus();
        self::assertSame('Partially Invoiced', $statusBefore);
        self::assertSame('6.0000', $this->refreshInventory()->getSalesHoldQuantity());
        self::assertSame('4.0000', $this->refreshInventory()->getPendingQuantity());

        // Nothing but the invoice line is written here.
        $invoice->getLines()->first()->setQuantity('9');
        $this->em->flush();

        self::assertSame($statusBefore, $order->getStatus(), 'guard: the order was not moved by this flush');
        self::assertSame('1.0000', $this->refreshInventory()->getSalesHoldQuantity());
        self::assertSame('9.0000', $this->refreshInventory()->getPendingQuantity());

        self::assertCount(1, $this->em->getRepository(InvoiceInventoryReservation::class)->findBy(['invoice' => $invoice]));
    }
}
