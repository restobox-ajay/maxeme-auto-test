<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\InventoryBucketChangeLog;
use App\Entity\Invoice;
use App\Entity\InvoiceInventoryReservation;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePayment;
use App\Entity\OrderInventoryReservation;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * Exercises reconcile() through the real path — persisting/flushing a SalesOrder or an Invoice —
 * rather than calling the service directly, so these tests also prove
 * InventoryReconciliationSubscriber actually triggers it (that wiring is exactly the thing a unit
 * test calling reconcile() directly would never catch a regression in).
 *
 * The scenario table at the bottom is #539's own, from the plan, and is the point of stage 3: which
 * bucket holds what, for each of the four shapes an order and its invoices can take.
 */
final class InventoryReservationReconcilerTest extends DoctrineIntegrationTestCase
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
     * A brand new order is a Draft, which holds nothing. Every status below is reached through the
     * named actions or through the deriver's own rules — SalesOrder has no setStatus() (#539 stage
     * 2), and a hand-written status would not survive the next flush anyway: the derived-status
     * subscriber recomputes it from the invoice set on every write.
     */
    private function newOrder(): SalesOrder
    {
        return (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setFulfillmentRegion($this->region->getName());
    }

    /** An accepted order with nothing invoiced against it: its whole quantity sits in sales hold. */
    private function approvedOrder(string $quantity): SalesOrder
    {
        $order = $this->newOrder();
        $this->addLine($order, $quantity);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);

        return $order;
    }

    /**
     * An invoice for part or all of $order, left Draft — the caller issues it with the action that
     * matches the state it wants, since which bucket it lands in is its status and nothing else.
     *
     * @param array<int, string>|null $quantities per order line, or null for the full quantity
     * @param bool $paid settle the invoice, so its order can derive to Closed. Since #539 stage 4
     *                   Payment is a row covering the total rather than a label typed onto the
     *                   invoice, which is why the fixture gives every invoice a real total: with no
     *                   total an invoice is owed nothing and is fully paid the moment it exists, and
     *                   an "unpaid" order would derive straight to Closed. See payInFull().
     *                   Issuing stays the caller's job — these tests move invoices through their
     *                   statuses deliberately.
     */
    /** Every fixture invoice is owed this, so "unpaid" means unpaid. */
    private const INVOICE_TOTAL = '100.00';

    private function invoiceFor(SalesOrder $order, ?array $quantities = null): Invoice
    {
        $invoice = new Invoice();
        $order->addInvoice($invoice);
        $invoice
            ->setCompany($this->company)
            ->setDocumentNumber('INV-' . uniqid())
            ->setFulfillmentRegion($order->getFulfillmentRegion())
            ->setTotal(self::INVOICE_TOTAL);

        foreach ($order->getLines() as $index => $line) {
            $invoice->addLine(
                (new InvoiceLine())
                    ->setSalesOrderLine($line)
                    ->setProduct($line->getProduct())
                    ->setName($line->getName())
                    ->setQuantity($quantities[$index] ?? $line->getQuantity()),
            );
        }

        $this->em->persist($invoice);

        return $invoice;
    }

    /**
     * Settles an ISSUED invoice in full.
     *
     * Called after issue() rather than folded into the fixture, because since #31 only an issued
     * invoice takes a payment — a draft has been sent to nobody, so nobody could have paid it — and
     * that is the real order of events anyway: the invoice goes out, then the money comes back.
     */
    private function payInFull(Invoice $invoice): void
    {
        $payment = (new InvoicePayment())->setMethod('Bank Transfer')->setAmount(self::INVOICE_TOTAL);
        $invoice->recordPayment(DocumentActor::system(), $payment);
        $this->em->persist($payment);
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

    /** @return array{int, int, int, int} sales hold, pending, approved, shipped */
    private function buckets(): array
    {
        $inventory = $this->refreshInventory();

        return [
            $inventory->getSalesHoldQuantity(),
            $inventory->getPendingQuantity(),
            $inventory->getApprovedQuantity(),
            $inventory->getShippedQuantity(),
        ];
    }

    public function testNewApprovedOrderHoldsItsWholeQuantityInSalesHold(): void
    {
        $this->approvedOrder('5');
        $this->em->flush();

        self::assertSame(['5.0000', '0.0000', '0.0000', '0.0000'], $this->buckets());
        self::assertSame('95.0000', $this->refreshInventory()->getAvailableQuantity());
    }

    /**
     * The move stage 3 exists to make: issuing an invoice does not add a second hold, it moves the
     * quantity from the order's ledger to the invoice's. Availability does not budge.
     */
    public function testIssuingAnInvoiceMovesTheHoldFromSalesHoldToPending(): void
    {
        $order = $this->approvedOrder('5');
        $this->em->flush();

        $invoice = $this->invoiceFor($order);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        self::assertSame(['0.0000', '5.0000', '0.0000', '0.0000'], $this->buckets());
        self::assertSame('95.0000', $this->refreshInventory()->getAvailableQuantity(), 'the same five units, held by a different document');

        self::assertCount(0, $this->em->getRepository(OrderInventoryReservation::class)->findBy(['order' => $order]));
        self::assertCount(1, $this->em->getRepository(InvoiceInventoryReservation::class)->findBy(['invoice' => $invoice]));
    }

    /**
     * Pending -> Processing moves the invoice's own hold into approved. Processing -> Completed
     * transfers it again, into shipped — a relabeling, not a release, and not a second claim on top
     * of the first (2026-09-15, docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md).
     */
    public function testProcessingHoldsApprovedAndCompletedTransfersToShipped(): void
    {
        $order = $this->approvedOrder('5');
        $this->em->flush();

        $invoice = $this->invoiceFor($order);
        $invoice->issue(DocumentActor::system());
        $this->payInFull($invoice);
        $this->em->flush();

        $invoice->startProcessing(DocumentActor::system());
        $this->em->flush();
        self::assertSame(['0.0000', '0.0000', '5.0000', '0.0000'], $this->buckets());

        $invoice->setStatus('Completed', DocumentActor::system());
        $this->em->flush();
        self::assertSame(['0.0000', '0.0000', '0.0000', '5.0000'], $this->buckets(), 'the hold moved to shipped, not a second claim on top of it');
        self::assertSame('Closed', $order->getStatus(), 'guard: fully invoiced and fully paid');
    }

    /**
     * A completed invoice keeps holding shipped indefinitely — this app has no mechanism that
     * decrements Starting Inventory on shipment, so a later write must not quietly hand the stock
     * back.
     */
    public function testCompletedInvoiceStaysInShippedBucketAcrossLaterWrites(): void
    {
        $order = $this->approvedOrder('5');
        $this->em->flush();

        $invoice = $this->invoiceFor($order);
        $invoice->issue(DocumentActor::system());
        $this->payInFull($invoice);
        $invoice->startProcessing(DocumentActor::system());
        $invoice->setStatus('Completed', DocumentActor::system());
        $this->em->flush();

        self::assertSame(['0.0000', '0.0000', '0.0000', '5.0000'], $this->buckets());

        $order->setSpecialInstructions('Leave at the loading dock');
        $this->em->flush();

        self::assertSame(['0.0000', '0.0000', '0.0000', '5.0000'], $this->buckets());
    }

    /**
     * A shipped line only releases its hold when the note that credits it says the goods came
     * back — otherwise the hold stays, since nothing about a money-only refund changes what
     * physically left the building.
     */
    public function testAShippedLineOnlyReleasesItsHoldWhenTheCreditingNoteRestocks(): void
    {
        $order = $this->approvedOrder('5');
        $this->em->flush();
        $invoice = $this->invoiceFor($order);
        $invoice->issue(DocumentActor::system());
        $this->payInFull($invoice);
        $invoice->startProcessing(DocumentActor::system());
        $invoice->setStatus('Completed', DocumentActor::system());
        $this->em->flush();
        $line = $invoice->getLines()->first();

        $noRestock = (new \App\Entity\CreditMemo())->setCompany($this->company)->setInvoice($invoice)->setDocumentNumber('CN-A')->setRestock(false);
        $cl1 = (new \App\Entity\CreditMemoLine())->setInvoiceLine($line)->setProduct($this->product)->setName('x')->setQuantity('1.00')->setPrice('1.00')->setSubtotal('1.00');
        $noRestock->addLine($cl1)->setSubtotal('1.00')->setTax('0.00')->setTotal('1.00');
        $this->em->persist($noRestock);
        $this->em->persist($cl1);
        $noRestock->issue();
        $this->em->flush();
        self::assertSame(['0.0000', '0.0000', '0.0000', '5.0000'], $this->buckets(), 'money-only refund does not release the shipped hold');

        $restocked = (new \App\Entity\CreditMemo())->setCompany($this->company)->setInvoice($invoice)->setDocumentNumber('CN-B')->setRestock(true);
        $cl2 = (new \App\Entity\CreditMemoLine())->setInvoiceLine($line)->setProduct($this->product)->setName('x')->setQuantity('1.00')->setPrice('1.00')->setSubtotal('1.00');
        $restocked->addLine($cl2)->setSubtotal('1.00')->setTax('0.00')->setTotal('1.00');
        $this->em->persist($restocked);
        $this->em->persist($cl2);
        $restocked->issue();
        $this->em->flush();
        self::assertSame(['0.0000', '0.0000', '0.0000', '4.0000'], $this->buckets(), 'a restocked note releases its own credited unit from shipped');
    }

    /**
     * A draft invoice is inert on both sides: it holds nothing itself and does not draw down the
     * order's sales hold. Decision 1 in the plan, and the reason drafting one cannot make a quantity
     * escape into neither ledger.
     */
    public function testDraftInvoiceHoldsNothingAndLeavesSalesHoldWhereItWas(): void
    {
        $order = $this->approvedOrder('5');
        $this->em->flush();

        $this->invoiceFor($order);
        $this->em->flush();

        self::assertSame(['5.0000', '0.0000', '0.0000', '0.0000'], $this->buckets());
        self::assertSame('Approved', $order->getStatus(), 'guard: a draft invoice does not count');
    }

    /**
     * Cancelling every invoice on an order returns its quantity to the order's sales hold rather
     * than releasing it — the goods are still owed, which is exactly why cancelling an invoice does
     * not void its order (decision 3 in the plan).
     */
    public function testCancellingAnInvoiceReturnsTheQuantityToSalesHold(): void
    {
        $order = $this->approvedOrder('5');
        $this->em->flush();

        $invoice = $this->invoiceFor($order);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();
        self::assertSame(['0.0000', '5.0000', '0.0000', '0.0000'], $this->buckets());

        $invoice->setStatus('Cancelled', DocumentActor::system(), 'Invoice cancelled: customer changed their mind');
        $this->em->flush();

        self::assertSame(['5.0000', '0.0000', '0.0000', '0.0000'], $this->buckets());
        self::assertSame('Approved', $order->getStatus());
        self::assertSame('95.0000', $this->refreshInventory()->getAvailableQuantity());
    }

    public function testVoidingOrderReleasesReservedQuantity(): void
    {
        $order = $this->approvedOrder('5');
        $this->em->flush();

        $order->setStatus('Void', DocumentActor::system());
        $this->em->flush();

        self::assertSame(['0.0000', '0.0000', '0.0000', '0.0000'], $this->buckets());
        self::assertSame('100.0000', $this->refreshInventory()->getAvailableQuantity());

        self::assertCount(0, $this->em->getRepository(OrderInventoryReservation::class)->findBy(['order' => $order]));
    }

    public function testEditingLineQuantityAppliesDeltaOnly(): void
    {
        $order = $this->approvedOrder('5');
        $this->em->flush();

        $order->getLines()->first()->setQuantity('8');
        $this->em->flush();

        self::assertSame(['8.0000', '0.0000', '0.0000', '0.0000'], $this->buckets());

        $reservations = $this->em->getRepository(OrderInventoryReservation::class)->findBy(['order' => $order]);
        self::assertCount(1, $reservations);
        self::assertSame('8.0000', $reservations[0]->getQuantity());
        self::assertSame(OrderInventoryReservation::BUCKET_SALES_HOLD, $reservations[0]->getBucket());
    }

    /**
     * Re-quantifying an invoice line moves the order's sales hold even though the order row is never
     * written and its status never changes. This is what the reconciliation subscriber queueing an
     * invoice's ORDER as well as the invoice itself buys — without it the remainder here would stay
     * at its old value indefinitely.
     */
    public function testEditingAnInvoiceLineMovesTheOrdersSalesHoldWithoutTouchingTheOrder(): void
    {
        $order = $this->approvedOrder('10');
        $this->em->flush();

        $invoice = $this->invoiceFor($order, ['4']);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        self::assertSame(['6.0000', '4.0000', '0.0000', '0.0000'], $this->buckets());
        self::assertSame('Partially Invoiced', $order->getStatus());

        $invoice->getLines()->first()->setQuantity('7');
        $this->em->flush();

        self::assertSame(['3.0000', '7.0000', '0.0000', '0.0000'], $this->buckets());
        self::assertSame('Partially Invoiced', $order->getStatus(), 'guard: the order row itself was never written');
    }

    public function testDraftOrderHoldsNothing(): void
    {
        $order = $this->newOrder();
        $this->addLine($order, '5');
        $this->em->persist($order);
        $this->em->flush();

        self::assertSame('Draft', $order->getStatus());
        self::assertSame(['0.0000', '0.0000', '0.0000', '0.0000'], $this->buckets());
    }

    /**
     * Simulates ProductImportService's clear_approved_balance reset (stamp syncedQuantity =
     * quantity directly on the ledger row, as the import does) then edits the invoice's line
     * quantity — only the amount beyond that baseline should re-enter Approved, not the whole new
     * quantity and not the old quantity stacked on top of it.
     *
     * The baseline lives on the INVOICE's ledger from stage 3 on, because approved is the invoice's
     * bucket.
     */
    public function testApprovedBalanceResetSurvivesLaterEdit(): void
    {
        $order = $this->approvedOrder('5');
        $this->em->flush();

        $invoice = $this->invoiceFor($order);
        $invoice->issue(DocumentActor::system());
        $this->payInFull($invoice);
        $invoice->startProcessing(DocumentActor::system());
        $this->em->flush();

        self::assertSame(['0.0000', '0.0000', '5.0000', '0.0000'], $this->buckets());

        $reservation = $this->em->getRepository(InvoiceInventoryReservation::class)->findOneBy(['invoice' => $invoice]);
        self::assertNotNull($reservation);
        $reservation->setSyncedQuantity($reservation->getQuantity());
        $this->refreshInventory()->setApprovedQuantity(0);
        $this->em->flush();

        self::assertSame('0.0000', $this->refreshInventory()->getApprovedQuantity());

        // The order line moves with the invoice line so the order stays fully invoiced; otherwise the
        // remainder would legitimately reappear in sales hold, which is a different behaviour from
        // the one under test here.
        $order->getLines()->first()->setQuantity('8');
        $invoice->getLines()->first()->setQuantity('8');
        $this->em->flush();

        self::assertSame(['0.0000', '0.0000', '3.0000', '0.0000'], $this->buckets());
    }

    public function testReconciledChangeIsRecordedInBucketChangeLog(): void
    {
        $order = $this->approvedOrder('5');
        $this->em->flush();

        $entries = $this->em->getRepository(InventoryBucketChangeLog::class)->findBy(['product' => $this->product]);
        self::assertCount(1, $entries);
        self::assertSame(InventoryBucketChangeLog::BUCKET_SALES_HOLD, $entries[0]->getBucket());
        self::assertSame('0.0000', $entries[0]->getPreviousQuantity());
        self::assertSame('5.0000', $entries[0]->getNewQuantity());
        self::assertSame('order_reconciled', $entries[0]->getAction());

        // The invoice signs its own entries, so a change history says which side moved.
        $this->invoiceFor($order)->issue(DocumentActor::system());
        $this->em->flush();

        $actions = array_map(
            static fn (InventoryBucketChangeLog $entry): string => $entry->getBucket() . ':' . $entry->getAction(),
            $this->em->getRepository(InventoryBucketChangeLog::class)->findBy(['product' => $this->product]),
        );

        self::assertContains('pending:invoice_reconciled', $actions);
        self::assertContains('sales_hold:order_reconciled', $actions);
    }

    /*
     * ------------------------------------------------------------------------------------------
     * The scenario table (#539, "Inventory: the Sales Hold bucket")
     * ------------------------------------------------------------------------------------------
     *
     * |                                | invoiced qty | SO sales_hold | Invoice bucket | net held |
     * |--------------------------------|--------------|---------------|----------------|----------|
     * | ecom, unpaid (On Hold)         | full         | 0             | none           | nothing  |
     * | ecom, paid (Pending)           | full         | 0             | pending        | qty      |
     * | manual SO, nothing invoiced    | 0            | full          | —              | qty      |
     * | manual SO, half invoiced       | half         | half          | pending, half  | full     |
     */

    /** Row 1: an abandoned card checkout reserves nothing at all, without any special case. */
    public function testScenarioEcomUnpaidHoldsNothing(): void
    {
        $order = $this->approvedOrder('10');
        $invoice = $this->invoiceFor($order);
        $invoice->issueAwaitingPayment(DocumentActor::system());
        $this->em->flush();

        self::assertSame('Invoiced', $order->getStatus(), 'an ecom order is fully invoiced from the outset');
        self::assertSame(['0.0000', '0.0000', '0.0000', '0.0000'], $this->buckets());
        self::assertSame('100.0000', $this->refreshInventory()->getAvailableQuantity());
    }

    /** Row 2: the payment lands, and the whole quantity is held once — by the invoice. */
    public function testScenarioEcomPaidHoldsTheQuantityOnTheInvoice(): void
    {
        $order = $this->approvedOrder('10');
        $invoice = $this->invoiceFor($order);
        $invoice->issueAwaitingPayment(DocumentActor::system());
        $this->em->flush();

        $invoice->paymentReceived(DocumentActor::system());
        $this->em->flush();

        self::assertSame(['0.0000', '10.0000', '0.0000', '0.0000'], $this->buckets());
        self::assertSame('90.0000', $this->refreshInventory()->getAvailableQuantity());
    }

    /** Row 3: an accepted order nobody has billed yet holds all of it, and only it. */
    public function testScenarioManualOrderWithNothingInvoicedHoldsItAll(): void
    {
        $this->approvedOrder('10');
        $this->em->flush();

        self::assertSame(['10.0000', '0.0000', '0.0000', '0.0000'], $this->buckets());
        self::assertSame('90.0000', $this->refreshInventory()->getAvailableQuantity());
    }

    /** Row 4: half billed, half not — the two ledgers add up to the whole, and never to more. */
    public function testScenarioManualOrderHalfInvoicedHoldsHalfOnEachSide(): void
    {
        $order = $this->approvedOrder('10');
        $this->em->flush();

        $invoice = $this->invoiceFor($order, ['5']);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        self::assertSame('Partially Invoiced', $order->getStatus());
        self::assertSame(['5.0000', '5.0000', '0.0000', '0.0000'], $this->buckets());
        self::assertSame('90.0000', $this->refreshInventory()->getAvailableQuantity(), 'ten units held in total, not fifteen');
    }
}
