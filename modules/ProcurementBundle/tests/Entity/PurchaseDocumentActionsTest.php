<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Entity;

use App\Service\DocumentActor;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\TestCase;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillPayment;
use ProcurementBundle\Enum\PurchaseOrderStatus;
use ProcurementBundle\Enum\VendorBillStatus;
use ProcurementBundle\Status\ProcurementStatusVocabularyProvider;
use ProcurementBundle\Status\PurchaseOrderStatusDeriver;
use ProcurementBundle\Status\VendorBillStatusDeriver;

/**
 * The named actions on the two purchase documents, and the two derivers (#555).
 *
 * A pure unit test — no database — because everything under test is a decision made in memory
 * before anything is written. That is the whole reason transitions are actions rather than a status
 * setter: the from-state is only knowable where the caller's intent still exists, which is here and
 * not at `onFlush`.
 *
 * The three properties this file exists to pin down:
 *
 *  1. an illegal transition is refused, loudly, with a message naming both states;
 *  2. every action writes its own timeline entry, signed by the actor it was given;
 *  3. a derived status cannot be written over a decided one, in either direction.
 */
final class PurchaseDocumentActionsTest extends TestCase
{
    /**
     * No kernel here, so the vocabulary registry is primed by hand with the real shipped provider —
     * the same thing `App\Tests\Entity\InvoiceTransitionsTest` does on the sell side, for the reason
     * `StatusVocabularyRegistry::loader()`'s own message gives. It is needed now that both
     * documents' status moves go through the seam's gate, which asks the vocabulary what exists.
     */
    protected function setUp(): void
    {
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new ProcurementStatusVocabularyProvider()]));
    }

    protected function tearDown(): void
    {
        // Static state outlives a test; leaking a loader into the next case is the hazard
        // StatusVocabularyRegistryTest names.
        StatusVocabularyRegistry::reset();
    }

    private function vendor(): Vendor
    {
        return (new Vendor())->setName('Acme Supply')->setPaymentTerm('Net 30');
    }

    private function order(string $ordered = '100.00'): PurchaseOrder
    {
        $order = (new PurchaseOrder())->setPoNumber('PO-1')->setVendor($this->vendor());

        $line = (new PurchaseOrderLine())->setName('Widget')->setQuantityOrdered($ordered)->setUnitCost('4.5000')->setSubtotal('450.00');
        $order->addLine($line);

        return $order;
    }

    /* ------------------------------------------------------------------------------------------
     * Purchase order
     * ---------------------------------------------------------------------------------------- */

    public function testIssuingWritesATimelineEntrySignedByTheActor(): void
    {
        $order = $this->order();

        $order->setStatus('Issued', DocumentActor::named('Priya'));

        self::assertSame(PurchaseOrderStatus::Issued, $order->getStatusEnum());
        self::assertCount(1, $order->getLogs());
        self::assertSame('Priya', $order->getLogs()->first()->getUserName());
        self::assertStringContainsString('issued', $order->getLogs()->first()->getComment());
    }

    /** Issuing is what makes the document the record of what was agreed, so it freezes the snapshot. */
    public function testIssuingFreezesTheVendorAddressAndTerm(): void
    {
        $vendor = $this->vendor();
        $order = (new PurchaseOrder())->setPoNumber('PO-2')->setVendor($vendor);
        $order->addLine((new PurchaseOrderLine())->setName('Widget')->setQuantityOrdered('1.00')->setUnitCost('1.0000')->setSubtotal('1.00'));

        $order->setStatus('Issued', DocumentActor::system());

        self::assertSame('Net 30', $order->getPaymentTerm());

        // Renaming the vendor afterwards must not rewrite the document.
        $vendor->setName('Acme Supply (formerly)');
        self::assertSame('Acme Supply', $order->getVendorName());
        self::assertSame('Acme Supply', $order->getCounterpartyName());
    }

    public function testAnEmptyOrderCannotBeIssued(): void
    {
        $order = (new PurchaseOrder())->setPoNumber('PO-3')->setVendor($this->vendor());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/no lines/');

        $order->setStatus('Issued', DocumentActor::system());
    }

    public function testAnIllegalTransitionIsRefusedAndNamesBothStates(): void
    {
        $order = $this->order();
        $order->setStatus('Issued', DocumentActor::system());
        $order->setStatus('Cancelled', DocumentActor::system());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/cannot go from Cancelled to Issued/');

        $order->setStatus('Issued', DocumentActor::system());
    }

    /** Goods on the dock are a fact; a cancelled PO would leave the receipts pointing at a lie. */
    public function testAnOrderWithGoodsAgainstItCannotBeCancelled(): void
    {
        $order = $this->order();
        $order->setStatus('Issued', DocumentActor::system());
        $order->getLines()->first()->setQuantityReceived('10.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Close it short instead/');

        $order->setStatus('Cancelled', DocumentActor::system());
    }

    public function testClosingShortDemandsAReason(): void
    {
        $order = $this->order();
        $order->setStatus('Issued', DocumentActor::system());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/needs a reason/');

        $order->closeShort(DocumentActor::system(), '   ');
    }

    public function testClosingShortRecordsWhatWasWrittenOff(): void
    {
        $order = $this->order('240.00');
        $order->setStatus('Issued', DocumentActor::system());
        $order->getLines()->first()->setQuantityReceived('210.00');

        $order->closeShort(DocumentActor::named('Priya'), 'Vendor discontinued the line.');

        self::assertSame(PurchaseOrderStatus::Closed, $order->getStatusEnum());
        $last = $order->getLogs()->last();
        // "30" and not "30.00": a log entry is prose, so the figure goes through DisplayNumber.
        self::assertStringContainsString('30 unit(s) outstanding', $last->getComment());
        self::assertStringContainsString('Vendor discontinued the line.', $last->getComment());
    }

    /* ------------------------------------------------------------------------------------------
     * Purchase order status derivation
     * ---------------------------------------------------------------------------------------- */

    public function testTheDeriverMovesAnIssuedOrderThroughPartiallyReceivedToReceived(): void
    {
        $deriver = new PurchaseOrderStatusDeriver();
        $order = $this->order('240.00');
        $order->setStatus('Issued', DocumentActor::system());

        self::assertFalse($deriver->recalculate($order), 'nothing has arrived, so nothing changes');

        $order->getLines()->first()->setQuantityReceived('210.00');
        self::assertTrue($deriver->recalculate($order));
        self::assertSame(PurchaseOrderStatus::PartiallyReceived, $order->getStatusEnum());

        $order->getLines()->first()->setQuantityReceived('240.00');
        self::assertTrue($deriver->recalculate($order));
        self::assertSame(PurchaseOrderStatus::Received, $order->getStatusEnum());
    }

    /** A draft holds no promise to anybody; deriving it would claim goods arrived against nothing. */
    public function testTheDeriverWillNotTouchADraft(): void
    {
        $deriver = new PurchaseOrderStatusDeriver();
        $order = $this->order();
        $order->getLines()->first()->setQuantityReceived('100.00');

        self::assertFalse($deriver->recalculate($order));
        self::assertSame(PurchaseOrderStatus::Draft, $order->getStatusEnum());
    }

    /** The decision to write off a remainder must survive the goods turning up late. */
    public function testTheDeriverWillNotReopenAClosedOrder(): void
    {
        $deriver = new PurchaseOrderStatusDeriver();
        $order = $this->order('240.00');
        $order->setStatus('Issued', DocumentActor::system());
        $order->getLines()->first()->setQuantityReceived('210.00');
        $order->closeShort(DocumentActor::system(), 'Written off.');

        $order->getLines()->first()->setQuantityReceived('240.00');

        self::assertFalse($deriver->recalculate($order));
        self::assertSame(PurchaseOrderStatus::Closed, $order->getStatusEnum());
    }

    public function testTheDeriverWritesOneEntryPerRealTransition(): void
    {
        $deriver = new PurchaseOrderStatusDeriver();
        $order = $this->order('10.00');
        $order->setStatus('Issued', DocumentActor::system());
        $order->getLines()->first()->setQuantityReceived('4.00');

        $deriver->recalculate($order);
        $before = $order->getLogs()->count();
        $deriver->recalculate($order);

        self::assertSame($before, $order->getLogs()->count(), 'a no-op derivation must not write an entry');
    }

    /* ------------------------------------------------------------------------------------------
     * Vendor bill
     * ---------------------------------------------------------------------------------------- */

    private function bill(string $total = '450.00'): VendorBill
    {
        return (new VendorBill())->setBillNumber('BILL-1')->setVendor($this->vendor())->setVendorName('Acme Supply')->setTotal($total);
    }

    /**
     * One payment row, of an amount.
     *
     * #658 replaced the cumulative figure written onto the bill with real `vendor_bill_payment`
     * rows, so these tests hand the bill a ROW and the figures below are increments rather than
     * running totals. `getAmountPaid()` is the sum of the rows and is stored nowhere, which is the
     * point of the change: the paid figure cannot drift from the payments that make it up.
     */
    private function payment(string $amount, string $method = 'EFT', ?string $comment = null): VendorBillPayment
    {
        return (new VendorBillPayment())
            ->setPaidAt(new \DateTimeImmutable('2026-01-01'))
            ->setMethod($method)
            ->setAmount($amount)
            ->setComment($comment);
    }

    public function testApprovingRecordsTheMatchSummaryOnTheTimeline(): void
    {
        $bill = $this->bill();

        $bill->approve(DocumentActor::named('Priya'), 'Three-way match clean across 2 line(s).');

        self::assertSame(VendorBillStatus::Open, $bill->getStatusEnum());
        self::assertStringContainsString('Three-way match clean', $bill->getLogs()->first()->getComment());
        self::assertSame('Priya', $bill->getLogs()->first()->getUserName());
    }

    public function testTheDeriverMovesAnApprovedBillThroughPartiallyPaidToPaid(): void
    {
        $deriver = new VendorBillStatusDeriver();
        $bill = $this->bill('450.00');
        $bill->approve(DocumentActor::system());

        self::assertFalse($deriver->recalculate($bill));

        $bill->recordPayment(DocumentActor::system(), $this->payment('200.00'));
        self::assertTrue($deriver->recalculate($bill));
        self::assertSame(VendorBillStatus::PartiallyPaid, $bill->getStatusEnum());
        self::assertSame('250.00', $bill->getBalance());

        // The balance of it, as a SECOND row. Under the old cumulative column this line read
        // '450.00' and meant the same thing; with rows, a second payment is a second payment.
        $bill->recordPayment(DocumentActor::system(), $this->payment('250.00'));
        self::assertTrue($deriver->recalculate($bill));
        self::assertSame(VendorBillStatus::Paid, $bill->getStatusEnum());
        self::assertSame('450.00', $bill->getAmountPaid());
        self::assertSame('0.00', $bill->getBalance());
    }

    /** A draft has authorised nothing; deriving it to Open would approve it because somebody typed a total. */
    public function testTheDeriverWillNotTouchADraftBill(): void
    {
        $deriver = new VendorBillStatusDeriver();
        $bill = $this->bill();

        self::assertFalse($deriver->recalculate($bill));
        self::assertSame(VendorBillStatus::Draft, $bill->getStatusEnum());
    }

    /** A part payment against a disputed bill does not settle the dispute. */
    public function testTheDeriverWillNotClearADispute(): void
    {
        $deriver = new VendorBillStatusDeriver();
        $bill = $this->bill('450.00');
        $bill->approve(DocumentActor::system());
        $bill->setStatus('Disputed', DocumentActor::system(), 'Short shipment.');
        $bill->recordPayment(DocumentActor::system(), $this->payment('200.00'));

        self::assertFalse($deriver->recalculate($bill));
        self::assertSame(VendorBillStatus::Disputed, $bill->getStatusEnum());
    }

    public function testABillWithMoneyAgainstItCannotBeVoided(): void
    {
        $bill = $this->bill();
        $bill->approve(DocumentActor::system());
        $bill->recordPayment(DocumentActor::system(), $this->payment('10.00'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Credit or refund it instead/');

        $bill->setStatus('Void', DocumentActor::system());
    }

    /**
     * The refusal now names the STATUS as the screens spell it — "is Void", not "is void".
     *
     * Queue item 34 moved this rule out of an inline `=== VendorBillStatus::Void` in
     * recordPayment() and into VendorBillStatus::acceptsPayment() plus VendorBill::paymentRefusal(),
     * so that recording a payment and MOVING one onto a bill cannot answer it differently. The
     * sentence is built from `$status->value`, which is the word a person sees on the bill.
     *
     * Asserted in full rather than by a case-sensitive /void/ substring, which is a stronger
     * assertion than the one it replaces: it pins the bill number and the remedy as well as the
     * state.
     */
    public function testAVoidedBillCannotTakeAPayment(): void
    {
        $bill = $this->bill();
        $bill->setStatus('Void', DocumentActor::system(), 'Bill voided: Entered twice.');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Bill BILL-1 is Void; it is owed nothing and cannot take a payment.');

        $bill->recordPayment(DocumentActor::system(), $this->payment('10.00'));
    }

    /**
     * A correction says what the figure was, which is the only part of one anybody needs later.
     *
     * Under the cumulative column every payment WAS a correction of the last, so this read off the
     * recording call. With rows, recording and correcting are different acts and only the second is
     * a correction: `amendApplication()` applies the new values itself, precisely so that the entry can
     * still say what they were.
     */
    public function testAmendingAPaymentSaysWhatTheFigureWasBefore(): void
    {
        $bill = $this->bill();
        $bill->approve(DocumentActor::system());

        $payment = $this->payment('200.00');
        $bill->recordPayment(DocumentActor::system(), $payment);
        $application = $bill->getApplications()->first();
        $bill->amendApplication(
            DocumentActor::named('Priya'),
            $application,
            new \DateTimeImmutable('2026-01-02'),
            'Cheque',
            '250.00',
            'Corrected — cheque was for 250.',
        );

        $last = $bill->getLogs()->last();
        self::assertStringContainsString('$250.00', $last->getComment());
        self::assertStringContainsString('was $200.00', $last->getComment());
        self::assertStringContainsString('Corrected', $last->getComment());
        self::assertSame('250.00', $bill->getAmountPaid(), 'a correction replaces the figure, it does not add to it');
        self::assertCount(1, $bill->getApplications(), 'correcting a payment does not make a second one');
    }

    /** A payment taken back off the bill leaves the money unpaid and the history saying so. */
    public function testVoidingAPaymentRemovesTheMoneyAndKeepsTheEntry(): void
    {
        $bill = $this->bill();
        $bill->approve(DocumentActor::system());

        $payment = $this->payment('200.00');
        $bill->recordPayment(DocumentActor::system(), $payment);
        self::assertSame('200.00', $bill->getAmountPaid());

        $application = $bill->getApplications()->first();
        $bill->withdrawApplication(DocumentActor::named('Priya'), $application, 'Cheque never cleared.');

        self::assertSame('0.00', $bill->getAmountPaid());
        self::assertCount(0, $bill->getApplications());
        self::assertStringContainsString('deleted', $bill->getLogs()->last()->getComment());
        self::assertStringContainsString('Cheque never cleared', $bill->getLogs()->last()->getComment());
    }

    /** A bill for nothing at all is covered by no payment, and must not be unsettleable forever. */
    public function testABillForNothingIsSettled(): void
    {
        $bill = $this->bill('0.00');

        self::assertTrue($bill->isSettled());
        self::assertSame(VendorBillStatus::Paid, (new VendorBillStatusDeriver())->statusFor($bill));
    }

    /** Money is summed in whole cents; 0.10 + 0.20 is not 0.30 in binary floating point. */
    public function testTheBalanceIsExactAtAwkwardFigures(): void
    {
        $bill = $this->bill('0.30');
        $bill->approve(DocumentActor::system());
        // Two rows that sum to the total in whole cents but not in binary floating point.
        $bill->recordPayment(DocumentActor::system(), $this->payment('0.10'));
        $bill->recordPayment(DocumentActor::system(), $this->payment('0.20'));

        self::assertSame('0.30', $bill->getAmountPaid());
        self::assertSame('0.00', $bill->getBalance());
        self::assertTrue($bill->isSettled());
    }
}
