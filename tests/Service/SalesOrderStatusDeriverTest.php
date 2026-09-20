<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePayment;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use App\Service\SalesOrderStatusDeriver;
use App\Status\CoreStatusVocabularyProvider;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The six derived-status rules from #539, one test each.
 *
 * Approved and Void are the only statuses a human sets; everything else follows from the invoice
 * set. This file is the statement of what "follows from" means, and it is deliberately a plain unit
 * test: the rules are arithmetic over an order's own invoices, so a database would add nothing but
 * a way for the rules and the fixtures to disagree about what was flushed.
 */
final class SalesOrderStatusDeriverTest extends TestCase
{
    private SalesOrderStatusDeriver $deriver;

    protected function setUp(): void
    {
        $this->deriver = new SalesOrderStatusDeriver();

        // These are plain unit tests with no kernel, so nothing has primed the static registry the
        // entities read their vocabulary through. Priming it with the REAL core provider rather
        // than a fixture is deliberate: a fixture vocabulary would let this file and the shipped
        // one disagree about what a sales order's statuses are, which is the drift the seam exists
        // to delete.
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new CoreStatusVocabularyProvider()]));
    }

    protected function tearDown(): void
    {
        // Static state outlives a test. Leaving it primed would hide a missing setUp() in the next
        // file that runs.
        StatusVocabularyRegistry::reset();
    }

    public function testAnUnapprovedOrderIsADraftWhateverItsInvoicesSay(): void
    {
        $order = $this->order();
        $line = $this->line($order, '5.00');
        // Issued against an order nobody approved. Nothing in the app can produce this — the
        // create-invoice screen and OrderInvoicingService both refuse an unapproved order — and the
        // rule still has to be stated, because "not approved" is the first question the deriver asks
        // and an invoice must not be able to answer it.
        $this->invoiceFor($order, $line, '5.00')->issue(DocumentActor::system());

        self::assertSame('Draft', $order->deriveStatus());
    }

    public function testAnApprovedOrderWithNothingInvoicedIsApproved(): void
    {
        $order = $this->approvedOrder();
        $this->line($order, '5.00');

        self::assertSame('Approved', $order->deriveStatus());
    }

    public function testADraftInvoiceLeavesTheOrderApproved(): void
    {
        $order = $this->approvedOrder();
        $line = $this->line($order, '5.00');
        $this->invoiceFor($order, $line, '5.00');

        self::assertSame('Approved', $order->deriveStatus());
    }

    public function testSomeButNotAllQuantityInvoicedIsPartiallyInvoiced(): void
    {
        $order = $this->approvedOrder();
        $line = $this->line($order, '5.00');
        $this->invoiceFor($order, $line, '2.00')->issue(DocumentActor::system());

        self::assertSame('Partially Invoiced', $order->deriveStatus());
    }

    public function testAllQuantityInvoicedAndUnpaidIsInvoiced(): void
    {
        $order = $this->approvedOrder();
        $line = $this->line($order, '5.00');
        $this->invoiceFor($order, $line, '5.00')->issue(DocumentActor::system());

        self::assertSame('Invoiced', $order->deriveStatus());
    }

    public function testAllQuantityInvoicedAndPaidIsClosed(): void
    {
        $order = $this->approvedOrder();
        $line = $this->line($order, '5.00');
        $this->paid($this->invoiceFor($order, $line, '5.00')->issue(DocumentActor::system()));

        self::assertSame('Closed', $order->deriveStatus());
    }

    public function testOneUnpaidInvoiceHoldsTheWholeOrderOpen(): void
    {
        $order = $this->approvedOrder();
        $line = $this->line($order, '6.00');
        $this->paid($this->invoiceFor($order, $line, '3.00')->issue(DocumentActor::system()));
        $this->invoiceFor($order, $line, '3.00')->issue(DocumentActor::system());

        self::assertSame('Invoiced', $order->deriveStatus());
    }

    public function testCancellingEveryInvoiceReturnsTheOrderToApproved(): void
    {
        $order = $this->approvedOrder();
        $line = $this->line($order, '5.00');
        $invoice = $this->invoiceFor($order, $line, '5.00');
        $invoice->issue(DocumentActor::system());

        self::assertSame('Invoiced', $order->deriveStatus());

        // The goods are still owed, which is exactly why cancelling an invoice does not void its
        // order — decision 3 in the plan.
        $invoice->setStatus('Cancelled', DocumentActor::system());

        self::assertSame('Approved', $order->deriveStatus());
    }

    public function testAnEcomOrderIsInvoicedTheMomentCheckoutCompletes(): void
    {
        // The consequence the plan calls out explicitly: an ecom order's whole quantity is invoiced
        // from the outset, so it derives straight past Partially Invoiced. Accurate — it IS fully
        // invoiced — and Approved remains the human action that got it there.
        $order = $this->approvedOrder();
        $line = $this->line($order, '2.00');
        $this->invoiceFor($order, $line, '2.00')->issueAwaitingPayment(DocumentActor::system());

        self::assertSame('Invoiced', $order->deriveStatus());
    }

    /**
     * Editing a fully-invoiced order's quantity upward reopens it, with no new code and no guard.
     *
     * Nothing stops an admin editing an order after it has been invoiced, deliberately (#539): the
     * invoice is the accounting record and the order is an operations document, so an order that
     * disagrees with its invoices is an ops problem rather than a books problem. What must hold is
     * that the status keeps telling the truth about it, which is the deriver's whole job.
     */
    public function testRaisingTheQuantityOnAnInvoicedOrderReopensItAsPartiallyInvoiced(): void
    {
        $order = $this->approvedOrder();
        $line = $this->line($order, '5.00');
        $this->invoiceFor($order, $line, '5.00')->issue(DocumentActor::system());

        self::assertSame('Invoiced', $order->deriveStatus(), 'guard: fully invoiced first');

        $line->setQuantity('8.00');

        self::assertSame('Partially Invoiced', $order->deriveStatus());
        self::assertSame('3.0000', $order->uninvoicedQuantityFor($line));
    }

    /**
     * Cutting the quantity BELOW what is already invoiced leaves the order invoiced, not negative.
     *
     * The remainder floors at zero, so an over-invoiced line reports nothing left rather than a
     * negative that the next invoice could borrow quantity back from. The order stays Invoiced
     * because by quantity there is genuinely nothing left to bill — the excess already billed is a
     * discrepancy to resolve with a credit, not something the order's status can express.
     */
    public function testCuttingTheQuantityBelowWhatIsInvoicedLeavesItInvoicedRatherThanNegative(): void
    {
        $order = $this->approvedOrder();
        $line = $this->line($order, '10.00');
        $this->invoiceFor($order, $line, '4.00')->issue(DocumentActor::system());

        self::assertSame('Partially Invoiced', $order->deriveStatus(), 'guard: partially invoiced first');

        $line->setQuantity('3.00');

        self::assertSame('0.0000', $order->uninvoicedQuantityFor($line), 'the remainder floors at zero, never negative');
        self::assertSame('Invoiced', $order->deriveStatus());
    }

    public function testAVoidedOrderIsNeverRecomputed(): void
    {
        $order = $this->approvedOrder();
        $line = $this->line($order, '5.00');
        $this->invoiceFor($order, $line, '5.00')->issue(DocumentActor::system());
        $order->setStatus('Void', DocumentActor::system());

        self::assertFalse($this->deriver->recalculate($order));
        self::assertSame(SalesOrderStatus::Void, $order->getStatusEnum());
        // Where the refusal now lives: the ORDER says it derives nothing, rather than the deriver
        // carrying a Void check of its own. Same behaviour, stated by the thing it is about.
        self::assertNull($order->deriveStatus());
    }

    public function testALegacyStatusTheEnumDoesNotKnowReadsAsADraft(): void
    {
        // A row written before any of this existed — 'Waiting for Quote', 'APPROVED' — still has to
        // load and still has to save. getStatusEnum() absorbs it by returning null, and the deriver
        // treats that as not approved rather than throwing.
        $order = $this->order();
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $reflection = new \ReflectionProperty(SalesOrder::class, 'status');
        $reflection->setValue($order, 'Waiting for Quote');

        self::assertNull($order->getStatusEnum());
        self::assertSame('Draft', $order->deriveStatus());
    }

    public function testRecalculateWritesTheTransitionOntoTheTimeline(): void
    {
        $order = $this->approvedOrder();
        $line = $this->line($order, '5.00');
        $this->invoiceFor($order, $line, '5.00')->issue(DocumentActor::system());
        $before = $order->getLogs()->count();

        self::assertTrue($this->deriver->recalculate($order));
        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());
        self::assertCount($before + 1, $order->getLogs());
        self::assertSame('Order status changed from Approved to Invoiced.', $order->getLogs()->last()->getComment());
        self::assertSame('System', $order->getLogs()->last()->getUserName(), 'a derived move is attributed to the machine, by setStatus()');
    }

    public function testRecalculateIsSilentWhenNothingMoved(): void
    {
        $order = $this->approvedOrder();
        $this->line($order, '5.00');
        $before = $order->getLogs()->count();

        self::assertFalse($this->deriver->recalculate($order));
        self::assertCount($before, $order->getLogs(), 'A no-op recalculation must not write a timeline entry.');
    }

    private function order(): SalesOrder
    {
        return (new SalesOrder())
            ->setCompany((new Company())->setName('Acme Co')->setCode('ACME'))
            ->setOrderNumber('SO-1');
    }

    private function approvedOrder(): SalesOrder
    {
        $order = $this->order();
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        return $order;
    }

    private function line(SalesOrder $order, string $quantity): SalesOrderLine
    {
        $line = (new SalesOrderLine())->setName('Widget')->setQuantity($quantity)->setPrice('5.00');
        $order->addLine($line);

        return $line;
    }

    /**
     * An invoice for $quantity of $line, priced and totalled.
     *
     * The total matters since #539 stage 4: an invoice is fully paid when its payments cover its
     * total, so an invoice left at 0.00 would be settled from birth and every order here would
     * derive straight to Closed.
     */
    private function invoiceFor(SalesOrder $order, SalesOrderLine $line, string $quantity): Invoice
    {
        $invoice = (new Invoice())->setCompany($order->getCompany());
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($line)
                ->setName($line->getName())
                ->setQuantity($quantity)
                ->setPrice($line->getPrice()),
        );
        $invoice->setTotal(number_format((float) $quantity * (float) $line->getPrice(), 2, '.', ''));

        return $invoice;
    }

    /**
     * Settled in full — which since stage 4 means a payment covering the total, not a label.
     *
     * Takes an ISSUED invoice: since #31 a draft accepts no payment, because it has been sent to
     * nobody. The call sites issue first and settle second, which is the order these happen in.
     */
    private function paid(Invoice $invoice): Invoice
    {
        $invoice->recordPayment(
            DocumentActor::system(),
            (new InvoicePayment())->setMethod('Bank Transfer')->setAmount($invoice->getTotal()),
        );

        return $invoice;
    }
}
