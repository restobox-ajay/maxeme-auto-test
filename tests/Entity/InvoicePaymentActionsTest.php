<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\Invoice;
use App\Entity\InvoicePayment;
use App\Service\DocumentActor;
use App\Status\CoreStatusVocabularyProvider;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Recording, applying, amending and withdrawing a payment are named actions on Invoice (#539
 * stage 4; the pool/claim split at #708).
 *
 * The same shape, and the same argument, as the status transitions InvoiceTransitionsTest covers:
 * each validates what it is being asked to do while the caller's intent still exists, and each
 * writes its own timeline entry. There is deliberately no addApplication() collection mutator for a
 * caller to attach money without saying who did it.
 *
 * No database: these are rules about one object, and the point of putting them on the entity is
 * that they hold before anything is persisted.
 */
final class InvoicePaymentActionsTest extends TestCase
{
    /**
     * No kernel here, so the vocabulary registry is primed by hand with the real shipped provider —
     * the same thing `EstimateStatusSeamTest` does, for the reason
     * `StatusVocabularyRegistry::loader()`'s own message gives. It is needed now because this
     * document's status moves go through the seam's gate, which asks the vocabulary what exists.
     */
    protected function setUp(): void
    {
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new CoreStatusVocabularyProvider()]));
    }

    protected function tearDown(): void
    {
        // Static state outlives a test; leaking a loader into the next case is the hazard
        // StatusVocabularyRegistryTest names.
        StatusVocabularyRegistry::reset();
    }

    private static function actor(): DocumentActor
    {
        return DocumentActor::system();
    }

    public function testRecordingAPaymentAttachesItBothWays(): void
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->payment('40.00');

        $invoice->recordPayment(self::actor(), $payment);

        self::assertCount(1, $invoice->getApplications());
        $application = $invoice->getApplications()->first();
        self::assertSame($invoice, $application->getDocument());
        self::assertSame($payment, $application->getPayment());
        self::assertSame('40.00', $invoice->getAmountPaid());
        self::assertSame('60.00', $invoice->getBalance());
    }

    /**
     * #603: an invoice credited in full reads exactly as one paid in full — the balance nets both
     * terms, and the payment status follows, with no cash involved at all.
     */
    public function testACreditNoteAppliedInFullReadsAsPaid(): void
    {
        $invoice = $this->invoice('100.00');
        $memo = $this->creditNote('100.00', $invoice->getCompany());

        $memo->applyTo($invoice, '100.00');

        self::assertSame('100.00', $invoice->getAmountCredited());
        self::assertSame('0.00', $invoice->getBalance());
        self::assertTrue($invoice->paymentCoversTotal());
        self::assertTrue($invoice->hasReceivedMoney(), 'applied credit counts as money received for Partially/Not Paid purposes');
    }

    /** A partial credit and a partial payment are two terms of the same balance, not competing ones. */
    public function testAPartialPaymentAndAPartialCreditBothCountTowardTheBalance(): void
    {
        $invoice = $this->invoice('100.00');
        $invoice->recordPayment(self::actor(), $this->payment('30.00'));
        $this->creditNote('100.00', $invoice->getCompany())->applyTo($invoice, '40.00');

        self::assertSame('30.00', $invoice->getAmountPaid());
        self::assertSame('40.00', $invoice->getAmountCredited());
        self::assertSame('30.00', $invoice->getBalance());
        self::assertFalse($invoice->paymentCoversTotal());
        self::assertTrue($invoice->hasReceivedMoney());
    }

    private function creditNote(string $total, Company $company): CreditMemo
    {
        $memo = (new CreditMemo())
            ->setCompany($company)
            ->setDocumentNumber('CN-' . uniqid())
            ->setDocumentDate('2026-08-23')
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);
        $memo->issue();

        return $memo;
    }

    public function testRecordingAPaymentWritesItsOwnTimelineEntry(): void
    {
        $invoice = $this->invoice('100.00');
        $invoice->recordPayment(self::actor(), $this->payment('40.00', 'cheque 1201'));

        // Two: the fixture's own "Invoice issued." and then the payment's. The payment's is the
        // last, which is what this is about — every action writes its own entry rather than leaving
        // the history to a changeset that cannot tell one Cancel from another.
        self::assertCount(2, $invoice->getLogs());
        self::assertSame(
            'Payment of $40.00 via Bank Transfer recorded. Comment: cheque 1201',
            $invoice->getLogs()->last()->getComment(),
        );
    }

    public function testAPaymentOfNothingIsRefused(): void
    {
        $this->expectException(\DomainException::class);

        $this->invoice('100.00')->recordPayment(self::actor(), $this->payment('0.00'));
    }

    public function testACancelledInvoiceTakesNoPayment(): void
    {
        $invoice = $this->invoice('100.00');
        $invoice->setStatus('Cancelled', self::actor());

        $this->expectException(\DomainException::class);

        $invoice->recordPayment(self::actor(), $this->payment('40.00'));
    }

    public function testAmendingSaysWhatItChangedFrom(): void
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->payment('40.00');
        $invoice->recordPayment(self::actor(), $payment);
        $application = $invoice->getApplications()->first();

        $invoice->amendApplication(
            self::actor(),
            $application,
            new \DateTimeImmutable('2026-08-19'),
            'Cheque',
            '55.00',
            null,
        );

        self::assertSame('55.00', $invoice->getAmountPaid());
        self::assertSame('2026-08-19', $application->getAppliedAt()->format('Y-m-d'));
        self::assertSame(
            'Payment updated to $55.00 via Cheque (was $40.00 via Bank Transfer).',
            $invoice->getLogs()->last()->getComment(),
        );
    }

    public function testAPaymentFromAnotherInvoiceCannotBeAmendedOrVoidedThroughThisOne(): void
    {
        $mine = $this->invoice('100.00');
        $theirs = $this->invoice('100.00');
        $payment = $this->payment('40.00');
        $theirs->recordPayment(self::actor(), $payment);
        $application = $theirs->getApplications()->first();

        $this->expectException(\DomainException::class);

        $mine->withdrawApplication(self::actor(), $application);
    }

    public function testVoidingDetachesThePaymentAndSaysSo(): void
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->payment('40.00');
        $invoice->recordPayment(self::actor(), $payment);
        $application = $invoice->getApplications()->first();

        $invoice->withdrawApplication(self::actor(), $application, 'cheque bounced');

        self::assertCount(0, $invoice->getApplications());
        self::assertSame('0.00', $invoice->getAmountPaid());
        self::assertSame(
            'Payment of $40.00 via Bank Transfer deleted. Comment: cheque bounced',
            $invoice->getLogs()->last()->getComment(),
        );
    }

    /**
     * An invoice with money against it cannot be cancelled (client decision, #539 stage 4, matching
     * Zoho Books).
     *
     * Once a payment has been allocated to an invoice it is a real accounting record: it is credited
     * or refunded, never withdrawn. An admin who genuinely means to withdraw it deletes the payments
     * first, which is a deliberate act with its own timeline entries.
     */
    public function testAnInvoiceWithPaymentsAgainstItCannotBeCancelled(): void
    {
        $invoice = $this->invoice('100.00');
        $invoice->recordPayment(self::actor(), $this->payment('40.00'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/cannot be cancelled/');

        $invoice->setStatus('Cancelled', self::actor());
    }

    public function testAnInvoiceWithNoPaymentsStillCancels(): void
    {
        $invoice = $this->invoice('100.00');

        $invoice->setStatus('Cancelled', self::actor());

        self::assertTrue($invoice->isCancelled());
    }

    public function testDeletingThePaymentsFreesTheInvoiceToBeCancelled(): void
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->payment('40.00');
        $invoice->recordPayment(self::actor(), $payment);
        $application = $invoice->getApplications()->first();

        $invoice->withdrawApplication(self::actor(), $application);
        $invoice->setStatus('Cancelled', self::actor());

        self::assertTrue($invoice->isCancelled());
    }

    /**
     * isFullyPaid() answers "is anything still owed", which is what the order's Closed derivation
     * asks. A cancelled invoice is owed nothing whatever its payments say — the alternative would
     * hold an order open forever on an invoice nobody is going to pay.
     */
    public function testACancelledInvoiceIsSettledEvenThoughNobodyPaidIt(): void
    {
        $invoice = $this->invoice('100.00');
        $invoice->setStatus('Cancelled', self::actor());

        self::assertTrue($invoice->isFullyPaid());
        self::assertFalse($invoice->paymentCoversTotal());
    }

    /**
     * ISSUED, because since #31 only an issued invoice takes a payment: a draft has been sent to
     * nobody, so nobody could have paid it. The fixture was a bare draft before that rule existed,
     * and every test here is about what happens to money on a live document rather than about the
     * status it sits at — InvoiceStatus::acceptsPayment() and InvoiceTransitionsTest own that.
     *
     * Cancelling still works from here: cancel() allows every live state, and the tests below that
     * cancel are about a cancelled invoice owing nothing.
     *
     * A Company is set (#708): `recordPayment()` now wires the payment pool to `$this->company`, so
     * a companyless fixture invoice would fail with a TypeError rather than exercise any rule this
     * file is actually about.
     */
    private function invoice(string $total): Invoice
    {
        $invoice = (new Invoice())
            ->setDocumentNumber('INV-1')
            ->setTotal($total);
        $invoice->setCompany((new Company())->setName('Fixture Co'));

        return $invoice->issue(self::actor());
    }

    private function payment(string $amount, ?string $comment = null): InvoicePayment
    {
        return (new InvoicePayment())
            ->setMethod('Bank Transfer')
            ->setAmount($amount)
            ->setComment($comment);
    }
}
